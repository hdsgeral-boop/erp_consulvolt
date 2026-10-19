<?php

namespace App\Console\Commands;

use App\Support\Tenancy\IsolamentoRls;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SaaS fase 2 (ADR-073): aplica (idempotente) a RLS por tenant a todas as tabelas com `empresa_id` e prepara a role
 * `erp_app`. Usar a ligação do dono da base (`--database=pgsql_owner`, ou SAAS_LIGACAO_DONO).
 */
final class AplicarRlsSaasCommand extends Command
{
    protected $signature = 'erp:saas:aplicar-rls
        {--verificar : Só lista as tabelas sem política (sai com 1 se houver alguma); não altera nada}
        {--sem-role : Não cria/actualiza a role erp_app}
        {--database= : Ligação com permissão de DDL (por omissão, SAAS_LIGACAO_DONO ou a ligação por omissão)}';

    protected $description = 'Aplica a RLS por tenant às tabelas com empresa_id sem política e prepara a role erp_app';

    public function handle(): int
    {
        $ligacao = $this->option('database') ?: config('erp.saas.ligacao_dono');
        $rls = new IsolamentoRls(DB::connection($ligacao));

        $faltam = $rls->tabelasSemPolitica();

        if ($this->option('verificar')) {
            if ($faltam === []) {
                $this->info('Todas as tabelas com empresa_id têm a política de isolamento.');

                return self::SUCCESS;
            }
            $this->error(count($faltam).' tabela(s) sem política: '.implode(', ', $faltam));

            return self::FAILURE;
        }

        $feitas = $rls->aplicarATodas();
        $this->info(count($feitas).' tabela(s) protegida(s) agora'.($feitas ? ': '.implode(', ', $feitas) : '.'));

        if (! $this->option('sem-role')) {
            $rls->prepararRole((string) env('DB_APP_PASSWORD', ''));
            $this->info('Role erp_app preparada.');
        }

        return self::SUCCESS;
    }
}
