<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\TipoOrganizacaoRH;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase D (Versão Actual, ADR-071) — cartas de pagamento de SALÁRIOS: nascem do pagamento do período gravado na tesouraria
 * (atalho do RH «Emitir carta» = gravar o pagamento + emitir a carta a partir dele), usam as coordenadas bancárias dos
 * colaboradores do período e não geram lançamentos. Dados fictícios.
 */
final class FdCartasSalariosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids;

    private int $periodo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Ficticia, Lda']);
        $ids = $this->como(function () {
            foreach (['7211' => 'Remunerações', '7221' => 'Encargos INSS', '3611' => 'Remunerações a pagar', '3421' => 'IRT', '3431' => 'INSS', '4311' => 'Banco'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            PlanoConta::query()->where('codigo', '4311')->firstOrFail()->update(['banco_nome' => 'Banco Ficticio SA', 'banco_iban' => RHCadastrosTest::ibanValido('0055000000000000001')]);

            return ['org' => TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id,
                'base' => InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id];
        });
        $this->s = $this->sessao(['colaboradores_detail', 'contratos_new', 'rh_bancario_gerir', 'contab_mapeamento', 'calcular_view', 'calcular_folha',
            'processamento_validate', 'processamento_integrate', 'processamento_view', 'rh_rel_banco_view', 'teso_doc_emitir', 'teso_integrar',
            'teso_gestao_pagamentos_view', 'teso_cartas_view', 'teso_carta_emitir']);

        $ana = $this->postJson('/api/rh/colaboradores', ['nome_completo' => 'Ana Ficticia', 'nif' => '111', 'tipo_organizacao_id' => $ids['org']], $this->s)->json('dados.id');
        $rui = $this->postJson('/api/rh/colaboradores', ['nome_completo' => 'Rui Ficticio', 'nif' => '222', 'tipo_organizacao_id' => $ids['org']], $this->s)->json('dados.id');
        foreach ([$ana => 300000, $rui => 150000] as $c => $v) {
            $this->postJson('/api/rh/contratos', ['colaborador_id' => $c, 'data_inicio' => now()->subYear()->toDateString(),
                'remuneracoes' => [['infotipo_salarial_id' => $ids['base'], 'valor_mes' => $v]]], $this->s)->assertCreated();
        }
        $this->putJson('/api/rh/mapeamentos-contabeis', ['rubricas' => [['infotipo_salarial_id' => $ids['base'], 'tipo_organizacao_id' => $ids['org'], 'numero_conta' => '7211']],
            'sistema' => collect(['NET_PAY_CREDIT' => '3611', 'IRT_CREDIT' => '3421', 'INSS_FUNC_CREDIT' => '3431', 'INSS_EMP_DEBIT' => '7221', 'INSS_EMP_CREDIT' => '3431'])
                ->map(fn ($conta, $codigo) => ['codigo' => $codigo, 'tipo_organizacao_id' => $ids['org'], 'numero_conta' => $conta])->values()->all()], $this->s)->assertOk();
        $p = $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => now()->format('m/Y')], $this->s)->json('dados.id');
        $this->postJson("/api/rh/salarios/periodos/{$p}/importar-contratos", [], $this->s)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$p}/encerrar", [], $this->s)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$p}/validar", [], $this->s)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$p}/contabilizar", [], $this->s)->assertOk();
        $this->ids = ['ana' => $ana, 'rui' => $rui];
        $this->periodo = $p;
    }

    private function como(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function ibans(bool $rui = true): void
    {
        $banco = $this->postJson('/api/rh/bancos', ['nome' => 'Banco Ficticio A'], $this->s)->json('dados.id');
        foreach (array_filter([$this->ids['ana'] => '0040000012345678901', $this->ids['rui'] => $rui ? '0040000098765432101' : null]) as $c => $prefixo) {
            $this->putJson("/api/rh/colaboradores/{$c}/coordenada-bancaria", ['banco_id' => $banco, 'iban' => RHCadastrosTest::ibanValido($prefixo)], $this->s)->assertOk();
        }
    }

    #[Test]
    public function atalho_do_rh_grava_o_pagamento_e_emite_a_carta_a_partir_dele_sem_lancamentos(): void
    {
        $this->ibans();
        $lancamentos = $this->como(fn () => LancamentoContabil::query()->count());
        $carta = $this->postJson("/api/rh/salarios/periodos/{$this->periodo}/cartas", ['codigo_conta_bancaria' => '4311', 'data' => now()->toDateString(),
            'colaboradores' => [$this->ids['ana']]], $this->s)->assertCreated()->json('dados');
        $doc = $carta['documento_tesouraria_id'];
        $this->assertNotNull($doc);
        // o pagamento fica PENDENTE (sem lançamento até ser integrado na Tesouraria); a carta não lança nada
        $this->assertSame($lancamentos, $this->como(fn () => LancamentoContabil::query()->count()));

        $this->getJson("/api/tesouraria/cartas/{$carta['id']}", $this->s)->assertOk()->assertJsonPath('dados.numero', sprintf('CP %d/001', now()->year))
            ->assertJsonPath('dados.tipo_efectivo', 'SALARIOS')->assertJsonPath('dados.banco_nome', 'Banco Ficticio SA')
            ->assertJsonPath('dados.itens.0.nome', 'Ana Ficticia')->assertJsonPath('dados.itens.0.banco', 'Banco Ficticio A')
            ->assertJsonPath('dados.itens.0.iban', RHCadastrosTest::ibanValido('0040000012345678901'))
            ->assertJsonPath('dados.assunto', 'Ordem de pagamento de salários — {{MES}}')->assertJsonPath('dados.mes_ano', now()->format('m/Y'));

        // na Tesouraria: o mesmo pagamento já está todo em carta; a Ana aparece assinalada
        $el = $this->getJson("/api/tesouraria/cartas/documentos/{$doc}/elegibilidade", $this->s)->assertOk()
            ->assertJsonPath('dados.tipo', 'SALARIOS')->assertJsonPath('dados.pode', false)->assertJsonPath('dados.codigo', 'SEM_VALOR_DISPONIVEL')->json('dados');
        $benef = collect($el['beneficiarios'])->keyBy('nome');
        $this->assertSame('243370.00', $benef['Ana Ficticia']['valor']);
        $this->assertNotNull($benef['Ana Ficticia']['ja_na_carta']);
        $this->assertNull($benef['Rui Ficticio']['ja_na_carta']);

        // anular a carta na Tesouraria liberta o pagamento e o colaborador (também na ordem do RH)
        $this->postJson("/api/tesouraria/cartas/{$carta['id']}/anular", ['motivo' => 'Banco recusou a carta'], $this->s)->assertOk();
        // colaborador sem salário neste período não entra
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['colaborador_id' => 999999]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'BENEFICIARIO_INVALIDO');
        $this->getJson("/api/rh/salarios/periodos/{$this->periodo}/ordem-pagamento", $this->s)->assertOk()->assertJsonPath('dados.linhas.0.carta_pagamento_id', null);
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['colaborador_id' => $this->ids['ana']]], 'assunto' => 'Salários {{MES}}'], $this->s)
            ->assertCreated()->assertJsonPath('dados.numero', sprintf('CP %d/002', now()->year))->assertJsonPath('dados.montante_total', '243370.00')
            ->assertJsonPath('dados.periodo_processamento_salarial_id', $this->periodo)->assertJsonPath('dados.assunto', 'Salários {{MES}}');
        $this->assertSame($lancamentos, $this->como(fn () => LancamentoContabil::query()->count()));
    }

    #[Test]
    public function sem_iban_do_colaborador_bloqueia_e_o_atalho_exige_emitir_pagamentos(): void
    {
        $this->ibans(rui: false);
        $corpo = ['codigo_conta_bancaria' => '4311', 'data' => now()->toDateString()];
        $this->postJson("/api/rh/salarios/periodos/{$this->periodo}/cartas", $corpo, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'COLABORADOR_SEM_IBAN')
            ->assertJsonPath('erros.colaboradores', ['Rui Ficticio']);
        // a conta tem de ter IBAN
        $this->como(fn () => PlanoConta::create(['codigo' => '4312', 'descricao' => 'Banco sem IBAN', 'tipo' => 'M']));
        $this->postJson("/api/rh/salarios/periodos/{$this->periodo}/cartas", ['codigo_conta_bancaria' => '4312'] + $corpo, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_SEM_IBAN');

        // quem só integra salários não grava pagamentos na tesouraria
        $rh = $this->sessao(['processamento_integrate', 'processamento_view']);
        $this->postJson("/api/rh/salarios/periodos/{$this->periodo}/cartas", [], $rh)->assertForbidden();

        // na Tesouraria, um pagamento do período com o Rui sem IBAN: o Rui fica de fora e, se escolhido, bloqueia
        $doc = $this->postJson("/api/rh/salarios/periodos/{$this->periodo}/cartas", $corpo + ['colaboradores' => [$this->ids['ana']]], $this->s)->assertCreated()->json('dados.documento_tesouraria_id');
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['colaborador_id' => $this->ids['rui']]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SEM_VALOR_DISPONIVEL');
    }
}
