<?php

namespace App\Http\Controllers\Api\Tesouraria;

use App\Http\Controllers\Controller;
use App\Models\DocumentoTesouraria;
use App\Models\ItemDocumentoTesouraria;
use App\Models\MeioPagamento;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use App\Services\Tesouraria\ServicoMeiosPagamento;
use App\Services\Tesouraria\ServicoPendentes;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/tesouraria — ecrãs teso_gestao_pagamentos e teso_meios_pagamento do legado (js/ui_tesouraria.js). */
final class TesourariaController extends Controller
{
    public function __construct(
        private readonly ServicoDocumentosTesouraria $documentos,
        private readonly ServicoPendentes $pendentes,
        private readonly ServicoMeiosPagamento $meios,
    ) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('teso_gestao_pagamentos_view', 'teso_contab_integracao_view', 'teso_contab_historico_view');
        $f = $r->validate(['tipo' => ['nullable', Rule::in(ServicoDocumentosTesouraria::TIPOS)], 'estado' => ['nullable', Rule::in(['PENDENTE', 'INTEGRADO', 'ANULADO'])],
            'conta_financeira' => ['nullable', 'string', 'max:20'], 'data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d'],
            'pesquisa' => ['nullable', 'string', 'max:100'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $pagina = DocumentoTesouraria::query()
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->when($f['conta_financeira'] ?? null, fn ($q, $v) => $q->where('conta_financeira', $v))
            ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('data_documento', '>=', $v))->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('data_documento', '<=', $v))
            ->when($f['pesquisa'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('descricao', 'ilike', "%{$v}%")->orWhere('referencia', 'ilike', "%{$v}%")
                ->orWhere('numero_documento', 'ilike', "%{$v}%")))
            ->orderByDesc('data_documento')->orderByDesc('id')->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));

        return RespostaApi::sucesso(['itens' => $pagina->items(), 'total' => $pagina->total(), 'pagina' => $pagina->currentPage(), 'por_pagina' => $pagina->perPage()],
            'Documentos de tesouraria.');
    }

    public function show(int $id): JsonResponse
    {
        $this->exigir('teso_gestao_pagamentos_view', 'teso_contab_integracao_view', 'teso_contab_historico_view');

        return RespostaApi::sucesso($this->doc(DocumentoTesouraria::query()->findOrFail($id)), 'Documento obtido com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('teso_doc_emitir');
        $d = $this->documentos->gravar($this->validar($r));

        return RespostaApi::criado($this->doc($d), "Documento {$d->numero_documento} gravado (por integrar).");
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $this->exigir('teso_doc_emitir');
        $d = $this->documentos->gravar($this->validar($r), DocumentoTesouraria::query()->findOrFail($id));

        return RespostaApi::sucesso($this->doc($d), "Documento {$d->numero_documento} actualizado.");
    }

    public function anular(Request $r, int $id): JsonResponse
    {
        $this->exigir('teso_doc_eliminar');
        $d = $this->documentos->anular(DocumentoTesouraria::query()->findOrFail($id), $this->motivo($r));

        return RespostaApi::sucesso($this->doc($d), 'Documento anulado.');
    }

    public function integrar(int $id): JsonResponse
    {
        $this->exigir('teso_integrar');
        $d = $this->documentos->integrar(DocumentoTesouraria::query()->findOrFail($id));

        return RespostaApi::sucesso($this->doc($d), "Documento integrado (lançamento {$d->numero_lan_contabilizacao}).");
    }

    public function desintegrar(Request $r, int $id): JsonResponse
    {
        $this->exigir('teso_desintegrar');
        $d = $this->documentos->desintegrar(DocumentoTesouraria::query()->findOrFail($id), $this->motivo($r));

        return RespostaApi::sucesso($this->doc($d), 'Documento desintegrado (estorno registado).');
    }

    /** GET /api/tesouraria/pendentes — documentos em aberto de clientes e fornecedores. */
    public function pendentes(Request $r): JsonResponse
    {
        $this->exigir('teso_gestao_pagamentos_view');
        $f = $r->validate(['terceiro_id' => ['nullable', 'integer'], 'codigo_conta' => ['nullable', 'string', 'max:20'],
            'natureza' => ['nullable', Rule::in(['A_RECEBER', 'A_PAGAR'])], 'pesquisa' => ['nullable', 'string', 'max:100']]);

        return RespostaApi::sucesso($this->pendentes->abertos($f), 'Documentos em aberto.');
    }

    public function meios(): JsonResponse
    {
        $this->exigir('teso_meios_pagamento_view', 'teso_gestao_pagamentos_view');

        return RespostaApi::sucesso(MeioPagamento::query()->orderByDesc('predefinido')->orderBy('nome')->get(), 'Meios de pagamento.');
    }

    public function gravarMeio(Request $r, ?int $id = null): JsonResponse
    {
        $this->exigir('teso_meios_gerir');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'codigo_conta' => ['required', 'string', 'max:20'], 'iban' => ['nullable', 'string', 'max:40'],
            'swift' => ['nullable', 'string', 'max:11'], 'ativo' => ['nullable', 'boolean'], 'predefinido' => ['nullable', 'boolean']]);
        $m = $this->meios->gravar($d, $id ? MeioPagamento::query()->findOrFail($id) : null);

        return $id ? RespostaApi::sucesso($m, 'Meio de pagamento actualizado.') : RespostaApi::criado($m, 'Meio de pagamento criado.');
    }

    public function eliminarMeio(int $id): JsonResponse
    {
        $this->exigir('teso_meios_gerir');
        $this->meios->eliminar(MeioPagamento::query()->findOrFail($id));

        return RespostaApi::sucesso(null, 'Meio de pagamento eliminado.');
    }

    private function validar(Request $r): array
    {
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $daEmpresa = fn (string $t) => Rule::exists($t, 'id')->where('empresa_id', $empresa);

        return $r->validate([
            'tipo' => ['required', Rule::in(ServicoDocumentosTesouraria::TIPOS)], 'data_documento' => ['required', 'date_format:Y-m-d'],
            'conta_financeira' => ['required', 'string', 'max:20'], 'descricao' => ['required', 'string', 'min:5', 'max:1000'],
            'referencia' => ['nullable', 'string', 'max:100'], 'projeto_id' => ['nullable', 'integer', $daEmpresa('projetos')],
            'linhas' => ['required', 'array', 'min:1', 'max:500'],
            'linhas.*.codigo_conta' => ['required', 'string', 'max:20'], 'linhas.*.tipo_dc' => ['required', Rule::in(['D', 'C'])],
            'linhas.*.valor' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'linhas.*.terceiro_id' => ['nullable', 'integer', $daEmpresa('terceiros')], 'linhas.*.numero_documento' => ['nullable', 'string', 'max:100'],
            'linhas.*.descricao' => ['nullable', 'string', 'max:1000'], 'linhas.*.venda_id' => ['nullable', 'integer', $daEmpresa('vendas')],
            'linhas.*.fatura_compra_id' => ['nullable', 'integer', $daEmpresa('faturas_compra')],
            'linhas.*.unidade_negocio_id' => ['nullable', 'integer', $daEmpresa('unidades_negocio')], 'linhas.*.centro_custo_id' => ['nullable', 'integer', $daEmpresa('centros_custo')],
            'linhas.*.projeto_id' => ['nullable', 'integer', $daEmpresa('projetos')],
        ], [], ['descricao' => 'descrição', 'conta_financeira' => 'conta de banco/caixa']);
    }

    private function motivo(Request $r): string
    {
        return $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo'])['motivo'];
    }

    private function doc(DocumentoTesouraria $d): array
    {
        return $d->refresh()->toArray() + ['linhas' => ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $d->id)->orderBy('id')->get()->toArray()];
    }
}
