<?php

namespace Tests\Feature;

use App\Models\ConferenciaCaixa;
use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\ItemDocumentoTesouraria;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PlanoConta;
use App\Models\Projeto;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tesouraria — campos do legado que faltavam (showTesourariaForm, ui_tesouraria.js:684-760): URL do documento, período
 * salarial, projecto e as notas (Demo / Fluxo) das linhas; e o «Nome do Tesoureiro / Operador» da conferência de caixa
 * (ui_tesouraria.js:7255). Valores fictícios.
 */
final class AbTesoCamposLegadoTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private array $s;

    private string $hoje;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['4311' => 'Banco Ficticio', '4511' => 'Caixa sede', '752' => 'Serviços', '611' => 'Vendas'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['proj_activo'] = Projeto::create(['codigo' => 'PRJ-01', 'nome' => 'Obra ficticia', 'estado' => 'ACTIVO'])->id;
            $this->ids['proj_fechado'] = Projeto::create(['codigo' => 'PRJ-02', 'nome' => 'Obra concluida', 'estado' => 'CONCLUIDO'])->id;
            $this->ids['per_validado'] = PeriodoProcessamentoSalarial::create(['mes_ano' => '05/2026', 'estado' => 'VALIDADO', 'contabilizado' => false])->id;
            $this->ids['per_integrado'] = PeriodoProcessamentoSalarial::create(['mes_ano' => '04/2026', 'estado' => 'FECHADO', 'contabilizado' => true])->id;
            $this->ids['per_aberto'] = PeriodoProcessamentoSalarial::create(['mes_ano' => '06/2026', 'estado' => 'ABERTO', 'contabilizado' => false])->id;
            $this->ids['nota_demo'] = NotaDemonstracao::create(['codigo' => '24', 'descricao' => 'Fornecimentos'])->id;
            $this->ids['nota_fluxo'] = NotaFluxoCaixa::create(['codigo' => 'F3', 'descricao' => 'Pagamentos a fornecedores'])->id;
        });
        app(ContextoEmpresa::class)->executarComo($this->outra->id, function () {
            $this->ids['proj_outra'] = Projeto::create(['codigo' => 'X-1', 'nome' => 'De outra empresa', 'estado' => 'ACTIVO'])->id;
            $this->ids['nota_outra'] = NotaDemonstracao::create(['codigo' => '99', 'descricao' => 'Outra'])->id;
        });
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys(['teso_gestao_pagamentos_view', 'teso_doc_emitir', 'teso_integrar', 'teso_conf_registar', 'teso_conf_assinar'], true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id, 'nome_completo' => 'Ana Ficticia']);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function corpo(array $extra = [], array $linha = [], string $tipo = 'PAGAMENTO'): array
    {
        return ['tipo' => $tipo, 'data_documento' => $this->hoje, 'conta_financeira' => '4311', 'descricao' => 'Pagamento de serviços ficticio',
            'linhas' => [['codigo_conta' => $tipo === 'PAGAMENTO' ? '752' : '611', 'tipo_dc' => $tipo === 'PAGAMENTO' ? 'D' : 'C', 'valor' => '1500'] + $linha]] + $extra;
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    #[Test]
    public function grava_url_periodo_projecto_e_notas_e_mostra_no_detalhe(): void
    {
        $id = $this->postJson('/api/tesouraria/documentos', $this->corpo(
            ['url_documento' => '  https://exemplo.test/doc/123  ', 'projeto_id' => $this->ids['proj_activo'], 'periodo_processamento_salarial_id' => $this->ids['per_validado']],
            ['nota_demonstracao_id' => $this->ids['nota_demo'], 'nota_fluxo_caixa_id' => $this->ids['nota_fluxo']],
        ), $this->s)->assertCreated()
            ->assertJsonPath('dados.url_documento', 'https://exemplo.test/doc/123')
            ->assertJsonPath('dados.projeto_id', $this->ids['proj_activo'])
            ->assertJsonPath('dados.codigo_projeto', 'PRJ-01')
            ->assertJsonPath('dados.periodo_processamento_salarial_id', $this->ids['per_validado'])
            ->assertJsonPath('dados.linhas.0.nota_demonstracao_id', $this->ids['nota_demo'])
            ->assertJsonPath('dados.linhas.0.nota_fluxo_caixa_id', $this->ids['nota_fluxo'])
            ->json('dados.id');

        $this->getJson("/api/tesouraria/documentos/{$id}", $this->s)->assertOk()
            ->assertJsonPath('dados.projeto.codigo', 'PRJ-01')
            ->assertJsonPath('dados.periodo_salarial.mes_ano', '05/2026')
            ->assertJsonPath("dados.notas_demonstracao.{$this->ids['nota_demo']}", '24')
            ->assertJsonPath("dados.notas_fluxo_caixa.{$this->ids['nota_fluxo']}", 'F3');

        // a edição limpa o URL e o período quando vêm vazios; o projecto mantém-se
        $this->putJson("/api/tesouraria/documentos/{$id}", $this->corpo(['url_documento' => null, 'periodo_processamento_salarial_id' => null, 'projeto_id' => $this->ids['proj_activo']]), $this->s)
            ->assertOk()->assertJsonPath('dados.url_documento', null)->assertJsonPath('dados.periodo_processamento_salarial_id', null);

        // o projecto do cabeçalho vai para as linhas na integração (saveTesouraria do legado)
        $this->postJson("/api/tesouraria/documentos/{$id}/integrar", [], $this->s)->assertOk();
        $this->assertSame($this->ids['proj_activo'], $this->naEmpresa(fn () => DocumentoTesouraria::query()->find($id)->projeto_id));
    }

    #[Test]
    public function url_so_http_ou_https(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,ola', 'ftp://exemplo.test/x', 'exemplo.test/sem-esquema'] as $url) {
            $this->postJson('/api/tesouraria/documentos', $this->corpo(['url_documento' => $url]), $this->s)->assertStatus(422);
        }
        $this->postJson('/api/tesouraria/documentos', $this->corpo(['url_documento' => 'https://exemplo.test/'.str_repeat('a', 150)]), $this->s)->assertStatus(422);
        $this->postJson('/api/tesouraria/documentos', $this->corpo(['url_documento' => 'http://exemplo.test/a']), $this->s)->assertCreated();
        $this->assertSame(1, $this->naEmpresa(fn () => DocumentoTesouraria::query()->count()));
    }

    #[Test]
    public function projecto_tem_de_ser_da_empresa_e_activo(): void
    {
        $this->postJson('/api/tesouraria/documentos', $this->corpo(['projeto_id' => $this->ids['proj_outra']]), $this->s)->assertStatus(422);
        $this->postJson('/api/tesouraria/documentos', $this->corpo(['projeto_id' => $this->ids['proj_fechado']]), $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PROJECTO_INACTIVO');

        // um documento que já tinha um projecto (entretanto concluído) continua a poder ser gravado com ele
        $id = $this->naEmpresa(fn () => DocumentoTesouraria::create(['tipo' => 'PAGAMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4311',
            'descricao' => 'Documento anterior', 'valor_total' => 1500, 'estado' => 'PENDENTE', 'projeto_id' => $this->ids['proj_fechado']])->id);
        $this->naEmpresa(fn () => ItemDocumentoTesouraria::create(['documento_tesouraria_id' => $id, 'codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 1500]));
        $this->putJson("/api/tesouraria/documentos/{$id}", $this->corpo(['projeto_id' => $this->ids['proj_fechado']]), $this->s)->assertOk()->assertJsonPath('dados.codigo_projeto', 'PRJ-02');
    }

    #[Test]
    public function periodo_salarial_so_em_pagamentos_e_processados(): void
    {
        $this->postJson('/api/tesouraria/documentos', $this->corpo(['periodo_processamento_salarial_id' => $this->ids['per_validado']], [], 'RECEBIMENTO'), $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_SO_PAGAMENTOS');
        $this->postJson('/api/tesouraria/documentos', $this->corpo(['periodo_processamento_salarial_id' => $this->ids['per_aberto']]), $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_NAO_PROCESSADO');
        $this->postJson('/api/tesouraria/documentos', $this->corpo(['periodo_processamento_salarial_id' => $this->ids['per_integrado']]), $this->s)->assertCreated();
    }

    #[Test]
    public function notas_das_linhas_tem_de_ser_da_empresa(): void
    {
        $this->postJson('/api/tesouraria/documentos', $this->corpo([], ['nota_demonstracao_id' => $this->ids['nota_outra']]), $this->s)->assertStatus(422);
    }

    #[Test]
    public function opcoes_do_cabecalho_projectos_activos_e_periodos_processados(): void
    {
        $r = $this->getJson('/api/tesouraria/documentos/opcoes', $this->s)->assertOk();
        $this->assertSame(['PRJ-01'], array_column($r->json('dados.projetos'), 'codigo'));
        // do mais recente para o mais antigo; o período aberto não entra
        $this->assertSame(['05/2026 (validado)', '04/2026 (integrado)'], array_column($r->json('dados.periodos_salariais'), 'rotulo'));

        // o projecto do documento em edição entra mesmo inactivo
        $id = $this->naEmpresa(fn () => DocumentoTesouraria::create(['tipo' => 'PAGAMENTO', 'data_documento' => $this->hoje, 'conta_financeira' => '4311',
            'descricao' => 'Documento anterior', 'valor_total' => 10, 'estado' => 'PENDENTE', 'projeto_id' => $this->ids['proj_fechado'],
            'periodo_processamento_salarial_id' => $this->ids['per_aberto']])->id);
        $r = $this->getJson("/api/tesouraria/documentos/opcoes?documento_id={$id}", $this->s)->assertOk();
        $this->assertSame(['PRJ-01', 'PRJ-02'], array_column($r->json('dados.projetos'), 'codigo'));
        $this->assertContains('06/2026', array_column($r->json('dados.periodos_salariais'), 'mes_ano'));

        $sem = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'teso_cartas_view' => true])->id]);
        $sem->empresas()->attach($this->empresa->id);
        $this->getJson('/api/tesouraria/documentos/opcoes', $this->entrar($sem) + ['X-Empresa-Id' => $this->empresa->id])->assertForbidden();
    }

    #[Test]
    public function conferencia_grava_o_operador_escrito_ou_o_utilizador_da_sessao(): void
    {
        $base = ['codigo_conta' => '4511', 'data_conferencia' => $this->hoje, 'denominacoes' => ['N1000' => 1]];
        $this->postJson('/api/tesouraria/conferencias', $base, $this->s)->assertCreated()->assertJsonPath('dados.nome_operador', 'Ana Ficticia');
        $c = $this->postJson('/api/tesouraria/conferencias', $base + ['nome_operador' => '  Tesoureiro Ficticio  '], $this->s)->assertCreated()
            ->assertJsonPath('dados.nome_operador', 'Tesoureiro Ficticio')->json('dados.id');
        $this->assertNotNull($this->naEmpresa(fn () => ConferenciaCaixa::query()->find($c)->registado_por));

        // quem registou não assina como gerente, mesmo com outro nome de operador
        $this->putJson("/api/tesouraria/conferencias/{$c}", $base + ['nome_operador' => 'Tesoureiro Ficticio', 'justificacao' => 'Diferença de contagem ficticia'], $this->s)->assertOk();
        $this->postJson("/api/tesouraria/conferencias/{$c}/finalizar", [], $this->s)->assertOk();
        $this->postJson("/api/tesouraria/conferencias/{$c}/assinar", [], $this->s)->assertStatus(403)->assertJsonPath('codigo', 'AUTO_ASSINATURA');
        $this->postJson('/api/tesouraria/conferencias', $base + ['nome_operador' => str_repeat('x', 256)], $this->s)->assertStatus(422);
    }
}
