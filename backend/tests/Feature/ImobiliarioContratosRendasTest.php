<?php

namespace Tests\Feature;

use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Sistema\CatalogoPermissoes;
use App\Support\Tenancy\ContextoEmpresa;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gestão Imobiliária, Onda 2 (F3): contratos (numeração, conflitos de datas, activar, suspender, renovar, terminar com cascata, editar,
 * eliminar), rendas (gerar idempotente, pro-rata, aprovar, anular, recalcular, actualizar renda), facturação sobre Vendas sem duplicar,
 * pagamento sobre a Tesouraria, conta corrente e mora, caução com lançamentos equilibrados e repetíveis, subarrendamento (regras e
 * margem), isolamento por empresa, permissões e idempotência da migração. Os números são calculados à mão (ver também
 * Tests\Unit\ImobiliarioCalculadorasTest). A data é fixada em 2026-03-20.
 */
final class ImobiliarioContratosRendasTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    /** @var list<string> */
    private array $perms = [];

    private int $cliente;

    private int $proprietario;

    private int $artigo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-03-20 10:00:00'));
        $this->empresa = $this->criarEmpresa(['nif' => '5417000555']);
        $this->ctx(function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '4311' => 'Banco', '621' => 'Rendas pagas', '3451' => 'Retenções a entregar', '3711' => 'Cauções de terceiros',
                '7911' => 'Caução retida', '1311' => 'Cauções prestadas', '3459' => 'Imposto predial retido (crédito)', '611' => 'Vendas'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = (int) Terceiro::create(['nome' => 'Inquilino Fictício', 'nif' => '5000000555', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->proprietario = (int) Terceiro::create(['nome' => 'Senhorio Fictício', 'nif' => '5000000556', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->artigo = (int) Produto::create(['codigo' => 'RENDA', 'nome' => 'Renda de arrendamento', 'preco_unitario' => 0, 'taxa_imposto' => 0, 'codigo_isencao_fe' => 'M14',
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false])->id;
        });
        $this->perms = ['imo_imoveis_view', 'imo_contratos_view', 'imo_rendas_view', 'imo_sub_view', 'imo_modelos_view', 'imo_imovel_editar', 'imo_fraccao_editar',
            'imo_estado_mudar', 'imo_contrato_editar', 'imo_contrato_activar', 'imo_contrato_terminar', 'imo_contrato_suspender', 'imo_contrato_renovar', 'imo_caucao_movimentar',
            'imo_renda_gerar', 'imo_renda_aprovar', 'imo_renda_facturar', 'imo_renda_pagar', 'imo_sub_debitar', 'imo_taxas_editar'];
        $this->s = $this->sessao($this->perms);
    }

    /** @template T @param  callable(): T  $f  @return T */
    private function ctx(callable $f, ?int $empresa = null): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($empresa ?? $this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function imovel(string $papel = 'PROPRIO', ?array $h = null): int
    {
        $d = ['nome' => 'Edifício Girassol', 'tipo' => 'PREDIO', 'papel' => $papel, 'n_pisos' => 2];
        if ($papel !== 'PROPRIO') {
            $d['proprietario_terceiro_id'] = $this->proprietario;
        }

        return $this->postJson('/api/imobiliario/imoveis', $d, $h ?? $this->s)->assertCreated()->json('dados.id');
    }

    private function fracao(int $imovel, string $nome = '101', ?array $h = null): int
    {
        return $this->postJson("/api/imobiliario/imoveis/{$imovel}/fracoes", ['designacao' => $nome, 'renda_referencia' => 100000], $h ?? $this->s)->assertCreated()->json('dados.id');
    }

    /** @param  array<string, mixed>  $o */
    private function corpo(int $fracao, array $o = []): array
    {
        return $o + ['tipo' => 'ARRENDAMENTO', 'ambito' => 'FRACCAO', 'fracao_id' => $fracao, 'terceiro_id' => $this->cliente, 'inicio' => '2026-01-16', 'renda' => 100000,
            'periodicidade' => 'MENSAL', 'dia_vencimento' => 5, 'antecipada' => true, 'finalidade_fiscal' => 'HABITACAO', 'retencao_ip' => true, 'caucao' => 0];
    }

    /** @param  array<string, mixed>  $corpo */
    private function contrato(array $corpo, bool $activar = false): array
    {
        $c = $this->postJson('/api/imobiliario/contratos', $corpo, $this->s)->assertCreated()->json('dados');
        if ($activar) {
            $this->postJson("/api/imobiliario/contratos/{$c['id']}/activar", [], $this->s)->assertOk();
        }

        return $c;
    }

    /** @return list<array<string, mixed>> */
    private function rendas(int $contrato): array
    {
        return $this->getJson("/api/imobiliario/contratos/{$contrato}/conta-corrente", $this->s)->assertOk()->json('dados.linhas');
    }

    private function configurar(): void
    {
        $this->putJson('/api/imobiliario/configuracao', ['produto_renda_id' => $this->artigo, 'conta_caucao_passivo' => '3711', 'conta_caucao_activo' => '1311',
            'conta_caucao_receita' => '7911', 'conta_ip_credito' => '3459'], $this->s)->assertOk();
    }

    /** Contrato tomado (a empresa é inquilina) num imóvel arrendado. @return array{0: array<string, mixed>, 1: int} o contrato e a fracção */
    private function tomada(array $o = [], bool $activar = true): array
    {
        $f = $this->fracao($this->imovel('ARRENDADO'));
        $c = $this->contrato($this->corpo($f, $o + ['tipo' => 'TOMADA', 'terceiro_id' => $this->proprietario, 'inicio' => '2026-01-01', 'conta_custo' => '621', 'conta_retencao' => '3451']), $activar);

        return [$c, $f];
    }

    // ---------------------------------------------------------------- numeração, validações e conflitos

    #[Test]
    public function contratos_nascem_em_rascunho_com_numero_por_tipo_e_ano_e_deixam_trilho(): void
    {
        $im = $this->imovel();
        $c1 = $this->contrato($this->corpo($this->fracao($im, '101')));
        $c2 = $this->contrato($this->corpo($this->fracao($im, '102')));
        $this->assertSame('AR 2026/0001', $c1['numero']);
        $this->assertSame('AR 2026/0002', $c2['numero']);
        $this->assertSame('RASCUNHO', $c1['estado']);
        $this->assertSame('SENHORIO', $c1['posicao']);
        [$t] = $this->tomada([], false);
        $this->assertSame('AT 2026/0001', $t['numero']);
        $this->assertSame('INQUILINO', $t['posicao']);
        $this->assertSame(['CRIADO'], array_column($c1['historico'], 'evento'));
        $this->assertSame('100000', (string) (int) $c1['renda']);
    }

    #[Test]
    public function validacoes_do_formulario_com_as_mensagens_do_legado(): void
    {
        $im = $this->imovel();
        $f = $this->fracao($im, '101');
        $post = fn (array $o) => $this->postJson('/api/imobiliario/contratos', $this->corpo($f, $o), $this->s);
        $post(['renda' => 0])->assertStatus(422)->assertJsonPath('codigo', 'IMO_RENDA_ZERO')->assertJsonPath('mensagem', 'A renda tem de ser maior do que zero.');
        $post(['caucao' => 700000])->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_MAXIMA');
        $post(['fim' => '2026-01-16'])->assertStatus(422)->assertJsonPath('codigo', 'IMO_FIM_ANTES_INICIO');
        $post(['actualizacao' => 'PERC', 'actualizacao_valor' => 150])->assertStatus(422)->assertJsonPath('codigo', 'IMO_ACTUALIZACAO_PERC');
        $post(['actualizacao' => 'VALOR', 'actualizacao_valor' => 0])->assertStatus(422)->assertJsonPath('codigo', 'IMO_ACTUALIZACAO_VALOR');
        $this->postJson('/api/imobiliario/contratos', $this->corpo($f, ['fracao_id' => null]), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_FRACCAO_OBRIGATORIA');
        $post(['terceiro_id' => null])->assertStatus(422)->assertJsonPath('mensagem', 'Indique o inquilino.');
        $post(['inicio' => null])->assertStatus(422)->assertJsonPath('codigo', 'IMO_INICIO');
        // o inquilino não pode ser o proprietário (imóvel arrendado a terceiros) e o contrato tomado exige a conta de custo
        $ia = $this->imovel('ARRENDADO');
        $fa = $this->fracao($ia, 'A1');
        $this->postJson('/api/imobiliario/contratos', $this->corpo($fa, ['terceiro_id' => $this->proprietario]), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_INQUILINO_PROPRIETARIO');
        $this->postJson('/api/imobiliario/contratos', $this->corpo($fa, ['tipo' => 'TOMADA', 'terceiro_id' => $this->proprietario]), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTA_CUSTO');
        $this->postJson('/api/imobiliario/contratos', $this->corpo($fa, ['tipo' => 'TOMADA', 'terceiro_id' => $this->proprietario, 'conta_custo' => '621']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTA_RETENCAO');
        $this->postJson('/api/imobiliario/contratos', $this->corpo($fa, ['tipo' => 'TOMADA', 'terceiro_id' => $this->proprietario, 'conta_custo' => '999', 'conta_retencao' => '3451']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        // tomada num imóvel próprio e arrendamento em mediação
        $this->postJson('/api/imobiliario/contratos', $this->corpo($f, ['tipo' => 'TOMADA', 'terceiro_id' => $this->proprietario, 'conta_custo' => '621', 'conta_retencao' => '3451']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_IMOVEL_PAPEL');
        $im2 = $this->imovel('MEDIACAO');
        $this->postJson('/api/imobiliario/contratos', $this->corpo($this->fracao($im2, 'M1')), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_IMOVEL_MEDIACAO');
    }

    #[Test]
    public function ambitos_imovel_inteiro_e_varias_fracoes(): void
    {
        $im = $this->imovel();
        $f1 = $this->fracao($im, '101');
        $f2 = $this->fracao($im, '102');
        $f3 = $this->fracao($im, '103');
        $todas = $this->postJson('/api/imobiliario/contratos', ['ambito' => 'IMOVEL', 'imovel_id' => $im] + $this->corpo($f1, ['fracao_id' => null]), $this->s)->assertCreated()->json('dados');
        $this->assertCount(3, $todas['fracoes_detalhe']);
        // uma fracção dessas já está noutro contrato vivo: PARCIAL com a 102 e a 104 conflita
        $f4 = $this->fracao($im, '104');
        $this->postJson('/api/imobiliario/contratos', ['ambito' => 'PARCIAL', 'imovel_id' => $im, 'fracoes_ids' => [$f2, $f4]] + $this->corpo($f1, ['fracao_id' => null]), $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONFLITO_DATAS');
        $this->postJson('/api/imobiliario/contratos', ['ambito' => 'PARCIAL', 'imovel_id' => $im, 'fracoes_ids' => [$f4]] + $this->corpo($f1, ['fracao_id' => null]), $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'IMO_FRACCOES_POUCAS');
        $im2 = $this->imovel();
        $outra = $this->fracao($im2, 'X1');
        $this->postJson('/api/imobiliario/contratos', ['ambito' => 'PARCIAL', 'imovel_id' => $im, 'fracoes_ids' => [$f4, $outra]] + $this->corpo($f1, ['fracao_id' => null]), $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'IMO_FRACCOES_OUTRO_IMOVEL');
        $this->assertNotNull($f3);
    }

    #[Test]
    public function conflito_de_datas_por_fracao_so_com_intervalos_que_se_cruzam(): void
    {
        $f = $this->fracao($this->imovel());
        $a = $this->contrato($this->corpo($f, ['inicio' => '2026-01-01', 'fim' => '2026-12-31']));
        $r = $this->postJson('/api/imobiliario/contratos', $this->corpo($f, ['inicio' => '2026-06-01']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONFLITO_DATAS');
        $this->assertStringContainsString($a['numero'], $r->json('mensagem'));
        $this->assertStringContainsString('2026-01-01 a 2026-12-31', $r->json('mensagem'));
        $this->postJson('/api/imobiliario/contratos', $this->corpo($f, ['inicio' => '2026-12-31']), $this->s)->assertStatus(422);   // o último dia ainda é do primeiro
        $this->postJson('/api/imobiliario/contratos', $this->corpo($f, ['inicio' => '2027-01-01']), $this->s)->assertCreated();
        // contrato terminado liberta o intervalo
        $f2 = $this->fracao($this->imovel(), '201');
        $b = $this->contrato($this->corpo($f2, ['inicio' => '2026-01-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$b['id']}/terminar", ['data' => '2026-03-01', 'motivo' => 'Fim do prazo'], $this->s)->assertOk();
        $this->postJson('/api/imobiliario/contratos', $this->corpo($f2, ['inicio' => '2026-03-02']), $this->s)->assertCreated();
    }

    // ---------------------------------------------------------------- activar, suspender, renovar, editar, eliminar

    #[Test]
    public function activar_marca_a_fraccao_arrendada_e_deixa_historico(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f));
        $r = $this->postJson("/api/imobiliario/contratos/{$c['id']}/activar", [], $this->s)->assertOk();
        $this->assertSame('ACTIVO', $r->json('dados.estado'));
        $this->assertSame(['CRIADO', 'ACTIVADO'], array_reverse(array_column($r->json('dados.historico'), 'evento')));
        $this->assertSame('ARRENDADA', $this->getJson("/api/imobiliario/fracoes/{$f}", $this->s)->json('dados.estado'));
        $this->assertSame(1, DB::table('historico_estado_fracao')->where('fracao_id', $f)->where('estado_para', 'ARRENDADA')->count());
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/activar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTRATO_JA_ACTIVO');
    }

    #[Test]
    public function suspender_reactivar_e_renovar_o_contrato(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['fim' => '2026-12-31']), true);
        $id = $c['id'];
        $this->postJson("/api/imobiliario/contratos/{$id}/suspender", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_MOTIVO_OBRIGATORIO');
        $this->postJson("/api/imobiliario/contratos/{$id}/suspender", ['motivo' => 'Obras no prédio'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'SUSPENSO');
        $this->postJson("/api/imobiliario/contratos/{$id}/rendas/gerar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTRATO_NAO_ACTIVO');
        $this->postJson("/api/imobiliario/contratos/{$id}/renovar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTRATO_NAO_ACTIVO');
        $r = $this->postJson("/api/imobiliario/contratos/{$id}/activar", [], $this->s)->assertOk();
        $this->assertContains('REACTIVADO', array_column($r->json('dados.historico'), 'evento'));
        $this->postJson("/api/imobiliario/contratos/{$id}/renovar", ['meses' => 12], $this->s)->assertOk()->assertJsonPath('dados.fim', '2027-12-31');
        $h = $this->getJson("/api/imobiliario/contratos/{$id}", $this->s)->json('dados.historico');
        $renovado = collect($h)->firstWhere('evento', 'RENOVADO');
        $this->assertEquals(['fim_anterior' => '2026-12-31', 'fim_novo' => '2027-12-31', 'meses' => 12], $renovado['dados']);
        $semTermo = $this->contrato($this->corpo($this->fracao($this->imovel(), '201')), true);
        $this->postJson("/api/imobiliario/contratos/{$semTermo['id']}/renovar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_SEM_TERMO');
    }

    #[Test]
    public function um_contrato_em_vigor_nao_muda_a_estrutura_mas_a_renda_sim(): void
    {
        $f = $this->fracao($this->imovel());
        $corpo = $this->corpo($f);
        $c = $this->contrato($corpo, true);
        $this->putJson("/api/imobiliario/contratos/{$c['id']}", ['renda' => 120000] + $corpo, $this->s)->assertOk();
        $this->assertEquals(120000, $this->getJson("/api/imobiliario/contratos/{$c['id']}", $this->s)->json('dados.renda'));
        $outro = (int) $this->ctx(fn () => Terceiro::create(['nome' => 'Outro Inquilino', 'nif' => '5000000999', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id);
        $this->putJson("/api/imobiliario/contratos/{$c['id']}", ['terceiro_id' => $outro] + $corpo, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTRATO_EM_VIGOR');
        $this->putJson("/api/imobiliario/contratos/{$c['id']}", ['inicio' => '2026-02-01'] + $corpo, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTRATO_EM_VIGOR');
        $this->assertSame('SENHORIO', $this->getJson("/api/imobiliario/contratos/{$c['id']}", $this->s)->json('dados.posicao'));
    }

    #[Test]
    public function eliminar_so_rascunho_sem_documentos(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f), true);
        $this->deleteJson("/api/imobiliario/contratos/{$c['id']}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTRATO_EM_VIGOR');
        $r = $this->contrato($this->corpo($this->fracao($this->imovel(), '201')));
        $this->deleteJson("/api/imobiliario/contratos/{$r['id']}", [], $this->s)->assertOk();
        $this->getJson("/api/imobiliario/contratos/{$r['id']}", $this->s)->assertNotFound();
        // terminado com renda já em documento: não se apaga
        $f3 = $this->fracao($this->imovel(), '301');
        $t = $this->contrato($this->corpo($f3, ['inicio' => '2026-03-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$t['id']}/rendas/gerar", [], $this->s)->assertOk();
        DB::table('rendas_arrendamento')->where('contrato_id', $t['id'])->update(['estado' => 'PAGA']);
        $this->postJson("/api/imobiliario/contratos/{$t['id']}/terminar", ['data' => '2026-03-31', 'motivo' => 'Saída'], $this->s)->assertOk();
        $this->deleteJson("/api/imobiliario/contratos/{$t['id']}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTRATO_COM_DOCUMENTOS');
    }

    // ---------------------------------------------------------------- rendas

    #[Test]
    public function gerar_rendas_e_idempotente_e_o_primeiro_periodo_e_proporcional(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f), true);
        $r = $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $this->assertSame(3, $r->json('dados.criadas'));
        $this->assertSame(['2026-01', '2026-02', '2026-03'], $r->json('dados.periodos'));
        $linhas = $this->rendas($c['id']);
        $this->assertSame(51612.9, $linhas[0]['renda']);          // 100 000 × 16/31
        $this->assertSame('2026-01-05', $linhas[0]['vencimento']);
        $this->assertSame(43870.96, $linhas[0]['total_receber']);  // IP 7 741,94 retido
        $this->assertSame(100000.0, $linhas[1]['renda']);
        $this->assertSame(85000.0, $linhas[2]['total_receber']);
        $this->assertSame('PREVISTA', $linhas[1]['estado']);
        // repetir não duplica
        $this->assertSame(0, $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk()->json('dados.criadas'));
        $this->assertSame(3, DB::table('rendas_arrendamento')->where('contrato_id', $c['id'])->count());
        // rendas do mês de Fevereiro
        $mes = $this->getJson('/api/imobiliario/rendas?periodo=2026-02&posicao=SENHORIO', $this->s)->assertOk();
        $this->assertCount(1, $mes->json('dados.linhas'));
        $this->assertSame(100000.0, $mes->json('dados.totais.total_receber') + 15000.0);
    }

    #[Test]
    public function o_legado_sem_pro_rata_cobra_o_primeiro_periodo_inteiro(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['pro_rata' => false]), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $linhas = $this->rendas($c['id']);
        $this->assertSame(100000.0, $linhas[0]['renda']);
        $this->assertSame(85000.0, $linhas[0]['total_receber']);
    }

    #[Test]
    public function aprovar_recalcular_e_anular_uma_renda(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['inicio' => '2026-01-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $ids = array_column($this->rendas($c['id']), 'id');
        $r = $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => $ids], $this->s)->assertOk();
        $this->assertSame(3, $r->json('dados.aprovadas'));
        $again = $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => $ids], $this->s)->assertOk();
        $this->assertSame(0, $again->json('dados.aprovadas'));
        $this->assertCount(3, $again->json('dados.ignoradas'));
        // recalcular uma aprovada (com desconto) devolve-a a «prevista»
        $rec = $this->postJson("/api/imobiliario/rendas/{$ids[0]}/recalcular", ['desconto' => 10000], $this->s)->assertOk();
        $this->assertSame('PREVISTA', $rec->json('dados.estado'));
        $this->assertSame(90000.0, $rec->json('dados.base'));
        $this->assertSame(13500.0, $rec->json('dados.ip_valor'));   // 90 000 × 60 % × 25 %
        // anular exige motivo; uma renda anulada não se regenera nem se recalcula
        $this->postJson("/api/imobiliario/rendas/{$ids[1]}/anular", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_MOTIVO_OBRIGATORIO');
        $this->postJson("/api/imobiliario/rendas/{$ids[1]}/anular", ['motivo' => 'Inquilino em obras'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'ANULADA');
        $this->assertSame(0, $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->json('dados.criadas'));
        $this->postJson("/api/imobiliario/rendas/{$ids[1]}/recalcular", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_RENDA_ANULADA');
        $this->assertCount(2, $this->rendas($c['id']));
    }

    #[Test]
    public function actualizar_a_renda_recalcula_so_o_que_ainda_nao_e_documento(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['inicio' => '2026-01-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $janeiro = $this->rendas($c['id'])[0]['id'];
        DB::table('rendas_arrendamento')->where('id', $janeiro)->update(['estado' => 'PAGA']);
        $r = $this->postJson("/api/imobiliario/contratos/{$c['id']}/actualizar-renda", ['renda_nova' => 110000, 'data_efeito' => '2026-01-01'], $this->s)->assertOk();
        $this->assertSame(2, $r->json('dados.recalculadas'));   // Fevereiro e Março; Janeiro já é documento e fica como está
        $linhas = $this->rendas($c['id']);
        $this->assertSame(100000.0, $linhas[0]['renda']);
        $this->assertSame(110000.0, $linhas[1]['renda']);
        $this->assertSame(110000.0, $linhas[2]['renda']);
        $this->assertSame(100000.0, $r->json('dados.contrato.renda_anterior'));
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/actualizar-renda", ['renda_nova' => 0, 'data_efeito' => '2026-01-01'], $this->s)->assertStatus(422);
    }

    #[Test]
    public function extra_debitado_ao_inquilino_entra_no_total_sem_mexer_no_imposto_predial(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['inicio' => '2026-03-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $id = $this->rendas($c['id'])[0]['id'];
        $r = $this->postJson("/api/imobiliario/rendas/{$id}/extras", ['designacao' => 'Reparação da canalização', 'valor' => 30000], $this->s)->assertOk();
        $this->assertSame(30000.0, $r->json('dados.extras_total'));
        $this->assertSame(130000.0, $r->json('dados.total_facturar'));
        $this->assertSame(15000.0, $r->json('dados.ip_valor'));
        $this->assertSame(115000.0, $r->json('dados.total_receber'));
        // um recalculo mantém o extra (o legado perdia-o)
        $rec = $this->postJson("/api/imobiliario/rendas/{$id}/recalcular", [], $this->s)->assertOk();
        $this->assertSame(130000.0, $rec->json('dados.total_facturar'));
    }

    // ---------------------------------------------------------------- facturar (Vendas)

    #[Test]
    public function facturar_emite_uma_so_factura_por_renda_e_repetir_nao_duplica(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['inicio' => '2026-03-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $id = $this->rendas($c['id'])[0]['id'];
        // por aprovar, e depois aprovada mas sem configuração
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$id]], $this->s)->assertOk()->assertJsonPath('dados.falhas.0.erro', 'Aprove a renda antes de a facturar — uma factura selada não se corrige.');
        $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => [$id]], $this->s)->assertOk();
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$id]], $this->s)->assertOk()->assertJsonPath('dados.falhas.0.erro', 'Falta o artigo de serviço «Renda»: indique-o na configuração do módulo imobiliário antes de facturar.');
        $this->configurar();
        $r = $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$id]], $this->s)->assertOk();
        $this->assertCount(1, $r->json('dados.feitas'));
        $this->assertFalse($r->json('dados.feitas.0.repetida'));
        $venda = $this->ctx(fn () => Venda::query()->where('cliente_id', $this->cliente)->get());
        $this->assertCount(1, $venda);
        $this->assertSame('100000.00', (string) $venda[0]->total_bruto);
        $this->assertSame('FT', $venda[0]->tipo_documento);
        $linha = $this->rendas($c['id'])[0];
        $this->assertSame('FACTURADA', $linha['estado']);
        $this->assertSame($venda[0]->id, $linha['venda_id']);
        // repetir devolve a mesma factura, sem emitir outra
        $again = $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$id]], $this->s)->assertOk();
        $this->assertTrue($again->json('dados.feitas.0.repetida'));
        $this->assertSame($r->json('dados.feitas.0.numero_documento'), $again->json('dados.feitas.0.numero_documento'));
        $this->assertCount(1, $this->ctx(fn () => Venda::query()->where('cliente_id', $this->cliente)->get()));
        // a renda facturada não se anula, não se recalcula e já não se debita
        $this->postJson("/api/imobiliario/rendas/{$id}/anular", ['motivo' => 'x'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_RENDA_FACTURADA');
        $this->postJson("/api/imobiliario/rendas/{$id}/recalcular", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_RENDA_EM_DOCUMENTO');
    }

    #[Test]
    public function facturar_recusa_renda_por_aprovar_do_lado_inquilino_e_artigo_com_iva_errado(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['inicio' => '2026-03-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $id = $this->rendas($c['id'])[0]['id'];
        $this->configurar();
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$id]], $this->s)->assertOk()
            ->assertJsonPath('dados.falhas.0.erro', 'Aprove a renda antes de a facturar — uma factura selada não se corrige.');
        [$t] = $this->tomada(['inicio' => '2026-03-01']);
        $this->postJson("/api/imobiliario/contratos/{$t['id']}/rendas/gerar", [], $this->s)->assertOk();
        $idT = $this->rendas($t['id'])[0]['id'];
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$idT]], $this->s)->assertOk()
            ->assertJsonPath('dados.falhas.0.erro', 'Neste contrato a empresa é a inquilina: a renda paga-se na Tesouraria, não se factura.');
        $this->putJson('/api/imobiliario/configuracao', ['produto_renda_id' => (int) $this->ctx(fn () => Produto::create(['codigo' => 'IVA14', 'nome' => 'Com IVA', 'preco_unitario' => 0, 'taxa_imposto' => 14,
            'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false])->id)], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_PRODUTO_IVA');
    }

    #[Test]
    public function a_retencao_do_imposto_predial_regista_se_uma_so_vez_como_credito_de_imposto(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['inicio' => '2026-03-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $id = $this->rendas($c['id'])[0]['id'];
        $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => [$id]], $this->s)->assertOk();
        $this->putJson('/api/imobiliario/configuracao', ['produto_renda_id' => $this->artigo], $this->s)->assertOk();
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$id]], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/rendas/{$id}/retencao", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_SEM_CONTA_IP');
        $this->configurar();
        $r = $this->postJson("/api/imobiliario/rendas/{$id}/retencao", [], $this->s)->assertOk();
        $lan = DB::table('rendas_arrendamento')->where('id', $id)->value('retencao_lancamento');
        $this->assertNotNull($lan);
        $linhas = $this->ctx(fn () => LancamentoContabil::query()->where('numero_lan', $lan)->orderBy('id')->get());
        $this->assertCount(2, $linhas);
        $this->assertSame(['D 3459 15000.00', 'C 311 15000.00'], $linhas->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
        $this->postJson("/api/imobiliario/rendas/{$id}/retencao", [], $this->s)->assertOk();   // repetir não lança de novo
        $this->assertSame(2, $this->ctx(fn () => LancamentoContabil::query()->where('tipo_origem', 'IMOBILIARIO')->count()));
        $this->assertSame($lan, $r->json('dados.retencao_lancamento'));
    }

    // ---------------------------------------------------------------- conta corrente e mora

    #[Test]
    public function conta_corrente_com_divida_atraso_e_juros_de_mora_a_1_por_cento(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['inicio' => '2026-03-01', 'mora_mensal' => 1]), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $id = $this->rendas($c['id'])[0]['id'];
        $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => [$id]], $this->s)->assertOk();
        $this->configurar();
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$id]], $this->s)->assertOk();
        $this->travelTo(Carbon::parse('2026-04-19 10:00:00'));   // vencimento 05/03: 45 dias de atraso
        $this->s = $this->sessao($this->perms);   // a sessão anterior caducou com a viagem no tempo
        $cc = $this->getJson("/api/imobiliario/contratos/{$c['id']}/conta-corrente", $this->s)->assertOk();
        $l = $cc->json('dados.linhas.0');
        $this->assertSame(85000.0, $l['divida']);
        $this->assertSame(45, $l['atraso']);
        $this->assertSame(1275.0, $l['juros']);                  // 85 000 × 1 % × 45/30
        $t = $cc->json('dados.totais');
        $this->assertSame([100000.0, 85000.0, 0.0, 85000.0, 1275.0, 15000.0, 100.0], [$t['facturado'], $t['esperado'], $t['recebido'], $t['em_divida'], $t['juros'], $t['ip_retido'], $t['selo_senhorio']]);
        // recebe 50 000 em Vendas: a dívida desce e os juros acompanham
        DB::table('vendas')->where('cliente_id', $this->cliente)->update(['valor_pago' => 50000]);
        $l2 = $this->getJson("/api/imobiliario/contratos/{$c['id']}/conta-corrente", $this->s)->json('dados.linhas.0');
        $this->assertSame(35000.0, $l2['divida']);
        $this->assertSame(525.0, $l2['juros']);                  // 35 000 × 1 % × 45/30
        // sem taxa no contrato usa a do ano do vencimento (2026)
        $this->putJson('/api/imobiliario/taxas/2026', ['mora_mensal' => 2], $this->s)->assertOk();
        $this->putJson("/api/imobiliario/contratos/{$c['id']}", $this->corpo($f, ['inicio' => '2026-03-01', 'mora_mensal' => null]), $this->s)->assertOk();
        $l3 = $this->getJson("/api/imobiliario/contratos/{$c['id']}/conta-corrente", $this->s)->json('dados.linhas.0');
        $this->assertSame(1050.0, $l3['juros']);                 // 35 000 × 2 % × 45/30
        $resumo = $this->getJson('/api/imobiliario/contratos/resumo', $this->s)->assertOk();
        $this->assertSame(1, $resumo->json('dados.rendas_em_atraso'));
        $this->assertSame(35000.0, $resumo->json('dados.em_divida'));
        $avisos = $this->getJson('/api/imobiliario/contratos/avisos', $this->s)->assertOk()->json('dados');
        $this->assertContains('MORA', array_column($avisos, 'tipo'));
    }

    // ---------------------------------------------------------------- pagar (Tesouraria)

    #[Test]
    public function pagar_renda_tomada_cria_um_pagamento_com_retencao_e_repetir_nao_duplica(): void
    {
        [$t] = $this->tomada(['inicio' => '2026-03-01']);
        $this->postJson("/api/imobiliario/contratos/{$t['id']}/rendas/gerar", [], $this->s)->assertOk();
        $id = $this->rendas($t['id'])[0]['id'];
        $this->postJson('/api/imobiliario/rendas/pagar', ['ids' => [$id], 'conta_financeira' => '4311'], $this->s)->assertOk()
            ->assertJsonPath('dados.falhas.0.erro', 'Aprove a renda antes de a pagar.');
        $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => [$id]], $this->s)->assertOk();
        $r = $this->postJson('/api/imobiliario/rendas/pagar', ['ids' => [$id], 'conta_financeira' => '4311', 'referencia' => 'TRF 123'], $this->s)->assertOk();
        $this->assertSame('85000.00', $r->json('dados.feitas.0.valor'));
        $this->assertSame('15000.00', $r->json('dados.feitas.0.retido'));
        $doc = $this->ctx(fn () => DocumentoTesouraria::query()->findOrFail($r->json('dados.feitas.0.documento_id')));
        $this->assertSame('PENDENTE', $doc->estado);
        $this->assertSame('PAGAMENTO', $doc->tipo);
        $this->assertSame('85000.00', (string) $doc->valor_total);
        $itens = $this->ctx(fn () => ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->orderBy('id')->get());
        $this->assertSame(['D 621 100000.00', 'C 3451 15000.00'], $itens->map(fn ($i) => "{$i->tipo_dc} {$i->codigo_conta} {$i->valor}")->all());
        $this->assertSame('PAGA', $this->rendas($t['id'])[0]['estado']);
        // repetir: o mesmo documento, nunca dois
        $again = $this->postJson('/api/imobiliario/rendas/pagar', ['ids' => [$id], 'conta_financeira' => '4311'], $this->s)->assertOk();
        $this->assertTrue($again->json('dados.feitas.0.repetida'));
        $this->assertSame(1, $this->ctx(fn () => DocumentoTesouraria::query()->count()));
        // uma renda do lado inquilino não se factura
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$id]], $this->s)->assertOk()
            ->assertJsonPath('dados.falhas.0.erro', 'Neste contrato a empresa é a inquilina: a renda paga-se na Tesouraria, não se factura.');
    }

    #[Test]
    public function desligar_o_pagamento_anula_o_documento_pendente_e_recusa_o_integrado(): void
    {
        [$t] = $this->tomada(['inicio' => '2026-03-01']);
        $this->postJson("/api/imobiliario/contratos/{$t['id']}/rendas/gerar", [], $this->s)->assertOk();
        $id = $this->rendas($t['id'])[0]['id'];
        $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => [$id]], $this->s)->assertOk();
        $pagar = fn () => $this->postJson('/api/imobiliario/rendas/pagar', ['ids' => [$id], 'conta_financeira' => '4311'], $this->s)->assertOk();
        $d1 = $pagar()->json('dados.feitas.0.documento_id');
        $this->postJson("/api/imobiliario/rendas/{$id}/desligar-pagamento", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_MOTIVO_OBRIGATORIO');
        $this->postJson("/api/imobiliario/rendas/{$id}/desligar-pagamento", ['motivo' => 'Valor errado'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'APROVADA');
        $this->assertSame('ANULADO', DB::table('documentos_tesouraria')->where('id', $d1)->value('estado'));
        $d2 = $pagar()->json('dados.feitas.0.documento_id');
        $this->assertNotSame($d1, $d2);
        DB::table('documentos_tesouraria')->where('id', $d2)->update(['estado' => 'INTEGRADO']);
        $this->postJson("/api/imobiliario/rendas/{$id}/desligar-pagamento", ['motivo' => 'Outra vez'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_DOCUMENTO_INTEGRADO');
        $this->assertSame('PAGA', $this->rendas($t['id'])[0]['estado']);
    }

    // ---------------------------------------------------------------- caução

    #[Test]
    public function caucao_com_lancamentos_equilibrados_chave_repetivel_e_anulacao_com_estorno(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['caucao' => 200000]), true);
        $url = "/api/imobiliario/contratos/{$c['id']}/caucao";
        $this->postJson($url, ['tipo' => 'RECEBIDA', 'valor' => 200000, 'conta_financeira' => '4311'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_SEM_CONTA_CAUCAO');
        $this->configurar();
        $this->postJson($url, ['tipo' => 'RECEBIDA', 'valor' => 200000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_CONTA');
        $r = $this->postJson($url, ['tipo' => 'RECEBIDA', 'valor' => 200000, 'conta_financeira' => '4311', 'chave' => 'rec-1'], $this->s)->assertCreated();
        $recebida = $r->json('dados.movimento');
        $linhas = $this->ctx(fn () => LancamentoContabil::query()->where('numero_lan', $recebida['lancamento_numero'])->orderBy('id')->get());
        $this->assertSame(['D 4311 200000.00', 'C 3711 200000.00'], $linhas->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
        $this->assertSame(200000.0, $r->json('dados.resumo.recebida'));
        $this->assertSame(200000.0, $r->json('dados.resumo.em_poder'));
        // o mesmo pedido, com a mesma chave, devolve o mesmo movimento e não lança de novo
        $again = $this->postJson($url, ['tipo' => 'RECEBIDA', 'valor' => 200000, 'conta_financeira' => '4311', 'chave' => 'rec-1'], $this->s)->assertCreated();
        $this->assertSame($recebida['id'], $again->json('dados.movimento.id'));
        $this->assertSame(1, DB::table('movimentos_caucao_imo')->where('contrato_id', $c['id'])->count());
        $this->assertSame(2, $this->ctx(fn () => LancamentoContabil::query()->where('tipo_origem', 'IMOBILIARIO')->count()));
        $this->postJson($url, ['tipo' => 'RECEBIDA', 'valor' => 100, 'conta_financeira' => '4311', 'chave' => 'rec-1'], $this->s)->assertStatus(409);
        // limites: não passa da caução acordada nem do que está em poder; reter exige motivo
        $this->postJson($url, ['tipo' => 'RECEBIDA', 'valor' => 1, 'conta_financeira' => '4311'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_EXCEDE_ACORDADA');
        $this->postJson($url, ['tipo' => 'DEVOLVIDA', 'valor' => 250000, 'conta_financeira' => '4311'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_EXCEDE_EM_PODER');
        $this->postJson($url, ['tipo' => 'RETIDA', 'valor' => 50000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_MOTIVO');
        $dev = $this->postJson($url, ['tipo' => 'DEVOLVIDA', 'valor' => 150000, 'conta_financeira' => '4311'], $this->s)->assertCreated()->json('dados.movimento');
        $ret = $this->postJson($url, ['tipo' => 'RETIDA', 'valor' => 50000, 'motivo' => 'Danos na cozinha, com vistoria'], $this->s)->assertCreated();
        $this->assertSame(0.0, $ret->json('dados.resumo.em_poder'));
        $l = $this->ctx(fn () => LancamentoContabil::query()->where('numero_lan', $ret->json('dados.movimento.lancamento_numero'))->orderBy('id')->get());
        $this->assertSame(['D 3711 50000.00', 'C 7911 50000.00'], $l->map(fn ($x) => "{$x->tipo_dc} {$x->codigo_conta} {$x->valor}")->all());
        // o recebimento não se anula enquanto há devolução ou retenção que dele dependem
        $this->postJson("/api/imobiliario/caucao/{$recebida['id']}/anular", ['motivo' => 'Engano'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_DEPENDENTE');
        $an = $this->postJson("/api/imobiliario/caucao/{$dev['id']}/anular", ['motivo' => 'Devolvida por engano'], $this->s)->assertOk();
        $this->assertSame(150000.0, $an->json('dados.resumo.em_poder'));
        $movs = collect($an->json('dados.movimentos'));
        $this->assertSame('ANULADO', $movs->firstWhere('id', $dev['id'])['estado']);
        $this->assertNotNull($movs->firstWhere('id', $dev['id'])['estorno_numero']);
        $this->postJson("/api/imobiliario/caucao/{$dev['id']}/anular", ['motivo' => 'x'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_JA_ANULADO');
        // todos os lançamentos do módulo estão equilibrados (ΣD = ΣC)
        $todos = $this->ctx(fn () => LancamentoContabil::query()->where('tipo_origem', 'IMOBILIARIO')->get());
        $this->assertSame(0, bccomp((string) $todos->where('tipo_dc', 'D')->sum('valor'), (string) $todos->where('tipo_dc', 'C')->sum('valor'), 2));
        // quem tem caução em poder é avisado ao terminar
        $t = $this->postJson("/api/imobiliario/contratos/{$c['id']}/terminar", ['data' => '2026-03-20', 'motivo' => 'Saída'], $this->s)->assertOk();
        $this->assertSame(150000.0, $t->json('dados.caucao_em_poder'));
        // o estado do contrato acompanha o ledger: depois de terminado ainda se devolve a caução
        $this->postJson($url, ['tipo' => 'DEVOLVIDA', 'valor' => 150000, 'conta_financeira' => '4311'], $this->s)->assertCreated();
        $this->assertEquals(0, $this->getJson($url, $this->s)->json('dados.resumo.em_poder'));
    }

    #[Test]
    public function caucao_do_contrato_tomado_e_um_activo_e_a_retencao_vai_a_custo(): void
    {
        [$t] = $this->tomada(['caucao' => 100000]);
        $this->configurar();
        $url = "/api/imobiliario/contratos/{$t['id']}/caucao";
        $r = $this->postJson($url, ['tipo' => 'RECEBIDA', 'valor' => 100000, 'conta_financeira' => '4311'], $this->s)->assertCreated();
        $l = $this->ctx(fn () => LancamentoContabil::query()->where('numero_lan', $r->json('dados.movimento.lancamento_numero'))->orderBy('id')->get());
        $this->assertSame(['D 1311 100000.00', 'C 4311 100000.00'], $l->map(fn ($x) => "{$x->tipo_dc} {$x->codigo_conta} {$x->valor}")->all());
        $ret = $this->postJson($url, ['tipo' => 'RETIDA', 'valor' => 20000, 'motivo' => 'Dano reclamado pelo senhorio'], $this->s)->assertCreated();
        $l2 = $this->ctx(fn () => LancamentoContabil::query()->where('numero_lan', $ret->json('dados.movimento.lancamento_numero'))->orderBy('id')->get());
        $this->assertSame(['D 621 20000.00', 'C 1311 20000.00'], $l2->map(fn ($x) => "{$x->tipo_dc} {$x->codigo_conta} {$x->valor}")->all());
    }

    // ---------------------------------------------------------------- terminar e subarrendamento

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int} o contrato tomado, o subarrendamento e a fracção */
    private function cadeia(): array
    {
        [$t, $f] = $this->tomada(['permite_sub' => true, 'fim' => '2026-12-31']);
        $sub = $this->contrato($this->corpo($f, ['tipo' => 'SUBARRENDAMENTO', 'inicio' => '2026-02-01', 'fim' => '2026-10-31', 'renda' => 150000]), true);

        return [$t, $sub, $f];
    }

    #[Test]
    public function terminar_o_contrato_tomado_exige_confirmar_a_cascata_e_acerta_as_rendas(): void
    {
        [$t, $sub, $f] = $this->cadeia();
        $this->assertSame('ARRENDADA', $this->getJson("/api/imobiliario/fracoes/{$f}", $this->s)->json('dados.estado'));
        $this->postJson("/api/imobiliario/contratos/{$sub['id']}/rendas/gerar", ['ate' => '2026-06-30'], $this->s)->assertOk();   // Fev a Jun
        $url = "/api/imobiliario/contratos/{$t['id']}/terminar";
        $this->postJson($url, ['data' => '2025-12-31', 'motivo' => 'x'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_SAIDA_ANTES_INICIO');
        $this->postJson($url, ['data' => '2026-03-10'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_MOTIVO_OBRIGATORIO');
        $this->postJson($url, ['data' => '2026-03-10', 'motivo' => 'Denúncia do senhorio'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CASCATA_NECESSARIA');
        $r = $this->postJson($url, ['data' => '2026-03-10', 'motivo' => 'Denúncia do senhorio', 'denuncia' => true, 'cascata' => true], $this->s)->assertOk();
        $this->assertSame(1, $r->json('dados.filhos_terminados'));
        $this->assertSame(3, $r->json('dados.rendas_anuladas'));   // Abril, Maio e Junho do subarrendamento
        $this->assertSame(1, $r->json('dados.rendas_acertadas'));  // Março passa a proporcional
        $this->assertSame('DENUNCIADO', $r->json('dados.contrato.estado'));
        $s = $this->getJson("/api/imobiliario/contratos/{$sub['id']}", $this->s)->json('dados');
        $this->assertSame('TERMINADO', $s['estado']);
        $this->assertSame('2026-03-10', $s['fim_real']);
        $linhas = $this->rendas($sub['id']);
        $this->assertCount(2, $linhas);                              // as anuladas saem da conta corrente
        $this->assertSame(150000.0, $linhas[0]['renda']);            // Fevereiro, inteiro
        $this->assertSame(48387.1, $linhas[1]['renda']);             // Março: 150 000 × 10/31
        $this->assertSame('DISPONIVEL', $this->getJson("/api/imobiliario/fracoes/{$f}", $this->s)->json('dados.estado'));
        $this->postJson($url, ['data' => '2026-03-10', 'motivo' => 'Outra vez'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CONTRATO_NAO_ACTIVO');
    }

    #[Test]
    public function regras_do_subarrendamento_titulo_autorizacao_e_datas(): void
    {
        $propria = $this->fracao($this->imovel('PROPRIO'), 'P1');
        $this->postJson('/api/imobiliario/contratos', $this->corpo($propria, ['tipo' => 'SUBARRENDAMENTO']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_IMOVEL_PAPEL');
        $sem = $this->fracao($this->imovel('ARRENDADO'), 'A1');
        $this->postJson('/api/imobiliario/contratos', $this->corpo($sem, ['tipo' => 'SUBARRENDAMENTO']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_SEM_TOMADA');
        [$naoAutoriza, $f1] = $this->tomada([]);
        $this->postJson('/api/imobiliario/contratos', $this->corpo($f1, ['tipo' => 'SUBARRENDAMENTO']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_PAI_SEM_AUTORIZACAO');
        [$t, $f2] = $this->tomada(['permite_sub' => true, 'fim' => '2026-12-31']);
        $sub = fn (array $o) => $this->postJson('/api/imobiliario/contratos', $this->corpo($f2, $o + ['tipo' => 'SUBARRENDAMENTO', 'inicio' => '2026-02-01', 'fim' => '2026-10-31']), $this->s);
        $sub(['inicio' => '2025-12-01'])->assertStatus(422)->assertJsonPath('codigo', 'IMO_SUB_ANTES_PAI');
        $sub(['fim' => '2027-06-30'])->assertStatus(422)->assertJsonPath('codigo', 'IMO_SUB_ALEM_PAI');
        $sub(['fim' => null])->assertStatus(422)->assertJsonPath('codigo', 'IMO_SUB_ALEM_PAI');
        $ok = $sub([])->assertCreated()->json('dados');
        $this->assertSame($t['id'], $ok['contrato_pai_id']);
        $this->assertSame('SB 2026/0001', $ok['numero']);
        // o subarrendamento só se activa com o contrato tomado activo
        [$rascunho, $f3] = $this->tomada(['permite_sub' => true], false);
        $s3 = $this->postJson('/api/imobiliario/contratos', $this->corpo($f3, ['tipo' => 'SUBARRENDAMENTO', 'inicio' => '2026-02-01']), $this->s)->assertCreated()->json('dados');
        $this->postJson("/api/imobiliario/contratos/{$s3['id']}/activar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_PAI_NAO_ACTIVO');
        $this->assertNotNull($rascunho);
    }

    #[Test]
    public function margem_por_fracao_e_cadeia_do_subarrendamento(): void
    {
        [$t, $sub, $f] = $this->cadeia();
        foreach ([$t, $sub] as $c) {
            $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", ['de' => '2026-02-01', 'ate' => '2026-04-30'], $this->s)->assertOk();
        }
        $m = $this->getJson('/api/imobiliario/subarrendamento/margem?de=2026-02&ate=2026-04', $this->s)->assertOk();
        $l = $m->json('dados.linhas.0');
        $this->assertSame($f, $l['fracao_id']);
        $this->assertSame('300000.00', $l['paga']);     // 3 × 100 000
        $this->assertSame('450000.00', $l['recebe']);   // 3 × 150 000
        $this->assertSame('150000.00', $l['margem']);
        $this->assertSame('50.0', $l['taxa']);
        $this->assertSame('101', $l['fracao_designacao']);
        $this->assertSame(['2026-02', '2026-03', '2026-04'], array_column($l['periodos'], 'periodo'));
        $this->assertSame('150000.00', $m->json('dados.totais.margem'));
        $this->assertSame(0, $m->json('dados.negativas'));
        $this->getJson('/api/imobiliario/subarrendamento/margem?de=2026-05&ate=2026-04', $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_INTERVALO_INVALIDO');
        $cad = $this->getJson("/api/imobiliario/subarrendamento/cadeia/{$f}", $this->s)->assertOk()->json('dados');
        $this->assertCount(1, $cad);
        $this->assertSame($t['numero'], $cad[0]['numero']);
        $this->assertSame($sub['numero'], $cad[0]['subarrendamentos'][0]['numero']);
        $this->assertSame(1, $cad[0]['vivos']);
    }

    // ---------------------------------------------------------------- isolamento, permissões, catálogo, base de dados

    #[Test]
    public function isolamento_por_empresa_em_contratos_rendas_e_facturacao(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['inicio' => '2026-03-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $renda = $this->rendas($c['id'])[0]['id'];
        $outra = $this->criarEmpresa(['nif' => '5417000777']);
        $h2 = $this->sessao(['imo_contratos_view', 'imo_rendas_view', 'imo_sub_view', 'imo_renda_facturar', 'imo_renda_aprovar', 'imo_renda_gerar', 'imo_caucao_movimentar', 'imo_contrato_terminar'], $outra);
        $this->getJson("/api/imobiliario/contratos/{$c['id']}", $h2)->assertNotFound();
        $this->getJson("/api/imobiliario/contratos/{$c['id']}/conta-corrente", $h2)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/imobiliario/contratos', $h2)->assertOk()->json('dados'));
        $this->assertSame([], $this->getJson('/api/imobiliario/rendas?periodo=2026-03', $h2)->assertOk()->json('dados.linhas'));
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$renda]], $h2)->assertOk()->assertJsonPath('dados.falhas.0.erro', 'Renda não encontrada.');
        $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => [$renda]], $h2)->assertOk()->assertJsonPath('dados.aprovadas', 0);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/caucao", ['tipo' => 'RECEBIDA', 'valor' => 1], $h2)->assertNotFound();
        $this->deleteJson("/api/imobiliario/contratos/{$c['id']}", [], $h2)->assertNotFound();
        $this->assertSame('PREVISTA', $this->rendas($c['id'])[0]['estado']);
    }

    #[Test]
    public function sem_permissao_recusa_com_403_antes_de_validar(): void
    {
        $leitor = $this->sessao(['imo_contratos_view', 'imo_rendas_view']);
        $this->getJson('/api/imobiliario/contratos', $leitor)->assertOk();
        $this->postJson('/api/imobiliario/contratos', [], $leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/contratos/1/activar', [], $leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/contratos/1/terminar', [], $leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/contratos/1/caucao', [], $leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/rendas/aprovar', [], $leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/rendas/facturar', [], $leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/rendas/pagar', [], $leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/rendas/gerar-tudo', [], $leitor)->assertForbidden();
        $this->putJson('/api/imobiliario/configuracao', [], $leitor)->assertForbidden();
        $this->getJson('/api/imobiliario/subarrendamento/margem', $leitor)->assertForbidden();
        $semAcesso = $this->sessao(['dashboard_view']);
        $this->getJson('/api/imobiliario/contratos', $semAcesso)->assertForbidden();
        $this->getJson('/api/imobiliario/rendas', $semAcesso)->assertForbidden();
    }

    #[Test]
    public function gerar_tudo_gera_as_rendas_em_falta_de_todos_os_contratos_activos(): void
    {
        $im = $this->imovel();
        $this->contrato($this->corpo($this->fracao($im, '101'), ['inicio' => '2026-02-01']), true);
        $this->contrato($this->corpo($this->fracao($im, '102'), ['inicio' => '2026-03-01']), true);
        $this->contrato($this->corpo($this->fracao($im, '103'), ['inicio' => '2026-03-01']));   // rascunho: ignorado
        $r = $this->postJson('/api/imobiliario/rendas/gerar-tudo', [], $this->s)->assertOk();
        $this->assertSame(3, $r->json('dados.criadas'));   // Fev e Mar do primeiro, Mar do segundo
        $this->assertSame([], $r->json('dados.problemas'));
        $this->assertSame(0, $this->postJson('/api/imobiliario/rendas/gerar-tudo', [], $this->s)->json('dados.criadas'));
    }

    #[Test]
    public function configuracao_valida_artigos_e_contas_e_nao_vem_preenchida(): void
    {
        $c = $this->getJson('/api/imobiliario/configuracao', $this->s)->assertOk();
        $this->assertNull($c->json('dados.produto_renda'));
        $this->assertNull($c->json('dados.conta_caucao_passivo'));
        $this->assertFalse($c->json('dados.completa_facturacao'));
        $this->putJson('/api/imobiliario/configuracao', ['conta_caucao_passivo' => '9999'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        $this->putJson('/api/imobiliario/configuracao', ['produto_renda_id' => 999999], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_PRODUTO_INEXISTENTE');
        $this->configurar();
        $c = $this->getJson('/api/imobiliario/configuracao', $this->s)->assertOk();
        $this->assertSame('RENDA', $c->json('dados.produto_renda.codigo'));
        $this->assertSame('3711', $c->json('dados.conta_caucao_passivo.codigo'));
        $this->assertTrue($c->json('dados.completa_caucao'));
        $this->assertNotEmpty($this->getJson('/api/imobiliario/configuracao/artigos', $this->s)->assertOk()->json('dados'));
        $this->assertNotEmpty($this->getJson('/api/imobiliario/contratos/contas', $this->s)->assertOk()->json('dados'));
    }

    #[Test]
    public function o_catalogo_separa_quem_gera_de_quem_aprova_e_os_perfis_cumprem_os_pares(): void
    {
        $pares = collect(CatalogoPermissoes::segregacao())->filter(fn ($s) => str_starts_with($s['a'], 'imo_'));
        $this->assertTrue($pares->contains(fn ($s) => $s['a'] === 'imo_renda_gerar' && $s['b'] === 'imo_renda_aprovar'));
        $modelos = collect(CatalogoPermissoes::modelos());
        $tecnico = $modelos->firstWhere('nome', 'Técnico de Rendas')['permissoes'];
        $this->assertTrue($tecnico['imo_renda_gerar']);
        $this->assertArrayNotHasKey('imo_renda_aprovar', $tecnico);
        $gestor = $modelos->firstWhere('nome', 'Gestor Imobiliário')['permissoes'];
        $this->assertTrue($gestor['imo_renda_aprovar']);
        $this->assertArrayNotHasKey('imo_renda_gerar', $gestor);
        foreach ($modelos as $mod) {
            foreach ($pares as $s) {
                $this->assertFalse(! empty($mod['permissoes'][$s['a']]) && ! empty($mod['permissoes'][$s['b']]), "{$mod['nome']} acumula {$s['a']} e {$s['b']}");
            }
        }
    }

    #[Test]
    public function restricoes_da_base_de_dados_e_idempotencia_da_migracao(): void
    {
        $f = $this->fracao($this->imovel());
        $c = $this->contrato($this->corpo($f, ['inicio' => '2026-03-01']), true);
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $linha = (array) DB::table('rendas_arrendamento')->where('contrato_id', $c['id'])->first();
        unset($linha['id']);
        try {
            DB::transaction(fn () => DB::table('rendas_arrendamento')->insert($linha));   // o mesmo contrato e período: UNIQUE
            $this->fail('A renda duplicada devia ser recusada.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('uq_rendas_arr_contrato_periodo', $e->getMessage());
        }
        foreach ([['caucao_recebida' => 5, 'caucao' => 1], ['renda' => 0]] as $mau) {
            try {
                DB::transaction(fn () => DB::table('contratos_arrendamento')->where('id', $c['id'])->update($mau));
                $this->fail('A restrição devia recusar o valor.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('ck_contratos_arr', $e->getMessage());
            }
        }
        $migracao = require base_path('database/migrations/2026_12_17_090000_imobiliario_onda2_contratos_rendas.php');
        $migracao->up();
        $migracao->up();
        $this->assertSame(1, DB::table('contratos_arrendamento')->where('id', $c['id'])->count());
    }
}
