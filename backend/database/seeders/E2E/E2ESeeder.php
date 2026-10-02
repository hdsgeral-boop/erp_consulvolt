<?php

namespace Database\Seeders\E2E;

use App\Models\Empresa;
use App\Models\Moeda;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dados FICTÍCIOS do ambiente E2E (Playwright) — corre só através de `php artisan erp:e2e:preparar`.
 *
 * Duas empresas de demonstração (a primeira com todos os dados; a segunda vazia, para testar a escolha de empresa),
 * cinco utilizadores com a mesma palavra-passe de teste e perfis próprios, e os dados mínimos coerentes para os fluxos
 * de Vendas, POS, Compras, Contabilidade, RH/Salários e Configurações. Nomes e NIF claramente fictícios (prefixo 5999).
 */
final class E2ESeeder extends Seeder
{
    public const PALAVRA_PASSE = 'E2e#Teste2026';

    public const EMPRESA_DEMO = 'Demo E2E Comércio, Lda';

    public const EMPRESA_VAZIA = 'Demo E2E Serviços, Lda';

    /**
     * Logótipo FICTÍCIO da empresa de demonstração (PNG 64×64, 206 bytes: quadrado azul com as letras «DE»), para a
     * barra do menu e o cabeçalho das impressões. Respeita ServicoGestaoEmpresas::validarLogotipo (data URI PNG ≤ 1 MB).
     */
    public const LOGOTIPO_DEMO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAlUlEQVR42u3aQQqAIBAFUG/S0bphV7RFEIEQiFZOPpm1/LdQcZiUi7Ws27BVpk0hct9IUsT0V0MKmv40AHwOiJv+KAAAAAAAAACAJwC5ctX+pHrtDADwBqC2X9C+W+dDDAAAAAAwEKD98QIAiA1wCwEAAABEA+hKAMwI0J0GAAAAAAAAmAFgahGgHRB+9PgPw99xx+93kLk9UqNFqX4AAAAASUVORK5CYII=';

    /** nome de utilizador => descrição */
    public const UTILIZADORES = [
        'e2e.admin' => 'super-administrador (acesso total, todas as empresas)',
        'e2e.vendas' => 'só Vendas e Facturação (empresa de demonstração)',
        'e2e.pos' => 'operador de POS (frente de caixa)',
        'e2e.rh' => 'Recursos Humanos e Salários',
        'e2e.aprovador' => 'aprovador de pedidos de compra (deliberação nível 1)',
    ];

    public function run(): void
    {
        $base = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($base, '_e2e')) {
            throw new RuntimeException("O seeder E2E só corre numa base *_e2e (base actual: {$base}).");
        }

        foreach ([['AOA', 'Kwanza', 'Kz'], ['USD', 'Dólar dos EUA', 'US$'], ['EUR', 'Euro', '€']] as [$codigo, $nome, $simbolo]) {
            Moeda::query()->updateOrCreate(['codigo' => $codigo], ['nome' => $nome, 'simbolo' => $simbolo, 'casas_decimais' => 2, 'ativo' => true]);
        }

        $demo = Empresa::create([
            'nome' => self::EMPRESA_DEMO, 'nif' => '5999000001', 'estado' => Empresa::ESTADO_ATIVO, 'moeda_funcional' => 'AOA',
            'endereco' => 'Rua Fictícia n.º 1, Luanda', 'provincia' => 'Luanda', 'municipio' => 'Luanda', 'telefone' => '+244 900 000 001',
            'email' => 'demo-e2e@exemplo.invalid', 'taxa_inss_trabalhador' => 3, 'taxa_inss_patronal' => 8,
            'logotipo' => self::LOGOTIPO_DEMO,
        ]);
        $vazia = Empresa::create(['nome' => self::EMPRESA_VAZIA, 'nif' => '5999000002', 'estado' => Empresa::ESTADO_ATIVO, 'moeda_funcional' => 'AOA']);

        $contexto = app(ContextoEmpresa::class);
        $contexto->executarComo($demo->id, function () {
            app(E2EContabilidadeSeeder::class)->executar();
            app(E2EComercialSeeder::class)->executar();
            app(E2ERHSeeder::class)->executar();
        });
        $contexto->executarComo($vazia->id, fn () => app(E2EContabilidadeSeeder::class)->executar(false));

        $this->utilizadores($demo);
    }

    private function utilizadores(Empresa $demo): void
    {
        $admin = Utilizador::create(['nome_utilizador' => 'e2e.admin', 'nome_completo' => 'Administrador E2E', 'email' => 'admin@exemplo.invalid',
            'palavra_passe' => self::PALAVRA_PASSE, 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true, 'ativo' => true]);
        // o administrador é também colaborador da empresa de demonstração: o Portal do Colaborador abre com dados próprios
        $colaborador = DB::table('colaboradores')->where('empresa_id', $demo->id)->where('nif', '5999100002')->value('id');
        $admin->empresas()->attach($demo->id, ['colaborador_id' => $colaborador]);

        $perfis = [
            'e2e.vendas' => ['Vendas (E2E)', 'Operadora Vendas E2E', ['dashboard_view', 'vendas_clientes_view', 'vendas_produtos_view', 'vendas_faturacao_view',
                'vendas_relatorios_view', 'vendas_fat_emitir', 'vendas_recibos', 'vendas_clientes_gerir']],
            'e2e.pos' => ['Operador POS (E2E)', 'Operador POS E2E', ['pos_view', 'pos_venda', 'pos_fecho', 'pos_relatorios_view']],
            'e2e.rh' => ['Recursos Humanos (E2E)', 'Técnica RH E2E', ['colaboradores_view', 'colaboradores_detail', 'contratos_view', 'contratos_new',
                'funcoes_view', 'infotipos_view', 'calcular_view', 'calcular_lancar', 'calcular_bulk', 'calcular_folha', 'rh_lanc_del', 'processamento_view',
                'relatorios_view', 'rh_rel_remuneracoes_view', 'rh_rel_irt_view', 'rh_rel_inss_view', 'rh_rel_pagamentos_view', 'rh_rel_banco_view',
                'rh_rel_recibos_view', 'rh_recibos_emitir', 'rh_ferias_view', 'rh_assiduidade_view', 'bancario_view']],
            'e2e.aprovador' => ['Aprovador de compras (E2E)', 'Aprovador Compras E2E', ['compras_pedidos_view', 'compras_ped_aprovar']],
        ];
        foreach ($perfis as $nomeUtilizador => [$perfilNome, $nomeCompleto, $permissoes]) {
            $perfil = PerfilUtilizador::create(['nome' => $perfilNome, 'descricao' => 'Perfil fictício dos testes E2E',
                'permissoes' => ['_v2' => true] + array_fill_keys($permissoes, true)]);
            $u = Utilizador::create(['nome_utilizador' => $nomeUtilizador, 'nome_completo' => $nomeCompleto, 'email' => str_replace('.', '-', $nomeUtilizador).'@exemplo.invalid',
                'palavra_passe' => self::PALAVRA_PASSE, 'papel' => Utilizador::PAPEL_UTILIZADOR, 'perfil_utilizador_id' => $perfil->id, 'ativo' => true]);
            $u->empresas()->attach($demo->id);
        }
    }
}
