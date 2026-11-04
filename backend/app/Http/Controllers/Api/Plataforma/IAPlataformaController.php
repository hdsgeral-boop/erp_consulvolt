<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Services\IA\Agente\ServicoConfiguracaoIA;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/plataforma/ia/* — IA da plataforma (middleware `plataforma`): chaves dos fornecedores (nunca devolvidas),
 * atribuição a cada cliente (fornecedor, modelo, quota mensal, pesquisa na internet, acções) e consumo.
 */
final class IAPlataformaController extends Controller
{
    public function __construct(private readonly ServicoConfiguracaoIA $ia, private readonly ServicoAuditoria $auditoria) {}

    public function fornecedores(): JsonResponse
    {
        $this->exigir('plataforma_ia_gerir');

        return RespostaApi::sucesso($this->ia->fornecedores(), 'Fornecedores de IA.');
    }

    public function gravarFornecedor(Request $r, string $fornecedor): JsonResponse
    {
        $this->exigir('plataforma_ia_gerir');
        $d = $r->validate([
            'chave' => ['nullable', 'string', 'max:500'],
            'nome' => ['sometimes', 'string', 'max:80'],
            'modelos' => ['sometimes', 'array', 'max:30'], 'modelos.*' => ['string', 'max:80'],
            'modelo_padrao' => ['sometimes', 'nullable', 'string', 'max:80'],
            'preco_entrada_mtok' => ['sometimes', 'numeric', 'min:0', 'max:10000'],
            'preco_saida_mtok' => ['sometimes', 'numeric', 'min:0', 'max:10000'],
            'ativo' => ['sometimes', 'boolean'],
        ]);
        $this->ia->gravarFornecedor($fornecedor, $d);
        $this->auditoria->registar('Plataforma', 'Configurar IA', "Fornecedor de IA «{$fornecedor}» actualizado".(! empty($d['chave']) ? ' (chave alterada).' : '.'), 'ia_fornecedores', null);

        return RespostaApi::sucesso($this->ia->fornecedores(), 'Fornecedor gravado.');
    }

    public function testar(Request $r, string $fornecedor): JsonResponse
    {
        $this->exigir('plataforma_ia_gerir');
        $d = $r->validate(['modelo' => ['nullable', 'string', 'max:80']]);
        $this->ia->testar($fornecedor, $d['modelo'] ?? null);

        return RespostaApi::sucesso(null, 'Ligação ao fornecedor verificada.');
    }

    public function modelos(Request $r, string $fornecedor): JsonResponse
    {
        $this->exigir('plataforma_ia_gerir');
        $d = $r->validate(['chave' => ['nullable', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->ia->modelosDisponiveis($fornecedor, $d['chave'] ?? null), 'Modelos disponíveis obtidos.');
    }

    public function atribuicoes(): JsonResponse
    {
        $this->exigir('plataforma_ia_gerir');

        return RespostaApi::sucesso($this->ia->atribuicoes(), 'Atribuições de IA.');
    }

    public function atribuir(Request $r, int $cliente): JsonResponse
    {
        $this->exigir('plataforma_ia_gerir');
        $d = $r->validate([
            'fornecedor' => ['required', Rule::in(array_keys(ServicoConfiguracaoIA::FORNECEDORES))],
            'modelo' => ['nullable', 'string', 'max:80'],
            'ativo' => ['sometimes', 'boolean'],
            'quota_tokens_mes' => ['nullable', 'integer', 'min:1000'],
            'pesquisa_web' => ['sometimes', 'boolean'],
            'accoes_erp' => ['sometimes', 'boolean'],
        ]);
        $this->ia->atribuir($cliente, $d);
        $this->auditoria->registar('Plataforma', 'Atribuir IA', "IA do cliente #{$cliente}: {$d['fornecedor']} ".($d['modelo'] ?? '').'.', 'ia_atribuicoes', $cliente);

        return RespostaApi::sucesso(null, 'IA atribuída ao cliente.');
    }

    public function removerAtribuicao(int $cliente): JsonResponse
    {
        $this->exigir('plataforma_ia_gerir');
        $this->ia->removerAtribuicao($cliente);
        $this->auditoria->registar('Plataforma', 'Retirar IA', "IA retirada ao cliente #{$cliente}.", 'ia_atribuicoes', $cliente);

        return RespostaApi::sucesso(null, 'IA retirada ao cliente.');
    }

    public function consumo(): JsonResponse
    {
        $this->exigir('plataforma_ia_gerir');

        return RespostaApi::sucesso($this->ia->consumo(), 'Consumo de IA.');
    }
}
