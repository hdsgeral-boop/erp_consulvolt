<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoSubstituicaoConta;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/sistema/plano-contas/substituir — Configurações › Plano de contas › "Substituir conta" (contab_conta_substituir). */
final class SubstituicaoContaController extends Controller
{
    public function __construct(private readonly ServicoSubstituicaoConta $substituicao) {}

    /** POST .../simular — onde a conta é usada, por empresa (alteráveis e informativos). */
    public function simular(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('contab_conta_substituir');
        $d = $this->validar($r);

        return RespostaApi::sucesso($this->substituicao->simular($d['origem'], $d['destino'], $d['empresas'] ?? [$contexto->obrigatorio()], $r->user()),
            'Utilização da conta de origem.');
    }

    /** POST ... — substitui (exige "confirmar": true). */
    public function executar(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('contab_conta_substituir');
        $d = $this->validar($r) + $r->validate(['confirmar' => ['required', 'boolean']]);
        if (! $d['confirmar']) {
            throw new ErroNegocio('Confirme a substituição.', 'CONFIRMACAO_EM_FALTA', 422);
        }
        $res = $this->substituicao->executar($d['origem'], $d['destino'], $d['empresas'] ?? [$contexto->obrigatorio()], $r->user());

        return RespostaApi::sucesso($res, 'Substituição concluída: '.array_sum(array_column($res, 'total')).' registo(s) alterado(s). Lançamentos e documentos contabilizados não foram alterados.');
    }

    /** @return array{origem: string, destino: string, empresas?: list<int>} */
    private function validar(Request $r): array
    {
        return $r->validate(['origem' => ['required', 'string', 'max:20'], 'destino' => ['required', 'string', 'max:20'],
            'empresas' => ['sometimes', 'array', 'min:1', 'max:200'], 'empresas.*' => ['integer']], [], ['origem' => 'conta de origem', 'destino' => 'conta de destino']);
    }
}
