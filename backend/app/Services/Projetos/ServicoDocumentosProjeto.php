<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\DocumentoProjeto;
use App\Models\Projeto;
use App\Models\TipoDocumentoProjeto;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Vendas\ServicoImagemProduto;
use App\Support\Seguranca\ValidadorUploadSeguro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documentos do projecto: só PDF (finfo real, sem JavaScript/acção de arranque, até 10 MB — `ValidadorUploadSeguro`,
 * perfil `pdf`), com tipo, título, versão, data, autor e observações. O ficheiro vai para o disco de objectos
 * (Cloudflare R2 quando configurado, disco privado local nos restantes casos) sob `empresas/{empresa}/projetos/{projecto}/documentos/`;
 * na base ficam o caminho, o nome original, o tamanho, o SHA-256 e o MIME. Nunca há URL público: a descarga é sempre
 * feita pelo endpoint autenticado (`attachment` + `nosniff`). Eliminação lógica; upload, descarga e eliminação auditados.
 */
final class ServicoDocumentosProjeto
{
    /** Catálogo inicial por empresa (editável). */
    public const TIPOS_INICIAIS = ['Contrato', 'Proposta', 'Relatório', 'Acta', 'Entregável', 'Factura', 'Outro'];

    public function __construct(private readonly ContextoEmpresa $contexto, private readonly ServicoAuditoria $auditoria) {}

    // ───────────── Tipos ─────────────

    /** @return list<array<string, mixed>> */
    public function tipos(bool $incluirInactivos = false): array
    {
        if (! TipoDocumentoProjeto::query()->exists()) {
            foreach (self::TIPOS_INICIAIS as $i => $nome) {
                TipoDocumentoProjeto::create(['nome' => $nome, 'ordem' => $i, 'ativo' => true]);
            }
        }

        return TipoDocumentoProjeto::query()->when(! $incluirInactivos, fn ($q) => $q->where('ativo', true))->orderBy('ordem')->orderBy('nome')->get()->toArray();
    }

    /** @param  array{nome: string, ordem?: int|null, ativo?: bool|null}  $d */
    public function guardarTipo(array $d, ?TipoDocumentoProjeto $tipo = null): TipoDocumentoProjeto
    {
        $nome = trim($d['nome']);
        if (TipoDocumentoProjeto::query()->whereRaw('lower(nome) = ?', [mb_strtolower($nome)])->when($tipo, fn ($q) => $q->where('id', '<>', $tipo->id))->exists()) {
            throw new ErroNegocio('Já existe um tipo de documento com esse nome.', 'TIPO_DOCUMENTO_DUPLICADO', 422);
        }
        $dados = ['nome' => $nome] + array_filter(['ordem' => $d['ordem'] ?? null, 'ativo' => $d['ativo'] ?? null], fn ($v) => $v !== null);

        if ($tipo) {
            $tipo->update($dados);

            return $tipo;
        }

        return TipoDocumentoProjeto::create($dados + ['ordem' => 99]);
    }

    /** Tipo em uso só se desactiva (os documentos mantêm a classificação); sem uso, apaga-se. */
    public function eliminarTipo(TipoDocumentoProjeto $tipo): string
    {
        if (DocumentoProjeto::query()->where('tipo_projeto_id', $tipo->id)->exists()) {
            $tipo->update(['ativo' => false]);

            return 'desactivado';
        }
        $tipo->delete();

        return 'eliminado';
    }

    // ───────────── Documentos ─────────────

    /**
     * Versão mais recente de cada documento (não eliminado), com o nº de versões.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(Projeto $projeto, ?int $tipo = null, ?string $pesquisa = null): array
    {
        $todos = DocumentoProjeto::query()->where('projeto_id', $projeto->id)->whereNull('eliminado_em')->orderByDesc('versao')->orderByDesc('id')->get();
        $porGrupo = $todos->groupBy(fn ($d) => $d->grupo_uid ?? "id{$d->id}");

        return $porGrupo->map(fn ($versoes) => $versoes->first()->toArray() + ['n_versoes' => $versoes->count()])
            ->filter(fn ($d) => (! $tipo || (int) $d['tipo_projeto_id'] === $tipo)
                && (! $pesquisa || mb_stripos(($d['titulo'] ?? '').' '.($d['nome_ficheiro'] ?? '').' '.($d['observacoes'] ?? ''), $pesquisa) !== false))
            ->sortByDesc('id')->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function versoes(DocumentoProjeto $doc): array
    {
        return DocumentoProjeto::query()->where('projeto_id', $doc->projeto_id)->whereNull('eliminado_em')
            ->when($doc->grupo_uid, fn ($q) => $q->where('grupo_uid', $doc->grupo_uid), fn ($q) => $q->where('id', $doc->id))
            ->orderByDesc('versao')->get()->toArray();
    }

    /**
     * @param  array{titulo?: string|null, tipo_projeto_id?: int|null, data_documento?: string|null, observacoes?: string|null}  $d
     */
    public function carregar(Projeto $projeto, UploadedFile $ficheiro, array $d, ?DocumentoProjeto $anterior = null): DocumentoProjeto
    {
        $empresa = $this->contexto->obrigatorio();
        if ((int) $projeto->empresa_id !== $empresa) {
            throw new ErroNegocio('Projecto de outra empresa.', 'PROJECTO_INVALIDO', 403);
        }
        ValidadorUploadSeguro::validarOu($ficheiro, 'pdf', 'DOCUMENTO_TIPO_INVALIDO', 'Só são aceites ficheiros PDF até 10 MB.');
        $tipo = $d['tipo_projeto_id'] ?? $anterior?->tipo_projeto_id;
        if ($tipo && ! TipoDocumentoProjeto::query()->whereKey($tipo)->exists()) {
            throw new ErroNegocio('Tipo de documento inválido.', 'TIPO_DOCUMENTO_INVALIDO', 422);
        }

        $real = (string) $ficheiro->getRealPath();
        $hash = hash_file('sha256', $real);
        $tamanho = (int) filesize($real);
        $nomeOriginal = mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', pathinfo($ficheiro->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'documento', 0, 200).'.pdf';

        $disco = Storage::disk(ServicoImagemProduto::disco());
        $caminho = $disco->putFileAs("empresas/{$empresa}/projetos/{$projeto->id}/documentos", $ficheiro, ValidadorUploadSeguro::nomeAleatorio('pdf'));
        if (! $caminho) {
            throw new ErroNegocio('Não foi possível guardar o documento.', 'DOCUMENTO_NAO_GRAVADO', 500);
        }
        try {
            $doc = DB::transaction(function () use ($projeto, $d, $anterior, $tipo, $caminho, $hash, $tamanho, $nomeOriginal) {
                $grupo = $anterior?->grupo_uid ?? (string) Str::uuid();
                if ($anterior && ! $anterior->grupo_uid) {
                    $anterior->update(['grupo_uid' => $grupo]);
                }
                $versao = $anterior ? ((int) DocumentoProjeto::query()->where('grupo_uid', $grupo)->max('versao')) + 1 : 1;
                $titulo = trim((string) ($d['titulo'] ?? '')) ?: ($anterior?->titulo ?? pathinfo($nomeOriginal, PATHINFO_FILENAME));

                return DocumentoProjeto::create([
                    'projeto_id' => $projeto->id, 'tipo_projeto_id' => $tipo, 'titulo' => mb_substr($titulo, 0, 200),
                    'nome_ficheiro' => $nomeOriginal, 'data_carregamento' => now()->toDateString(), 'data_documento' => $d['data_documento'] ?? now()->toDateString(),
                    'versao' => $versao, 'grupo_uid' => $grupo, 'autor' => Auth::user()?->nome_utilizador, 'observacoes' => $d['observacoes'] ?? null,
                    'caminho' => $caminho, 'mime' => 'application/pdf', 'tamanho_bytes' => $tamanho, 'hash_sha256' => $hash,
                ]);
            });
        } catch (\Throwable $e) {
            $disco->delete($caminho);
            throw $e;
        }
        $this->auditoria->registar('Projectos', $anterior ? 'Documento: nova versão' : 'Documento carregado', "Projecto {$projeto->codigo} · {$doc->titulo} v{$doc->versao} ({$nomeOriginal}, {$tamanho} B)", 'documentos_projeto', $doc->id);

        return $doc->fresh();
    }

    /** @param  array{titulo?: string|null, tipo_projeto_id?: int|null, data_documento?: string|null, observacoes?: string|null}  $d */
    public function actualizar(DocumentoProjeto $doc, array $d): DocumentoProjeto
    {
        if (! empty($d['tipo_projeto_id']) && ! TipoDocumentoProjeto::query()->whereKey($d['tipo_projeto_id'])->exists()) {
            throw new ErroNegocio('Tipo de documento inválido.', 'TIPO_DOCUMENTO_INVALIDO', 422);
        }
        $doc->update(array_intersect_key($d, array_flip(['titulo', 'tipo_projeto_id', 'data_documento', 'observacoes'])));

        return $doc->fresh();
    }

    /** @return array{conteudo: string, nome: string, mime: string} */
    public function descarregar(DocumentoProjeto $doc): array
    {
        $empresa = $this->contexto->obrigatorio();
        $disco = Storage::disk(ServicoImagemProduto::disco());
        if ($doc->eliminado_em || ! $doc->caminho || ! str_starts_with($doc->caminho, "empresas/{$empresa}/projetos/{$doc->projeto_id}/documentos/") || ! $disco->exists($doc->caminho)) {
            throw new ErroNegocio('Documento não encontrado.', 'DOCUMENTO_NAO_ENCONTRADO', 404);
        }
        $this->auditoria->registar('Projectos', 'Documento descarregado', "{$doc->titulo} v{$doc->versao} (#{$doc->id})", 'documentos_projeto', $doc->id);

        return ['conteudo' => (string) $disco->get($doc->caminho), 'nome' => $doc->nome_ficheiro ?: 'documento.pdf', 'mime' => 'application/pdf'];
    }

    /** Eliminação lógica; `todas` elimina todas as versões do documento. O ficheiro mantém-se no disco (recuperável). */
    public function eliminar(DocumentoProjeto $doc, bool $todas = false): int
    {
        $alvo = DocumentoProjeto::query()->where('projeto_id', $doc->projeto_id)->whereNull('eliminado_em')
            ->when($todas && $doc->grupo_uid, fn ($q) => $q->where('grupo_uid', $doc->grupo_uid), fn ($q) => $q->where('id', $doc->id))->get();
        foreach ($alvo as $d) {
            $d->update(['eliminado_em' => now(), 'eliminado_por' => Auth::user()?->nome_utilizador]);
            $this->auditoria->registar('Projectos', 'Documento eliminado', "{$d->titulo} v{$d->versao} (#{$d->id})", 'documentos_projeto', $d->id);
        }

        return $alvo->count();
    }
}
