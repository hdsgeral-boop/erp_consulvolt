<?php

namespace App\Services\Contabilidade;

use App\Models\LancamentoContabil;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Mapas contabilísticos da empresa activa, calculados no PostgreSQL (agregações sobre os índices
 * (empresa_id, codigo_conta, data_documento)). Os estornos entram nos saldos (anulam o original);
 * `excluir_estornos` retira os pares original/estorno para leitura do movimento "efectivo".
 */
final class ServicoRelatoriosContabeis
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * Balancete: saldo inicial (antes de data_inicio), débitos e créditos do período, saldo final.
     * `nivel` agrega por prefixo do código (ex.: 1 = classe, 2 = grau 2); null = conta de movimento.
     *
     * @param  array{data_inicio: string, data_fim: string, prefixo?: ?string, nivel?: ?int, excluir_estornos?: bool, so_com_saldo?: bool}  $f
     * @return array{linhas: list<array<string, mixed>>, totais: array<string, string>}
     */
    public function balancete(array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        $nivel = isset($f['nivel']) ? (int) $f['nivel'] : null;
        $conta = $nivel ? 'left(l.codigo_conta, ?)' : 'l.codigo_conta';
        $params = $nivel ? [$nivel] : [];

        $sql = "SELECT {$conta} AS codigo,
                    SUM(CASE WHEN l.data_documento < ? THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) AS saldo_inicial,
                    SUM(CASE WHEN l.data_documento >= ? AND l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS debito,
                    SUM(CASE WHEN l.data_documento >= ? AND l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS credito
                FROM lancamentos_contabeis l
                WHERE l.empresa_id = ? AND l.data_documento <= ?"
            .(! empty($f['prefixo']) ? ' AND l.codigo_conta LIKE ?' : '')
            .(! empty($f['excluir_estornos']) ? ' AND l.estorno_de_id IS NULL AND l.estornado_por_id IS NULL' : '')
            .' GROUP BY 1 ORDER BY 1';
        $params = array_merge($params, [$f['data_inicio'], $f['data_inicio'], $f['data_inicio'], $empresa, $f['data_fim']]);
        if (! empty($f['prefixo'])) {
            $params[] = str_replace(['%', '_'], ['\%', '\_'], $f['prefixo']).'%';
        }

        $descricoes = DB::table('plano_contas')->where('empresa_id', $empresa)->whereNull('eliminado_em')->pluck('descricao', 'codigo');
        $totais = ['saldo_inicial' => '0.00', 'debito' => '0.00', 'credito' => '0.00', 'saldo_final' => '0.00'];
        $linhas = [];
        foreach (DB::select($sql, $params) as $r) {
            $final = bcadd(bcadd((string) $r->saldo_inicial, (string) $r->debito, 2), bcmul((string) $r->credito, '-1', 2), 2);
            if (! empty($f['so_com_saldo']) && bccomp($final, '0', 2) === 0 && bccomp((string) $r->debito, '0', 2) === 0 && bccomp((string) $r->credito, '0', 2) === 0) {
                continue;
            }
            $linha = ['codigo_conta' => $r->codigo, 'descricao' => $descricoes[$r->codigo] ?? null,
                'saldo_inicial' => $this->fmt($r->saldo_inicial), 'debito' => $this->fmt($r->debito), 'credito' => $this->fmt($r->credito),
                'saldo_final' => $this->fmt($final)];
            foreach (['saldo_inicial', 'debito', 'credito', 'saldo_final'] as $k) {
                $totais[$k] = bcadd($totais[$k], $linha[$k], 2);
            }
            $linhas[] = $linha;
        }

        return ['linhas' => $linhas, 'totais' => $totais];
    }

    /**
     * Razão / extracto de uma conta: saldo inicial + movimentos com saldo acumulado.
     *
     * @param  array{codigo_conta: string, data_inicio: string, data_fim: string, terceiro_id?: ?int}  $f
     * @return array<string, mixed>
     */
    public function razao(array $f): array
    {
        $empresa = $this->contexto->obrigatorio();
        $filtroTerceiro = isset($f['terceiro_id']) ? ' AND terceiro_id = ?' : '';
        $base = [$empresa, $f['codigo_conta']];
        $terceiro = isset($f['terceiro_id']) ? [(int) $f['terceiro_id']] : [];

        $inicial = (string) (DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS s
            FROM lancamentos_contabeis WHERE empresa_id = ? AND codigo_conta = ? AND data_documento < ?{$filtroTerceiro}",
            array_merge($base, [$f['data_inicio']], $terceiro))->s);

        $movimentos = DB::select("SELECT l.id, l.data_documento, l.numero_lan, l.numero_documento, l.descricao, l.tipo_dc, l.valor, l.terceiro_id,
                d.codigo AS diario, l.estorno_de_id, l.estornado_por_id,
                ?::numeric + SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) OVER (ORDER BY l.data_documento, l.id) AS saldo
            FROM lancamentos_contabeis l JOIN diarios_contabeis d ON d.id = l.diario_id
            WHERE l.empresa_id = ? AND l.codigo_conta = ? AND l.data_documento BETWEEN ? AND ?{$filtroTerceiro}
            ORDER BY l.data_documento, l.id", array_merge([$inicial], $base, [$f['data_inicio'], $f['data_fim']], $terceiro));

        $debito = $credito = '0.00';
        foreach ($movimentos as $m) {
            $m->tipo_dc === 'D' ? $debito = bcadd($debito, (string) $m->valor, 2) : $credito = bcadd($credito, (string) $m->valor, 2);
            $m->valor = $this->fmt($m->valor);
            $m->saldo = $this->fmt($m->saldo);
        }

        return ['codigo_conta' => $f['codigo_conta'], 'saldo_inicial' => $this->fmt($inicial), 'debito' => $debito, 'credito' => $credito,
            'saldo_final' => bcsub(bcadd($this->fmt($inicial), $debito, 2), $credito, 2), 'movimentos' => $movimentos];
    }

    /**
     * Lançamentos desequilibrados (Σ D ≠ Σ C) — decisão 2026-09-29: os do legado foram importados como estão
     * e são analisados aqui. Agrupa por diário + chave do lançamento (LancamentoContabil::chaveSql).
     *
     * @return array{resumo: array<string, mixed>, lancamentos: list<object>}
     */
    public function desequilibrios(?string $dataInicio = null, ?string $dataFim = null): array
    {
        $empresa = $this->contexto->obrigatorio();
        $periodo = ($dataInicio ? ' AND l.data_documento >= ?' : '').($dataFim ? ' AND l.data_documento <= ?' : '');
        $params = array_values(array_filter([$empresa, $dataInicio, $dataFim]));

        $chave = LancamentoContabil::chaveSql('l');
        $lancamentos = DB::select("SELECT d.codigo AS diario, l.diario_id, {$chave} AS lancamento,
                BOOL_AND(l.numero_lan IS NULL OR l.numero_lan = '') AS sem_numero_lan, MIN(l.data_documento) AS data_documento, COUNT(*) AS linhas,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END) AS debito,
                SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END) AS credito,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS diferenca,
                MIN(l.id) AS primeira_linha_id
            FROM lancamentos_contabeis l JOIN diarios_contabeis d ON d.id = l.diario_id
            WHERE l.empresa_id = ?{$periodo}
            GROUP BY d.codigo, l.diario_id, {$chave}
            HAVING SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) <> 0
            ORDER BY ABS(SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END)) DESC, 1, 3", $params);

        $total = DB::selectOne("SELECT COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE 0 END), 0) AS debito,
                COALESCE(SUM(CASE WHEN tipo_dc = 'C' THEN valor ELSE 0 END), 0) AS credito
            FROM lancamentos_contabeis l WHERE l.empresa_id = ?{$periodo}", $params);

        foreach ($lancamentos as $l) {
            foreach (['debito', 'credito', 'diferenca'] as $k) {
                $l->{$k} = $this->fmt($l->{$k});
            }
        }

        return [
            'resumo' => [
                'debito' => $this->fmt($total->debito), 'credito' => $this->fmt($total->credito),
                'diferenca' => bcsub($this->fmt($total->debito), $this->fmt($total->credito), 2),
                'lancamentos_desequilibrados' => count($lancamentos),
                'soma_das_diferencas' => array_reduce($lancamentos, fn ($a, $l) => bcadd($a, $l->diferenca, 2), '0.00'),
            ],
            'lancamentos' => $lancamentos,
        ];
    }

    private function fmt(mixed $v): string
    {
        return number_format(round((float) $v, 2), 2, '.', '');
    }
}
