<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\AditamentoAlteracaoProjeto;
use App\Models\FolhaHorasProjeto;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\RevisaoMensalProjeto;
use App\Services\Compras\ServicoFaturasCompra;
use App\Services\Projetos\ServicoAnaliticoProjetos;
use App\Services\Projetos\ServicoExecucaoProjetos;
use App\Services\Projetos\ServicoOrcamentoProjetos;
use App\Services\Projetos\ServicoRequisicoesProjetos;
use App\Services\Projetos\ServicoRevisoesProjetos;
use App\Services\Vendas\ServicoDocumentosVenda;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/projetos/{projeto} — orçamento base, aditamentos, horas, equipamentos, requisições, autos/revisões e facturação. */
final class ExecucaoController extends Controller
{
    public function __construct(
        private readonly ServicoOrcamentoProjetos $orcamento,
        private readonly ServicoExecucaoProjetos $execucao,
        private readonly ServicoRequisicoesProjetos $requisicoes,
        private readonly ServicoRevisoesProjetos $revisoes,
    ) {}

    // ───────────── Orçamento base ─────────────

    public function orcamento(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->orcamento->linhas(Projeto::query()->findOrFail($projeto)), 'Orçamento base do projecto.');
    }

    public function guardarLinha(Request $r, int $projeto, ?int $linha = null): JsonResponse
    {
        $this->exigir('proj_gerir');
        $p = Projeto::query()->findOrFail($projeto);
        $d = $r->validate(['tarefa_projeto_id' => ['nullable', 'integer'], 'rubrica' => [$linha ? 'sometimes' : 'required', 'string', 'max:20'],
            'numero_conta' => ['nullable', 'string', 'max:20'], 'montante' => [$linha ? 'sometimes' : 'required', 'numeric']]);
        $l = $linha ? LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->findOrFail($linha) : null;
        $res = $this->orcamento->guardarLinha($p, $d, $l);

        return $l ? RespostaApi::sucesso($res, 'Rubrica actualizada.') : RespostaApi::criado($res, 'Rubrica adicionada ao orçamento.');
    }

    public function eliminarLinha(int $projeto, int $linha): JsonResponse
    {
        $this->exigir('proj_gerir');
        $this->orcamento->eliminarLinha(LinhaOrcamentoProjeto::query()->where('projeto_id', $projeto)->findOrFail($linha));

        return RespostaApi::sucesso(null, 'Rubrica removida do orçamento.');
    }

    // ───────────── Aditamentos ─────────────

    public function aditamentos(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->orcamento->aditamentos(Projeto::query()->findOrFail($projeto)), 'Aditamentos e trabalhos a mais.');
    }

    public function guardarAditamento(Request $r, int $projeto, ?int $aditamento = null): JsonResponse
    {
        $this->exigir('proj_gerir');
        $p = Projeto::query()->findOrFail($projeto);
        $d = $r->validate(['descricao' => [$aditamento ? 'sometimes' : 'required', 'string', 'max:5000'], 'montante' => ['nullable', 'numeric'],
            'estado' => ['nullable', Rule::in(ServicoOrcamentoProjetos::ESTADOS_ADITAMENTO)]]);
        $a = $aditamento ? AditamentoAlteracaoProjeto::query()->where('projeto_id', $p->id)->findOrFail($aditamento) : null;
        $res = $this->orcamento->guardarAditamento($p, $d, $a);

        return $a ? RespostaApi::sucesso($res, 'Aditamento actualizado.') : RespostaApi::criado($res, 'Aditamento registado.');
    }

    public function eliminarAditamento(int $projeto, int $aditamento): JsonResponse
    {
        $this->exigir('proj_eliminar');
        $this->orcamento->eliminarAditamento(AditamentoAlteracaoProjeto::query()->where('projeto_id', $projeto)->findOrFail($aditamento));

        return RespostaApi::sucesso(null, 'Aditamento eliminado.');
    }

    // ───────────── Horas e equipamentos ─────────────

    public function horas(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->execucao->folhasHoras(Projeto::query()->findOrFail($projeto)), 'Folhas de horas do projecto.');
    }

    public function registarHoras(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir', 'proj_execucao');
        $d = $r->validate(['tarefa_projeto_id' => ['required', 'integer'], 'colaborador_id' => ['required', 'integer'], 'data' => ['required', 'date'], 'horas' => ['required', 'numeric']]);

        return RespostaApi::criado($this->execucao->registarHoras(Projeto::query()->findOrFail($projeto), $d), 'Horas registadas.');
    }

    public function eliminarHoras(int $projeto, int $folha): JsonResponse
    {
        $this->exigir('proj_eliminar');
        $this->execucao->eliminarHoras(FolhaHorasProjeto::query()->where('projeto_id', $projeto)->findOrFail($folha));

        return RespostaApi::sucesso(null, 'Registo de horas eliminado.');
    }

    /** GET /{projeto}/equipamentos — usos de equipamento imputados e afectações de activos ao projecto (ADR-064). */
    public function equipamentos(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->execucao->equipamentos(Projeto::query()->findOrFail($projeto)), 'Equipamentos do projecto.');
    }

    public function registarEquipamento(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir', 'proj_execucao');
        $d = $r->validate(['tarefa_projeto_id' => ['required', 'integer'], 'ativo_imobilizado_id' => ['required', 'integer'], 'data' => ['required', 'date'],
            'horas' => ['required', 'numeric'], 'custo_hora' => ['required', 'numeric']]);

        return RespostaApi::criado($this->execucao->registarEquipamento(Projeto::query()->findOrFail($projeto), $d), 'Custo de equipamento imputado.');
    }

    public function eliminarEquipamento(int $projeto, int $movimento): JsonResponse
    {
        $this->exigir('proj_eliminar');
        $this->execucao->eliminarEquipamento(RazaoAnaliticoProjeto::query()->where('projeto_id', $projeto)->findOrFail($movimento));

        return RespostaApi::sucesso(null, 'Uso de equipamento eliminado.');
    }

    /** Imputação manual do processamento salarial (até o gancho em ServicoFolhaSalarial::contabilizar existir). */
    public function imputarPeriodo(int $periodo): JsonResponse
    {
        $this->exigir('proj_revisao');
        $per = PeriodoProcessamentoSalarial::query()->findOrFail($periodo);
        if (! $per->contabilizado) {
            throw new ErroNegocio('Só se imputam aos projectos processamentos já contabilizados.', 'PERIODO_NAO_CONTABILIZADO', 422);
        }

        return RespostaApi::sucesso($this->execucao->imputarPeriodo($per), 'Mão de obra do processamento imputada aos projectos.');
    }

    public function reverterPeriodo(int $periodo): JsonResponse
    {
        $this->exigir('proj_revisao');

        return RespostaApi::sucesso(['removidos' => $this->execucao->reverterPeriodo(PeriodoProcessamentoSalarial::query()->findOrFail($periodo))],
            'Imputação do processamento revertida.');
    }

    // ───────────── Requisições ─────────────

    public function requisicoes(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->requisicoes->listar(Projeto::query()->findOrFail($projeto)), 'Requisições de material.');
    }

    public function requisitar(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_requisitar', 'proj_gerir');
        $d = $r->validate(['nome_requerente' => ['required', 'string', 'max:200'], 'data' => ['required', 'date'], 'data_prevista' => ['nullable', 'date'],
            'linhas' => ['required', 'array', 'min:1'], 'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric'],
            'linhas.*.tarefa_projeto_id' => ['nullable', 'integer']]);
        $res = $this->requisicoes->criar(Projeto::query()->findOrFail($projeto), $d);

        return RespostaApi::criado($res['requisicao']->toArray() + ['pedido_compra_id' => $res['pedido_compra_id']], 'Requisição submetida.');
    }

    // ───────────── Autos e revisões ─────────────

    public function revisoes(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->revisoes->listar(Projeto::query()->findOrFail($projeto)), 'Autos de medição e revisões mensais.');
    }

    public function simular(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_revisao');
        $d = $r->validate(['mes' => ['required', 'integer', 'between:1,12'], 'ano' => ['required', 'integer', 'between:2000,2100']]);

        return RespostaApi::sucesso($this->revisoes->simular(Projeto::query()->findOrFail($projeto), (int) $d['mes'], (int) $d['ano']), 'Simulação da revisão do mês.');
    }

    public function executar(Request $r, int $projeto, ServicoFaturasCompra $faturas): JsonResponse
    {
        $this->exigir('proj_revisao');
        $d = $r->validate(['mes' => ['required', 'integer', 'between:1,12'], 'ano' => ['required', 'integer', 'between:2000,2100'],
            'confirmar_aditamento' => ['nullable', 'boolean'], 'produto_subempreitada_id' => ['nullable', 'integer']]);
        $p = Projeto::query()->findOrFail($projeto);
        $rev = $this->revisoes->executar($p, $d, $faturas);

        return RespostaApi::criado($this->revisoes->detalhe($p, $rev), 'Revisão mensal executada.');
    }

    public function revisao(int $projeto, int $revisao): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);
        $p = Projeto::query()->findOrFail($projeto);

        return RespostaApi::sucesso($this->revisoes->detalhe($p, RevisaoMensalProjeto::query()->where('projeto_id', $p->id)->findOrFail($revisao)), 'Auto de medição.');
    }

    public function propostaFaturacao(int $projeto, int $revisao): JsonResponse
    {
        $this->exigir('proj_revisao');
        $p = Projeto::query()->findOrFail($projeto);

        return RespostaApi::sucesso($this->revisoes->propostaFaturacao($p, RevisaoMensalProjeto::query()->where('projeto_id', $p->id)->findOrFail($revisao)), 'Proposta de facturação do auto.');
    }

    public function faturar(Request $r, int $projeto, int $revisao, ServicoDocumentosVenda $vendas): JsonResponse
    {
        $this->exigir('proj_revisao');
        $d = $r->validate(['tipo_documento' => ['required', Rule::in(ServicoRevisoesProjetos::TIPOS_FATURACAO)], 'valor' => ['required', 'numeric', 'gt:0'],
            'produto_id' => ['nullable', 'integer'], 'data_emissao' => ['nullable', 'date'], 'conta_disponibilidade' => ['nullable', 'string', 'max:20'],
            'meio_pagamento' => ['nullable', 'string', 'max:20']]);
        $p = Projeto::query()->findOrFail($projeto);
        $v = $this->revisoes->faturar($p, RevisaoMensalProjeto::query()->where('projeto_id', $p->id)->findOrFail($revisao), $d, $vendas);

        return RespostaApi::criado($v, "Documento {$v->numero_documento} gerado no módulo de Vendas.");
    }

    public function custosPorTarefa(int $projeto, ServicoAnaliticoProjetos $analitico): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($analitico->porTarefa(Projeto::query()->findOrFail($projeto)), 'Orçamento, custos e horas por tarefa.');
    }
}
