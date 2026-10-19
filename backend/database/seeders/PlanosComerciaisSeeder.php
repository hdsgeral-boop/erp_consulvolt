<?php

namespace Database\Seeders;

use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semeador dos planos comerciais iniciais do SaaS (ADR-074, ADR-076).
 * Cria os planos Básico, Profissional e Empresarial com limites de empresas/utilizadores
 * e publica a versão 1 de cada plano com os respectivos módulos incluídos.
 */
class PlanosComerciaisSeeder extends Seeder
{
    public function run(): void
    {
        $planos = [
            [
                'codigo' => 'basico',
                'nome' => 'Plano Básico',
                'descricao' => 'Para profissionais liberais e microempresas. Gestão essencial de faturação certificada AGT, ponto de venda (POS), stock e tesouraria.',
                'preco_mensal' => 9500.00,
                'moeda' => 'AOA',
                'max_empresas' => 1,
                'max_utilizadores' => 3,
                'ativo' => true,
                'ordem' => 1,
                'modulos' => ['vendas', 'pos', 'stock', 'teso'],
            ],
            [
                'codigo' => 'profissional',
                'nome' => 'Plano Profissional',
                'descricao' => 'Para pequenas e médias empresas. Gestão integrada com compras, contabilidade geral, processamento salarial e RH.',
                'preco_mensal' => 15000.00,
                'moeda' => 'AOA',
                'max_empresas' => 3,
                'max_utilizadores' => 10,
                'ativo' => true,
                'ordem' => 2,
                'modulos' => ['vendas', 'pos', 'compras', 'stock', 'teso', 'contab', 'rh'],
            ],
            [
                'codigo' => 'empresarial',
                'nome' => 'Plano Empresarial',
                'descricao' => 'Para médias e grandes empresas ou grupos empresariais. Todos os módulos do ERP disponíveis (projetos, ativos, orçamento e CRM) com limites alargados.',
                'preco_mensal' => 21000.00,
                'moeda' => 'AOA',
                'max_empresas' => 10,
                'max_utilizadores' => 50,
                'ativo' => true,
                'ordem' => 3,
                'modulos' => ['vendas', 'pos', 'compras', 'stock', 'teso', 'contab', 'rh', 'activos', 'crm', 'projectos', 'orcamento'],
            ],
        ];

        DB::transaction(function () use ($planos) {
            foreach ($planos as $dados) {
                $modulos = $dados['modulos'];
                unset($dados['modulos']);

                $plano = PlanoLicenca::query()->updateOrCreate(
                    ['codigo' => $dados['codigo']],
                    $dados
                );

                // Criar ou actualizar a versão 1 como publicada em vigor
                PlanoLicencaVersao::query()->updateOrCreate(
                    ['plano_id' => $plano->id, 'numero' => 1],
                    [
                        'estado' => PlanoLicencaVersao::PUBLICADA,
                        'preco_mensal' => $plano->preco_mensal,
                        'moeda' => $plano->moeda,
                        'max_empresas' => $plano->max_empresas,
                        'max_utilizadores' => $plano->max_utilizadores,
                        'modulos' => $modulos,
                        'publicada_em' => now(),
                    ]
                );
            }
        });
    }
}
