<?php

namespace App\Http\Controllers\Api\Integracoes;

use App\Http\Controllers\Controller;
use App\Services\Integracoes\PowerBI\ServicoFeedBI;
use App\Services\Integracoes\PowerBI\ServicoTokensBI;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/sistema/bi — gestão dos tokens de leitura do feed OData do Power BI da empresa activa (decisão 25).
 * Permissão: config_backup (quem já pode exportar os dados da empresa).
 */
final class TokensBIController extends Controller
{
    public function __construct(private readonly ServicoTokensBI $tokens) {}

    /** GET — tokens da empresa activa, conjuntos disponíveis e o endereço do feed. */
    public function index(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('config_backup');

        return RespostaApi::sucesso([
            'tokens' => $this->tokens->listar($contexto->obrigatorio()),
            'conjuntos' => array_values(array_map(fn ($id, $d) => ['id' => $id, 'nome' => $d['nome'], 'propriedades' => count(ServicoFeedBI::propriedades($d))],
                array_keys(ServicoFeedBI::conjuntos()), ServicoFeedBI::conjuntos())),
            'endereco' => url('/api/bi/odata'),
            'tamanho_pagina' => ServicoFeedBI::TAMANHO_PAGINA,
        ], 'Tokens do Power BI.');
    }

    /** POST {nome, conjuntos?[], expira_em?} — cria um token (o valor só é devolvido agora). */
    public function store(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('config_backup');
        $d = $r->validate(['nome' => ['required', 'string', 'min:3', 'max:255'], 'conjuntos' => ['nullable', 'array', 'max:20'], 'conjuntos.*' => ['string', 'max:40'],
            'expira_em' => ['nullable', 'date']]);
        $res = $this->tokens->criar($contexto->obrigatorio(), $d['nome'], $d['conjuntos'] ?? null, $d['expira_em'] ?? null);

        return RespostaApi::criado($res, 'Token criado. Copie-o agora: por segurança não volta a ser mostrado.');
    }

    /** POST {id}/revogar — revoga o token (deixa de funcionar de imediato). */
    public function revogar(int $token, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('config_backup');

        return RespostaApi::sucesso($this->tokens->revogar($contexto->obrigatorio(), $token), 'Token revogado.');
    }
}
