<?php

namespace App\Services\Gestao\Paineis;

/**
 * Um painel de módulo (CONSTRUTORES, ui_painel_modulos.js:131-844). Devolve os dados agregados do período para a empresa
 * activa; o frontend desenha os cartões, os gráficos e as tabelas.
 *
 * Filtros recebidos: unidade_negocio_id, centro_custo_id, iva (sem | com) e, internamente, modulos_visiveis (painéis a que o
 * utilizador tem acesso), ver_salarios (est_ver_salarios) e origem_holding (linhas de uma empresa na holding).
 */
interface Painel
{
    /**
     * @param  array<string, mixed>  $f
     * @return array{aviso?: ?string, kpis: list<array<string, mixed>>, graficos?: list<array<string, mixed>>, tabelas?: list<array<string, mixed>>, atalhos?: list<array<string, string>>}
     */
    public function construir(PeriodoPainel $p, array $f): array;

    /** Suporta os filtros por unidade de negócio e centro de custo. */
    public function filtraDimensoes(): bool;
}
