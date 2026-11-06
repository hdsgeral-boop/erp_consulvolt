<?php

namespace App\Http\Controllers\Api\Procedimentos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procedimentos\CriarProcedimentoRequest;
use App\Http\Requests\Procedimentos\DefinicoesManualRequest;
use App\Http\Requests\Procedimentos\GravarProcedimentoRequest;
use App\Http\Resources\Procedimentos\ProcedimentosResource;
use App\Services\Procedimentos\ServicoManualProcedimentos;
use App\Services\Procedimentos\ServicoProcedimentos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * Manual de Procedimentos (Onda 1, ecrã proc_manual). Ver: proc_manual_view. Escrever: proc_editar (aplicado no servidor,
 * corrige o B-04 do legado). Editar a narrativa muda o manual, nunca o que o painel do Fluxo de Processos mede.
 *  GET    /procedimentos                    processos (fábrica ∪ edições ∪ próprios) e escondidos
 *  GET    /procedimentos/cobertura          módulos do ERP × painéis × narrativas
 *  POST   /procedimentos                    novo processo próprio
 *  PUT    /procedimentos/{chave}            gravar (substitui etapas e cruzamentos)
 *  DELETE /procedimentos/{chave}            repor o texto do ERP / apagar o próprio
 *  POST   /procedimentos/{chave}/ocultar|mostrar
 *  GET|PUT /manuais/procedimentos           definições do documento
 *  POST   /manuais/procedimentos/recolha    dados do documento (formulário por gravar), para pré-visualizar e imprimir
 */
final class ProcedimentosController extends Controller
{
    public function __construct(private readonly ServicoProcedimentos $processos, private readonly ServicoManualProcedimentos $manual) {}

    public function index(): JsonResponse
    {
        $this->exigir('proc_manual_view');

        return RespostaApi::sucesso(new ProcedimentosResource(['processos' => $this->processos->listar(), 'ocultos' => $this->processos->ocultos()]), 'Processos do manual.');
    }

    public function cobertura(): JsonResponse
    {
        $this->exigir('proc_manual_view');

        return RespostaApi::sucesso(new ProcedimentosResource($this->processos->cobertura()), 'Cobertura dos processos.');
    }

    public function store(CriarProcedimentoRequest $r): JsonResponse
    {
        return RespostaApi::criado(new ProcedimentosResource($this->processos->criar((string) $r->validated('titulo'))), 'Processo criado. Preencha as etapas.');
    }

    public function update(GravarProcedimentoRequest $r, string $chave): JsonResponse
    {
        return RespostaApi::sucesso(new ProcedimentosResource($this->processos->gravar($chave, $r->validated())), 'Processo gravado.');
    }

    public function destroy(string $chave): JsonResponse
    {
        $this->exigir('proc_editar');
        $this->processos->repor($chave);

        return RespostaApi::sucesso(null, 'Processo reposto.');
    }

    public function ocultar(string $chave): JsonResponse
    {
        $this->exigir('proc_editar');
        $this->processos->ocultar($chave, true);

        return RespostaApi::sucesso(null, 'Escondido do manual.');
    }

    public function mostrar(string $chave): JsonResponse
    {
        $this->exigir('proc_editar');
        $this->processos->ocultar($chave, false);

        return RespostaApi::sucesso(null, 'Voltou a incluir-se no manual.');
    }

    public function definicoes(): JsonResponse
    {
        $this->exigir('proc_manual_view');

        return RespostaApi::sucesso(new ProcedimentosResource($this->manual->definicoes()), 'Definições do manual.');
    }

    public function guardarDefinicoes(DefinicoesManualRequest $r): JsonResponse
    {
        return RespostaApi::sucesso(new ProcedimentosResource($this->manual->gravar($r->validated())), 'Manual gravado.');
    }

    public function recolha(DefinicoesManualRequest $r): JsonResponse
    {
        return RespostaApi::sucesso(new ProcedimentosResource($this->manual->recolher(array_filter($r->validated(), fn ($v) => $v !== null))), 'Dados do manual.');
    }
}
