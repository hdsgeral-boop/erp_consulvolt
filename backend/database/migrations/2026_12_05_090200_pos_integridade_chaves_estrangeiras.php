<?php

use App\Support\POS\IntegridadePOS;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F0 do POS: chaves estrangeiras em falta (hoje só `sessoes_pos.operador_id` → `utilizadores`, ON DELETE SET NULL: o nome do
 * operador fica em `nome_operador`). Só se cria (NOT VALID + VALIDATE) se não houver órfãos; senão aviso no log, sem falhar.
 * As restantes colunas *_id das tabelas do POS já têm FK e índice (verificado na base). Nunca altera nem apaga dados.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new IntegridadePOS(DB::connection()))->aplicar(['fk']);
    }

    public function down(): void
    {
        foreach (IntegridadePOS::definicoes() as $d) {
            if ($d['tipo'] === 'fk') {
                DB::statement("ALTER TABLE {$d['tabela']} DROP CONSTRAINT IF EXISTS {$d['nome']}");
            }
        }
    }
};
