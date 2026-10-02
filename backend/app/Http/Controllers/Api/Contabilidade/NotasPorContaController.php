<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoNotasPorConta;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/contabilidade/tabelas/notas-demonstracao/sincronizar-por-conta — «Sincronizar notas automática»
 * (recoverDataMapping, js/app_v2.js:9208-9250): atribui a nota às demonstrações pelo prefixo da conta às linhas sem nota.
 * Ferramenta de reparação: permissão config_ferramentas (permissoes.js:443). Simulação por omissão; aplicar = true grava.
 */
final class NotasPorContaController extends Controller
{
    public function __construct(private readonly ServicoNotasPorConta $notas) {}

    public function sincronizar(Request $r): JsonResponse
    {
        $this->exigir('config_ferramentas');
        $d = $r->validate(['aplicar' => ['nullable', 'boolean'], 'data_inicio' => ['nullable', 'date_format:Y-m-d'],
            'data_fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_inicio']]);
        $res = $this->notas->sincronizar($d);

        return RespostaApi::sucesso($res, $res['aplicado'] ? "Notas atribuídas a {$res['linhas_a_atribuir']} linha(s)."
            : "Simulação: {$res['linhas_a_atribuir']} linha(s) sem nota receberiam nota pela conta. Nada foi gravado.");
    }
}
