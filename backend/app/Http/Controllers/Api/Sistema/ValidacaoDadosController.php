<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoValidacoesDados;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/** /api/sistema/validacoes — Validações de Dados (ADR-015), no ecrã "Manutenção de dados" do legado. */
final class ValidacaoDadosController extends Controller
{
    public function __construct(private readonly ServicoValidacoesDados $validacoes) {}

    public function index(): JsonResponse
    {
        $this->exigir('config_manutencao_view');

        return RespostaApi::sucesso($this->validacoes->resumo(), 'Validações executadas com sucesso.');
    }

    public function show(string $codigo): JsonResponse
    {
        $this->exigir('config_manutencao_view');

        return RespostaApi::sucesso($this->validacoes->detalhe($codigo), 'Detalhe da validação obtido com sucesso.');
    }
}
