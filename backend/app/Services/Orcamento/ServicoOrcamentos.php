<?php

namespace App\Services\Orcamento;

use App\Exceptions\ErroNegocio;
use App\Models\LinhaOrcamento;
use App\Models\OrcamentoAnual;
use App\Models\RubricaOrcamental;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Orçamentos anuais (orcamento_dados.js:150-290, orcamento_planeamento.js:78-178). Paridade: chave ano|tipo|UN|CC|projecto
 * (uma por chave em todas as versões); método HISTÓRICO (base = realizado ou orçamento aprovado do ano anterior ×
 * (1 + crescimento)(1 + inflação), crescimento distinto para proveitos e custos) ou BASE ZERO (cada valor justificado);
 * 12 meses por rubrica; RASCUNHO → SUBMETIDO → APROVADO (o aprovado anterior da mesma chave fica SUBSTITUIDO);
 * devolver com motivo; nova versão a partir do aprovado (o anterior vigora até à aprovação); hierarquia top-down
 * (repartição IGUAL/REALIZADO/MANUAL) e bottom-up (contributos com responsável, consolidação).
 * Correcções (ADR-044):
 *   - quem submeteu não aprova (o legado só avisava);
 *   - a consolidação usa só a ÚLTIMA versão de cada filho (o legado somava v1 aprovada + v2 submetida em duplicado);
 *   - um orçamento com filhos não se elimina (o legado deixava pai_id órfão);
 *   - a chave é verificada dentro de uma transacção com bloqueio (dois pedidos em simultâneo não duplicam).
 */
final class ServicoOrcamentos
{
    public function __construct(
        private readonly ServicoExecucaoOrcamental $execucao,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    public static function chave(array|OrcamentoAnual $o): string
    {
        $o = $o instanceof OrcamentoAnual ? $o->toArray() : $o;

        return implode('|', [$o['ano'], $o['tipo'], $o['unidade_negocio_id'] ?? '', $o['centro_custo_id'] ?? '', $o['projeto_id'] ?? '']);
    }

    private function mesmaChave($q, array $c)
    {
        foreach (['unidade_negocio_id', 'centro_custo_id', 'projeto_id'] as $k) {
            empty($c[$k]) ? $q->whereNull($k) : $q->where($k, $c[$k]);
        }

        return $q->where('ano', $c['ano'])->where('tipo', $c['tipo']);
    }

    public static function fator(string $natureza, array $aj): float
    {
        $entrada = in_array($natureza, ['PROVEITO', 'RECEBIMENTO'], true);

        return (1 + (float) ($entrada ? ($aj['crescimento_proveitos_pct'] ?? 0) : ($aj['crescimento_custos_pct'] ?? 0)) / 100) * (1 + (float) ($aj['inflacao_pct'] ?? 0) / 100);
    }

    /** @param  array<string, mixed>  $d */
    public function criar(array $d): OrcamentoAnual
    {
        $cab = ['ano' => (int) $d['ano'], 'tipo' => $d['tipo'], 'unidade_negocio_id' => $d['unidade_negocio_id'] ?? null, 'centro_custo_id' => $d['centro_custo_id'] ?? null,
            'projeto_id' => $d['projeto_id'] ?? null];
        if (! RubricaOrcamental::query()->where('tipo', $cab['tipo'])->where('ativo', true)->exists()) {
            throw new ErroNegocio('Não há rubricas activas deste tipo: crie-as primeiro (ou use as rubricas base).', 'SEM_RUBRICAS', 422);
        }
        $metodo = ($d['metodo'] ?? 'HISTORICO') === 'BASE_ZERO' ? 'BASE_ZERO' : 'HISTORICO';
        $origem = $metodo === 'BASE_ZERO' ? null : ($d['origem'] ?? null);

        return DB::transaction(function () use ($d, $cab, $metodo, $origem) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['orcamento:'.self::chave($cab)]);
            $existente = $this->mesmaChave(OrcamentoAnual::query(), $cab)->max('versao');
            if ($existente) {
                throw new ErroNegocio("Já existe um orçamento para {$cab['ano']} com estas dimensões (versão {$existente}). Abra-o ou crie uma nova versão.", 'ORCAMENTO_EXISTENTE', 422);
            }
            $o = OrcamentoAnual::create($cab + ['nome' => trim((string) ($d['nome'] ?? '')) ?: ($cab['tipo'] === 'TESOURARIA' ? 'Orçamento de tesouraria ' : 'Orçamento de exploração ').$cab['ano'],
                'descricao' => $d['descricao'] ?? null, 'versao' => 1, 'estado' => 'RASCUNHO', 'criado_por' => Auth::user()?->nome_utilizador, 'rejeicoes' => [],
                'metodo' => $metodo, 'origem' => $origem, 'crescimento_proveitos_pct' => (float) ($d['crescimento_proveitos_pct'] ?? 0),
                'crescimento_custos_pct' => (float) ($d['crescimento_custos_pct'] ?? 0), 'inflacao_pct' => (float) ($d['inflacao_pct'] ?? 0),
                'abordagem' => $d['abordagem'] ?? null, 'responsavel' => $d['responsavel'] ?? null, 'orcamento_pai_id' => $d['orcamento_pai_id'] ?? null,
                'dimensao_filhos' => $d['dimensao_filhos'] ?? null, 'saldo_inicial' => $d['saldo_inicial'] ?? null]);
            if ($origem) {
                $this->preencherDeBase($o, $origem);
            }

            return $o->refresh();
        });
    }

    private function preencherDeBase(OrcamentoAnual $o, string $origem): void
    {
        $rubs = RubricaOrcamental::query()->where('tipo', $o->tipo)->where('ativo', true)->get()->keyBy('id');
        if ($origem === 'REALIZADO_ANTERIOR') {
            $base = $this->execucao->realizado($o, $o->ano - 1)['por_rubrica'];
            $rotulo = 'realizado '.($o->ano - 1);
        } else {
            $ant = $this->mesmaChave(OrcamentoAnual::query(), ['ano' => $o->ano - 1] + $o->only(['tipo', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id']))
                ->where('estado', 'APROVADO')->orderByDesc('versao')->first()
                ?? throw new ErroNegocio('Não há orçamento aprovado de '.($o->ano - 1).' com estas dimensões para servir de base.', 'SEM_BASE', 422);
            $base = LinhaOrcamento::query()->where('orcamento_anual_id', $ant->id)->pluck('valores', 'rubrica_orcamental_id')->all();
            $rotulo = 'orçamento '.($o->ano - 1)." v{$ant->versao}";
        }
        foreach ($base as $rid => $valores) {
            $r = $rubs[$rid] ?? null;
            if (! $r || ! collect($valores)->contains(fn ($x) => abs((float) $x) > 0.004)) {
                continue;
            }
            $f = self::fator($r->natureza, $o->toArray());
            $v = array_map(fn ($x) => round((float) $x * $f, 2), array_values((array) $valores));
            LinhaOrcamento::create(['orcamento_anual_id' => $o->id, 'rubrica_orcamental_id' => $r->id, 'valores' => $v, 'total' => round(array_sum($v), 2), 'notas' => "Base: {$rotulo}"]);
        }
    }

    /** @param  list<array{rubrica_orcamental_id: int, valores: list<mixed>, notas?: ?string}>  $linhas */
    public function gravarValores(OrcamentoAnual $o, array $linhas): OrcamentoAnual
    {
        return DB::transaction(function () use ($o, $linhas) {
            $o = OrcamentoAnual::query()->lockForUpdate()->findOrFail($o->id);
            $this->exigirEstado($o, ['RASCUNHO']);
            $rubs = RubricaOrcamental::query()->where('tipo', $o->tipo)->pluck('id')->flip();
            LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->delete();
            foreach ($linhas as $l) {
                if (! isset($rubs[(int) $l['rubrica_orcamental_id']])) {
                    throw new ErroNegocio('Rubrica inexistente ou de outro tipo de orçamento.', 'RUBRICA_INVALIDA', 422);
                }
                if (count($l['valores']) !== 12) {
                    throw new ErroNegocio('Cada rubrica tem 12 valores mensais.', 'VALORES_INVALIDOS', 422);
                }
                $v = array_map(fn ($x) => round((float) $x, 2), array_values($l['valores']));
                if (! collect($v)->contains(fn ($x) => abs($x) > 0.004) && trim((string) ($l['notas'] ?? '')) === '') {
                    continue;   // linhas a zero e sem notas não se gravam
                }
                LinhaOrcamento::create(['orcamento_anual_id' => $o->id, 'rubrica_orcamental_id' => (int) $l['rubrica_orcamental_id'], 'valores' => $v,
                    'total' => round(array_sum($v), 2), 'notas' => $l['notas'] ?? null]);
            }
            $o->update(['atualizado_por' => Auth::user()?->nome_utilizador]);

            return $o->refresh();
        });
    }

    public function submeter(OrcamentoAnual $o): OrcamentoAnual
    {
        $this->exigirEstado($o, ['RASCUNHO']);
        $linhas = LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->get();
        if ($linhas->isEmpty()) {
            throw new ErroNegocio('O orçamento não tem valores.', 'ORCAMENTO_VAZIO', 422);
        }
        if ($o->metodo === 'BASE_ZERO' && $linhas->contains(fn ($l) => (float) $l->total != 0 && mb_strlen(trim((string) $l->notas)) < 10)) {
            throw new ErroNegocio('Orçamento de base zero: justifique cada rubrica com valor (mínimo 10 caracteres).', 'JUSTIFICACAO_EM_FALTA', 422);
        }
        $o->update(['estado' => 'SUBMETIDO', 'submetido_por' => Auth::user()?->nome_utilizador, 'submetido_em' => now()]);

        return $o->refresh();
    }

    public function aprovar(OrcamentoAnual $o): OrcamentoAnual
    {
        return DB::transaction(function () use ($o) {
            $o = OrcamentoAnual::query()->lockForUpdate()->findOrFail($o->id);
            $this->exigirEstado($o, ['SUBMETIDO']);
            if ($o->submetido_por && $o->submetido_por === Auth::user()?->nome_utilizador) {
                throw new ErroNegocio('Quem submeteu o orçamento não o pode aprovar.', 'SEGREGACAO_FUNCOES', 403);
            }
            $this->mesmaChave(OrcamentoAnual::query(), $o->toArray())->where('estado', 'APROVADO')->whereKeyNot($o->id)
                ->update(['estado' => 'SUBSTITUIDO', 'substituido_por_id' => $o->id, 'substituido_em' => now()]);
            $o->update(['estado' => 'APROVADO', 'aprovado_por' => Auth::user()?->nome_utilizador, 'aprovado_em' => now()]);
            $this->auditoria->registar('Orçamento', 'Aprovar orçamento', self::chave($o)." v{$o->versao}", 'orcamentos_anuais', $o->id);

            return $o->refresh();
        });
    }

    public function devolver(OrcamentoAnual $o, string $motivo): OrcamentoAnual
    {
        $this->exigirEstado($o, ['SUBMETIDO']);
        $o->update(['estado' => 'RASCUNHO', 'rejeicoes' => [...(array) $o->rejeicoes, ['por' => Auth::user()?->nome_utilizador, 'em' => now()->toIso8601String(), 'motivo' => $motivo]]]);

        return $o->refresh();
    }

    public function novaVersao(OrcamentoAnual $o): OrcamentoAnual
    {
        return DB::transaction(function () use ($o) {
            $this->exigirEstado($o, ['APROVADO']);
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['orcamento:'.self::chave($o)]);
            if ($this->mesmaChave(OrcamentoAnual::query(), $o->toArray())->whereIn('estado', ['RASCUNHO', 'SUBMETIDO'])->exists()) {
                throw new ErroNegocio('Já existe uma versão em preparação para este orçamento.', 'VERSAO_EM_CURSO', 422);
            }
            $n = OrcamentoAnual::create(array_merge($o->only(['ano', 'tipo', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'nome', 'descricao', 'metodo', 'abordagem',
                'responsavel', 'orcamento_pai_id', 'dimensao_filhos', 'saldo_inicial']), ['versao' => $this->mesmaChave(OrcamentoAnual::query(), $o->toArray())->max('versao') + 1,
                    'versao_origem_id' => $o->id, 'estado' => 'RASCUNHO', 'criado_por' => Auth::user()?->nome_utilizador, 'rejeicoes' => []]));
            foreach (LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->get() as $l) {
                LinhaOrcamento::create(['orcamento_anual_id' => $n->id] + $l->only(['rubrica_orcamental_id', 'valores', 'total', 'notas']));
            }

            return $n->refresh();
        });
    }

    public function eliminar(OrcamentoAnual $o): void
    {
        $this->exigirEstado($o, ['RASCUNHO', 'SUBMETIDO']);
        if (OrcamentoAnual::query()->where('orcamento_pai_id', $o->id)->exists()) {
            throw new ErroNegocio('O orçamento tem orçamentos filhos (repartição/contributos): elimine-os primeiro.', 'REGISTO_EM_USO', 422);
        }
        DB::transaction(function () use ($o) {
            LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->delete();
            $o->delete();
        });
    }

    // ───────────── Hierarquia ─────────────

    /**
     * Top-down: reparte as linhas do pai pelos filhos em RASCUNHO. critério IGUAL, REALIZADO (peso do realizado do ano
     * anterior por rubrica) ou MANUAL (percentagens que somam 100).
     *
     * @param  array<int, float>  $percentagens  filho_id => %
     */
    public function repartirTopDown(OrcamentoAnual $pai, string $criterio, array $percentagens = []): array
    {
        $filhos = OrcamentoAnual::query()->where('orcamento_pai_id', $pai->id)->where('estado', 'RASCUNHO')->get();
        if ($filhos->isEmpty()) {
            throw new ErroNegocio('Não há orçamentos filhos em rascunho para repartir.', 'SEM_FILHOS', 422);
        }
        if ($criterio === 'MANUAL' && abs(array_sum(array_map('floatval', $percentagens)) - 100) > 0.01) {
            throw new ErroNegocio('As percentagens da repartição têm de somar 100.', 'PERCENTAGENS_INVALIDAS', 422);
        }

        return DB::transaction(function () use ($pai, $filhos, $criterio, $percentagens) {
            $linhas = LinhaOrcamento::query()->where('orcamento_anual_id', $pai->id)->get();
            $pesos = [];
            if ($criterio === 'REALIZADO') {
                foreach ($filhos as $f) {
                    $pesos[$f->id] = array_map(fn ($v) => array_sum($v), $this->execucao->realizado($f, $f->ano - 1)['por_rubrica']);
                }
            }
            foreach ($filhos as $f) {
                LinhaOrcamento::query()->where('orcamento_anual_id', $f->id)->delete();
            }
            foreach ($linhas as $l) {
                $total = array_sum(array_map(fn ($f) => abs((float) ($pesos[$f->id][$l->rubrica_orcamental_id] ?? 0)), $filhos->all()));
                $partes = [];
                foreach ($filhos as $i => $f) {
                    $partes[$f->id] = match ($criterio) {
                        'MANUAL' => (float) ($percentagens[$f->id] ?? 0) / 100,
                        'REALIZADO' => $total > 0 ? abs((float) ($pesos[$f->id][$l->rubrica_orcamental_id] ?? 0)) / $total : 1 / $filhos->count(),
                        default => 1 / $filhos->count(),
                    };
                }
                $restos = array_map('floatval', (array) $l->valores);
                $ultimo = $filhos->last()->id;
                foreach ($filhos as $f) {
                    // arredondamento: o último filho fica com o resto, para a soma dos filhos igualar o pai
                    $v = $f->id === $ultimo ? array_map(fn ($x) => round($x, 2), $restos)
                        : array_map(fn ($x) => round((float) $x * $partes[$f->id], 2), array_values((array) $l->valores));
                    if ($f->id !== $ultimo) {
                        foreach ($v as $m => $x) {
                            $restos[$m] -= $x;
                        }
                    }
                    if (collect($v)->contains(fn ($x) => abs($x) > 0.004)) {
                        LinhaOrcamento::create(['orcamento_anual_id' => $f->id, 'rubrica_orcamental_id' => $l->rubrica_orcamental_id, 'valores' => $v, 'total' => round(array_sum($v), 2),
                            'notas' => "Repartição top-down ({$criterio}) do orçamento #{$pai->id}"]);
                    }
                }
            }

            return ['filhos' => $filhos->count(), 'rubricas' => $linhas->count()];
        });
    }

    /**
     * Bottom-up: cria um orçamento filho em rascunho por dimensão, com responsável.
     *
     * @param  list<array{unidade_negocio_id?: ?int, centro_custo_id?: ?int, projeto_id?: ?int, responsavel: string}>  $filhos
     */
    public function pedirContributos(OrcamentoAnual $pai, array $filhos, ?string $prazo, bool $preencher): array
    {
        return DB::transaction(function () use ($pai, $filhos, $prazo, $preencher) {
            $criados = [];
            foreach ($filhos as $f) {
                if (trim((string) ($f['responsavel'] ?? '')) === '') {
                    throw new ErroNegocio('Cada contributo tem de ter um responsável.', 'RESPONSAVEL_EM_FALTA', 422);
                }
                $criados[] = $this->criar(['ano' => $pai->ano, 'tipo' => $pai->tipo, 'unidade_negocio_id' => $f['unidade_negocio_id'] ?? $pai->unidade_negocio_id,
                    'centro_custo_id' => $f['centro_custo_id'] ?? $pai->centro_custo_id, 'projeto_id' => $f['projeto_id'] ?? $pai->projeto_id,
                    'nome' => "{$pai->nome} — contributo", 'metodo' => 'HISTORICO', 'origem' => $preencher ? 'REALIZADO_ANTERIOR' : null, 'abordagem' => 'BOTTOM_UP',
                    'responsavel' => $f['responsavel'], 'orcamento_pai_id' => $pai->id])->id;
            }
            $pai->update(['abordagem' => 'BOTTOM_UP', 'prazo_contributo' => $prazo]);

            return $criados;
        });
    }

    /** Soma no pai (rascunho) a ÚLTIMA versão de cada filho, se submetida ou aprovada. */
    public function consolidar(OrcamentoAnual $pai): OrcamentoAnual
    {
        $this->exigirEstado($pai, ['RASCUNHO']);

        return DB::transaction(function () use ($pai) {
            $filhos = OrcamentoAnual::query()->where('orcamento_pai_id', $pai->id)->get()->groupBy(fn ($f) => self::chave($f))
                ->map(fn ($g) => $g->sortByDesc('versao')->first())->filter(fn ($f) => in_array($f->estado, ['SUBMETIDO', 'APROVADO'], true))->values();
            if ($filhos->isEmpty()) {
                throw new ErroNegocio('Nenhum contributo foi submetido.', 'SEM_CONTRIBUTOS', 422);
            }
            $soma = [];
            foreach (LinhaOrcamento::query()->whereIn('orcamento_anual_id', $filhos->pluck('id'))->get() as $l) {
                $soma[$l->rubrica_orcamental_id] ??= array_fill(0, 12, 0.0);
                foreach (array_values((array) $l->valores) as $m => $x) {
                    $soma[$l->rubrica_orcamental_id][$m] += (float) $x;
                }
            }
            LinhaOrcamento::query()->where('orcamento_anual_id', $pai->id)->delete();
            foreach ($soma as $rid => $v) {
                $v = array_map(fn ($x) => round($x, 2), $v);
                LinhaOrcamento::create(['orcamento_anual_id' => $pai->id, 'rubrica_orcamental_id' => $rid, 'valores' => $v, 'total' => round(array_sum($v), 2),
                    'notas' => 'Consolidação de '.$filhos->count().' contributo(s)']);
            }
            $pai->update(['consolidado_em' => now(), 'consolidado_por' => Auth::user()?->nome_utilizador, 'consolidado_ids' => $filhos->pluck('id')->all()]);

            return $pai->refresh();
        });
    }

    /** O responsável de um contributo pode editá-lo e submetê-lo sem a permissão geral. */
    public static function eResponsavel(OrcamentoAnual $o): bool
    {
        return $o->responsavel && $o->responsavel === Auth::user()?->nome_utilizador;
    }

    private function exigirEstado(OrcamentoAnual $o, array $estados): void
    {
        if (! in_array($o->estado, $estados, true)) {
            throw new ErroNegocio("O orçamento está {$o->estado}: operação não permitida.", 'ESTADO_INVALIDO', 422);
        }
    }
}
