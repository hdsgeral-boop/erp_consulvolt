<?php

namespace Tests\Feature;

use App\Models\LancamentoContabil;
use App\Models\ReciboVenda;
use App\Models\Venda;
use App\Services\Sistema\CatalogoPermissoes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FixturesImobiliarioF4;
use Tests\TestCase;

/**
 * Gestão Imobiliária, Onda 3 (F4): áreas comuns e reservas. Espaços e regulamento, verificação em directo (horário, dias, duração, antecedência,
 * conflito, contrato em vigor, limite mensal, capacidade), reserva interna e por fracção, dívida de rendas com autorização gravada, estados e
 * permissões por estado, factura sobre Vendas sem duplicar, recibo parcial e total, caução com lançamentos equilibrados, vistoria e retenção
 * facturada à parte, escala de cancelamento, nota de crédito e caducidade, calendário, isolamento por empresa e permissões. Os números são
 * calculados à mão: 2,5 h a 4 000 Kz/h = 3 unidades = 12 000; cancelar a 3 dias de 7 devolve 50 % = 6 000. A data é fixada em 2026-03-20.
 */
final class ImobiliarioReservasTest extends TestCase
{
    use FixturesImobiliarioF4;

    private int $im;

    private int $fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montar();
        $this->im = $this->imovel();
        $this->fa = $this->fracao($this->im, '101');
    }

    /** @param  array<string, mixed>  $o */
    private function espaco(array $o = []): int
    {
        return $this->postJson('/api/imobiliario/areas/espacos', $o + ['nome' => 'Salão de festas', 'imovel_id' => $this->im, 'tipo' => 'SALAO', 'regime' => 'RESERVA_PAGA', 'preco' => 4000,
            'preco_unidade' => 'HORA', 'caucao' => 20000, 'prazo_pagamento_horas' => 48, 'cancel_dias' => 7, 'cancel_pct' => 50, 'capacidade' => 50, 'limite_mes_fraccao' => 4], $this->s)
            ->assertCreated()->json('dados.id');
    }

    /** Espaço gratuito, para as regras que não dependem do dinheiro. */
    private function gratuito(array $o = []): int
    {
        return $this->espaco($o + ['nome' => 'Sala de reuniões', 'tipo' => 'REUNIOES', 'regime' => 'RESERVA_GRATUITA']);
    }

    /** @param  array<string, mixed>  $o */
    private function corpoReserva(int $espaco, array $o = []): array
    {
        return $o + ['espaco_id' => $espaco, 'fracao_id' => $this->fa, 'data' => '2026-03-27', 'hora_inicio' => '14:00', 'hora_fim' => '16:30', 'pessoas' => 20, 'motivo' => 'Aniversário'];
    }

    /** @param  array<string, mixed>  $o */
    private function reservar(int $espaco, array $o = [], ?array $h = null): TestResponse
    {
        return $this->postJson('/api/imobiliario/reservas', $this->corpoReserva($espaco, $o), $h ?? $this->s);
    }

    /** Contrato activo na fracção `$fa` (a reserva exige contrato em vigor). */
    private function comContrato(array $o = []): array
    {
        return $this->contrato($this->corpo($this->fa, $o + ['inicio' => '2026-03-01']));
    }

    private function url(int $id, string $acao = ''): string
    {
        return "/api/imobiliario/reservas/{$id}".($acao !== '' ? "/{$acao}" : '');
    }

    /** Reserva paga já confirmada: factura, recebimento da utilização e da caução. @return array{0: int, 1: int} o espaço e a reserva */
    private function confirmada(array $o = []): array
    {
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e, $o)->assertCreated()->json('dados.id');
        $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertOk();
        $this->postJson($this->url($r, 'receber'), ['conta_financeira' => '4311'], $this->s)->assertOk()->assertJsonPath('dados.reserva.estado', 'CONFIRMADA');

        return [$e, $r];
    }

    private function lanc(?string $numero): array
    {
        return $this->ctx(fn () => LancamentoContabil::query()->where('numero_lan', $numero)->orderBy('id')->get()->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor}")->all());
    }

    private function vendas(): int
    {
        return $this->ctx(fn () => Venda::query()->count());
    }

    private function viajar(string $quando): void
    {
        $this->travelTo(Carbon::parse($quando));
        $this->s = $this->sessao($this->perms);   // a sessão anterior caducou com a viagem no tempo
    }

    // ---------------------------------------------------------------- espaços

    #[Test]
    public function espaco_valida_o_regulamento_e_o_regime_define_o_que_se_cobra(): void
    {
        $e = $this->espaco(['cancel_pct' => 40, 'hora_abre' => '09:00', 'dias_semana' => [5, 6, 0, 5]]);
        $x = $this->getJson('/api/imobiliario/areas/espacos', $this->s)->assertOk()->json('dados.0');
        $this->assertSame(['09:00', '22:00', [0, 5, 6], 4000.0, 20000.0, 48, 7, 40, 0], [$x['hora_abre'], $x['hora_fecha'], $x['dias_semana'], $x['preco'], $x['caucao'], $x['prazo_pagamento_horas'], $x['cancel_dias'], $x['cancel_pct'], $x['reservas']]);
        $post = fn (array $o) => $this->postJson('/api/imobiliario/areas/espacos', $o + ['nome' => 'Outro', 'imovel_id' => $this->im], $this->s);
        $post(['nome' => ''])->assertStatus(422)->assertJsonPath('codigo', 'AREA_SEM_NOME');
        $post(['regime' => 'RESERVA_PAGA'])->assertStatus(422)->assertJsonPath('codigo', 'AREA_SEM_PRECO');
        $post(['regime' => 'RESERVA_PAGA', 'preco_unidade' => 'FIXO', 'preco' => 0])->assertStatus(422)->assertJsonPath('codigo', 'AREA_SEM_PRECO');   // o legado deixava passar o preço fixo de 0 Kz
        $post(['hora_abre' => '10:00', 'hora_fecha' => '10:00'])->assertStatus(422)->assertJsonPath('codigo', 'AREA_HORAS');
        $post(['hora_abre' => '25:00'])->assertStatus(422)->assertJsonPath('codigo', 'AREA_HORAS');
        $post(['duracao_min' => 5, 'duracao_max' => 2])->assertStatus(422)->assertJsonPath('codigo', 'AREA_DURACAO');
        $post(['antecedencia_min_dias' => 10, 'antecedencia_max_dias' => 5])->assertStatus(422)->assertJsonPath('codigo', 'AREA_ANTECEDENCIA');
        $post(['nome' => 'salão de FESTAS'])->assertStatus(422)->assertJsonPath('codigo', 'AREA_DUPLICADA');
        $post(['cancel_pct' => 150])->assertStatus(422);
        $post(['regulamento_url' => 'ftp://x.pt/r.pdf'])->assertStatus(422)->assertJsonPath('codigo', 'URL_INVALIDO');
        $post(['imovel_id' => 999999])->assertStatus(404);
        // regime gratuito: preço e caução ficam a zero mesmo que venham preenchidos
        $g = $post(['regime' => 'RESERVA_GRATUITA', 'preco' => 5000, 'caucao' => 9000])->assertCreated();
        $this->assertSame([0.0, 0.0], [$g->json('dados.preco'), $g->json('dados.caucao')]);
        // o espaço pago sem preço recusa-se também ao editar, e activar/inactivar não mexe no resto
        $this->putJson("/api/imobiliario/areas/espacos/{$e}", ['nome' => 'Salão de festas', 'preco' => 0], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'AREA_SEM_PRECO');
        $this->postJson("/api/imobiliario/areas/espacos/{$e}/activo", ['activo' => false], $this->s)->assertOk()->assertJsonPath('dados.activo', false);
        $this->assertSame(4000.0, $this->getJson('/api/imobiliario/areas/espacos?activo=0', $this->s)->assertOk()->json('dados.0.preco'));
    }

    #[Test]
    public function espaco_com_reservas_nao_muda_de_imovel_nem_se_apaga(): void
    {
        $e = $this->gratuito();
        $outro = $this->imovel('Outro prédio');
        $r = $this->reservar($e, ['fracao_id' => null])->assertCreated()->json('dados.id');
        $this->putJson("/api/imobiliario/areas/espacos/{$e}", ['nome' => 'Sala de reuniões', 'imovel_id' => $outro], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'AREA_COM_RESERVAS');
        $this->deleteJson("/api/imobiliario/areas/espacos/{$e}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'AREA_COM_RESERVAS_VIVAS');
        $this->postJson($this->url($r, 'estado'), ['estado' => 'CANCELADA', 'motivo' => 'Desistiu'], $this->s)->assertOk();
        $this->deleteJson("/api/imobiliario/areas/espacos/{$e}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'AREA_COM_HISTORICO');
        $livre = $this->espaco(['nome' => 'Ginásio', 'tipo' => 'GINASIO', 'regime' => 'GRATUITO']);
        $this->deleteJson("/api/imobiliario/areas/espacos/{$livre}", [], $this->s)->assertOk();
        // o espaço de uso livre não se marca
        $uso = $this->espaco(['nome' => 'Jardim', 'regime' => 'GRATUITO']);
        $this->reservar($uso)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_IMPEDIDA');
    }

    // ---------------------------------------------------------------- verificação e marcação

    #[Test]
    public function a_verificacao_diz_tudo_o_que_impede_a_marcacao_com_as_mensagens_do_legado(): void
    {
        $e = $this->gratuito(['dias_semana' => [1, 2, 3, 4, 5], 'hora_abre' => '08:00', 'hora_fecha' => '20:00', 'duracao_min' => 1, 'duracao_max' => 3, 'antecedencia_min_dias' => 3,
            'antecedencia_max_dias' => 30, 'capacidade' => 10]);
        $this->comContrato();
        $ver = fn (array $q) => $this->getJson('/api/imobiliario/reservas/verificar?'.http_build_query(['espaco_id' => $e, 'fracao_id' => $this->fa] + $q), $this->s)->assertOk()->json('dados.impedimentos');
        $ok = ['data' => '2026-03-27', 'hora_inicio' => '10:00', 'hora_fim' => '12:00', 'pessoas' => 5];
        $this->assertSame([], $ver($ok));
        $this->assertSame(['Sábado não é dia de funcionamento (abre Segunda, Terça, Quarta, Quinta, Sexta).'], $ver(['data' => '2026-03-28'] + $ok));
        $this->assertSame(['Fora do horário: o espaço funciona das 08:00 às 20:00.'], $ver(['hora_inicio' => '19:00', 'hora_fim' => '20:30'] + $ok));
        $this->assertSame(['A reserva mínima é de 1 h e esta tem 0.5 h.'], $ver(['hora_fim' => '10:30'] + $ok));
        $this->assertSame(['A reserva máxima é de 3 h e esta tem 4.0 h.'], $ver(['hora_fim' => '14:00'] + $ok));
        $this->assertSame(['A hora de fim tem de ser depois da de início.'], $ver(['hora_fim' => '09:00'] + $ok));
        $this->assertSame(['Indique a hora de início e de fim.'], $ver(['hora_inicio' => ''] + $ok));
        $this->assertSame(['Marca-se com pelo menos 3 dia(s) de antecedência e faltam 0.'], $ver(['data' => '2026-03-20'] + $ok));   // hoje é sexta-feira
        $this->assertSame(['Não se marca com mais de 30 dia(s) de antecedência.'], $ver(['data' => '2026-05-01'] + $ok));
        $this->assertSame(['A data já passou.'], $ver(['data' => '2026-03-19'] + $ok));
        $this->assertSame(['O espaço comporta 10 pessoas e a reserva indica 11.'], $ver(['pessoas' => 11] + $ok));
        $this->assertSame(['Indique a data.'], $ver(['data' => ''] + $ok));
        // a marcação real recusa com o mesmo texto e a lista
        $r = $this->reservar($e, ['data' => '2026-03-28', 'hora_inicio' => '10:00', 'hora_fim' => '12:00'])->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_IMPEDIDA');
        $this->assertStringContainsString('Sábado não é dia de funcionamento', $r->json('mensagem'));
        // espaço inactivo
        $this->postJson("/api/imobiliario/areas/espacos/{$e}/activo", ['activo' => false], $this->s)->assertOk();
        $this->assertContains('O espaço está inactivo.', $ver($ok));
    }

    #[Test]
    public function reserva_interna_e_por_fraccao_exige_contrato_em_vigor_a_cobrir_a_data(): void
    {
        $e = $this->gratuito();
        $interna = $this->reservar($e, ['fracao_id' => null, 'motivo' => 'Assembleia de condóminos'])->assertCreated();
        $this->assertSame([true, 'APROVADA', 0.0, 'Administração'], [$interna->json('dados.interna'), $interna->json('dados.estado'), $interna->json('dados.valor'), $interna->json('dados.reservante')]);
        $this->assertContains('Reserva interna, sem fracção: fica em nome da administração.', $this->getJson('/api/imobiliario/reservas/verificar?'.http_build_query(['espaco_id' => $e, 'data' => '2026-03-30',
            'hora_inicio' => '10:00', 'hora_fim' => '11:00']), $this->s)->json('dados.avisos'));
        // a fracção sem contrato não marca (o proprietário a usar marca como interna)
        $this->reservar($e, ['hora_inicio' => '18:00', 'hora_fim' => '19:00'])->assertStatus(422)
            ->assertJsonPath('mensagem', 'A fracção não tem contrato de arrendamento em vigor. Se é o proprietário a usar, marque como reserva interna.');
        $c = $this->comContrato(['fim' => '2026-03-25']);
        $this->reservar($e, ['hora_inicio' => '18:00', 'hora_fim' => '19:00'])->assertStatus(422)->assertJsonPath('mensagem', 'O contrato da fracção não cobre esta data.');
        $this->reservar($e, ['data' => '2026-03-24', 'hora_inicio' => '18:00', 'hora_fim' => '19:00'])->assertCreated()
            ->assertJsonPath('dados.contrato_id', $c['id'])->assertJsonPath('dados.terceiro_id', $this->cliente)->assertJsonPath('dados.reservante', 'Inquilino Fictício')->assertJsonPath('dados.fraccao.designacao', '101');
        // a fracção de outro imóvel não usa as áreas comuns deste
        $f2 = $this->fracao($this->imovel('Outro prédio'), '201');
        $this->reservar($e, ['fracao_id' => $f2, 'data' => '2026-03-24', 'hora_inicio' => '10:00', 'hora_fim' => '11:00'])->assertStatus(422)
            ->assertJsonPath('mensagem', 'A fracção é de outro imóvel: só os moradores do prédio usam as suas áreas comuns.');
        // contrato por activar (rascunho) ainda não dá direito
        $f3 = $this->fracao($this->im, '102');
        $this->contrato($this->corpo($f3), false);
        $this->reservar($e, ['fracao_id' => $f3, 'data' => '2026-03-24', 'hora_inicio' => '10:00', 'hora_fim' => '11:00'])->assertStatus(422);
    }

    #[Test]
    public function reserva_paga_calcula_o_valor_o_prazo_e_o_estado_inicial_conforme_a_aprovacao(): void
    {
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e)->assertCreated();
        // 14:00–16:30 = 2,5 h a 4 000 Kz/h: 3 unidades = 12 000, mais 20 000 de caução
        $this->assertSame(['AGUARDA_PAGAMENTO', 12000.0, '3 × hora (2.5 h)', 20000.0], [$r->json('dados.estado'), $r->json('dados.valor'), $r->json('dados.valor_detalhe'), $r->json('dados.caucao')]);
        $this->assertSame('2026-03-22 10:00', Carbon::parse($r->json('dados.prazo_pagamento'))->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));   // 48 h depois de marcar
        $this->assertSame([12000.0, false, true], [$r->json('dados.situacao.valor'), $r->json('dados.situacao.pago'), $r->json('dados.situacao.paga')]);
        // aprovar uma reserva paga leva a «a aguardar pagamento», não a «aprovada» (o legado saltava este passo)
        $ap = $this->espaco(['nome' => 'Salão nobre', 'exige_aprovacao' => true]);
        $p = $this->reservar($ap, ['data' => '2026-03-31'])->assertCreated();
        $this->assertSame(['PEDIDA', null], [$p->json('dados.estado'), $p->json('dados.prazo_pagamento')]);
        $a = $this->postJson($this->url($p->json('dados.id'), 'estado'), ['estado' => 'APROVADA'], $this->s)->assertOk();
        $this->assertSame('AGUARDA_PAGAMENTO', $a->json('dados.estado'));
        $this->assertNotNull($a->json('dados.prazo_pagamento'));
        $this->assertSame(['PEDIDA', 'AGUARDA_PAGAMENTO'], [$p->json('dados.estado'), $a->json('dados.estado')]);
        // gratuita com aprovação vai a «aprovada»
        $g = $this->gratuito(['exige_aprovacao' => true]);
        $x = $this->reservar($g, ['data' => '2026-03-31'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($x, 'estado'), ['estado' => 'APROVADA'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'APROVADA')->assertJsonPath('dados.prazo_pagamento', null);
    }

    #[Test]
    public function conflitos_de_horario_sao_semiabertos_e_a_edicao_nao_conflita_consigo_propria(): void
    {
        $e = $this->gratuito();
        $this->comContrato();
        $a = $this->reservar($e, ['hora_inicio' => '10:00', 'hora_fim' => '12:00'])->assertCreated()->json('dados.id');
        $this->reservar($e, ['hora_inicio' => '11:00', 'hora_fim' => '13:00'])->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_IMPEDIDA')
            ->assertJsonPath('erros.impedimentos.0', 'Já há reserva das 10:00 às 12:00 (Aprovada).');
        $c = $this->reservar($e, ['hora_inicio' => '12:00', 'hora_fim' => '14:00'])->assertCreated()->json('dados.id');   // encostar não conflita
        // o legado contava a própria reserva ao editar: agora não
        $this->putJson($this->url($a), $this->corpoReserva($e, ['hora_inicio' => '10:30', 'hora_fim' => '12:00']), $this->s)->assertOk()->assertJsonPath('dados.hora_inicio', '10:30');
        $this->putJson($this->url($a), $this->corpoReserva($e, ['hora_inicio' => '10:30', 'hora_fim' => '12:30']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_IMPEDIDA');
        $v = $this->getJson('/api/imobiliario/reservas/verificar?'.http_build_query(['espaco_id' => $e, 'data' => '2026-03-27', 'hora_inicio' => '10:30', 'hora_fim' => '12:00', 'excepto' => $a]), $this->s)->json('dados');
        $this->assertSame([], $v['impedimentos']);
        $semExcepto = $this->getJson('/api/imobiliario/reservas/verificar?'.http_build_query(['espaco_id' => $e, 'data' => '2026-03-27', 'hora_inicio' => '10:30', 'hora_fim' => '12:00']), $this->s)->json('dados.impedimentos');
        $this->assertCount(1, $semExcepto);
        // outro dia ou outro espaço não conflitam; a reserva cancelada liberta o horário
        $this->reservar($e, ['data' => '2026-03-28', 'hora_inicio' => '10:00', 'hora_fim' => '12:00'])->assertCreated();
        $this->postJson($this->url($c, 'estado'), ['estado' => 'CANCELADA', 'motivo' => 'Desistiu'], $this->s)->assertOk();
        $this->reservar($e, ['hora_inicio' => '12:30', 'hora_fim' => '14:00'])->assertCreated();
        // duas marcações iguais em simultâneo: a segunda recebe sempre o conflito (o espaço é bloqueado ao gravar)
        $this->reservar($e, ['hora_inicio' => '18:00', 'hora_fim' => '19:00'])->assertCreated();
        $this->reservar($e, ['hora_inicio' => '18:00', 'hora_fim' => '19:00'])->assertStatus(422);
        $this->assertSame(1, DB::table('reservas_espaco')->where('hora_inicio', '18:00')->count());
    }

    #[Test]
    public function limite_mensal_por_fraccao_conta_as_reservas_que_ocupam_e_as_faltas(): void
    {
        $e = $this->gratuito(['limite_mes_fraccao' => 2]);
        $this->comContrato();
        $f2 = $this->fracao($this->im, '102');
        $this->contrato($this->corpo($f2, ['terceiro_id' => $this->cliente2, 'inicio' => '2026-03-01']));
        $a = $this->reservar($e, ['data' => '2026-03-23'])->assertCreated()->json('dados.id');
        $v = $this->getJson('/api/imobiliario/reservas/verificar?'.http_build_query(['espaco_id' => $e, 'fracao_id' => $this->fa, 'data' => '2026-03-24', 'hora_inicio' => '10:00', 'hora_fim' => '11:00']), $this->s)->json('dados');
        $this->assertSame(['Com esta, a fracção esgota as 2 reservas que tem direito este mês.'], $v['avisos']);
        $b = $this->reservar($e, ['data' => '2026-03-24'])->assertCreated()->json('dados.id');
        $this->reservar($e, ['data' => '2026-03-25'])->assertStatus(422)->assertJsonPath('mensagem', 'A fracção já usou as 2 reservas deste mês (2026-03-23, 2026-03-24).');
        $this->reservar($e, ['data' => '2026-03-25', 'fracao_id' => $f2])->assertCreated();   // outra fracção tem o seu limite
        $this->reservar($e, ['data' => '2026-04-02'])->assertCreated();                      // outro mês também
        // cancelar liberta o direito; faltar não
        $this->postJson($this->url($a, 'estado'), ['estado' => 'CANCELADA', 'motivo' => 'x'], $this->s)->assertOk();
        $this->reservar($e, ['data' => '2026-03-26'])->assertCreated();
        $this->viajar('2026-03-25 10:00:00');
        $this->postJson($this->url($b, 'estado'), ['estado' => 'FALTOU'], $this->s)->assertOk();
        $this->reservar($e, ['data' => '2026-03-31'])->assertStatus(422);   // 24 (faltou) + 26 já são as 2 do mês
        // limite 1: mensagem no singular
        $u = $this->gratuito(['nome' => 'Terraço', 'limite_mes_fraccao' => 1]);
        $this->reservar($u, ['data' => '2026-03-31'])->assertCreated();
        $this->reservar($u, ['data' => '2026-03-30', 'hora_inicio' => '10:00', 'hora_fim' => '11:00'])->assertStatus(422)->assertJsonPath('mensagem', 'A fracção já marcou este mês (2026-03-31) e só pode uma vez por mês.');
    }

    #[Test]
    public function fraccao_com_rendas_em_divida_so_marca_com_autorizacao_de_quem_assume_gravada_com_o_nome(): void
    {
        $this->configurar();
        $e = $this->gratuito();
        $c = $this->comContrato();
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();
        $renda = $this->getJson("/api/imobiliario/contratos/{$c['id']}/conta-corrente", $this->s)->json('dados.linhas.0.id');
        $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => [$renda]], $this->s)->assertOk();
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$renda]], $this->s)->assertOk()->assertJsonPath('dados.feitas.0.repetida', false);
        $v = $this->getJson('/api/imobiliario/reservas/verificar?'.http_build_query(['espaco_id' => $e, 'fracao_id' => $this->fa, 'data' => '2026-03-27', 'hora_inicio' => '10:00', 'hora_fim' => '11:00']), $this->s)->json('dados');
        $this->assertSame([true, 85000.0], [$v['exige_autorizacao'], $v['divida']]);   // 100 000 − 15 000 de Imposto Predial retido
        $this->assertSame(['A fracção tem 85 000,00 Kz de rendas em dívida.'], array_map(fn ($x) => str_replace("\u{202f}", ' ', str_replace("\u{a0}", ' ', $x)), $v['avisos']));
        $this->reservar($e)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_DEVEDOR');
        // sem a permissão de autorizar, mesmo com motivo: recusado
        $semPerm = $this->sessao(array_values(array_diff($this->perms, ['imo_reserva_devedor'])));
        $this->reservar($e, ['autorizacao_motivo' => 'Cliente antigo'], $semPerm)->assertStatus(403)->assertJsonPath('codigo', 'RESERVA_DEVEDOR_SEM_PERMISSAO');
        $r = $this->reservar($e, ['autorizacao_motivo' => 'Cliente antigo, paga na sexta'])->assertCreated();
        $this->assertSame([85000.0, 'Cliente antigo, paga na sexta'], [$r->json('dados.divida_na_marcacao'), $r->json('dados.autorizacao_motivo')]);
        $this->assertNotNull($r->json('dados.autorizacao_por'));
        $this->assertNotNull($r->json('dados.autorizacao_em'));
        $this->assertSame(1, $this->getJson('/api/imobiliario/areas/resumo', $this->s)->json('dados.com_divida'));
        $this->assertContains('1 reserva(s) foram autorizadas com rendas em dívida.', $this->getJson('/api/imobiliario/areas/avisos', $this->s)->json('dados'));
        // quem não deve nada não precisa de autorização
        $f2 = $this->fracao($this->im, '102');
        $this->contrato($this->corpo($f2, ['terceiro_id' => $this->cliente2, 'inicio' => '2026-03-01']));
        $this->reservar($e, ['fracao_id' => $f2, 'hora_inicio' => '12:00', 'hora_fim' => '13:00'])->assertCreated()->assertJsonPath('dados.divida_na_marcacao', 0.0)->assertJsonPath('dados.autorizacao_motivo', null);
    }

    // ---------------------------------------------------------------- estados

    #[Test]
    public function estados_seguem_as_transicoes_com_motivo_datas_e_permissao_por_estado(): void
    {
        $e = $this->gratuito(['exige_aprovacao' => true]);
        $this->comContrato();
        $a = $this->reservar($e)->assertCreated()->json('dados.id');
        $est = fn (int $id, string $estado, ?string $motivo = null, ?array $h = null) => $this->postJson($this->url($id, 'estado'), ['estado' => $estado, 'motivo' => $motivo], $h ?? $this->s);
        $est($a, 'RECUSADA')->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_MOTIVO')->assertJsonPath('mensagem', 'Diga o motivo: quem marcou vai perguntar porquê.');
        $est($a, 'REALIZADA')->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_TRANSICAO')->assertJsonPath('mensagem', 'Uma reserva «pedida» não passa a «realizada».');
        $est($a, 'CONFIRMADA')->assertStatus(422);
        $est($a, 'APROVADA')->assertOk()->assertJsonPath('dados.estado', 'APROVADA');
        $est($a, 'REALIZADA')->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_FUTURA')->assertJsonPath('mensagem', 'A reserva ainda não aconteceu.');
        $est($a, 'FALTOU')->assertStatus(422)->assertJsonPath('mensagem', 'Ainda não se pode dizer que não compareceu: a data não chegou.');
        // permissões por estado: aprovar e recusar, realizada e faltou, cancelar são três permissões diferentes
        $soMarcar = $this->sessao(['imo_areas_view', 'imo_reserva_marcar']);
        $b = $this->reservar($e, ['data' => '2026-03-28'])->assertCreated()->json('dados.id');
        $est($b, 'APROVADA', null, $soMarcar)->assertForbidden();
        $est($b, 'CANCELADA', 'x', $soMarcar)->assertForbidden();
        $soAprovar = $this->sessao(['imo_areas_view', 'imo_reserva_aprovar']);
        $est($b, 'RECUSADA', 'Sem vaga', $soAprovar)->assertOk()->assertJsonPath('dados.estado', 'RECUSADA');
        $est($b, 'APROVADA', null, $soAprovar)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_TRANSICAO');   // recusada é final
        $this->assertSame(['Sem vaga'], [$this->getJson($this->url($b), $this->s)->json('dados.motivo_estado')]);
        // depois da data a reserva fecha: realizada, e já não se altera nem se apaga
        $this->viajar('2026-03-28 09:00:00');
        $est($a, 'REALIZADA')->assertOk()->assertJsonPath('dados.estado', 'REALIZADA');
        $this->assertNotNull($this->getJson($this->url($a), $this->s)->json('dados.realizada_em'));
        $this->putJson($this->url($a), $this->corpoReserva($e, ['data' => '2026-03-29']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_NAO_VIVA');
        $this->deleteJson($this->url($a), [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_NAO_VIVA');
        $est($a, 'CANCELADA', 'x')->assertStatus(422);
        // a realizada continua a ocupar o horário; a recusada não
        $this->assertContains('REALIZADA', array_column($this->getJson('/api/imobiliario/areas/espacos/'.$e.'/dia?data=2026-03-27', $this->s)->json('dados.ocupadas'), 'estado'));
        $h = $this->getJson($this->url($a), $this->s)->json('dados.historico');
        $this->assertSame(['CRIADA', 'ESTADO', 'ESTADO'], array_column($h, 'evento'));
        $this->assertSame(['PEDIDA', 'APROVADA'], [$h[1]['estado_de'], $h[1]['estado_para']]);
    }

    #[Test]
    public function editar_e_apagar_so_antes_de_haver_documento(): void
    {
        $this->configurar();
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e)->assertCreated()->json('dados.id');
        // antes de facturar edita-se e o preço e o prazo recalculam-se
        $x = $this->putJson($this->url($r), $this->corpoReserva($e, ['hora_inicio' => '14:00', 'hora_fim' => '15:00']), $this->s)->assertOk();
        $this->assertSame([4000.0, '1 × hora (1 h)'], [$x->json('dados.valor'), $x->json('dados.valor_detalhe')]);
        $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertOk();
        $this->putJson($this->url($r), $this->corpoReserva($e, ['hora_fim' => '17:00']), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_FACTURADA');
        $this->deleteJson($this->url($r), [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_FACTURADA');
        $apagavel = $this->reservar($e, ['data' => '2026-03-30'])->assertCreated()->json('dados.id');
        $this->deleteJson($this->url($apagavel), [], $this->s)->assertOk();
        $this->assertSame(0, DB::table('historico_reserva_imo')->where('reserva_id', $apagavel)->count());
        $this->getJson($this->url($apagavel), $this->s)->assertNotFound();
    }

    // ---------------------------------------------------------------- financeiro

    #[Test]
    public function facturar_emite_uma_so_factura_e_repetir_nao_duplica(): void
    {
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e)->assertCreated()->json('dados.id');
        $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_SEM_ARTIGO_RESERVA');
        $this->putJson('/api/imobiliario/configuracao', ['produto_reserva_id' => $this->artigoComIva], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_PRODUTO_IVA');
        $this->configurar();
        $f = $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertOk();
        $this->assertFalse($f->json('dados.repetida'));
        $venda = $this->ctx(fn () => Venda::query()->get());
        $this->assertCount(1, $venda);
        $this->assertSame(['FT', '12000.00', $this->cliente], [$venda[0]->tipo_documento, (string) $venda[0]->total_bruto, $venda[0]->cliente_id]);
        $this->assertSame($venda[0]->id, $f->json('dados.reserva.venda_id'));
        $again = $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertOk();
        $this->assertTrue($again->json('dados.repetida'));
        $this->assertSame($f->json('dados.numero_documento'), $again->json('dados.numero_documento'));
        $this->assertSame(1, $this->vendas());
        $this->assertSame(1, DB::table('reservas_espaco')->whereNotNull('venda_id')->count());
        // regras: pedida, interna, gratuita
        $ap = $this->espaco(['nome' => 'Salão nobre', 'exige_aprovacao' => true]);
        $p = $this->reservar($ap, ['data' => '2026-03-31'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($p, 'facturar'), [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_NAO_APROVADA');
        $i = $this->reservar($e, ['fracao_id' => null, 'data' => '2026-03-30'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($i, 'facturar'), [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_INTERNA');
        $g = $this->reservar($this->gratuito(), ['data' => '2026-03-30'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($g, 'facturar'), [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_NAO_PAGA');
        $this->assertSame(1, $this->vendas());
    }

    #[Test]
    public function recebimento_parcial_e_total_confirma_a_reserva_e_a_caucao_tem_lancamentos_equilibrados(): void
    {
        $this->configurar();
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e)->assertCreated()->json('dados.id');
        $rec = fn (array $o) => $this->postJson($this->url($r, 'receber'), $o, $this->s);
        $rec(['valor' => 1000, 'caucao' => 0, 'conta_financeira' => '4311'])->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_SEM_FACTURA');   // a utilização recebe-se depois de facturada
        $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertOk();
        $rec(['valor' => 5000])->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_SEM_CONTA');
        // parcial: 5 000 da utilização, sem caução ainda
        $p = $rec(['valor' => 5000, 'caucao' => 0, 'conta_financeira' => '4311'])->assertOk()->assertJsonPath('dados.reserva.estado', 'AGUARDA_PAGAMENTO');
        $this->assertSame([12000.0, 5000.0, 7000.0, false], [$p->json('dados.reserva.situacao.facturado'), $p->json('dados.reserva.situacao.recebido'), $p->json('dados.reserva.situacao.por_pagar'), $p->json('dados.reserva.situacao.pago')]);
        $this->assertSame(1, $this->ctx(fn () => ReciboVenda::query()->count()));
        $rec(['valor' => 8000, 'caucao' => 0, 'conta_financeira' => '4311'])->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_EXCEDE');
        // o resto da utilização (por omissão) mais a caução (por omissão): confirma
        $t = $rec(['conta_financeira' => '4311', 'chave' => 'c1'])->assertOk();
        $this->assertSame(['CONFIRMADA', 7000.0, 20000.0], [$t->json('dados.reserva.estado'), (float) $t->json('dados.valor'), (float) $t->json('dados.caucao')]);
        $s = $t->json('dados.reserva.situacao');
        $this->assertSame([12000.0, 0.0, true, 'EM_PODER', 20000.0], [$s['recebido'], $s['por_pagar'], $s['pago'], $s['caucao']['estado'], $s['caucao']['em_poder']]);
        $this->assertSame(2, $this->ctx(fn () => ReciboVenda::query()->count()));
        $this->assertSame('PAGO', $this->ctx(fn () => Venda::query()->first()->estado));
        $mov = $this->getJson($this->url($r, 'caucao'), $this->s)->assertOk()->json('dados.movimentos');
        $this->assertSame(['D 4311 20000.00', 'C 3711 20000.00'], $this->lanc($mov[0]['lancamento_numero']));
        $rec(['conta_financeira' => '4311'])->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_NADA_A_RECEBER');
        // a caução nunca passa da devida
        $this->postJson($this->url($r, 'caucao'), ['tipo' => 'RECEBIDA', 'valor' => 1, 'conta_financeira' => '4311'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_EXCEDE_ACORDADA');
        $this->assertSame(['CRIADA', 'FACTURADA', 'RECEBIDO', 'RECEBIDO', 'ESTADO'], array_column($this->getJson($this->url($r), $this->s)->json('dados.historico'), 'evento'));
    }

    #[Test]
    public function vistoria_de_saida_e_a_prova_da_retencao_que_se_factura_a_parte_e_o_resto_devolve_se(): void
    {
        $this->configurar();
        [, $r] = $this->confirmada();
        $url = $this->url($r, 'caucao');
        $this->assertSame(1, $this->vendas());
        $this->postJson($url, ['tipo' => 'RETIDA', 'valor' => 5000, 'motivo' => 'Mesa partida'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_SEM_VISTORIA');
        $this->postJson($this->url($r, 'vistoria'), ['quando' => 'ANTES', 'estado' => 'BOM'], $this->s)->assertCreated();
        $this->postJson($url, ['tipo' => 'RETIDA', 'valor' => 5000, 'motivo' => 'Mesa partida'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_SEM_VISTORIA');   // a de entrada não prova danos
        $this->postJson($this->url($r, 'vistoria'), ['quando' => 'DEPOIS', 'estado' => 'DANIFICADO', 'observacoes' => 'Mesa partida', 'url' => 'https://exemplo.ao/fotos/1', 'custo_danos' => 5000], $this->s)->assertCreated();
        $this->postJson($this->url($r, 'vistoria'), ['quando' => 'DEPOIS', 'url' => 'javascript:alert(1)'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'URL_INVALIDO');
        $this->postJson($url, ['tipo' => 'RETIDA', 'valor' => 5000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_MOTIVO');
        $this->postJson($url, ['tipo' => 'RETIDA', 'valor' => 25000, 'motivo' => 'Tudo'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_EXCEDE_EM_PODER');
        $ret = $this->postJson($url, ['tipo' => 'RETIDA', 'valor' => 5000, 'motivo' => 'Mesa partida', 'chave' => 'ret-1'], $this->s)->assertCreated();
        $this->assertSame(2, $this->vendas());   // a factura da retenção, à parte
        $m = $ret->json('dados.movimento');
        $this->assertNotNull($m['venda_id']);
        $retida = $this->ctx(fn () => Venda::query()->find($m['venda_id']));
        $this->assertSame(['FT', '5000.00', 'PAGO', '0.00'], [$retida->tipo_documento, (string) $retida->total_bruto, $retida->estado, (string) $retida->valor_pendente]);
        $this->assertSame(['D 3711 5000.00', 'C 311 5000.00'], $this->lanc($m['lancamento_numero']));   // a caução compensa a factura: só esta parte é proveito
        // o mesmo pedido, com a mesma chave, não factura nem lança de novo
        $again = $this->postJson($url, ['tipo' => 'RETIDA', 'valor' => 5000, 'motivo' => 'Mesa partida', 'chave' => 'ret-1'], $this->s)->assertCreated();
        $this->assertSame($m['id'], $again->json('dados.movimento.id'));
        $this->assertSame(2, $this->vendas());
        $this->assertSame(1, DB::table('movimentos_caucao_reserva')->where('tipo', 'RETIDA')->count());
        // devolve-se o resto
        $dev = $this->postJson($url, ['tipo' => 'DEVOLVIDA', 'valor' => 15000, 'conta_financeira' => '4311'], $this->s)->assertCreated();
        $this->assertSame(['D 3711 15000.00', 'C 4311 15000.00'], $this->lanc($dev->json('dados.movimento.lancamento_numero')));
        $res = $this->getJson($url, $this->s)->json('dados.resumo');
        $this->assertSame([20000.0, 15000.0, 5000.0, 0.0, 'RETIDA_PARCIAL'], [$res['recebida'], $res['devolvida'], $res['retida'], $res['em_poder'], $res['estado']]);
        $this->postJson($url, ['tipo' => 'DEVOLVIDA', 'valor' => 1, 'conta_financeira' => '4311'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_EXCEDE_EM_PODER');
        // a retenção é definitiva (corrige-se com nota de crédito em Vendas); a devolução anula-se com estorno
        $this->postJson("/api/imobiliario/reservas/caucao/{$m['id']}/anular", ['motivo' => 'Engano'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_RETIDA_DEFINITIVA');
        $an = $this->postJson('/api/imobiliario/reservas/caucao/'.$dev->json('dados.movimento.id').'/anular', ['motivo' => 'Devolvida por engano'], $this->s)->assertOk();
        $this->assertNotNull($an->json('dados.estorno_numero'));
        $this->assertSame(15000.0, $this->getJson($url, $this->s)->json('dados.resumo.em_poder'));
        $this->postJson('/api/imobiliario/reservas/caucao/'.$dev->json('dados.movimento.id').'/anular', ['motivo' => 'x'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_JA_ANULADO');
        // o diário do módulo está equilibrado (ΣD = ΣC)
        $todos = $this->ctx(fn () => LancamentoContabil::query()->where('tipo_origem', 'IMOBILIARIO')->get());
        $this->assertGreaterThan(0, $todos->count());
        $this->assertSame(0, bccomp((string) $todos->where('tipo_dc', 'D')->sum('valor'), (string) $todos->where('tipo_dc', 'C')->sum('valor'), 2));
        // sem configuração da caução não se movimenta
        $this->assertSame(1, DB::table('vistorias_reserva')->where('reserva_id', $r)->where('quando', 'ANTES')->count());
    }

    #[Test]
    public function a_caucao_exige_a_conta_do_passivo_e_a_reserva_interna_nao_tem_retencao(): void
    {
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e)->assertCreated()->json('dados.id');
        $this->postJson($this->url($r, 'caucao'), ['tipo' => 'RECEBIDA', 'valor' => 1000, 'conta_financeira' => '4311'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_SEM_CONTA_CAUCAO');
        $this->configurar();
        $this->postJson($this->url($r, 'caucao'), ['tipo' => 'RECEBIDA', 'valor' => 1000], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_CAUCAO_CONTA');
        $this->postJson($this->url($r, 'caucao'), ['tipo' => 'RECEBIDA', 'valor' => 0, 'conta_financeira' => '4311'], $this->s)->assertStatus(422);
        $this->postJson($this->url($r, 'caucao'), ['tipo' => 'RECEBIDA', 'valor' => 1000, 'conta_financeira' => '9999'], $this->s)->assertStatus(422);
        $x = $this->postJson($this->url($r, 'caucao'), ['tipo' => 'RECEBIDA', 'valor' => 1000, 'conta_financeira' => '4311', 'chave' => 'k1'], $this->s)->assertCreated();
        $this->postJson($this->url($r, 'caucao'), ['tipo' => 'RECEBIDA', 'valor' => 2000, 'conta_financeira' => '4311', 'chave' => 'k1'], $this->s)->assertStatus(409);
        $this->assertSame(1, DB::table('movimentos_caucao_reserva')->count());
        $this->assertSame(1000.0, $this->getJson($this->url($r), $this->s)->json('dados.caucao_recebida'));
        $this->assertSame('EM_PODER', $x->json('dados.reserva.situacao.caucao.estado'));
    }

    // ---------------------------------------------------------------- cancelar

    #[Test]
    public function previsao_do_cancelamento_segue_a_escala_do_espaco(): void
    {
        $this->configurar();
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e, ['data' => '2026-03-23'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertOk();
        $this->postJson($this->url($r, 'receber'), ['conta_financeira' => '4311', 'caucao' => 0], $this->s)->assertOk()->assertJsonPath('dados.reserva.estado', 'AGUARDA_PAGAMENTO');   // sem a caução ainda não confirma
        // faltam 3 dias e o espaço devolve tudo até 7 dias antes; depois, 50 %: devolve 6 000 dos 12 000
        $p = $this->getJson($this->url($r, 'previsao-cancelamento'), $this->s)->assertOk()->json('dados');
        $this->assertSame([3, 12000.0, 50, 6000.0, 6000.0], [$p['faltam_dias'], $p['recebido'], $p['pct'], $p['devolve'], $p['retem']]);
        $this->assertSame('Faltam só 3 dia(s): dentro dos 7 dia(s) do regulamento devolve-se 50%.', $p['explicacao']);
        $longe = $this->getJson($this->url($r, 'previsao-cancelamento').'?quando=2026-03-16', $this->s)->json('dados');   // 7 dias antes
        $this->assertSame([7, 100, 12000.0, 0.0], [$longe['faltam_dias'], $longe['pct'], $longe['devolve'], $longe['retem']]);
        $this->assertSame('Faltam 7 dia(s) e o espaço devolve tudo até 7 dia(s) antes.', $longe['explicacao']);
    }

    #[Test]
    public function cancelar_reserva_paga_regista_o_reembolso_e_deixa_a_nota_de_credito_pendente(): void
    {
        $this->configurar();
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e, ['data' => '2026-03-23'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertOk();
        $this->postJson($this->url($r, 'receber'), ['conta_financeira' => '4311'], $this->s)->assertOk()->assertJsonPath('dados.reserva.estado', 'CONFIRMADA');
        $c = fn (array $o) => $this->postJson($this->url($r, 'cancelar'), $o, $this->s);
        $c([])->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_MOTIVO');
        $c(['motivo' => 'Desistiu', 'devolver' => 99999])->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_REEMBOLSO');
        $x = $c(['motivo' => 'Desistiu à última hora'])->assertOk();
        $this->assertSame(6000.0, $x->json('dados.previsao.devolve_aplicado'));
        $res = $x->json('dados.reserva');
        $this->assertSame(['CANCELADA', 6000.0, 6000.0, 50, true, null], [$res['estado'], $res['reembolso_previsto'], $res['reembolso_retido'], $res['reembolso_pct'], $res['nc_pendente'], $res['nota_credito_id']]);
        $this->assertSame(1, $this->vendas());   // paga: a nota de crédito exige anular primeiro o recibo (regra de Vendas)
        // a caução continua em poder, por devolver (aviso), e a reserva cancelada não se cancela duas vezes
        $c(['motivo' => 'x'])->assertStatus(422)->assertJsonPath('codigo', 'RESERVA_NAO_VIVA');
        $av = $this->getJson('/api/imobiliario/reservas/avisos', $this->s)->assertOk()->json('dados');
        $this->assertContains('1 reserva(s) canceladas com reembolso por tratar: anule o recibo e emita a nota de crédito em Vendas.', $av);
        $this->assertContains('1 reserva(s) terminadas com a caução por devolver.', $av);
        $this->assertSame(20000.0, $this->getJson('/api/imobiliario/reservas/contas', $this->s)->json('dados.caucoes_em_poder'));
        $this->postJson($this->url($r, 'caucao'), ['tipo' => 'DEVOLVIDA', 'valor' => 20000, 'conta_financeira' => '4311'], $this->s)->assertCreated();   // depois de cancelada ainda se devolve
        // devolver um valor escolhido
        $r2 = $this->reservar($e, ['data' => '2026-03-25', 'hora_inicio' => '10:00', 'hora_fim' => '11:00'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($r2, 'facturar'), [], $this->s)->assertOk();
        $this->postJson($this->url($r2, 'receber'), ['conta_financeira' => '4311', 'caucao' => 0], $this->s)->assertOk();
        $y = $this->postJson($this->url($r2, 'cancelar'), ['motivo' => 'Doença', 'devolver' => 4000], $this->s)->assertOk()->json('dados.reserva');
        $this->assertSame([4000.0, 0.0], [$y['reembolso_previsto'], $y['reembolso_retido']]);   // recebeu 4 000 (1 h): devolve tudo
    }

    #[Test]
    public function cancelar_factura_por_pagar_emite_a_nota_de_credito_uma_so_vez_e_liberta_o_horario(): void
    {
        $this->configurar();
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e)->assertCreated()->json('dados.id');
        $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertOk();
        $this->assertSame(1, $this->vendas());
        // o cancelamento pelo estado passa pela mesma regra (e pela permissão própria)
        $semCancelar = $this->sessao(array_values(array_diff($this->perms, ['imo_reserva_cancelar'])));
        $this->postJson($this->url($r, 'estado'), ['estado' => 'CANCELADA', 'motivo' => 'x'], $semCancelar)->assertForbidden();
        $this->postJson($this->url($r, 'cancelar'), ['motivo' => 'x'], $semCancelar)->assertForbidden();
        $x = $this->postJson($this->url($r, 'estado'), ['estado' => 'CANCELADA', 'motivo' => 'Mudou de ideias'], $this->s)->assertOk();
        $this->assertSame(['CANCELADA', false], [$x->json('dados.estado'), $x->json('dados.nc_pendente')]);
        $this->assertNotNull($x->json('dados.nota_credito_id'));
        $this->assertSame(2, $this->vendas());
        $nc = $this->ctx(fn () => Venda::query()->find($x->json('dados.nota_credito_id')));
        $this->assertSame(['NC', '12000.00'], [$nc->tipo_documento, (string) $nc->total_bruto]);
        $this->assertSame('0.00', (string) $this->ctx(fn () => Venda::query()->where('tipo_documento', 'FT')->first()->valor_pendente));
        $this->postJson($this->url($r, 'cancelar'), ['motivo' => 'x'], $this->s)->assertStatus(422);
        $this->assertSame(2, $this->vendas());
        $this->reservar($e)->assertCreated();   // o horário ficou livre
        // sem factura: cancela-se sem nota de crédito
        $r2 = $this->reservar($e, ['data' => '2026-03-30'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($r2, 'cancelar'), ['motivo' => 'Sem factura'], $this->s)->assertOk()->assertJsonPath('dados.reserva.nota_credito_id', null);
        $this->assertSame(2, $this->vendas());
    }

    // ---------------------------------------------------------------- caducidade

    #[Test]
    public function caducidade_cancela_o_que_nao_recebeu_nada_com_nota_de_credito_e_nao_cancela_pagamentos_parciais(): void
    {
        $this->configurar();
        $e = $this->espaco();
        $this->comContrato();
        $f2 = $this->fracao($this->im, '102');
        $this->contrato($this->corpo($f2, ['terceiro_id' => $this->cliente2, 'inicio' => '2026-03-01']));
        $semFactura = $this->reservar($e, ['data' => '2026-03-27', 'hora_inicio' => '08:00', 'hora_fim' => '09:00'])->assertCreated()->json('dados.id');
        $facturada = $this->reservar($e, ['data' => '2026-03-27', 'hora_inicio' => '10:00', 'hora_fim' => '12:00'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($facturada, 'facturar'), [], $this->s)->assertOk();
        $parcial = $this->reservar($e, ['fracao_id' => $f2, 'data' => '2026-03-27', 'hora_inicio' => '13:00', 'hora_fim' => '15:00'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($parcial, 'facturar'), [], $this->s)->assertOk();
        $this->postJson($this->url($parcial, 'receber'), ['valor' => 3000, 'caucao' => 0, 'conta_financeira' => '4311'], $this->s)->assertOk();
        $paga = $this->reservar($e, ['fracao_id' => $f2, 'data' => '2026-03-27', 'hora_inicio' => '16:00', 'hora_fim' => '17:00'])->assertCreated()->json('dados.id');
        $this->postJson($this->url($paga, 'facturar'), [], $this->s)->assertOk();
        $this->postJson($this->url($paga, 'receber'), ['conta_financeira' => '4311', 'caucao' => 0], $this->s)->assertOk();   // tudo pago, falta a caução
        $this->assertSame(3, $this->vendas());
        // antes do prazo nada acontece
        $this->postJson('/api/imobiliario/reservas/caducar', [], $this->s)->assertOk()->assertJsonPath('dados.caducadas', 0);
        // 48 h depois (22/03 10:00): o prazo esgotou-se
        $this->viajar('2026-03-22 11:00:00');
        $r = $this->postJson('/api/imobiliario/reservas/caducar', [], $this->s)->assertOk();
        $this->assertSame([2, 0, 2, 0], [$r->json('dados.caducadas'), $r->json('dados.confirmadas'), $r->json('dados.parciais'), $r->json('dados.falhas')]);
        $est = fn (int $id) => $this->getJson($this->url($id), $this->s)->json('dados');
        $this->assertSame(['CANCELADA', true, null], [$est($semFactura)['estado'], $est($semFactura)['caducada'], $est($semFactura)['nota_credito_id']]);
        $this->assertSame(['CANCELADA', true], [$est($facturada)['estado'], $est($facturada)['caducada']]);
        $this->assertNotNull($est($facturada)['nota_credito_id']);   // o legado deixava a factura emitida sem nota de crédito
        $this->assertSame('Prazo de pagamento esgotado (2026-03-22 10:00). O horário foi libertado.', $est($facturada)['motivo_estado']);
        $this->assertSame('sistema', $est($facturada)['estado_por']);
        $this->assertSame(['AGUARDA_PAGAMENTO', 'AGUARDA_PAGAMENTO'], [$est($parcial)['estado'], $est($paga)['estado']]);   // há dinheiro de terceiros: decide uma pessoa
        $this->assertSame(4, $this->vendas());   // 3 facturas + 1 nota de crédito
        // repetível: não cancela nem emite outra nota de crédito
        $this->postJson('/api/imobiliario/reservas/caducar', [], $this->s)->assertOk()->assertJsonPath('dados.caducadas', 0);
        $this->assertSame(4, $this->vendas());
        $this->assertContains('2 reserva(s) com o prazo de pagamento esgotado e dinheiro já recebido: decida se se confirma ou se se cancela com reembolso.', $this->getJson('/api/imobiliario/reservas/avisos', $this->s)->json('dados'));
        // o horário libertado já se marca
        $this->reservar($e, ['data' => '2026-03-27', 'hora_inicio' => '10:00', 'hora_fim' => '12:00'])->assertCreated();
        // completa a caução da reserva paga: confirma-se (a partir daí a caducidade já a confirma sozinha)
        $this->postJson($this->url($paga, 'receber'), ['conta_financeira' => '4311'], $this->s)->assertOk()->assertJsonPath('dados.reserva.estado', 'CONFIRMADA');
    }

    #[Test]
    public function a_tarefa_agendada_caduca_as_reservas_de_todas_as_empresas(): void
    {
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e)->assertCreated()->json('dados.id');
        $this->travelTo(Carbon::parse('2026-03-22 11:00:00'));
        $this->artisan('erp:imobiliario:caducar-reservas')->assertExitCode(0);
        $this->assertSame('CANCELADA', DB::table('reservas_espaco')->where('id', $r)->value('estado'));
        $this->assertTrue((bool) DB::table('reservas_espaco')->where('id', $r)->value('caducada'));
        $this->artisan('erp:imobiliario:caducar-reservas')->assertExitCode(0);   // repetível
        $this->assertSame(1, DB::table('historico_reserva_imo')->where('reserva_id', $r)->where('evento', 'ESTADO')->count());
    }

    // ---------------------------------------------------------------- calendário, dia, resumo e avisos

    #[Test]
    public function calendario_dia_resumo_e_avisos_das_areas_comuns(): void
    {
        $e = $this->espaco(['limite_mes_fraccao' => null, 'nome' => 'Churrasqueira', 'tipo' => 'CHURRASQUEIRA', 'regime' => 'RESERVA_GRATUITA']);
        $this->comContrato();
        $a = $this->reservar($e, ['hora_inicio' => '10:00', 'hora_fim' => '12:00'])->assertCreated()->json('dados.id');
        $this->reservar($e, ['hora_inicio' => '14:00', 'hora_fim' => '16:00', 'fracao_id' => null, 'motivo' => 'Obras'])->assertCreated();
        $this->reservar($e, ['data' => '2026-03-28', 'hora_inicio' => '09:00', 'hora_fim' => '10:00'])->assertCreated();
        $d = $this->getJson("/api/imobiliario/areas/espacos/{$e}/dia?data=2026-03-27", $this->s)->assertOk()->json('dados');
        $this->assertSame(['Sexta', true, false, 10.0], [$d['dia'], $d['abre'], $d['vazio'], $d['horas_livres']]);   // 08–10 (2 h), 12–14 (2 h), 16–22 (6 h)
        $this->assertSame([['08:00', '10:00', 2.0], ['12:00', '14:00', 2.0], ['16:00', '22:00', 6.0]], array_map(fn ($l) => [$l['inicio'], $l['fim'], $l['horas']], $d['livres']));
        $this->assertTrue($this->getJson("/api/imobiliario/areas/espacos/{$e}/dia?data=2026-03-29", $this->s)->json('dados.vazio'));
        $cal = $this->getJson('/api/imobiliario/areas/calendario?de=2026-03-27&ate=2026-03-28', $this->s)->assertOk()->json('dados');
        $this->assertSame(['2026-03-27', 'Sexta', 2, '2026-03-28', 1], [$cal[0]['data'], $cal[0]['dia'], count($cal[0]['reservas']), $cal[1]['data'], count($cal[1]['reservas'])]);
        $this->assertSame(['Inquilino Fictício', 'Administração', 'Churrasqueira'], [$cal[0]['reservas'][0]['reservante'], $cal[0]['reservas'][1]['reservante'], $cal[0]['reservas'][0]['espaco_nome']]);
        // as canceladas continuam no calendário (como no legado)
        $this->postJson($this->url($a, 'estado'), ['estado' => 'CANCELADA', 'motivo' => 'x'], $this->s)->assertOk();
        $this->assertSame(2, count($this->getJson('/api/imobiliario/areas/calendario?de=2026-03-27&ate=2026-03-27', $this->s)->json('dados.0.reservas')));
        $r = $this->getJson('/api/imobiliario/areas/resumo', $this->s)->assertOk()->json('dados');
        $this->assertSame([1, 1, 1, 0, 2, 0, 0], [$r['espacos'], $r['activos'], $r['reservaveis'], $r['pagos'], $r['futuras'], $r['por_aprovar'], $r['hoje']]);
        $this->assertContains('1 espaço(s) de festa sem limite de reservas por fracção — uma só família pode ficar com todos os fins-de-semana.', $this->getJson('/api/imobiliario/areas/avisos', $this->s)->json('dados'));
        $this->viajar('2026-03-30 10:00:00');
        $this->assertSame(2, $this->getJson('/api/imobiliario/areas/resumo', $this->s)->json('dados.por_fechar'));
        $this->assertContains('2 reserva(s) com data passada ainda por fechar — diga se foram realizadas ou se ninguém apareceu.', $this->getJson('/api/imobiliario/areas/avisos', $this->s)->json('dados'));
        // filtros e validação dos parâmetros
        $this->getJson('/api/imobiliario/areas/calendario?de=ontem', $this->s)->assertStatus(422);
        $this->getJson("/api/imobiliario/areas/espacos/{$e}/dia", $this->s)->assertStatus(422);
        $this->assertSame([], $this->getJson('/api/imobiliario/areas/espacos?imovel_id=999999', $this->s)->assertOk()->json('dados'));
    }

    // ---------------------------------------------------------------- isolamento e permissões

    #[Test]
    public function isolamento_por_empresa_nas_areas_comuns_e_nas_reservas(): void
    {
        $this->configurar();
        $e = $this->espaco();
        $this->comContrato();
        $r = $this->reservar($e)->assertCreated()->json('dados.id');
        $this->postJson($this->url($r, 'facturar'), [], $this->s)->assertOk();
        $outra = $this->criarEmpresa(['nif' => '5417000777']);
        $h2 = $this->sessao(array_values(array_filter($this->perms, fn ($p) => str_starts_with($p, 'imo_reserva') || in_array($p, ['imo_areas_view', 'imo_area_editar']))), $outra);
        $this->assertSame([], $this->getJson('/api/imobiliario/areas/espacos', $h2)->assertOk()->json('dados'));
        $this->assertSame([], $this->getJson('/api/imobiliario/reservas', $h2)->assertOk()->json('dados'));
        $this->assertSame([], $this->getJson('/api/imobiliario/areas/calendario', $h2)->assertOk()->json('dados'));
        $this->getJson($this->url($r), $h2)->assertNotFound();
        $this->putJson($this->url($r), $this->corpoReserva($e), $h2)->assertNotFound();
        $this->deleteJson($this->url($r), [], $h2)->assertNotFound();
        foreach (['estado' => ['estado' => 'APROVADA'], 'cancelar' => ['motivo' => 'x'], 'facturar' => [], 'receber' => ['conta_financeira' => '4311'], 'vistoria' => ['quando' => 'ANTES'], 'caucao' => ['tipo' => 'RECEBIDA', 'valor' => 1]] as $acao => $corpo) {
            $this->postJson($this->url($r, $acao), $corpo, $h2)->assertNotFound();
        }
        $this->getJson($this->url($r, 'caucao'), $h2)->assertNotFound();
        $this->getJson($this->url($r, 'previsao-cancelamento'), $h2)->assertNotFound();
        $this->putJson("/api/imobiliario/areas/espacos/{$e}", ['nome' => 'Roubado'], $h2)->assertNotFound();
        $this->deleteJson("/api/imobiliario/areas/espacos/{$e}", [], $h2)->assertNotFound();
        $this->getJson("/api/imobiliario/areas/espacos/{$e}/dia?data=2026-03-27", $h2)->assertNotFound();
        $this->postJson('/api/imobiliario/reservas', $this->corpoReserva($e, ['fracao_id' => null]), $h2)->assertNotFound();
        $this->getJson('/api/imobiliario/reservas/verificar?'.http_build_query(['espaco_id' => $e, 'data' => '2026-03-27', 'hora_inicio' => '10:00', 'hora_fim' => '11:00']), $h2)->assertOk()->assertJsonPath('dados.impedimentos.0', 'Espaço não encontrado.');
        $this->assertSame(0.0, $this->getJson('/api/imobiliario/reservas/contas', $h2)->json('dados.facturado'));
        $this->assertSame('AGUARDA_PAGAMENTO', DB::table('reservas_espaco')->where('id', $r)->value('estado'));
    }

    #[Test]
    public function sem_permissao_recusa_com_403_antes_de_validar(): void
    {
        $leitor = $this->sessao(['imo_areas_view']);
        $this->getJson('/api/imobiliario/areas/espacos', $leitor)->assertOk();
        $this->getJson('/api/imobiliario/reservas', $leitor)->assertOk();
        $this->getJson('/api/imobiliario/reservas/contas', $leitor)->assertOk();
        foreach ([['post', '/api/imobiliario/areas/espacos'], ['put', '/api/imobiliario/areas/espacos/1'], ['delete', '/api/imobiliario/areas/espacos/1'], ['post', '/api/imobiliario/areas/espacos/1/activo'],
            ['post', '/api/imobiliario/reservas'], ['put', '/api/imobiliario/reservas/1'], ['delete', '/api/imobiliario/reservas/1'], ['post', '/api/imobiliario/reservas/1/estado'],
            ['post', '/api/imobiliario/reservas/1/cancelar'], ['post', '/api/imobiliario/reservas/1/facturar'], ['post', '/api/imobiliario/reservas/1/receber'], ['post', '/api/imobiliario/reservas/1/vistoria'],
            ['post', '/api/imobiliario/reservas/1/caucao'], ['post', '/api/imobiliario/reservas/caucao/1/anular'], ['post', '/api/imobiliario/reservas/caducar']] as [$m, $u]) {
            $this->{$m.'Json'}($u, [], $leitor)->assertForbidden();
        }
        $semAcesso = $this->sessao(['dashboard_view']);
        foreach (['/api/imobiliario/areas/espacos', '/api/imobiliario/areas/resumo', '/api/imobiliario/areas/avisos', '/api/imobiliario/areas/calendario', '/api/imobiliario/reservas',
            '/api/imobiliario/reservas/contas', '/api/imobiliario/reservas/avisos', '/api/imobiliario/reservas/verificar', '/api/imobiliario/reservas/1', '/api/imobiliario/reservas/1/caucao',
            '/api/imobiliario/reservas/1/previsao-cancelamento'] as $u) {
            $this->getJson($u, $semAcesso)->assertForbidden();
        }
        // quem só tem uma das permissões financeiras não faz as outras
        $soFacturar = $this->sessao(['imo_areas_view', 'imo_reserva_facturar']);
        $this->postJson('/api/imobiliario/reservas/1/receber', [], $soFacturar)->assertForbidden();
        $this->postJson('/api/imobiliario/reservas/1/caucao', [], $soFacturar)->assertForbidden();
        $this->postJson('/api/imobiliario/reservas/1/facturar', [], $soFacturar)->assertNotFound();
    }

    #[Test]
    public function o_catalogo_segrega_aprovar_de_facturar_facturar_de_receber_e_a_vistoria_da_retencao(): void
    {
        $pares = collect(CatalogoPermissoes::segregacao())->filter(fn ($s) => str_starts_with($s['a'], 'imo_'));
        foreach ([['imo_reserva_aprovar', 'imo_reserva_facturar'], ['imo_reserva_facturar', 'imo_reserva_receber'], ['imo_reserva_vistoria', 'imo_reserva_caucao'], ['imo_reserva_devedor', 'imo_reserva_facturar']] as [$a, $b]) {
            $this->assertTrue($pares->contains(fn ($s) => $s['a'] === $a && $s['b'] === $b), "falta o par {$a} × {$b}");
        }
        $tarefas = collect(CatalogoPermissoes::completo()['modulos'])->firstWhere('id', 'imobiliario')['ecras'];
        $areas = collect($tarefas)->firstWhere('id', 'imo_areas')['tarefas'];
        $porChave = collect($areas)->keyBy('chave');
        $this->assertTrue($porChave['imo_reserva_receber']['sensivel']);
        $this->assertFalse($porChave['imo_reserva_vistoria']['sensivel']);
        $this->assertTrue($porChave['imo_reserva_cancelar']['sensivel']);
        foreach (CatalogoPermissoes::modelos() as $mod) {
            foreach ($pares as $s) {
                $this->assertFalse(! empty($mod['permissoes'][$s['a']]) && ! empty($mod['permissoes'][$s['b']]), "{$mod['nome']} acumula {$s['a']} e {$s['b']}");
            }
        }
        $gestor = collect(CatalogoPermissoes::modelos())->firstWhere('nome', 'Gestor Imobiliário')['permissoes'];
        $cobranca = collect(CatalogoPermissoes::modelos())->firstWhere('nome', 'Responsável de Cobrança')['permissoes'];
        $this->assertTrue($gestor['imo_reserva_receber'] && $gestor['imo_reserva_vistoria'] && $gestor['imo_reserva_aprovar']);
        $this->assertTrue($cobranca['imo_reserva_facturar'] && $cobranca['imo_reserva_caucao']);
        $this->assertArrayNotHasKey('imo_reserva_receber', $cobranca);
        $this->assertArrayNotHasKey('imo_reserva_facturar', $gestor);
        // o operador não recebe tarefas do módulo
        $operador = collect(CatalogoPermissoes::modelos())->firstWhere('nome', 'Operador POS / Lavandaria')['permissoes'];
        $this->assertSame([], array_values(array_filter(array_keys($operador), fn ($k) => str_starts_with($k, 'imo_'))));
    }
}
