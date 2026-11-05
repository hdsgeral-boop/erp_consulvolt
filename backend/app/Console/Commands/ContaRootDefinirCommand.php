<?php

namespace App\Console\Commands;

use App\Exceptions\ErroNegocio;
use App\Services\Plataforma\ServicoContaRoot;
use Illuminate\Console\Command;

/**
 * ADR-084 — única via para marcar ou revogar a conta ROOT global (nenhuma API o faz).
 *
 *   php artisan erp:root:definir 1                  (por id; pede confirmação)
 *   php artisan erp:root:definir --nome=admin       (por nome; recusa se houver mais de uma conta com esse nome)
 *   php artisan erp:root:definir 1 --revogar        (retira a marca; recusa a última root sem --ultima)
 *   php artisan erp:root:definir 1 --confirmar      (sem pergunta, para execução não interactiva)
 */
final class ContaRootDefinirCommand extends Command
{
    protected $signature = 'erp:root:definir {utilizador_id? : ID do utilizador} {--nome= : nome de utilizador (alternativa ao ID)}
        {--revogar : retira a marca root em vez de a definir} {--ultima : permite revogar a última conta root}
        {--confirmar : confirma sem perguntar (execução não interactiva)}';

    protected $description = 'Define ou revoga a conta root global (ADR-084). Exige confirmação e regista auditoria.';

    public function handle(ServicoContaRoot $root): int
    {
        $id = $this->argument('utilizador_id');
        $nome = $this->option('nome');
        if (($id !== null) === ($nome !== null && $nome !== '')) {
            $this->error('Indique o ID do utilizador OU --nome=, nunca os dois nem nenhum.');

            return self::FAILURE;
        }

        if ($id !== null) {
            $alvo = ctype_digit((string) $id) ? $root->porId((int) $id) : null;
            if ($alvo === null) {
                $this->error("Utilizador {$id} não encontrado.");

                return self::FAILURE;
            }
        } else {
            $encontrados = $root->porNome((string) $nome);
            if ($encontrados->count() > 1) {
                $this->error("O nome de utilizador '{$nome}' é ambíguo ({$encontrados->count()} contas: ids ".$encontrados->pluck('id')->implode(', ').'). Use o ID.');

                return self::FAILURE;
            }
            $alvo = $encontrados->first();
            if ($alvo === null) {
                $this->error("Nenhum utilizador com o nome '{$nome}'.");

                return self::FAILURE;
            }
        }

        $revogar = (bool) $this->option('revogar');
        $this->line("Conta: #{$alvo->id} '{$alvo->nome_utilizador}' · papel {$alvo->papel} · tenant {$alvo->tenant_id} · ".($alvo->ativo ? 'activa' : 'INACTIVA')
            .' · root actual: '.($alvo->conta_root ? 'sim' : 'não'));

        if ($revogar && ! $alvo->conta_root) {
            $this->info('Esta conta já não é root. Nada a fazer.');

            return self::SUCCESS;
        }
        if (! $revogar && $alvo->conta_root) {
            $this->info('Esta conta já é root. Nada a fazer.');

            return self::SUCCESS;
        }

        $pergunta = $revogar
            ? 'Revogar a conta root? Perde o acesso total a todos os clientes.'
            : 'Tornar esta conta ROOT? Fica com acesso a TODOS os clientes, empresas, planos e permissões (acções auditadas).';
        if (! $this->option('confirmar')) {
            if (! $this->input->isInteractive()) {
                $this->warn('Execução não interactiva: use --confirmar para confirmar.');

                return self::FAILURE;
            }
            if (! $this->confirm($pergunta, false)) {
                $this->warn('Cancelado.');

                return self::FAILURE;
            }
        }

        try {
            $operador = (string) (get_current_user() ?: 'desconhecido');
            if ($revogar) {
                $root->revogar($alvo, $operador, (bool) $this->option('ultima'));
            } else {
                $root->definir($alvo, $operador);
            }
        } catch (ErroNegocio $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($revogar ? 'Conta root revogada.' : 'Conta root definida.');

        return self::SUCCESS;
    }
}
