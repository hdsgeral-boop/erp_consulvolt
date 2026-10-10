<?php

namespace App\Http\Controllers\Api\Estrutura;

use App\Http\Controllers\Controller;
use App\Services\Estrutura\ServicoParidadeEstrutura;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/estrutura/* — paridade com o ecrã «Estrutura orgânica» do sistema anterior: salários dos colaboradores afectos
 * (só com est_ver_salarios) e disposição das subunidades no organigrama (est_editar).
 */
final class ParidadeEstruturaController extends Controller
{
    public function __construct(private readonly ServicoParidadeEstrutura $servico) {}

    /** GET /estrutura/salarios — ilíquido do último processamento fechado por colaborador activo. */
    public function salarios(): JsonResponse
    {
        $this->exigir('est_ver_salarios');
        $this->exigir('est_estrutura_view', 'est_mapa_view', 'est_mapa', 'colaboradores_view');

        return RespostaApi::sucesso($this->servico->salarios(), 'Salários da estrutura orgânica.');
    }

    /** POST /estrutura/disposicao — {ids: [...], disposicao: 'COLUNA' | null}. */
    public function disposicao(Request $r): JsonResponse
    {
        $this->exigir('est_editar');
        $e = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', Rule::exists('unidades_organicas', 'id')->where('empresa_id', $e)->whereNull('eliminado_em')],
            'disposicao' => ['present', 'nullable', Rule::in(ServicoParidadeEstrutura::DISPOSICOES)],
        ]);
        $n = $this->servico->disposicao(array_map('intval', $d['ids']), $d['disposicao'] ?? null);

        return RespostaApi::sucesso(['unidades' => $n, 'disposicao' => $d['disposicao'] ?? null],
            $n.' unidade(s): '.(($d['disposicao'] ?? null) === 'COLUNA' ? 'subunidades em coluna.' : 'subunidades lado a lado.'));
    }
}
