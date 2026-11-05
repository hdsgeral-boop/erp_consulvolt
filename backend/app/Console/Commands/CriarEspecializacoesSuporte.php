<?php

namespace App\Console\Commands;

use App\Services\Plataforma\ServicoEspecializacoesSuporte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ADR-077: garante que as especializações base do backoffice de suporte existem (Geral, Técnico, Financeiro,
 * Marketing e Comunicação, todas as categorias). Idempotente; nunca altera nem apaga perfis existentes.
 */
final class CriarEspecializacoesSuporte extends Command
{
    protected $signature = 'erp:suporte:criar-especializacoes {--simular : mostra o que seria criado e desfaz tudo (ROLLBACK)}';

    protected $description = 'Cria as especializações base do backoffice de suporte que ainda não existem (ADR-077)';

    public function handle(ServicoEspecializacoesSuporte $servico): int
    {
        $simular = (bool) $this->option('simular');
        DB::beginTransaction();
        try {
            $criados = $servico->garantirBase();
            $simular ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('NÃO APLICADA: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($criados === []) {
            $this->info('As especializações base já existem. Nada a fazer.');

            return self::SUCCESS;
        }
        foreach ($criados as $nome) {
            $this->line(($simular ? 'Seria criada: ' : 'Criada: ').$nome);
        }
        $this->info($simular ? 'Simulação: nada foi gravado (ROLLBACK).' : 'Gravado.');

        return self::SUCCESS;
    }
}
