<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\MeioPagamento;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoRelatoriosContabeis;
use App\Support\Tenancy\ContextoEmpresa;
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
     * Extracto de uma conta 43/45 (razão da contabilidade): saldo inicial, movimentos com saldo corrido e totais, com o
     * terceiro {id, nome, nif} de cada movimento (uma consulta, sem N+1).
     *
     * @return array<string, mixed>
     */
    public function extratoConta(string $conta, string $inicio, string $fim): array
    {
        $conta = trim($conta);
        if (! in_array(substr($conta, 0, 2), self::PREFIXOS, true)) {
            throw new ErroNegocio('O extracto da Tesouraria só abrange contas de disponibilidades (43 ou 45).', 'CONTA_NAO_DISPONIBILIDADES', 422);
        }
        $razao = $this->relatorios->razao(['codigo_conta' => $conta, 'data_inicio' => $inicio, 'data_fim' => $fim]);
        $ids = collect($razao['movimentos'])->pluck('terceiro_id')->filter()->unique()->values();
        $terceiros = $ids->isEmpty() ? collect() : Terceiro::withTrashed()->whereIn('id', $ids)->get(['id', 'nome', 'nif'])->keyBy('id');
        $movimentos = array_map(function ($m) use ($terceiros) {
            $t = $m->terceiro_id ? ($terceiros[$m->terceiro_id] ?? null) : null;

            return (array) $m + ['terceiro' => $t ? ['id' => $t->id, 'nome' => $t->nome, 'nif' => $t->nif] : null];
        }, $razao['movimentos']);
        $descricao = DB::table('plano_contas')->where('empresa_id', $this->contexto->obrigatorio())->where('codigo', $conta)->whereNull('eliminado_em')->value('descricao');

        return array_merge(['codigo_conta' => $conta, 'descricao' => $descricao, 'data_inicio' => $inicio, 'data_fim' => $fim], $razao, ['movimentos' => $movimentos]);
    }
}
