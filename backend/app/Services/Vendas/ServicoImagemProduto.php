<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\Produto;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imagem do produto (paridade 4.1.5: «Imagem do Produto» do legado, mostrada nos cartões do POS).
 *
 * Segurança (OWASP A01/A04/A05/A08):
 *   - só imagens raster (JPEG, PNG, WebP; nada de SVG, que pode levar código), até 2 MB e 4000 × 4000 px — o tipo é o
 *     detectado pelo conteúdo (finfo/getimagesize), não o nome nem o Content-Type enviados;
 *   - nome gerado no servidor (UUID) e extensão derivada do tipo detectado; o nome original nunca é usado;
 *   - disco privado («local», fora do public/), numa pasta por empresa: empresas/{empresa}/produtos/{uuid}.{ext};
 *   - servida só por GET /api/vendas/produtos/{id}/imagem, com autenticação, empresa activa e permissão, e com
 *     X-Content-Type-Options: nosniff e Content-Disposition inline com nome neutro.
 * As imagens migradas do legado (produtos.imagem_base64, data URL) continuam a ser servidas enquanto não houver ficheiro.
 */
final class ServicoImagemProduto
{
    public const DISCO = 'local';

    public const TAMANHO_MAXIMO_KB = 2048;

    public const LADO_MAXIMO = 4000;

    /** Tipos aceites (detectados pelo conteúdo) → extensão gravada. */
    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    public function guardar(Produto $produto, UploadedFile $ficheiro): Produto
    {
        $empresa = $this->contexto->obrigatorio();
        if ((int) $produto->empresa_id !== $empresa) {   // defesa em profundidade (o âmbito global já filtra)
            throw new ErroNegocio('Produto inexistente.', 'PRODUTO_INEXISTENTE', 404);
        }
        $mime = $this->tipoReal($ficheiro);
        $extensao = self::TIPOS[$mime] ?? throw new ErroNegocio('Só são aceites imagens JPEG, PNG ou WebP.', 'IMAGEM_TIPO_INVALIDO', 422);
        $dimensoes = @getimagesize($ficheiro->getRealPath());
        if (! $dimensoes || $dimensoes[0] < 1 || $dimensoes[1] < 1) {
            throw new ErroNegocio('O ficheiro não é uma imagem válida.', 'IMAGEM_INVALIDA', 422);
        }
        if ($dimensoes[0] > self::LADO_MAXIMO || $dimensoes[1] > self::LADO_MAXIMO) {
            throw new ErroNegocio('A imagem não pode ter mais de '.self::LADO_MAXIMO.' × '.self::LADO_MAXIMO.' píxeis.', 'IMAGEM_GRANDE', 422);
        }

        $pasta = "empresas/{$empresa}/produtos";
        $nome = Str::uuid()->toString().'.'.$extensao;
        $caminho = Storage::disk(self::DISCO)->putFileAs($pasta, $ficheiro, $nome);
        if (! $caminho) {
            throw new ErroNegocio('Não foi possível guardar a imagem.', 'IMAGEM_NAO_GRAVADA', 500);
        }
        $anterior = $produto->imagem_caminho;
        try {
            DB::transaction(fn () => $produto->forceFill(['imagem_caminho' => $caminho, 'imagem_mime' => $mime, 'imagem_atualizada_em' => now()])->save());
        } catch (\Throwable $e) {
            Storage::disk(self::DISCO)->delete($caminho);
            throw $e;
        }
        if ($anterior && $anterior !== $caminho && $this->daEmpresa($anterior, $empresa)) {
            Storage::disk(self::DISCO)->delete($anterior);
        }

        return $produto->refresh();
    }

    /** Remove a imagem (ficheiro e, se for o caso, a imagem migrada do legado). */
    public function remover(Produto $produto): Produto
    {
        $empresa = $this->contexto->obrigatorio();
        $anterior = $produto->imagem_caminho;
        DB::transaction(fn () => $produto->forceFill(['imagem_caminho' => null, 'imagem_mime' => null, 'imagem_base64' => null, 'imagem_atualizada_em' => now()])->save());
        if ($anterior && $this->daEmpresa($anterior, $empresa)) {
            Storage::disk(self::DISCO)->delete($anterior);
        }

        return $produto->refresh();
    }

    /**
     * Conteúdo da imagem para servir: ficheiro ou, nos produtos migrados, a data URL do legado (só tipos raster aceites).
     *
     * @return array{conteudo: string, mime: string}|null
     */
    public function conteudo(Produto $produto): ?array
    {
        $empresa = $this->contexto->obrigatorio();
        $caminho = $produto->imagem_caminho;
        if ($caminho && $this->daEmpresa($caminho, $empresa) && Storage::disk(self::DISCO)->exists($caminho)) {
            $mime = in_array($produto->imagem_mime, array_keys(self::TIPOS), true) ? $produto->imagem_mime : 'application/octet-stream';

            return ['conteudo' => (string) Storage::disk(self::DISCO)->get($caminho), 'mime' => $mime];
        }

        return self::descodificarLegado($produto->imagem_base64);
    }

    public static function temImagem(Produto $produto): bool
    {
        return ! empty($produto->imagem_caminho) || ! empty($produto->imagem_base64);
    }

    /** URL (relativo à API) para o frontend/POS; inclui a versão para não ficar com uma imagem antiga em cache. */
    public static function url(Produto $produto): ?string
    {
        if (! self::temImagem($produto)) {
            return null;
        }
        $versao = $produto->imagem_atualizada_em ? strtotime((string) $produto->imagem_atualizada_em) : 0;

        return "/vendas/produtos/{$produto->id}/imagem?v={$versao}";
    }

    /**
     * Data URL do legado («data:image/png;base64,…») → bytes, só para JPEG/PNG/WebP e com o tipo confirmado pelo conteúdo.
     *
     * @return array{conteudo: string, mime: string}|null
     */
    public static function descodificarLegado(?string $dataUrl): ?array
    {
        if (! $dataUrl || ! preg_match('#^data:(image/(?:jpeg|jpg|png|webp));base64,([A-Za-z0-9+/=\s]+)$#', trim($dataUrl), $m)) {
            return null;
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::TAMANHO_MAXIMO_KB * 1024 * 4) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        return isset(self::TIPOS[$mime]) ? ['conteudo' => $bytes, 'mime' => $mime] : null;
    }

    private function tipoReal(UploadedFile $ficheiro): string
    {
        $caminho = $ficheiro->getRealPath();

        return $caminho ? ((new \finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: '') : '';
    }

    /** O caminho tem de estar na pasta da empresa (nunca se lê/apaga fora dela, nem com «..»). */
    private function daEmpresa(string $caminho, int $empresa): bool
    {
        return str_starts_with($caminho, "empresas/{$empresa}/produtos/") && ! str_contains($caminho, '..');
    }
}
