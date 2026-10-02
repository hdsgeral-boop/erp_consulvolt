<?php

namespace App\Services\Migracao;

use Normalizer;

/**
 * Conversão de valores do legado (JS/Dexie) para os tipos PostgreSQL do contrato do esquema.
 * Nunca inventa valores: quando um valor não é convertível devolve null e regista uma ocorrência.
 * Excepção documentada: datas só com mês ("2026-08") passam ao dia 1, preservando o período em que
 * o legado as contabilizava (filtros por startsWith('2026-08')) — sempre com ocorrência.
 */
final class ConversorTipos
{
    /** @var callable(string, string, mixed, ?string, string): void */
    private $aoOcorrer;

    public function __construct(callable $aoOcorrer)
    {
        $this->aoOcorrer = $aoOcorrer;
    }

    /** Valores que o legado usa como "sem valor". */
    public static function vazio(mixed $v): bool
    {
        return $v === null || $v === '' || $v === 'NaN' || $v === 'undefined' || $v === 'null' || (is_float($v) && is_nan($v));
    }

    /**
     * Espaços Unicode (não ASCII) sem significado nas pontas de um texto: espaço não separável (U+00A0), espaços tipográficos
     * U+2000-U+200A, U+202F, U+205F, U+3000, U+1680, separadores de linha/parágrafo e U+0085.
     */
    private const ESPACOS_UNICODE = '\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    /**
     * Colunas de código (contas, produtos, terceiros, centros, diários, moedas…): os espaços nas pontas nunca fazem parte do
     * código e criavam códigos «gémeos» (ex.: conta «75216 » com U+00A0 ao lado da 75216; 47 códigos de produto com espaços).
     */
    public static function colunaDeCodigo(?string $coluna): bool
    {
        return $coluna !== null && preg_match('/^codigo(_|$)|_codigo$|^numero_conta$|^conta(_|$)|_conta$/', $coluna) === 1;
    }

    /**
     * @param  ?string  $coluna  coluna de destino; nas colunas de código (colunaDeCodigo) também se retiram os espaços ASCII
     *                           (espaço, tabulação, mudança de linha) das pontas
     */
    public function converter(mixed $v, string $tipo, ?string $coluna = null): mixed
    {
        if (self::vazio($v)) {
            return null;
        }
        if (self::colunaDeCodigo($coluna) && ! is_array($v) && (str_starts_with($tipo, 'varchar') || $tipo === 'text')) {
            $v = $this->aparar(is_bool($v) ? ($v ? 'true' : 'false') : (is_float($v) ? json_encode($v) : (string) $v), true);
            if ($v === '') {
                return null;
            }
        }

        return match (true) {
            $tipo === 'bigint', $tipo === 'integer' => $this->inteiro($v),
            str_starts_with($tipo, 'numeric') => $this->arredondar($this->numerico($v), (int) (preg_match('/,(\d+)\)$/', $tipo, $m) ? $m[1] : 2)),
            $tipo === 'boolean' => $this->booleano($v),
            $tipo === 'date' => $this->data($v),
            $tipo === 'timestamptz' => $this->dataHora($v),
            $tipo === 'jsonb' => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            str_starts_with($tipo, 'varchar') => $this->texto($v, (int) substr($tipo, 8, -1)),
            default => $this->texto($v, null),
        };
    }

    /** Id de FK: inteiro positivo ou null (0, "", NaN = sem valor no legado). */
    public function chave(mixed $v): ?int
    {
        if (self::vazio($v) || $v === 0 || $v === '0' || $v === false) {
            return null;
        }
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_float($v) && $v > 0 && floor($v) === $v) {
            return (int) $v;
        }
        if (is_string($v) && ctype_digit(trim($v)) && (int) trim($v) > 0) {
            return (int) trim($v);
        }

        return null;
    }

    /** Dobra um texto para comparação com os mapas de normalização (igual a normalizacoes.mjs: dobrar). */
    public static function dobrar(string $s): string
    {
        $d = Normalizer::normalize($s, Normalizer::FORM_D) ?: $s;
        $d = preg_replace('/[\p{Mn}\x{FFFD}]+/u', '', $d);
        $d = preg_replace('/[\s_-]+/u', ' ', $d);

        return mb_strtoupper(trim($d));
    }

    private function inteiro(mixed $v): ?int
    {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v) && floor($v) === $v) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/^\s*-?\d+\s*$/', $v)) {
            return (int) $v;
        }
        $this->ocorrer('CONVERSAO', 'AVISO', $v, null, 'Valor não inteiro numa coluna inteira');

        return null;
    }

    private function numerico(mixed $v): ?string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return json_encode($v);   // representação exacta (serialize_precision = -1)
        }
        if (is_string($v)) {
            $t = str_replace([' ', "\u{00A0}"], '', trim($v));
            if (preg_match('/^-?\d+(,\d+)?$/', $t)) {
                $t = str_replace(',', '.', $t);
            } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $t)) {   // 1.234.567,89
                $t = str_replace(['.', ','], ['', '.'], $t);
            } elseif (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $t)) {   // 1,234,567.89
                $t = str_replace(',', '', $t);
            }
            if (is_numeric($t)) {
                return $t;
            }
        }
        $this->ocorrer('CONVERSAO', 'AVISO', $v, null, 'Valor não numérico numa coluna numérica');

        return null;
    }

    /**
     * Arredonda à escala da coluna (decisão 2026-09-29: dinheiro a 2 casas decimais, directiva NUMERIC(15,2)).
     * O legado guarda floats JS: o ruído binário (261.6700000000001) desaparece sem registo; fracções de
     * cêntimo reais (conversões cambiais/percentagens) geram ocorrência INFO com o valor original.
     * Arredondamento "half away from zero", igual ao do PostgreSQL para NUMERIC.
     */
    private function arredondar(?string $valor, int $escala): ?string
    {
        if ($valor === null) {
            return null;
        }
        $arredondado = number_format(round((float) $valor, $escala, PHP_ROUND_HALF_UP), $escala, '.', '');
        if (abs((float) $valor - (float) $arredondado) > 1e-6) {
            $this->ocorrer('ARREDONDAMENTO', 'INFO', $valor, $arredondado, "Fracção abaixo da escala da coluna ({$escala} casas) arredondada");
        }

        return $arredondado;
    }

    private function booleano(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 1 || $v === 0) {
            return $v === 1;
        }
        $t = is_string($v) ? mb_strtolower(trim($v)) : null;
        if (in_array($t, ['1', 'true', 'sim', 's', 'yes'], true)) {
            return true;
        }
        if (in_array($t, ['0', 'false', 'não', 'nao', 'n', 'no'], true)) {
            return false;
        }
        $this->ocorrer('CONVERSAO', 'AVISO', $v, null, 'Valor não booleano numa coluna booleana');

        return null;
    }

    private function data(mixed $v): ?string
    {
        if (is_string($v)) {
            $t = trim($v);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return "{$m[1]}-{$m[2]}-{$m[3]}";
            }
            if (preg_match('#^(\d{2})[-/](\d{2})[-/](\d{4})$#', $t, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            if (preg_match('/^(\d{4})-(\d{2})$/', $t, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
                $this->ocorrer('DATA_SO_MES', 'AVISO', $v, "{$m[1]}-{$m[2]}-01", 'Data só com mês: assumido o dia 1 (o legado incluía-a nesse mês)');

                return "{$m[1]}-{$m[2]}-01";
            }
        }
        if (is_int($v) || is_float($v)) {
            return gmdate('Y-m-d', (int) ($v > 1e11 ? $v / 1000 : $v));
        }
        $this->ocorrer('CONVERSAO', 'AVISO', $v, null, 'Valor não é uma data válida');

        return null;
    }

    private function dataHora(mixed $v): ?string
    {
        if (is_string($v)) {
            $t = trim($v);
            if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/', $t)) {
                return str_replace('T', ' ', $t);
            }
            if (preg_match('#^(\d{2})[-/](\d{2})[-/](\d{4})( \d{2}:\d{2}(:\d{2})?)?$#', $t, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return "{$m[3]}-{$m[2]}-{$m[1]}".($m[4] ?? '');
            }
            if (preg_match('/^\d{4}-\d{2}$/', $t)) {
                return $this->data($t);   // só com mês: mesma regra (dia 1 + ocorrência)
            }
        }
        if (is_int($v) || is_float($v)) {
            $segundos = $v > 1e11 ? $v / 1000 : $v;   // epoch em ms (Date.now()) ou em s

            return gmdate('Y-m-d H:i:s', (int) $segundos).'+00';
        }
        $this->ocorrer('CONVERSAO', 'AVISO', $v, null, 'Valor não é uma data/hora válida');

        return null;
    }

    private function texto(mixed $v, ?int $maximo): string
    {
        $s = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (is_bool($v) ? ($v ? 'true' : 'false') : (is_float($v) ? json_encode($v) : (string) $v));

        // Caracteres invisíveis (espaço de largura zero, BOM, word joiner) colados ao copiar/colar no legado:
        // criavam contas "fantasma" (ex.: "\u{200B}4311" ao lado de "4311") e lançamentos em códigos inexistentes.
        $limpo = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $s);
        if ($limpo !== null && $limpo !== $s) {
            $this->ocorrer('NORMALIZACAO', 'INFO', $s, $limpo, 'Caracteres invisíveis removidos (espaço de largura zero/BOM)');
            $s = $limpo;
        }
        // Espaços Unicode nas pontas (U+00A0 e afins) nunca têm significado, em nenhum texto (E-CON-2: conta «75216 » migrada)
        $s = $this->aparar($s, false);

        if ($maximo !== null && mb_strlen($s) > $maximo) {
            $this->ocorrer('TRUNCAGEM', 'ERRO', $s, mb_substr($s, 0, $maximo), "Texto com mais de {$maximo} caracteres truncado (original na ocorrência)");

            return mb_substr($s, 0, $maximo);
        }

        return $s;
    }

    /** Retira das pontas os espaços Unicode (e, com $ascii, também os ASCII), registando a normalização. */
    private function aparar(string $s, bool $ascii): string
    {
        $classe = self::ESPACOS_UNICODE.($ascii ? '\s' : '');
        $limpo = preg_replace('/^['.$classe.']+|['.$classe.']+$/u', '', $s);
        if ($limpo !== null && $limpo !== $s) {
            $this->ocorrer('NORMALIZACAO', 'INFO', $s, $limpo, $ascii ? 'Espaços retirados das pontas de um código' : 'Espaços Unicode (ex.: U+00A0) retirados das pontas');

            return $limpo;
        }

        return $s;
    }

    private function ocorrer(string $regra, string $gravidade, mixed $valor, ?string $final, string $descricao): void
    {
        ($this->aoOcorrer)($regra, $gravidade, $valor, $final, $descricao);
    }
}
