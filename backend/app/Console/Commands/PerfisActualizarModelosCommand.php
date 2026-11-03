<?php

namespace App\Console\Commands;

use App\Services\Sistema\ServicoPerfis;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Acrescenta aos perfis existentes as permissões novas dos perfis-modelo (Fase K, ADR-079; permActualizarModelos do legado):
 * os perfis com o nome de um modelo ganham as permissões do modelo que lhes faltam — nunca se retira nada. Perfis de acesso total e
 * os que não estão no formato v2 ficam de fora. Idempotente. Corre-se à mão após cada deploy que acrescente permissões ao catálogo
 * (não está no scheduler). `--simular` só mostra o que acrescentaria. Percorre todos os tenants (ContextoTenant::porTenant); a falha
 * de um não impede os outros (os perfis são globais à instalação: a segunda passagem já não encontra nada por acrescentar).
 */
final class PerfisActualizarModelosCommand extends Command
{
    protected $signature = 'erp:perfis:actualizar-modelos {--simular : só mostra o que acrescentaria, sem gravar}';

    protected $description = 'Acrescenta aos perfis existentes as permissões novas dos perfis-modelo (nunca retira)';

    public function handle(ServicoPerfis $perfis, ContextoTenant $tenant): int
    {
        $simular = (bool) $this->option('simular');
        $falhas = 0;
        $acrescentos = 0;

        $tenant->porTenant(function (?int $id) use ($perfis, $simular, &$falhas, &$acrescentos) {
            $rotulo = $id === null ? 'Instalação' : "Tenant {$id}";
            try {
                $alteracoes = $perfis->actualizarModelos($simular);
            } catch (Throwable $e) {
                $falhas++;
                report($e);
                $this->error("{$rotulo}: falhou — {$e->getMessage()}");

                return;
            }
            if ($alteracoes === []) {
                $this->line("{$rotulo}: os perfis já têm as permissões dos modelos.");

                return;
            }
            foreach ($alteracoes as $a) {
                $acrescentos += count($a['acrescentar']);
                $this->info("{$rotulo} · perfil «{$a['nome']}» (#{$a['perfil_id']}): ".($simular ? 'acrescentaria ' : 'acrescentou ').implode(', ', $a['acrescentar']));
            }
        });

        $this->line(($simular ? 'Simulação: nada foi gravado. ' : '')."{$acrescentos} permissão(ões) ".($simular ? 'a acrescentar' : 'acrescentada(s)').($falhas ? "; {$falhas} falha(s)" : '').'.');

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
