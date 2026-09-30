<?php

namespace App\Services\Orcamento;

use App\Exceptions\ErroNegocio;
use App\Models\CenarioOrcamental;
use App\Models\LinhaOrcamento;
use App\Models\LinhaPrevisaoOrcamental;
use App\Models\OrcamentoAnual;
use App\Models\PrevisaoOrcamental;
use App\Models\RubricaOrcamental;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Previsões deslizantes a 12 meses, cenários what-if e análise de desvios (orcamento_planeamento.js:180-420 e 576-623).
 * Paridade: previsão por dimensões com revisões (a nova revisão copia os meses em comum e semeia só os novos);
 * métodos ORCAMENTO (aprovado do mês), TENDENCIA (média dos 3 últimos meses reais), ANO_ANTERIOR (mês homólogo +
 * crescimento) e BRANCO; publicar fixa a revisão (as publicadas não se alteram nem eliminam); estimativa de fecho do
 * ano = real até ao mês de referência + previsão do resto. Cenários: variáveis (volume, preço, matérias, pessoal, outros,
 * câmbio), indutor e parte variável por rubrica (deduzidos do PGC), exposição cambial, ajuste por rubrica; padrões
 * Otimista/Realista/Pessimista; um cenário gera uma nova versão do orçamento aprovado. Desvios: mês a mês, classificação
 * SEM_DESVIO/TEMPORAL/PONTUAL/ESTRUTURAL/MISTO, contas face ao ano anterior, maiores movimentos e estimativa de fecho.
 * Correcção (ADR-046): as permissões de previsões e cenários são verificadas no servidor (no legado só no ecrã).
 */
final class ServicoPlaneamentoOrcamental
{
    public const METODOS = ['ORCAMENTO', 'TENDENCIA', 'ANO_ANTERIOR', 'BRANCO'];

    public const VARIAVEIS = ['vendas_volume_pct', 'vendas_preco_pct', 'materias_pct', 'pessoal_pct', 'outros_pct', 'cambio_pct'];

    public const PADROES = [
        'OTIMISTA' => ['Otimista', ['vendas_volume_pct' => 10, 'vendas_preco_pct' => 5, 'materias_pct' => 3, 'pessoal_pct' => 5, 'outros_pct' => 3, 'cambio_pct' => 0]],
        'REALISTA' => ['Realista', ['vendas_volume_pct' => 0, 'vendas_preco_pct' => 3, 'materias_pct' => 8, 'pessoal_pct' => 6, 'outros_pct' => 8, 'cambio_pct' => 10]],
        'PESSIMISTA' => ['Pessimista', ['vendas_volume_pct' => -15, 'vendas_preco_pct' => 0, 'materias_pct' => 15, 'pessoal_pct' => 8, 'outros_pct' => 12, 'cambio_pct' => 25]],
    ];

    public function __construct(
        private readonly ServicoExecucaoOrcamental $execucao,
        private readonly ServicoOrcamentos $orcamentos,
        private readonly ContextoEmpresa $contexto,
    ) {}

    // ───────────── Auxiliares de meses ─────────────

    public static function somarMeses(string $mes, int $n): string
    {
        return date('Y-m', strtotime("{$mes}-01 {$n} months"));
    }

    /** @return list<string> os 12 meses a seguir ao de referência */
    public static function mesesApos(string $mesRef): array
    {
        return array_map(fn ($i) => self::somarMeses($mesRef, $i), range(1, 12));
    }

    /** Real mensal por rubrica (chave AAAA-MM) para os anos indicados, com as dimensões da previsão. */
    private function realMensal(array $dims, array $anos): array
    {
        $saida = [];
        foreach (array_unique($anos) as $ano) {
            $o = new OrcamentoAnual($dims + ['ano' => $ano]);
            foreach ($this->execucao->realizado($o, $ano)['por_rubrica'] as $rid => $v) {
                foreach ($v as $m => $x) {
                    $saida[sprintf('%04d-%02d', $ano, $m + 1)][$rid] = $x;
                }
            }
        }

        return $saida;
    }

    private function aprovado(array $dims, int $ano): ?OrcamentoAnual
    {
        $q = OrcamentoAnual::query()->where('tipo', $dims['tipo'])->where('ano', $ano)->where('estado', 'APROVADO');
        foreach (['unidade_negocio_id', 'centro_custo_id', 'projeto_id'] as $k) {
            empty($dims[$k]) ? $q->whereNull($k) : $q->where($k, $dims[$k]);
        }

        return $q->orderByDesc('versao')->first();
    }

    /** Valores-semente: rubrica → [AAAA-MM → valor]. */
    private function semear(array $dims, array $meses, string $metodo, float $crescimento, $rubs, string $mesRef, array $anterior = []): array
    {
        $valores = [];
        if ($metodo === 'BRANCO') {
            return $valores;
        }
        $g = 1 + $crescimento / 100;
        $anos = array_map(fn ($k) => (int) substr($k, 0, 4), $meses);
        $real = $this->realMensal($dims, $metodo === 'TENDENCIA' ? [(int) substr($mesRef, 0, 4), (int) substr(self::somarMeses($mesRef, -2), 0, 4)] : array_map(fn ($a) => $a - 1, $anos));
        $orcs = [];
        if ($metodo === 'ORCAMENTO') {
            foreach (array_unique($anos) as $a) {
                $o = $this->aprovado($dims, $a);
                $orcs[$a] = $o ? LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->pluck('valores', 'rubrica_orcamental_id')->all() : null;
            }
        }
        foreach ($rubs as $r) {
            foreach ($meses as $k) {
                $a = (int) substr($k, 0, 4);
                $m = (int) substr($k, 5, 2) - 1;
                $homologo = function () use ($k, $mesRef, $anterior, $r, $real, $g) {
                    $h = self::somarMeses($k, -12);
                    if ($h > $mesRef && isset($anterior[$r->id][$h])) {
                        return (float) $anterior[$r->id][$h] * $g;
                    }

                    return (float) ($real[$h][$r->id] ?? 0) * $g;
                };
                $v = match ($metodo) {
                    'ORCAMENTO' => $orcs[$a] !== null ? (float) (((array) ($orcs[$a][$r->id] ?? []))[$m] ?? 0) : $homologo(),
                    'TENDENCIA' => array_sum(array_map(fn ($i) => (float) ($real[self::somarMeses($mesRef, $i)][$r->id] ?? 0), [0, -1, -2])) / 3 * $g,
                    default => $homologo(),
                };
                $valores[$r->id][$k] = round($v, 2);
            }
        }

        return $valores;
    }

    // ───────────── Previsões ─────────────

    /** @param  array<string, mixed>  $d */
    public function criarPrevisao(array $d): PrevisaoOrcamental
    {
        $dims = ['tipo' => $d['tipo'], 'unidade_negocio_id' => $d['unidade_negocio_id'] ?? null, 'centro_custo_id' => $d['centro_custo_id'] ?? null, 'projeto_id' => $d['projeto_id'] ?? null];
        $rubs = RubricaOrcamental::query()->where('tipo', $dims['tipo'])->where('ativo', true)->get();
        if ($rubs->isEmpty()) {
            throw new ErroNegocio('Crie primeiro as rubricas deste tipo.', 'SEM_RUBRICAS', 422);
        }

        return DB::transaction(function () use ($d, $dims, $rubs) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['previsao:'.implode('|', $dims)]);
            if ($this->serie($dims)->exists()) {
                throw new ErroNegocio('Já existe a série de previsões para estas dimensões: crie uma nova revisão.', 'PREVISAO_EXISTENTE', 422);
            }
            $metodo = in_array($d['metodo'] ?? 'ORCAMENTO', self::METODOS, true) ? ($d['metodo'] ?? 'ORCAMENTO') : 'ORCAMENTO';
            $valores = $this->semear($dims, self::mesesApos($d['mes_referencia']), $metodo, (float) ($d['crescimento_pct'] ?? 0), $rubs, $d['mes_referencia']);
            $p = PrevisaoOrcamental::create($dims + ['nome' => trim((string) ($d['nome'] ?? '')) ?: 'Previsão '.strtolower($dims['tipo']), 'mes_referencia' => $d['mes_referencia'],
                'revisao' => 1, 'estado' => 'RASCUNHO', 'metodo' => $metodo, 'crescimento_pct' => (float) ($d['crescimento_pct'] ?? 0), 'criado_por' => Auth::user()?->nome_utilizador]);
            foreach ($rubs as $r) {
                LinhaPrevisaoOrcamental::create(['previsao_orcamental_id' => $p->id, 'rubrica_orcamental_id' => $r->id,
                    'valores' => $valores[$r->id] ?? array_fill_keys(self::mesesApos($d['mes_referencia']), 0)]);
            }

            return $p->refresh();
        });
    }

    private function serie(array $dims)
    {
        $q = PrevisaoOrcamental::query()->where('tipo', $dims['tipo']);
        foreach (['unidade_negocio_id', 'centro_custo_id', 'projeto_id'] as $k) {
            empty($dims[$k]) ? $q->whereNull($k) : $q->where($k, $dims[$k]);
        }

        return $q;
    }

    /** Nova revisão: os meses em comum copiam a revisão anterior; os novos são semeados. */
    public function novaRevisao(PrevisaoOrcamental $p, ?string $mesRefNovo = null): PrevisaoOrcamental
    {
        return DB::transaction(function () use ($p, $mesRefNovo) {
            $dims = $p->only(['tipo', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id']);
            $ultima = $this->serie($dims)->orderByDesc('revisao')->lockForUpdate()->first();
            if ($ultima->estado === 'RASCUNHO') {
                throw new ErroNegocio("A revisão {$ultima->revisao} ainda está em rascunho: publique-a ou elimine-a primeiro.", 'REVISAO_EM_CURSO', 422);
            }
            $mesRefNovo ??= self::somarMeses($ultima->mes_referencia, 1);
            if ($mesRefNovo <= $ultima->mes_referencia) {
                throw new ErroNegocio("O novo mês de referência tem de ser posterior a {$ultima->mes_referencia}.", 'MES_INVALIDO', 422);
            }
            $rubs = RubricaOrcamental::query()->where('tipo', $ultima->tipo)->where('ativo', true)->get();
            $ant = LinhaPrevisaoOrcamental::query()->where('previsao_orcamental_id', $ultima->id)->pluck('valores', 'rubrica_orcamental_id')->map(fn ($v) => (array) $v)->all();
            $meses = self::mesesApos($mesRefNovo);
            $novos = array_values(array_filter($meses, fn ($k) => ! $rubs->contains(fn ($r) => isset($ant[$r->id][$k]))));
            $semente = $novos ? $this->semear($dims, $novos, $ultima->metodo, (float) $ultima->crescimento_pct, $rubs, $mesRefNovo, $ant) : [];
            $n = PrevisaoOrcamental::create($dims + $ultima->only(['nome', 'metodo', 'crescimento_pct']) + ['mes_referencia' => $mesRefNovo, 'revisao' => $ultima->revisao + 1,
                'revisao_origem_id' => $ultima->id, 'estado' => 'RASCUNHO', 'criado_por' => Auth::user()?->nome_utilizador]);
            foreach ($rubs as $r) {
                $v = [];
                foreach ($meses as $k) {
                    $v[$k] = $ant[$r->id][$k] ?? ($semente[$r->id][$k] ?? 0);
                }
                LinhaPrevisaoOrcamental::create(['previsao_orcamental_id' => $n->id, 'rubrica_orcamental_id' => $r->id, 'valores' => $v]);
            }

            return $n->refresh();
        });
    }

    /** @param  list<array{rubrica_orcamental_id: int, valores: array<string, mixed>}>  $linhas */
    public function gravarPrevisao(PrevisaoOrcamental $p, array $linhas, ?string $notas): PrevisaoOrcamental
    {
        if ($p->estado !== 'RASCUNHO') {
            throw new ErroNegocio('Só revisões em rascunho se alteram.', 'PREVISAO_PUBLICADA', 422);
        }
        $meses = self::mesesApos($p->mes_referencia);

        return DB::transaction(function () use ($p, $linhas, $notas, $meses) {
            LinhaPrevisaoOrcamental::query()->where('previsao_orcamental_id', $p->id)->delete();
            foreach ($linhas as $l) {
                $v = [];
                foreach ($meses as $k) {
                    $v[$k] = round((float) ($l['valores'][$k] ?? 0), 2);
                }
                LinhaPrevisaoOrcamental::create(['previsao_orcamental_id' => $p->id, 'rubrica_orcamental_id' => (int) $l['rubrica_orcamental_id'], 'valores' => $v]);
            }
            $p->update(['notas' => $notas ?? $p->notas, 'atualizado_por' => Auth::user()?->nome_utilizador]);

            return $p->refresh();
        });
    }

    public function publicarPrevisao(PrevisaoOrcamental $p): PrevisaoOrcamental
    {
        if ($p->estado !== 'RASCUNHO') {
            throw new ErroNegocio('A revisão já está publicada.', 'PREVISAO_PUBLICADA', 422);
        }
        $p->update(['estado' => 'PUBLICADA', 'publicado_por' => Auth::user()?->nome_utilizador, 'publicado_em' => now()]);

        return $p->refresh();
    }

    public function eliminarPrevisao(PrevisaoOrcamental $p): void
    {
        if ($p->estado !== 'RASCUNHO') {
            throw new ErroNegocio('Revisões publicadas fazem parte do histórico e não se eliminam.', 'PREVISAO_PUBLICADA', 422);
        }
        DB::transaction(function () use ($p) {
            LinhaPrevisaoOrcamental::query()->where('previsao_orcamental_id', $p->id)->delete();
            $p->delete();
        });
    }

    /** Resumo: real dos últimos 3 meses, previsão, total a 12 meses, estimativa de fecho do ano e orçado. */
    public function resumoPrevisao(PrevisaoOrcamental $p): array
    {
        $dims = $p->only(['tipo', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id']);
        $meses = self::mesesApos($p->mes_referencia);
        $reais = array_map(fn ($i) => self::somarMeses($p->mes_referencia, $i), [-2, -1, 0]);
        $anoFecho = (int) substr($p->mes_referencia, 0, 4) + (str_ends_with($p->mes_referencia, '-12') ? 1 : 0);
        $real = $this->realMensal($dims, [...array_map(fn ($k) => (int) substr($k, 0, 4), $reais), $anoFecho]);
        $orc = $this->aprovado($dims, $anoFecho);
        $orcLs = $orc ? LinhaOrcamento::query()->where('orcamento_anual_id', $orc->id)->pluck('valores', 'rubrica_orcamental_id')->all() : [];
        $ls = LinhaPrevisaoOrcamental::query()->where('previsao_orcamental_id', $p->id)->pluck('valores', 'rubrica_orcamental_id')->map(fn ($v) => (array) $v)->all();
        $linhas = [];
        foreach (RubricaOrcamental::query()->where('tipo', $p->tipo)->orderBy('ordem')->orderBy('codigo')->get() as $r) {
            if (! $r->ativo && ! isset($ls[$r->id])) {
                continue;
            }
            $prev = $ls[$r->id] ?? [];
            $realAno = array_sum(array_map(fn ($m) => (float) ($real[sprintf('%04d-%02d', $anoFecho, $m)][$r->id] ?? 0),
                array_filter(range(1, 12), fn ($m) => sprintf('%04d-%02d', $anoFecho, $m) <= $p->mes_referencia)));
            $prevAno = array_sum(array_map(fn ($k) => (float) ($prev[$k] ?? 0), array_filter($meses, fn ($k) => (int) substr($k, 0, 4) === $anoFecho)));
            $linhas[] = ['rubrica_id' => $r->id, 'codigo' => $r->codigo, 'nome' => $r->nome, 'real_recente' => array_map(fn ($k) => (float) ($real[$k][$r->id] ?? 0), $reais),
                'previsao' => $prev, 'total_12' => round(array_sum(array_map('floatval', $prev)), 2), 'real_ano' => round($realAno, 2), 'previsto_ano' => round($prevAno, 2),
                'fecho_estimado' => round($realAno + $prevAno, 2), 'orcado' => $orc ? round(array_sum(array_map('floatval', (array) ($orcLs[$r->id] ?? []))), 2) : null];
        }

        return ['previsao' => $p, 'meses' => $meses, 'meses_reais' => $reais, 'ano_fecho' => $anoFecho, 'orcamento_id' => $orc?->id, 'linhas' => $linhas];
    }

    // ───────────── Cenários ─────────────

    public static function indutor(RubricaOrcamental $r): string
    {
        if ($r->indutor) {
            return $r->indutor;
        }
        $c = (string) (((array) $r->contas)[0]['codigo'] ?? '');
        if ($r->tipo === 'TESOURARIA') {
            return match (true) {
                str_starts_with($c, '31') || ($r->natureza === 'RECEBIMENTO' && str_starts_with($c, '6')) => 'VENDAS',
                str_starts_with($c, '32') || str_starts_with($c, '2') => 'MATERIAS',
                str_starts_with($c, '36') || str_starts_with($c, '72') => 'PESSOAL',
                (bool) preg_match('/^3[35]/', $c) => 'FINANCEIRO',
                str_starts_with($c, '34') || str_starts_with($c, '7') => 'OUTROS',
                default => 'NENHUM',
            };
        }

        return match (true) {
            (bool) preg_match('/^6[12]/', $c) => 'VENDAS', str_starts_with($c, '71') => 'MATERIAS', str_starts_with($c, '72') => 'PESSOAL',
            str_starts_with($c, '75') => 'OUTROS', (bool) preg_match('/^(66|76)/', $c) => 'FINANCEIRO', default => 'NENHUM',
        };
    }

    /** @return array<int, list<float>> rubrica → 12 meses do cenário */
    public static function aplicar($rubs, array $base, array $variaveis, array $ajustes): array
    {
        $v = fn (string $k) => (float) ($variaveis[$k] ?? 0) / 100;
        $saida = [];
        foreach ($rubs as $r) {
            if (! isset($base[$r->id])) {
                continue;
            }
            $ind = self::indutor($r);
            $variavel = ($r->variavel_pct !== null ? (float) $r->variavel_pct : (in_array($ind, ['VENDAS', 'MATERIAS'], true) ? 100 : 0)) / 100;
            $exp = (float) ($r->cambial_pct ?? 0) / 100;
            $f = 1 + $v('vendas_volume_pct') * $variavel;
            if ($ind === 'VENDAS' && in_array($r->natureza, ['PROVEITO', 'RECEBIMENTO'], true)) {
                $f *= 1 + $v('vendas_preco_pct');
            }
            $f *= match ($ind) {
                'MATERIAS' => 1 + $v('materias_pct'), 'PESSOAL' => 1 + $v('pessoal_pct'), 'OUTROS' => 1 + $v('outros_pct'), default => 1
            };
            $f *= (1 - $exp) + $exp * (1 + $v('cambio_pct'));
            $f *= 1 + (float) ($ajustes[$r->id] ?? 0) / 100;
            $saida[$r->id] = array_map(fn ($x) => round((float) $x * $f, 2), array_values((array) $base[$r->id]));
        }

        return $saida;
    }

    /** @param  array<string, mixed>  $d */
    public function gravarCenario(array $d, ?CenarioOrcamental $c = null): CenarioOrcamental
    {
        $o = OrcamentoAnual::query()->findOrFail($d['orcamento_anual_id'] ?? $c?->orcamento_anual_id);
        $variaveis = [];
        foreach (self::VARIAVEIS as $k) {
            $variaveis[$k] = max(-100, min(500, (float) ($d['variaveis'][$k] ?? 0)));
        }
        $ajustes = array_map(fn ($x) => max(-100, min(500, (float) $x)), array_filter((array) ($d['ajustes'] ?? []), fn ($x) => (float) $x != 0));
        $dados = ['orcamento_anual_id' => $o->id, 'nome' => trim((string) $d['nome']), 'tipo' => isset(self::PADROES[$d['tipo'] ?? '']) ? $d['tipo'] : 'PERSONALIZADO',
            'variaveis' => $variaveis, 'ajustes' => $ajustes, 'notas' => $d['notas'] ?? null, 'atualizado_por' => Auth::user()?->nome_utilizador];
        $c ? $c->update($dados) : $c = CenarioOrcamental::create($dados + ['criado_por' => Auth::user()?->nome_utilizador]);

        return $c->refresh();
    }

    public function criarPadrao(OrcamentoAnual $o): array
    {
        $criados = [];
        foreach (self::PADROES as $tipo => [$nome, $vars]) {
            if (! CenarioOrcamental::query()->where('orcamento_anual_id', $o->id)->where('tipo', $tipo)->exists()) {
                $criados[] = $this->gravarCenario(['orcamento_anual_id' => $o->id, 'nome' => $nome, 'tipo' => $tipo, 'variaveis' => $vars])->nome;
            }
        }

        return $criados;
    }

    public function calcularCenario(CenarioOrcamental $c): array
    {
        $o = OrcamentoAnual::query()->findOrFail($c->orcamento_anual_id);
        $rubs = RubricaOrcamental::query()->where('tipo', $o->tipo)->get();
        $base = LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->pluck('valores', 'rubrica_orcamental_id')->all();
        $valores = self::aplicar($rubs, $base, (array) $c->variaveis, (array) $c->ajustes);
        $linhas = [];
        foreach ($rubs as $r) {
            if (isset($valores[$r->id])) {
                $b = round(array_sum(array_map('floatval', (array) $base[$r->id])), 2);
                $cen = round(array_sum($valores[$r->id]), 2);
                $linhas[] = ['rubrica_id' => $r->id, 'codigo' => $r->codigo, 'nome' => $r->nome, 'natureza' => $r->natureza, 'indutor' => self::indutor($r),
                    'base' => $b, 'cenario' => $cen, 'variacao' => round($cen - $b, 2), 'valores' => $valores[$r->id]];
            }
        }
        $saldo = fn (string $campo) => round(array_sum(array_map(fn ($l) => (in_array($l['natureza'], ['PROVEITO', 'RECEBIMENTO'], true) ? 1 : -1) * $l[$campo], $linhas)), 2);

        return ['cenario' => $c, 'orcamento_id' => $o->id, 'linhas' => $linhas, 'resultado_base' => $saldo('base'), 'resultado_cenario' => $saldo('cenario')];
    }

    /** O cenário vira uma nova versão (rascunho) do orçamento aprovado. */
    public function orcamentoDeCenario(CenarioOrcamental $c): OrcamentoAnual
    {
        $o = OrcamentoAnual::query()->findOrFail($c->orcamento_anual_id);
        if ($o->estado !== 'APROVADO') {
            throw new ErroNegocio('O cenário só gera uma nova versão a partir de um orçamento aprovado.', 'ORCAMENTO_NAO_APROVADO', 422);
        }

        return DB::transaction(function () use ($c, $o) {
            $n = $this->orcamentos->novaVersao($o);
            $vals = $this->calcularCenario($c)['linhas'];
            $this->orcamentos->gravarValores($n, array_map(fn ($l) => ['rubrica_orcamental_id' => $l['rubrica_id'], 'valores' => $l['valores'], 'notas' => "Cenário «{$c->nome}»"], $vals));
            $n->update(['descricao' => trim(($o->descricao ? $o->descricao."\n" : '')."Gerado do cenário «{$c->nome}»")]);

            return $n->refresh();
        });
    }

    // ───────────── Análise de desvios ─────────────

    public function analisarDesvio(OrcamentoAnual $o, int $rubrica, int $m0, int $m1): array
    {
        $r = RubricaOrcamental::query()->findOrFail($rubrica);
        $real = $this->execucao->realizado($o)['por_rubrica'][$r->id] ?? array_fill(0, 12, 0.0);
        $orc = array_map('floatval', (array) (LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->where('rubrica_orcamental_id', $r->id)->value('valores') ?? array_fill(0, 12, 0)));
        $entrada = in_array($r->natureza, ['PROVEITO', 'RECEBIMENTO'], true);
        $acum = 0.0;
        $mensal = [];
        for ($m = $m0 - 1; $m <= $m1 - 1; $m++) {
            $d = round((float) $real[$m] - $orc[$m], 2);
            $acum += $d;
            $mensal[] = ['mes' => $m + 1, 'orcado' => $orc[$m], 'real' => (float) $real[$m], 'desvio' => $d, 'acumulado' => round($acum, 2), 'desfavoravel' => $entrada ? $d < -0.005 : $d > 0.005];
        }
        $total = array_sum(array_column($mensal, 'desvio'));
        $abs = array_sum(array_map(fn ($x) => abs($x['desvio']), $mensal));
        $top2 = array_sum(array_slice(array_map(fn ($x) => abs($x['desvio']), collect($mensal)->sortByDesc(fn ($x) => abs($x['desvio']))->values()->all()), 0, 2));
        $mesmoSinal = count(array_filter($mensal, fn ($x) => abs($x['desvio']) > 0.005 && ($x['desvio'] <=> 0) === ($total <=> 0)));
        $n = count($mensal);
        $tipo = match (true) {
            abs($total) < 0.005 => 'SEM_DESVIO',
            $n >= 3 && $abs > 0 && abs($total) < $abs * 0.35 => 'TEMPORAL',
            $n >= 3 && $abs > 0 && $top2 / $abs >= 0.7 => 'PONTUAL',
            $n >= 3 && $mesmoSinal / $n >= 0.7 => 'ESTRUTURAL',
            default => 'MISTO',
        };
        $contas = [];
        $maiores = [];
        if ($o->tipo === 'EXPLORACAO') {
            $linhas = fn (int $ano) => DB::table('lancamentos_contabeis as l')->leftJoin('diarios_contabeis as d', 'd.id', '=', 'l.diario_id')
                ->where('l.empresa_id', $this->contexto->obrigatorio())->whereBetween('l.data_documento', [sprintf('%04d-%02d-01', $ano, $m0), date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $ano, $m1)))])
                ->where(fn ($q) => $q->whereNull('d.codigo')->orWhere('d.codigo', 'not like', 'AP-%'))
                ->where(fn ($q) => collect((array) $r->contas)->each(fn ($e) => ! empty($e['prefixo']) ? $q->orWhere('l.codigo_conta', 'like', $e['codigo'].'%') : $q->orWhere('l.codigo_conta', $e['codigo'])))
                ->when($o->unidade_negocio_id, fn ($q, $v) => $q->where('l.unidade_negocio_id', $v))->when($o->centro_custo_id, fn ($q, $v) => $q->where('l.centro_custo_id', $v))
                ->when($o->projeto_id, fn ($q, $v) => $q->where('l.projeto_id', $v));
            $sinal = $entrada ? "CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE -l.valor END" : "CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END";
            $atual = $linhas($o->ano)->selectRaw("l.codigo_conta, SUM({$sinal}) AS v")->groupBy('l.codigo_conta')->pluck('v', 'codigo_conta');
            $anterior = $linhas($o->ano - 1)->selectRaw("l.codigo_conta, SUM({$sinal}) AS v")->groupBy('l.codigo_conta')->pluck('v', 'codigo_conta');
            foreach ($atual->keys()->merge($anterior->keys())->unique() as $conta) {
                $contas[] = ['conta' => $conta, 'real' => round((float) ($atual[$conta] ?? 0), 2), 'anterior' => round((float) ($anterior[$conta] ?? 0), 2),
                    'variacao' => round((float) ($atual[$conta] ?? 0) - (float) ($anterior[$conta] ?? 0), 2)];
            }
            usort($contas, fn ($a, $b) => abs($b['variacao']) <=> abs($a['variacao']));
            $maiores = $linhas($o->ano)->orderByRaw('l.valor DESC')->limit(10)->get(['l.data_documento', 'l.numero_documento', 'l.codigo_conta', 'l.descricao', 'l.tipo_dc', 'l.valor', 'l.terceiro_id'])->all();
        }
        $prev = $this->serie($o->only(['tipo', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id']))->where('estado', 'PUBLICADA')
            ->where('mes_referencia', 'like', "{$o->ano}-%")->orderByDesc('revisao')->first();
        $fecho = null;
        if ($prev) {
            $lp = (array) (LinhaPrevisaoOrcamental::query()->where('previsao_orcamental_id', $prev->id)->where('rubrica_orcamental_id', $r->id)->value('valores') ?? []);
            $mesRef = (int) substr($prev->mes_referencia, 5, 2);
            $fecho = ['revisao' => $prev->revisao, 'mes_referencia' => $prev->mes_referencia, 'orcado_ano' => round(array_sum($orc), 2),
                'valor' => round(array_sum(array_slice($real, 0, $mesRef)) + array_sum(array_map('floatval', array_filter($lp, fn ($k) => str_starts_with($k, "{$o->ano}-"), ARRAY_FILTER_USE_KEY))), 2)];
        }

        return ['rubrica' => $r->only(['id', 'codigo', 'nome', 'natureza']), 'mensal' => $mensal, 'desvio_total' => round($total, 2), 'classificacao' => $tipo,
            'desfavoraveis' => count(array_filter($mensal, fn ($x) => $x['desfavoravel'])), 'contas' => $contas, 'maiores_movimentos' => $maiores, 'fecho_estimado' => $fecho];
    }
}
