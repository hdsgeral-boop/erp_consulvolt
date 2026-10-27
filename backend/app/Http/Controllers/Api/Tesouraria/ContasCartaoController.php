<?php

namespace App\Http\Controllers\Api\Tesouraria;

use App\Http\Controllers\Controller;
use App\Services\Tesouraria\ServicoContasCartao;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * /api/tesouraria/contas-cartao — contas-cartão de tesouraria (classe 3 marcada pelo utilizador no plano).
 * Só leitura: a gravação faz-se com a conta, em /api/contabilidade/plano-contas (contab_plano_gerir).
 * Serve o formulário do plano de contas e os ecrãs de Tesouraria (meios de pagamento, recebimentos/pagamentos).
 */
final class ContasCartaoController extends Controller
{
    /** Quem gere o plano, o consulta, ou emite/consulta pagamentos e recebimentos — igual a ContasBancariasController. */
    private const LEITURA = ['config_plano_view', 'contab_plano_gerir', 'teso_gestao_pagamentos_view', 'teso_doc_emitir'];

    public function __construct(private readonly ServicoContasCartao $contas) {}

    /** GET — contas-cartão de movimento com os dados do cartão. */
    public function index(): JsonResponse
    {
        $this->exigir(...self::LEITURA);
        $contas = $this->contas->listar();

        return RespostaApi::sucesso($contas, 'Contas-cartão obtidas com sucesso.', 200, ['total' => count($contas)]);
    }

    /** GET {codigo} — dados do cartão de uma conta marcada. */
    public function show(string $codigo): JsonResponse
    {
        $this->exigir(...self::LEITURA);

        return RespostaApi::sucesso($this->contas->dados($codigo), 'Dados do cartão obtidos com sucesso.');
    }

    /** GET sugestoes-bancos — nomes dos bancos registados, para o campo «Banco associado» do formulário do plano. */
    public function sugestoesBancos(): JsonResponse
    {
        $this->exigir('contab_plano_gerir');

        return RespostaApi::sucesso($this->contas->sugestoesBancos(), 'Bancos obtidos com sucesso.');
    }
}
