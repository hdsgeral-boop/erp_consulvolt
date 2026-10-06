<?php

use App\Services\Sistema\ServicoPermissoesNovas;
use Illuminate\Database\Migrations\Migration;

/**
 * Decisão 1 do utilizador (ronda 2): a tabela de IRT passa a configurável. Tarefa nova no catálogo,
 * rh_tabela_irt_gerir, atribuída aos perfis que hoje gerem as rubricas salariais (rh_infotipos_gerir), para ninguém
 * perder a possibilidade de manter as regras salariais. Lógica em ServicoPermissoesNovas, que a migração do legado
 * volta a aplicar depois de carregar os perfis (numa base nova esta migração corre com a tabela de perfis vazia).
 * down() retira a chave.
 */
return new class extends Migration
{
    public function up(): void
    {
        ServicoPermissoesNovas::aplicar(['rh_tabela_irt_gerir']);
    }

    public function down(): void
    {
        ServicoPermissoesNovas::retirar(['rh_tabela_irt_gerir']);
    }
};
