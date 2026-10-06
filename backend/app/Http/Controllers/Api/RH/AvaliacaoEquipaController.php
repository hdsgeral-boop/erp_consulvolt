<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\AutoavaliacaoColaborador;
use App\Models\AvaliacaoDesempenhoRH;
use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\FeedbackAvaliacao360;
use App\Services\RH\ServicoAvaliacao;
use App\Services\RH\ServicoAvaliacao360;
use App\Services\RH\ServicoEstruturaOrg;
use App\Services\RH\ServicoPortalColaborador;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * M-11 — leituras que faltavam aos ecrãs (as gravações já existiam: POST /rh/avaliacao/avaliacoes como chefia e
 * POST /rh/avaliacao/feedbacks):
 *   - GET /rh/avaliacao/equipa: a chefia vê no portal a sua equipa do período (chefia do ciclo desse ano/período, senão
 *     a chefia directa), com a avaliação, os itens a avaliar e as reuniões de acompanhamento (avaliacaoGravarChefia,
 *     aval360RegistarFeedback do legado);
 *   - GET /rh/avaliacao/autoavaliacoes: o RH vê as autoavaliações (separador «Autoavaliações», portal_ui.js:615);
 *   - GET /rh/avaliacao/chefias: as chefias com equipa, para o separador «Avaliação das chefias» (resultados anónimos
 *     por GET /rh/avaliacao/ascendente/{colaborador}, com o mínimo de respostas do ciclo).
 */
final class AvaliacaoEquipaController extends Controller
{
    public function __construct(
        private readonly ServicoAvaliacao $avaliacao,
        private readonly ServicoPortalColaborador $portal,
        private readonly ServicoEstruturaOrg $estrutura,
    ) {}

    public function equipa(Request $r): JsonResponse
    {
        $this->exigir('rh_portal_usar', 'rh_avaliacao_edit');
        $f = $r->validate(['ano' => ['required', 'integer'], 'periodo' => ['required', Rule::in(ServicoAvaliacao::PERIODOS)]]);
        $eu = $this->portal->exigirColaborador()->id;
        $ciclo = CicloAvaliacao360::query()->where('ano', $f['ano'])->where('periodo', $f['periodo'])->first();
        $equipa = Colaborador::query()->where('estado', 'ACTIVO')->whereKeyNot($eu)->orderBy('nome_completo')->get()
            ->filter(fn ($c) => $this->avaliacao->chefiaNoPeriodo($c->id, $ciclo) === $eu)->values();
        $avaliacoes = AvaliacaoDesempenhoRH::query()->where('ano', $f['ano'])->where('periodo', $f['periodo'])->whereIn('colaborador_id', $equipa->pluck('id'))->get()->keyBy('colaborador_id');
        $feedbacks = $ciclo ? FeedbackAvaliacao360::query()->where('ciclo_avaliacao_id', $ciclo->id)->whereIn('colaborador_id', $equipa->pluck('id'))->orderBy('data')->get()->groupBy('colaborador_id') : collect();

        return RespostaApi::sucesso([
            'ciclo' => $ciclo?->only(['id', 'nome', 'ano', 'periodo', 'estado']),
            'membros' => $equipa->map(fn ($c) => [
                'colaborador_id' => $c->id, 'nome' => $c->nome_completo,
                'avaliacao' => ($a = $avaliacoes[$c->id] ?? null) ? $a->toArray() + ['fase' => ServicoAvaliacao360::fase($a, $ciclo), 'nota_final' => ServicoAvaliacao360::notaFinal($a)] : null,
                'criterios' => $this->avaliacao->itensDe($c->id, 'CRITERIO'), 'objetivos' => $this->avaliacao->itensDe($c->id, 'OBJECTIVO'),
                'feedbacks' => ($feedbacks[$c->id] ?? collect())->values(),
            ])->all(),
        ], 'A minha equipa.');
    }

    public function autoavaliacoes(Request $r): JsonResponse
    {
        $this->exigir('rh_portal_aprovar', 'rh_avaliacao_view', 'rh_portal_gestao_view');
        $f = $r->validate(['ano' => ['required', 'integer'], 'periodo' => ['required', Rule::in(ServicoAvaliacao::PERIODOS)]]);
        $lista = AutoavaliacaoColaborador::query()->where('ano', $f['ano'])->where('periodo', $f['periodo'])->get();
        $nomes = Colaborador::query()->withTrashed()->whereKey($lista->pluck('colaborador_id'))->pluck('nome_completo', 'id');

        return RespostaApi::sucesso($lista->map(fn ($a) => $a->toArray() + ['nome' => $nomes[$a->colaborador_id] ?? null])->sortBy('nome')->values()->all(), 'Autoavaliações.');
    }

    public function chefias(): JsonResponse
    {
        $this->exigir('rh_portal_aprovar');
        $colabs = Colaborador::query()->where('estado', 'ACTIVO')->get(['id', 'nome_completo']);
        $chefias = [];
        foreach ($colabs as $c) {
            $ch = $this->estrutura->chefiaDe($c->id);
            if ($ch) {
                $chefias[$ch] = ($chefias[$ch] ?? 0) + 1;
            }
        }
        $nomes = Colaborador::query()->withTrashed()->whereKey(array_keys($chefias))->pluck('nome_completo', 'id');

        return RespostaApi::sucesso(collect($chefias)->map(fn ($n, $id) => ['colaborador_id' => (int) $id, 'nome' => $nomes[$id] ?? "#{$id}", 'equipa' => $n])
            ->sortBy('nome')->values()->all(), 'Chefias com equipa.');
    }
}
