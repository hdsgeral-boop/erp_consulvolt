import { describe, expect, it } from 'vitest';
import {
  accoesDaReserva, aparenciaDaVaga, designacaoVaga, diasEntre, horasEntre, minutos, normalizarMatricula, porteCabe, reembolso, resumoDias, valorReserva,
} from './logicaParque';

// Os mesmos números calculados à mão que os testes do servidor (ImobiliarioParqueamentoCalculadorasTest).
describe('matrículas, porte e designações das vagas', () => {
  it('normaliza a matrícula: maiúsculas, sem separadores, 8 caracteres em LD-12-34-AB', () => {
    expect(normalizarMatricula(' ld 12 34 ab ')).toBe('LD-12-34-AB');
    expect(normalizarMatricula('LD-12-34-AB')).toBe('LD-12-34-AB');
    expect(normalizarMatricula('abc-123')).toBe('ABC123');
    expect(normalizarMatricula('--')).toBe('');
    expect(normalizarMatricula(null)).toBe('');
  });

  it('o veículo cabe se a ordem do porte não for maior; porte desconhecido aceita tudo', () => {
    expect(porteCabe('SUV', 'LIGEIRO')).toBe(true);
    expect(porteCabe('SUV', 'SUV')).toBe(true);
    expect(porteCabe('LIGEIRO', 'SUV')).toBe(false);
    expect(porteCabe('MOTA', 'PESADO')).toBe(false);
    expect(porteCabe(null, 'PESADO')).toBe(true);
    expect(porteCabe('MOTA', null)).toBe(true);
  });

  it('numera as vagas por piso: G-101, G-212 e, ao R/C, G-01', () => {
    expect(designacaoVaga('G', -1, 1)).toBe('G-101');
    expect(designacaoVaga('G', -2, 12)).toBe('G-212');
    expect(designacaoVaga('P', 0, 3)).toBe('P-03');
  });

  it('o lugar tem sempre texto de estado: obra, uso comum, ocupada ou livre', () => {
    const base = { estado: 'DISPONIVEL', arrendavel: true, afectacao: 'AUTONOMA' as const, ocupada: false };
    expect(aparenciaDaVaga(base).rotulo).toBe('Livre');
    expect(aparenciaDaVaga({ ...base, ocupada: true }).rotulo).toBe('Ocupada');
    expect(aparenciaDaVaga({ ...base, arrendavel: false, afectacao: 'VISITA' }).rotulo).toBe('Visitas');
    expect(aparenciaDaVaga({ ...base, arrendavel: false, afectacao: 'CARGA_DESCARGA' }, [{ valor: 'CARGA_DESCARGA', rotulo: 'Cargas e descargas' }]).rotulo).toBe('Cargas e descargas');
    expect(aparenciaDaVaga({ ...base, estado: 'EM_OBRA' }).rotulo).toBe('Em obra');
    expect(aparenciaDaVaga({ ...base, estado: 'INDISPONIVEL', ocupada: true }).rotulo).toBe('Indisponível');
  });
});

describe('horas, preço e cancelamento das reservas', () => {
  it('a duração arredonda aos 6 minutos com uma casa decimal', () => {
    expect(horasEntre('14:00', '16:30')).toBe(2.5);
    expect(horasEntre('09:00', '12:00')).toBe(3);
    expect(horasEntre('10:00', '11:05')).toBe(1.1);
    expect(horasEntre('10:00', '10:00')).toBeNull();
    expect(horasEntre('11:00', '10:00')).toBeNull();
    expect(horasEntre('xx', '10:00')).toBeNull();
    expect(minutos('24:00')).toBe(1440);
    expect(minutos('24:30')).toBeNull();
  });

  it('2,5 h a 4 000 Kz/h = 3 unidades = 12 000, com a caução à parte', () => {
    expect(valorReserva('RESERVA_PAGA', 4000, 'HORA', 20000, 2.5)).toEqual({ valor: 12000, caucao: 20000, unidades: 3, detalhe: '3 × hora (2.5 h)' });
    expect(valorReserva('RESERVA_PAGA', 15000, 'PERIODO', 0, 3).valor).toBe(15000);   // 3 h = 1 período de 4 h
    expect(valorReserva('RESERVA_PAGA', 15000, 'PERIODO', 0, 4.5).valor).toBe(30000);
    expect(valorReserva('RESERVA_PAGA', 50000, 'DIA', 0, 10).valor).toBe(50000);
    expect(valorReserva('RESERVA_PAGA', 35000, 'FIXO', 0, 6)).toMatchObject({ valor: 35000, unidades: 1, detalhe: 'valor fixo' });
    expect(valorReserva('RESERVA_PAGA', 4000, 'HORA', 0, 0.5).valor).toBe(4000);        // meia hora cobra a unidade inteira
    expect(valorReserva('RESERVA_GRATUITA', 4000, 'HORA', 9999, 2.5)).toEqual({ valor: 0, caucao: 0, unidades: 0, detalhe: 'Sem custo' });
  });

  it('cancelar a 3 dias de 7 devolve 50 % = 6 000; a 7 dias devolve tudo; depois da data, a percentagem do regulamento', () => {
    expect(reembolso(12000, 3, 7, 50)).toEqual({ pct: 50, devolve: 6000, retem: 6000 });
    expect(reembolso(12000, 7, 7, 50)).toEqual({ pct: 100, devolve: 12000, retem: 0 });
    expect(reembolso(12000, -1, 7, 30)).toEqual({ pct: 30, devolve: 3600, retem: 8400 });
    expect(reembolso(333.33, 1, 7, 50).devolve).toBe(166.67);   // 166,665 → meio cêntimo para cima
    expect(reembolso(0, 1, 7, 50).devolve).toBe(0);
  });

  it('dias entre datas e resumo dos dias de funcionamento', () => {
    expect(diasEntre('2026-03-20', '2026-03-23')).toBe(3);
    expect(diasEntre('2026-03-23', '2026-03-20')).toBe(-3);
    const nomes = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    expect(resumoDias([0, 1, 2, 3, 4, 5, 6], nomes)).toBe('todos os dias');
    expect(resumoDias([6, 5, 0, 5], nomes)).toBe('Dom, Sex, Sáb');
  });
});

describe('acções permitidas por estado da reserva', () => {
  it('pedida: aprovar e recusar; não factura nem recebe', () => {
    const a = accoesDaReserva('PEDIDA', true, 12000, false, false, false);
    expect([a.aprovar, a.recusar, a.facturar, a.receber, a.cancelar, a.editar]).toEqual([true, true, false, false, true, true]);
  });

  it('a aguardar pagamento: factura se ainda não facturada, recebe, e depois de facturada já não se edita nem apaga', () => {
    const antes = accoesDaReserva('AGUARDA_PAGAMENTO', true, 12000, false, false, false);
    expect([antes.facturar, antes.receber, antes.editar, antes.apagar]).toEqual([true, true, true, true]);
    const depois = accoesDaReserva('AGUARDA_PAGAMENTO', true, 12000, true, false, false);
    expect([depois.facturar, depois.receber, depois.editar, depois.apagar, depois.cancelar]).toEqual([false, true, false, false, true]);
  });

  it('reserva interna ou gratuita não se factura; as fechadas não têm acções', () => {
    expect(accoesDaReserva('APROVADA', true, 12000, false, true, false).facturar).toBe(false);
    expect(accoesDaReserva('APROVADA', false, 0, false, false, false)).toMatchObject({ facturar: false, receber: false, realizada: true, faltou: true });
    const f = accoesDaReserva('REALIZADA', true, 12000, true, false, true);
    expect(Object.values(f).some(Boolean)).toBe(false);
    expect(accoesDaReserva('CANCELADA', true, 12000, true, false, false).cancelar).toBe(false);
  });
});
