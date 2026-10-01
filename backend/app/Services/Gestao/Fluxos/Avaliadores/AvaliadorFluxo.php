<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Support\Tenancy\ContextoEmpresa;

/**
 * Base dos avaliadores do Fluxo de Processos (js/fluxo_*.js). Cada avaliador lê os documentos reais do módulo em consultas
 * agregadas (sem N+1) e devolve a lista de processos, cada um com as etapas do fluxo e, por etapa:
 *   estado (concluida | curso | fazer | bloqueada), resumo, factos [{rotulo, valor, formato}], problemas [{nivel, texto}]
 *   e accoes [{rotulo, acao, primaria}] (acao = ecrã do legado onde a etapa se executa; o frontend traduz para a rota).
 * O ServicoFluxos calcula o resto (etapa actual, funil, indicadores comuns, filtros e paginação).
 */
abstract class AvaliadorFluxo
{
    public function __construct(protected readonly ContextoEmpresa $contexto) {}

    /** O fluxo só aparece quando a empresa já registou a primeira transacção no módulo onde começa (fluxosComActividade). */
    abstract public function temActividade(): bool;

    /**
     * @return array{processos: list<array<string, mixed>>, kpis: list<array<string, mixed>>}
     */
    abstract public function avaliar(): array;

    protected function empresa(): int
    {
        return $this->contexto->obrigatorio();
    }

    protected static function etapa(string $estado, string $resumo, array $factos = [], array $problemas = [], array $accoes = []): array
    {
        return ['estado' => $estado, 'resumo' => $resumo, 'factos' => $factos, 'problemas' => $problemas, 'accoes' => $accoes];
    }

    protected static function feito(string $resumo, array $factos = [], array $accoes = [], array $problemas = []): array
    {
        return self::etapa('concluida', $resumo, $factos, $problemas, $accoes);
    }

    protected static function naoAplica(string $resumo = 'Não aplicável'): array
    {
        return self::etapa('concluida', $resumo);
    }

    protected static function porFazer(string $resumo, array $accoes = []): array
    {
        return self::etapa('fazer', $resumo, [], [], $accoes);
    }

    /** Facto: par rótulo/valor; formato kz | data | data_hora | num | texto. */
    protected static function f(string $rotulo, mixed $valor, string $formato = 'texto'): array
    {
        return ['rotulo' => $rotulo, 'valor' => $valor, 'formato' => $formato];
    }

    protected static function aviso(string $texto): array
    {
        return ['nivel' => 'aviso', 'texto' => $texto];
    }

    protected static function erro(string $texto): array
    {
        return ['nivel' => 'erro', 'texto' => $texto];
    }

    protected static function accao(string $rotulo, string $acao, bool $primaria = false): array
    {
        return ['rotulo' => $rotulo, 'acao' => $acao, 'primaria' => $primaria];
    }

    /** Indicador do topo do fluxo (fluxo-kpis). */
    protected static function kpi(string $chave, string $rotulo, mixed $valor, string $formato = 'num', bool $alerta = false): array
    {
        return ['chave' => $chave, 'rotulo' => $rotulo, 'valor' => $valor, 'formato' => $formato, 'alerta' => $alerta];
    }

    /** «a, b, c, d, e e mais n» (lista, fluxo_vendas.js:32). */
    protected static function lista(iterable $itens): string
    {
        $a = array_values(array_unique(array_filter(array_map('strval', is_array($itens) ? $itens : iterator_to_array($itens)), fn ($x) => $x !== '')));

        return implode(', ', array_slice($a, 0, 5)).(count($a) > 5 ? ' e mais '.(count($a) - 5) : '');
    }

    /** Valor em Kz para os textos das pendências (1 234,56 Kz). */
    protected static function kz(mixed $v): string
    {
        return number_format((float) $v, 2, ',', '.').' Kz';
    }

    protected static function data(mixed $d): string
    {
        if (! $d) {
            return '—';
        }
        $s = substr((string) ($d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d), 0, 10);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $s;
    }

    public static function dinheiro(mixed $v): string
    {
        $s = is_float($v) ? sprintf('%.6F', $v) : trim((string) ($v ?? '0'));
        if ($s === '' || ! is_numeric($s)) {
            $s = '0';
        }
        $r = bcadd($s, str_starts_with($s, '-') ? '-0.005' : '0.005', 2);

        return $r === '-0.00' ? '0.00' : $r;
    }

    /** Contagem interna (concluídas e bloqueio) para os indicadores do avaliador; o ServicoFluxos retira as chaves «_». */
    protected static function contagem(array $E): array
    {
        return ['_concluidas' => count(array_filter($E, fn ($x) => $x['estado'] === 'concluida')), '_bloqueado' => (bool) array_filter($E, fn ($x) => $x['estado'] === 'bloqueada')];
    }

    /** Indicadores comuns: em curso, bloqueados e concluídos. */
    protected static function kpisBase(array $processos, int $total, string $nome = 'Processos em curso'): array
    {
        $b = count(array_filter($processos, fn ($p) => $p['_bloqueado']));

        return [
            self::kpi('em_curso', $nome, count(array_filter($processos, fn ($p) => ! $p['_bloqueado'] && $p['_concluidas'] < $total))),
            self::kpi('bloqueados', 'Bloqueados', $b, 'num', $b > 0),
            self::kpi('concluidos', 'Concluídos', count(array_filter($processos, fn ($p) => $p['_concluidas'] === $total))),
        ];
    }

    protected static function soma(string ...$v): string
    {
        return array_reduce($v, fn ($s, $x) => bcadd($s, self::dinheiro($x), 2), '0.00');
    }

    protected static function somar(iterable $itens, callable $valor): string
    {
        $s = '0.00';
        foreach ($itens as $i) {
            $s = bcadd($s, self::dinheiro($valor($i)), 2);
        }

        return $s;
    }

    protected static function min(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? $a : $b;
    }

    protected static function max0(string $a): string
    {
        return bccomp($a, '0', 2) > 0 ? $a : '0.00';
    }

    protected static function anulado(?string $estado): bool
    {
        return (bool) preg_match('/ANUL|CANCEL/i', (string) $estado);
    }

    protected static function hoje(): string
    {
        return now()->toDateString();
    }
}
