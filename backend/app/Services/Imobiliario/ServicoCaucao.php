<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoArrendamento;
use App\Models\LancamentoContabil;
use App\Models\MovimentoCaucaoImo;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Caução do contrato: ledger imutável (`movimentos_caucao_imo`: recebida, devolvida, retida) com lançamentos contabilísticos
 * equilibrados e idempotentes. No lado senhorio a caução é um passivo (dinheiro de terceiros): recebida D disponibilidades / C passivo;
 * devolvida D passivo / C disponibilidades; retida D passivo / C receita. No lado inquilino é um activo (caução prestada): entregue
 * D activo / C disponibilidades; devolvida o inverso; retida pelo senhorio D conta de custo do contrato / C activo. As contas são da
 * configuração da empresa (nunca inventadas aqui; decisão P4) e a empresa valida-as com o contabilista. O movimento e o seu
 * lançamento nascem na mesma transacção; repetir o pedido com a mesma `chave` devolve o movimento já registado sem lançar de novo, e
 * `contabilizar` é repetível. Corrigir é anular (estorno ligado ao original), nunca apagar. Os totais do contrato
 * (`caucao_recebida/devolvida/retida`) são sempre recalculados a partir dos movimentos válidos. Quem movimenta a caução não termina
 * o contrato (par de segregação do catálogo): terminar só avisa que há caução em poder.
 */
final class ServicoCaucao
{
    public const ORIGEM_LANCAMENTO = 'IMOBILIARIO';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoConfiguracaoImobiliario $config,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /** @return array<string, mixed> os movimentos e o resumo (acordada, recebida, devolvida, retida, em poder, por receber) */
    public function listar(ContratoArrendamento $c): array
    {
        $mov = MovimentoCaucaoImo::query()->where('contrato_id', $c->id)->orderBy('data')->orderBy('id')->get();
        $emPoder = round((float) $c->caucao_recebida - (float) $c->caucao_devolvida - (float) $c->caucao_retida, 2);

        return ['movimentos' => $mov->map(fn ($m) => $m->attributesToArray())->all(), 'resumo' => ['acordada' => (float) $c->caucao, 'recebida' => (float) $c->caucao_recebida,
            'devolvida' => (float) $c->caucao_devolvida, 'retida' => (float) $c->caucao_retida, 'em_poder' => $emPoder, 'por_receber' => round(max(0, (float) $c->caucao - (float) $c->caucao_recebida), 2),
            'lado' => $c->posicao]];
    }

    /**
     * Regista um movimento da caução e contabiliza-o. `chave` (opcional) torna o pedido repetível sem duplicar.
     *
     * @param  array{tipo: string, valor: float|string, data?: ?string, conta_financeira?: ?string, motivo?: ?string, chave?: ?string}  $d
     */
    public function movimentar(ContratoArrendamento $contrato, array $d): MovimentoCaucaoImo
    {
        $tipo = (string) ($d['tipo'] ?? '');
        if (! in_array($tipo, MovimentoCaucaoImo::TIPOS, true)) {
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
            throw new ErroNegocio('Retida sem motivo escrito é uma retenção indefensável: indique o motivo.', 'IMO_CAUCAO_MOTIVO');
        }
        if ($tipo !== 'RETIDA' && $conta === null) {
            throw new ErroNegocio('Indique a conta de disponibilidades (banco) por onde a caução entra ou sai.', 'IMO_CAUCAO_CONTA');
        }
        if ($conta !== null) {
            $this->plano->contaDeMovimento($conta);
        }

        return DB::transaction(function () use ($contrato, $tipo, $valor, $data, $motivo, $conta, $chave) {
            $c = ContratoArrendamento::query()->lockForUpdate()->findOrFail($contrato->id);
            if ($chave !== null && ($existente = MovimentoCaucaoImo::query()->where('chave', $chave)->first())) {
                if ($existente->contrato_id !== $c->id || $existente->tipo !== $tipo || bccomp(number_format((float) $existente->valor, 2, '.', ''), $valor, 2) !== 0) {
                    throw new ErroNegocio('Esta chave já foi usada noutro movimento.', 'IMO_CAUCAO_CHAVE_REPETIDA', 409);
                }

                return $existente;   // pedido repetido: o movimento já existe e está contabilizado
            }
            $this->conferirLimites($c, $tipo, $valor);
            $this->exigirContas($c, $tipo);
            $m = MovimentoCaucaoImo::create(['contrato_id' => $c->id, 'tipo' => $tipo, 'valor' => $valor, 'data' => $data, 'conta_financeira' => $conta, 'motivo' => $motivo,
                'chave' => $chave ?? 'auto:'.Str::uuid()->toString(), 'estado' => 'VALIDO', 'criado_por' => Autor::nome()]);
            $this->contabilizar($m);
            $this->recalcular($c);

            return $m->refresh();
        });
    }

    /** @throws ErroNegocio se o movimento ultrapassa a caução acordada (recebida) ou o que está em poder (devolvida, retida) */
    private function conferirLimites(ContratoArrendamento $c, string $tipo, string $valor): void
    {
        $rec = number_format((float) $c->caucao_recebida, 2, '.', '');
        $emPoder = bcsub(bcsub($rec, number_format((float) $c->caucao_devolvida, 2, '.', ''), 2), number_format((float) $c->caucao_retida, 2, '.', ''), 2);
        if ($tipo === 'RECEBIDA' && bccomp(bcadd($rec, $valor, 2), number_format((float) $c->caucao, 2, '.', ''), 2) > 0) {
            throw new ErroNegocio('A caução recebida não pode passar da caução acordada ('.number_format((float) $c->caucao, 2, ',', ' ').' Kz).', 'IMO_CAUCAO_EXCEDE_ACORDADA');
        }
        if ($tipo !== 'RECEBIDA' && bccomp($valor, $emPoder, 2) > 0) {
            throw new ErroNegocio('Só há '.number_format((float) $emPoder, 2, ',', ' ').' Kz de caução em poder: o movimento não pode ser maior.', 'IMO_CAUCAO_EXCEDE_EM_PODER');
        }
    }

    private function exigirContas(ContratoArrendamento $c, string $tipo): void
    {
        $cfg = $this->config->actual();
        $conta = $c->posicao === 'SENHORIO' ? $cfg?->conta_caucao_passivo : $cfg?->conta_caucao_activo;
        if (! $conta) {
            throw new ErroNegocio($c->posicao === 'SENHORIO' ? 'Falta a conta do passivo «cauções de terceiros» na configuração do módulo imobiliário.'
                : 'Falta a conta do activo «cauções prestadas» na configuração do módulo imobiliário.', 'IMO_SEM_CONTA_CAUCAO');
        }
        if ($tipo === 'RETIDA' && $c->posicao === 'SENHORIO' && ! $cfg?->conta_caucao_receita) {
            throw new ErroNegocio('Falta a conta de receita da caução retida na configuração do módulo imobiliário.', 'IMO_SEM_CONTA_CAUCAO');
        }
        if ($tipo === 'RETIDA' && $c->posicao === 'INQUILINO' && ! $c->conta_custo) {
            throw new ErroNegocio('O contrato não tem conta de custo para a caução retida pelo senhorio.', 'IMO_SEM_CONTA_CAUCAO');
        }
    }

    /**
     * Lança o movimento na contabilidade (diário IMO), equilibrado. Idempotente: um movimento já contabilizado não se lança de novo.
     * Corre dentro da transacção do movimento; quem o chama fora dela (reprocessamento) fica protegido pelo bloqueio da linha.
     */
    public function contabilizar(MovimentoCaucaoImo $movimento): MovimentoCaucaoImo
    {
        return DB::transaction(function () use ($movimento) {
            $m = MovimentoCaucaoImo::query()->lockForUpdate()->findOrFail($movimento->id);
            if ($m->lancamento_numero !== null || $m->estado !== 'VALIDO') {
                return $m;
            }
            $c = ContratoArrendamento::query()->findOrFail($m->contrato_id);
            $cfg = $this->config->actual();
            $valor = number_format((float) $m->valor, 2, '.', '');
            $fin = (string) $m->conta_financeira;
            if ($c->posicao === 'SENHORIO') {
                $passivo = (string) $cfg?->conta_caucao_passivo;
                [$deb, $cre] = match ($m->tipo) {
                    'RECEBIDA' => [$fin, $passivo],
                    'DEVOLVIDA' => [$passivo, $fin],
                    default => [$passivo, (string) $cfg?->conta_caucao_receita],
                };
            } else {
                $activo = (string) $cfg?->conta_caucao_activo;
                [$deb, $cre] = match ($m->tipo) {
                    'RECEBIDA' => [$activo, $fin],
                    'DEVOLVIDA' => [$fin, $activo],
                    default => [(string) $c->conta_custo, $activo],
                };
            }
            $texto = 'Caução '.mb_strtolower(CatalogoContratos::TIPOS_CAUCAO[$m->tipo]).' — contrato '.$c->numero;
            $linhas = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('IMO', 'Imobiliário')->id, 'data_documento' => $m->data->format('Y-m-d'),
                'numero_documento' => 'IMO-CAU-'.$m->id, 'referencia' => $c->numero, 'descricao' => $texto, 'tipo_origem' => self::ORIGEM_LANCAMENTO, 'linhas' => [
                    ['codigo_conta' => $deb, 'tipo_dc' => 'D', 'valor' => $valor, 'terceiro_id' => $c->terceiro_id, 'descricao' => $texto],
                    ['codigo_conta' => $cre, 'tipo_dc' => 'C', 'valor' => $valor, 'terceiro_id' => $c->terceiro_id, 'descricao' => $texto],
                ]]);
            $m->update(['lancamento_numero' => $linhas->first()->numero_lan]);

            return $m->refresh();
        });
    }

    /** Anula um movimento válido: estorna o lançamento (ligado ao original) e recalcula os totais; recusa se há movimentos que dele dependem. */
    public function anular(MovimentoCaucaoImo $movimento, ?string $motivo): MovimentoCaucaoImo
    {
        $motivo = Normaliza::texto($motivo) ?? throw new ErroNegocio('Indique o motivo da anulação.', 'IMO_MOTIVO_OBRIGATORIO');

        return DB::transaction(function () use ($movimento, $motivo) {
            $m = MovimentoCaucaoImo::query()->lockForUpdate()->findOrFail($movimento->id);
            $c = ContratoArrendamento::query()->lockForUpdate()->findOrFail($m->contrato_id);
            if ($m->estado !== 'VALIDO') {
                throw new ErroNegocio('O movimento já está anulado.', 'IMO_CAUCAO_JA_ANULADO');
            }
            if ($m->tipo === 'RECEBIDA') {
                $depois = (float) $c->caucao_recebida - (float) $m->valor;
                if ($depois + 0.001 < (float) $c->caucao_devolvida + (float) $c->caucao_retida) {
                    throw new ErroNegocio('Há devoluções ou retenções que dependem deste recebimento: anule-as primeiro.', 'IMO_CAUCAO_DEPENDENTE');
                }
            }
            $estorno = null;
            if ($m->lancamento_numero !== null) {
                $linha = LancamentoContabil::query()->where('numero_lan', $m->lancamento_numero)->orderBy('id')->firstOrFail();
                $estorno = $this->lancamentos->estornar($linha, 'Caução anulada: '.$motivo)->first()?->numero_lan;
            }
            $m->update(['estado' => 'ANULADO', 'anulado_por' => Autor::nome(), 'anulado_em' => now(), 'anulado_motivo' => mb_substr($motivo, 0, 300), 'estorno_numero' => $estorno]);
            $this->recalcular($c);

            return $m->refresh();
        });
    }

    /** Recalcula os totais do contrato a partir dos movimentos válidos. */
    public function recalcular(ContratoArrendamento $c): void
    {
        $soma = MovimentoCaucaoImo::query()->where('contrato_id', $c->id)->where('estado', 'VALIDO')->selectRaw('tipo, coalesce(sum(valor), 0) as total')->groupBy('tipo')->pluck('total', 'tipo');
        $c->update(['caucao_recebida' => $soma['RECEBIDA'] ?? 0, 'caucao_devolvida' => $soma['DEVOLVIDA'] ?? 0, 'caucao_retida' => $soma['RETIDA'] ?? 0]);
    }
}
