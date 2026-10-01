<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoEncerramento;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/contabilidade/encerramento — apuramento de resultados (período 13), validações, encerramento e reabertura do exercício. */
final class EncerramentoController extends Controller
{
    public function __construct(private readonly ServicoEncerramento $encerramento) {}

    public function exercicios(): JsonResponse
    {
        $this->exigir('encerramento_view', 'contab_apurar');

        return RespostaApi::sucesso($this->encerramento->exercicios(), 'Exercícios da empresa.');
    }

    public function estado(int $ano): JsonResponse
    {
        $this->exigir('encerramento_view', 'contab_apurar');

        return RespostaApi::sucesso($this->encerramento->estado($this->ano($ano)), "Estado do exercício de {$ano}.");
    }

    public function previsualizar(int $ano, int $passo): JsonResponse
    {
        $this->exigir('encerramento_view', 'contab_apurar');

        return RespostaApi::sucesso($this->encerramento->previsualizar($this->ano($ano), $passo), "Pré-visualização do passo {$passo}.");
    }

    public function executarPasso(Request $r, int $ano, int $passo): JsonResponse
    {
        $this->exigir('contab_apurar');
        $d = $r->validate(['criar_contas_em_falta' => ['sometimes', 'boolean']]);
        $res = $this->encerramento->executarPasso($this->ano($ano), $passo, (bool) ($d['criar_contas_em_falta'] ?? false));

        return RespostaApi::sucesso($res, $res['numero_lan'] ? "Passo {$passo} executado: lançamento {$res['numero_lan']} no período 13." : "Não existem saldos a apurar no passo {$passo}.");
    }

    public function validar(int $ano): JsonResponse
    {
        $this->exigir('encerramento_view', 'contab_apurar');
        $res = $this->encerramento->validar($this->ano($ano));

        return RespostaApi::sucesso($res, $res['pode_encerrar'] ? 'Todas as validações aprovadas.' : 'Existem divergências que impedem o encerramento.');
    }

    public function encerrar(int $ano): JsonResponse
    {
        $this->exigir('contab_apurar');

        return RespostaApi::sucesso($this->encerramento->encerrar($this->ano($ano)), "Exercício de {$ano} encerrado.");
    }

    public function reabrir(Request $r, int $ano): JsonResponse
    {
        $this->exigir('contab_exercicio_reabrir');
        $d = $r->validate(['motivo' => ['required', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->encerramento->reabrir($this->ano($ano), $d['motivo']), "Exercício de {$ano} reaberto.");
    }

    public function cancelarApuramento(Request $r, int $ano): JsonResponse
    {
        $this->exigir('contab_exercicio_reabrir');
        $d = $r->validate(['motivo' => ['required', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->encerramento->cancelarApuramento($this->ano($ano), $d['motivo']), "Apuramento de {$ano} cancelado: lançamentos do período 13 estornados.");
    }

    public function mapa(int $ano): JsonResponse
    {
        $this->exigir('encerramento_view', 'contab_apurar');

        return RespostaApi::sucesso($this->encerramento->mapa($this->ano($ano)), "Mapa de apuramento de {$ano}.");
    }

    private function ano(int $ano): int
    {
        validator(['ano' => $ano], ['ano' => ['integer', 'between:1900,2100']])->validate();

        return $ano;
    }
}
