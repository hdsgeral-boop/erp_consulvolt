<?php

namespace Tests\Feature;

use App\Models\CargoFuncao;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade RH (relatório 6.5.2–6.5.4): Template, Importar Excel e «Copiar de outra empresa» das Funções, Infotipos e
 * Bancos (/api/rh/paridade/cadastros/{entidade}). Dados fictícios.
 */
final class ParidadeRHCadastrosTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private Empresa $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa(['nome' => 'Empresa Destino Teste']);
        $this->b = $this->criarEmpresa(['nome' => 'Empresa Origem Teste']);
        $this->c = $this->criarEmpresa(['nome' => 'Empresa Alheia Teste']);
        $ctx = app(ContextoEmpresa::class);
        $ctx->executarComo($this->a->id, fn () => CargoFuncao::create(['nome' => 'Contabilista']));
        $ctx->executarComo($this->b->id, function () {
            CargoFuncao::create(['nome' => 'contabilista ']);   // já existe no destino (mesmo nome normalizado)
            CargoFuncao::create(['nome' => 'Motorista', 'descricao' => 'Conduz as viaturas']);
            InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de Turno', 'sujeito_inss' => true, 'irt' => 'true']);
        });
        $ctx->executarComo($this->c->id, fn () => CargoFuncao::create(['nome' => 'Segredo da outra empresa']));
    }

    /** @param  list<int>  $empresas */
    private function sessao(array $permissoes, array $empresas): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresas);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->a->id, 'Accept' => 'application/json'];
    }

    /** @return list<string> */
    private function funcoes(Empresa $e): array
    {
        return app(ContextoEmpresa::class)->executarComo($e->id, fn () => CargoFuncao::query()->orderBy('nome')->pluck('nome')->all());
    }

    #[Test]
    public function copiar_de_outra_empresa_so_de_empresas_acessiveis_e_sem_duplicar(): void
    {
        $s = $this->sessao(['funcoes_view', 'rh_funcoes_gerir'], [$this->a->id, $this->b->id]);

        $origens = $this->getJson('/api/rh/paridade/empresas-origem', $s)->assertOk()->json('dados');
        $this->assertSame([$this->b->id], array_column($origens, 'id'));   // nem a activa nem a alheia

        $sim = $this->postJson('/api/rh/paridade/cadastros/funcoes/copiar', ['empresa_origem_id' => $this->b->id, 'simular' => true], $s)->assertOk()->json('dados');
        $this->assertSame([1, 1, true], [$sim['criados'], $sim['ignorados'], $sim['simulacao']]);
        $this->assertSame(['Contabilista'], $this->funcoes($this->a));   // simulação: nada gravado

        $this->postJson('/api/rh/paridade/cadastros/funcoes/copiar', ['empresa_origem_id' => $this->b->id], $s)->assertOk()->assertJsonPath('dados.criados', 1);
        $this->assertSame(['Contabilista', 'Motorista'], $this->funcoes($this->a));
        $this->assertCount(2, $this->funcoes($this->b));   // a origem fica intacta

        // OWASP A01: empresa sem acesso e a própria empresa activa são recusadas
        $this->postJson('/api/rh/paridade/cadastros/funcoes/copiar', ['empresa_origem_id' => $this->c->id], $s)->assertStatus(403);
        $this->postJson('/api/rh/paridade/cadastros/funcoes/copiar', ['empresa_origem_id' => $this->a->id], $s)->assertStatus(422);
        $this->assertNotContains('Segredo da outra empresa', $this->funcoes($this->a));

        // repetir não duplica
        $this->postJson('/api/rh/paridade/cadastros/funcoes/copiar', ['empresa_origem_id' => $this->b->id], $s)->assertOk()->assertJsonPath('dados.criados', 0);
    }

    #[Test]
    public function bancos_nao_se_copiam_de_outra_empresa(): void
    {
        $s = $this->sessao(['bancario_view', 'rh_bancario_gerir'], [$this->a->id, $this->b->id]);
        $this->postJson('/api/rh/paridade/cadastros/bancos/copiar', ['empresa_origem_id' => $this->b->id, 'simular' => true], $s)
            ->assertStatus(403)->assertJsonPath('codigo', 'RH_COPIA_BANCOS_PROIBIDA');
    }

    #[Test]
    public function copiar_infotipos_mantem_as_regras_da_rubrica(): void
    {
        $s = $this->sessao(['infotipos_view', 'rh_infotipos_gerir'], [$this->a->id, $this->b->id]);
        $this->postJson('/api/rh/paridade/cadastros/infotipos/copiar', ['empresa_origem_id' => $this->b->id], $s)->assertOk()->assertJsonPath('dados.criados', 1);
        $i = app(ContextoEmpresa::class)->executarComo($this->a->id, fn () => InfotipoSalarial::query()->where('nome', 'Subsídio de Turno')->first());
        $this->assertNotNull($i);
        $this->assertSame('VENCIMENTO', $i->tipo);
        $this->assertTrue((bool) $i->sujeito_inss);
    }

    #[Test]
    public function importar_excel_e_tudo_ou_nada_e_ignora_existentes(): void
    {
        $s = $this->sessao(['funcoes_view', 'rh_funcoes_gerir'], [$this->a->id]);
        $f = fn (string $csv) => UploadedFile::fake()->createWithContent('funcoes.csv', $csv);

        $ok = "Nome,Descrição\nCONTABILISTA,Repetida no destino\nTécnico de Teste,Faz testes\nTécnico de Teste,Repetida no ficheiro\n";
        $sim = $this->post('/api/rh/paridade/cadastros/funcoes/importar', ['ficheiro' => $f($ok), 'simular' => '1'], $s)->assertOk()->json('dados');
        $this->assertSame([1, 1, 1], [$sim['novos'], $sim['ignorados'], $sim['repetidos']]);
        $this->assertSame(['Contabilista'], $this->funcoes($this->a));

        $this->post('/api/rh/paridade/cadastros/funcoes/importar', ['ficheiro' => $f($ok)], $s)->assertOk()->assertJsonPath('dados.criados', 1);
        $this->assertSame(['Contabilista', 'Técnico de Teste'], $this->funcoes($this->a));

        // uma linha sem nome: nada é gravado
        $mau = "Nome,Descrição\nNova Função Teste,ok\n,sem nome\n";
        $this->post('/api/rh/paridade/cadastros/funcoes/importar', ['ficheiro' => $f($mau)], $s)->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_ERROS');
        $this->assertNotContains('Nova Função Teste', $this->funcoes($this->a));
    }

    #[Test]
    public function modelo_e_permissoes(): void
    {
        $s = $this->sessao(['bancario_view', 'rh_bancario_gerir'], [$this->a->id]);
        $r = $this->get('/api/rh/paridade/cadastros/bancos/modelo', $s)->assertOk();
        $this->assertStringContainsString('Template_Bancos.xlsx', (string) $r->headers->get('content-disposition'));

        // sem a permissão de gestão daquela tabela
        $this->get('/api/rh/paridade/cadastros/funcoes/modelo', $s)->assertStatus(403);
        $this->postJson('/api/rh/paridade/cadastros/infotipos/copiar', ['empresa_origem_id' => $this->b->id], $s)->assertStatus(403);
        // entidade desconhecida
        $this->get('/api/rh/paridade/cadastros/colaboradores/modelo', $s)->assertStatus(404);
    }
}
