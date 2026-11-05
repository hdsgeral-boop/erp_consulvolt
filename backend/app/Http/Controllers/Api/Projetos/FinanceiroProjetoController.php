<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\FaturacaoProjeto;
use App\Models\Projeto;
use App\Services\Projetos\ServicoFinanceiroProjetos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/projetos/{projeto}/financeiro — separador «Financeiro» (indicadores, valor acrescentado, plano de facturação). */
final class FinanceiroProjetoController extends Controller
{
    private const GERIR = ['proj_gerir', 'proj_revisao'];

    public function __construct(private readonly ServicoFinanceiroProjetos $financeiro) {}

    public function painel(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->financeiro->painel(Projeto::query()->findOrFail($projeto)), 'Financeiro do projecto.');
    }

    public function guardar(Request $r, int $projeto, ?int $linha = null): JsonResponse
    {
        $this->exigir(...self::GERIR);
        $d = $r->validate(['descricao' => ['required', 'string', 'max:200'], 'montante' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'], 'data_prevista' => ['nullable', 'date'],
            'retencao_pct' => ['nullable', 'numeric', 'between:0,100'], 'marco_projeto_id' => ['nullable', 'integer']]);
        $l = $linha ? $this->linha($projeto, $linha) : null;
        $x = $this->financeiro->guardarPrestacao(Projeto::query()->findOrFail($projeto), $d, $l);

        return $linha ? RespostaApi::sucesso($x, 'Prestação actualizada.') : RespostaApi::criado($x, 'Prestação criada.');
    }

    public function cancelar(int $projeto, int $linha): JsonResponse
    {
        $this->exigir(...self::GERIR);

        return RespostaApi::sucesso($this->financeiro->cancelarPrestacao($this->linha($projeto, $linha)), 'Prestação cancelada.');
    }

    public function associar(Request $r, int $projeto, int $linha): JsonResponse
    {
        $this->exigir(...self::GERIR);
        $d = $r->validate(['venda_id' => ['required', 'integer']]);

        return RespostaApi::sucesso($this->financeiro->associarFactura(Projeto::query()->findOrFail($projeto), $this->linha($projeto, $linha), (int) $d['venda_id']), 'Factura associada à prestação.');
    }

    public function desassociar(int $projeto, int $linha): JsonResponse
    {
        $this->exigir(...self::GERIR);

        return RespostaApi::sucesso($this->financeiro->desassociarFactura($this->linha($projeto, $linha)), 'Factura desassociada.');
    }

    private function linha(int $projeto, int $linha): FaturacaoProjeto
    {
        return FaturacaoProjeto::query()->where('projeto_id', $projeto)->findOrFail($linha);
    }
}
