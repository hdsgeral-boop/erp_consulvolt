<?php

namespace App\Services\Sistema;

/**
 * Catálogo de permissões do legado (116 ecrãs, 195 tarefas), extraído de js/permissoes.js por
 * ferramentas/levantamento/extrair_permissoes.mjs para resources/permissoes/catalogo.json.
 */
final class CatalogoPermissoes
{
    /** Chaves antigas verificadas pelo código do legado que agrupam tarefas novas (js/permissoes.js:486-491). */
    public const LEGADO = [
        'config' => ['config_util_gerir', 'config_perfis_gerir'],
        'acao_descontabilizar' => ['vendas_fat_descontab', 'compras_descontab'],
        'rh_funcoes' => ['rh_funcoes_gerir'],
        'rh_infotipos' => ['rh_infotipos_gerir'],
    ];

    /** Consultas usadas pelo código que não são ecrãs do menu (js/permissoes.js:493-500). */
    public const LEGADO_VISTA = [
        'armazem' => ['armazem_stock', 'armazem_rececoes', 'armazem_guias', 'armazem_movimentos', 'armazem_armazens'],
        'vendas' => ['vendas_clientes', 'vendas_produtos', 'vendas_faturacao', 'vendas_relatorios', 'pos'],
        'teso' => ['teso_gestao_pagamentos', 'teso_folha_caixa', 'teso_gestao_mapas', 'teso_gestao_conciliacao'],
        'contab' => ['lancamentos', 'relatorios_contabeis'],
        'config' => ['config_geral', 'config_utilizadores', 'config_perfis'],
        'ativos' => ['activos'], 'rh_colab' => ['colaboradores'], 'rh_proc' => ['calcular'], 'stock' => ['armazem_stock'],
    ];

    /** @var array<string, array<string, mixed>>|null ecrã id => ecrã */
    private static ?array $ecras = null;

    /** @var array<string, string>|null vista => ecrã id */
    private static ?array $porVista = null;

    /** @var array<string, string>|null tarefa => ecrã id */
    private static ?array $tarefas = null;

    /** @var array<string, mixed>|null catálogo completo (módulos, segregação, modelos) */
    private static ?array $bruto = null;

    /** Catálogo completo, tal como extraído do legado (módulos → ecrãs → tarefas, segregação e perfis-modelo). */
    public static function completo(): array
    {
        self::carregar();

        return self::$bruto;
    }

    /** @return list<array{a: string, b: string, motivo: string}> pares de tarefas incompatíveis (js/permissoes.js:514-535) */
    public static function segregacao(): array
    {
        self::carregar();

        return self::$bruto['segregacao'];
    }

    /** @return list<array{nome: string, permissoes: array<string, bool>}> perfis-modelo (js/permissoes.js:539-581) */
    public static function modelos(): array
    {
        self::carregar();

        return self::$bruto['modelos'];
    }

    /** Chave válida num perfil v2: consulta de um ecrã ("<ecrã>_view") ou tarefa do catálogo. */
    public static function chaveValida(string $chave): bool
    {
        self::carregar();

        return self::eTarefa($chave) || (str_ends_with($chave, '_view') && isset(self::$ecras[substr($chave, 0, -5)]));
    }

    /** @return array<string, array<string, mixed>> chave da tarefa => tarefa (com o ecrã) */
    public static function tarefas(): array
    {
        self::carregar();
        $r = [];
        foreach (self::$ecras as $e) {
            foreach ($e['tarefas'] as $t) {
                $r[$t['chave']] = $t + ['ecra' => $e['id']];
            }
        }

        return $r;
    }

    /** @return array<string, array<string, mixed>> */
    public static function ecras(): array
    {
        self::carregar();

        return self::$ecras;
    }

    /** Ecrã a que pertence uma vista (ex.: 'audit_logs' -> 'config_logs'). */
    public static function ecraDaVista(string $vista): ?array
    {
        self::carregar();
        $id = self::$porVista[$vista] ?? null;

        return $id ? self::$ecras[$id] : null;
    }

    public static function eTarefa(string $chave): bool
    {
        self::carregar();

        return isset(self::$tarefas[$chave]);
    }

    /** @return list<array<string, mixed>> */
    public static function filhos(string $ecraId): array
    {
        return array_values(array_filter(self::ecras(), fn ($e) => $e['pai'] === $ecraId));
    }

    private static function carregar(): void
    {
        if (self::$ecras !== null) {
            return;
        }
        $catalogo = json_decode(file_get_contents(resource_path('permissoes/catalogo.json')), true, flags: JSON_THROW_ON_ERROR);
        self::$bruto = $catalogo;
        self::$ecras = self::$porVista = self::$tarefas = [];
        foreach ($catalogo['modulos'] as $m) {
            foreach ($m['ecras'] as $e) {
                $e['modulo'] = $m['id'];
                self::$ecras[$e['id']] = $e;
                foreach ($e['vistas'] as $v) {
                    self::$porVista[$v] = $e['id'];
                }
                foreach ($e['tarefas'] as $t) {
                    self::$tarefas[$t['chave']] = $e['id'];
                }
            }
        }
    }
}
