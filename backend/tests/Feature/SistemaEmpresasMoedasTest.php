<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\Moeda;
use App\Models\TaxaCambio;
use App\Models\UnidadeNegocio;
use App\Services\Sistema\ServicoCambiosBAI;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Administração (ADR-058): gestão de empresas, moedas e câmbios (incl. importação e BAI) e unidades de negócio. */
final class SistemaEmpresasMoedasTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000201']);
        foreach ([['AOA', 'Kwanza', 'Kz'], ['USD', 'Dólar dos EUA', 'US$'], ['EUR', 'Euro', '€']] as [$c, $n, $s]) {
            Moeda::create(['codigo' => $c, 'nome' => $n, 'simbolo' => $s, 'casas_decimais' => 2, 'ativo' => true]);
        }
        $this->s = $this->sessao(['config_empresas_view', 'config_empresas_gerir', 'config_moedas_view', 'config_moedas_gerir', 'tabelas_aux_view', 'aux_gerir',
            'aux_eliminar', 'rh_infotipos_gerir']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach(($empresa ?? $this->empresa)->id);

        return $this->entrar($u) + ['X-Empresa-Id' => ($empresa ?? $this->empresa)->id];
    }

    #[Test]
    public function cria_empresa_com_rubricas_por_omissao_e_liga_o_criador(): void
    {
        $r = $this->postJson('/api/sistema/empresas', ['nome' => 'Nova Empresa, Lda', 'nif' => '5417000299', 'taxa_inss_patronal' => 0, 'telefone' => '+244 923 000 000'], $this->s)
            ->assertCreated()->assertJsonPath('dados.taxa_inss_patronal', 0.0)->assertJsonPath('dados.taxa_inss_trabalhador', 3.0);
        $id = $r->json('dados.id');
        $rubricas = app(ContextoEmpresa::class)->executarComo($id, fn () => InfotipoSalarial::query()->pluck('irt', 'nome'));
        $this->assertCount(13, $rubricas);
        $this->assertSame('conditional_30k', $rubricas['Subsídio de transporte']);
        $this->assertArrayHasKey('Subsídio de comunicação', $rubricas->all());
        $this->assertSame(2, DB::table('tipos_organizacao_rh')->where('empresa_id', $id)->count());
        // o criador (sem acesso a todas) passa a aceder à nova empresa
        $this->getJson('/api/sistema/gestao-empresas', $this->s)->assertOk()->assertJsonCount(2, 'dados');

        $this->postJson('/api/sistema/empresas', ['nome' => 'Repetida', 'nif' => '5417000299'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NIF_DUPLICADO');
        $this->putJson("/api/sistema/empresas/{$id}", ['logotipo' => 'data:text/html;base64,PHNjcmlwdD4='], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LOGOTIPO_INVALIDO');
        $png = 'data:image/png;base64,'.base64_encode('PNG-teste');
        $this->putJson("/api/sistema/empresas/{$id}", ['logotipo' => $png, 'he_percentagem_1' => 60], $this->s)->assertOk()->assertJsonPath('dados.tem_logotipo', true);
        // logótipo do ecrã de entrada: só o Super Administrador
        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['logotipo' => $png], $this->s)->assertStatus(403);
        $super = $this->criarUtilizador(['papel' => 'SUPER_ADMINISTRADOR']);
        $this->putJson('/api/sistema/configuracoes/logotipo-login', ['logotipo' => $png], $this->entrar($super) + ['X-Empresa-Id' => $this->empresa->id])->assertOk();
        $this->assertSame($png, DB::table('configuracoes_sistema')->where('chave', 'login_logo')->value('valor'));
        $this->getJson("/api/sistema/gestao-empresas/{$id}", $this->s)->assertOk()->assertJsonPath('dados.logotipo', $png)->assertJsonPath('dados.he_percentagem_1', 60.0);
        // gravar sem logótipo mantém-no (correcção do legado)
        $this->putJson("/api/sistema/empresas/{$id}", ['nome' => 'Nova Empresa SA'], $this->s)->assertOk()->assertJsonPath('dados.tem_logotipo', true);
    }

    #[Test]
    public function estado_holding_moeda_funcional_e_horas_extra(): void
    {
        $outra = $this->criarEmpresa(['nif' => '5417000202']);
        $this->getJson("/api/sistema/gestao-empresas/{$outra->id}", $this->s)->assertNotFound();

        $this->putJson("/api/sistema/empresas/{$this->empresa->id}/estado", ['estado' => 'INATIVO'], $this->sessao(['config_empresas_gerir']))->assertOk();
        $this->putJson("/api/sistema/empresas/{$this->empresa->id}/estado", ['estado' => 'ATIVO'], $this->sessao(['config_empresas_gerir'], $outra))->assertNotFound();

        $this->empresa->refresh()->update(['estado' => 'ATIVO']);
        DB::table('lancamentos_contabeis')->insert(['empresa_id' => $this->empresa->id, 'codigo_conta' => '4311', 'tipo_dc' => 'D', 'valor' => 10, 'data_documento' => '2026-01-01']);
        $this->putJson("/api/sistema/empresas/{$this->empresa->id}", ['e_consolidacao' => true], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'HOLDING_COM_MOVIMENTOS');
        $this->putJson('/api/sistema/moedas/funcional', ['codigo_moeda' => 'USD'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MOEDA_FUNCIONAL_COM_MOVIMENTOS');
        $this->putJson("/api/sistema/empresas/{$outra->id}", ['e_consolidacao' => true, 'moeda_consolidacao' => 'USD'], $this->sessao(['config_empresas_gerir'], $outra))
            ->assertOk()->assertJsonPath('dados.e_consolidacao', true);

        $this->putJson('/api/sistema/empresa-ativa/horas-extra', ['he_percentagem_1' => 50, 'he_limite_horas' => 40, 'he_percentagem_2' => 100], $this->sessao(['rh_infotipos_gerir']))
            ->assertOk()->assertJsonPath('dados.he_limite_horas', 40.0);
        $this->putJson('/api/sistema/empresa-ativa/horas-extra', ['he_percentagem_1' => -1, 'he_limite_horas' => 40, 'he_percentagem_2' => 100], $this->s)->assertStatus(422);
    }

    #[Test]
    public function cambios_com_ambito_substituicao_e_bloqueio_em_uso(): void
    {
        $this->getJson('/api/sistema/moedas?ativas=1', $this->sessao([]))->assertOk()->assertJsonPath('dados.0.codigo', 'AOA');
        $this->postJson('/api/sistema/cambios', ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'AOA', 'taxa' => 1, 'ambito' => 'TODAS'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MOEDA_BASE');
        $geral = $this->postJson('/api/sistema/cambios', ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'USD', 'taxa' => '912,50', 'ambito' => 'TODAS', 'fonte_dados' => 'BNA'], $this->s)
            ->assertCreated()->assertJsonPath('dados.taxa', 912.5)->assertJsonPath('dados.ambito', 'TODAS')->json('dados.id');
        $this->postJson('/api/sistema/cambios', ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'USD', 'taxa' => 915, 'ambito' => 'TODAS'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CAMBIO_EXISTENTE');
        $this->postJson('/api/sistema/cambios', ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'USD', 'taxa' => 915, 'ambito' => 'TODAS', 'substituir' => true], $this->s)
            ->assertOk()->assertJsonPath('dados.taxa', 915.0);
        $this->assertSame(1, TaxaCambio::query()->whereNull('empresa_id')->count());
        $this->postJson('/api/sistema/cambios', ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'USD', 'taxa' => 920, 'ambito' => 'EMPRESA'], $this->s)->assertCreated();
        $this->getJson('/api/sistema/cambios/consultar?codigo_moeda=USD&data=2026-09-05', $this->s)->assertOk()->assertJsonPath('dados.taxa', '920')
            ->assertJsonPath('dados.ambito', 'empresa')->assertJsonPath('dados.exata', false);

        // em uso: não muda de taxa nem se elimina
        DB::table('documentos_tesouraria')->insert(['empresa_id' => $this->empresa->id, 'taxa_cambio_id' => $geral]);
        $this->putJson("/api/sistema/cambios/{$geral}", ['data_taxa' => '2026-09-01', 'codigo_moeda' => 'USD', 'taxa' => 930, 'ambito' => 'TODAS'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CAMBIO_EM_USO');
        $this->deleteJson("/api/sistema/cambios/{$geral}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CAMBIO_EM_USO');
        // câmbio de outra empresa não é acessível
        $alheio = TaxaCambio::create(['empresa_id' => $this->criarEmpresa()->id, 'codigo_moeda' => 'EUR', 'data_taxa' => '2026-09-01', 'taxa' => 1000]);
        $this->deleteJson("/api/sistema/cambios/{$alheio->id}", [], $this->s)->assertNotFound();
        $this->getJson('/api/sistema/cambios', $this->s)->assertOk()->assertJsonPath('metadados.paginacao.total', 2);
    }

    #[Test]
    public function importacao_de_cambios_rejeita_linhas_invalidas_e_respeita_a_decisao(): void
    {
        TaxaCambio::create(['codigo_moeda' => 'EUR', 'data_taxa' => '2026-09-01', 'taxa' => 1000]);
        $linhas = [
            ['Data' => '2026-09-01', 'Moeda' => 'USD', 'Taxa' => 912.5, 'Origem' => 'BNA', 'Âmbito' => 'TODAS'],
            ['Data' => '01/09/2026', 'Moeda' => 'EUR', 'Taxa' => '1.050,25', 'Origem' => 'BNA'],
            ['Data' => '31/02/2026', 'Moeda' => 'USD', 'Taxa' => '900'],
            ['Data' => '2026-09-02', 'Moeda' => 'GBP', 'Taxa' => '1200'],
            ['Data' => '2026-09-02', 'Moeda' => 'USD', 'Taxa' => '0'],
        ];
        $sim = $this->postJson('/api/sistema/cambios/importar', ['linhas' => $linhas, 'simular' => true], $this->s)->assertOk();
        $this->assertSame(1, $sim->json('dados.novos'));
        $this->assertSame(1, $sim->json('dados.existentes'));
        $this->assertSame([4, 5, 6], array_column($sim->json('dados.rejeitadas'), 'linha'));
        $this->assertSame(1, TaxaCambio::query()->count());

        $this->postJson('/api/sistema/cambios/importar', ['linhas' => $linhas], $this->s)->assertOk()->assertJsonPath('dados.importados', 1)->assertJsonPath('dados.mantidos', 1);
        $this->assertSame('1000.000000', (string) TaxaCambio::query()->where('codigo_moeda', 'EUR')->value('taxa'));
        $this->postJson('/api/sistema/cambios/importar', ['linhas' => $linhas, 'decisao' => 'ACTUALIZAR'], $this->s)->assertOk()->assertJsonPath('dados.actualizados', 2);
        $this->assertSame('1050.250000', (string) TaxaCambio::query()->where('codigo_moeda', 'EUR')->value('taxa'));
    }

    #[Test]
    public function cambios_do_bai_media_de_divisas_lida_pelo_servidor(): void
    {
        $html = '<div class="ExchangeRatesList"><table><thead><tr><th>Divisas</th><th>Notas</th></tr><tr><th>Moeda</th><th>Venda</th><th>Compra</th><th>Venda</th><th>Compra</th></tr></thead>'
            .'<tbody><tr><td>USD Dólar</td><td>920,00</td><td>910,00</td><td>930</td><td>890</td></tr><tr><td>EUR Euro</td><td>1.060,50</td><td>1.040,50</td><td>1</td><td>1</td></tr></tbody></table></div>';
        $this->assertSame(915.0, ServicoCambiosBAI::interpretar($html)[0]['media']);
        Http::fake([ServicoCambiosBAI::URL => Http::sequence()->push($html)->push($html)->push('bloqueado', 403)]);
        TaxaCambio::create(['codigo_moeda' => 'USD', 'data_taxa' => now()->subDays(3)->toDateString(), 'taxa' => 800, 'fonte_dados' => 'BNA']);

        $pre = $this->getJson('/api/sistema/cambios/bai', $this->s)->assertOk();
        $usd = collect($pre->json('dados.itens'))->firstWhere('codigo_moeda', 'USD');
        $this->assertTrue($usd['alerta']);
        $this->assertSame(1050.5, collect($pre->json('dados.itens'))->firstWhere('codigo_moeda', 'EUR')['media']);

        $this->postJson('/api/sistema/cambios/bai', ['moedas' => ['USD', 'EUR']], $this->s)->assertOk()->assertJsonPath('dados.novos', 2);
        $t = TaxaCambio::query()->where('codigo_moeda', 'USD')->whereDate('data_taxa', now()->toDateString())->firstOrFail();
        $this->assertSame(['915.000000', 'BAI', null], [(string) $t->taxa, $t->fonte_dados, $t->empresa_id]);
        $this->assertSame('910.000000', (string) $t->taxa_compra_bai);

        $this->getJson('/api/sistema/cambios/bai', $this->s)->assertStatus(502)->assertJsonPath('codigo', 'BAI_INDISPONIVEL');
    }

    #[Test]
    public function unidades_de_negocio_com_hierarquia_sem_ciclos_e_eliminacao_bloqueada_em_uso(): void
    {
        $gestor = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Colaborador::create(['nome_completo' => 'Gestor UN', 'estado' => 'ACTIVO'])->id);
        $a = $this->postJson('/api/sistema/unidades-negocio', ['codigo' => 'LDA', 'nome' => 'Luanda', 'colaborador_gestor_id' => $gestor, 'tem_vendas' => true], $this->s)
            ->assertCreated()->json('dados.id');
        $b = $this->postJson('/api/sistema/unidades-negocio', ['codigo' => 'BGL', 'nome' => 'Benguela', 'unidade_negocio_pai_id' => $a], $this->s)->assertCreated()->json('dados.id');
        $this->postJson('/api/sistema/unidades-negocio', ['codigo' => 'lda', 'nome' => 'Repetida'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'UNIDADE_DUPLICADA');
        $this->putJson("/api/sistema/unidades-negocio/{$a}", ['unidade_negocio_pai_id' => $b], $this->s)->assertStatus(422);
        $this->putJson("/api/sistema/unidades-negocio/{$a}", ['valido_de' => '2026-05-01', 'valido_ate' => '2026-01-01'], $this->s)->assertStatus(422);
        $this->getJson('/api/sistema/unidades-negocio', $this->s)->assertOk()->assertJsonCount(2, 'dados')->assertJsonPath('dados.1.colaborador_gestor_nome', 'Gestor UN');

        $this->deleteJson("/api/sistema/unidades-negocio/{$a}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->deleteJson("/api/sistema/unidades-negocio/{$b}", [], $this->sessao(['tabelas_aux_view', 'aux_gerir']))->assertForbidden();
        $this->deleteJson("/api/sistema/unidades-negocio/{$b}", [], $this->s)->assertOk();
        $this->assertSame(1, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => UnidadeNegocio::query()->count()));
    }
}
