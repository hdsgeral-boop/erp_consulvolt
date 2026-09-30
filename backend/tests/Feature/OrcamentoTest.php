<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\RubricaOrcamental;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Orçamento parte 1 (ADR-044): rubricas, ciclo e versões, base histórica, controlo orçado × realizado e hierarquia.
 * Ano anterior: venda 1 000 (Mar), custo com pessoal 400 pago por banco (Mar), recebimento do cliente 1 000 (Abr) e um
 * lançamento de apuramento (diário AP-O) que não pode entrar no realizado. Ano corrente: venda 600 e pessoal 300 (Jan).
 */
final class OrcamentoTest extends TestCase
{
    private Empresa $empresa;

    private array $s = [];

    private array $u = [];

    private int $ano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ano = (int) now()->format('Y');
        $this->empresa = $this->criarEmpresa();
        $ant = $this->ano - 1;
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () use ($ant) {
            foreach (['3111' => 'Clientes', '3211' => 'Fornecedores', '4311' => 'Banco', '611' => 'Vendas', '7211' => 'Remunerações', '8111' => 'Resultados'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $lan = app(ServicoLancamentos::class);
            $dia = fn (string $c) => app(LocalizadorLancamentos::class)->diario($c, $c)->id;
            $post = fn (string $diario, string $data, string $doc, array $linhas) => $lan->criar(['diario_id' => $dia($diario), 'data_documento' => $data, 'numero_documento' => $doc,
                'descricao' => $doc, 'linhas' => array_map(fn ($l) => ['codigo_conta' => $l[0], 'tipo_dc' => $l[1], 'valor' => $l[2]], $linhas)]);
            $post('FC', "{$ant}-03-10", 'FT1', [['3111', 'D', 1000], ['611', 'C', 1000]]);
            $post('BD', "{$ant}-03-25", 'SAL', [['7211', 'D', 400], ['4311', 'C', 400]]);
            $post('BD', "{$ant}-04-05", 'REC1', [['4311', 'D', 1000], ['3111', 'C', 1000]]);
            $post('AP-O', "{$ant}-12-31", 'APUR', [['611', 'D', 1000], ['8111', 'C', 1000]]);   // apuramento: excluído
            $post('FC', "{$this->ano}-01-10", 'FT2', [['3111', 'D', 600], ['611', 'C', 600]]);
            $post('BD', "{$this->ano}-01-25", 'SAL2', [['7211', 'D', 300], ['4311', 'C', 300]]);
            CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Comercial']);
            CentroCusto::create(['codigo' => 'CC2', 'descricao' => 'Operações']);
        });
        $tudo = ['orc_rubricas_view', 'orc_orcamentos_view', 'orc_controlo_view', 'orc_rubricas_edit', 'orc_editar', 'orc_submeter', 'orc_aprovar', 'orc_hierarquia'];
        foreach (['a' => $tudo, 'b' => $tudo, 'resp' => ['orc_contributo']] as $k => $perm) {
            $this->u[$k] = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($perm, true))->id]);
            $this->u[$k]->empresas()->attach($this->empresa->id);
            $this->s[$k] = $this->entrar($this->u[$k]) + ['X-Empresa-Id' => $this->empresa->id];
        }
    }

    private function rub(string $codigo): int
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => RubricaOrcamental::query()->where('codigo', $codigo)->value('id'));
    }

    private function meses(float $v, ?int $so = null): array
    {
        return array_map(fn ($m) => $so === null || $m === $so ? $v : 0, range(0, 11));
    }

    #[Test]
    public function rubricas_base_ciclo_versoes_e_controlo(): void
    {
        [$a, $b] = [$this->s['a'], $this->s['b']];
        $this->postJson('/api/orcamento/rubricas/base', ['tipo' => 'EXPLORACAO'], $a)->assertOk()->assertJsonCount(2, 'dados.criadas');   // só 61 e 72 existem no plano
        $this->postJson('/api/orcamento/rubricas/base', ['tipo' => 'TESOURARIA'], $a)->assertOk();
        $this->postJson('/api/orcamento/rubricas', ['tipo' => 'EXPLORACAO', 'codigo' => 'X1', 'nome' => 'Vendas A', 'natureza' => 'PROVEITO', 'contas' => [['codigo' => '611']]], $a)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTAS_INVALIDAS');   // já coberta por P01 (61*)
        $this->postJson('/api/orcamento/rubricas', ['tipo' => 'TESOURARIA', 'codigo' => 'X2', 'nome' => 'Banco', 'natureza' => 'PAGAMENTO', 'contas' => [['codigo' => '4311']]], $a)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTAS_INVALIDAS');   // 43/45 não
        $this->putJson('/api/orcamento/rubricas/'.$this->rub('P01'), ['tipo' => 'EXPLORACAO', 'codigo' => 'P01', 'nome' => 'Vendas', 'natureza' => 'PROVEITO',
            'contas' => [['codigo' => '61', 'prefixo' => true]], 'controlo' => ['modo' => 'BLOQUEAR']], $a)->assertStatus(422)->assertJsonPath('codigo', 'CONTROLO_INVALIDO');

        // orçamento a partir do realizado do ano anterior: proveitos +10 %, custos +5 %; o apuramento (AP-O) não entra
        $o = $this->postJson('/api/orcamento/orcamentos', ['ano' => $this->ano, 'tipo' => 'EXPLORACAO', 'origem' => 'REALIZADO_ANTERIOR', 'crescimento_proveitos_pct' => 10,
            'crescimento_custos_pct' => 5], $a)->assertCreated()->assertJsonPath('dados.versao', 1)->json('dados.id');
        $linhas = collect($this->getJson("/api/orcamento/orcamentos/{$o}", $a)->json('dados.linhas'))->keyBy('rubrica_orcamental_id');
        $this->assertSame([1100.0, 420.0], [(float) $linhas[$this->rub('P01')]['valores'][2], (float) $linhas[$this->rub('C02')]['valores'][2]]);
        $this->postJson('/api/orcamento/orcamentos', ['ano' => $this->ano, 'tipo' => 'EXPLORACAO'], $a)->assertStatus(422)->assertJsonPath('codigo', 'ORCAMENTO_EXISTENTE');

        $this->putJson("/api/orcamento/orcamentos/{$o}/valores", ['linhas' => [['rubrica_orcamental_id' => $this->rub('P01'), 'valores' => $this->meses(100)],
            ['rubrica_orcamental_id' => $this->rub('C02'), 'valores' => $this->meses(250)]]], $a)->assertOk();
        $this->postJson("/api/orcamento/orcamentos/{$o}/submeter", [], $a)->assertOk()->assertJsonPath('dados.estado', 'SUBMETIDO');
        $this->postJson("/api/orcamento/orcamentos/{$o}/aprovar", [], $a)->assertStatus(403)->assertJsonPath('codigo', 'SEGREGACAO_FUNCOES');
        $this->postJson("/api/orcamento/orcamentos/{$o}/aprovar", [], $b)->assertOk()->assertJsonPath('dados.estado', 'APROVADO');

        // controlo de Janeiro: vendas 600 vs 100 (favorável); pessoal 300 vs 250 (desfavorável 20 % → significativo)
        $c = collect($this->getJson("/api/orcamento/orcamentos/{$o}/controlo?mes=1&vista=MES", $a)->assertOk()->json('dados.linhas'))->keyBy('codigo');
        $this->assertEquals([100, 600, 500, true], [$c['P01']['orcado'], $c['P01']['realizado'], $c['P01']['desvio'], $c['P01']['favoravel']]);
        $this->assertEquals([250, 300, 20, false, true], [$c['C02']['orcado'], $c['C02']['realizado'], $c['C02']['desvio_pct'], $c['C02']['favoravel'], $c['C02']['desvio_significativo']]);

        // nova versão: a anterior vigora até à aprovação da nova, depois fica SUBSTITUIDA
        $v2 = $this->postJson("/api/orcamento/orcamentos/{$o}/nova-versao", [], $a)->assertCreated()->assertJsonPath('dados.versao', 2)->json('dados.id');
        $this->postJson("/api/orcamento/orcamentos/{$o}/nova-versao", [], $a)->assertStatus(422)->assertJsonPath('codigo', 'VERSAO_EM_CURSO');
        $this->putJson("/api/orcamento/orcamentos/{$v2}/valores", ['linhas' => [['rubrica_orcamental_id' => $this->rub('P01'), 'valores' => $this->meses(150)]]], $a)->assertOk();
        $this->postJson("/api/orcamento/orcamentos/{$v2}/submeter", [], $a)->assertOk();
        $this->postJson("/api/orcamento/orcamentos/{$v2}/aprovar", [], $b)->assertOk();
        $this->getJson("/api/orcamento/orcamentos/{$o}", $a)->assertJsonPath('dados.estado', 'SUBSTITUIDO')->assertJsonPath('dados.substituido_por_id', $v2);
        $c2 = collect($this->getJson("/api/orcamento/orcamentos/{$v2}/controlo?mes=1&vista=MES", $a)->json('dados.linhas'))->keyBy('codigo');
        $this->assertEquals([150, 100], [$c2['P01']['orcado'], $c2['P01']['orcado_inicial']]);

        // tesouraria do ano anterior: recebimento de clientes 1 000; pagamento (contrapartida 72xx) 400; saldo de Abril 600
        $t = $this->postJson('/api/orcamento/orcamentos', ['ano' => $this->ano - 1, 'tipo' => 'TESOURARIA', 'metodo' => 'BASE_ZERO'], $a)->assertCreated()->json('dados.id');
        $ct = $this->getJson("/api/orcamento/orcamentos/{$t}/controlo?vista=ANO", $a)->assertOk()->json('dados');
        $l = collect($ct['linhas'])->keyBy('codigo');
        $this->assertEquals([1000, 400], [$l['R01']['realizado'], $l['G04']['realizado']]);
        $this->assertEquals([0, -400, 600], [$ct['saldo_inicial'], $ct['saldos_fim_mes'][2], $ct['saldos_fim_mes'][3]]);
        // base zero: submeter exige justificação de cada rubrica com valor
        $this->putJson("/api/orcamento/orcamentos/{$t}/valores", ['linhas' => [['rubrica_orcamental_id' => $this->rub('R01'), 'valores' => $this->meses(10)]]], $a)->assertOk();
        $this->postJson("/api/orcamento/orcamentos/{$t}/submeter", [], $a)->assertStatus(422)->assertJsonPath('codigo', 'JUSTIFICACAO_EM_FALTA');
        $this->deleteJson('/api/orcamento/rubricas/'.$this->rub('R01'), [], $a)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
    }

    #[Test]
    public function hierarquia_top_down_e_consolidacao_da_ultima_versao(): void
    {
        [$a, $b] = [$this->s['a'], $this->s['b']];
        $this->postJson('/api/orcamento/rubricas/base', ['tipo' => 'EXPLORACAO'], $a)->assertOk();
        [$cc1, $cc2] = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => CentroCusto::query()->orderBy('codigo')->pluck('id')->all());
        $ano = $this->ano + 1;

        // top-down: o pai reparte-se em partes iguais; o último filho fica com o resto do arredondamento
        $pai = $this->postJson('/api/orcamento/orcamentos', ['ano' => $ano, 'tipo' => 'EXPLORACAO', 'metodo' => 'BASE_ZERO', 'abordagem' => 'TOP_DOWN'], $a)->json('dados.id');
        $f = [];
        foreach ([$cc1, $cc2] as $cc) {
            $f[] = $this->postJson('/api/orcamento/orcamentos', ['ano' => $ano, 'tipo' => 'EXPLORACAO', 'metodo' => 'BASE_ZERO', 'centro_custo_id' => $cc, 'orcamento_pai_id' => $pai], $a)->json('dados.id');
        }
        $this->putJson("/api/orcamento/orcamentos/{$pai}/valores", ['linhas' => [['rubrica_orcamental_id' => $this->rub('P01'), 'valores' => $this->meses(100.01)]]], $a)->assertOk();
        $this->postJson("/api/orcamento/orcamentos/{$pai}/repartir", ['criterio' => 'MANUAL', 'percentagens' => [$f[0] => 60, $f[1] => 30]], $a)
            ->assertStatus(422)->assertJsonPath('codigo', 'PERCENTAGENS_INVALIDAS');
        $this->postJson("/api/orcamento/orcamentos/{$pai}/repartir", ['criterio' => 'IGUAL'], $a)->assertOk()->assertJsonPath('dados.filhos', 2);
        $v = fn (int $id) => (float) collect($this->getJson("/api/orcamento/orcamentos/{$id}", $a)->json('dados.linhas'))->first()['valores'][0];
        $this->assertSame([50.01, 50.0], [$v($f[0]), $v($f[1])]);   // 50,005 → 50,01 e o resto 50,00: somam 100,01
        $this->deleteJson("/api/orcamento/orcamentos/{$pai}", [], $a)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');

        // bottom-up: contributos com responsável (edita e submete só com orc_contributo); consolidação da ÚLTIMA versão
        $pai2 = $this->postJson('/api/orcamento/orcamentos', ['ano' => $ano + 1, 'tipo' => 'EXPLORACAO', 'metodo' => 'BASE_ZERO'], $a)->json('dados.id');
        $filhos = $this->postJson("/api/orcamento/orcamentos/{$pai2}/contributos", ['filhos' => [['centro_custo_id' => $cc1, 'responsavel' => $this->u['resp']->nome_utilizador],
            ['centro_custo_id' => $cc2, 'responsavel' => $this->u['resp']->nome_utilizador]]], $a)->assertCreated()->json('dados');
        $this->postJson("/api/orcamento/orcamentos/{$pai2}/consolidar", [], $a)->assertStatus(422)->assertJsonPath('codigo', 'SEM_CONTRIBUTOS');
        foreach ($filhos as $i => $fid) {
            $this->putJson("/api/orcamento/orcamentos/{$fid}/valores", ['linhas' => [['rubrica_orcamental_id' => $this->rub('P01'), 'valores' => $this->meses(10 * ($i + 1), 0),
                'notas' => 'Previsão do departamento']]], $this->s['resp'])->assertOk();
            $this->postJson("/api/orcamento/orcamentos/{$fid}/submeter", [], $this->s['resp'])->assertOk();
        }
        // o primeiro contributo é aprovado e revisto (v2 = 15): só a v2 entra na consolidação (o legado somava as duas)
        $this->postJson("/api/orcamento/orcamentos/{$filhos[0]}/aprovar", [], $b)->assertOk();
        $v2 = $this->postJson("/api/orcamento/orcamentos/{$filhos[0]}/nova-versao", [], $a)->json('dados.id');
        $this->putJson("/api/orcamento/orcamentos/{$v2}/valores", ['linhas' => [['rubrica_orcamental_id' => $this->rub('P01'), 'valores' => $this->meses(15, 0), 'notas' => 'Revisto']]], $a)->assertOk();
        $this->postJson("/api/orcamento/orcamentos/{$v2}/submeter", [], $a)->assertOk();
        $this->postJson("/api/orcamento/orcamentos/{$pai2}/consolidar", [], $a)->assertOk()->assertJsonCount(2, 'dados.consolidado_ids');
        $this->assertSame(35.0, (float) collect($this->getJson("/api/orcamento/orcamentos/{$pai2}", $a)->json('dados.linhas'))->first()['valores'][0]);   // 15 + 20
    }
}
