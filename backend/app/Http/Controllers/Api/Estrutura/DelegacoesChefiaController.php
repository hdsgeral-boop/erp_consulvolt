<?php

namespace App\Http\Controllers\Api\Estrutura;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\DelegacaoChefia;
use App\Services\RH\ServicoDelegacoesChefia;
use App\Services\RH\ServicoPortalColaborador;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Delegação da chefia (Fase K, ADR-079):
 *   - /api/rh/estrutura/delegacoes — lista (est_estrutura_view, est_delegar ou só as próprias), cria (o próprio ou, com est_delegar,
 *     em nome de outro) e revoga (o delegante ou est_delegar);
 *   - /api/rh/portal/delegacoes — do próprio, sem permissão adicional: as que dei e as que recebi, dar uma delegação, revogar.
 * As regras (período, âmbito, sem cadeia, sem sobreposição, delegado com utilizador) estão em ServicoDelegacoesChefia.
 */
final class DelegacoesChefiaController extends Controller
{
    public function __construct(
        private readonly ServicoDelegacoesChefia $delegacoes,
        private readonly ServicoPortalColaborador $portal,
    ) {}

    // ───────────── Estrutura ─────────────

    /** GET /rh/estrutura/delegacoes?estado&colaborador_id */
    public function index(Request $r): JsonResponse
    {
        $f = $r->validate(['estado' => ['nullable', Rule::in(DelegacaoChefia::ESTADOS)], 'colaborador_id' => ['nullable', 'integer']]);
        $todas = Gate::any(['est_estrutura_view', 'est_delegar']);

        return RespostaApi::sucesso($this->delegacoes->listar($f, $todas), 'Delegações da chefia.');
    }

    /** POST /rh/estrutura/delegacoes — `delegante_colaborador_id` omitido = o próprio; outro exige est_delegar. */
    public function store(Request $r): JsonResponse
    {
        // antes da validação (OWASP A01): sem colaborador ligado só quem tem est_delegar cria (em nome de outro)
        if (! Gate::allows('est_delegar') && $this->delegacoes->colaboradorDe() === null) {
            throw new ErroNegocio('O seu utilizador não está ligado a um colaborador desta empresa e não pode delegar em nome de outros.', 'SEM_PERMISSAO', 403);
        }
        $d = $this->validarCriar($r) + $r->validate(['delegante_colaborador_id' => ['nullable', 'integer']]);

        return RespostaApi::criado($this->delegacoes->uma($this->delegacoes->criar($d)), 'Delegação criada.');
    }

    public function revogar(int $delegacao): JsonResponse
    {
        return RespostaApi::sucesso($this->delegacoes->uma($this->delegacoes->revogar(DelegacaoChefia::query()->findOrFail($delegacao))), 'Delegação revogada.');
    }

    // ───────────── Portal (do próprio) ─────────────

    /** GET /rh/portal/delegacoes — {dadas, recebidas} */
    public function doPortal(): JsonResponse
    {
        $c = $this->portal->exigirColaborador();

        return RespostaApi::sucesso($this->delegacoes->doPortal($c->id), 'As minhas delegações.');
    }

    /** GET /rh/portal/delegacoes/candidatos — quem pode receber uma delegação (activos, com utilizador ligado, sem o próprio); só id e nome. */
    public function candidatos(): JsonResponse
    {
        $c = $this->portal->exigirColaborador();

        return RespostaApi::sucesso($this->delegacoes->candidatos($c->id), 'Possíveis delegados.');
    }

    /** POST /rh/portal/delegacoes — o delegante é sempre o próprio. */
    public function storePortal(Request $r): JsonResponse
    {
        $c = $this->portal->exigirColaborador();
        $d = $this->validarCriar($r) + ['delegante_colaborador_id' => $c->id];

        return RespostaApi::criado($this->delegacoes->uma($this->delegacoes->criar($d)), 'Delegação criada.');
    }

    public function revogarPortal(int $delegacao): JsonResponse
    {
        $c = $this->portal->exigirColaborador();
        $d = DelegacaoChefia::query()->findOrFail($delegacao);
        if ((int) $d->delegante_colaborador_id !== (int) $c->id) {
            throw new ErroNegocio('Só o delegante revoga a sua delegação a partir do portal.', 'SEM_PERMISSAO', 403);
        }

        return RespostaApi::sucesso($this->delegacoes->uma($this->delegacoes->revogar($d)), 'Delegação revogada.');
    }

    /** @return array{delegado_colaborador_id: int, data_inicio: string, data_fim: string, ambito?: ?array, motivo?: ?string} */
    private function validarCriar(Request $r): array
    {
        return $r->validate(['delegado_colaborador_id' => ['required', 'integer'], 'data_inicio' => ['required', 'date_format:Y-m-d'], 'data_fim' => ['required', 'date_format:Y-m-d'],
            'ambito' => ['nullable', 'array', 'max:3'], 'ambito.*' => ['string', Rule::in(DelegacaoChefia::AMBITOS)], 'motivo' => ['nullable', 'string', 'max:255']]);
    }
}
