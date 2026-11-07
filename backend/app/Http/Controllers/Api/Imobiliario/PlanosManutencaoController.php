<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\PlanoManutencaoImo;
use App\Services\Imobiliario\CalculadoraObras;
use App\Services\Imobiliario\ServicoPlanosManutencaoImo;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Planos de manutenção e vistoria (ecrã imo_obras). Ver: imo_obras_view. Criar, editar e apagar: imo_plano_editar. Gerar as ordens: imo_plano_gerar
 * (recusadas no servidor, antes da validação).
 *  GET|POST /imobiliario/planos;  GET|PUT|DELETE /imobiliario/planos/{id};  GET /imobiliario/planos/{id}/calendario
 *  GET /imobiliario/planos/propor-datas?primeira=AAAA-MM-DD&periodicidade=&quantas=   datas propostas
 *  POST /imobiliario/planos/{id}/gerar (todas, ate);  POST /imobiliario/planos/gerar-todos
 */
final class PlanosManutencaoController extends Controller
{
    public function __construct(private readonly ServicoPlanosManutencaoImo $planos) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('imo_obras_view');
        $f = $r->validate(['imovel_id' => ['nullable', 'integer', 'min:1'], 'fracao_id' => ['nullable', 'integer', 'min:1'], 'tipo' => ['nullable', Rule::in(CalculadoraObras::TIPOS_PLANEAVEIS)], 'activo' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->planos->listar($f)), 'Planos de manutenção e vistoria.');
    }

    public function propor(Request $r): JsonResponse
    {
        $this->exigir('imo_obras_view');
        $d = $r->validate(['primeira' => ['required', 'date_format:Y-m-d'], 'periodicidade' => ['nullable', Rule::in(array_keys(CalculadoraObras::PERIODICIDADES))], 'quantas' => ['nullable', 'integer', 'min:1', 'max:60']],
            ['primeira.required' => 'Indique a primeira data.']);

        return RespostaApi::sucesso(CalculadoraObras::proporDatas($d['primeira'], $d['periodicidade'] ?? 'ANUAL', (int) ($d['quantas'] ?? 4)), 'Datas propostas.');
    }

    public function show(int $plano): JsonResponse
    {
        $this->exigir('imo_obras_view');

        return RespostaApi::sucesso(new ImobiliarioResource(PlanoManutencaoImo::query()->findOrFail($plano)), 'Plano.');
    }

    public function calendario(int $plano): JsonResponse
    {
        $this->exigir('imo_obras_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->planos->calendario(PlanoManutencaoImo::query()->findOrFail($plano))), 'Calendário do plano.');
    }

    /** @return array<string, mixed> */
    private function regras(): array
    {
        return ['nome' => ['nullable', 'string', 'max:120'], 'tipo' => ['nullable', 'string', 'max:20'], 'descricao' => ['nullable', 'string', 'max:5000'], 'imovel_id' => ['nullable', 'integer', 'min:1'],
            'fracao_id' => ['nullable', 'integer', 'min:1'], 'periodicidade' => ['nullable', 'string', 'max:20'], 'primeira_data' => ['nullable', 'date_format:Y-m-d'], 'quantas' => ['nullable', 'integer', 'min:1', 'max:60'],
            'datas' => ['nullable', 'array', 'max:80'], 'datas.*' => ['string', 'max:10'], 'prioridade' => ['nullable', 'string', 'max:8'], 'quem_paga' => ['nullable', 'string', 'max:10'], 'debitavel' => ['nullable', 'boolean'],
            'responsavel' => ['nullable', 'string', 'max:120'], 'antecedencia_dias' => ['nullable', 'integer', 'min:0', 'max:3650'], 'custo_estimado' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'notas' => ['nullable', 'string', 'max:5000'], 'activo' => ['nullable', 'boolean']];
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('imo_plano_editar');

        return RespostaApi::criado(new ImobiliarioResource($this->planos->gravar($r->validate($this->regras()))), 'Plano gravado.');
    }

    public function update(Request $r, int $plano): JsonResponse
    {
        $this->exigir('imo_plano_editar');

        return RespostaApi::sucesso(new ImobiliarioResource($this->planos->gravar($r->validate($this->regras()), PlanoManutencaoImo::query()->findOrFail($plano))), 'Plano gravado.');
    }

    public function destroy(int $plano): JsonResponse
    {
        $this->exigir('imo_plano_editar');
        $x = $this->planos->eliminar(PlanoManutencaoImo::query()->findOrFail($plano));

        return RespostaApi::sucesso(new ImobiliarioResource($x), 'Plano eliminado.'.($x['ordens_historicas'] > 0 ? " As {$x['ordens_historicas']} ordem(ns) que gerou ficam no histórico." : ''));
    }

    public function gerar(Request $r, int $plano): JsonResponse
    {
        $this->exigir('imo_plano_gerar');
        $d = $r->validate(['todas' => ['nullable', 'boolean'], 'ate' => ['nullable', 'date_format:Y-m-d']]);
        $x = $this->planos->gerarOrdens(PlanoManutencaoImo::query()->findOrFail($plano), (bool) ($d['todas'] ?? false), $d['ate'] ?? null);
        $n = count($x['criadas']);

        return RespostaApi::sucesso(new ImobiliarioResource($x), $n > 0 ? "{$n} ordem(ns) criada(s)." : 'Não havia nada por gerar dentro do horizonte.');
    }

    public function gerarTodos(Request $r): JsonResponse
    {
        $this->exigir('imo_plano_gerar');
        $d = $r->validate(['todas' => ['nullable', 'boolean']]);
        $x = $this->planos->gerarTodos((bool) ($d['todas'] ?? false));

        return RespostaApi::sucesso(new ImobiliarioResource($x), $x['criadas'] > 0 ? "{$x['criadas']} ordem(ns) criada(s) a partir de {$x['planos']} plano(s)." : 'Não havia nada por gerar.');
    }
}
