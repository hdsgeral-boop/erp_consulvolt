<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\DocumentoProjeto;
use App\Models\Projeto;
use App\Models\TipoDocumentoProjeto;
use App\Services\Projetos\ServicoDocumentosProjeto;
use App\Support\Api\RespostaApi;
use App\Support\Seguranca\ValidadorUploadSeguro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /api/projetos/{projeto}/documentos — separador «Documentos» (PDF). Ver: qualquer permissão de leitura de projectos;
 * carregar/nova versão/editar: gerir ou execução; eliminar: eliminar ou gerir; catálogo de tipos: gerir.
 */
final class DocumentoProjetoController extends Controller
{
    private const ESCREVER = ['proj_gerir', 'proj_execucao'];

    private const ELIMINAR = ['proj_eliminar', 'proj_gerir'];

    public function __construct(private readonly ServicoDocumentosProjeto $documentos) {}

    public function index(Request $r, int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);
        $f = $r->validate(['tipo' => ['nullable', 'integer'], 'pesquisa' => ['nullable', 'string', 'max:100']]);

        return RespostaApi::sucesso(['documentos' => $this->documentos->listar(Projeto::query()->findOrFail($projeto), $f['tipo'] ?? null, $f['pesquisa'] ?? null),
            'tipos' => $this->documentos->tipos(true)], 'Documentos do projecto.');
    }

    public function store(Request $r, int $projeto): JsonResponse
    {
        $this->exigir(...self::ESCREVER);
        $d = $this->validar($r);

        return RespostaApi::criado($this->documentos->carregar(Projeto::query()->findOrFail($projeto), $r->file('ficheiro'), $d), 'Documento carregado.');
    }

    public function versoes(int $projeto, int $documento): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->documentos->versoes($this->doc($projeto, $documento)), 'Versões do documento.');
    }

    public function novaVersao(Request $r, int $projeto, int $documento): JsonResponse
    {
        $this->exigir(...self::ESCREVER);
        $d = $this->validar($r);

        return RespostaApi::criado($this->documentos->carregar(Projeto::query()->findOrFail($projeto), $r->file('ficheiro'), $d, $this->doc($projeto, $documento)), 'Nova versão carregada.');
    }

    public function update(Request $r, int $projeto, int $documento): JsonResponse
    {
        $this->exigir(...self::ESCREVER);
        $d = $r->validate(['titulo' => ['sometimes', 'string', 'max:200'], 'tipo_projeto_id' => ['nullable', 'integer'], 'data_documento' => ['nullable', 'date'], 'observacoes' => ['nullable', 'string', 'max:2000']]);

        return RespostaApi::sucesso($this->documentos->actualizar($this->doc($projeto, $documento), $d), 'Documento actualizado.');
    }

    public function descarregar(int $projeto, int $documento): Response
    {
        $this->exigir(...ProjetoController::LER);
        $f = $this->documentos->descarregar($this->doc($projeto, $documento));

        return response($f['conteudo'], 200, ValidadorUploadSeguro::cabecalhosDownload($f['nome'], $f['mime']));
    }

    public function destroy(Request $r, int $projeto, int $documento): JsonResponse
    {
        $this->exigir(...self::ELIMINAR);
        $n = $this->documentos->eliminar($this->doc($projeto, $documento), $r->boolean('todas'));

        return RespostaApi::sucesso(['eliminados' => $n], 'Documento eliminado.');
    }

    // ───────────── Catálogo de tipos (por empresa) ─────────────

    public function tipos(): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->documentos->tipos(true), 'Tipos de documento do projecto.');
    }

    public function guardarTipo(Request $r, ?int $tipo = null): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['nome' => ['required', 'string', 'max:60'], 'ordem' => ['nullable', 'integer', 'between:0,999'], 'ativo' => ['nullable', 'boolean']]);
        $t = $this->documentos->guardarTipo($d, $tipo ? TipoDocumentoProjeto::query()->findOrFail($tipo) : null);

        return $tipo ? RespostaApi::sucesso($t, 'Tipo actualizado.') : RespostaApi::criado($t, 'Tipo criado.');
    }

    public function eliminarTipo(int $tipo): JsonResponse
    {
        $this->exigir('proj_gerir');

        return RespostaApi::sucesso(['resultado' => $this->documentos->eliminarTipo(TipoDocumentoProjeto::query()->findOrFail($tipo))], 'Tipo removido.');
    }

    private function doc(int $projeto, int $documento): DocumentoProjeto
    {
        return DocumentoProjeto::query()->where('projeto_id', $projeto)->whereNull('eliminado_em')->findOrFail($documento);
    }

    /** @return array<string, mixed> */
    private function validar(Request $r): array
    {
        return $r->validate(['ficheiro' => ['required', 'file'], 'titulo' => ['nullable', 'string', 'max:200'], 'tipo_projeto_id' => ['nullable', 'integer'],
            'data_documento' => ['nullable', 'date'], 'observacoes' => ['nullable', 'string', 'max:2000']]);
    }
}
