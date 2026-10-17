<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deliberação dos pedidos de compra — 1.º nível pelo responsável da unidade orgânica do requisitante
 * (aprovadorPrimeiroNivel / podeDecidir, js/compras_deliberacao.js:99-149). Dados fictícios.
 */
final class ComprasDeliberacaoResponsavelTest extends TestCase
{
    private Empresa $empresa;

    /** @var array<string, int> */
    private array $ids = [];

    private const CRIAR = ['compras_pedidos_view', 'compras_ped_criar'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->em(function () {
            PlanoConta::create(['codigo' => '3211', 'descricao' => 'Fornecedores', 'tipo' => 'M']);
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n, array $x = []) => Colaborador::create($x + ['nome_completo' => $n, 'nif' => "NIF{$n}", 'numero_inss' => "INSS{$n}", 'estado' => 'ACTIVO',
                'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => '2020-03-02'])->id;
            $dir = UnidadeOrganica::create(['nome' => 'Direcção Fictícia', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'ativo' => true])->id;
            $dep = UnidadeOrganica::create(['nome' => 'Departamento Fictício', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir, 'ativo' => true])->id;
            $chefe = $mk('Chefe Teste', ['unidade_organica_id' => $dir]);
            $ana = $mk('Ana Teste', ['unidade_organica_id' => $dep]);
            $semUnidade = $mk('Sem Unidade');
            UnidadeOrganica::query()->whereKey($dir)->update(['colaborador_responsavel_id' => $chefe]);
            $cc = CentroCusto::create(['codigo' => 'CCX', 'descricao' => 'Centro fictício'])->id;
            $produto = Produto::create(['codigo' => 'P1', 'nome' => 'Artigo fictício', 'preco_unitario' => 10, 'taxa_imposto' => 14, 'movimenta_stock' => false])->id;
            $fornecedor = Terceiro::create(['nome' => 'Fornecedor Fictício', 'nif' => '5000000011', 'tipo' => Terceiro::FORNECEDOR,
                'codigo_conta' => '3211'])->id;
            $this->ids = compact('dir', 'dep', 'chefe', 'ana', 'semUnidade', 'cc', 'produto', 'fornecedor');
        });
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?int $colaborador = null, array $atributos = []): array
    {
        $u = $this->criarUtilizador($atributos + ['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function pedido(array $quem, array $extra = [], float $qtd = 10): TestResponse
    {
        return $this->postJson('/api/compras/pedidos', $extra + ['nome_requerente' => 'Requisitante fictício',
            'linhas' => [['produto_id' => $this->ids['produto'], 'quantidade' => $qtd, 'preco_unitario' => 10]]], $quem)->assertCreated();
    }

    private function aprovar(int $pedido, array $quem): TestResponse
    {
        return $this->postJson("/api/compras/pedidos/{$pedido}/decidir", ['decisao' => 'APROVAR'], $quem);
    }

    #[Test]
    public function responsavel_com_utilizador_aprova_o_primeiro_nivel_e_mais_ninguem(): void
    {
        $chefe = $this->sessao(['compras_pedidos_view'], $this->ids['chefe']);   // sem a tarefa de aprovação
        $aprovador = $this->sessao(['compras_pedidos_view', 'compras_ped_aprovar']);
        // Ana (DEP, sem responsável) → sobe a DIR (responsável Chefe, com utilizador)
        $p = $this->pedido($this->sessao(self::CRIAR, $this->ids['ana']))
            ->assertJsonPath('dados.deliberacao.etapas.0.tipo', 'RESPONSAVEL')
            ->assertJsonPath('dados.deliberacao.etapas.0.employee_id', $this->ids['chefe'])
            ->assertJsonPath('dados.deliberacao.etapas.0.nome_aprovador', 'Chefe Teste (Direcção Fictícia)')
            ->assertJsonPath('dados.deliberacao.etapas.0.nota_aprovador', '')->json('dados.id');

        $this->getJson("/api/compras/pedidos/{$p}", $aprovador)->assertJsonPath('dados.pode_decidir', false)
            ->assertJsonPath('dados.motivo_decisao', 'Aguarda Chefe Teste (Direcção Fictícia).');
        $this->getJson('/api/compras/pedidos', $aprovador)->assertJsonPath('dados.0.pode_decidir', false);
        $this->getJson('/api/compras/pedidos', $chefe)->assertJsonPath('dados.0.pode_decidir', true);
        $this->aprovar($p, $aprovador)->assertStatus(403)->assertJsonPath('codigo', 'SEM_PERMISSAO_ETAPA');
        $this->aprovar($p, $chefe)->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
    }

    #[Test]
    public function requisitante_responsavel_da_propria_unidade_sobe_na_estrutura(): void
    {
        $this->em(fn () => UnidadeOrganica::query()->whereKey($this->ids['dep'])->update(['colaborador_responsavel_id' => $this->ids['ana']]));
        $this->sessao([], $this->ids['chefe']);
        $this->pedido($this->sessao(self::CRIAR, $this->ids['ana']))
            ->assertJsonPath('dados.deliberacao.etapas.0.tipo', 'RESPONSAVEL')->assertJsonPath('dados.deliberacao.etapas.0.employee_id', $this->ids['chefe']);
    }

    #[Test]
    public function responsavel_sem_utilizador_cai_na_tarefa_com_nota(): void
    {
        $aprovador = $this->sessao(['compras_pedidos_view', 'compras_ped_aprovar']);
        $p = $this->pedido($this->sessao(self::CRIAR, $this->ids['ana']))
            ->assertJsonPath('dados.deliberacao.etapas.0.tipo', 'TAREFA')->assertJsonPath('dados.deliberacao.etapas.0.tarefa', 'compras_ped_aprovar')
            ->assertJsonPath('dados.deliberacao.etapas.0.nome_aprovador', 'Utilizadores com a tarefa «Aprovar e rejeitar pedidos»')
            ->assertJsonPath('dados.deliberacao.etapas.0.nota_aprovador', 'O responsável de «Direcção Fictícia» (Chefe Teste) não tem utilizador no sistema.')
            ->json('dados.id');
        $this->aprovar($p, $aprovador)->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
    }

    #[Test]
    public function responsavel_com_utilizador_inactivo_conta_como_sem_utilizador(): void
    {
        $this->criarUtilizador(['ativo' => false])->empresas()->attach($this->empresa->id, ['colaborador_id' => $this->ids['chefe']]);
        $this->pedido($this->sessao(self::CRIAR, $this->ids['ana']))->assertJsonPath('dados.deliberacao.etapas.0.tipo', 'TAREFA')
            ->assertJsonPath('dados.deliberacao.etapas.0.nota_aprovador', 'O responsável de «Direcção Fictícia» (Chefe Teste) não tem utilizador no sistema.');
    }

    #[Test]
    public function cadeia_sem_responsavel(): void
    {
        $this->em(fn () => UnidadeOrganica::query()->whereKey($this->ids['dir'])->update(['colaborador_responsavel_id' => null]));
        $this->pedido($this->sessao(self::CRIAR, $this->ids['ana']))->assertJsonPath('dados.deliberacao.etapas.0.tipo', 'TAREFA')
            ->assertJsonPath('dados.deliberacao.etapas.0.nota_aprovador', 'A unidade do requisitante não tem responsável definido.');
    }

    #[Test]
    public function sem_colaborador_vai_ao_responsavel_do_centro_de_custo(): void
    {
        $this->em(fn () => UnidadeOrganica::query()->whereKey($this->ids['dep'])->update(['centro_custo_id' => $this->ids['cc'], 'utilizador_responsavel' => 'resp.cc.teste']));
        $resp = $this->sessao(['compras_pedidos_view'], null, ['nome_utilizador' => 'resp.cc.teste']);
        $aprovador = $this->sessao(['compras_pedidos_view', 'compras_ped_aprovar']);
        $p = $this->pedido($this->sessao(self::CRIAR), ['centro_custo_id' => $this->ids['cc']])
            ->assertJsonPath('dados.deliberacao.etapas.0.tipo', 'UTILIZADOR')->assertJsonPath('dados.deliberacao.etapas.0.utilizador', 'resp.cc.teste')
            ->assertJsonPath('dados.deliberacao.etapas.0.nome_aprovador', 'resp.cc.teste (responsável do CC/UN)')->json('dados.id');
        $this->aprovar($p, $aprovador)->assertStatus(403)->assertJsonPath('mensagem', 'Aguarda resp.cc.teste.');
        $this->aprovar($p, $resp)->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
    }

    #[Test]
    public function sem_colaborador_nem_unidade_cai_na_tarefa(): void
    {
        // o responsável do CC é o próprio criador → não conta (ninguém aprova os próprios pedidos)
        $this->em(fn () => UnidadeOrganica::query()->whereKey($this->ids['dep'])->update(['centro_custo_id' => $this->ids['cc'], 'utilizador_responsavel' => 'criador.teste']));
        $this->pedido($this->sessao(self::CRIAR, null, ['nome_utilizador' => 'criador.teste']), ['centro_custo_id' => $this->ids['cc']])
            ->assertJsonPath('dados.deliberacao.etapas.0.tipo', 'TAREFA')->assertJsonPath('dados.deliberacao.etapas.0.nota_aprovador', 'Requisitante sem unidade na Estrutura Orgânica.');
        // colaborador sem unidade e pedido sem CC/UN
        $this->pedido($this->sessao(self::CRIAR, $this->ids['semUnidade']))
            ->assertJsonPath('dados.deliberacao.etapas.0.nota_aprovador', 'Requisitante sem unidade na Estrutura Orgânica.');
    }

    #[Test]
    public function o_colaborador_requisitante_nao_aprova_o_proprio_pedido(): void
    {
        $this->em(fn () => UnidadeOrganica::query()->whereKey($this->ids['dir'])->update(['colaborador_responsavel_id' => null]));
        $p = $this->pedido($this->sessao(self::CRIAR, $this->ids['ana']))->json('dados.id');
        // outro utilizador ligado ao mesmo colaborador, com a tarefa
        $outro = $this->sessao(['compras_pedidos_view', 'compras_ped_aprovar'], $this->ids['ana']);
        $this->getJson("/api/compras/pedidos/{$p}", $outro)->assertJsonPath('dados.pode_decidir', false)
            ->assertJsonPath('dados.motivo_decisao', 'Não pode aprovar os seus próprios pedidos.');
        $this->aprovar($p, $outro)->assertStatus(403)->assertJsonPath('codigo', 'AUTO_APROVACAO');
    }

    #[Test]
    public function revisao_na_adjudicacao_acrescenta_niveis_com_aprovador(): void
    {
        $comprador = $this->sessao([...self::CRIAR, 'compras_deliberacao_config', 'compras_prospeccao_view', 'compras_new_proposal', 'compras_evaluate', 'compras_adjudicate',
            'compras_encomendas_view'], $this->ids['ana']);
        $chefe = $this->sessao(['compras_pedidos_view'], $this->ids['chefe']);
        $this->putJson('/api/compras/deliberacao/escaloes', ['niveis' => [['nome' => 'Responsável', 'limite' => 1000], ['nome' => 'Direcção', 'limite' => null]]], $comprador)->assertOk();
        $p = $this->pedido($comprador, [], 50)->json('dados.id');   // 500 → só o 1.º nível
        $this->aprovar($p, $chefe)->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $linha = $this->getJson("/api/compras/pedidos/{$p}", $comprador)->json('dados.linhas.0.id');
        $c = $this->postJson('/api/compras/propostas', ['pedido_compra_id' => $p, 'fornecedor_id' => $this->ids['fornecedor'], 'referencia' => 'PROP-T',
            'linhas' => [['item_pedido_id' => $linha, 'preco_unitario' => 30, 'taxa_imposto' => 14]]], $comprador)->assertCreated()->json('dados.id');   // 1500
        $this->postJson("/api/compras/propostas/{$c}/propor", [], $comprador)->assertOk();
        $this->postJson("/api/compras/propostas/{$c}/adjudicar", [], $comprador)->assertStatus(409)->assertJsonPath('codigo', 'REVISAO_DELIBERACAO');
        $this->getJson("/api/compras/pedidos/{$p}", $comprador)->assertJsonPath('dados.deliberacao.etapas.0.tipo', 'RESPONSAVEL')
            ->assertJsonPath('dados.deliberacao.etapas.1.tipo', 'TAREFA')->assertJsonPath('dados.deliberacao.etapas.1.tarefa', 'compras_ped_aprovar_n2')
            ->assertJsonPath('dados.deliberacao.etapas.1.nome_aprovador', 'Utilizadores com a tarefa «Aprovar pedidos — nível 2»')
            ->assertJsonPath('dados.deliberacao.etapas.1.revisao', true);
    }
}
