<?php

namespace App\Http\Controllers\Api\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\DocumentoTesouraria;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use App\Services\Tesouraria\ServicoImportacaoTesouraria;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * /api/tesouraria/documentos — importação por Excel com o modelo do legado (A-12) e acções em lote sobre a selecção
 * (deleteSelectedtreasury / unpostSelectedtreasury do legado): anular os pendentes e desintegrar (estornar) os integrados,
 * cada documento na sua transacção.
 */
final class ImportacaoTesourariaController extends Controller
{
    public function __construct(
        private readonly ServicoImportacaoTesouraria $importacao,
        private readonly ServicoDocumentosTesouraria $documentos,
    ) {}

    /** GET /modelo-importacao — Template_Importação_Tesouraria.xlsx. */
    public function modelo(): BinaryFileResponse
    {
        $this->exigir('teso_doc_emitir');

        return response()->download($this->importacao->modelo(), 'Template_Importacao_Tesouraria.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** POST /importar — {ficheiro, simular (por omissão true)}. Os documentos ficam PENDENTES. */
    public function importar(Request $r): JsonResponse
    {
        $this->exigir('teso_doc_emitir');
        $d = $r->validate(['ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'], 'simular' => ['nullable', 'boolean']], [], ['ficheiro' => 'ficheiro']);
        $simular = $r->boolean('simular', true);
        $res = $this->importacao->importar($r->file('ficheiro')->getRealPath(), $simular);

        return RespostaApi::sucesso($res, $simular
            ? "Simulação: {$res['documentos']} documento(s) lidos; ".count($res['gravados']).' válido(s), '.count($res['erros']).' com erro. Nada foi gravado.'
            : count($res['gravados']).' documento(s) importado(s) em PENDENTE; '.count($res['erros']).' com erro.');
    }

    /** POST /anular — anula os pendentes seleccionados (com motivo). */
    public function anularLote(Request $r): JsonResponse
    {
        $this->exigir('teso_doc_eliminar');
        $d = $this->lote($r);

        return $this->executar($d['ids'], fn (DocumentoTesouraria $doc) => $this->documentos->anular($doc, $d['motivo']), 'anulado(s)');
    }

    /** POST /desintegrar — estorna os integrados seleccionados (com motivo). */
    public function desintegrarLote(Request $r): JsonResponse
    {
        $this->exigir('teso_desintegrar');
        $d = $this->lote($r);

        return $this->executar($d['ids'], fn (DocumentoTesouraria $doc) => $this->documentos->desintegrar($doc, $d['motivo']), 'desintegrado(s)');
    }

    /** @return array{ids: list<int>, motivo: string} */
    private function lote(Request $r): array
    {
        return $r->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'], 'motivo' => ['required', 'string', 'min:5', 'max:500']],
            [], ['motivo' => 'motivo']);
    }

    private function executar(array $ids, callable $accao, string $verbo): JsonResponse
    {
        $ok = [];
        $erros = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $doc = DocumentoTesouraria::query()->find($id);
            if (! $doc) {
                $erros[] = ['id' => $id, 'numero_documento' => null, 'codigo' => 'DOCUMENTO_INEXISTENTE', 'mensagem' => 'Documento inexistente.'];

                continue;
            }
            try {
                $accao($doc);
                $ok[] = ['id' => $doc->id, 'numero_documento' => $doc->numero_documento];
            } catch (ErroNegocio $e) {
                $erros[] = ['id' => $doc->id, 'numero_documento' => $doc->numero_documento, 'codigo' => $e->codigo, 'mensagem' => $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                $erros[] = ['id' => $doc->id, 'numero_documento' => $doc->numero_documento, 'codigo' => 'ERRO_INTERNO', 'mensagem' => 'Erro inesperado: operação desfeita para este documento.'];
            }
        }

        return RespostaApi::sucesso(['ok' => $ok, 'erros' => $erros], count($ok)." documento(s) {$verbo}; ".count($erros).' com erro.');
    }
}
