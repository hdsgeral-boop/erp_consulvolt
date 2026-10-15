<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase B (Versão Actual, ADR-071) — RH: colunas que o legado novo grava e que o esquema gerado do backup não trouxe
 * (0 exemplos no backup). Migração ADITIVA e com guardas (pode correr sobre uma base já migrada):
 *   - contratos_trabalho: suspensão e cessação do contrato (js/modules/rh/contrato_estado.js:146-236):
 *     suspensao/suspensoes[] e cessacao/cessacoes_anuladas[] (JSONB, com o fundamento, o artigo da Lei 12/23 e quem registou);
 *   - colaboradores.data_cessacao (contrato_estado.js:217);
 *   - prestacoes_emprestimos: ligação ao lançamento do processamento (payroll_entry_id, descontado_em — emprestimos.js:254);
 *   - emprestimos_colaboradores: liquidado_em e decidido_em (emprestimos.js:168, 258);
 *   - índices de consulta da escala (por colaborador e dia) e das prestações (por mês).
 */
return new class extends Migration
{
    /** @var array<string, array<string, array{0: string, 1: ?int, 2: string}>> tabela => coluna => [tipo, tamanho, comentário] */
    private const COLUNAS = [
        'contratos_trabalho' => [
            'suspensao' => ['jsonb', null, 'legado: suspensao · suspensão em vigor {de, ate, motivo_codigo, motivo, artigo, lado, observacoes, registado_em, registado_por}'],
            'suspensoes' => ['jsonb', null, 'legado: suspensoes · histórico das suspensões (lista)'],
            'cessacao' => ['jsonb', null, 'legado: cessacao · cessação {data, modalidade, motivo_codigo, artigo, aviso_previo_dias, antiguidade_anos, salario_base, compensacao_*}'],
            'cessacoes_anuladas' => ['jsonb', null, 'legado: cessacoes_anuladas · histórico das cessações anuladas (lista)'],
        ],
        'colaboradores' => [
            'data_cessacao' => ['date', null, 'legado: data_cessacao · data da cessação do contrato (Fase B)'],
        ],
        'prestacoes_emprestimos' => [
            'linha_folha_salarial_id' => ['bigint', null, 'legado: payroll_entry_id · lançamento do processamento que descontou a prestação'],
            'periodo_processamento_salarial_id' => ['bigint', null, 'Processamento salarial em que a prestação foi descontada'],
            'descontado_em' => ['timestamptz', null, 'legado: descontado_em'],
            'descontado_por' => ['varchar', 100, 'Utilizador que importou a prestação no cálculo'],
        ],
        'emprestimos_colaboradores' => [
            'decidido_em' => ['timestamptz', null, 'legado: decidido_em · recusa/cancelamento pelo circuito do portal'],
            'liquidado_em' => ['timestamptz', null, 'legado: liquidado_em'],
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUNAS as $tabela => $colunas) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }
            Schema::table($tabela, function (Blueprint $t) use ($tabela, $colunas) {
                foreach ($colunas as $nome => [$tipo, $tamanho, $comentario]) {
                    if (Schema::hasColumn($tabela, $nome)) {
                        continue;
                    }
                    $col = match ($tipo) {
                        'jsonb' => $t->jsonb($nome),
                        'date' => $t->date($nome),
                        'bigint' => $t->bigInteger($nome),
                        'timestamptz' => $t->timestampTz($nome),
                        default => $t->string($nome, $tamanho ?? 255),
                    };
                    $col->nullable()->comment($comentario);
                }
            });
        }

        if (Schema::hasTable('escalas_trabalho')) {
            DB::statement('CREATE INDEX IF NOT EXISTS ix_escalas_trabalho_empresa_colaborador_dia ON escalas_trabalho (empresa_id, colaborador_id, dia)');
            DB::statement('CREATE INDEX IF NOT EXISTS ix_escalas_trabalho_empresa_dia ON escalas_trabalho (empresa_id, dia)');
        }
        if (Schema::hasTable('prestacoes_emprestimos')) {
            DB::statement('CREATE INDEX IF NOT EXISTS ix_prestacoes_emprestimos_empresa_mes ON prestacoes_emprestimos (empresa_id, mes_ano)');
            DB::statement('CREATE INDEX IF NOT EXISTS ix_prestacoes_emprestimos_linha_folha ON prestacoes_emprestimos (linha_folha_salarial_id)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_prestacoes_emprestimos_linha_folha');
        DB::statement('DROP INDEX IF EXISTS ix_prestacoes_emprestimos_empresa_mes');
        DB::statement('DROP INDEX IF EXISTS ix_escalas_trabalho_empresa_dia');
        DB::statement('DROP INDEX IF EXISTS ix_escalas_trabalho_empresa_colaborador_dia');
        foreach (self::COLUNAS as $tabela => $colunas) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }
            Schema::table($tabela, function (Blueprint $t) use ($tabela, $colunas) {
                foreach (array_keys($colunas) as $nome) {
                    if (Schema::hasColumn($tabela, $nome)) {
                        $t->dropColumn($nome);
                    }
                }
            });
        }
    }
};
