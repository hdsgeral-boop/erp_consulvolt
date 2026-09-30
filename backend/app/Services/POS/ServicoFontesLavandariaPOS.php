<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\DiarioContabil;
use App\Models\PagamentoLavandaria;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Vendas\ServicoConfigVendas;
use App\Services\Vendas\ServicoContabilizacaoVendas;
use Illuminate\Support\Collection;

/**
 * Fontes da lavandaria na sessão POS (fonteSessao / linhasIntegracao / marcar / desmarcar, js/lavandaria.js:2369-2482).
 * As facturas-recibo da lavandaria são vendas FR do terminal e já entram nos totais e na integração do POS; aqui entram:
 *   - recibos (ADIANTAMENTO/PAGAMENTO) recebidos na sessão: totais por meio, numerário esperado e transferências;
 *     integração D transitória do meio / C cliente;
 *   - facturas (FT) de lavandaria emitidas na sessão (sem dinheiro): integração D cliente / C proveitos + IVA.
 * Correcções face ao legado:
 *   - o Z deixa de somar recibos às vendas: total_vendas continua a ser só documentos; os recebimentos ficam à parte (`lavandaria`);
 *   - sem contas fixas ('31.1', '34.5.3', '62'): conta do cliente → configuração de vendas; proveitos e IVA do produto → configuração
 *     (ServicoContabilizacaoVendas::linhasProveitoEIva), com erro claro;
 *   - lançamento equilibrado por construção, sem «absorver até 1 Kz» (lavandaria.js:2455-2458): um desequilíbrio é um erro.
 */
final class ServicoFontesLavandariaPOS
{
    public function __construct(
        private readonly ServicoLancamentos $lancamentos,
        private readonly ServicoContabilizacaoVendas $vendas,
        private readonly ServicoConfigVendas $configVendas,
    ) {}

    /** Recibos (não facturas-recibo) não anulados recebidos na sessão. */
    public function recibos(SessaoPOS $s, bool $bloquear = false): Collection
    {
        return PagamentoLavandaria::query()->where('sessao_pos_id', $s->id)->whereIn('natureza_registo', ['ADIANTAMENTO', 'PAGAMENTO'])
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))->when($bloquear, fn ($q) => $q->lockForUpdate())->orderBy('id')->get();
    }

    /** Facturas (FT) de lavandaria emitidas na sessão. */
    public function faturas(SessaoPOS $s): Collection
    {
        return Venda::query()->where('sessao_pos_id', $s->id)->where('tipo_documento', 'FT')->whereNotNull('pedido_lavandaria_id')
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))->orderBy('id')->get();
    }

    /**
     * Soma os recibos aos totais por meio, ao numerário e às transferências (formato de ServicoSessoesPOS::totais) e devolve o resumo.
     *
     * @return array{numero_recibos: int, total_recibos: string, numero_faturas: int, total_faturas: string, movimento: bool}
     */
    public function acumular(SessaoPOS $s, array &$porMeio, array &$transferencias, string &$numerario): array
    {
        $recibos = $this->recibos($s);
        $total = '0.00';
        foreach ($recibos as $r) {
            $total = bcadd($total, (string) $r->montante, 2);
            foreach ($r->pos_pagamentos ?? [] as $p) {
                $valor = number_format((float) ($p['valor'] ?? 0), 2, '.', '');
                $k = $p['meio_id'] ?? $p['tipo'];
                $porMeio[$k] ??= ['meio_id' => $p['meio_id'] ?? null, 'tipo' => $p['tipo'], 'nome' => $p['nome'] ?? $p['tipo'], 'conta_transitoria' => $p['conta_transitoria'] ?? null,
                    'codigo_tpa' => $p['codigo_tpa'] ?? null, 'valor' => '0.00', 'quantidade' => 0];
                $porMeio[$k]['valor'] = bcadd($porMeio[$k]['valor'], $valor, 2);
                $porMeio[$k]['quantidade']++;
                if ($p['tipo'] === 'NUMERARIO') {
                    $numerario = bcadd($numerario, $valor, 2);
                }
                if ($p['tipo'] === 'TRANSFERENCIA') {
                    $transferencias[] = ['venda_id' => null, 'numero_documento' => $r->numero_recibo, 'valor' => $valor, 'referencia' => $p['referencia'] ?? null,
                        'meio_id' => $p['meio_id'] ?? null, 'terceiro_id' => $r->cliente_id, 'pagamento_lavandaria_id' => $r->id];
                }
            }
        }
        $faturas = $this->faturas($s);

        return ['numero_recibos' => $recibos->count(), 'total_recibos' => $total, 'numero_faturas' => $faturas->count(),
            'total_faturas' => number_format((float) $faturas->sum('total_bruto'), 2, '.', ''), 'movimento' => $recibos->isNotEmpty() || $faturas->isNotEmpty()];
    }

    /**
     * Integração: um lançamento por data (tipo_origem POS, sessão), com as facturas e os recibos de lavandaria do dia.
     *
     * @return list<string> números de lançamento criados
     */
    public function contabilizar(SessaoPOS $s, DiarioContabil $diario): array
    {
        $recibos = $this->recibos($s, true);
        $faturas = $this->faturas($s);
        $porDia = [];
        foreach ($faturas as $f) {
            $porDia[$f->data_emissao->toDateString()]['faturas'][] = $f;
        }
        foreach ($recibos as $r) {
            $porDia[$r->data->toDateString()]['recibos'][] = $r;
        }
        ksort($porDia);
        $lans = [];
        foreach ($porDia as $data => $g) {
            $linhas = $this->linhas($g['faturas'] ?? [], $g['recibos'] ?? []);
            $n = count($g['faturas'] ?? []) + count($g['recibos'] ?? []);
            $numeroLan = $this->lancamentos->criar([
                'diario_id' => $diario->id, 'data_documento' => $data, 'numero_documento' => $s->numero_z, 'tipo_origem' => 'POS', 'sessao_pos_id' => $s->id,
                'descricao' => mb_substr("{$s->numero_z} — lavandaria {$s->codigo_terminal} ({$n} doc.)", 0, 1000), 'linhas' => $linhas,
            ])->first()->numero_lan;
            $lans[] = $numeroLan;
            $ids = array_map(fn ($f) => $f->id, $g['faturas'] ?? []);
            if ($ids) {
                Venda::query()->whereIn('id', $ids)->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $numeroLan, 'pos_lans_contabilizacao' => json_encode([$numeroLan])]);
            }
            foreach ($g['recibos'] ?? [] as $r) {
                $r->update(['lans_contabilizacao' => [$numeroLan]]);
            }
        }

        return $lans;
    }

    /** Descontabilização da sessão: os recibos deixam de estar integrados (as vendas são desmarcadas pelo POS). */
    public function desmarcar(SessaoPOS $s): void
    {
        PagamentoLavandaria::query()->where('sessao_pos_id', $s->id)->update(['lans_contabilizacao' => null]);
    }

    private function linhas(array $faturas, array $recibos): array
    {
        $debitos = $linhas = $creditos = [];
        foreach ($faturas as $f) {
            $dim = ['unidade_negocio_id' => $f->unidade_negocio_id, 'centro_custo_id' => $f->centro_custo_id];
            $proveitos = $this->vendas->linhasProveitoEIva($f);
            $soma = array_reduce($proveitos, fn ($c, $l) => bcadd($c, $l['valor'], 2), '0.00');
            if (bccomp($soma, (string) $f->total_bruto, 2) !== 0) {
                throw new ErroNegocio("{$f->numero_documento}: as linhas somam {$soma} mas o total é {$f->total_bruto}.", 'TOTAIS_INCONSISTENTES', 422, ['venda_id' => $f->id]);
            }
            $linhas[] = ['codigo_conta' => $this->contaCliente($f->cliente_id, $f->numero_documento), 'tipo_dc' => 'D', 'valor' => $soma, 'terceiro_id' => $f->cliente_id,
                'numero_documento' => $f->numero_documento, 'descricao' => "Lavandaria {$f->numero_documento} · {$f->numero_pedido_lavandaria}"] + $dim;
            foreach ($proveitos as $c) {
                $k = "{$c['conta']}|".implode('|', $dim);
                $creditos[$k] ??= ['codigo_conta' => $c['conta'], 'tipo_dc' => 'C', 'valor' => '0.00'] + $dim;
                $creditos[$k]['valor'] = bcadd($creditos[$k]['valor'], $c['valor'], 2);
            }
        }
        foreach ($recibos as $r) {
            $pagos = '0.00';
            foreach ($r->pos_pagamentos ?? [] as $p) {
                $conta = $p['conta_transitoria'] ?? null;
                if (! $conta) {
                    throw new ErroNegocio("{$r->numero_recibo}: pagamento por {$p['nome']} sem conta transitória.", 'PAGAMENTO_SEM_CONTA', 422);
                }
                $valor = number_format((float) $p['valor'], 2, '.', '');
                $pagos = bcadd($pagos, $valor, 2);
                $trf = $p['tipo'] === 'TRANSFERENCIA';
                $k = $conta.($trf ? "|{$r->id}" : '');
                $debitos[$k] ??= ['codigo_conta' => $conta, 'tipo_dc' => 'D', 'valor' => '0.00']
                    + ($trf ? ['terceiro_id' => $r->cliente_id, 'numero_documento' => $r->numero_recibo, 'descricao' => "Transferência {$p['referencia']} — {$r->numero_recibo}"] : []);
                $debitos[$k]['valor'] = bcadd($debitos[$k]['valor'], $valor, 2);
            }
            if (bccomp($pagos, (string) $r->montante, 2) !== 0) {
                throw new ErroNegocio("{$r->numero_recibo}: os meios de pagamento ({$pagos}) não somam o valor ({$r->montante}).", 'PAGAMENTOS_INCONSISTENTES', 422,
                    ['pagamento_lavandaria_id' => $r->id]);
            }
            $linhas[] = ['codigo_conta' => $this->contaCliente($r->cliente_id, $r->numero_recibo), 'tipo_dc' => 'C', 'valor' => (string) $r->montante,
                'terceiro_id' => $r->cliente_id, 'numero_documento' => $r->numero_recibo, 'descricao' => "Lavandaria {$r->numero_recibo} · {$r->numero_encomenda}"];
        }

        return array_merge(array_values($debitos), $linhas, array_values($creditos));
    }

    private function contaCliente(?int $clienteId, string $documento): string
    {
        return Terceiro::query()->withTrashed()->find($clienteId)?->codigo_conta
            ?: $this->configVendas->exigir('clientes_default', "{$documento}: o cliente não tem conta contabilística.");
    }
}
