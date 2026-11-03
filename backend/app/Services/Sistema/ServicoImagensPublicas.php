<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imagens públicas no Cloudflare R2 (logótipos das empresas, logótipo do ecrã de entrada e imagens de produtos).
 *
 * A base de dados guarda só o URL público (https://…), nunca a imagem: o navegador lê-a directamente da Cloudflare
 * (CDN, sem passar pelo servidor da aplicação). Enquanto o disco não estiver configurado (config erp.imagens.disco
 * vazio), os serviços mantêm o comportamento antigo (data URI na base / ficheiro privado) e nada muda.
 *
 * Segurança: só imagens raster (PNG, JPEG, GIF, WEBP) com o tipo confirmado pelo CONTEÚDO (finfo), nome gerado no
 * servidor (hash do conteúdo, que também serve de versão para a cache do navegador) e extensão derivada do tipo real.
 */
final class ServicoImagensPublicas
{
    /** Tipos aceites (detectados pelo conteúdo) → extensão gravada. */
    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public static function nomeDisco(): ?string
    {
        $disco = trim((string) config('erp.imagens.disco', ''));

        return $disco !== '' ? $disco : null;
    }

    /** O disco público está configurado (ERP_IMAGENS_DISCO=imagens)? */
    public static function activo(): bool
    {
        return self::nomeDisco() !== null;
    }

    public function disco(): Filesystem
    {
        $nome = self::nomeDisco() ?? throw new ErroNegocio('O armazenamento de imagens (Cloudflare R2) não está configurado.', 'IMAGENS_NAO_CONFIGURADAS', 500);

        return Storage::disk($nome);
    }

    /** O valor guardado é um URL público (http/https), por oposição a uma data URI ou a um caminho relativo. */
    public static function eUrl(?string $valor): bool
    {
        return is_string($valor) && preg_match('#^https?://[^\s"\'<>]+$#i', $valor) === 1;
    }

    /**
     * Data URI («data:image/png;base64,…») → bytes + tipo real, ou null se não for uma imagem raster aceite.
     *
     * @return array{conteudo: string, mime: string, extensao: string}|null
     */
    public static function descodificarDataUri(?string $dataUri, int $tamanhoMaximo = 1048576): ?array
    {
        if (! $dataUri || ! preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=\s]+)$#', trim($dataUri), $m)) {
            return null;
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > $tamanhoMaximo) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        return isset(self::TIPOS[$mime]) ? ['conteudo' => $bytes, 'mime' => $mime, 'extensao' => self::TIPOS[$mime]] : null;
    }

    /**
     * Grava bytes de uma imagem no disco público e devolve o URL. O nome inclui o hash do conteúdo: uma imagem nova tem
     * sempre um URL novo (cache do navegador/CDN nunca fica com a antiga) e a mesma imagem nunca é duplicada.
     */
    public function guardarBytes(string $bytes, string $mime, string $pasta, string $nomeBase): string
    {
        $extensao = self::TIPOS[$mime] ?? throw new ErroNegocio('Só são aceites imagens PNG, JPEG, GIF ou WEBP.', 'IMAGEM_TIPO_INVALIDO', 422);
        $pasta = trim($pasta, '/');
        $caminho = "{$pasta}/".Str::slug($nomeBase).'-'.substr(hash('sha256', $bytes), 0, 16).'.'.$extensao;
        $disco = $this->disco();
        if (! $disco->exists($caminho) && ! $disco->put($caminho, $bytes, ['ContentType' => $mime, 'CacheControl' => 'public, max-age=31536000, immutable'])) {
            throw new ErroNegocio('Não foi possível guardar a imagem no armazenamento público.', 'IMAGEM_NAO_GRAVADA', 500);
        }

        return $disco->url($caminho);
    }

    /** Data URI validada (ServicoGestaoEmpresas::validarLogotipo) → ficheiro no disco público; devolve o URL. */
    public function guardarDataUri(string $dataUri, string $pasta, string $nomeBase, int $tamanhoMaximo = 1048576): string
    {
        $img = self::descodificarDataUri($dataUri, $tamanhoMaximo)
            ?? throw new ErroNegocio('A imagem é inválida ou tem mais de '.round($tamanhoMaximo / 1048576).' MB.', 'IMAGEM_INVALIDA', 422);

        return $this->guardarBytes($img['conteudo'], $img['mime'], $pasta, $nomeBase);
    }

    /** Caminho (chave no bucket) de um URL público deste disco, ou null se o URL não for deste disco. */
    public function caminhoDeUrl(?string $url): ?string
    {
        if (! self::eUrl($url)) {
            return null;
        }
        $base = rtrim((string) $this->disco()->url(''), '/');
        if ($base === '' || ! str_starts_with((string) $url, $base.'/')) {
            return null;
        }
        $caminho = substr((string) $url, strlen($base) + 1);

        return $caminho !== '' && ! str_contains($caminho, '..') ? $caminho : null;
    }

    /** Apaga o objecto de um URL público deste disco (URLs de outros sítios são ignorados). Nunca falha o pedido. */
    public function apagarPorUrl(?string $url): void
    {
        $caminho = $this->caminhoDeUrl($url);
        if ($caminho === null) {
            return;
        }
        try {
            $this->disco()->delete($caminho);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
