<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\EspacoComum;
use App\Models\Fracao;
use App\Models\HistoricoReservaImo;
use App\Models\ReservaEspaco;
use App\Models\Venda;
use App\Models\VistoriaReserva;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use App\Services\Vendas\ServicoDocumentosVenda;
use App\Services\Vendas\ServicoRecibosVenda;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Parte financeira das reservas pagas (imobiliario_reservas_fin.js do legado), sobre Vendas (factura, recibo, nota de crédito) e a caução
 * em ServicoCaucaoReserva. Princípios: a reserva paga-se antes de usar (a aguardar pagamento → confirmada só com o recebimento); caduca
 * sozinha no prazo do espaço; a caução nunca é proveito; cancelar tem escala (N dias antes devolve tudo, depois a percentagem do
 * regulamento). Idempotente: facturar devolve a factura existente (UNIQUE venda_id), o recebimento nunca passa do que falta, a
 * caducidade só cancela uma vez. Correcções (P3): o prazo conta desde que a reserva fica a aguardar pagamento; a caducidade só cancela
 * o que não recebeu nada (com pagamento parcial não se cancela em silêncio: fica sinalizada) e emite a nota de crédito da factura
 * que ficou por pagar (o legado deixava-a emitida); o cancelamento é uma acção com permissão própria; cancelar uma reserva paga regista
 * o reembolso previsto e marca a nota de crédito como pendente (o próprio Vendas exige anular primeiro o recibo de uma factura paga).
 * O recibo fica por contabilizar na rotina normal de Vendas; a factura contabiliza-se aqui porque o recibo o exige.
 */
final class ServicoReservasFinanceiro
{
    public function __construct(
        private readonly ServicoReservasEspaco $reservas,
        private readonly ServicoCaucaoReserva $caucao,
        private readonly ServicoConfiguracaoImobiliario $config,
        private readonly ServicoDocumentosVenda $vendas,
        private readonly ServicoRecibosVenda $recibos,
        private readonly ServicoContabilizacaoVendas $contabilizacao,
    ) {}

    // ---------------------------------------------------------------- situação

    /** @return array<string, mixed> o que foi facturado, recebido e falta, o prazo e a caução */
    public function situacao(ReservaEspaco $r): array
    {
        $e = EspacoComum::query()->find($r->espaco_id);
        $paga = (bool) ($e && CatalogoParqueamento::REGIMES[$e->regime]['paga']) && (float) $r->valor > 0;
        $venda = $r->venda_id ? Venda::query()->find($r->venda_id, ['id', 'numero_documento', 'total_bruto', 'valor_pago', 'valor_pendente', 'estado', 'contabilizado']) : null;
        $facturado = $venda ? number_format((float) $venda->total_bruto, 2, '.', '') : '0.00';
        $recebido = $venda ? number_format((float) $venda->valor_pago, 2, '.', '') : '0.00';
        $porPagar = $venda ? number_format(max(0, (float) $venda->valor_pendente), 2, '.', '') : number_format((float) $r->valor, 2, '.', '');
        $pago = ! $paga || ($venda !== null && (float) $porPagar <= 0.009);
        $caducou = $r->prazo_pagamento !== null && $r->estado === 'AGUARDA_PAGAMENTO' && $r->prazo_pagamento->lessThan(now());
        $vist = fn (string $q) => VistoriaReserva::query()->where('reserva_id', $r->id)->where('quando', $q)->orderByDesc('id')->first();
        $antes = $vist('ANTES');
        $depois = $vist('DEPOIS');

        return ['valor' => (float) $r->valor, 'facturado' => (float) $facturado, 'recebido' => (float) $recebido, 'por_pagar' => (float) $porPagar, 'paga' => $paga, 'pago' => $pago,
            'venda_id' => $r->venda_id ? (int) $r->venda_id : null, 'documento' => $venda?->numero_documento, 'venda_estado' => $venda?->estado, 'prazo' => $r->prazo_pagamento?->toIso8601String(), 'caducou' => $caducou,
            'caucao' => $this->caucao->resumo($r), 'vistoria_antes' => $antes?->only(['id', 'estado', 'observacoes', 'url', 'data', 'custo_danos']),
            'vistoria_depois' => $depois?->only(['id', 'estado', 'observacoes', 'url', 'data', 'custo_danos']),
            'reembolso' => $r->reembolso_previsto === null ? null : ['previsto' => (float) $r->reembolso_previsto, 'retido' => (float) $r->reembolso_retido, 'pct' => $r->reembolso_pct, 'nc_pendente' => (bool) $r->nc_pendente,
                'nota_credito_id' => $r->nota_credito_id ? (int) $r->nota_credito_id : null]];
    }

    // ---------------------------------------------------------------- facturar

    /**
     * Factura a utilização da reserva ao reservante (a caução não entra na factura). Idempotente: repetir devolve a factura existente.
     *
     * @return array{reserva: ReservaEspaco, venda_id: int, numero_documento: string, repetida: bool}
     */
    public function facturar(ReservaEspaco $reserva, ?string $data = null): array
    {
        return DB::transaction(function () use ($reserva, $data) {
            $r = ReservaEspaco::query()->whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            if ($r->venda_id !== null) {
                $v = Venda::query()->findOrFail($r->venda_id);

                return ['reserva' => $r, 'venda_id' => (int) $v->id, 'numero_documento' => (string) $v->numero_documento, 'repetida' => true];
            }
            $e = EspacoComum::query()->findOrFail($r->espaco_id);
            if (! CatalogoParqueamento::REGIMES[$e->regime]['paga']) {
                throw new ErroNegocio("«{$e->nome}» não é de reserva paga: não há o que facturar.", 'RESERVA_NAO_PAGA');
            }
            if (! CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['viva']) {
                throw new ErroNegocio('Uma reserva «'.mb_strtolower(CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['rot']).'» não se factura.', 'RESERVA_NAO_VIVA');
            }
            if ($r->estado === 'PEDIDA') {
                throw new ErroNegocio('Aprove a reserva antes de a facturar — uma factura selada não se corrige.', 'RESERVA_NAO_APROVADA');
            }
            if ((float) $r->valor <= 0) {
                throw new ErroNegocio('A reserva não tem valor. Reveja o preço do espaço.', 'RESERVA_SEM_VALOR');
            }
            if ($r->interna || ! $r->terceiro_id) {
                throw new ErroNegocio('Uma reserva interna não tem a quem facturar. Marque-a em nome de uma fracção com contrato.', 'RESERVA_INTERNA');
            }
            $produto = $this->config->produtoDaReserva();
            $valor = number_format((float) $r->valor, 2, '.', '');
            $fr = $r->fracao_id ? Fracao::query()->find($r->fracao_id, ['id', 'designacao']) : null;
            $venda = $this->vendas->emitir(['tipo_documento' => 'FT', 'cliente_id' => $r->terceiro_id, 'data_emissao' => Normaliza::texto($data) ?? now()->toDateString(),
                'linhas' => [['produto_id' => $produto->id, 'quantidade' => '1', 'preco_unitario' => $valor, 'descricao' => "Utilização de {$e->nome} — {$r->valor_detalhe}"]],
                'observacoes' => "{$e->nome} · {$r->data->format('Y-m-d')} {$r->hora_inicio}–{$r->hora_fim}".($fr ? " — {$fr->designacao}" : '').($r->motivo ? " ({$r->motivo})" : '').". Reserva #{$r->id}."]);
            if (bccomp(number_format((float) $venda->total_bruto, 2, '.', ''), $valor, 2) !== 0) {
                throw new ErroNegocio('O total da factura não bate com o valor da reserva. Reveja a configuração do artigo das reservas e o IVA.', 'IMO_FACTURA_NAO_BATE');
            }
            $r->update(['venda_id' => $venda->id, 'atualizado_por' => Autor::nome()]);
            if ($r->estado === 'APROVADA') {
                $this->reservas->mudarEstado($r, 'AGUARDA_PAGAMENTO', null, true);
            }
            $this->reservas->registar($r, 'FACTURADA', null, null, null, ['documento' => $venda->numero_documento, 'valor' => $valor]);

            return ['reserva' => $r->refresh(), 'venda_id' => (int) $venda->id, 'numero_documento' => (string) $venda->numero_documento, 'repetida' => false];
        });
    }

    // ---------------------------------------------------------------- receber

    /**
     * Regista o recebimento da utilização (recibo sobre a factura) e/ou da caução. A utilização é receita; a caução é dinheiro à guarda da
     * empresa, a devolver: seguem caminhos diferentes. Confirma a reserva quando tudo o que é devido está pago.
     *
     * @param  array{valor?: float|string|null, caucao?: float|string|null, data?: ?string, meio_pagamento?: ?string, conta_financeira?: ?string, referencia?: ?string, chave?: ?string}  $d
     * @return array{reserva: ReservaEspaco, recibo_id: ?int, valor: string, caucao: string}
     */
    public function receber(ReservaEspaco $reserva, array $d): array
    {
        $conta = Normaliza::texto($d['conta_financeira'] ?? null) ?? throw new ErroNegocio('Indique a conta de caixa ou banco onde o dinheiro entrou.', 'RESERVA_SEM_CONTA');

        return DB::transaction(function () use ($reserva, $d, $conta) {
            $r = ReservaEspaco::query()->whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            if (! CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['viva'] || $r->estado === 'PEDIDA') {
                throw new ErroNegocio('Uma reserva «'.mb_strtolower(CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['rot']).'» não recebe pagamentos.', 'RESERVA_NAO_RECEBE');
            }
            $s = $this->situacao($r);
            $valor = isset($d['valor']) && $d['valor'] !== '' ? number_format((float) $d['valor'], 2, '.', '') : ($r->venda_id ? number_format($s['por_pagar'], 2, '.', '') : '0.00');
            $caucao = isset($d['caucao']) && $d['caucao'] !== '' ? number_format((float) $d['caucao'], 2, '.', '') : number_format(max(0, (float) $r->caucao - (float) $r->caucao_recebida), 2, '.', '');
            if (bccomp($valor, '0', 2) < 0 || bccomp($caucao, '0', 2) < 0) {
                throw new ErroNegocio('Os valores recebidos não podem ser negativos.', 'RESERVA_VALOR');
            }
            if (bccomp(bcadd($valor, $caucao, 2), '0', 2) <= 0) {
                throw new ErroNegocio('Não há nada por receber nesta reserva.', 'RESERVA_NADA_A_RECEBER');
            }
            $reciboId = null;
            if (bccomp($valor, '0', 2) > 0) {
                if (! $r->venda_id) {
                    throw new ErroNegocio('Facture a reserva antes de registar o recebimento.', 'RESERVA_SEM_FACTURA');
                }
                if (bccomp($valor, number_format($s['por_pagar'], 2, '.', ''), 2) > 0) {
                    throw new ErroNegocio('Está a receber '.number_format((float) $valor, 2, ',', ' ').' e só faltam '.number_format($s['por_pagar'], 2, ',', ' ').'.', 'RESERVA_EXCEDE');
                }
                $venda = Venda::query()->lockForUpdate()->findOrFail($r->venda_id);
                if (! $venda->contabilizado) {
                    $this->contabilizacao->contabilizar($venda);
                }
                $recibo = $this->recibos->criar(['cliente_id' => $r->terceiro_id, 'data' => Normaliza::texto($d['data'] ?? null) ?? now()->toDateString(), 'codigo_conta' => $conta,
                    'meio_pagamento' => Normaliza::texto($d['meio_pagamento'] ?? null) ?? 'NUMERARIO', 'referencia_pagamento' => Normaliza::texto($d['referencia'] ?? null) ?? "Reserva #{$r->id}",
                    'alocacoes' => [['venda_id' => $venda->id, 'montante' => $valor]]]);
                $reciboId = (int) $recibo->id;
            }
            if (bccomp($caucao, '0', 2) > 0) {
                if (bccomp(bcadd(number_format((float) $r->caucao_recebida, 2, '.', ''), $caucao, 2), number_format((float) $r->caucao, 2, '.', ''), 2) > 0) {
                    throw new ErroNegocio('A caução recebida ficaria acima da caução devida.', 'IMO_CAUCAO_EXCEDE_ACORDADA');
                }
                $this->caucao->movimentar($r, ['tipo' => 'RECEBIDA', 'valor' => $caucao, 'data' => $d['data'] ?? null, 'conta_financeira' => $conta,
                    'chave' => isset($d['chave']) && $d['chave'] !== '' ? 'rec:'.$d['chave'] : null]);
            }
            $r = ReservaEspaco::query()->findOrFail($r->id);
            $s = $this->situacao($r);
            $this->reservas->registar($r, 'RECEBIDO', null, null, null, ['utilizacao' => $valor, 'caucao' => $caucao, 'recibo_id' => $reciboId]);
            if ($r->estado === 'AGUARDA_PAGAMENTO' && $s['pago'] && (float) $r->caucao_recebida >= (float) $r->caucao - 0.009) {
                $r = $this->reservas->mudarEstado($r, 'CONFIRMADA', null, true);
            }

            return ['reserva' => $r->refresh(), 'recibo_id' => $reciboId, 'valor' => $valor, 'caucao' => $caucao];
        });
    }

    // ---------------------------------------------------------------- vistoria

    /**
     * Vistoria de entrada (o estado em que se entregou o espaço) ou de saída (o que justifica reter caução).
     *
     * @param  array{quando: string, estado?: ?string, observacoes?: ?string, url?: ?string, data?: ?string, custo_danos?: float|string|null}  $d
     */
    public function vistoria(ReservaEspaco $r, array $d): VistoriaReserva
    {
        $quando = (string) ($d['quando'] ?? '');
        if (! in_array($quando, VistoriaReserva::QUANDO, true)) {
            throw new ErroNegocio('A vistoria é «ANTES» ou «DEPOIS».', 'VISTORIA_QUANDO');
        }
        $estado = (string) ($d['estado'] ?? 'BOM');
        if (! in_array($estado, VistoriaReserva::ESTADOS, true)) {
            throw new ErroNegocio('Estado da vistoria desconhecido.', 'VISTORIA_ESTADO');
        }
        if (in_array($r->estado, ['RECUSADA', 'PEDIDA'], true)) {
            throw new ErroNegocio('Uma reserva «'.mb_strtolower(CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['rot']).'» não tem vistoria.', 'VISTORIA_RESERVA');
        }
        $url = Normaliza::url($d['url'] ?? null, 'Comprovativo da vistoria');
        $custo = isset($d['custo_danos']) && $d['custo_danos'] !== '' ? Normaliza::r2($d['custo_danos']) : null;
        if ($custo !== null && $custo < 0) {
            throw new ErroNegocio('O custo dos danos não pode ser negativo.', 'VISTORIA_CUSTO');
        }

        return DB::transaction(function () use ($r, $quando, $estado, $d, $url, $custo) {
            $v = VistoriaReserva::create(['reserva_id' => $r->id, 'quando' => $quando, 'estado' => $estado, 'observacoes' => Normaliza::texto($d['observacoes'] ?? null), 'url' => $url !== '' ? $url : null,
                'data' => Normaliza::texto($d['data'] ?? null) ?? now()->toDateString(), 'custo_danos' => $custo, 'criado_por' => Autor::nome()]);
            $this->reservas->registar($r, 'VISTORIA', null, null, null, ['quando' => $quando, 'estado' => $estado]);

            return $v;
        });
    }

    // ---------------------------------------------------------------- cancelar

    /**
     * Previsão do cancelamento: quanto se devolve e quanto fica retido, pela escala do espaço (devolve tudo até `cancel_dias` antes; depois
     * `cancel_pct` %), e a caução a devolver.
     *
     * @return array<string, mixed>
     */
    public function previsaoCancelamento(ReservaEspaco $r, ?string $quando = null): array
    {
        $e = EspacoComum::query()->findOrFail($r->espaco_id);
        $s = $this->situacao($r);
        $faltam = (int) CarbonImmutable::parse($quando ?? now()->toDateString())->diffInDays(CarbonImmutable::parse($r->data->format('Y-m-d')), false);
        $recebido = number_format($s['recebido'], 2, '.', '');
        $x = CalculadoraReserva::reembolso($recebido, $faltam, (int) $e->cancel_dias, (int) $e->cancel_pct);

        return ['faltam_dias' => $faltam, 'recebido' => (float) $recebido, 'pct' => $x['pct'], 'devolve' => (float) $x['devolve'], 'retem' => (float) $x['retem'],
            'caucao_a_devolver' => round((float) $r->caucao_recebida - (float) $r->caucao_retida - (float) $r->caucao_devolvida, 2),
            'explicacao' => CalculadoraReserva::explicacao($recebido, $faltam, (int) $e->cancel_dias, $x['pct']), 'facturada' => $r->venda_id !== null];
    }

    /**
     * Cancela uma reserva com a escala de reembolso. Factura por pagar: emite a nota de crédito (idempotente: `nota_credito_id`). Com dinheiro
     * recebido regista o reembolso previsto e deixa a nota de crédito pendente (em Vendas anula-se primeiro o recibo). A caução recebida fica
     * por devolver (sinalizada nos avisos).
     *
     * @return array<string, mixed> a previsão aplicada
     */
    public function cancelar(ReservaEspaco $reserva, ?string $motivo, float|string|null $devolver = null): array
    {
        $motivo = Normaliza::texto($motivo) ?? throw new ErroNegocio('Diga o motivo do cancelamento.', 'RESERVA_MOTIVO');

        return DB::transaction(function () use ($reserva, $motivo, $devolver) {
            $r = ReservaEspaco::query()->whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            if (! CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['viva']) {
                throw new ErroNegocio('Uma reserva «'.mb_strtolower(CatalogoParqueamento::ESTADOS_RESERVA[$r->estado]['rot']).'» não se cancela.', 'RESERVA_NAO_VIVA');
            }
            $p = $this->previsaoCancelamento($r);
            $devolve = $p['devolve'];
            if ($devolver !== null && $devolver !== '') {
                if ((float) $devolver < 0 || (float) $devolver > $p['recebido'] + 0.001) {
                    throw new ErroNegocio('Não se devolve mais do que o que foi recebido.', 'RESERVA_REEMBOLSO');
                }
                $devolve = round((float) $devolver, 2);
            }
            $retem = round($p['recebido'] - $devolve, 2);
            $extra = [];
            if ($r->venda_id !== null && $p['recebido'] <= 0.009) {
                $extra['nota_credito_id'] = $this->notaDeCredito($r, "Reserva cancelada: {$motivo}");
            } elseif ($r->venda_id !== null) {
                $extra += ['reembolso_previsto' => $devolve, 'reembolso_retido' => $retem, 'reembolso_pct' => $p['pct'], 'nc_pendente' => true];
            }
            $r->update($extra);
            $this->reservas->mudarEstado($r, 'CANCELADA', $motivo);
            $this->reservas->registar($r->refresh(), 'CANCELADA_FINANCEIRO', null, null, $motivo, ['recebido' => $p['recebido'], 'devolve' => $devolve, 'retem' => $retem, 'pct' => $p['pct']]);

            return $p + ['devolve_aplicado' => $devolve, 'retem_aplicado' => $retem];
        });
    }

    /** Emite a nota de crédito total da factura (por pagar) da reserva e devolve o seu id. Idempotente: já emitida devolve a existente. */
    private function notaDeCredito(ReservaEspaco $r, string $motivo): int
    {
        if ($r->nota_credito_id !== null) {
            return (int) $r->nota_credito_id;
        }
        $origem = Venda::query()->findOrFail($r->venda_id);
        $item = $origem->itensVenda()->orderBy('id')->firstOrFail();
        $nc = $this->vendas->emitir(['tipo_documento' => 'NC', 'cliente_id' => $r->terceiro_id, 'data_emissao' => now()->toDateString(), 'venda_origem_id' => $origem->id,
            'motivo_nota_credito' => mb_substr($motivo, 0, 300), 'linhas' => [['produto_id' => $item->produto_id, 'quantidade' => '1']]]);

        return (int) $nc->id;
    }

    // ---------------------------------------------------------------- caducidade

    /**
     * Caduca as reservas a aguardar pagamento cujo prazo passou: se afinal está tudo pago, confirma-a; se não recebeu nada, cancela-a e liberta o
     * horário (com nota de crédito da factura emitida); com pagamento parcial não a cancela em silêncio (fica sinalizada). Cada reserva na sua
     * transacção, repetível. Corre por tarefa agendada (erp:imobiliario:caducar-reservas) e ao abrir o ecrã de áreas comuns.
     *
     * @return array{caducadas: int, confirmadas: int, parciais: int, falhas: int}
     */
    public function caducarPendentes(?int $imovelId = null, ?CarbonImmutable $agora = null): array
    {
        $agora ??= CarbonImmutable::now();
        $ids = ReservaEspaco::query()->where('estado', 'AGUARDA_PAGAMENTO')->where('prazo_pagamento', '<', $agora)->when($imovelId, fn ($q) => $q->where('imovel_id', $imovelId))
            ->orderBy('id')->limit(500)->pluck('id');
        $res = ['caducadas' => 0, 'confirmadas' => 0, 'parciais' => 0, 'falhas' => 0];
        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, $agora, &$res) {
                    $r = ReservaEspaco::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $r || $r->estado !== 'AGUARDA_PAGAMENTO' || $r->prazo_pagamento === null || ! $r->prazo_pagamento->lessThan($agora)) {
                        return;   // entretanto mudou: nada a fazer (repetível)
                    }
                    $s = $this->situacao($r);
                    if ($s['pago'] && (float) $r->caucao_recebida >= (float) $r->caucao - 0.009) {
                        $this->reservas->mudarEstado($r, 'CONFIRMADA', null, true);
                        $res['confirmadas']++;

                        return;
                    }
                    if ($s['recebido'] > 0.009 || (float) $r->caucao_recebida > 0.009) {
                        $res['parciais']++;

                        return;   // há dinheiro de terceiros envolvido: decide uma pessoa (aviso na lista)
                    }
                    $motivo = 'Prazo de pagamento esgotado ('.$r->prazo_pagamento->format('Y-m-d H:i').'). O horário foi libertado.';
                    $extra = ['caducada' => true];
                    if ($r->venda_id !== null) {
                        $extra['nota_credito_id'] = $this->notaDeCredito($r, $motivo);
                    }
                    $r->update($extra);
                    $this->reservas->mudarEstado($r, 'CANCELADA', $motivo, true);
                    $res['caducadas']++;
                });
            } catch (Throwable $e) {
                $res['falhas']++;
                report($e);
            }
        }

        return $res;
    }

    // ---------------------------------------------------------------- contas e avisos

    /**
     * Resumo financeiro das reservas (de um imóvel e período): facturado, recebido, por cobrar, cauções em poder e retidas, receita.
     *
     * @param  array{imovel_id?: ?int, de?: ?string, ate?: ?string}  $f
     * @return array<string, mixed>
     */
    public function contas(array $f = []): array
    {
        $q = ReservaEspaco::query()->where('estado', '<>', 'RECUSADA')->when(! empty($f['imovel_id']), fn ($x) => $x->where('imovel_id', (int) $f['imovel_id']))
            ->when(! empty($f['de']), fn ($x) => $x->where('data', '>=', $f['de']))->when(! empty($f['ate']), fn ($x) => $x->where('data', '<=', $f['ate']));
        $reservas = $q->limit(5000)->get();
        $vendas = Venda::query()->whereIn('id', $reservas->pluck('venda_id')->filter()->unique())->get(['id', 'total_bruto', 'valor_pago', 'valor_pendente'])->keyBy('id');
        $t = ['reservas' => $reservas->count(), 'facturado' => 0.0, 'recebido' => 0.0, 'por_cobrar' => 0.0, 'caucoes_em_poder' => 0.0, 'caucoes_retidas' => 0.0, 'reembolsos_pendentes' => 0.0];
        foreach ($reservas as $r) {
            $v = $r->venda_id ? ($vendas[$r->venda_id] ?? null) : null;
            if ($v) {
                $t['facturado'] += (float) $v->total_bruto;
                $t['recebido'] += (float) $v->valor_pago;
                if ($r->estado !== 'CANCELADA') {
                    $t['por_cobrar'] += max(0, (float) $v->valor_pendente);
                }
            }
            $t['caucoes_em_poder'] += (float) $r->caucao_recebida - (float) $r->caucao_devolvida - (float) $r->caucao_retida;
            $t['caucoes_retidas'] += (float) $r->caucao_retida;
            if ($r->nc_pendente) {
                $t['reembolsos_pendentes'] += (float) $r->reembolso_previsto;
            }
        }
        // a receita é a utilização recebida (menos o que se devolve nos cancelamentos) mais a caução retida por danos
        $t['receita_total'] = $t['recebido'] - $t['reembolsos_pendentes'] + $t['caucoes_retidas'];

        return array_map(fn ($x) => is_float($x) ? round($x, 2) : $x, $t);
    }

    /** @return list<string> */
    public function avisos(?int $imovelId = null): array
    {
        $c = $this->contas(['imovel_id' => $imovelId]);
        if ($c['reservas'] === 0) {
            return [];
        }
        $avisos = [];
        if ($c['por_cobrar'] > 0.009) {
            $avisos[] = number_format($c['por_cobrar'], 2, ',', ' ').' Kz facturados de reservas e ainda por receber.';
        }
        if ($c['caucoes_em_poder'] > 0.009) {
            $avisos[] = number_format($c['caucoes_em_poder'], 2, ',', ' ').' Kz de cauções em poder da empresa — dinheiro de terceiros que está por devolver.';
        }
        $q = fn () => ReservaEspaco::query()->when($imovelId, fn ($x) => $x->where('imovel_id', $imovelId));
        $terminadas = $q()->whereIn('estado', ['REALIZADA', 'CANCELADA', 'FALTOU'])->whereRaw('(caucao_recebida - caucao_devolvida - caucao_retida) > 0.009')->count();
        if ($terminadas > 0) {
            $avisos[] = "{$terminadas} reserva(s) terminadas com a caução por devolver.";
        }
        $semFactura = $q()->whereIn('estado', ['APROVADA', 'AGUARDA_PAGAMENTO', 'CONFIRMADA'])->where('valor', '>', 0)->where('interna', false)->whereNull('venda_id')->count();
        if ($semFactura > 0) {
            $avisos[] = "{$semFactura} reserva(s) com valor e ainda sem factura.";
        }
        $nc = $q()->where('nc_pendente', true)->count();
        if ($nc > 0) {
            $avisos[] = "{$nc} reserva(s) canceladas com reembolso por tratar: anule o recibo e emita a nota de crédito em Vendas.";
        }
        $parciais = $q()->where('estado', 'AGUARDA_PAGAMENTO')->where('prazo_pagamento', '<', now())->count();
        if ($parciais > 0) {
            $avisos[] = "{$parciais} reserva(s) com o prazo de pagamento esgotado e dinheiro já recebido: decida se se confirma ou se se cancela com reembolso.";
        }

        return $avisos;
    }

    /** @return list<array<string, mixed>> o trilho da reserva */
    public function historico(ReservaEspaco $r): array
    {
        return HistoricoReservaImo::query()->where('reserva_id', $r->id)->orderBy('em')->orderBy('id')->get()->map(fn ($h) => $h->only(['id', 'evento', 'estado_de', 'estado_para', 'motivo', 'dados', 'utilizador_id', 'em']))->all();
    }
}
