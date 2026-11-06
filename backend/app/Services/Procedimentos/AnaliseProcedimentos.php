<?php

namespace App\Services\Procedimentos;

use Illuminate\Support\Str;

/**
 * Cálculos puros sobre a lista de processos do manual (porte de cruzamentos(), cobertura() e lacunas() do legado):
 * matriz de cruzamentos (pares, ligações, grau, isolados, central), cobertura de módulos e painéis, e avisos de partes por preencher.
 */
final class AnaliseProcedimentos
{
    /**
     * @param  list<array<string, mixed>>  $ps
     * @return array{pares: list<array<string, mixed>>, externos: list<array<string, mixed>>, ligacoes: list<array<string, mixed>>, grau: array<string, int>, isolados: list<string>, central: ?string}
     */
    public static function cruzamentos(array $ps): array
    {
        $porChave = [];
        foreach ($ps as $p) {
            $porChave[$p['chave']] = $p;
        }
        $pares = [];
        $externos = [];
        foreach ($ps as $a) {
            foreach ($a['cruza'] as $c) {
                if (isset($porChave[$c['com']])) {
                    $pares[] = ['de' => $a['chave'], 'para' => $c['com'], 'onde' => $c['onde'], 'o_que' => $c['o_que']];
                } else {
                    $externos[] = ['de' => $a['chave'], 'para' => CatalogoFabricaProcedimentos::EXTERNOS[$c['com']] ?? $c['com'], 'onde' => $c['onde'], 'o_que' => $c['o_que'], 'fora' => true];
                }
            }
        }
        // A matriz é simétrica para quem a lê (A toca B = B toca A), mas guarda-se o sentido dos pontos.
        $ligacoes = [];
        foreach ($pares as $p) {
            $par = [$p['de'], $p['para']];
            sort($par);
            $k = implode('|', $par);
            $ligacoes[$k] ??= ['a' => $p['de'], 'b' => $p['para'], 'pontos' => []];
            $ligacoes[$k]['pontos'][] = $p;
        }
        $grau = array_fill_keys(array_keys($porChave), 0);
        foreach ($ligacoes as $l) {
            $grau[$l['a']]++;
            $grau[$l['b']]++;
        }
        $isolados = [];
        $central = null;
        foreach ($ps as $p) {
            if (($grau[$p['chave']] ?? 0) === 0) {
                $isolados[] = $p['chave'];
            }
            if ($central === null || $grau[$p['chave']] > $grau[$central]) {
                $central = $p['chave'];
            }
        }

        return ['pares' => $pares, 'externos' => $externos, 'ligacoes' => array_values($ligacoes), 'grau' => $grau, 'isolados' => $isolados, 'central' => $central];
    }

    /**
     * Cruza módulos do ERP, painéis de acompanhamento e narrativas escritas.
     *
     * @param  list<array<string, mixed>>  $ps  processos visíveis (fábrica ∪ edições ∪ próprios)
     * @param  list<string>  $paineis  chaves dos painéis do Fluxo de Processos
     * @return array<string, mixed>
     */
    public static function cobertura(array $ps, array $paineis): array
    {
        $comNarrativa = array_column($ps, 'chave');
        $modulos = [];
        foreach (CatalogoFabricaProcedimentos::POR_MODULO as $id => $m) {
            $descritos = array_values(array_filter($m['processos'], fn ($k) => in_array($k, $comNarrativa, true)));
            $emFalta = array_values(array_filter($m['processos'], fn ($k) => ! in_array($k, $comNarrativa, true)));
            $modulos[] = ['id' => $id, 'nome' => $m['nome'], 'esperados' => $m['processos'], 'descritos' => $descritos, 'em_falta' => $emFalta,
                'coberto' => $m['processos'] !== [] && count($descritos) === count($m['processos']), 'sem_processo' => $m['processos'] === []];
        }

        return [
            'modulos' => $modulos,
            // O caso que mais interessa: tem painel e ninguém escreveu a narrativa.
            'painel_sem_narrativa' => array_values(array_filter($paineis, fn ($k) => ! in_array($k, $comNarrativa, true))),
            // O contrário: processo descrito sem painel (normal nos processos próprios).
            'narrativa_sem_painel' => array_values(array_map(fn ($p) => ['chave' => $p['chave'], 'titulo' => $p['titulo'], 'origem' => $p['origem']],
                array_filter($ps, fn ($p) => ! in_array($p['chave'], $paineis, true)))),
            'modulos_sem_processo' => array_values(array_filter($modulos, fn ($m) => $m['sem_processo'] || $m['em_falta'] !== [])),
            'total' => ['modulos' => count($modulos), 'paineis' => count($paineis), 'narrativas' => count($ps)],
        ];
    }

    /** Minúsculas e sem acentos, para comparar nomes de funções. */
    public static function normal(string $s): string
    {
        return Str::ascii(mb_strtolower(trim($s)));
    }

    /**
     * Avisos de partes por preencher (os 7 do legado, com o texto original).
     *
     * @param  list<array<string, mixed>>  $ps
     * @param  list<array<string, mixed>>  $intervenientes
     * @param  array<string, mixed>  $o  opções do documento
     * @param  array<string, mixed>  $cruz
     * @return list<string>
     */
    public static function lacunas(array $ps, array $intervenientes, array $o, array $cruz): array
    {
        if ($ps === []) {
            return ['Nenhum processo escolhido. O manual sairia vazio.'];
        }
        $titulos = fn (array $lista, int $n) => implode(', ', array_slice(array_map(fn ($p) => $p['titulo'], $lista), 0, $n)).(count($lista) > $n ? '…' : '');
        $porChave = array_column($ps, null, 'chave');
        $a = [];
        if ($o['incluir_cruzamentos'] && $cruz['isolados'] !== []) {
            $iso = array_map(fn ($k) => $porChave[$k], $cruz['isolados']);
            $a[] = count($iso).' processo(s) sem nenhum cruzamento declarado — nenhum processo vive sozinho, estes só estão por ligar: '.$titulos($iso, 4);
        }
        $semObjetivo = array_values(array_filter($ps, fn ($p) => trim($p['objetivo']) === ''));
        if ($semObjetivo !== []) {
            $a[] = count($semObjetivo).' processo(s) sem objectivo: '.$titulos($semObjetivo, 3);
        }
        $semQuem = [];
        foreach ($ps as $p) {
            foreach ($p['etapas'] as $i => $e) {
                if (trim($e['quem']) === '') {
                    $semQuem[] = $p['titulo'].' · etapa '.($i + 1);
                }
            }
        }
        if ($semQuem !== []) {
            $a[] = count($semQuem).' etapa(s) sem responsável — é a pergunta a que um procedimento tem de responder: '.implode('; ', array_slice($semQuem, 0, 3)).(count($semQuem) > 3 ? '…' : '');
        }
        if ($o['incluir_controlos']) {
            $semControlo = array_values(array_filter($ps, fn ($p) => ! array_filter($p['etapas'], fn ($e) => trim($e['controlos']) !== '')));
            if ($semControlo !== []) {
                $a[] = count($semControlo).' processo(s) sem nenhum controlo definido: '.$titulos($semControlo, 3);
            }
        }
        $semResultado = 0;
        foreach ($ps as $p) {
            $semResultado += count(array_filter($p['etapas'], fn ($e) => trim($e['resultado']) === ''));
        }
        if ($semResultado > 0) {
            $a[] = "{$semResultado} etapa(s) sem resultado — sem ele não se sabe quando a etapa está feita.";
        }
        if ($o['conferir_funcoes']) {
            $fora = array_values(array_filter($intervenientes, fn ($p) => $p['na_funcoes'] === false));
            if ($fora !== []) {
                $a[] = count($fora).' interveniente(s) sem função correspondente em RH › Funções: '.implode(', ', array_slice(array_column($fora, 'nome'), 0, 4)).(count($fora) > 4 ? '…' : '');
            }
        }

        return $a;
    }
}
