<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use App\Support\Tenancy\ModulosPlano;
use Database\Seeders\PlanosComerciaisSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planos iniciais da plataforma: Interno (sem limites, sem custo, não aparece aos clientes) e os 3 comerciais (definidos
 * em PlanosComerciaisSeeder, a fonte única), cada um com a versão 1 publicada. Só cria o que FALTA: planos que já existem
 * (mesmo código) nunca são alterados — preços, limites e módulos em vigor ficam como estão.
 *
 * Ao contrário da produção, NÃO atribui o plano Interno a nenhum cliente por omissão (os clientes de produção não existem
 * aqui e não se mexe em licenças reais). Para atribuir, indicar explicitamente: --atribuir-interno=slug1,slug2 — o cliente
 * fica com o plano `interno` e as suas empresas com licença sem limite (activa).
 */
final class PrepararPlanosIniciais extends Command
{
    protected $signature = 'erp:licencas:planos-iniciais
        {--atribuir-interno= : Slugs de clientes (separados por vírgula) a quem atribuir o plano Interno e licença sem limite}';

    protected $description = 'Cria os planos iniciais em falta (Interno + 3 comerciais); só atribui o Interno quando pedido';

    public function handle(): int
    {
        if (! Schema::hasTable('planos_licenca') || ! Schema::hasTable('planos_licenca_versoes')) {
            $this->warn('Sem as tabelas de planos: nada a fazer.');

            return self::SUCCESS;
        }

        $criados = [];
        if (! PlanoLicenca::query()->where('codigo', CriarPlanoInterno::CODIGO)->exists()) {
            $this->call('erp:planos:criar-interno');
            // o plano interno não é oferecido aos clientes
            PlanoLicenca::query()->where('codigo', CriarPlanoInterno::CODIGO)->update(['ativo' => false]);
            $criados[] = CriarPlanoInterno::CODIGO;
        }

        DB::transaction(function () use (&$criados) {
            foreach (PlanosComerciaisSeeder::planos() as $dados) {
                if (PlanoLicenca::query()->where('codigo', $dados['codigo'])->exists()) {
                    continue;
                }
                $modulos = $dados['modulos'];
                unset($dados['modulos']);
                $plano = PlanoLicenca::query()->create($dados);
                PlanoLicencaVersao::query()->create([
                    'plano_id' => $plano->id, 'numero' => 1, 'estado' => PlanoLicencaVersao::PUBLICADA, 'preco_mensal' => $plano->preco_mensal,
                    'moeda' => $plano->moeda, 'max_empresas' => $plano->max_empresas, 'max_utilizadores' => $plano->max_utilizadores,
                    'modulos' => $modulos, 'publicada_em' => now(),
                ]);
                $criados[] = $dados['codigo'];
            }
        });
        ModulosPlano::invalidar();

        $atribuidos = $this->atribuirInterno($this->slugs());
        $this->info('Planos criados: '.($criados ? implode(', ', $criados) : 'nenhum (já existiam)').". Clientes com o plano interno atribuído agora: {$atribuidos}.");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function slugs(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->option('atribuir-interno')))));
    }

    /** @param  list<string>  $slugs */
    private function atribuirInterno(array $slugs): int
    {
        if ($slugs === []) {
            return 0;
        }
        // ligação do dono (sem RLS): actua sobre clientes e empresas de todos os tenants
        $db = DB::connection(config('erp.saas.ligacao_dono') ?: null);

        return $db->transaction(function () use ($db, $slugs) {
            // a própria plataforma não tem plano (sem limites por natureza)
            $ids = $db->table('tenants')->whereIn('slug', $slugs)->where('id', '<>', (int) config('erp.dominio.plataforma_tenant_id', 1))->pluck('id');
            if ($ids->isNotEmpty()) {
                $db->table('tenants')->whereIn('id', $ids)->update(['plano' => CriarPlanoInterno::CODIGO]);
                $db->table('empresas')->whereIn('tenant_id', $ids)->update([
                    'licenca_origem' => Empresa::LICENCA_SEM_LIMITE, 'licenca_fim' => null,
                    'estado_licenca' => Empresa::LICENCA_ACTIVA, 'motivo_suspensao' => null,
                ]);
            }

            return $ids->count();
        });
    }
}
