<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Support\Tenancy\ModulosPlano;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Licenças (pedido de 2026-10-22): 4 planos na plataforma — Interno (sem limites, sem custo, não aparece aos clientes)
 * e os 3 comerciais (os do PlanosComerciaisSeeder) — cada um com a versão 1 publicada. O plano Interno fica atribuído aos
 * clientes consulvolt e grupoglomeu, e as empresas deles ficam com licença sem limite (não entram em teste nem expiram
 * com as licenças activas). Só cria o que falta: planos já existentes (mesmo código) não são alterados.
 * Corre uma vez pela migração 2026_10_22_100000; pode repetir-se.
 */
final class PrepararPlanosIniciais extends Command
{
    public const CLIENTES_INTERNOS = ['consulvolt', 'grupoglomeu'];

    protected $signature = 'erp:licencas:planos-iniciais';

    protected $description = 'Cria os 4 planos iniciais (Interno + 3 comerciais) e atribui o Interno a consulvolt e grupoglomeu';

    public function handle(): int
    {
        if (! Schema::hasTable('planos_licenca') || ! Schema::hasTable('planos_licenca_versoes')) {
            $this->warn('Sem as tabelas de planos: nada a fazer.');

            return self::SUCCESS;
        }
        $todos = array_keys((array) config('erp.planos_catalogo.modulos'));
        $planos = [
            ['codigo' => Empresa::PLANO_INTERNO, 'nome' => 'Plano Interno', 'descricao' => 'Plano interno da equipa: sem limite de empresas nem de utilizadores, todos os módulos, sem custo.',
                'preco_mensal' => 0, 'max_empresas' => null, 'max_utilizadores' => null, 'ativo' => false, 'ordem' => 0, 'modulos' => $todos],
            ['codigo' => 'basico', 'nome' => 'Plano Básico', 'descricao' => 'Para profissionais liberais e microempresas. Gestão essencial de faturação certificada AGT, ponto de venda (POS), stock e tesouraria.',
                'preco_mensal' => 9500, 'max_empresas' => 1, 'max_utilizadores' => 3, 'ativo' => true, 'ordem' => 1, 'modulos' => ['vendas', 'pos', 'stock', 'teso']],
            ['codigo' => 'profissional', 'nome' => 'Plano Profissional', 'descricao' => 'Para pequenas e médias empresas. Gestão integrada com compras, contabilidade geral, processamento salarial e RH.',
                'preco_mensal' => 15000, 'max_empresas' => 3, 'max_utilizadores' => 10, 'ativo' => true, 'ordem' => 2, 'modulos' => ['vendas', 'pos', 'compras', 'stock', 'teso', 'contab', 'rh']],
            ['codigo' => 'empresarial', 'nome' => 'Plano Empresarial', 'descricao' => 'Para médias e grandes empresas ou grupos empresariais. Todos os módulos do ERP disponíveis (projetos, ativos, orçamento e CRM) com limites alargados.',
                'preco_mensal' => 21000, 'max_empresas' => 10, 'max_utilizadores' => 50, 'ativo' => true, 'ordem' => 3, 'modulos' => $todos],
        ];

        // ligação do dono (sem RLS): actua sobre clientes e empresas de todos os tenants
        $db = DB::connection(config('erp.saas.ligacao_dono') ?: null);
        $criados = [];
        $clientes = $db->transaction(function () use ($db, $planos, &$criados) {
            $agora = now();
            foreach ($planos as $p) {
                if ($db->table('planos_licenca')->where('codigo', $p['codigo'])->exists()) {
                    continue;
                }
                $modulos = $p['modulos'];
                unset($p['modulos']);
                $id = $db->table('planos_licenca')->insertGetId($p + ['moeda' => 'AOA', 'criado_em' => $agora, 'atualizado_em' => $agora]);
                $db->table('planos_licenca_versoes')->insert([
                    'plano_id' => $id, 'numero' => 1, 'estado' => 'PUBLICADA', 'preco_mensal' => $p['preco_mensal'], 'moeda' => 'AOA',
                    'max_empresas' => $p['max_empresas'], 'max_utilizadores' => $p['max_utilizadores'],
                    'modulos' => json_encode($modulos), 'publicada_em' => $agora, 'criado_em' => $agora,
                ]);
                $criados[] = $p['codigo'];
            }

            // o operador da plataforma não tem plano (sem limites por natureza)
            $ids = $db->table('tenants')->whereIn('slug', self::CLIENTES_INTERNOS)
                ->where('id', '<>', (int) config('erp.dominio.plataforma_tenant_id', 1))->pluck('id');
            if ($ids->isNotEmpty()) {
                $db->table('tenants')->whereIn('id', $ids)->update(['plano' => Empresa::PLANO_INTERNO, 'licenca_origem' => Empresa::LICENCA_SEM_LIMITE, 'licenca_inicio' => null, 'licenca_fim' => null]);
                $db->table('empresas')->whereIn('tenant_id', $ids)
                    ->update(['licenca_origem' => Empresa::LICENCA_SEM_LIMITE, 'licenca_fim' => null]);
            }

            return $ids->count();
        });
        ModulosPlano::invalidar();
        $this->info('Planos criados: '.($criados ? implode(', ', $criados) : 'nenhum (já existiam)').". Clientes com o plano interno: {$clientes}.");

        return self::SUCCESS;
    }
}
