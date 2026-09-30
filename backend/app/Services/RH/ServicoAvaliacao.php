<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\AutoavaliacaoColaborador;
use App\Models\AvaliacaoDesempenhoRH;
use App\Models\BonificacaoAvaliacaoRH;
use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\CriterioAvaliacaoRH;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Avaliação de desempenho (js/modules/rh/avaliacao.js) e autoavaliação do portal (portal_dados.js:400-433).
 * Paridade: critérios (nota 1–5, peso) e objectivos (quantitativos: atingido/meta, sentido MAIOR/MENOR, 0–200 %;
 * qualitativos: (nota−1)·25 %); pontuação = critérios·(1−P) + objectivos·P (P = 30 % por omissão), 2 casas;
 * classes Excelente ≥ 4,5 · Muito Bom ≥ 3,5 · Bom ≥ 2,5 · Suficiente ≥ 1,5 · Insuficiente; uma avaliação por
 * colaborador, ano e período (agora com índice único); concluir exige todas as notas, resultados, avaliador e data;
 * concluída ou tomada de conhecimento → não se altera; itens comuns (8 critérios padrão na 1.ª utilização) e específicos.
 * Correcções (ADR-041):
 *   - ninguém se avalia a si próprio (o legado permitia ao RH);
 *   - peso dos objectivos vazio = 30 (no legado Number('') = 0 e os objectivos deixavam de contar em silêncio);
 *   - a chefia é a do ciclo do MESMO ano/período (o legado usava o ciclo que estivesse aberto);
 *   - eliminar só sem tomada de conhecimento, contestação nem bonificação (o legado apagava e deixava bónus órfãos);
 *   - item de avaliação já usado desactiva-se em vez de se eliminar (o legado só avisava).
 */
final class ServicoAvaliacao
{
    public const PERIODOS = ['ANUAL', 'S1', 'S2', 'T1', 'T2', 'T3', 'T4'];

    public const PESO_OBJECTIVOS = 30;

    public const CRITERIOS_PADRAO = ['qualidade' => 'Qualidade do trabalho', 'produtividade' => 'Produtividade', 'assiduidade' => 'Assiduidade e pontualidade',
        'conhecimentos' => 'Conhecimentos técnicos', 'equipa' => 'Trabalho em equipa', 'iniciativa' => 'Iniciativa', 'responsabilidade' => 'Responsabilidade',
        'relacionamento' => 'Relacionamento interpessoal'];

    public function __construct(
        private readonly ServicoEstruturaOrg $estrutura,
        private readonly ServicoPortalColaborador $portal,
    ) {}

    // ───────────── Cálculo ─────────────

    public static function classificar(?float $p): string
    {
        return match (true) {
            $p === null => '', $p >= 4.5 => 'Excelente', $p >= 3.5 => 'Muito Bom', $p >= 2.5 => 'Bom', $p >= 1.5 => 'Suficiente', default => 'Insuficiente',
        };
    }

    public static function resultadoQuantitativo(mixed $atingido, mixed $meta, ?string $sentido): ?float
    {
        if ($atingido === null || $atingido === '' || ! is_numeric($atingido) || ! ((float) $meta > 0)) {
            return null;
        }
        $a = (float) $atingido;
        $r = $sentido === 'MENOR' ? ($a <= 0 ? 200 : (float) $meta / $a * 100) : $a / (float) $meta * 100;

        return round(min(200, max(0, $r)), 1);
    }

    public static function resultadoQualitativo(mixed $nota): ?float
    {
        return is_numeric($nota) && (float) $nota >= 1 && (float) $nota <= 5 ? ((float) $nota - 1) * 25 : null;
    }

    /** @return array{pontuacao_criterios: ?float, pontuacao_objetivos: ?float, pontuacao: ?float, classificacao: string} */
    public static function calcular(array $criterios, array $objetivos, float $pesoObjetivos = self::PESO_OBJECTIVOS): array
    {
        $cAval = array_filter($criterios, fn ($c) => (float) ($c['nota'] ?? 0) >= 1 && (float) ($c['nota'] ?? 0) <= 5 && (float) ($c['peso'] ?? 0) > 0);
        $somaC = array_sum(array_map(fn ($c) => (float) $c['peso'], $cAval));
        $pc = $somaC ? array_sum(array_map(fn ($c) => (float) $c['nota'] * (float) $c['peso'], $cAval)) / $somaC : null;
        $oVal = array_filter($objetivos, fn ($o) => trim((string) ($o['descricao'] ?? '')) !== '' && (float) ($o['peso'] ?? 0) > 0 && isset($o['resultado']) && is_numeric($o['resultado']));
        $somaO = array_sum(array_map(fn ($o) => (float) $o['peso'], $oVal));
        $po = $somaO ? array_sum(array_map(fn ($o) => (1 + 4 * min(100, max(0, (float) $o['resultado'])) / 100) * (float) $o['peso'], $oVal)) / $somaO : null;
        $w = min(100, max(0, $pesoObjetivos)) / 100;
        $final = $pc !== null && $po !== null ? $pc * (1 - $w) + $po * $w : ($pc ?? $po);
        $arred = fn (?float $v) => $v === null ? null : round($v, 2);

        return ['pontuacao_criterios' => $arred($pc), 'pontuacao_objetivos' => $arred($po), 'pontuacao' => $arred($final), 'classificacao' => self::classificar($final)];
    }

    // ───────────── Itens ─────────────

    /** Itens da empresa; na primeira utilização criam-se os 8 critérios padrão como itens comuns. */
    public function itens(): array
    {
        if (! CriterioAvaliacaoRH::query()->exists()) {
            $n = 0;
            foreach (self::CRITERIOS_PADRAO as $chave => $nome) {
                CriterioAvaliacaoRH::create(['ambito' => 'COMUM', 'tipo' => 'CRITERIO', 'chave' => $chave, 'nome' => $nome, 'peso' => 1, 'ordem' => ++$n, 'ativo' => true]);
            }
        }

        return CriterioAvaliacaoRH::query()->orderBy('ambito')->orderBy('tipo')->orderBy('ordem')->orderBy('nome')->get()->all();
    }

    /** Itens aplicáveis a um colaborador (comuns + específicos activos). */
    public function itensDe(int $colaborador, string $tipo): array
    {
        $this->itens();

        return CriterioAvaliacaoRH::query()->where('ativo', true)->where('tipo', $tipo)
            ->where(fn ($q) => $q->where('ambito', 'COMUM')->orWhere(fn ($x) => $x->where('ambito', 'ESPECIFICO')->where('colaborador_id', $colaborador)))
            ->orderBy('ordem')->orderBy('nome')->get()->all();
    }

    public function guardarItem(array $d, ?CriterioAvaliacaoRH $i = null): CriterioAvaliacaoRH
    {
        $d['nome'] = trim((string) $d['nome']);
        $colab = $d['ambito'] === 'ESPECIFICO' ? (int) $d['colaborador_id'] : null;
        if ($d['ambito'] === 'ESPECIFICO') {
            Colaborador::query()->findOrFail($colab);
        }
        $repetido = CriterioAvaliacaoRH::query()->whereRaw('lower(nome) = lower(?)', [$d['nome']])->where('tipo', $d['tipo'])->when($i, fn ($q) => $q->whereKeyNot($i->id))
            ->where(fn ($q) => $q->where('ambito', 'COMUM')->when($colab, fn ($x) => $x->orWhere('colaborador_id', $colab)))->exists();
        if ($repetido) {
            throw new ErroNegocio("Já existe o item «{$d['nome']}» (comum ou do colaborador).", 'ITEM_DUPLICADO', 422);
        }
        if ($d['tipo'] === 'OBJECTIVO' && ($d['natureza'] ?? 'QUANTITATIVO') === 'QUANTITATIVO' && ! ((float) ($d['meta'] ?? 0) > 0)) {
            throw new ErroNegocio('Um objectivo quantitativo precisa de meta > 0.', 'META_EM_FALTA', 422);
        }
        $d['colaborador_id'] = $colab;
        $d['chave'] ??= $i?->chave ?? 'item_'.bin2hex(random_bytes(4));
        $i ? $i->update($d) : $i = CriterioAvaliacaoRH::create($d + ['peso' => 1, 'ativo' => true, 'ordem' => 99]);

        return $i->refresh();
    }

    /** Eliminar um item já usado numa avaliação apenas o desactiva. */
    public function eliminarItem(CriterioAvaliacaoRH $i): string
    {
        $usado = AvaliacaoDesempenhoRH::query()->where(fn ($q) => $q->whereRaw('criterios @> ?::jsonb', [json_encode([['chave' => $i->chave]])])
            ->orWhereRaw('objetivos @> ?::jsonb', [json_encode([['chave' => $i->chave]])]))->exists();
        if ($usado) {
            $i->update(['ativo' => false]);

            return 'DESACTIVADO';
        }
        $i->delete();

        return 'ELIMINADO';
    }

    // ───────────── Avaliações ─────────────

    /**
     * @param  array<string, mixed>  $d  colaborador_id, ano, periodo, criterios[{chave, nota, comentario}], objetivos[{chave, atingido|nota_qual, comentario}],
     *                                   peso_objetivos?, avaliador?, data_avaliacao?, pontos_fortes?, pontos_melhorar?, plano_desenvolvimento?, concluir?
     */
    public function gravar(array $d, bool $comoChefia = false): AvaliacaoDesempenhoRH
    {
        $colab = Colaborador::query()->findOrFail($d['colaborador_id']);
        $eu = $this->portal->colaboradorDe();
        if ($eu && $eu === $colab->id) {
            throw new ErroNegocio('Não pode avaliar-se a si próprio.', 'AUTO_AVALIACAO', 403);
        }
        $ciclo = CicloAvaliacao360::query()->where('ano', $d['ano'])->where('periodo', $d['periodo'])->first();
        if ($comoChefia) {
            if (! $eu || $this->chefiaNoPeriodo($colab->id, $ciclo) !== $eu) {
                throw new ErroNegocio('Só a chefia directa (ou o RH) avalia este colaborador.', 'SEM_PERMISSAO', 403);
            }
            if ($ciclo && $ciclo->estado !== 'ABERTO') {
                throw new ErroNegocio('O ciclo de avaliação deste período não está aberto.', 'CICLO_NAO_ABERTO', 422);
            }
        }

        return DB::transaction(function () use ($d, $colab, $ciclo) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["avaliacao:{$colab->id}:{$d['ano']}:{$d['periodo']}"]);
            $a = AvaliacaoDesempenhoRH::query()->where('colaborador_id', $colab->id)->where('ano', $d['ano'])->where('periodo', $d['periodo'])->lockForUpdate()->first();
            if ($a && ($a->estado === 'CONCLUIDA' || $a->conhecimento)) {
                throw new ErroNegocio('A avaliação está concluída: reabra-a para alterar.', 'AVALIACAO_CONCLUIDA', 422);
            }
            $itensC = collect($this->itensDe($colab->id, 'CRITERIO'))->keyBy('chave');
            $itensO = collect($this->itensDe($colab->id, 'OBJECTIVO'))->keyBy('chave');
            $notas = collect($d['criterios'] ?? [])->keyBy('chave');
            $criterios = $itensC->map(fn ($i) => ['chave' => $i->chave, 'nome' => $i->nome, 'ambito' => $i->ambito, 'item_id' => $i->id, 'descricao' => $i->descricao,
                'peso' => (float) $i->peso, 'nota' => isset($notas[$i->chave]['nota']) && $notas[$i->chave]['nota'] !== null ? max(1, min(5, (int) round((float) $notas[$i->chave]['nota']))) : null,
                'comentario' => $notas[$i->chave]['comentario'] ?? null])->values()->all();
            $res = collect($d['objetivos'] ?? [])->keyBy('chave');
            $objetivos = $itensO->map(function ($i) use ($res) {
                $r = $res[$i->chave] ?? [];
                $qual = $i->natureza === 'QUALITATIVO';

                return ['chave' => $i->chave, 'ambito' => $i->ambito, 'item_id' => $i->id, 'descricao' => $i->nome, 'detalhe' => $i->descricao, 'peso' => (float) $i->peso,
                    'natureza' => $i->natureza ?? 'QUANTITATIVO', 'meta' => $i->meta !== null ? (float) $i->meta : null, 'unidade' => $i->unidade, 'sentido' => $i->sentido ?? 'MAIOR',
                    'atingido' => $r['atingido'] ?? null, 'nota_qual' => $r['nota_qual'] ?? null, 'comentario' => $r['comentario'] ?? null,
                    'resultado' => $qual ? self::resultadoQualitativo($r['nota_qual'] ?? null) : self::resultadoQuantitativo($r['atingido'] ?? null, $i->meta, $i->sentido)];
            })->values()->all();
            $peso = isset($d['peso_objetivos']) && $d['peso_objetivos'] !== '' && $d['peso_objetivos'] !== null ? (float) $d['peso_objetivos'] : self::PESO_OBJECTIVOS;
            $calc = self::calcular($criterios, $objetivos, $peso);
            $concluir = ! empty($d['concluir']);
            if ($concluir) {
                $faltas = [];
                if (collect($criterios)->contains(fn ($c) => $c['nota'] === null)) {
                    $faltas[] = 'todas as notas dos critérios';
                }
                if (! collect($criterios)->contains(fn ($c) => $c['peso'] > 0) && ! collect($objetivos)->contains(fn ($o) => $o['peso'] > 0)) {
                    $faltas[] = 'pelo menos um item com peso';
                }
                if (collect($objetivos)->contains(fn ($o) => $o['resultado'] === null)) {
                    $faltas[] = 'o resultado de todos os objectivos';
                }
                if (trim((string) ($d['avaliador'] ?? '')) === '' || empty($d['data_avaliacao'])) {
                    $faltas[] = 'o avaliador e a data';
                }
                if ($faltas) {
                    throw new ErroNegocio('Para concluir falta: '.implode('; ', $faltas).'.', 'AVALIACAO_INCOMPLETA', 422, ['faltas' => $faltas]);
                }
            }
            $dados = ['colaborador_id' => $colab->id, 'ano' => (int) $d['ano'], 'periodo' => $d['periodo'], 'criterios' => $criterios, 'objetivos' => $objetivos,
                'peso_objetivos' => $peso, 'avaliador' => $d['avaliador'] ?? null, 'data_avaliacao' => $d['data_avaliacao'] ?? null, 'pontos_fortes' => $d['pontos_fortes'] ?? null,
                'pontos_melhorar' => $d['pontos_melhorar'] ?? null, 'plano_desenvolvimento' => $d['plano_desenvolvimento'] ?? null, 'estado' => $concluir ? 'CONCLUIDA' : 'RASCUNHO',
                'ciclo_avaliacao_id' => $ciclo?->id] + $calc
                + ($concluir ? ['concluida_em' => now(), 'concluida_por' => Auth::user()?->nome_utilizador] : []);
            $a ? $a->update($dados) : $a = AvaliacaoDesempenhoRH::create($dados);
            if ($concluir && $ciclo) {
                app(ServicoAvaliacao360::class)->actualizar360($a->refresh());
            }

            return $a->refresh();
        });
    }

    public function reabrir(AvaliacaoDesempenhoRH $a): AvaliacaoDesempenhoRH
    {
        if ($a->estado !== 'CONCLUIDA') {
            throw new ErroNegocio('A avaliação não está concluída.', 'ESTADO_INVALIDO', 422);
        }
        if ($a->conhecimento) {
            throw new ErroNegocio('O colaborador já tomou conhecimento: a avaliação não se reabre.', 'AVALIACAO_CONHECIDA', 422);
        }
        $a->update(['estado' => 'RASCUNHO', 'reaberta_em' => now(), 'reaberta_por' => Auth::user()?->nome_utilizador]);

        return $a->refresh();
    }

    public function eliminar(AvaliacaoDesempenhoRH $a): void
    {
        if ($a->conhecimento || $a->contestacao || BonificacaoAvaliacaoRH::query()->where('avaliacao_desempenho_id', $a->id)->exists()) {
            throw new ErroNegocio('A avaliação já foi dada a conhecer, contestada ou tem bonificação: não se elimina.', 'REGISTO_EM_USO', 422);
        }
        $a->delete();
    }

    /** Chefia para o período: a registada nos participantes do ciclo desse ano/período, ou a chefia directa actual. */
    public function chefiaNoPeriodo(int $colaborador, ?CicloAvaliacao360 $ciclo): ?int
    {
        $p = $ciclo ? collect((array) $ciclo->participantes)->first(fn ($x) => (int) ($x['colaborador_id'] ?? $x['employee_id'] ?? 0) === $colaborador) : null;
        $chefe = $p['chefia_id'] ?? null;

        return $chefe ? (int) $chefe : $this->estrutura->chefiaDe($colaborador);
    }

    // ───────────── Autoavaliação (portal) ─────────────

    public function gravarAutoavaliacao(array $d): AutoavaliacaoColaborador
    {
        $c = $this->portal->exigirColaborador();

        return DB::transaction(function () use ($c, $d) {
            $a = AutoavaliacaoColaborador::query()->where('colaborador_id', $c->id)->where('ano', $d['ano'])->where('periodo', $d['periodo'])->lockForUpdate()->first();
            if ($a?->estado === 'SUBMETIDA') {
                throw new ErroNegocio('A autoavaliação já foi submetida.', 'AUTOAVALIACAO_SUBMETIDA', 422);
            }
            $notas = collect($d['criterios'] ?? [])->keyBy('chave');
            $criterios = collect($this->itensDe($c->id, 'CRITERIO'))->map(fn ($i) => ['chave' => $i->chave, 'nome' => $i->nome,
                'nota' => isset($notas[$i->chave]['nota']) ? max(1, min(5, (int) round((float) $notas[$i->chave]['nota']))) : null, 'comentario' => $notas[$i->chave]['comentario'] ?? null])->all();
            $res = collect($d['objetivos'] ?? [])->keyBy('chave');
            $objetivos = collect($this->itensDe($c->id, 'OBJECTIVO'))->map(fn ($i) => ['chave' => $i->chave, 'descricao' => $i->nome,
                'resultado' => isset($res[$i->chave]['resultado']) ? max(0, min(200, (float) $res[$i->chave]['resultado'])) : null])->all();
            $submeter = ! empty($d['submeter']);
            if ($submeter && (collect($criterios)->contains(fn ($x) => $x['nota'] === null) || mb_strlen(trim((string) ($d['realizacoes'] ?? ''))) < 10)) {
                throw new ErroNegocio('Para submeter, dê nota a todos os critérios e descreva as realizações (mínimo 10 caracteres).', 'AUTOAVALIACAO_INCOMPLETA', 422);
            }
            $dados = ['colaborador_id' => $c->id, 'ano' => (int) $d['ano'], 'periodo' => $d['periodo'], 'criterios' => array_values($criterios), 'objetivos' => array_values($objetivos),
                'realizacoes' => $d['realizacoes'] ?? null, 'dificuldades' => $d['dificuldades'] ?? null, 'formacao' => $d['formacao'] ?? null,
                'estado' => $submeter ? 'SUBMETIDA' : 'RASCUNHO', 'submetida_em' => $submeter ? now() : null];
            $a ? $a->update($dados) : $a = AutoavaliacaoColaborador::create($dados);

            return $a->refresh();
        });
    }
}
