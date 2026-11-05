<?php

namespace App\Support\Seguranca;

use App\Exceptions\ErroNegocio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Validador central de ficheiros enviados (ADR-083). Reutilizado por TODOS os pontos de entrada: o middleware
 * `ValidarUploadsSeguros` aplica o perfil «geral» a cada ficheiro de cada pedido /api, e os serviços que guardam
 * ficheiros (imagens, anexos, comprovativos, folhas de importação) aplicam o perfil estrito do seu caso.
 *
 * Nunca confia no cliente: extensão, tipo MIME declarado e nome são tratados como não fiáveis. Verifica:
 *  - nome sem byte nulo, sem separadores de directório nem «..» (path traversal);
 *  - TODAS as extensões do nome (anti «.php.jpg», «.jpg.php», «.html.png») fora da lista de executáveis/scripts;
 *  - extensão final na lista branca do perfil e tamanho máximo;
 *  - tipo REAL pelo conteúdo (finfo/magic bytes) coerente com a extensão;
 *  - imagens: decodificáveis (getimagesize) e sem marcadores de script embutidos (polyglots GIF/JPEG+PHP);
 *  - PDF: sem JavaScript/Launch; folhas: ZIP sem «..», sem macros (vbaProject), sem bombas de descompressão;
 *  - texto/CSV/JSON/XML: sem bytes nulos, sem tags PHP/script e sem entidades externas (XXE).
 * O nome com que o ficheiro é guardado é sempre gerado aqui (`nomeAleatorio`), nunca o do cliente.
 */
final class ValidadorUploadSeguro
{
    /** Extensões que nunca entram, em qualquer posição do nome. */
    public const EXTENSOES_PROIBIDAS = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'cpl', 'jar', 'war', 'class', 'so', 'bin', 'dmg', 'apk', 'app',
        'js', 'mjs', 'cjs', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'psm1', 'sh', 'bash', 'zsh', 'csh', 'pl', 'py', 'rb', 'cgi', 'lua',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'hta', 'swf', 'asp', 'aspx', 'ascx', 'ashx', 'jsp', 'jspx', 'cfm',
        'htaccess', 'htpasswd', 'ini', 'lnk', 'url', 'reg', 'docm', 'xlsm', 'pptm', 'dotm', 'xlam',
    ];

    /** Tipos que o finfo atribui a chaves/certificados PEM (variam com a versão do libmagic). */
    private const MIME_CHAVES = ['text/plain', 'text/x-ssh-private-key', 'application/x-pem-file', 'text/x-pem-file', 'application/pkcs8', 'application/pkix-cert', 'application/x-x509-ca-cert', 'application/x-x509-user-cert', 'text/x-x509-certificate'];

    /** Extensão → tipos MIME reais aceites (conteúdo). */
    private const MIME_POR_EXTENSAO = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-unknown', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/octet-stream', 'text/x-csv'],
        'txt' => ['text/plain', 'text/csv', 'application/octet-stream'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        'json' => ['application/json', 'text/plain', 'text/json'],
        'xml' => ['text/xml', 'application/xml', 'text/plain'],
        'pem' => self::MIME_CHAVES, 'key' => self::MIME_CHAVES, 'crt' => self::MIME_CHAVES, 'cer' => self::MIME_CHAVES,
    ];

    /** Perfis: extensões permitidas e tamanho máximo (KB). */
    public const PERFIS = [
        'imagem' => ['ext' => ['jpg', 'jpeg', 'png', 'webp'], 'max_kb' => 5120],
        'imagem_gif' => ['ext' => ['jpg', 'jpeg', 'png', 'webp', 'gif'], 'max_kb' => 5120],
        'documento' => ['ext' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 'max_kb' => 5120],
        'pdf' => ['ext' => ['pdf'], 'max_kb' => 10240],
        'folha' => ['ext' => ['xlsx', 'xls', 'csv', 'txt'], 'max_kb' => 20480],
        'texto' => ['ext' => ['json', 'txt', 'csv', 'md', 'xml'], 'max_kb' => 51200],
        'copia' => ['ext' => ['json'], 'max_kb' => 512000],
        'chave' => ['ext' => ['pem', 'key', 'crt', 'cer', 'txt'], 'max_kb' => 64],
        // pedido genérico (middleware): a união dos perfis; cada serviço aperta depois
        'geral' => ['ext' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'xlsx', 'xls', 'csv', 'txt', 'md', 'json', 'xml', 'pem', 'key', 'crt', 'cer'], 'max_kb' => 51200],
    ];

    /** Marcadores de código embutido. Em binários só os de 5+ bytes (os de 3 bytes aparecem por acaso em MB de dados). */
    private const MARCADORES_SCRIPT = ['<?php', '<script', '#!/bin/', '#!/usr/bin'];

    private const MARCADORES_SCRIPT_TEXTO = ['<?=', '<%@', '<%=', '<?xml-stylesheet'];

    private const MAX_ENTRADAS_ZIP = 5000;

    private const MAX_DESCOMPRIMIDO = 300 * 1024 * 1024;

    private const RACIO_MAXIMO_ZIP = 150;

    /**
     * @throws ErroNegocio 422 com código FICHEIRO_* se o ficheiro não for seguro
     */
    public static function validar(UploadedFile $f, string $perfil = 'geral'): void
    {
        $cfg = self::PERFIS[$perfil] ?? throw new \InvalidArgumentException("Perfil de upload desconhecido: {$perfil}");
        if (! $f->isValid() || ! is_file((string) $f->getRealPath())) {
            self::recusar('FICHEIRO_INVALIDO', 'O envio do ficheiro falhou.');
        }
        $caminho = (string) $f->getRealPath();
        $tamanho = (int) filesize($caminho);
        if ($tamanho <= 0) {
            self::recusar('FICHEIRO_VAZIO', 'O ficheiro está vazio.');
        }
        if ($tamanho > $cfg['max_kb'] * 1024) {
            self::recusar('FICHEIRO_GRANDE', 'O ficheiro excede o tamanho máximo de '.round($cfg['max_kb'] / 1024, 1).' MB.');
        }

        $ext = self::validarNome($f->getClientOriginalName(), $cfg['ext']);

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: '';
        if (! in_array($mime, self::MIME_POR_EXTENSAO[$ext] ?? [], true)) {
            self::recusar('FICHEIRO_TIPO_INVALIDO', 'O conteúdo do ficheiro não corresponde ao tipo indicado.');
        }

        match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) => self::validarImagem($caminho, $ext),
            $ext === 'pdf' => self::validarPdf($caminho),
            $ext === 'xlsx' => self::validarZipOffice($caminho),
            $ext === 'xls' => self::validarOle($caminho),
            default => self::validarTexto($caminho, $ext),
        };
    }

    /** Códigos «moles»: o ficheiro não é perigoso, só não é do tipo/tamanho esperado — decide o endpoint que o recebe. */
    public const CODIGOS_MOLES = ['FICHEIRO_INVALIDO', 'FICHEIRO_VAZIO', 'FICHEIRO_GRANDE', 'FICHEIRO_SEM_EXTENSAO', 'FICHEIRO_EXTENSAO_INVALIDA', 'FICHEIRO_TIPO_INVALIDO', 'FICHEIRO_IMAGEM_INVALIDA'];

    /**
     * Como `validar`, mas os erros «moles» (tipo/tamanho/vazio) viram o código e a mensagem do próprio endpoint
     * (para não mudar o contrato da API); os erros de segurança (scripts, executáveis, PDF activo, zip bomb, XXE,
     * nomes com traversal…) passam tal e qual.
     */
    public static function validarOu(UploadedFile $f, string $perfil, string $codigoTipo, string $mensagemTipo, int $estado = 422): void
    {
        try {
            self::validar($f, $perfil);
        } catch (ErroNegocio $e) {
            if (in_array($e->codigo, self::CODIGOS_MOLES, true)) {
                throw new ErroNegocio($mensagemTipo, $codigoTipo, $estado);
            }

            throw $e;
        }
    }

    /**
     * Rede de segurança mínima (middleware global): nome, extensões proibidas em qualquer posição e código embutido
     * (`<?php`, `<script`…) nos formatos que não são comprimidos. Não impõe tipo nem tamanho: isso é do endpoint.
     */
    public static function validarMinimo(UploadedFile $f): void
    {
        $nome = $f->getClientOriginalName();
        if ($nome === '' || preg_match('/[\x00-\x1F\x7F]/', $nome) === 1 || str_contains($nome, '%00') || str_contains($nome, '..') || str_contains($nome, '/') || str_contains($nome, '\\')) {
            self::recusar('FICHEIRO_NOME_INVALIDO', 'O nome do ficheiro não é válido.');
        }
        $partes = explode('.', mb_strtolower(trim($nome, " \t.")));
        array_shift($partes);
        foreach ($partes as $p) {
            if (in_array(trim($p), self::EXTENSOES_PROIBIDAS, true)) {
                self::recusar('FICHEIRO_EXTENSAO_PROIBIDA', 'Este tipo de ficheiro não é aceite.');
            }
        }
        $ext = trim((string) end($partes));
        $caminho = (string) $f->getRealPath();
        if (! in_array($ext, ['xlsx', 'xls', 'pdf'], true) && $caminho !== '' && is_file($caminho) && filesize($caminho) > 0) {
            self::semMarcadoresDeScript($caminho, ! in_array($ext, ['csv', 'txt', 'json', 'md', 'xml', 'pem', 'key', 'crt', 'cer'], true));
        }
    }

    /** Valida só o nome (sem abrir o ficheiro) e devolve a extensão final em minúsculas. */
    public static function validarNome(string $nome, array $permitidas): string
    {
        if ($nome === '' || preg_match('/[\x00-\x1F\x7F]/', $nome) === 1 || str_contains($nome, '%00')) {
            self::recusar('FICHEIRO_NOME_INVALIDO', 'O nome do ficheiro não é válido.');
        }
        if (str_contains($nome, '/') || str_contains($nome, '\\') || str_contains($nome, '..') || preg_match('/%2e|%2f|%5c/i', $nome) === 1) {
            self::recusar('FICHEIRO_NOME_INVALIDO', 'O nome do ficheiro não é válido.');
        }
        $partes = explode('.', mb_strtolower(trim($nome, " \t.")));
        array_shift($partes);   // a primeira parte é o nome-base
        if ($partes === []) {
            self::recusar('FICHEIRO_SEM_EXTENSAO', 'O ficheiro tem de ter extensão.');
        }
        foreach ($partes as $p) {
            if (in_array(trim($p), self::EXTENSOES_PROIBIDAS, true)) {
                self::recusar('FICHEIRO_EXTENSAO_PROIBIDA', 'Este tipo de ficheiro não é aceite.');
            }
        }
        $ext = trim((string) end($partes));
        if (! in_array($ext, $permitidas, true)) {
            self::recusar('FICHEIRO_EXTENSAO_INVALIDA', 'Extensão não aceite. Aceites: '.implode(', ', $permitidas).'.');
        }

        return $ext;
    }

    /** Nome aleatório gerado no servidor (nunca o do cliente). */
    public static function nomeAleatorio(string $extensao): string
    {
        return Str::uuid()->toString().'.'.strtolower(preg_replace('/[^a-z0-9]/i', '', $extensao));
    }

    /** Cabeçalhos para servir um ficheiro enviado por utilizadores: descarregar, sem interpretação, sem execução. */
    public static function cabecalhosDownload(string $nomeNeutro, string $mime, bool $inline = false): array
    {
        $nome = preg_replace('/[^A-Za-z0-9._-]/', '_', $nomeNeutro) ?: 'ficheiro';

        return [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$nome.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ];
    }

    private static function validarImagem(string $caminho, string $ext): void
    {
        $info = @getimagesize($caminho);
        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] > 20000 || $info[1] > 20000) {
            self::recusar('FICHEIRO_IMAGEM_INVALIDA', 'A imagem não é válida.');
        }
        $permitidos = ['jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP, 'gif' => IMAGETYPE_GIF];
        if (($info[2] ?? null) !== $permitidos[$ext]) {
            self::recusar('FICHEIRO_TIPO_INVALIDO', 'O conteúdo da imagem não corresponde à extensão.');
        }
        self::semMarcadoresDeScript($caminho, true);
    }

    private static function validarPdf(string $caminho): void
    {
        $f = fopen($caminho, 'rb');
        $cabeca = $f ? (string) fread($f, 1024) : '';
        if ($f) {
            fclose($f);
        }
        if (! str_contains(substr($cabeca, 0, 1024), '%PDF-')) {
            self::recusar('FICHEIRO_TIPO_INVALIDO', 'O PDF não é válido.');
        }
        $c = (string) file_get_contents($caminho);
        if (preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b/', $c) === 1) {
            self::recusar('FICHEIRO_PDF_ACTIVO', 'O PDF contém conteúdo activo (scripts/anexos) e não é aceite.');
        }
    }

    private static function validarZipOffice(string $caminho): void
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho, ZipArchive::RDONLY) !== true) {
            self::recusar('FICHEIRO_TIPO_INVALIDO', 'A folha de cálculo não é válida.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRADAS_ZIP) {
                self::recusar('FICHEIRO_ZIP_SUSPEITO', 'A folha de cálculo tem uma estrutura inválida.');
            }
            $total = 0;
            $comprimido = max(1, (int) filesize($caminho));
            $temConteudos = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $nome = (string) ($st['name'] ?? '');
                if ($nome === '' || str_contains($nome, '..') || str_starts_with($nome, '/') || str_contains($nome, '\\') || str_contains($nome, "\0")) {
                    self::recusar('FICHEIRO_ZIP_SUSPEITO', 'A folha de cálculo contém caminhos inválidos.');
                }
                if (preg_match('/vbaProject\.bin$|\.(exe|dll|bat|js|vbs|ps1|sh|php)$/i', $nome) === 1) {
                    self::recusar('FICHEIRO_ZIP_SUSPEITO', 'A folha de cálculo contém macros ou executáveis.');
                }
                $temConteudos = $temConteudos || $nome === '[Content_Types].xml';
                $total += (int) ($st['size'] ?? 0);
                if ($total > self::MAX_DESCOMPRIMIDO || $total / $comprimido > self::RACIO_MAXIMO_ZIP) {
                    self::recusar('FICHEIRO_ZIP_SUSPEITO', 'A folha de cálculo excede os limites de descompressão.');
                }
                // XXE: nenhuma parte XML pode declarar entidades
                if (str_ends_with($nome, '.xml') && ($st['size'] ?? 0) < 2_000_000) {
                    $x = (string) $zip->getFromIndex($i);
                    if (stripos($x, '<!ENTITY') !== false) {
                        self::recusar('FICHEIRO_XML_PERIGOSO', 'A folha de cálculo contém entidades externas e não é aceite.');
                    }
                }
            }
            if (! $temConteudos) {
                self::recusar('FICHEIRO_TIPO_INVALIDO', 'A folha de cálculo não é um XLSX válido.');
            }
        } finally {
            $zip->close();
        }
    }

    private static function validarOle(string $caminho): void
    {
        $f = fopen($caminho, 'rb');
        $m = $f ? (string) fread($f, 8) : '';
        if ($f) {
            fclose($f);
        }
        if ($m !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            self::recusar('FICHEIRO_TIPO_INVALIDO', 'A folha XLS não é válida.');
        }
    }

    private static function validarTexto(string $caminho, string $ext): void
    {
        $c = (string) file_get_contents($caminho, false, null, 0, 52_428_800);
        if (str_contains($c, "\0")) {
            self::recusar('FICHEIRO_BINARIO', 'O ficheiro de texto contém dados binários.');
        }
        if (str_starts_with($c, "\xFF\xFE") || str_starts_with($c, "\xFE\xFF")) {
            self::recusar('FICHEIRO_TIPO_INVALIDO', 'Codificação do ficheiro não suportada (use UTF-8).');
        }
        self::semMarcadoresDeScript($caminho, false);
        if (in_array($ext, ['xml'], true) && stripos($c, '<!ENTITY') !== false) {
            self::recusar('FICHEIRO_XML_PERIGOSO', 'O XML contém entidades externas e não é aceite.');
        }
        if (in_array($ext, ['pem', 'key', 'crt', 'cer'], true) && ! str_contains($c, '-----BEGIN') && ! in_array($ext, ['cer'], true)) {
            self::recusar('FICHEIRO_TIPO_INVALIDO', 'O ficheiro de chave/certificado não está em formato PEM.');
        }
    }

    private static function semMarcadoresDeScript(string $caminho, bool $binario): void
    {
        $limite = $binario ? 8_388_608 : 52_428_800;
        $c = (string) file_get_contents($caminho, false, null, 0, $limite);
        $baixo = strtolower($c);
        foreach ($binario ? self::MARCADORES_SCRIPT : array_merge(self::MARCADORES_SCRIPT, self::MARCADORES_SCRIPT_TEXTO) as $m) {
            if (str_contains($baixo, $m)) {
                self::recusar('FICHEIRO_SCRIPT_EMBUTIDO', 'O ficheiro contém código embutido e não é aceite.');
            }
        }
    }

    private static function recusar(string $codigo, string $mensagem): never
    {
        throw new ErroNegocio($mensagem, $codigo, 422);
    }
}
