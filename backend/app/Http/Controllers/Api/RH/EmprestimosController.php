<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\EmprestimoColaborador;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\RH\ServicoEmprestimos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/rh/emprestimos — RH › Empréstimos (Versão Actual: emprestimos_ui.js, separadores Lista e Configurações) e o botão
 * «Importar Empréstimos» do cálculo (app_v2.js:5406). Permissões (permissoes.js, ecrã rh_emprestimos):
 *   - rh_emprestimos_view / rh_emp_gerir: consultar; rh_emp_gerir: cancelar;
 *   - rh_emp_lancar: lançar na conta corrente e importar/anular as prestações no processamento;
 *   - rh_emp_config: configuração (contas, diário, rubrica de desconto, limites).
 */
final class EmprestimosController extends Controller
{
    public function __construct(private readonly ServicoEmprestimos $emprestimos) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('rh_emprestimos_view', 'rh_emp_gerir', 'rh_emp_lancar');
        $f = $r->validate(['estado' => ['nullable', Rule::in(EmprestimoColaborador::ESTADOS)], 'colaborador_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->emprestimos->listar($f['estado'] ?? null, isset($f['colaborador_id']) ? (int) $f['colaborador_id'] : null), 'Empréstimos a colaboradores.');
    }

    public function show(int $emprestimo): JsonResponse
    {
        $this->exigir('rh_emprestimos_view', 'rh_emp_gerir', 'rh_emp_lancar');

        return RespostaApi::sucesso($this->emprestimos->detalhe(EmprestimoColaborador::query()->findOrFail($emprestimo)), 'Empréstimo e plano de amortização.');
    }

    public function configuracao(): JsonResponse
    {
        $this->exigir('rh_emprestimos_view', 'rh_emp_config', 'rh_emp_gerir', 'rh_emp_lancar');

        return RespostaApi::sucesso($this->emprestimos->definicoes(), 'Configuração dos empréstimos.');
    }

    public function gravarConfiguracao(Request $r): JsonResponse
    {
        $this->exigir('rh_emp_config');
        $d = $r->validate([
            'conta_emprestimo' => ['nullable', 'string', 'max:20'], 'conta_contrapartida' => ['nullable', 'string', 'max:20'],
            'diario_id' => ['nullable', 'integer'], 'infotipo_desconto_id' => ['nullable', 'integer'],
            'prestacoes_max' => ['required', 'integer', 'between:1,120'], 'multiplo_salario_max' => ['required', 'numeric', 'between:0,100'],
            'taxa_juro_padrao' => ['required', 'numeric', 'between:0,100'], 'exige_chefia' => ['required', 'boolean'],
        ], [], ['prestacoes_max' => 'máximo de prestações', 'multiplo_salario_max' => 'múltiplo do salário', 'taxa_juro_padrao' => 'taxa de juro anual']);

        return RespostaApi::sucesso($this->emprestimos->gravarDefinicoes($d), 'Configuração dos empréstimos gravada.');
    }

    public function cancelar(Request $r, int $emprestimo): JsonResponse
    {
        $this->exigir('rh_emp_gerir');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:1000']]);

        return RespostaApi::sucesso($this->emprestimos->cancelar(EmprestimoColaborador::query()->findOrFail($emprestimo), $d['motivo']), 'Empréstimo cancelado.');
    }

    public function contaCorrente(Request $r, int $emprestimo): JsonResponse
    {
        $this->exigir('rh_emp_lancar');
        $d = $r->validate(['data' => ['nullable', 'date_format:Y-m-d'], 'diario_id' => ['nullable', 'integer']]);
        $e = $this->emprestimos->lancarContaCorrente(EmprestimoColaborador::query()->findOrFail($emprestimo), $d['data'] ?? null, $d['diario_id'] ?? null);

        return RespostaApi::sucesso($e, "Empréstimo lançado na conta corrente ({$e->lancamento_referencia}).");
    }

    /** GET /rh/salarios/periodos/{id}/emprestimos — prestações por descontar no mês do processamento. */
    public function prestacoesDoPeriodo(int $id): JsonResponse
    {
        $this->exigir('rh_emp_lancar', 'calcular_view');

        return RespostaApi::sucesso($this->emprestimos->prestacoesDoMes(PeriodoProcessamentoSalarial::query()->findOrFail($id)->mes_ano), 'Prestações do mês.');
    }

    /** POST /rh/salarios/periodos/{id}/importar-emprestimos — «Importar Empréstimos». */
    public function importar(Request $r, int $id): JsonResponse
    {
        $this->exigir('rh_emp_lancar');
        $d = $r->validate(['substituir' => ['nullable', 'boolean']]);
        $res = $this->emprestimos->lancarNoPeriodo(PeriodoProcessamentoSalarial::query()->findOrFail($id), (bool) ($d['substituir'] ?? false));

        return RespostaApi::sucesso($res, "Empréstimos: {$res['lancados']} lançado(s), {$res['actualizados']} actualizado(s), {$res['ignorados']} já existente(s).");
    }

    /** POST /rh/salarios/periodos/{id}/anular-emprestimos — desfaz o desconto das prestações no período (aberto). */
    public function anular(int $id): JsonResponse
    {
        $this->exigir('rh_emp_lancar');
        $n = $this->emprestimos->anularDoPeriodo(PeriodoProcessamentoSalarial::query()->findOrFail($id));

        return RespostaApi::sucesso(['repostas' => $n], "{$n} prestação(ões) reposta(s) por descontar.");
    }
}
