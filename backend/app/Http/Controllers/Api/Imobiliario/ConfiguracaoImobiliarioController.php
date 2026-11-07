<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\Produto;
use App\Services\Imobiliario\ServicoConfiguracaoImobiliario;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuração do módulo imobiliário por empresa: artigo de serviço «Renda» e contas da caução e do Imposto Predial retido. Ver:
 * imo_modelos_view ou imo_contratos_view; alterar: imo_taxas_editar (sensível, como as taxas). O ERP não pré-preenche nada.
 *  GET /imobiliario/configuracao   GET /imobiliario/configuracao/artigos   PUT /imobiliario/configuracao
 */
final class ConfiguracaoImobiliarioController extends Controller
{
    public function __construct(private readonly ServicoConfiguracaoImobiliario $config) {}

    public function show(): JsonResponse
    {
        $this->exigir('imo_modelos_view', 'imo_contratos_view', 'imo_rendas_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->config->obter()), 'Configuração do módulo imobiliário.');
    }

    public function artigos(): JsonResponse
    {
        $this->exigir('imo_modelos_view', 'imo_taxas_editar');
        $artigos = Produto::query()->where(fn ($q) => $q->where('bloqueado', false)->orWhereNull('bloqueado'))->orderBy('codigo')->limit(500)->get(['id', 'codigo', 'nome', 'taxa_imposto'])
            ->map(fn ($p) => ['id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'taxa_imposto' => (float) $p->taxa_imposto])->all();

        return RespostaApi::sucesso(ImobiliarioResource::collection($artigos), 'Artigos disponíveis para a renda.');
    }

    public function update(Request $r): JsonResponse
    {
        $this->exigir('imo_taxas_editar');
        $d = $r->validate(['produto_renda_id' => ['nullable', 'integer', 'min:1'], 'produto_renda_alojamento_id' => ['nullable', 'integer', 'min:1'],
            'conta_caucao_passivo' => ['nullable', 'string', 'max:20'], 'conta_caucao_activo' => ['nullable', 'string', 'max:20'], 'conta_caucao_receita' => ['nullable', 'string', 'max:20'],
            'conta_ip_credito' => ['nullable', 'string', 'max:20']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->config->gravar($d)), 'Configuração gravada.');
    }
}
