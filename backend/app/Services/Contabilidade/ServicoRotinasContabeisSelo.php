<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\LancamentoContabil;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Rotina «Imposto de Selo» (js/ui_rotinas.js:668-868): 1% sobre os débitos do mês nas contas 43, 45 e 48 dos diários VD e CB
 * e nas contas 45 do diário CX; lançamento no diário AC «Apuramentos Contabilísticos», datado do último dia do mês,
 * documento/referência "IS-<mês><ano>" (sem zero à esquerda, como o legado), D 75311 / C 3471.
 * Correcções face ao legado:
 *   - o imposto é arredondado ao cêntimo (half-up, ADR-022); o legado gravava o float de base × 0,01;
 *   - não se lança duas vezes o mesmo mês (o legado não verificava e duplicava o imposto);
 *   - as linhas estornadas e os estornos não entram na base: no legado um documento descontabilizado deixava de ter linhas.
 */
final class ServicoRotinasContabeisSelo
{
    public const DIARIO = 'AC';

    public const NOME_DIARIO = 'Apuramentos Contabilísticos';

    public const CONTA_GASTO = '75311';

    public const CONTA_ESTADO = '3471';

    public const TIPO_ORIGEM = 'ROTINAS';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
    ) {}

    /** Base e imposto do mês (calcularResumoSelo, ui_rotinas.js:711-789). */
    public function resumo(int $mes, int $ano): array
    {
        $empresa = $this->contexto->obrigatorio();
        $inicio = sprintf('%04d-%02d-01', $ano, $mes);
        $fim = date('Y-m-t', strtotime($inicio));
        $diarios = DB::table('diarios_contabeis')->where('empresa_id', $empresa)->whereIn('codigo', ['VD', 'CB', 'CX'])->whereNull('eliminado_em')->get(['id', 'codigo']);
        $vdcb = $diarios->whereIn('codigo', ['VD', 'CB'])->pluck('id')->all();
        $cx = $diarios->where('codigo', 'CX')->pluck('id')->all();
        $base = fn (array $ids, string $regex) => $ids ? DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->whereIn('diario_id', $ids)
            ->whereBetween('data_documento', [$inicio, $fim])->where('tipo_dc', 'D')->where('codigo_conta', '~', $regex)
            ->whereNull('estorno_de_id')->whereNull('estornado_por_id')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(valor), 0) AS v')->first() : (object) ['n' => 0, 'v' => '0'];
        $a = $base($vdcb, '^(43|45|48)');
        $b = $base($cx, '^45');
        $total = bcadd((string) $a->v, (string) $b->v, 2);
        // base ≥ 0: somar meio cêntimo e truncar às 2 casas = arredondamento half-up
        $imposto = bcadd(bcdiv($total, '100', 6), '0.005', 2);
        $existente = $this->existente($mes, $ano);

        return ['mes' => $mes, 'ano' => $ano, 'documento' => $this->documento($mes, $ano), 'data_documento' => $fim,
            'base_vd_cb' => bcadd((string) $a->v, '0', 2), 'movimentos_vd_cb' => (int) $a->n, 'base_cx' => bcadd((string) $b->v, '0', 2), 'movimentos_cx' => (int) $b->n,
            'base_total' => $total, 'taxa' => '1.00', 'imposto' => $imposto,
            'lancamento_existente' => $existente ? ($existente->numero_lan ?? $existente->numero_documento) : null];
    }

    /** Gera o lançamento do imposto do mês (gerarLancamentoSelo, ui_rotinas.js:791-829). */
    public function lancar(int $mes, int $ano): array
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($empresa, $mes, $ano) {
            DB::table('empresas')->where('id', $empresa)->lockForUpdate()->first(['id']);
            if ($this->existente($mes, $ano)) {
                throw new ErroNegocio("O Imposto de Selo de {$mes}/{$ano} já foi lançado ({$this->documento($mes, $ano)}): estorne-o primeiro para o lançar de novo.", 'SELO_JA_LANCADO', 422);
            }
            $r = $this->resumo($mes, $ano);
            if (bccomp($r['imposto'], '0', 2) <= 0) {
                throw new ErroNegocio("Não há base tributável de Imposto de Selo em {$mes}/{$ano}.", 'SELO_SEM_BASE', 422);
            }
            $diario = $this->localizador->diario(self::DIARIO, self::NOME_DIARIO);
            $linhas = $this->lancamentos->criar([
                'diario_id' => $diario->id, 'data_documento' => $r['data_documento'], 'numero_documento' => $r['documento'], 'referencia' => $r['documento'],
                'descricao' => "Apuramento Mensal Imposto de Selo (1%) - {$mes}/{$ano}", 'tipo_origem' => self::TIPO_ORIGEM,
                'linhas' => [
                    ['codigo_conta' => self::CONTA_GASTO, 'tipo_dc' => 'D', 'valor' => $r['imposto'], 'descricao' => 'DÉBITO Imposto de Selo (Gastos)'],
                    ['codigo_conta' => self::CONTA_ESTADO, 'tipo_dc' => 'C', 'valor' => $r['imposto'], 'descricao' => 'CRÉDITO Imposto de Selo (A Pagar)'],
                ],
            ]);

            return ['numero_lan' => $linhas->first()->numero_lan, 'lancamento_existente' => $linhas->first()->numero_lan] + $r;
        });
    }

    /** Lançamentos de Imposto de Selo efectuados (carregarHistoricoSelo, ui_rotinas.js:831-868: débitos da conta 75311). */
    public function historico(): array
    {
        return LancamentoContabil::query()->where('codigo_conta', self::CONTA_GASTO)->where('tipo_dc', 'D')->whereNull('estorno_de_id')
            ->orderByDesc('data_documento')->orderByDesc('id')->get()
            ->map(fn ($l) => ['id' => $l->id, 'data_documento' => $l->data_documento?->toDateString(), 'numero_lan' => $l->numero_lan,
                'numero_documento' => $l->numero_documento, 'descricao' => $l->descricao, 'valor' => (string) $l->valor,
                'estado' => $l->estornado_por_id ? 'ESTORNADO' : 'CONTABILIZADO'])->all();
    }

    private function existente(int $mes, int $ano): ?LancamentoContabil
    {
        $diario = DB::table('diarios_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->where('codigo', self::DIARIO)->whereNull('eliminado_em')->value('id');

        return $diario ? LancamentoContabil::query()->where('diario_id', $diario)->where('numero_documento', $this->documento($mes, $ano))
            ->where('codigo_conta', self::CONTA_GASTO)->whereNull('estorno_de_id')->whereNull('estornado_por_id')->orderBy('id')->first() : null;
    }

    private function documento(int $mes, int $ano): string
    {
        return "IS-{$mes}{$ano}";
    }
}
