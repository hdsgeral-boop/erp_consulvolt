<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imobiliario\GravarImovelRequest;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\AtivoImobilizado;
use App\Models\Imovel;
use App\Models\Terceiro;
use App\Services\Imobiliario\CatalogoImobiliario;
use App\Services\Imobiliario\ServicoImoveis;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cadastro de imóveis (Onda 1, ecrã imo_imoveis). Ver: imo_imoveis_view (ou imo_ocupacao_view nas leituras partilhadas com o mapa).
 * Escrever: imo_imovel_editar. Mensagens de erro próprias (ErroNegocio); nunca texto técnico.
 *  GET    /imobiliario/listas            enumerações do cadastro (rótulos, transições, províncias)
 *  GET    /imobiliario/resumo            KPIs globais (imóveis, fracções, ocupação, por estado e papel)
 *  GET    /imobiliario/edificios         edifícios do Activos ligáveis a um imóvel
 *  GET    /imobiliario/terceiros         proprietários possíveis (id, nome, NIF)
 *  GET|POST /imobiliario/imoveis, GET|PUT|DELETE /imobiliario/imoveis/{id}
 */
final class ImoveisController extends Controller
{
    public function __construct(private readonly ServicoImoveis $imoveis) {}

    public function listas(): JsonResponse
    {
        $this->exigir('imo_imoveis_view', 'imo_ocupacao_view', 'imo_modelos_view');

        return RespostaApi::sucesso(new ImobiliarioResource(CatalogoImobiliario::listas()), 'Listas do cadastro imobiliário.');
    }

    public function resumo(): JsonResponse
    {
        $this->exigir('imo_imoveis_view', 'imo_ocupacao_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->imoveis->resumo()), 'Resumo do património imobiliário.');
    }

    public function index(Request $r): JsonResponse
    {
        $this->exigir('imo_imoveis_view', 'imo_ocupacao_view');
        $f = $r->validate(['texto' => ['nullable', 'string', 'max:100'], 'papel' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::PAPEIS))],
            'estado' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::ESTADOS_IMOVEL))], 'tipo' => ['nullable', Rule::in(array_keys(CatalogoImobiliario::TIPOS_IMOVEL))]]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->imoveis->listar($f)), 'Imóveis.');
    }

    public function show(int $imovel): JsonResponse
    {
        $this->exigir('imo_imoveis_view', 'imo_ocupacao_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->imoveis->ficha(Imovel::query()->findOrFail($imovel))), 'Ficha do imóvel.');
    }

    public function store(GravarImovelRequest $r): JsonResponse
    {
        $i = $this->imoveis->criar($r->validated());

        return RespostaApi::criado(new ImobiliarioResource($this->imoveis->ficha($i)), "Imóvel {$i->codigo} registado.");
    }

    public function update(GravarImovelRequest $r, int $imovel): JsonResponse
    {
        $i = $this->imoveis->actualizar(Imovel::query()->findOrFail($imovel), $r->validated());

        return RespostaApi::sucesso(new ImobiliarioResource($this->imoveis->ficha($i)), 'Imóvel actualizado.');
    }

    public function destroy(int $imovel): JsonResponse
    {
        $this->exigir('imo_imovel_editar');
        $this->imoveis->eliminar(Imovel::query()->findOrFail($imovel));

        return RespostaApi::sucesso(null, 'Imóvel eliminado.');
    }

    public function edificios(): JsonResponse
    {
        $this->exigir('imo_imoveis_view');
        $ligados = Imovel::query()->whereNotNull('ativo_edificio_id')->pluck('codigo', 'ativo_edificio_id');
        $lista = AtivoImobilizado::query()->where('tipo_ativo', 'EDIFICIO')->orderBy('codigo')->limit(500)->get(['id', 'codigo', 'descricao', 'propriedade', 'regime_alheio'])
            ->map(fn ($a) => ['id' => $a->id, 'codigo' => $a->codigo, 'descricao' => $a->descricao, 'propriedade' => $a->propriedade ?? 'PROPRIO', 'regime_alheio' => $a->regime_alheio,
                'ligado_ao_imovel' => $ligados[$a->id] ?? null])->all();

        return RespostaApi::sucesso(ImobiliarioResource::collection($lista), 'Edifícios do imobilizado.');
    }

    public function terceiros(Request $r): JsonResponse
    {
        $this->exigir('imo_imoveis_view');
        $f = $r->validate(['texto' => ['nullable', 'string', 'max:100']]);
        $lista = Terceiro::query()->when($f['texto'] ?? null, fn ($q, $t) => $q->where(fn ($x) => $x->where('nome', 'ilike', "%{$t}%")->orWhere('nif', 'ilike', "%{$t}%")))
            ->orderBy('nome')->limit(200)->get(['id', 'nome', 'nif'])->map(fn ($t) => ['id' => $t->id, 'nome' => $t->nome, 'nif' => $t->nif])->all();

        return RespostaApi::sucesso(ImobiliarioResource::collection($lista), 'Terceiros.');
    }
}
