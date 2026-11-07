<?php

namespace Tests\Unit;

use App\Services\Imobiliario\CalculadoraReserva;
use App\Services\Imobiliario\CatalogoParqueamento;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gestão Imobiliária, Onda 3 (F4): fórmulas puras do parqueamento e das reservas de áreas comuns, com números calculados à mão (as
 * fixtures do legado onde o legado está certo): duração, preço por unidade, prazo de pagamento, escala de cancelamento, estado da caução,
 * sobreposição de horários, matrículas, porte, designação das vagas.
 */
final class ImobiliarioParqueamentoCalculadorasTest extends TestCase
{
    #[Test]
    public function a_duracao_arredonda_aos_6_minutos_com_uma_casa_decimal(): void
    {
        $this->assertSame('2.5', CalculadoraReserva::horasEntre('14:00', '16:30'));   // 150 min
        $this->assertSame('3.0', CalculadoraReserva::horasEntre('09:00', '12:00'));
        $this->assertSame('0.5', CalculadoraReserva::horasEntre('10:00', '10:30'));
        $this->assertSame('1.1', CalculadoraReserva::horasEntre('10:00', '11:05'));   // 65 min = 10,83 → 11 décimas
        $this->assertNull(CalculadoraReserva::horasEntre('10:00', '10:00'));
        $this->assertNull(CalculadoraReserva::horasEntre('11:00', '10:00'));
        $this->assertNull(CalculadoraReserva::horasEntre('xx', '10:00'));
    }

    #[Test]
    public function o_preco_e_por_unidade_inteira_com_o_minimo_de_uma(): void
    {
        // 2,5 h a 4 000 Kz/h com 1 h por unidade = 3 unidades = 12 000 (legado: Math.ceil)
        $r = CalculadoraReserva::valor('RESERVA_PAGA', 4000, 'HORA', 20000, '2.5');
        $this->assertSame(['12000.00', '20000.00', 3, '3 × hora (2.5 h)'], [$r['valor'], $r['caucao'], $r['unidades'], $r['detalhe']]);
        // por período de 4 h: 3 h = 1 período; 4,5 h = 2
        $this->assertSame('15000.00', CalculadoraReserva::valor('RESERVA_PAGA', 15000, 'PERIODO', 0, '3.0')['valor']);
        $this->assertSame('30000.00', CalculadoraReserva::valor('RESERVA_PAGA', 15000, 'PERIODO', 0, '4.5')['valor']);
        $this->assertSame('1 × período de 4 horas (3 h)', CalculadoraReserva::valor('RESERVA_PAGA', 15000, 'PERIODO', 0, '3.0')['detalhe']);
        // por dia e valor fixo (uma só vez, seja qual for a duração)
        $this->assertSame('50000.00', CalculadoraReserva::valor('RESERVA_PAGA', 50000, 'DIA', 0, '10.0')['valor']);
        $fixo = CalculadoraReserva::valor('RESERVA_PAGA', 35000, 'FIXO', 0, '6.0');
        $this->assertSame(['35000.00', 1, 'valor fixo'], [$fixo['valor'], $fixo['unidades'], $fixo['detalhe']]);
        // uma meia hora cobra a unidade inteira (mínimo 1)
        $this->assertSame('4000.00', CalculadoraReserva::valor('RESERVA_PAGA', 4000, 'HORA', 0, '0.5')['valor']);
        // sem regime pago não custa nada (nem caução)
        $this->assertSame(['0.00', '0.00', 0, 'Sem custo'], array_values(CalculadoraReserva::valor('RESERVA_GRATUITA', 4000, 'HORA', 9999, '2.5')));
    }

    #[Test]
    public function o_cancelamento_devolve_tudo_com_antecedencia_e_a_percentagem_depois(): void
    {
        // recebido 12 000; devolve tudo até 7 dias antes; depois 50 %
        $a = CalculadoraReserva::reembolso('12000.00', 3, 7, 50);
        $this->assertSame([50, '6000.00', '6000.00'], [$a['pct'], $a['devolve'], $a['retem']]);   // faltam 3 dias: devolve 6 000
        $b = CalculadoraReserva::reembolso('12000.00', 7, 7, 50);
        $this->assertSame([100, '12000.00', '0.00'], [$b['pct'], $b['devolve'], $b['retem']]);    // exactamente 7 dias: tudo
        $c = CalculadoraReserva::reembolso('12000.00', 6, 7, 50);
        $this->assertSame('6000.00', $c['devolve']);
        $d = CalculadoraReserva::reembolso('12000.00', -1, 7, 30);                                // já passou: a percentagem do regulamento
        $this->assertSame([30, '3600.00', '8400.00'], [$d['pct'], $d['devolve'], $d['retem']]);
        // meio cêntimo para cima: 333,33 × 50 % = 166,665 → 166,67
        $this->assertSame('166.67', CalculadoraReserva::pctDe('333.33', 50));
        $this->assertSame('0.00', CalculadoraReserva::reembolso('0.00', 1, 7, 50)['devolve']);
        $this->assertSame('Nada foi pago: não há nada a devolver.', CalculadoraReserva::explicacao('0.00', 1, 7, 50));
        $this->assertSame('Faltam 3 dia(s) e o espaço devolve tudo até 2 dia(s) antes.', CalculadoraReserva::explicacao('100.00', 3, 2, 100));
        $this->assertSame('Faltam só 1 dia(s): dentro dos 7 dia(s) do regulamento devolve-se 50%.', CalculadoraReserva::explicacao('100.00', 1, 7, 50));
    }

    #[Test]
    public function o_prazo_de_pagamento_nunca_passa_do_inicio_do_evento(): void
    {
        $agora = CarbonImmutable::parse('2026-03-20 10:00:00');
        // 48 h por omissão: 22/03 10:00 (o evento é daqui a uma semana)
        $this->assertSame('2026-03-22 10:00', CalculadoraReserva::prazoPagamento($agora, null, '2026-03-27', '14:00')->format('Y-m-d H:i'));
        // 24 h
        $this->assertSame('2026-03-21 10:00', CalculadoraReserva::prazoPagamento($agora, 24, '2026-03-27', '14:00')->format('Y-m-d H:i'));
        // evento amanhã às 08:00: o prazo fica no início do evento
        $this->assertSame('2026-03-21 08:00', CalculadoraReserva::prazoPagamento($agora, 48, '2026-03-21', '08:00')->format('Y-m-d H:i'));
        // 1 hora; zero ou vazio volta às 48 h por omissão (como no legado)
        $this->assertSame('2026-03-20 11:00', CalculadoraReserva::prazoPagamento($agora, 1, '2026-03-27', '14:00')->format('Y-m-d H:i'));
        $this->assertSame('2026-03-22 10:00', CalculadoraReserva::prazoPagamento($agora, 0, '2026-03-27', '14:00')->format('Y-m-d H:i'));
    }

    #[Test]
    public function estado_da_caucao_a_partir_dos_totais(): void
    {
        $this->assertSame('NAO_APLICA', CalculadoraReserva::estadoCaucao(0, 0, 0, 0));
        $this->assertSame('POR_RECEBER', CalculadoraReserva::estadoCaucao(20000, 0, 0, 0));
        $this->assertSame('EM_PODER', CalculadoraReserva::estadoCaucao(20000, 20000, 0, 0));
        $this->assertSame('DEVOLVIDA', CalculadoraReserva::estadoCaucao(20000, 20000, 0, 20000));
        $this->assertSame('RETIDA_PARCIAL', CalculadoraReserva::estadoCaucao(20000, 20000, 5000, 15000));
        $this->assertSame('RETIDA_TOTAL', CalculadoraReserva::estadoCaucao(20000, 20000, 20000, 0));
    }

    #[Test]
    public function horarios_semiabertos_encostar_nao_conflita(): void
    {
        $m = fn (string $h) => CatalogoParqueamento::minutos($h);
        $this->assertTrue(CalculadoraReserva::sobrepoe($m('10:00'), $m('12:00'), $m('11:00'), $m('13:00')));
        $this->assertTrue(CalculadoraReserva::sobrepoe($m('10:00'), $m('12:00'), $m('10:30'), $m('11:00')));
        $this->assertFalse(CalculadoraReserva::sobrepoe($m('10:00'), $m('12:00'), $m('12:00'), $m('14:00')));   // encosta
        $this->assertFalse(CalculadoraReserva::sobrepoe($m('10:00'), $m('12:00'), $m('08:00'), $m('10:00')));
        $this->assertSame(1440, $m('24:00'));
        $this->assertNull($m('24:30'));
        $this->assertNull($m('25:00'));
        $this->assertSame('09:05', CatalogoParqueamento::hhmm(545));
    }

    #[Test]
    public function matriculas_porte_e_designacoes_das_vagas(): void
    {
        $this->assertSame('LD-12-34-AB', CatalogoParqueamento::normalizarMatricula(' ld 12 34 ab '));
        $this->assertSame('LD-12-34-AB', CatalogoParqueamento::normalizarMatricula('LD-12-34-AB'));
        $this->assertSame('ABC123', CatalogoParqueamento::normalizarMatricula('abc-123'));   // estrangeira: sem hífenes
        $this->assertSame('', CatalogoParqueamento::normalizarMatricula('--'));
        // o veículo cabe se a sua ordem não for maior do que a do lugar; porte desconhecido aceita tudo
        $this->assertTrue(CatalogoParqueamento::porteCabe('SUV', 'LIGEIRO'));
        $this->assertTrue(CatalogoParqueamento::porteCabe('SUV', 'SUV'));
        $this->assertFalse(CatalogoParqueamento::porteCabe('LIGEIRO', 'SUV'));
        $this->assertFalse(CatalogoParqueamento::porteCabe('MOTA', 'PESADO'));
        $this->assertTrue(CatalogoParqueamento::porteCabe(null, 'PESADO'));
        $this->assertTrue(CatalogoParqueamento::porteCabe('MOTA', null));
        // G-101 (piso −1, 1.º lugar), G-201 (piso −2), R/C com dois dígitos
        $this->assertSame('G-101', CatalogoParqueamento::designacaoVaga('G', -1, 1));
        $this->assertSame('G-212', CatalogoParqueamento::designacaoVaga('G', -2, 12));
        $this->assertSame('G-01', CatalogoParqueamento::designacaoVaga('G', 0, 1));
        $this->assertSame(101, CatalogoParqueamento::sequenciaVaga(-1, 1));
        // só PERMANENTE e AUTONOMA se arrendam; sem afectação não é arrendável
        $this->assertTrue(CatalogoParqueamento::vagaArrendavel('ESTACIONAMENTO', 'PERMANENTE'));
        $this->assertTrue(CatalogoParqueamento::vagaArrendavel('ESTACIONAMENTO', 'AUTONOMA'));
        $this->assertFalse(CatalogoParqueamento::vagaArrendavel('ESTACIONAMENTO', 'VISITA'));
        $this->assertFalse(CatalogoParqueamento::vagaArrendavel('ESTACIONAMENTO', null));
        $this->assertFalse(CatalogoParqueamento::vagaArrendavel('HABITACIONAL', 'AUTONOMA'));
    }

    #[Test]
    public function a_maquina_de_estados_da_reserva_nao_tem_saidas_dos_estados_finais(): void
    {
        foreach (['REALIZADA', 'FALTOU', 'CANCELADA', 'RECUSADA'] as $f) {
            $this->assertSame([], CatalogoParqueamento::TRANSICOES[$f]);
            $this->assertFalse(CatalogoParqueamento::ESTADOS_RESERVA[$f]['viva']);
        }
        $this->assertContains('AGUARDA_PAGAMENTO', CatalogoParqueamento::TRANSICOES['PEDIDA']);
        $this->assertSame(['CONFIRMADA', 'CANCELADA'], CatalogoParqueamento::TRANSICOES['AGUARDA_PAGAMENTO']);
        // só a realizada continua a ocupar o horário depois de fechada
        $this->assertTrue(CatalogoParqueamento::ESTADOS_RESERVA['REALIZADA']['ocupa']);
        $this->assertFalse(CatalogoParqueamento::ESTADOS_RESERVA['FALTOU']['ocupa']);
    }
}
