<?php

namespace App\Services\RH;

use App\Models\AutoavaliacaoColaborador;
use App\Models\AvaliacaoDesempenhoRH;
use App\Models\CicloAvaliacao360;
use App\Models\ConfirmacaoAvaliacaoRH;
use App\Models\FeedbackAvaliacao360;
use App\Models\RespostaAvaliacao360;

/**
 * «Acompanhamento do Ciclo 360º» do RH (legado js/modules/rh/aval360_ui.js renderAval360Ciclo): numa só leitura, para o
 * ciclo escolhido, a participação (chefia, autoavaliação, respostas de pares e subordinados — só contagens, nunca quem
 * respondeu: anonimato), as reuniões de acompanhamento (registadas e em falta pela periodicidade do ciclo), as
 * confirmações de leitura do comunicado e os indicadores do topo. Só leitura; as regras ficam no ServicoAvaliacao360.
 */
final class ServicoAcompanhamento360RH
{
    private const PASSOS = ['MENSAL' => 1, 'TRIMESTRAL' => 3, 'SEMESTRAL' => 6];

    /** @return array<string, mixed> */
    public function acompanhamento(CicloAvaliacao360 $c, ?string $hoje = null): array
    {
        $hoje ??= now()->toDateString();
        $participantes = collect((array) $c->participantes)->map(fn ($p) => [
            'colaborador_id' => (int) ($p['colaborador_id'] ?? $p['employee_id'] ?? 0),
            'nome' => (string) ($p['nome'] ?? ''),
            'chefia_id' => isset($p['chefia_id']) ? (int) $p['chefia_id'] : null,
            'pares' => count((array) ($p['pares'] ?? [])),
            'subordinados' => count((array) ($p['subordinados'] ?? [])),
        ])->filter(fn ($p) => $p['colaborador_id'] > 0)->values();
        $ids = $participantes->pluck('colaborador_id')->all();
        $nomes = $participantes->pluck('nome', 'colaborador_id')->all();

        $avaliacoes = AvaliacaoDesempenhoRH::query()->where('ano', $c->ano)->where('periodo', $c->periodo)->whereIn('colaborador_id', $ids)->get()->keyBy('colaborador_id');
        $autos = AutoavaliacaoColaborador::query()->where('ano', $c->ano)->where('periodo', $c->periodo)->whereIn('colaborador_id', $ids)->get()->keyBy('colaborador_id');
        $respostas = RespostaAvaliacao360::query()->where('ciclo_avaliacao_id', $c->id)
            ->selectRaw('colaborador_avaliado_id, grupo, count(*) as n')->groupBy('colaborador_avaliado_id', 'grupo')->get();
        $contagem = fn (int $cid, string $g) => (int) ($respostas->first(fn ($r) => (int) $r->colaborador_avaliado_id === $cid && $r->grupo === $g)?->n ?? 0);

        $participacao = $participantes->map(function ($p) use ($avaliacoes, $autos, $contagem, $nomes, $c) {
            $a = $avaliacoes->get($p['colaborador_id']);
            $auto = $autos->get($p['colaborador_id']);
            $fase = ServicoAvaliacao360::fase($a, $c);

            return [
                'colaborador_id' => $p['colaborador_id'], 'nome' => $p['nome'],
                'chefia_id' => $p['chefia_id'], 'chefia' => $p['chefia_id'] ? ($nomes[$p['chefia_id']] ?? null) : null,
                'auto' => $auto ? ($auto->estado === 'SUBMETIDA' ? 'SUBMETIDA' : 'RASCUNHO') : null,
                'pares_recebidas' => $contagem($p['colaborador_id'], 'PARES'), 'pares_total' => $p['pares'],
                'subordinados_recebidas' => $contagem($p['colaborador_id'], 'SUBORDINADOS'), 'subordinados_total' => $p['subordinados'],
                'avaliacao_id' => $a?->id, 'estado' => $a?->estado, 'fase' => $fase,
                'nota_chefia' => $a?->pontuacao !== null ? (float) $a->pontuacao : null,
                'nota_final' => $a && $a->estado === 'CONCLUIDA' ? ServicoAvaliacao360::notaFinal($a) : null,
            ];
        })->all();

        $feedbacks = FeedbackAvaliacao360::query()->where('ciclo_avaliacao_id', $c->id)->orderBy('data')->get();
        $registadas = $feedbacks->map(fn (FeedbackAvaliacao360 $f) => [
            'id' => $f->id, 'colaborador_id' => (int) $f->colaborador_id, 'nome' => $nomes[(int) $f->colaborador_id] ?? null,
            'periodo_referencia' => $f->periodo_referencia, 'data' => $f->data?->toDateString(),
            'resumo' => $f->acordos ?: ($f->melhorar ?: $f->positivos),
            'confirmado_em' => $f->confirmacao['em'] ?? null, 'comentario' => $f->confirmacao['comentario'] ?? null,
        ])->all();
        $emFalta = [];
        foreach (self::periodosFeedback($c) as $per) {
            if ($per['fim'] >= $hoje) {
                continue;
            }
            foreach ($participantes as $p) {
                if ($p['chefia_id'] && ! $feedbacks->contains(fn ($f) => (int) $f->colaborador_id === $p['colaborador_id'] && $f->periodo_referencia === $per['ref'])) {
                    $emFalta[] = ['colaborador_id' => $p['colaborador_id'], 'nome' => $p['nome'], 'chefia' => $nomes[$p['chefia_id']] ?? null, 'periodo_referencia' => $per['ref'], 'terminou' => $per['fim']];
                }
            }
        }

        $confirmacoes = ConfirmacaoAvaliacaoRH::query()->where('ciclo_avaliacao_id', $c->id)->whereIn('colaborador_id', $ids)->orderBy('em')->get();
        $leituras = $confirmacoes->map(fn ($l) => ['colaborador_id' => (int) $l->colaborador_id, 'nome' => $nomes[(int) $l->colaborador_id] ?? null, 'em' => $l->em?->toIso8601String()])->all();
        $confirmados = $confirmacoes->pluck('colaborador_id')->map(fn ($v) => (int) $v)->all();
        $porConfirmar = $participantes->reject(fn ($p) => in_array($p['colaborador_id'], $confirmados, true))->map(fn ($p) => ['colaborador_id' => $p['colaborador_id'], 'nome' => $p['nome']])->values()->all();

        $contestacoesPendentes = $avaliacoes->filter(fn ($a) => $a->contestacao && empty($a->contestacao['decisao']))->count();

        return [
            'participacao' => $participacao,
            'reunioes' => ['periodicidade' => $c->feedback['periodicidade'] ?? 'NENHUM', 'registadas' => $registadas, 'em_falta' => $emFalta],
            'comunicado' => ['titulo' => $c->comunicado['titulo'] ?? null, 'texto' => $c->comunicado['texto'] ?? null, 'confirmacoes' => $leituras, 'por_confirmar' => $porConfirmar],
            'indicadores' => [
                'colaboradores' => $participantes->count(),
                'autoavaliacoes_submetidas' => $autos->filter(fn ($a) => $a->estado === 'SUBMETIDA')->count(),
                'respostas_360' => (int) $respostas->sum('n'),
                'chefia_concluida' => $avaliacoes->filter(fn ($a) => $a->estado === 'CONCLUIDA')->count(),
                'contestacoes_por_decidir' => $contestacoesPendentes,
                'finais' => collect($participacao)->where('fase', 'FINAL')->count(),
            ],
            'contadores' => ['contestacoes' => $contestacoesPendentes, 'reunioes' => count($emFalta), 'comunicado' => count($porConfirmar)],
        ];
    }

    /**
     * Períodos de acompanhamento do ciclo pela periodicidade (MENSAL «2026-03», TRIMESTRAL «2026-T1», SEMESTRAL «2026-S1»).
     *
     * @return list<array{ref: string, inicio: string, fim: string}>
     */
    public static function periodosFeedback(CicloAvaliacao360 $c): array
    {
        $passo = self::PASSOS[$c->feedback['periodicidade'] ?? 'NENHUM'] ?? null;
        $ini = $c->data_inicio?->format('Y-m-d');
        $fim = $c->data_fim?->format('Y-m-d');
        if (! $passo || ! $ini || ! $fim) {
            return [];
        }
        $res = [];
        $d = new \DateTimeImmutable(substr($ini, 0, 7).'-01');
        while (count($res) < 25) {
            $inicio = $d->format('Y-m-d');
            if ($inicio > $fim) {
                break;
            }
            $a = (int) $d->format('Y');
            $m = (int) $d->format('n');
            $fimP = $d->modify('+'.$passo.' months')->modify('-1 day')->format('Y-m-d');
            $ref = match ($passo) {
                1 => $d->format('Y-m'),
                3 => $a.'-T'.(int) ceil($m / 3),
                default => $a.'-S'.(int) ceil($m / 6),
            };
            $res[] = ['ref' => $ref, 'inicio' => $inicio, 'fim' => min($fimP, $fim)];
            $d = $d->modify('+'.$passo.' months');
        }

        return $res;
    }
}
