<?php

namespace App\Support\Seguranca;

/**
 * Detecção CONSERVADORA de padrões evidentes de injecção em parâmetros de pesquisa/filtro/ordenação (ADR-083).
 *
 * Isto é uma segunda linha de defesa: a primeira é não concatenar SQL (bindings, listas brancas de colunas).
 * Por isso as regras só apanham ataques inequívocos e deixam passar texto legítimo: «O'Brien», «D'Avila»,
 * «select» isolado, «Pedro and Maria», «A-B--C», «Fatura -- Janeiro», «union» sozinho.
 */
final class DetectorInjecao
{
    /** Parâmetros tratados como pesquisa/filtro/ordenação (nome exacto ou prefixo). */
    private const NOMES_EXACTOS = ['q', 'pesquisa', 'search', 'busca', 'termo', 'texto_pesquisa', 'ordem', 'sort', 'order', 'order_by', 'orderby', 'direcao', 'direccao', 'sort_by', 'sortby'];

    private const PREFIXOS = ['filtro', 'ordenar', 'ordenacao', 'pesquisa_', 'search_', 'q_'];

    /** Nomes que são um texto simples: nunca aceitam listas/objectos. */
    private const ESCALARES = ['q', 'pesquisa', 'search', 'busca', 'termo', 'sort', 'order_by', 'orderby', 'direcao', 'direccao', 'sort_by', 'sortby'];   // «ordem»/«order» podem ser listas de ids (reordenar)

    public const COMPRIMENTO_MAXIMO = 300;

    /** @var list<string> */
    private const PADROES_SQL = [
        '/;\s*(drop|delete|insert|update|alter|truncate|create|grant|revoke|exec|execute|select|copy|call|do)\b/i',
        '/\bunion\b\s+(all\s+|distinct\s+)?select\b/i',
        '/[\'"`]\s*\)?\s*(or|and|\|\||&&)\s*[\'"`(]?\s*[\w.]+\s*[\'"`]?\s*(=|<|>|like\b|is\b|in\b)/i',
        '/\b(or|and)\s+[\'"`]?(\d+|true|false)[\'"`]?\s*(=|<>|!=)\s*[\'"`]?(\d+|true|false)\b/i',
        '/[\'")]\s*(--|#)\s*$/',
        '/;\s*--/',
        '/\/\*.*?\*\//s',
        '/\b(pg_sleep|pg_read_file|pg_ls_dir|lo_import|lo_export|dblink|generate_series)\s*\(/i',
        '/\b(sleep|benchmark|load_file|extractvalue|updatexml|waitfor\s+delay|xp_cmdshell)\b\s*[(\']/i',
        '/\binto\s+(outfile|dumpfile)\b/i',
        '/\b(information_schema|pg_catalog|pg_shadow|pg_user|sqlite_master|sys\.objects)\b/i',
        '/\bcurrent_setting\s*\(|\bpg_sleep\b|\bversion\s*\(\s*\)/i',
        '/\b(select|case)\b[^;]{0,80}\bfrom\b[^;]{0,80}\bwhere\b[^;]{0,80}(=|like)/i',
    ];

    /** @var list<string> */
    private const PADROES_OUTROS = [
        '/(\.\.[\/\\\\])|([\/\\\\]\.\.)|(%2e%2e)|(\.\.%2f)|(\.\.%5c)/i',      // path traversal
        '/<\s*script\b|javascript\s*:|\bon(error|load|click|mouseover)\s*=|<\s*iframe\b|<\s*svg\b[^>]*\bon\w+\s*=/i',  // XSS evidente
        '/\{\s*["\']?\$(ne|gt|gte|lt|lte|in|nin|regex|where|expr|or|and|exists|eq)\b/i',   // operadores NoSQL em texto
        '/\$\{|\{\{.*\}\}|<%[=-]?|#\{/',                                            // templates/expressões (SSTI)
        '/\$\(\s*[a-z]+|`[^`]+`|\|\s*(cat|ls|nc|bash|sh|curl|wget)\b|;\s*(cat|ls|rm|nc|bash|sh|curl|wget)\b/i',  // comando de shell evidente
        '/\b(php|file|data|expect|phar|gopher|dict|ldap|ftp):\/\//i',                // esquemas perigosos
    ];

    /** Este parâmetro é de pesquisa/filtro/ordenação? */
    public static function eParametroAlvo(string $nome): bool
    {
        $n = strtolower($nome);
        if (in_array($n, self::NOMES_EXACTOS, true)) {
            return true;
        }
        foreach (self::PREFIXOS as $p) {
            if (str_starts_with($n, $p)) {
                return true;
            }
        }

        return false;
    }

    public static function eEscalar(string $nome): bool
    {
        return in_array(strtolower($nome), self::ESCALARES, true);
    }

    /**
     * Primeiro motivo de recusa para um valor de pesquisa (ou null se estiver bem).
     */
    public static function motivo(string $valor): ?string
    {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $valor) === 1 || str_contains($valor, '%00')) {
            return 'caracteres de controlo';
        }
        if (! mb_check_encoding($valor, 'UTF-8')) {
            return 'codificação inválida';
        }
        if (mb_strlen($valor) > self::COMPRIMENTO_MAXIMO) {
            return 'demasiado longo';
        }
        foreach (self::PADROES_SQL as $p) {
            if (preg_match($p, $valor) === 1) {
                return 'padrão de SQL';
            }
        }
        foreach (self::PADROES_OUTROS as $p) {
            if (preg_match($p, $valor) === 1) {
                return 'padrão de injecção';
            }
        }

        return null;
    }

    /** Normaliza Unicode (NFC) e remove caracteres invisíveis de formatação (zero-width, BOM, bidi) de um texto de pesquisa. */
    public static function normalizar(string $valor): string
    {
        $v = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $valor) ?? $valor;
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($v, \Normalizer::FORM_C);
            if (is_string($n)) {
                $v = $n;
            }
        }

        return $v;
    }
}
