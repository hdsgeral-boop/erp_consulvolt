<?php

namespace App\Services\Integracoes\PowerBI;

use App\Exceptions\ErroNegocio;
use App\Services\Gestao\Paineis\Cubo\CatalogoCubo;
use App\Services\Gestao\Paineis\Cubo\ServicoCubo;
use Illuminate\Support\Facades\DB;

/**
 * Feed OData v4 de leitura para o Power BI (decisão 25, lacuna M-02) — /api/bi/odata/{conjunto}.
 *
 * Expõe, linha a linha (sem agregação), os 8 conjuntos da lista branca da Análise Dinâmica (CatalogoCubo: contabilidade,
 * vendas, compras, tesouraria, armazem, rh, projetos, ativos), com as mesmas regras de cada conjunto (anulados excluídos,
 * apuramento fora da contabilidade salvo `incluir_apuramento=1`, fotografia salarial…). Cada entidade tem:
 *   - `linha` (chave, Edm.Int64: posição estável na ordenação), `data` (Edm.Date: data do movimento);
 *   - uma propriedade Edm.String por dimensão e uma Edm.Decimal por medida (ids da lista branca; nenhum texto do pedido
 *     entra no SQL; o período vai por parâmetros).
 * Paginação no servidor (`@odata.nextLink`, 5 000 linhas por página) e `$top`, `$skip`, `$select`, `$count`; os restantes
 * operadores ($filter, $orderby, $expand, $apply, $search) são recusados com 501 e o `$metadata` declara-o
 * (Org.OData.Capabilities.V1) para o Power Query não os pedir. Período opcional: `data_inicio`/`data_fim` (AAAA-MM-DD).
 *
 * Correcções face ao servidor do legado (powerbi_api/server.js): sem autenticação (qualquer processo local lia salários,
 * NIF e IBAN de TODAS as empresas exportadas), `POST /api/upload` aberto que reescrevia os dados, ficheiro estático
 * exportado à mão (dados desactualizados), `$metadata` sem EntityType (CSDL inválido) e `payroll_results` recalculado no
 * servidor com regras próprias (líquido = bruto − INSS − IRT − todos os descontos, contando o INSS/IRT duas vezes quando
 * eram rubricas). Agora: token por empresa, dados vivos da base, CSDL válido e salários da fotografia do processamento.
 */
final class ServicoFeedBI
{
    public const TAMANHO_PAGINA = 5000;

    public const ESPACO_NOMES = 'ERPConsulvolt';

    public const DATA_MINIMA = '1900-01-01';

    public const DATA_MAXIMA = '2999-12-31';

    public function __construct(private readonly ServicoCubo $cubo) {}

    /**
     * Conjuntos do feed: definição do cubo sem as dimensões próprias da holding (o token lê só a sua empresa).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function conjuntos(): array
    {
        $saida = [];
        foreach (CatalogoCubo::conjuntos() as $id => $def) {
            $def['dimensoes'] = array_filter($def['dimensoes'], fn ($d) => empty($d['holding']));
            $saida[$id] = $def;
        }

        return $saida;
    }

    /** @param  list<string>|null  $permitidos */
    public static function permitidos(?array $permitidos): array
    {
        $todos = self::conjuntos();

        return $permitidos ? array_intersect_key($todos, array_flip($permitidos)) : $todos;
    }

    /**
     * Propriedades de uma entidade: nome => [tipo Edm, rótulo].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function propriedades(array $def): array
    {
        $p = ['linha' => ['Edm.Int64', 'Linha'], 'data' => ['Edm.Date', 'Data']];
        foreach ($def['dimensoes'] as $id => $d) {
            $p[$id] = ['Edm.String', $d[0]];
        }
        foreach ($def['medidas'] as $id => $m) {
            $p[$id] = ['Edm.Decimal', $m[0]];
        }

        return $p;
    }

    /** Documento de serviço (raiz do feed). */
    public function documentoServico(string $base, ?array $permitidos): array
    {
        return ['@odata.context' => "{$base}/\$metadata", 'value' => array_values(array_map(fn ($id, $def) => ['name' => $id, 'kind' => 'EntitySet', 'url' => $id, 'title' => $def['nome']],
            array_keys(self::permitidos($permitidos)), self::permitidos($permitidos)))];
    }

    /** CSDL (OData v4) com os EntityType, o EntityContainer, os rótulos (Core.Description) e as restrições de consulta. */
    public function metadata(?array $permitidos): string
    {
        $x = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $tipos = '';
        $conjuntos = '';
        $anotacoes = '';
        foreach (self::permitidos($permitidos) as $id => $def) {
            $props = '';
            foreach (self::propriedades($def) as $nome => [$tipo, $rotulo]) {
                $extra = $tipo === 'Edm.Decimal' ? ' Scale="variable"' : '';
                $nulo = $nome === 'linha' ? ' Nullable="false"' : '';
                $props .= "<Property Name=\"{$nome}\" Type=\"{$tipo}\"{$nulo}{$extra}><Annotation Term=\"Core.Description\" String=\"{$x($rotulo)}\"/></Property>";
            }
            $tipos .= "<EntityType Name=\"{$id}\"><Key><PropertyRef Name=\"linha\"/></Key>{$props}</EntityType>";
            $conjuntos .= "<EntitySet Name=\"{$id}\" EntityType=\"".self::ESPACO_NOMES.".{$id}\"><Annotation Term=\"Core.Description\" String=\"{$x($def['nome'])}\"/></EntitySet>";
            $anotacoes .= '<Annotations Target="'.self::ESPACO_NOMES.".Contentor/{$id}\">"
                .'<Annotation Term="Capabilities.FilterRestrictions"><Record><PropertyValue Property="Filterable" Bool="false"/></Record></Annotation>'
                .'<Annotation Term="Capabilities.SortRestrictions"><Record><PropertyValue Property="Sortable" Bool="false"/></Record></Annotation>'
                .'<Annotation Term="Capabilities.ExpandRestrictions"><Record><PropertyValue Property="Expandable" Bool="false"/></Record></Annotation>'
                .'<Annotation Term="Capabilities.SearchRestrictions"><Record><PropertyValue Property="Searchable" Bool="false"/></Record></Annotation>'
                .'<Annotation Term="Capabilities.TopSupported" Bool="true"/><Annotation Term="Capabilities.SkipSupported" Bool="true"/>'
                .'<Annotation Term="Capabilities.CountRestrictions"><Record><PropertyValue Property="Countable" Bool="true"/></Record></Annotation>'
                .'</Annotations>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<edmx:Edmx Version="4.0" xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx">'
            .'<edmx:Reference Uri="https://oasis-tcs.github.io/odata-vocabularies/vocabularies/Org.OData.Core.V1.xml"><edmx:Include Namespace="Org.OData.Core.V1" Alias="Core"/></edmx:Reference>'
            .'<edmx:Reference Uri="https://oasis-tcs.github.io/odata-vocabularies/vocabularies/Org.OData.Capabilities.V1.xml"><edmx:Include Namespace="Org.OData.Capabilities.V1" Alias="Capabilities"/></edmx:Reference>'
            .'<edmx:DataServices><Schema Namespace="'.self::ESPACO_NOMES.'" xmlns="http://docs.oasis-open.org/odata/ns/edm">'
            .$tipos.'<EntityContainer Name="Contentor">'.$conjuntos.'</EntityContainer>'.$anotacoes
            .'</Schema></edmx:DataServices></edmx:Edmx>';
    }

    /**
     * Uma página do conjunto para a empresa.
     *
     * @param  array{skip?: int, top?: ?int, data_inicio?: ?string, data_fim?: ?string, incluir_apuramento?: bool, contar?: bool, selecionar?: list<string>}  $op
     * @return array{linhas: list<array<string, mixed>>, mais: bool, total: ?int}
     */
    public function pagina(string $conjunto, int $empresa, array $op): array
    {
        $def = self::conjuntos()[$conjunto] ?? null;
        if ($def === null) {
            throw new ErroNegocio("Conjunto de dados desconhecido: {$conjunto}.", 'BI_CONJUNTO_INVALIDO', 404);
        }
        $ini = $op['data_inicio'] ?? null ?: self::DATA_MINIMA;
        $fim = $op['data_fim'] ?? null ?: self::DATA_MAXIMA;
        foreach ([$ini, $fim] as $d) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || ! strtotime($d)) {
                throw new ErroNegocio('Datas no formato AAAA-MM-DD (data_inicio, data_fim).', 'BI_PERIODO_INVALIDO', 400);
            }
        }
        $skip = max(0, (int) ($op['skip'] ?? 0));
        $top = isset($op['top']) ? max(0, (int) $op['top']) : null;
        $limite = $top === null ? self::TAMANHO_PAGINA : min(self::TAMANHO_PAGINA, $top);

        [$from, $where, $params] = $this->cubo->fonteFeed($conjunto, $def, ['data_inicio' => $ini, 'data_fim' => $fim, 'incluir_apuramento' => (bool) ($op['incluir_apuramento'] ?? false)], $empresa);
        $juncoes = implode(' ', $def['juncoes'] ?? []);
        $sel = ["({$def['data']})::date AS data"];
        $dims = array_keys($def['dimensoes']);
        $meds = array_keys($def['medidas']);
        foreach ($dims as $i => $id) {
            $sel[] = "({$def['dimensoes'][$id][1]})::text AS d{$i}";
        }
        foreach ($meds as $i => $id) {
            $sel[] = "({$def['medidas'][$id][1]})::numeric AS m{$i}";
        }
        // ordenação total (todas as colunas): páginas estáveis entre pedidos sem depender de ids internos
        $ordem = implode(', ', array_map(fn ($n) => "{$n} NULLS LAST", range(1, count($sel))));
        $linhas = $limite === 0 ? [] : DB::select('SELECT '.implode(', ', $sel)." FROM {$from} {$juncoes} WHERE {$where} ORDER BY {$ordem} LIMIT ? OFFSET ?",
            array_merge($params, [$limite + 1, $skip]));
        $mais = count($linhas) > $limite;
        $linhas = array_slice($linhas, 0, $limite);
        $total = ! empty($op['contar']) ? (int) DB::selectOne("SELECT COUNT(*) AS n FROM {$from} {$juncoes} WHERE {$where}", $params)->n : null;

        $selecionar = array_flip($op['selecionar'] ?? []);
        $saida = [];
        foreach ($linhas as $k => $l) {
            $r = ['linha' => $skip + $k + 1, 'data' => $l->data];
            foreach ($dims as $i => $id) {
                $r[$id] = $l->{"d{$i}"};
            }
            foreach ($meds as $i => $id) {
                $v = $l->{"m{$i}"};
                $r[$id] = $v === null ? null : (float) $v;
            }
            $saida[] = $selecionar ? array_intersect_key($r, $selecionar + ['linha' => true]) : $r;
        }

        return ['linhas' => $saida, 'mais' => $mais && ($top === null || $top > $limite), 'total' => $total];
    }
}
