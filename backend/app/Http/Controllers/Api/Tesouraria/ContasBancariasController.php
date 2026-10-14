<?php

namespace App\Http\Controllers\Api\Tesouraria;

use App\Http\Controllers\Controller;
use App\Services\Tesouraria\ServicoContasBancarias;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * /api/tesouraria/contas-bancarias — dados bancários das contas 42/43 do plano (Fase A, legado novo app_v2.js:9050-9163).
 * Só leitura: a gravação faz-se com a conta, em /api/contabilidade/plano-contas (contab_plano_gerir).
 * Serve o formulário do plano de contas e as cartas de pagamento (Fase D).
 */
final class ContasBancariasController extends Controller
{
    /** Quem gere o plano, o consulta, ou emite/consulta pagamentos e recebimentos. */
    private const LEITURA = ['config_plano_view', 'contab_plano_gerir', 'teso_gestao_pagamentos_view', 'teso_doc_emitir'];

    public function __construct(private readonly ServicoContasBancarias $contas) {}

    /** GET — contas 42/43 de movimento com os dados bancários (completa = tem IBAN, pode emitir cartas). */
    public function index(): JsonResponse
    {
        $this->exigir(...self::LEITURA);
        $contas = $this->contas->listar();

        return RespostaApi::sucesso($contas, 'Contas bancárias obtidas com sucesso.', 200, ['total' => count($contas)]);
    }

    /** GET {codigo} — dados bancários de uma conta 42/43 de movimento. */
    public function show(string $codigo): JsonResponse
    {
        $this->exigir(...self::LEITURA);

        return RespostaApi::sucesso($this->contas->dados($codigo), 'Dados bancários obtidos com sucesso.');
    }

    /** GET sugestoes-bancos — nomes dos bancos registados, para o campo «Banco» do formulário do plano. */
    public function sugestoesBancos(): JsonResponse
    {
        $this->exigir('contab_plano_gerir');

        return RespostaApi::sucesso($this->contas->sugestoesBancos(), 'Bancos obtidos com sucesso.');
    }
}
