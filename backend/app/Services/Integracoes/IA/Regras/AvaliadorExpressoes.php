<?php

namespace App\Services\Integracoes\IA\Regras;

/**
 * Avaliador seguro das expressões dos ficheiros de regras do assistente IA (legado js/ia_regras_ficheiro.js:31-78).
 *
 * Só aceita números, variáveis, + - * / ( ) e as funções round, abs, min, max e sum. Não há `eval` nem chamadas a funções
 * do PHP: é um analisador descendente recursivo sobre o texto, com limites de comprimento e de profundidade.
 *
 * Correcção face ao legado: a vírgula decimal («1,5») só é aceite fora dos argumentos de uma função — no legado,
 * `max(1,2)` era lido como `max(1.2)`.
 */
final class AvaliadorExpressoes
{
    public const MAX_COMPRIMENTO = 500;

    public const MAX_PROFUNDIDADE = 40;

    public const FUNCOES = ['round', 'abs', 'min', 'max', 'sum'];

    private int $i = 0;

    private int $profundidade = 0;

    private int $emArgumentos = 0;

    /** @param  array<string, float|int>|null  $vars  null = qualquer variável vale 1 (verificação da sintaxe) */
    private function __construct(private readonly string $s, private readonly ?array $vars) {}

    /**
     * @param  array<string, float|int>  $vars
     *
     * @throws ErroExpressao
     */
    public static function avaliar(string|int|float|null $expressao, array $vars): float
    {
        return (new self(self::texto($expressao), $vars))->executar();
    }

    /** É expressão se o avaliador a conseguir ler (com qualquer variável a valer 1); senão é prosa («soma das bebidas…»). */
    public static function eExpressao(mixed $t): bool
    {
        if (is_int($t) || is_float($t)) {
            return true;
        }
        if (! is_string($t)) {
            return false;
        }
        $s = trim($t);
        if ($s === '' || strlen($s) > self::MAX_COMPRIMENTO || ! preg_match('/^[A-Za-z0-9_+\-*\/(),.\s]+$/', $s)) {
            return false;
        }
        try {
            (new self($s, null))->executar();

            return true;
        } catch (ErroExpressao) {
            return false;
        }
    }

    /** @return list<string> identificadores usados (sem as funções) */
    public static function identificadores(string|int|float|null $expressao): array
    {
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', self::texto($expressao), $m);

        return array_values(array_unique(array_filter($m[0], fn ($x) => ! in_array(strtolower($x), self::FUNCOES, true))));
    }

    private static function texto(string|int|float|null $expressao): string
    {
        $s = is_float($expressao) ? rtrim(rtrim(number_format($expressao, 10, '.', ''), '0'), '.') : (string) $expressao;
        if (strlen($s) > self::MAX_COMPRIMENTO) {
            throw new ErroExpressao('A expressão é demasiado longa (máximo '.self::MAX_COMPRIMENTO.' caracteres).');
        }

        return $s;
    }

    private function executar(): float
    {
        if (trim($this->s) === '') {
            throw new ErroExpressao('Expressão vazia.');
        }
        $v = $this->soma();
        $this->espaco();
        if ($this->i < strlen($this->s)) {
            throw new ErroExpressao('Não percebi «'.substr($this->s, $this->i, 12).'».');
        }
        if (! is_finite($v)) {
            throw new ErroExpressao('O resultado não é um número finito.');
        }

        return $v;
    }

    private function espaco(): void
    {
        while ($this->i < strlen($this->s) && ctype_space($this->s[$this->i])) {
            $this->i++;
        }
    }

    private function car(): string
    {
        return $this->s[$this->i] ?? '';
    }

    private function soma(): float
    {
        $v = $this->produto();
        for (; ;) {
            $this->espaco();
            $c = $this->car();
            if ($c === '+') {
                $this->i++;
                $v += $this->produto();
            } elseif ($c === '-') {
                $this->i++;
                $v -= $this->produto();
            } else {
                return $v;
            }
        }
    }

    private function produto(): float
    {
        $v = $this->primario();
        for (; ;) {
            $this->espaco();
            $c = $this->car();
            if ($c === '*') {
                $this->i++;
                $v *= $this->primario();
            } elseif ($c === '/') {
                $this->i++;
                $d = $this->primario();
                if ($d == 0.0) {
                    if ($this->vars === null) {
                        return 1.0;   // verificação de sintaxe: as variáveis valem 1, um zero literal não é erro de escrita
                    }
                    throw new ErroExpressao('Divisão por zero.');
                }
                $v /= $d;
            } else {
                return $v;
            }
        }
    }

    private function primario(): float
    {
        if (++$this->profundidade > self::MAX_PROFUNDIDADE) {
            throw new ErroExpressao('Expressão demasiado encadeada.');
        }
        try {
            return $this->primarioInterno();
        } finally {
            $this->profundidade--;
        }
    }

    private function primarioInterno(): float
    {
        $this->espaco();
        $c = $this->car();
        if ($c === '(') {
            $this->i++;
            $v = $this->soma();
            $this->espaco();
            if ($this->car() !== ')') {
                throw new ErroExpressao('Falta um ) na expressão.');
            }
            $this->i++;

            return $v;
        }
        if ($c === '-') {
            $this->i++;

            return -$this->primario();
        }
        if ($c === '+') {
            $this->i++;

            return $this->primario();
        }
        $resto = substr($this->s, $this->i);
        $padrao = $this->emArgumentos > 0 ? '/^\d+(?:\.\d+)?/' : '/^\d+(?:[.,]\d+)?/';
        if (preg_match($padrao, $resto, $m)) {
            $this->i += strlen($m[0]);

            return (float) str_replace(',', '.', $m[0]);
        }
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*/', $resto, $m)) {
            throw new ErroExpressao('Não percebi «'.substr($resto, 0, 12).'».');
        }
        $nome = $m[0];
        $this->i += strlen($nome);
        $this->espaco();
        if ($this->car() === '(') {
            return $this->funcao($nome);
        }
        if ($this->vars === null) {
            return 1.0;
        }
        if (! array_key_exists($nome, $this->vars)) {
            throw new ErroExpressao("Falta o valor de «{$nome}».");
        }
        $v = $this->vars[$nome];
        if (! is_numeric($v) || ! is_finite((float) $v)) {
            throw new ErroExpressao("O valor de «{$nome}» não é um número.");
        }

        return (float) $v;
    }

    private function funcao(string $nome): float
    {
        $this->i++;   // (
        $args = [];
        $this->emArgumentos++;
        try {
            $this->espaco();
            if ($this->car() !== ')') {
                for (; ;) {
                    $args[] = $this->soma();
                    if (count($args) > 100) {
                        throw new ErroExpressao('Demasiados argumentos na função.');
                    }
                    $this->espaco();
                    if ($this->car() === ',') {
                        $this->i++;

                        continue;
                    }
                    break;
                }
            }
            $this->espaco();
            if ($this->car() !== ')') {
                throw new ErroExpressao('Falta um ) na função.');
            }
            $this->i++;
        } finally {
            $this->emArgumentos--;
        }
        $f = strtolower($nome);

        return match ($f) {
            'round' => $this->arredondar($args),
            'abs' => count($args) === 1 ? abs($args[0]) : throw new ErroExpressao('abs() leva um argumento.'),
            'min' => $args ? min($args) : throw new ErroExpressao('min() leva pelo menos um argumento.'),
            'max' => $args ? max($args) : throw new ErroExpressao('max() leva pelo menos um argumento.'),
            'sum' => array_sum($args),
            default => throw new ErroExpressao("Função «{$nome}» não é permitida (use round, abs, min, max ou sum)."),
        };
    }

    /** @param  list<float>  $args */
    private function arredondar(array $args): float
    {
        if (! $args || count($args) > 2) {
            throw new ErroExpressao('round() leva um ou dois argumentos.');
        }
        $casas = isset($args[1]) ? max(0, min(6, (int) round($args[1]))) : 0;

        return round($args[0], $casas);
    }
}
