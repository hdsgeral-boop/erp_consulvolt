<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Teste de contrato JSON dos controladores `Api\POS\*` (F0 do POS): a forma das respostas (chaves e tipos, não os valores)
 * não pode mudar quando se introduzem FormRequests e Resources. Opt-in (sem a variável POS_CONTRATO não faz nada):
 *
 *   POS_CONTRATO=gravar    acrescenta a forma de cada resposta POS a POS_CONTRATO_FICHEIRO (jsonl; juntar com ::juntar())
 *   POS_CONTRATO=verificar cada resposta POS tem de caber na forma gravada em tests/Fixtures/contrato_pos.json
 *                          (chaves e tipos já vistos para essa rota e estado HTTP); falha com o caminho exacto da diferença.
 *
 * Os testes existentes (`POS*`, `R3VendasTest`, …) fazem de cenários: correr com POS_CONTRATO=verificar prova o contrato.
 * Nó = `_t` tipos escalares, `_m` objecto (chave → nó), `_l` lista (nó dos elementos); um nó pode ter vários (p.ex. null ou objecto).
 */
final class ContratoPOS
{
    public const FIXTURE = __DIR__.'/../Fixtures/contrato_pos.json';

    private static ?array $fixture = null;

    public static function registar(string $metodo, TestResponse $resposta): void
    {
        $modo = getenv('POS_CONTRATO');
        if (! $modo) {
            return;
        }
        $rota = app('request')->route();
        if (! $rota || ! str_contains($rota->getActionName(), 'Controllers\\Api\\POS\\')) {
            return;
        }
        $chave = strtoupper($metodo).' '.$rota->uri().' '.$resposta->getStatusCode();
        $json = json_decode((string) $resposta->getContent(), true);
        $forma = json_last_error() === JSON_ERROR_NONE ? self::forma($json) : ['_t' => ['nao_json']];

        if ($modo === 'gravar') {
            file_put_contents(getenv('POS_CONTRATO_FICHEIRO') ?: sys_get_temp_dir().'/contrato_pos.jsonl', json_encode([$chave, $forma])."\n", FILE_APPEND | LOCK_EX);

            return;
        }
        self::$fixture ??= json_decode((string) file_get_contents(self::FIXTURE), true) ?? [];
        if (! isset(self::$fixture[$chave])) {
            return;
        }
        $erros = [];
        self::cabe($forma, self::$fixture[$chave], '$', $erros);
        Assert::assertSame([], $erros, "Contrato JSON do POS alterado em {$chave}:\n".implode("\n", $erros));
    }

    /** @return array<string, mixed> */
    public static function forma(mixed $v): array
    {
        if (is_array($v)) {
            if ($v === []) {
                return ['_t' => ['vazio']];
            }
            if (array_is_list($v)) {
                return ['_l' => array_reduce(array_map([self::class, 'forma'], $v), fn ($a, $b) => $a === null ? $b : self::juntarNos($a, $b))];
            }

            return ['_m' => array_map([self::class, 'forma'], $v)];
        }

        return ['_t' => [match (true) {
            $v === null => 'null', is_bool($v) => 'bool', is_int($v) => 'int', is_float($v) => 'float', default => 'string',
        }]];
    }

    /** Junta nós (união de tipos, chaves e elementos). */
    public static function juntarNos(array $a, array $b): array
    {
        $r = $a;
        if (isset($b['_t'])) {
            $r['_t'] = array_values(array_unique(array_merge($a['_t'] ?? [], $b['_t'])));
            sort($r['_t']);
        }
        if (isset($b['_l'])) {
            $r['_l'] = isset($a['_l']) ? self::juntarNos($a['_l'], $b['_l']) : $b['_l'];
        }
        if (isset($b['_m'])) {
            $m = $a['_m'] ?? [];
            foreach ($b['_m'] as $k => $no) {
                $m[$k] = isset($m[$k]) ? self::juntarNos($m[$k], $no) : $no;
            }
            ksort($m);
            $r['_m'] = $m;
        }

        return $r;
    }

    /** Une o ficheiro jsonl gravado num único mapa chave → nó (a fixture). */
    public static function juntar(string $jsonl): array
    {
        $mapa = [];
        foreach (file($jsonl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
            [$chave, $forma] = json_decode($linha, true);
            $mapa[$chave] = isset($mapa[$chave]) ? self::juntarNos($mapa[$chave], $forma) : $forma;
        }
        ksort($mapa);

        return $mapa;
    }

    /** @param list<string> $erros */
    private static function cabe(array $novo, array $base, string $caminho, array &$erros): void
    {
        foreach ($novo['_t'] ?? [] as $t) {
            $aceita = in_array($t, $base['_t'] ?? [], true) || ($t === 'vazio' && (isset($base['_l']) || isset($base['_m'])))
                || ($t === 'int' && in_array('float', $base['_t'] ?? [], true));
            if (! $aceita) {
                $erros[] = "{$caminho}: tipo '{$t}' não previsto (previstos: ".implode('|', $base['_t'] ?? ['—']).')';
            }
        }
        if (isset($novo['_l'])) {
            isset($base['_l']) ? self::cabe($novo['_l'], $base['_l'], $caminho.'[]', $erros) : $erros[] = "{$caminho}: lista não prevista";
        }
        if (isset($novo['_m'])) {
            if (! isset($base['_m'])) {
                $erros[] = "{$caminho}: objecto não previsto";

                return;
            }
            foreach ($novo['_m'] as $k => $no) {
                isset($base['_m'][$k]) ? self::cabe($no, $base['_m'][$k], "{$caminho}.{$k}", $erros) : $erros[] = "{$caminho}.{$k}: chave nova";
            }
        }
    }
}
