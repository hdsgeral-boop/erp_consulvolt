<?php

namespace App\Http\Controllers\Api\Estrategia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Estrategia\GravarPlanoRequest;
use App\Http\Requests\Estrategia\TransicaoPlanoRequest;
use App\Http\Resources\Estrategia\EstrategiaResource;
use App\Http\Resources\Estrategia\ObjectivoEstrategicoResource;
use App\Http\Resources\Estrategia\PerspectivaEstrategicaResource;
use App\Http\Resources\Estrategia\PlanoEstrategicoResource;
use App\Services\Estrategia\ServicoObjectivosEstrategicos;
use App\Services\Estrategia\ServicoPlanosEstrategicos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * Planeamento Estratégico — planos (ecrã est_plano). Ver: est_plano_view. Editar: est_plano_editar. Aprovar, iniciar a
 * execução, reabrir e encerrar: est_plano_aprovar (tarefa sensível). As regras (estados, congelamento, segregação) vivem no serviço.
 *  GET    estrategia/planos                      lista
 *  POST   estrategia/planos                      novo plano (nasce com as 4 perspectivas do Balanced Scorecard)
 *  GET    estrategia/planos/{plano}              plano + perspectivas + objectivos
 *  PUT    estrategia/planos/{plano}              editar (plano aprovado: motivo + est_plano_aprovar)
 *  DELETE estrategia/planos/{plano}              apagar rascunho sem dados
 *  POST   estrategia/planos/{plano}/aprovar|iniciar-execucao|reabrir|encerrar
 */
final class PlanosEstrategicosController extends Controller
{
    public function __construct(private readonly ServicoPlanosEstrategicos $planos, private readonly ServicoObjectivosEstrategicos $estrutura) {}

    public function index(): JsonResponse
    {
        $this->exigir('est_plano_view');

        return RespostaApi::sucesso(new EstrategiaResource(['planos' => $this->planos->listar()]), 'Planos estratégicos.');
    }

    public function show(int $plano): JsonResponse
    {
        $this->exigir('est_plano_view');
        $e = $this->estrutura->estrutura($plano);

        return RespostaApi::sucesso(new EstrategiaResource([
            'plano' => (new PlanoEstrategicoResource($e['plano']))->resolve(),
            'perspectivas' => $e['perspectivas']->map(fn ($p) => (new PerspectivaEstrategicaResource($p))->resolve())->all(),
            'objectivos' => $e['objectivos']->map(fn ($o) => (new ObjectivoEstrategicoResource($o))->resolve())->all(),
            'indicadores_por_objectivo' => $e['indicadores_por_objectivo'],
        ]), 'Plano estratégico.');
    }

    public function store(GravarPlanoRequest $r): JsonResponse
    {
        $p = $this->planos->criar($r->validated());

        return RespostaApi::criado(new PlanoEstrategicoResource($p), 'Plano criado com as quatro perspectivas do Balanced Scorecard.');
    }

    public function update(GravarPlanoRequest $r, int $plano): JsonResponse
    {
        $d = $r->validated();
        $motivo = $d['motivo_excepcao'] ?? null;
        unset($d['motivo_excepcao']);

        return RespostaApi::sucesso(new PlanoEstrategicoResource($this->planos->actualizar($plano, $d, $motivo)), 'Plano gravado.');
    }

    public function destroy(int $plano): JsonResponse
    {
        $this->exigir('est_plano_editar');
        $this->planos->apagar($plano);

        return RespostaApi::sucesso(null, 'Plano apagado.');
    }

    public function aprovar(TransicaoPlanoRequest $r, int $plano): JsonResponse
    {
        return RespostaApi::sucesso(new PlanoEstrategicoResource($this->planos->aprovar($plano, $r->validated('excepcao'))), 'Plano aprovado. A estrutura fica congelada.');
    }

    public function iniciarExecucao(TransicaoPlanoRequest $r, int $plano): JsonResponse
    {
        return RespostaApi::sucesso(new PlanoEstrategicoResource($this->planos->iniciarExecucao($plano)), 'Plano em execução.');
    }

    public function reabrir(TransicaoPlanoRequest $r, int $plano): JsonResponse
    {
        return RespostaApi::sucesso(new PlanoEstrategicoResource($this->planos->reabrir($plano, $r->validated('motivo'))), 'Plano reaberto para elaboração.');
    }

    public function encerrar(TransicaoPlanoRequest $r, int $plano): JsonResponse
    {
        return RespostaApi::sucesso(new PlanoEstrategicoResource($this->planos->encerrar($plano)), 'Plano encerrado.');
    }
}
