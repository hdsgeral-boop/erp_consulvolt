<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Support\Tenancy\ContextoEmpresa;

/**
 * Base dos módulos do relatório de gestão (MODULOS, modules/gestao/relatorios_gestao.js:118-572).
 * Cada módulo devolve, para um período {inicio, fim}, { kpis, tabelas, graficos, notas } com valores numéricos; a comparação
 * A × B (Δ, Δ%) e a leitura (melhor quando sobe/desce) são feitas no fim, iguais para todos (ServicoRelatoriosGestao).
 *
 * Representação dos valores: formato «kz» em texto com 2 casas (bcmath / numeric — ADR-022); restantes formatos em número.
 * Divisão por zero → null (no legado NaN, que o ecrã mostrava como «—»).
 */
abstract class ModuloGestao
{
    public function __construct(protected readonly ContextoEmpresa $contexto) {}

    abstract public function id(): string;

    abstract public function nome(): string;

    abstract public function descricao(): string;

    /**
     * @param  array{inicio: string, fim: string}  $p
     * @return array{kpis: list<array<string, mixed>>, tabelas: list<array<string, mixed>>, graficos: list<array<string, mixed>>, notas: list<string>}
     */
    abstract public function calcular(array $p): array;

    protected function empresa(): int
    {
        return $this->contexto->obrigatorio();
    }

    /**
     * Indicador. $formato: kz | num | pct | dias | meses | horas | nota; $sentido: sobe | desce | neutro.
     *
     * @param  array<string, mixed>  $extra  actual (situação actual, sem histórico), casas
     */
    protected static function k(string $chave, string $rotulo, mixed $valor, string $formato, string $sentido, string $ajuda = '', array $extra = []): array
    {
        return ['chave' => $chave, 'rotulo' => $rotulo, 'valor' => self::valor($valor, $formato), 'formato' => $formato, 'sentido' => $sentido, 'ajuda' => $ajuda,
            'actual' => (bool) ($extra['actual'] ?? false), 'casas' => $extra['casas'] ?? null];
    }

    public static function valor(mixed $v, string $formato): string|float|int|null
    {
        if ($v === null || (is_float($v) && ! is_finite($v))) {
            return null;
        }
        if ($formato === 'kz') {
            return self::dinheiro($v);
        }
        if ($formato === 'num' && (is_int($v) || (is_string($v) && ctype_digit(ltrim($v, '-'))))) {
            return (int) $v;
        }

        return round((float) $v, 4);
    }

    /** Valor monetário com 2 casas, arredondado a meio para longe de zero (ADR-022). */
    public static function dinheiro(mixed $v): string
    {
        $s = is_float($v) ? sprintf('%.6F', $v) : trim((string) ($v ?? '0'));
        if ($s === '' || ! is_numeric($s)) {
            $s = '0';
        }
        $r = bcadd($s, str_starts_with($s, '-') ? '-0.005' : '0.005', 2);

        return $r === '-0.00' ? '0.00' : $r;
    }

    /** a ÷ b em vírgula flutuante; b = 0 → null. */
    protected static function div(mixed $a, mixed $b): ?float
    {
        $b = (float) $b;

        return $b != 0.0 ? (float) $a / $b : null;
    }

    /** a ÷ b × 100; b = 0 → null. */
    protected static function pct(mixed $a, mixed $b): ?float
    {
        $d = self::div($a, $b);

        return $d === null ? null : $d * 100;
    }

    protected static function soma(string ...$valores): string
    {
        return array_reduce($valores, fn ($s, $v) => bcadd($s, self::dinheiro($v), 2), '0.00');
    }

    /** Estado válido (o legado: !/^(ANULAD|CANCELAD)/i). */
    protected static function sqlValido(string $coluna): string
    {
        return "COALESCE({$coluna}, '') !~* '^(ANULAD|CANCELAD)'";
    }

    /** Tabela no formato do legado: título, coluna-chave, colunas (id, rotulo, formato) e linhas. */
    protected static function tabela(string $id, string $titulo, string $chave, array $colunas, array $linhas): array
    {
        return ['id' => $id, 'titulo' => $titulo, 'chave' => $chave, 'colunas' => array_map(fn ($c) => ['id' => $c[0], 'rotulo' => $c[1], 'formato' => $c[2] ?? null], $colunas),
            'linhas' => array_values($linhas)];
    }
}
