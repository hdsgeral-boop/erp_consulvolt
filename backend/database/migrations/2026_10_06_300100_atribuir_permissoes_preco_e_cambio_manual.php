<?php

use App\Services\Sistema\ServicoPermissoesNovas;
use Illuminate\Database\Migrations\Migration;

/**
 * Decisões 8 e 9 do utilizador (ronda 2): duas tarefas novas no catálogo de permissões:
 *   - vendas_alterar_preco — alterar o preço da ficha nos documentos de venda: perfis com vendas_fat_emitir;
 *   - cambio_manual_fora_tolerancia — câmbio manual acima da tolerância face ao câmbio do dia: só perfis com
 *     config_moedas_gerir (excepção de controlo para quem gere moedas e câmbios; o acesso total já a tem).
 * Lógica em ServicoPermissoesNovas, que a migração do legado volta a aplicar depois de carregar os perfis.
 * down() retira as chaves.
 */
return new class extends Migration
{
    private const CHAVES = ['vendas_alterar_preco', 'cambio_manual_fora_tolerancia'];

    public function up(): void
    {
        ServicoPermissoesNovas::aplicar(self::CHAVES);
    }

    public function down(): void
    {
        ServicoPermissoesNovas::retirar(self::CHAVES);
    }
};
