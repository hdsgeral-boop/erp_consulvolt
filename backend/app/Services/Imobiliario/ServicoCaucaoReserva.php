<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\EspacoComum;
use App\Models\LancamentoContabil;
use App\Models\MovimentoCaucaoReserva;
use App\Models\ReservaEspaco;
use App\Models\Terceiro;
use App\Models\VistoriaReserva;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Services\Vendas\ServicoDocumentosVenda;
use App\Services\Vendas\ServicoEstadoVenda;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Caução das reservas de áreas comuns (fecharCaucao e receber do legado): é dinheiro de terceiros à guarda da empresa, nunca proveito.
 * Ledger imutável (`movimentos_caucao_reserva`) com lançamentos equilibrados e idempotentes pelas contas da configuração do módulo
 * (as mesmas da caução dos contratos: passivo «cauções de terceiros»; nada inventado no código). Recebida: D disponibilidades / C passivo;
 * devolvida: D passivo / C disponibilidades. Só a parte retida por danos, e só com vistoria de saída que o prove, vira receita: é
 * facturada à parte ao reservante (artigo das reservas) e compensada com a caução (D passivo / C conta do cliente), ficando a factura
 * regularizada. A retenção é definitiva: corrige-se com nota de crédito em Vendas, não com anulação aqui. O pedido repetido com a mesma
 * `chave` devolve o movimento já registado sem lançar nem facturar de novo.
 */
final class ServicoCaucaoReserva
{
    public function __construct(
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoConfiguracaoImobiliario $config,
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoDocumentosVenda $vendas,
        private readonly ServicoContabilizacaoVendas $contabilizacao,
        private readonly ServicoEstadoVenda $estadoVenda,
    ) {}

    /** @return array<string, mixed> */
    public function listar(ReservaEspaco $r): array
    {
        $mov = MovimentoCaucaoReserva::query()->where('reserva_id', $r->id)->orderBy('data')->orderBy('id')->get();

        return ['movimentos' => $mov->map(fn ($m) => $m->attributesToArray())->all(), 'resumo' => $this->resumo($r)];
    }

    /** @return array<string, mixed> */
    public function resumo(ReservaEspaco $r): array
    {
        $emPoder = round((float) $r->caucao_recebida - (float) $r->caucao_devolvida - (float) $r->caucao_retida, 2);

        return ['devida' => (float) $r->caucao, 'recebida' => (float) $r->caucao_recebida, 'devolvida' => (float) $r->caucao_devolvida, 'retida' => (float) $r->caucao_retida, 'em_poder' => $emPoder,
            'por_receber' => round(max(0, (float) $r->caucao - (float) $r->caucao_recebida), 2),
            'estado' => CalculadoraReserva::estadoCaucao((float) $r->caucao, (float) $r->caucao_recebida, (float) $r->caucao_retida, (float) $r->caucao_devolvida)];
    }

    /**
     * @param  array{tipo: string, valor: float|string, data?: ?string, conta_financeira?: ?string, motivo?: ?string, chave?: ?string}  $d
     */
    public function movimentar(ReservaEspaco $reserva, array $d): MovimentoCaucaoReserva
    {
        $tipo = (string) ($d['tipo'] ?? '');
        if (! in_array($tipo, MovimentoCaucaoReserva::TIPOS, true)) {
            throw new ErroNegocio('Escolha o tipo de movimento: recebida, devolvida ou retida.', 'IMO_CAUCAO_TIPO');
        }
        if (! is_numeric($d['valor'] ?? null) || (float) $d['valor'] <= 0) {
            throw new ErroNegocio('Indique o valor do movimento da caução.', 'IMO_CAUCAO_VALOR');
        }
        $valor = number_format((float) $d['valor'], 2, '.', '');
        $data = Normaliza::texto($d['data'] ?? null) ?? now()->toDateString();
        $motivo = Normaliza::texto($d['motivo'] ?? null);
        $conta = Normaliza::texto($d['conta_financeira'] ?? null);
        $chave = Normaliza::texto($d['chave'] ?? null);
        if ($chave !== null && mb_strlen($chave) > 80) {
            throw new ErroNegocio('A chave do pedido é demasiado longa.', 'IMO_CAUCAO_CHAVE');
        }
        if ($tipo === 'RETIDA' && $motivo === null) {
            throw new ErroNegocio('Para reter caução é preciso dizer o que se encontrou.', 'IMO_CAUCAO_MOTIVO');
        }
        if ($tipo !== 'RETIDA' && $conta === null) {
            throw new ErroNegocio('Indique a conta de caixa ou banco por onde a caução entra ou sai.', 'IMO_CAUCAO_CONTA');
        }
        if ($conta !== null) {
            $this->plano->contaDeMovimento($conta);
        }

        return DB::transaction(function () use ($reserva, $tipo, $valor, $data, $motivo, $conta, $chave) {
            $r = ReservaEspaco::query()->whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            if ($chave !== null && ($existente = MovimentoCaucaoReserva::query()->where('chave', $chave)->first())) {
                if ($existente->reserva_id !== $r->id || $existente->tipo !== $tipo || bccomp(number_format((float) $existente->valor, 2, '.', ''), $valor, 2) !== 0) {
                    throw new ErroNegocio('Esta chave já foi usada noutro movimento.', 'IMO_CAUCAO_CHAVE_REPETIDA', 409);
                }

                return $existente;
            }
            $this->conferir($r, $tipo, $valor);
            $passivo = $this->config->actual()?->conta_caucao_passivo ?? throw new ErroNegocio('Falta a conta do passivo «cauções de terceiros» na configuração do módulo imobiliário.', 'IMO_SEM_CONTA_CAUCAO');
            $m = MovimentoCaucaoReserva::create(['reserva_id' => $r->id, 'tipo' => $tipo, 'valor' => $valor, 'data' => $data, 'conta_financeira' => $conta, 'motivo' => $motivo,
                'chave' => $chave ?? 'auto:'.Str::uuid()->toString(), 'estado' => 'VALIDO', 'criado_por' => Autor::nome()]);
            if ($tipo === 'RETIDA') {
                $this->facturarRetencao($r, $m, $passivo);
            } else {
                $this->contabilizar($m, $r, $passivo);
            }
            $this->recalcular($r);

            return $m->refresh();
        });
    }

    /** @throws ErroNegocio se o movimento não cabe no estado da reserva ou excede o acordado/em poder */
    private function conferir(ReservaEspaco $r, string $tipo, string $valor): void
    {
        $rec = number_format((float) $r->caucao_recebida, 2, '.', '');
        $emPoder = bcsub(bcsub($rec, number_format((float) $r->caucao_devolvida, 2, '.', ''), 2), number_format((float) $r->caucao_retida, 2, '.', ''), 2);
        if ($r->estado === 'RECUSADA') {
            throw new ErroNegocio('Uma reserva recusada não tem caução.', 'IMO_CAUCAO_RESERVA');
        }
        if ($tipo === 'RECEBIDA') {
            if (in_array($r->estado, ['PEDIDA', 'CANCELADA', 'REALIZADA', 'FALTOU'], true)) {
                throw new ErroNegocio('Uma reserva «'.mb_strtolower(CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['rot']).'» não recebe caução.', 'IMO_CAUCAO_RESERVA');
            }
            if (bccomp(bcadd($rec, $valor, 2), number_format((float) $r->caucao, 2, '.', ''), 2) > 0) {
                throw new ErroNegocio('A caução recebida ficaria acima da caução devida.', 'IMO_CAUCAO_EXCEDE_ACORDADA');
            }

            return;
        }
        if (bccomp($valor, $emPoder, 2) > 0) {
            throw new ErroNegocio('Só há '.number_format((float) $emPoder, 2, ',', ' ').' Kz de caução em poder: o movimento não pode ser maior.', 'IMO_CAUCAO_EXCEDE_EM_PODER');
        }
        if ($tipo === 'RETIDA') {
            if (! VistoriaReserva::query()->where('reserva_id', $r->id)->where('quando', 'DEPOIS')->exists()) {
                throw new ErroNegocio('Reter caução sem vistoria de saída é ficar com dinheiro de outra pessoa sem prova. Registe a vistoria primeiro.', 'IMO_CAUCAO_SEM_VISTORIA');
            }
            if ($r->interna || ! $r->terceiro_id) {
                throw new ErroNegocio('Uma reserva interna não tem a quem facturar a retenção.', 'IMO_CAUCAO_INTERNA');
            }
        }
    }

    /** D/C do movimento (recebida e devolvida) no diário IMO, equilibrado; idempotente (não lança duas vezes o mesmo movimento). */
    private function contabilizar(MovimentoCaucaoReserva $m, ReservaEspaco $r, string $passivo): void
    {
        if ($m->lancamento_numero !== null) {
            return;
        }
        $valor = number_format((float) $m->valor, 2, '.', '');
        $fin = (string) $m->conta_financeira;
        [$deb, $cre] = $m->tipo === 'RECEBIDA' ? [$fin, $passivo] : [$passivo, $fin];
        $this->lancar($m, $r, $deb, $cre, 'Caução '.($m->tipo === 'RECEBIDA' ? 'recebida' : 'devolvida'), $valor);
    }

    private function lancar(MovimentoCaucaoReserva $m, ReservaEspaco $r, string $deb, string $cre, string $rotulo, string $valor): void
    {
        $e = EspacoComum::query()->find($r->espaco_id, ['id', 'nome']);
        $texto = "{$rotulo} — reserva {$e?->nome} em {$r->data->format('Y-m-d')} (#{$r->id})";
        $linhas = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('IMO', 'Imobiliário')->id, 'data_documento' => $m->data->format('Y-m-d'), 'numero_documento' => 'IMO-CAR-'.$m->id,
            'referencia' => 'Reserva #'.$r->id, 'descricao' => $texto, 'tipo_origem' => ServicoCaucao::ORIGEM_LANCAMENTO, 'linhas' => [
                ['codigo_conta' => $deb, 'tipo_dc' => 'D', 'valor' => $valor, 'terceiro_id' => $r->terceiro_id, 'descricao' => $texto],
                ['codigo_conta' => $cre, 'tipo_dc' => 'C', 'valor' => $valor, 'terceiro_id' => $r->terceiro_id, 'descricao' => $texto]]]);
        $m->update(['lancamento_numero' => $linhas->first()->numero_lan]);
    }

    /** Retenção por danos: factura ao reservante o valor retido (receita), contabiliza-a e compensa-a com a caução (D passivo / C cliente). */
    private function facturarRetencao(ReservaEspaco $r, MovimentoCaucaoReserva $m, string $passivo): void
    {
        $produto = $this->config->produtoDaReserva();
        $cliente = Terceiro::query()->findOrFail($r->terceiro_id);
        $contaCliente = $cliente->contaCliente();
        if ($contaCliente === '') {
            throw new ErroNegocio('O reservante não tem conta de cliente.', 'IMO_SEM_CONTA_CLIENTE');
        }
        $valor = number_format((float) $m->valor, 2, '.', '');
        $e = EspacoComum::query()->find($r->espaco_id, ['id', 'nome']);
        $venda = $this->vendas->emitir(['tipo_documento' => 'FT', 'cliente_id' => $r->terceiro_id, 'data_emissao' => $m->data->format('Y-m-d'),
            'linhas' => [['produto_id' => $produto->id, 'quantidade' => '1', 'preco_unitario' => $valor, 'descricao' => "Caução retida por danos — {$e?->nome}, reserva de {$r->data->format('Y-m-d')}"]],
            'observacoes' => 'Caução retida — '.mb_substr((string) $m->motivo, 0, 200)]);
        if (bccomp(number_format((float) $venda->total_bruto, 2, '.', ''), $valor, 2) !== 0) {
            throw new ErroNegocio('O total da factura da retenção não bate com o valor retido. Reveja o IVA do artigo das reservas.', 'IMO_FACTURA_NAO_BATE');
        }
        $venda = $this->contabilizacao->contabilizar($venda);
        $m->update(['venda_id' => $venda->id]);
        $this->lancar($m, $r, $passivo, $contaCliente, 'Caução retida por danos', $valor);
        $venda->update(['valor_pago' => $valor]);
        $this->estadoVenda->recalcular($venda->refresh());
    }

    /** Anula um movimento recebido ou devolvido: estorna o lançamento (ligado ao original) e recalcula os totais. A retenção é definitiva. */
    public function anular(MovimentoCaucaoReserva $movimento, ?string $motivo): MovimentoCaucaoReserva
    {
        $motivo = Normaliza::texto($motivo) ?? throw new ErroNegocio('Indique o motivo da anulação.', 'IMO_MOTIVO_OBRIGATORIO');

        return DB::transaction(function () use ($movimento, $motivo) {
            $m = MovimentoCaucaoReserva::query()->lockForUpdate()->findOrFail($movimento->id);
            $r = ReservaEspaco::query()->whereKey($m->reserva_id)->lockForUpdate()->firstOrFail();
            if ($m->estado !== 'VALIDO') {
                throw new ErroNegocio('O movimento já está anulado.', 'IMO_CAUCAO_JA_ANULADO');
            }
            if ($m->tipo === 'RETIDA') {
                throw new ErroNegocio('A retenção já foi facturada: corrige-se com uma nota de crédito em Vendas, não por aqui.', 'IMO_CAUCAO_RETIDA_DEFINITIVA');
            }
            if ($m->tipo === 'RECEBIDA' && (float) $r->caucao_recebida - (float) $m->valor + 0.001 < (float) $r->caucao_devolvida + (float) $r->caucao_retida) {
                throw new ErroNegocio('Há devoluções ou retenções que dependem deste recebimento: anule-as primeiro.', 'IMO_CAUCAO_DEPENDENTE');
            }
            $estorno = null;
            if ($m->lancamento_numero !== null) {
                $linha = LancamentoContabil::query()->where('numero_lan', $m->lancamento_numero)->orderBy('id')->firstOrFail();
                $estorno = $this->lancamentos->estornar($linha, 'Caução da reserva anulada: '.$motivo)->first()?->numero_lan;
            }
            $m->update(['estado' => 'ANULADO', 'anulado_por' => Autor::nome(), 'anulado_em' => now(), 'anulado_motivo' => mb_substr($motivo, 0, 300), 'estorno_numero' => $estorno]);
            $this->recalcular($r);

            return $m->refresh();
        });
    }

    /** Recalcula os totais da reserva a partir dos movimentos válidos. */
    public function recalcular(ReservaEspaco $r): void
    {
        $soma = MovimentoCaucaoReserva::query()->where('reserva_id', $r->id)->where('estado', 'VALIDO')->selectRaw('tipo, coalesce(sum(valor), 0) as total')->groupBy('tipo')->pluck('total', 'tipo');
        $ultimaRetencao = MovimentoCaucaoReserva::query()->where('reserva_id', $r->id)->where('estado', 'VALIDO')->where('tipo', 'RETIDA')->orderByDesc('id')->value('motivo');
        $r->update(['caucao_recebida' => $soma['RECEBIDA'] ?? 0, 'caucao_devolvida' => $soma['DEVOLVIDA'] ?? 0, 'caucao_retida' => $soma['RETIDA'] ?? 0, 'caucao_motivo' => $ultimaRetencao]);
    }
}
