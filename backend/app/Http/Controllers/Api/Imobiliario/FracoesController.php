<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imobiliario\GerarFracoesRequest;
use App\Http\Requests\Imobiliario\GravarFracaoRequest;
use App\Http\Requests\Imobiliario\MudarEstadoFracaoRequest;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Services\Imobiliario\ServicoFracoes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * Fracções e mapa de ocupação (Onda 1, ecrãs imo_imoveis e imo_ocupacao). Ver: imo_imoveis_view ou imo_ocupacao_view. Escrever:
 * imo_fraccao_editar; mudar o estado: imo_estado_mudar (deixa trilho e obedece às transições do catálogo).
 *  GET  /imobiliario/imoveis/{id}/fracoes              fracções do imóvel
 *  POST /imobiliario/imoveis/{id}/fracoes              nova fracção
 *  POST /imobiliario/imoveis/{id}/fracoes/gerar        gerar por piso, bloco e posição
 *  POST /imobiliario/imoveis/{id}/fracoes/unica        fracção «Única»
 *  POST /imobiliario/imoveis/{id}/fracoes/importar-portas   portas do edifício do Activos (idempotente)
 *  GET|PUT|DELETE /imobiliario/fracoes/{id}            ficha, gravar, eliminar;  POST /imobiliario/fracoes/{id}/estado
 */
final class FracoesController extends Controller
{
    public function __construct(private readonly ServicoFracoes $fracoes) {}

    public function index(int $imovel): JsonResponse
    {
        $this->exigir('imo_imoveis_view', 'imo_ocupacao_view');

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->fracoes->listar(Imovel::query()->findOrFail($imovel))), 'Fracções do imóvel.');
    }

    public function show(int $fracao): JsonResponse
    {
        $this->exigir('imo_imoveis_view', 'imo_ocupacao_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->fracoes->ficha(Fracao::query()->findOrFail($fracao))), 'Ficha da fracção.');
    }

    public function store(GravarFracaoRequest $r, int $imovel): JsonResponse
    {
        $f = $this->fracoes->criar(Imovel::query()->findOrFail($imovel), $r->validated());

        return RespostaApi::criado(new ImobiliarioResource($this->fracoes->ficha($f)), "Fracção {$f->designacao} registada.");
    }

    public function update(GravarFracaoRequest $r, int $fracao): JsonResponse
    {
        $f = $this->fracoes->actualizar(Fracao::query()->findOrFail($fracao), $r->validated());

        return RespostaApi::sucesso(new ImobiliarioResource($this->fracoes->ficha($f)), 'Fracção actualizada.');
    }

    public function destroy(int $fracao): JsonResponse
    {
        $this->exigir('imo_fraccao_editar');
        $this->fracoes->eliminar(Fracao::query()->findOrFail($fracao));

        return RespostaApi::sucesso(null, 'Fracção eliminada.');
    }

    public function estado(MudarEstadoFracaoRequest $r, int $fracao): JsonResponse
    {
        $f = $this->fracoes->mudarEstado(Fracao::query()->findOrFail($fracao), (string) $r->validated('estado'), $r->validated('motivo'));

        return RespostaApi::sucesso(new ImobiliarioResource($this->fracoes->ficha($f)), 'Estado da fracção actualizado.');
    }

    public function gerar(GerarFracoesRequest $r, int $imovel): JsonResponse
    {
        $res = $this->fracoes->gerarPorPiso(Imovel::query()->findOrFail($imovel), $r->validated());

        return RespostaApi::sucesso(new ImobiliarioResource($res), $res['criadas'].' fracção(ões) criada(s)'.($res['repetidas'] > 0 ? ', '.$res['repetidas'].' repetida(s) ignorada(s).' : '.'));
    }

    public function unica(int $imovel): JsonResponse
    {
        $this->exigir('imo_fraccao_editar');
        $f = $this->fracoes->criarUnica(Imovel::query()->findOrFail($imovel));

        return RespostaApi::criado(new ImobiliarioResource($this->fracoes->ficha($f)), 'Fracção «Única» criada.');
    }

    public function importarPortas(int $imovel): JsonResponse
    {
        $this->exigir('imo_fraccao_editar');
        $res = $this->fracoes->importarPortas(Imovel::query()->findOrFail($imovel));

        return RespostaApi::sucesso(new ImobiliarioResource($res), $res['criadas'].' fracção(ões) importada(s) de '.$res['total'].' porta(s) do edifício.');
    }
}
