<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\BemFracao;
use App\Models\FichaApetrechamento;
use App\Models\Fracao;
use App\Services\Imobiliario\CalculadoraObras;
use App\Services\Imobiliario\ServicoApetrechamento;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fichas de apetrechamento (ecrã imo_fichas) e bens da fracção. Ver: imo_fichas_view (bens e comparação também com imo_obras_view). Criar, editar e
 * apagar fichas: imo_ficha_editar. Registar, editar, apagar e trazer bens: imo_bens_editar (recusadas no servidor, antes da validação).
 *  GET|POST /imobiliario/fichas;  GET|PUT|DELETE /imobiliario/fichas/{id}
 *  GET /imobiliario/fracoes/{id}/bens;  POST …/bens;  PUT|DELETE …/bens/{bem};  POST …/bens/trazer-imobilizado
 *  GET /imobiliario/fracoes/{id}/comparar-ficha?ficha_id=
 */
final class ApetrechamentoController extends Controller
{
    public function __construct(private readonly ServicoApetrechamento $apet) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('imo_fichas_view', 'imo_obras_view');
        $d = $r->validate(['activo' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->apet->fichas(isset($d['activo']) ? (bool) $d['activo'] : null)), 'Fichas de apetrechamento.');
    }

    public function show(int $ficha): JsonResponse
    {
        $this->exigir('imo_fichas_view', 'imo_obras_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->apet->ficha(FichaApetrechamento::query()->findOrFail($ficha))), 'Ficha de apetrechamento.');
    }

    /** @return array<string, mixed> */
    private function regrasFicha(): array
    {
        return ['nome' => ['nullable', 'string', 'max:120'], 'tipologia' => ['nullable', 'string', 'max:20'], 'finalidade' => ['nullable', 'string', 'max:20'], 'activo' => ['nullable', 'boolean'],
            'notas' => ['nullable', 'string', 'max:5000'], 'itens' => ['nullable', 'array', 'max:200'], 'itens.*.produto_id' => ['nullable', 'integer', 'min:1'], 'itens.*.descricao' => ['nullable', 'string', 'max:200'],
            'itens.*.quantidade' => ['nullable', 'numeric', 'min:1', 'max:100000'], 'itens.*.obrigatorio' => ['nullable', 'boolean'], 'itens.*.notas' => ['nullable', 'string', 'max:300']];
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('imo_ficha_editar');
        $f = $this->apet->gravarFicha($r->validate($this->regrasFicha()));

        return RespostaApi::criado(new ImobiliarioResource($this->apet->ficha($f)), 'Ficha gravada.');
    }

    public function update(Request $r, int $ficha): JsonResponse
    {
        $this->exigir('imo_ficha_editar');
        $f = $this->apet->gravarFicha($r->validate($this->regrasFicha()), FichaApetrechamento::query()->findOrFail($ficha));

        return RespostaApi::sucesso(new ImobiliarioResource($this->apet->ficha($f)), 'Ficha gravada.');
    }

    public function destroy(int $ficha): JsonResponse
    {
        $this->exigir('imo_ficha_editar');
        $this->apet->apagarFicha(FichaApetrechamento::query()->findOrFail($ficha));

        return RespostaApi::sucesso(null, 'Ficha apagada.');
    }

    public function bens(int $fracao): JsonResponse
    {
        $this->exigir('imo_fichas_view', 'imo_obras_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->apet->bens(Fracao::query()->findOrFail($fracao))), 'Bens da fracção.');
    }

    /** @return array<string, mixed> */
    private function regrasBem(): array
    {
        return ['produto_id' => ['nullable', 'integer', 'min:1'], 'descricao' => ['nullable', 'string', 'max:200'], 'quantidade' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'estado' => ['nullable', Rule::in(array_keys(CalculadoraObras::ESTADOS_BEM))], 'data' => ['nullable', 'date_format:Y-m-d'], 'notas' => ['nullable', 'string', 'max:300']];
    }

    public function gravarBem(Request $r, int $fracao): JsonResponse
    {
        $this->exigir('imo_bens_editar');
        $b = $this->apet->gravarBem(Fracao::query()->findOrFail($fracao), $r->validate($this->regrasBem()));

        return RespostaApi::criado(new ImobiliarioResource($b), 'Bem gravado.');
    }

    public function actualizarBem(Request $r, int $fracao, int $bem): JsonResponse
    {
        $this->exigir('imo_bens_editar');
        $f = Fracao::query()->findOrFail($fracao);
        $b = $this->apet->gravarBem($f, $r->validate($this->regrasBem()), BemFracao::query()->where('fracao_id', $f->id)->findOrFail($bem));

        return RespostaApi::sucesso(new ImobiliarioResource($b), 'Bem gravado.');
    }

    public function apagarBem(int $fracao, int $bem): JsonResponse
    {
        $this->exigir('imo_bens_editar');
        $f = Fracao::query()->findOrFail($fracao);
        $this->apet->apagarBem($f, BemFracao::query()->where('fracao_id', $f->id)->findOrFail($bem));

        return RespostaApi::sucesso(null, 'Bem apagado.');
    }

    public function trazer(int $fracao): JsonResponse
    {
        $this->exigir('imo_bens_editar');
        $x = $this->apet->trazerDoImobilizado(Fracao::query()->findOrFail($fracao));

        return RespostaApi::sucesso(new ImobiliarioResource($x), $x['criados'] > 0 ? "{$x['criados']} bem(ns) trazidos do imobilizado." : "Nenhum bem novo ({$x['encontrados']} já estavam).");
    }

    public function comparar(Request $r, int $fracao): JsonResponse
    {
        $this->exigir('imo_fichas_view', 'imo_obras_view');
        $d = $r->validate(['ficha_id' => ['required', 'integer', 'min:1']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->apet->comparar(Fracao::query()->findOrFail($fracao), FichaApetrechamento::query()->findOrFail($d['ficha_id']))), 'Comparação com a ficha.');
    }
}
