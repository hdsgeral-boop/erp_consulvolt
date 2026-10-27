<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\LancamentoContabil;
use App\Models\MeioPagamento;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoRelatoriosContabeis;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mapas próprios da Tesouraria (ecrã teso_gestao_mapas, ADR-064): disponibilidades e extracto de uma conta de
 * disponibilidades (43 Depósitos à ordem / 45 Caixa). Não recalcula nada: reutiliza o balancete e o razão da
 * contabilidade (ServicoRelatoriosContabeis), pelo que os saldos batem exactamente com esses mapas.
 */
final class ServicoMapasTesouraria
{
    /** Prefixos das contas de disponibilidades (PGC angolano). */
    public const PREFIXOS = ['43', '45'];

    public function __construct(
        private readonly ServicoRelatoriosContabeis $relatorios,
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoContasCartao $contasCartao,
    ) {}

    /**
     * Saldos das contas 43/45 à data (inclusive): o saldo final do balancete com data_inicio = data_fim = data, com os
     * filtros por omissão do balancete (FiltroMapas). Contas sem saldo nem movimento não aparecem.
     *
     * @return array<string, mixed>
     */
    public function disponibilidades(string $data): array
    {
        $bal = $this->relatorios->balancete(['data_inicio' => $data, 'data_fim' => $data, 'filtro_contas' => implode(',', self::PREFIXOS)]);
        $meios = MeioPagamento::query()->get(['codigo_conta', 'nome', 'codigo_moeda', 'ativo'])->keyBy('codigo_conta');
        $totais = ['bancos' => '0.00', 'caixa' => '0.00', 'total' => '0.00'];
        $contas = [];
        foreach ($bal['linhas'] as $l) {
            $grupo = substr((string) $l['codigo_conta'], 0, 2);
            $meio = $meios[$l['codigo_conta']] ?? null;
            $contas[] = [
                'codigo_conta' => $l['codigo_conta'], 'descricao' => $l['descricao'], 'grupo' => $grupo,
                'tipo' => $grupo === '45' ? 'CAIXA' : 'BANCO', 'meio_pagamento' => $meio?->nome, 'codigo_moeda' => $meio?->codigo_moeda,
                'saldo' => $l['saldo_final'],
            ];
            $chave = $grupo === '45' ? 'caixa' : 'bancos';
            $totais[$chave] = bcadd($totais[$chave], $l['saldo_final'], 2);
            $totais['total'] = bcadd($totais['total'], $l['saldo_final'], 2);
        }

        return ['data' => $data, 'contas' => $contas, 'totais' => $totais];
    }

    /**
     * Extracto de uma conta 43/45 ou cartão (razão da contabilidade): saldo inicial, movimentos com saldo corrido e
     * totais, com o terceiro {id, nome, nif} de cada movimento (uma consulta, sem N+1).
     *
     * @return array<string, mixed>
     */
    public function extratoConta(string $conta, string $inicio, string $fim): array
    {
        $conta = trim($conta);
        if (! in_array(substr($conta, 0, 2), self::PREFIXOS, true) && ! $this->contasCartao->ehCodigoCartao($conta)) {
            throw new ErroNegocio('O extracto da Tesouraria só abrange contas de disponibilidades (43, 45) ou de cartão.', 'CONTA_NAO_DISPONIBILIDADES', 422);
        }
        $razao = $this->relatorios->razao(['codigo_conta' => $conta, 'data_inicio' => $inicio, 'data_fim' => $fim]);
        $ids = collect($razao['movimentos'])->pluck('terceiro_id')->filter()->unique()->values();
        $terceiros = $ids->isEmpty() ? collect() : Terceiro::withTrashed()->whereIn('id', $ids)->get(['id', 'nome', 'nif'])->keyBy('id');
        $extra = $this->contrapartidasEReconciliacao($conta, $inicio, $fim);
        $movimentos = array_map(function ($m) use ($terceiros, $extra) {
            $t = $m->terceiro_id ? ($terceiros[$m->terceiro_id] ?? null) : null;
            $x = $extra[(int) $m->id] ?? null;

            return (array) $m + ['terceiro' => $t ? ['id' => $t->id, 'nome' => $t->nome, 'nif' => $t->nif] : null,
                'contrapartidas' => $x['contrapartidas'] ?? [], 'reconciliacao_codigo' => $x['reconciliacao_codigo'] ?? null,
                'reconciliacao_data' => $x['reconciliacao_data'] ?? null];
        }, $razao['movimentos']);
        $descricao = DB::table('plano_contas')->where('empresa_id', $this->contexto->obrigatorio())->where('codigo', $conta)->whereNull('eliminado_em')->value('descricao');

        return array_merge(['codigo_conta' => $conta, 'descricao' => $descricao, 'data_inicio' => $inicio, 'data_fim' => $fim], $razao, ['movimentos' => $movimentos]);
    }

    /**
     * Colunas «Contrapartida» e «Estado Recon.» do extracto do legado (ui_tesouraria.js:3455-3511), numa só consulta
     * agregada pelo mesmo âmbito do razão (empresa, conta, período): as contrapartidas são as contas distintas das
     * OUTRAS linhas do mesmo lançamento (diário + chave, ADR-025 — a mesma regra do extracto de conta corrente da
     * contabilidade, ServicoRelatoriosContabeis::extrato); a reconciliação é o código guardado na linha do Diário e a
     * data do registo da reconciliação (anuladas não contam).
     *
     * @return array<int, array{contrapartidas: list<string>, reconciliacao_codigo: ?string, reconciliacao_data: ?string}>
     */
    private function contrapartidasEReconciliacao(string $conta, string $inicio, string $fim): array
    {
        $chave = LancamentoContabil::chaveSql('l');
        $chaveO = LancamentoContabil::chaveSql('o');
        $linhas = DB::select("SELECT l.id, NULLIF(l.reconciliacao_codigo, '') AS reconciliacao_codigo,
                (SELECT MAX(r.data) FROM reconciliacoes_bancarias r WHERE r.empresa_id = l.empresa_id AND r.reconciliacao_codigo = l.reconciliacao_codigo
                    AND (r.estado IS NULL OR r.estado <> 'ANULADA')) AS reconciliacao_data,
                (SELECT string_agg(DISTINCT o.codigo_conta, '|' ORDER BY o.codigo_conta) FROM lancamentos_contabeis o
                  WHERE o.empresa_id = l.empresa_id AND o.diario_id = l.diario_id AND {$chaveO} = {$chave} AND o.id <> l.id) AS contrapartidas
            FROM lancamentos_contabeis l
            WHERE l.empresa_id = ? AND l.codigo_conta = ? AND l.data_documento BETWEEN ? AND ?", [$this->contexto->obrigatorio(), $conta, $inicio, $fim]);
        $fuso = (string) config('app.timezone');
        $out = [];
        foreach ($linhas as $l) {
            $out[(int) $l->id] = [
                'contrapartidas' => $l->contrapartidas !== null && $l->contrapartidas !== '' ? explode('|', $l->contrapartidas) : [],
                'reconciliacao_codigo' => $l->reconciliacao_codigo,
                'reconciliacao_data' => $l->reconciliacao_data !== null ? Carbon::parse($l->reconciliacao_data)->setTimezone($fuso)->toDateString() : null,
            ];
        }

        return $out;
    }
}
