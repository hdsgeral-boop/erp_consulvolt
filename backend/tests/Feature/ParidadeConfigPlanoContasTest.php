<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Configurações › Plano de contas — paridade com o sistema anterior (verificação visual de 2026-10-08, linha 14.3.1):
 * coluna «Uso» (contas com lançamentos, isolada por empresa), «Copiar de outra empresa» (só as que faltam, nunca
 * substitui; exige confirmação, contab_plano_gerir e acesso à origem; auditoria; cache do plano invalidada) e
 * «Importar Excel» (validação de código/descrição/tipo por linha, relatório de erros, existentes nunca alteradas,
 * ficheiro limitado em tipo e tamanho). Valores fictícios.
 */
final class ParidadeConfigPlanoContasTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private Empresa $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nome' => 'Destino Teste', 'nif' => '5000888811']);
        $this->b = $this->criarEmpresa(['nome' => 'Origem Teste', 'nif' => '5000888822']);
        $this->c = $this->criarEmpresa(['nome' => 'Sem Acesso', 'nif' => '5000888833']);
        $this->em($this->a, function () {
            PlanoConta::create(['codigo' => '3111', 'descricao' => 'Clientes A', 'tipo' => 'M']);
            PlanoConta::create(['codigo' => '62', 'descricao' => 'Gastos com pessoal', 'tipo' => 'T']);
        });
        $this->em($this->b, function () {
            PlanoConta::create(['codigo' => '3111', 'descricao' => 'Clientes B (não substitui)', 'tipo' => 'M']);
            PlanoConta::create(['codigo' => '62', 'descricao' => 'Outro nome', 'tipo' => 'T']);
            PlanoConta::create(['codigo' => '4311', 'descricao' => 'Banco USD', 'tipo' => 'M', 'codigo_moeda' => 'USD']);
            PlanoConta::create(['codigo' => '71', 'descricao' => 'Vendas', 'tipo' => 'T']);
        });
        $this->em($this->c, fn () => PlanoConta::create(['codigo' => '9999', 'descricao' => 'Alheia', 'tipo' => 'M']));
    }

    private function em(Empresa $e, \Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($e->id, $f);
    }

    /** @param  list<int>  $empresas */
    private function sessao(array $permissoes, array $empresas = []): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresas ?: [$this->a->id]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->a->id];
    }

    /** @return array<string, string> codigo => descricao da empresa A */
    private function planoA(): array
    {
        return $this->em($this->a, fn () => PlanoConta::query()->orderBy('codigo')->pluck('descricao', 'codigo')->all());
    }

    #[Test]
    public function uso_lista_so_as_contas_com_lancamentos_da_empresa_activa(): void
    {
        DB::table('lancamentos_contabeis')->insert([
            ['empresa_id' => $this->a->id, 'codigo_conta' => '3111', 'tipo_dc' => 'D', 'valor' => 100, 'data_documento' => '2026-01-10'],
            ['empresa_id' => $this->a->id, 'codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => 100, 'data_documento' => '2026-01-11'],
            ['empresa_id' => $this->b->id, 'codigo_conta' => '71', 'tipo_dc' => 'C', 'valor' => 50, 'data_documento' => '2026-01-10'],
        ]);
        $this->getJson('/api/config/plano-contas/uso', $this->sessao(['vendas_view']))->assertForbidden();
        $this->getJson('/api/config/plano-contas/uso', $this->sessao(['config_plano_view']))
            ->assertOk()->assertJsonPath('dados', ['3111'])->assertJsonPath('metadados.total', 1);
    }

    #[Test]
    public function copiar_de_outra_empresa_so_acrescenta_as_contas_que_faltam(): void
    {
        $s = $this->sessao(['config_plano_view', 'contab_plano_gerir'], [$this->a->id, $this->b->id]);
        // aquece a cache do plano para provar a invalidação depois do commit
        $this->getJson('/api/contabilidade/plano-contas', $s)->assertOk()->assertJsonCount(2, 'dados');

        $p = $this->postJson('/api/config/plano-contas/copiar/simular', ['empresa_origem_id' => $this->b->id], $s)->assertOk()->json('dados');
        $this->assertSame([4, 2, 2], [$p['registos_origem'], $p['novas'], $p['existentes']]);
        $this->assertSame(['4311', '71'], array_column($p['amostra'], 'codigo'));
        $this->assertCount(2, $this->planoA(), 'a simulação não grava');

        // sem confirmação explícita → 422 e nada muda
        $this->postJson('/api/config/plano-contas/copiar', ['empresa_origem_id' => $this->b->id], $s)->assertStatus(422);
        $this->postJson('/api/config/plano-contas/copiar', ['empresa_origem_id' => $this->b->id, 'confirmar' => false], $s)->assertStatus(422);
        $this->assertCount(2, $this->planoA());

        $this->postJson('/api/config/plano-contas/copiar', ['empresa_origem_id' => $this->b->id, 'confirmar' => true], $s)
            ->assertOk()->assertJsonPath('dados.copiadas', 2)->assertJsonPath('dados.existentes', 2);
        $this->assertSame(['3111' => 'Clientes A', '4311' => 'Banco USD', '62' => 'Gastos com pessoal', '71' => 'Vendas'], $this->planoA());
        $this->assertSame('USD', $this->em($this->a, fn () => PlanoConta::query()->where('codigo', '4311')->value('codigo_moeda')));
        $this->assertSame('T', $this->em($this->a, fn () => PlanoConta::query()->where('codigo', '71')->value('tipo')));
        // a origem fica igual
        $this->assertSame(4, $this->em($this->b, fn () => PlanoConta::query()->count()));
        // cache invalidada: a leitura seguinte já traz as contas copiadas
        $this->getJson('/api/contabilidade/plano-contas', $s)->assertOk()->assertJsonCount(4, 'dados');
        // auditoria do resumo
        $log = app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('acao', 'Copiar de outra empresa')->firstOrFail());
        $this->assertSame($this->a->id, (int) $log->empresa_id);
        $this->assertSame(2, $log->dados_novos['copiadas']);

        // repetir não duplica
        $this->postJson('/api/config/plano-contas/copiar', ['empresa_origem_id' => $this->b->id, 'confirmar' => true], $s)
            ->assertOk()->assertJsonPath('dados.copiadas', 0);
        $this->assertCount(4, $this->planoA());
    }

    #[Test]
    public function copiar_exige_permissao_de_escrita_e_acesso_a_origem(): void
    {
        // só leitura do plano → 403
        $leitura = $this->sessao(['config_plano_view'], [$this->a->id, $this->b->id]);
        $this->postJson('/api/config/plano-contas/copiar', ['empresa_origem_id' => $this->b->id, 'confirmar' => true], $leitura)->assertForbidden();
        // empresa de origem sem acesso → 403 (e a pré-visualização também, para não revelar o plano alheio)
        $s = $this->sessao(['config_plano_view', 'contab_plano_gerir'], [$this->a->id, $this->b->id]);
        $this->postJson('/api/config/plano-contas/copiar/simular', ['empresa_origem_id' => $this->c->id], $s)->assertForbidden();
        $this->postJson('/api/config/plano-contas/copiar', ['empresa_origem_id' => $this->c->id, 'confirmar' => true], $s)->assertForbidden();
        // origem = empresa activa, ou valor inválido → 422
        $this->postJson('/api/config/plano-contas/copiar', ['empresa_origem_id' => $this->a->id, 'confirmar' => true], $s)->assertStatus(422);
        $this->postJson('/api/config/plano-contas/copiar/simular', ['empresa_origem_id' => '1 OR 1=1'], $s)->assertStatus(422);
        $this->assertCount(2, $this->planoA());
        $this->assertFalse($this->em($this->a, fn () => PlanoConta::query()->where('codigo', '9999')->exists()));
    }

    #[Test]
    public function importar_valida_cada_linha_e_nada_grava_com_erros(): void
    {
        $s = $this->sessao(['config_plano_view', 'contab_plano_gerir']);
        $csv = "Conta,Descrição,Tipo\n"
            ."3111,Outro nome para clientes,M\n"     // existente: mantida
            ."72,Proveitos operacionais,t\n"        // válida (tipo em minúsculas)
            ."7a!,Código inválido,M\n"              // código inválido
            ."73,,M\n"                              // falta a descrição
            ."74,Tipo errado,Z\n"                   // tipo inválido
            ."72,Repetida,M\n";                     // repetida
        $f = fn () => UploadedFile::fake()->createWithContent('plano.csv', $csv);

        $r = $this->post('/api/config/plano-contas/importar', ['ficheiro' => $f(), 'simular' => '1'], $s + ['Accept' => 'application/json'])->assertOk()->json('dados');
        $this->assertSame([4, 5, 6], array_column($r['erros'], 'linha'));
        $this->assertStringContainsString('código inválido', $r['erros'][0]['motivo']);
        $this->assertStringContainsString('falta a descrição', $r['erros'][1]['motivo']);
        $this->assertStringContainsString('tipo «Z» inválido', $r['erros'][2]['motivo']);
        $this->assertSame([1, 1, 1], [$r['novas'], $r['existentes'], $r['repetidas']]);

        $this->post('/api/config/plano-contas/importar', ['ficheiro' => $f()], $s + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_ERROS');
        $this->assertSame(['3111' => 'Clientes A', '62' => 'Gastos com pessoal'], $this->planoA());
    }

    #[Test]
    public function importar_sem_erros_cria_so_as_contas_novas(): void
    {
        $s = $this->sessao(['config_plano_view', 'contab_plano_gerir']);
        // cabeçalhos do modelo do legado («Código»), linha de exemplo do modelo novo (ignorada) e tipo vazio (= M)
        $csv = "Código,Descrição,Tipo\n3111,Clientes nacionais,M\n3111,Outro nome,M\n72,Proveitos,T\n75,Subsídios,\n";
        $f = fn () => UploadedFile::fake()->createWithContent('plano.csv', $csv);

        $sim = $this->post('/api/config/plano-contas/importar', ['ficheiro' => $f(), 'simular' => '1'], $s + ['Accept' => 'application/json'])->assertOk()->json('dados');
        $this->assertSame([], $sim['erros']);
        $this->assertSame([2, 1, 1], [$sim['novas'], $sim['existentes'], $sim['linhas_exemplo_ignoradas']]);
        $this->assertCount(2, $this->planoA(), 'a simulação não grava');

        $this->post('/api/config/plano-contas/importar', ['ficheiro' => $f()], $s + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('dados.criadas', 2);
        $this->assertSame(['3111' => 'Clientes A', '62' => 'Gastos com pessoal', '72' => 'Proveitos', '75' => 'Subsídios'], $this->planoA());
        $this->assertSame('M', $this->em($this->a, fn () => PlanoConta::query()->where('codigo', '75')->value('tipo')));
    }

    #[Test]
    public function importar_recusa_ficheiros_de_outro_tipo_grandes_ou_sem_permissao(): void
    {
        $s = $this->sessao(['config_plano_view', 'contab_plano_gerir']);
        $h = $s + ['Accept' => 'application/json'];
        $this->post('/api/config/plano-contas/importar', ['ficheiro' => UploadedFile::fake()->createWithContent('plano.php', '<?php echo 1;')], $h)->assertStatus(422);
        $this->post('/api/config/plano-contas/importar', ['ficheiro' => UploadedFile::fake()->create('plano.xlsx', 6000)], $h)->assertStatus(422);
        $this->post('/api/config/plano-contas/importar', [], $h)->assertStatus(422);
        $this->post('/api/config/plano-contas/importar', ['ficheiro' => UploadedFile::fake()->createWithContent('plano.csv', "Conta,Descrição\n80,X\n")],
            $this->sessao(['config_plano_view']) + ['Accept' => 'application/json'])->assertForbidden();
        $this->assertCount(2, $this->planoA());
    }
}
