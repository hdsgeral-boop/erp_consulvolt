<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acréscimos e diferimentos (ADR-053): definições, plano de repartição com arredondamento acumulado, proposta mensal,
 * contabilização por linha, estados, regularização com o documento real, anulação, término, descontabilização por
 * estorno sem buracos, reconciliação das contas 37 e recolha de lançamentos do Diário.
 */
final class AcrescimosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private int $diario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000053']);
        $this->diario = app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3731' => 'Proveitos a facturar', '3743' => 'Encargos a repartir', '3754' => 'Encargos a pagar', '3766' => 'Proveitos a repartir',
                '752331' => 'Seguros', '7521' => 'Electricidade', '6211' => 'Serviços', '37' => 'Acréscimos (totalizadora)', '311' => 'Clientes', '321' => 'Fornecedores'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => (string) $c === '37' ? 'T' : 'M']);
            }

            return DiarioContabil::create(['codigo' => 'AC', 'nome' => 'Acréscimos e diferimentos'])->id;
        });
        $this->s = $this->sessao(['ad_registos_view', 'ad_propostas_view', 'ad_recolher_view', 'ad_editar', 'ad_contabilizar', 'ad_definicoes_edit']);
        $this->putJson('/api/acrescimos/definicoes', ['contas' => ['ACRESCIMO_CUSTO' => '3754', 'ACRESCIMO_PROVEITO' => '3731', 'DIFERIMENTO_CUSTO' => '3743',
            'DIFERIMENTO_PROVEITO' => '3766'], 'diario_id' => $this->diario, 'prazo_documento_dias' => 60], $this->s)->assertOk();
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    /** @return list<string> linhas activas do Diário do módulo, "D conta valor" */
    private function diario(): array
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('tipo_origem', 'ACRESCIMOS')
            ->whereNull('estornado_por_id')->orderBy('id')->get()->map(fn ($l) => "{$l->numero_documento} {$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
    }

    private function criar(array $d): int
    {
        return $this->postJson('/api/acrescimos/itens', $d + ['natureza' => 'CUSTO', 'conta_resultado' => '752331', 'reparticao' => 'MESES'], $this->s)
            ->assertCreated()->json('dados.item.id');
    }

    private function chaves(string $mes): array
    {
        return array_column($this->getJson("/api/acrescimos/proposta?mes={$mes}", $this->s)->assertOk()->json('dados.linhas'), 'chave');
    }

    #[Test]
    public function quotas_com_arredondamento_acumulado_por_meses_e_por_dias(): void
    {
        // dados reais (empresa 10): 3 379 000 de 31/08/2026 a 30/08/2027 por meses → 13 quotas, 259 923,08 e depois 259 923,07
        $q = $this->postJson('/api/acrescimos/quotas', ['valor' => 3379000, 'data_inicio' => '2026-08-31', 'data_fim' => '2027-08-30', 'reparticao' => 'MESES'], $this->s)
            ->assertOk()->json('dados');
        $this->assertCount(13, $q);
        $this->assertSame(['259923.08', '259923.07', '259923.08'], array_column(array_slice($q, 0, 3), 'valor'));
        $this->assertSame('3379000.00', array_reduce(array_column($q, 'valor'), fn ($s, $v) => bcadd($s, $v, 2), '0.00'));
        // por dias: 1 000 de 15/01 a 14/03/2026 (17 + 28 + 14 = 59 dias); a última absorve a diferença
        $q = $this->postJson('/api/acrescimos/quotas', ['valor' => 1000, 'data_inicio' => '2026-01-15', 'data_fim' => '2026-03-14', 'reparticao' => 'DIAS'], $this->s)->json('dados');
        $this->assertSame([17, 28, 14], array_column($q, 'peso'));
        $this->assertSame(['288.14', '474.57', '237.29'], array_column($q, 'valor'));
    }

    #[Test]
    public function definicoes_e_validacao_do_registo(): void
    {
        $this->putJson('/api/acrescimos/definicoes', ['contas' => ['ACRESCIMO_CUSTO' => '37', 'ACRESCIMO_PROVEITO' => '3731', 'DIFERIMENTO_CUSTO' => '9999', 'DIFERIMENTO_PROVEITO' => '3766'],
            'diario_id' => $this->diario], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DEFINICOES_INVALIDAS')
            ->assertJsonPath('erros.ACRESCIMO_CUSTO', 'Encargos a pagar: 37 é uma conta totalizadora — escolha uma subconta de movimento.');
        $this->getJson('/api/acrescimos/definicoes', $this->s)->assertOk()->assertJsonPath('dados.contas.ACRESCIMO_CUSTO', '3754')->assertJsonPath('dados.prazo_documento_dias', 60);

        // gasto tem de ir a uma conta 7; diferimento exige a data do documento
        $this->postJson('/api/acrescimos/itens', ['tipo' => 'DIFERIMENTO', 'natureza' => 'CUSTO', 'descricao' => 'Seguro', 'valor' => 1200, 'conta_resultado' => '6211',
            'conta_balanco' => '3743', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_INVALIDO')
            ->assertJsonPath('erros.conta_resultado', 'Conta de gasto: 6211 deve começar por 7.')
            ->assertJsonPath('erros.data_documento', 'Diferimento: indique a data do documento pago/recebido (o lançamento inicial é feito nesse mês).');
        // acréscimo: data limite = fim + prazo
        $id = $this->criar(['tipo' => 'ACRESCIMO', 'descricao' => 'Electricidade de Março', 'valor' => 500, 'conta_resultado' => '7521', 'conta_balanco' => '3754',
            'data_inicio' => '2026-03-01', 'data_fim' => '2026-03-31']);
        $this->getJson("/api/acrescimos/itens/{$id}", $this->s)->assertOk()->assertJsonPath('dados.item.estado', 'ACTIVO')
            ->assertJsonPath('dados.quotas.0.valor', '500.00');
        $this->assertStringStartsWith('2026-05-30', $this->getJson("/api/acrescimos/itens/{$id}", $this->s)->json('dados.item.data_limite'));
        $this->getJson('/api/acrescimos/itens', $this->sessao(['pos_view']))->assertForbidden();
        $this->postJson('/api/acrescimos/proposta/contabilizar', ['mes' => '2026-03', 'chaves' => ['x']], $this->sessao(['ad_propostas_view']))->assertForbidden();
    }

    #[Test]
    public function diferimento_proposta_contabilizacao_descontabilizacao_e_conclusao(): void
    {
        $s = $this->s;
        // seguro de 1 200 pago a 10/01/2026, 1/01 a 30/04 (4 meses)
        $id = $this->criar(['tipo' => 'DIFERIMENTO', 'descricao' => 'Seguro multirriscos', 'valor' => 1200, 'conta_balanco' => '3743',
            'data_inicio' => '2026-01-01', 'data_fim' => '2026-04-30', 'data_documento' => '2026-01-10']);
        $p = $this->getJson('/api/acrescimos/proposta?mes=2026-02', $s)->assertOk()->json('dados');
        $this->assertSame(["{$id}|INICIAL|2026-01", "{$id}|RECONHECIMENTO|2026-01", "{$id}|RECONHECIMENTO|2026-02"], array_column($p['linhas'], 'chave'));
        $this->assertSame(['2026-01-10', '2026-01-31', '2026-02-28'], array_column($p['linhas'], 'data'));
        $this->assertSame([true, true, false], array_column($p['linhas'], 'atrasada'));
        $this->assertSame('1800.00', $p['total']);

        $r = $this->postJson('/api/acrescimos/proposta/contabilizar', ['mes' => '2026-02', 'chaves' => array_column($p['linhas'], 'chave')], $s)->assertOk()->json('dados');
        $this->assertCount(3, $r['ok']);
        $this->assertSame([], $r['erros']);
        $this->assertSame([
            "AD{$id}-202601-INI D 3743 1200.00", "AD{$id}-202601-INI C 752331 1200.00",
            "AD{$id}-202601-REC D 752331 300.00", "AD{$id}-202601-REC C 3743 300.00",
            "AD{$id}-202602-REC D 752331 300.00", "AD{$id}-202602-REC C 3743 300.00",
        ], $this->diario());
        $this->assertSame([], $this->chaves('2026-02'));   // não duplica
        $this->postJson('/api/acrescimos/proposta/contabilizar', ['mes' => '2026-02', 'chaves' => array_column($p['linhas'], 'chave')], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SEM_LINHAS');

        // descontabilizar sem buracos: o reconhecimento de Janeiro depende do de Fevereiro; o inicial de todos
        $lanc = collect($this->getJson("/api/acrescimos/lancamentos?item_id={$id}", $s)->json('dados'))->keyBy(fn ($l) => "{$l['tipo']}|{$l['periodo']}");
        $this->postJson("/api/acrescimos/lancamentos/{$lanc['RECONHECIMENTO|2026-01']['id']}/descontabilizar", ['motivo' => 'Erro de período'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTOS_DEPENDENTES');
        $this->postJson("/api/acrescimos/lancamentos/{$lanc['INICIAL|2026-01']['id']}/descontabilizar", ['motivo' => 'Erro de período'], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'LANCAMENTOS_DEPENDENTES');
        $this->postJson("/api/acrescimos/lancamentos/{$lanc['RECONHECIMENTO|2026-02']['id']}/descontabilizar", ['motivo' => 'Erro de período'], $s)
            ->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $estorno = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('tipo_origem', 'ESTORNO')->orderBy('id')->get());
        $this->assertSame(['C 752331 300.00', 'D 3743 300.00'], $estorno->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
        $this->assertSame(["{$id}|RECONHECIMENTO|2026-02"], $this->chaves('2026-02'));

        // reconciliação: módulo = Diário (1 200 − 300)
        $rec = collect($this->getJson('/api/acrescimos/reconciliacao', $s)->json('dados'))->keyBy('conta');
        $this->assertSame(['conta' => '3743', 'modulo' => '900.00', 'diario' => '900.00', 'diferenca' => '0.00'], $rec['3743']);

        // Abril: fecham-se os três meses em falta → CONCLUIDO
        $this->postJson('/api/acrescimos/proposta/contabilizar', ['mes' => '2026-04', 'chaves' => $this->chaves('2026-04')], $s)->assertOk()->assertJsonCount(3, 'dados.ok');
        $this->getJson("/api/acrescimos/itens/{$id}", $s)->assertJsonPath('dados.item.estado', 'CONCLUIDO')->assertJsonPath('dados.reconhecido', '1200.00')
            ->assertJsonPath('dados.saldo_balanco', '0.00');
        $this->getJson('/api/acrescimos/itens', $s)->assertOk()->assertJsonCount(0, 'dados');   // por omissão só os abertos
        $this->deleteJson("/api/acrescimos/itens/{$id}", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_CONTABILIZADO');
    }

    #[Test]
    public function acrescimo_regularizado_com_o_documento_real_e_anulacao_sem_lancamentos(): void
    {
        $s = $this->s;
        // consumos de Jan-Fev (1 000 por dias: 31/59 e 28/59)
        $id = $this->criar(['tipo' => 'ACRESCIMO', 'descricao' => 'Electricidade Jan-Fev', 'valor' => 1000, 'conta_resultado' => '7521', 'conta_balanco' => '3754',
            'data_inicio' => '2026-01-01', 'data_fim' => '2026-02-28', 'reparticao' => 'DIAS']);
        $this->postJson('/api/acrescimos/proposta/contabilizar', ['mes' => '2026-02', 'chaves' => $this->chaves('2026-02')], $s)->assertOk()->assertJsonCount(2, 'dados.ok');
        // com lançamentos só se alteram notas e data limite
        $this->putJson("/api/acrescimos/itens/{$id}", ['tipo' => 'ACRESCIMO', 'natureza' => 'CUSTO', 'descricao' => 'Outro', 'valor' => 5000, 'conta_resultado' => '7521',
            'conta_balanco' => '3754', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-02-28', 'notas' => 'Aguarda factura', 'data_limite' => '2026-06-30'], $s)
            ->assertOk()->assertJsonPath('dados.parcial', true)->assertJsonPath('dados.item.valor', '1000.00')->assertJsonPath('dados.item.notas', 'Aguarda factura');

        // factura real de 1 100 em 05/03: a regularização salda a 37 pelo reconhecido; a diferença fica informativa
        $this->postJson("/api/acrescimos/itens/{$id}/regularizar", ['data' => '2026-03-05', 'valor' => 1100, 'doc' => 'FT 2026/77', 'fonte' => 'MANUAL'], $s)
            ->assertOk()->assertJsonPath('dados.estado', 'A_REGULARIZAR')->assertJsonPath('dados.regularizacao.doc', 'FT 2026/77');
        $l = $this->getJson('/api/acrescimos/proposta?mes=2026-03', $s)->json('dados.linhas');
        $this->assertSame([["{$id}|REGULARIZACAO|2026-03", '1000.00', '100.00', '3754', '7521', '2026-03-05']],
            array_map(fn ($x) => [$x['chave'], $x['valor'], $x['diferenca'], $x['debito'], $x['credito'], $x['data']], $l));
        $this->postJson('/api/acrescimos/proposta/contabilizar', ['mes' => '2026-03', 'chaves' => [$l[0]['chave']]], $s)->assertOk();
        $this->getJson("/api/acrescimos/itens/{$id}", $s)->assertJsonPath('dados.item.estado', 'REGULARIZADO')->assertJsonPath('dados.saldo_balanco', '0.00');
        $this->assertContains("AD{$id}-202603-REG D 3754 1000.00", $this->diario());
        $this->postJson("/api/acrescimos/itens/{$id}/regularizar", ['data' => '2026-03-06', 'valor' => 1, 'doc' => 'X'], $s)->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_INVALIDO');

        // anulação sem nada contabilizado nem por contabilizar antes do documento: fecha logo
        $id2 = $this->criar(['tipo' => 'ACRESCIMO', 'descricao' => 'Juros de Maio', 'valor' => 300, 'conta_resultado' => '7521', 'conta_balanco' => '3754',
            'data_inicio' => '2026-05-01', 'data_fim' => '2026-05-31']);
        $this->postJson("/api/acrescimos/itens/{$id2}/regularizar", ['data' => '2026-05-10', 'anulacao' => true, 'motivo' => 'Não há juros'], $s)
            ->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $this->deleteJson("/api/acrescimos/itens/{$id2}", [], $s)->assertOk();
    }

    #[Test]
    public function termino_antecipado_reconhece_o_saldo_e_desfazer_pedido(): void
    {
        $s = $this->s;
        $id = $this->criar(['tipo' => 'DIFERIMENTO', 'descricao' => 'Renda adiantada', 'valor' => 900, 'conta_balanco' => '3743',
            'data_inicio' => '2026-01-01', 'data_fim' => '2026-03-31', 'data_documento' => '2026-01-02']);
        $this->postJson('/api/acrescimos/proposta/contabilizar', ['mes' => '2026-01', 'chaves' => $this->chaves('2026-01')], $s)->assertOk()->assertJsonCount(2, 'dados.ok');
        $this->postJson("/api/acrescimos/itens/{$id}/terminar", ['data' => '2025-12-01'], $s)->assertStatus(422)->assertJsonPath('codigo', 'DATA_INVALIDA');
        $this->postJson("/api/acrescimos/itens/{$id}/terminar", ['data' => '2026-02-15', 'motivo' => 'Contrato cancelado'], $s)->assertOk()->assertJsonPath('dados.estado', 'A_TERMINAR');
        $this->postJson("/api/acrescimos/itens/{$id}/desfazer-pedido", [], $s)->assertOk()->assertJsonPath('dados.estado', 'ACTIVO');
        $this->postJson("/api/acrescimos/itens/{$id}/terminar", ['data' => '2026-02-15', 'motivo' => 'Contrato cancelado'], $s)->assertOk();
        // Fevereiro ainda se reconhece (300) e o término leva o restante (300)
        $l = $this->getJson('/api/acrescimos/proposta?mes=2026-03', $s)->json('dados.linhas');
        $this->assertSame([["{$id}|RECONHECIMENTO|2026-02", '300.00'], ["{$id}|TERMINO|2026-02", '300.00']], array_map(fn ($x) => [$x['chave'], $x['valor']], $l));
        $this->postJson('/api/acrescimos/proposta/contabilizar', ['mes' => '2026-03', 'chaves' => array_column($l, 'chave')], $s)->assertOk();
        $this->getJson("/api/acrescimos/itens/{$id}", $s)->assertJsonPath('dados.item.estado', 'CONCLUIDO')->assertJsonPath('dados.saldo_balanco', '0.00');
    }

    #[Test]
    public function alerta_de_documento_em_falta_e_recolha_do_diario(): void
    {
        $s = $this->s;
        $this->criar(['tipo' => 'ACRESCIMO', 'descricao' => 'Comunicações', 'valor' => 200, 'conta_resultado' => '7521', 'conta_balanco' => '3754',
            'data_inicio' => '2026-01-01', 'data_fim' => '2026-01-31', 'data_limite' => '2026-02-10']);
        $this->assertSame(['Comunicações'], array_column($this->getJson('/api/acrescimos/proposta?mes=2026-03', $s)->json('dados.alertas'), 'descricao'));
        $this->assertSame([], $this->getJson('/api/acrescimos/proposta?mes=2026-01', $s)->json('dados.alertas'));

        // um lançamento manual com gasto 7 aparece como candidato; os do próprio módulo não
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoLancamentos::class)->criar(['diario_id' => $this->diario, 'data_documento' => '2026-01-20',
            'numero_documento' => 'FT 55', 'descricao' => 'Seguro anual', 'linhas' => [['codigo_conta' => '752331', 'tipo_dc' => 'D', 'valor' => 2400],
                ['codigo_conta' => '321', 'tipo_dc' => 'C', 'valor' => 2400]]]));
        $this->postJson('/api/acrescimos/proposta/contabilizar', ['mes' => '2026-01', 'chaves' => $this->chaves('2026-01')], $s)->assertOk();
        $c = $this->getJson('/api/acrescimos/recolha?fonte=DIARIO&de=2026-01-01&ate=2026-12-31', $s)->assertOk()->json('dados');
        $this->assertCount(1, $c);
        $this->assertSame(['FT 55', 'CUSTO', '2400.00', false], [$c[0]['doc'], $c[0]['natureza'], $c[0]['total'], $c[0]['ligado']]);
        $this->assertSame([['conta' => '752331', 'valor' => '2400.00']], $c[0]['linhas']);
        $a = $this->getJson('/api/acrescimos/recolha/acrescimos-abertos?natureza=CUSTO&contas[]=7521', $s)->assertOk()->json('dados');
        $this->assertSame([1], array_column($a, 'afinidade'));
    }
}
