<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\Projeto;
use App\Services\Projetos\ServicoOrcamentoQuantidadesProjetos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/projetos/{projeto}/orcamento/... — orçamento mapeado pelas quantidades (Versão Actual, projectos_orcamento_qtd.js). */
final class OrcamentoQuantidadesController extends Controller
{
    public function __construct(private readonly ServicoOrcamentoQuantidadesProjetos $servico) {}

    public function resumo(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->servico->resumo(Projeto::query()->findOrFail($projeto)), 'Orçamento mapeado pelas quantidades.');
    }

    public function prever(Request $r, int $projeto, int $linha): JsonResponse
    {
        $this->exigir('proj_orcamento_qtd');
        $d = $this->validar($r);
        $p = Projeto::query()->findOrFail($projeto);

        return RespostaApi::sucesso($this->servico->prever($p, LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->findOrFail($linha), $d), 'Previsão do mapeamento.');
    }

    public function mapear(Request $r, int $projeto, int $linha): JsonResponse
    {
        $this->exigir('proj_orcamento_qtd');
        $d = $this->validar($r);
        $res = $this->servico->mapear(Projeto::query()->findOrFail($projeto), $linha, $d);

        return RespostaApi::criado($res, "Mapeado: {$res['preco_unitario']} Kz por {$res['unidade']}, repartido por ".count($res['linhas']).' tarefa(s).');
    }

    public function retirar(int $projeto, int $linha, int $indice): JsonResponse
    {
        $this->exigir('proj_orcamento_qtd');

        return RespostaApi::sucesso($this->servico->retirar(Projeto::query()->findOrFail($projeto), $linha, $indice), 'Mapeamento retirado: o valor voltou ao saldo.');
    }

    private function validar(Request $r): array
    {
        return $r->validate(['unidade' => ['required', 'string', 'max:20'], 'base' => ['nullable', Rule::in(array_keys(ServicoOrcamentoQuantidadesProjetos::BASES))],
            'escopo' => ['nullable', 'array'], 'escopo.tipo' => ['nullable', Rule::in(ServicoOrcamentoQuantidadesProjetos::ESCOPOS)], 'escopo.ids' => ['nullable', 'array', 'max:2000'],
            'escopo.ids.*' => ['integer'], 'valor' => ['required', 'numeric', 'gt:0', 'max:999999999999']]);
    }
}
