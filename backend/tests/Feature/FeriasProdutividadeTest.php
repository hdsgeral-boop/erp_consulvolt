<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\RegistoProdutividadeRH;
use App\Models\TipoOrganizacaoRH;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Férias e subsídio de produtividade (RH parte 2b, ADR-039). */
final class FeriasProdutividadeTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $prod = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de produtividade', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $desc = InfotipoSalarial::create(['tipo' => 'DESCONTO', 'nome' => 'Adiantamento', 'sujeito_inss' => false, 'irt' => 'false'])->id;
            foreach (['ana' => 'Ana', 'rui' => 'Rui', 'novo' => 'Novo'] as $k => $nome) {
                $this->ids[$k] = Colaborador::create(['nome_completo' => $nome, 'nif' => $k, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22])->id;
            }
            $this->ids += ['base' => $base, 'prod' => $prod, 'desc' => $desc];
        });
        $this->s = $this->sessao(['rh_ferias_view', 'rh_ferias_edit', 'rh_assid_config', 'rh_assid_registar', 'rh_produtividade_view', 'rh_prod_registar', 'rh_prod_periodo',
            'rh_prod_config', 'calcular_folha', 'calcular_view', 'contratos_new']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function ferias_com_calendario_da_empresa_saldo_e_sobreposicao(): void
    {
        // feriado a 15/08/2025 (sexta): 11 a 22/08 são 9 dias úteis (o legado contava 10)
        $this->putJson('/api/rh/assiduidade/configuracao', ['dias_uteis' => [1, 2, 3, 4, 5], 'tolerancia_min' => 10, 'arredondamento_min' => 15, 'extras_min_minutos' => 15,
            'modo_compensacao' => 'DIA', 'extra_nao_util_exige_autorizacao' => false, 'feriados' => ['2025-08-15']], $this->s)->assertOk();
        $ana = $this->ids['ana'];
        $p1 = $this->postJson('/api/rh/ferias', ['colaborador_id' => $ana, 'data_inicio' => '2025-08-11', 'data_fim' => '2025-08-22'], $this->s)->assertCreated()
            ->assertJsonPath('dados.dias', 9)->assertJsonPath('dados.estado', 'PLANEADO')->assertJsonPath('dados.direito', 22)->json('dados.id');
        $this->postJson('/api/rh/ferias', ['colaborador_id' => $ana, 'data_inicio' => '2025-08-20', 'data_fim' => '2025-08-27'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SOBREPOSICAO');
        $set = ['colaborador_id' => $ana, 'data_inicio' => '2025-09-01', 'data_fim' => '2025-09-30'];
        $this->postJson('/api/rh/ferias', $set, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SALDO_EXCEDIDO')->assertJsonPath('erros.marcados', 9);
        $p2 = $this->postJson('/api/rh/ferias', $set + ['confirmar_excesso' => true, 'direito' => 25], $this->s)->assertCreated()->json('dados.id');
        $this->postJson('/api/rh/ferias', ['colaborador_id' => $ana, 'data_inicio' => '2025-10-01', 'data_fim' => '2025-10-02', 'estado' => 'PEDIDO'], $this->s)->assertStatus(422);

        // o direito do ano aplica-se a todos os períodos; saldo = 25 − (9 + 22)
        $r = collect($this->getJson('/api/rh/ferias?ano=2025', $this->s)->assertOk()->json('dados.resumo'))->firstWhere('colaborador_id', $ana);
        $this->assertSame([25, 31, -6], [$r['direito'], $r['marcados'], $r['saldo']]);
        $this->postJson("/api/rh/ferias/{$p1}/estado", ['estado' => 'GOZADO'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'GOZADO');
        $fut = $this->postJson('/api/rh/ferias', ['colaborador_id' => $this->ids['rui'], 'data_inicio' => now()->addMonth()->toDateString(), 'data_fim' => now()->addMonth()->addDays(6)->toDateString()], $this->s)
            ->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/ferias/{$fut}/estado", ['estado' => 'GOZADO'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FERIAS_NAO_TERMINADAS');
        $this->postJson("/api/rh/ferias/{$p2}/estado", ['estado' => 'CANCELADO'], $this->s)->assertOk();
        $this->deleteJson("/api/rh/ferias/{$p2}", [], $this->s)->assertOk();

        // sobreposição com uma ausência (e vice-versa, na parte 2a)
        $this->postJson('/api/rh/assiduidade/ausencias', ['colaborador_id' => $this->ids['rui'], 'tipo' => 'OUTRA', 'motivo' => 'Assunto pessoal',
            'data_inicio' => '2025-11-03', 'data_fim' => '2025-11-04'], $this->s)->assertCreated();
        $this->postJson('/api/rh/ferias', ['colaborador_id' => $this->ids['rui'], 'data_inicio' => '2025-11-04', 'data_fim' => '2025-11-07'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SOBREPOSICAO');
    }

    #[Test]
    public function produtividade_com_limites_no_total_e_lancamento_no_processamento(): void
    {
        $this->postJson('/api/rh/produtividade/itens', ['codigo' => 'a b', 'descricao' => 'X', 'preco_unitario' => 1, 'infotipo_salarial_id' => $this->ids['prod']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_INVALIDO');
        $this->postJson('/api/rh/produtividade/itens', ['codigo' => 'X1', 'descricao' => 'X', 'preco_unitario' => 1, 'infotipo_salarial_id' => $this->ids['desc']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'RUBRICA_NAO_VENCIMENTO');
        $item = $this->postJson('/api/rh/produtividade/itens', ['codigo' => 'cfrs', 'descricao' => 'Cofragem', 'unidade' => 'peças', 'preco_unitario' => 125,
            'infotipo_salarial_id' => $this->ids['prod'], 'minimo' => 100, 'maximo' => 1500], $this->s)->assertCreated()->assertJsonPath('dados.codigo', 'CFRS')->json('dados.id');
        $this->postJson('/api/rh/produtividade/itens', ['codigo' => 'CFRS', 'descricao' => 'Outro', 'preco_unitario' => 1, 'infotipo_salarial_id' => $this->ids['prod']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');

        // contratos: Ana ao preço do item; Rui a 150 (preço do contrato); Novo sem o item
        $contrato = fn (int $c, array $prod) => $this->postJson('/api/rh/contratos', ['colaborador_id' => $c, 'data_inicio' => '2025-01-01', 'produtividade' => $prod,
            'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 100000]]], $this->s);
        $contrato($this->ids['ana'], [['item_id' => 999999]])->assertStatus(422)->assertJsonPath('codigo', 'ITEM_PRODUTIVIDADE_INVALIDO');
        $contrato($this->ids['ana'], [['item_id' => $item, 'preco_unitario' => null]])->assertCreated();
        $contrato($this->ids['rui'], [['item_id' => $item, 'preco_unitario' => 150]])->assertCreated();
        $contrato($this->ids['novo'], [])->assertCreated();

        $per = ['mes' => '2025-08', 'data_inicio' => '2025-08-01', 'data_fim' => '2025-08-31'];
        $this->postJson('/api/rh/produtividade/periodos', ['mes' => '2025-08', 'data_inicio' => '2025-05-01', 'data_fim' => '2025-08-31'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'JANELA_EXCESSIVA');
        $p = $this->postJson('/api/rh/produtividade/periodos', $per, $this->s)->assertCreated()->assertJsonPath('dados.estado', 'ABERTO')->json('dados.id');
        $this->postJson('/api/rh/produtividade/periodos', ['mes' => '2025-09', 'data_inicio' => '2025-08-25', 'data_fim' => '2025-09-30'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'JANELA_SOBREPOSTA');
        $this->getJson("/api/rh/produtividade/periodos/{$p}", $this->s)->assertJsonCount(2, 'dados.elegiveis');

        $reg = fn (string $k, $q, ?string $data) => $this->postJson("/api/rh/produtividade/periodos/{$p}/registos", ['colaborador_id' => $this->ids[$k], 'item_produtividade_id' => $item,
            'quantidade' => $q, 'data' => $data], $this->s);
        $reg('novo', 10, '2025-08-04')->assertStatus(422)->assertJsonPath('codigo', 'NAO_ELEGIVEL');
        $reg('ana', 10, '2025-09-04')->assertStatus(422)->assertJsonPath('codigo', 'DATA_FORA_DA_JANELA');
        $reg('ana', 1000, '2025-08-04')->assertCreated();
        $reg('ana', 1000, '2025-08-05')->assertCreated();
        $reg('ana', 5, '2025-08-05')->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_DUPLICADO');
        $reg('rui', 50, '2025-08-04')->assertCreated();
        // Ana: 2 000 peças, tecto de 1 500 no TOTAL → 1 500 × 125 = 187 500 (o legado aplicava o tecto por registo: 250 000)
        $valores = fn (string $k) => app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => RegistoProdutividadeRH::query()
            ->where('colaborador_id', $this->ids[$k])->orderBy('id')->get()->map(fn ($r) => (float) $r->valor)->all());
        $this->assertSame([93750.0, 93750.0], $valores('ana'));
        $this->assertSame([0.0], $valores('rui'));   // 50 < mínimo de 100
        $reg('rui', 60, '2025-08-06')->assertCreated();
        $this->assertSame([7500.0, 9000.0], $valores('rui'));   // total 110 ≥ 100: tudo conta, a 150

        $this->putJson("/api/rh/produtividade/periodos/{$p}", ['mes' => '2025-08', 'data_inicio' => '2025-08-05', 'data_fim' => '2025-08-31'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'REGISTOS_FORA_DA_JANELA');
        $this->deleteJson("/api/rh/produtividade/itens/{$item}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');

        // lançar no processamento de 08/2025
        $sal = $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => '08/2025'], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/salarios/periodos/{$sal}/importar-produtividade", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_NAO_FECHADO');
        $this->postJson("/api/rh/produtividade/periodos/{$p}/fechar", [], $this->s)->assertOk()->assertJsonPath('dados.total_fecho', '204000.00')->assertJsonPath('dados.registos_fecho', 4);
        $reg('ana', 1, '2025-08-07')->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_FECHADO');
        $this->postJson("/api/rh/salarios/periodos/{$sal}/importar-produtividade", [], $this->s)->assertOk()->assertJsonPath('dados.lancados', 2)->assertJsonPath('dados.total', '204000.00');
        $linhas = fn () => app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $sal)
            ->orderBy('colaborador_id')->get()->map(fn ($l) => "{$l->colaborador_id}:{$l->valor}:{$l->origem}")->all());
        $this->assertSame(["{$this->ids['ana']}:187500.00:PRODUTIVIDADE", "{$this->ids['rui']}:16500.00:PRODUTIVIDADE"], $linhas());

        // reabrir, retirar o Rui, fechar e relançar: o lançamento do Rui sai
        $this->postJson("/api/rh/produtividade/periodos/{$p}/reabrir", ['motivo' => 'Correcção das quantidades'], $this->s)->assertOk();
        foreach (app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => RegistoProdutividadeRH::query()->where('colaborador_id', $this->ids['rui'])->pluck('id')) as $id) {
            $this->deleteJson("/api/rh/produtividade/periodos/{$p}/registos/{$id}", [], $this->s)->assertOk();
        }
        $this->postJson("/api/rh/produtividade/periodos/{$p}/fechar", [], $this->s)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$sal}/importar-produtividade", [], $this->s)->assertOk()->assertJsonPath('dados.removidos', 1);
        $this->assertCount(1, $linhas());
    }
}
