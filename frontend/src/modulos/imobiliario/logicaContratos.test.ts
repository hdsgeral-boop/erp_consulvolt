import { describe, expect, it } from 'vitest';
import { accoesDoContrato, chaveDePedido, diasDoPrimeiroPeriodo, fimDoMes, limiteCaucao, previsaoRenda, rendaMensal, rendaSeleccionavel, somarMeses, textoAtraso, type TaxasRenda } from './logicaContratos';

const TAXAS: TaxasRenda = { ip_taxa: 25, ip_base: 60, selo_habitacao: 0.1, selo_outro: 0.4, iva_arrendamento: 0, iva_alojamento: 7 };
const C = { renda: 100000, retencao_ip: true, isencao_ip: 'NAO', selo_encargo: 'SENHORIO', finalidade_fiscal: 'HABITACAO' };

describe('pré-visualização da renda (as mesmas fixtures do servidor)', () => {
  it('100 000 Kz em habitação com retenção: IP 15 000, selo 100, a receber 85 000', () => {
    expect(previsaoRenda(C, TAXAS)).toEqual({ base: 100000, ip: 15000, selo: 100, iva: 0, totalFacturar: 100000, totalReceber: 85000, custoSenhorio: 100 });
  });

  it('sem retenção o Imposto Predial é custo do senhorio; isenções e selo isento', () => {
    expect(previsaoRenda({ ...C, retencao_ip: false }, TAXAS)).toMatchObject({ totalReceber: 100000, custoSenhorio: 15100 });
    expect(previsaoRenda({ ...C, isencao_ip: 'METADE' }, TAXAS).ip).toBe(7500);
    expect(previsaoRenda({ ...C, isencao_ip: 'TOTAL' }, TAXAS)).toMatchObject({ ip: 0, totalReceber: 100000 });
    expect(previsaoRenda({ ...C, selo_encargo: 'ISENTO' }, TAXAS)).toMatchObject({ selo: 0, custoSenhorio: 0 });
  });

  it('comércio paga 0,4 % de selo e o alojamento 7 % de IVA', () => {
    expect(previsaoRenda({ ...C, finalidade_fiscal: 'COMERCIO' }, TAXAS).selo).toBe(400);
    expect(previsaoRenda({ ...C, finalidade_fiscal: 'ALOJAMENTO' }, TAXAS)).toMatchObject({ iva: 7000, totalFacturar: 107000, totalReceber: 92000 });
  });

  it('encargos a acrescer entram na base e os incluídos na renda não', () => {
    const r = previsaoRenda({ ...C, encargos: [{ valor: 20000, incluido_na_renda: false }, { valor: 5000, incluido_na_renda: true }] }, TAXAS);
    expect(r.base).toBe(120000);
    expect(r.ip).toBe(18000);
  });

  it('primeiro período proporcional: 16 dias de 31 dão 51 612,90 de renda e 7 741,94 de IP', () => {
    const r = previsaoRenda(C, TAXAS, 16 / 31);
    expect(r.base).toBe(51612.9);
    expect(r.ip).toBe(7741.94);
    expect(r.totalReceber).toBe(43870.96);
  });
});

describe('períodos e pro-rata', () => {
  it('dias do primeiro período', () => {
    expect(diasDoPrimeiroPeriodo('2026-01-16', 'MENSAL')).toEqual({ cobertos: 16, total: 31 });
    expect(diasDoPrimeiroPeriodo('2026-01-01', 'MENSAL')).toEqual({ cobertos: 31, total: 31 });
    expect(diasDoPrimeiroPeriodo('2026-11-16', 'TRIMESTRAL')).toEqual({ cobertos: 77, total: 92 });   // 16 Nov a 31 Jan: 15 + 31 + 31 = 77 de 30 + 31 + 31
  });

  it('fim do mês e soma de meses', () => {
    expect(fimDoMes('2026-02')).toBe('2026-02-28');
    expect(fimDoMes('2028-02')).toBe('2028-02-29');
    expect(somarMeses('2026-11', 3)).toBe('2027-02');
    expect(somarMeses('2026-03', -11)).toBe('2025-04');
  });
});

describe('regras de apresentação', () => {
  it('renda mensal e limite da caução', () => {
    expect(rendaMensal(120000, 'ANUAL')).toBe(10000);
    expect(rendaMensal(300000, 'TRIMESTRAL')).toBe(100000);
    expect(limiteCaucao(100000)).toBe(600000);
  });

  it('as acções dependem do estado do contrato', () => {
    expect(accoesDoContrato('RASCUNHO', false)).toMatchObject({ editar: true, activar: true, terminar: false, eliminar: true, suspender: false });
    expect(accoesDoContrato('ACTIVO', true)).toMatchObject({ activar: false, suspender: true, terminar: true, renovar: true, actualizarRenda: true, eliminar: false });
    expect(accoesDoContrato('ACTIVO', false).renovar).toBe(false);
    expect(accoesDoContrato('SUSPENSO', true)).toMatchObject({ activar: true, terminar: true, suspender: false, renovar: false });
    expect(accoesDoContrato('TERMINADO', true)).toMatchObject({ editar: false, activar: false, terminar: false, eliminar: true });
  });

  it('só se seleccionam rendas previstas ou aprovadas; a chave do pedido é única', () => {
    expect(['PREVISTA', 'APROVADA', 'FACTURADA', 'PAGA', 'ANULADA'].map((e) => rendaSeleccionavel(e as never))).toEqual([true, true, false, false, false]);
    expect(chaveDePedido()).not.toBe(chaveDePedido());
    expect(textoAtraso(45)).toBe('45 dias de atraso');
    expect(textoAtraso(1)).toBe('1 dia de atraso');
    expect(textoAtraso(0)).toBe('');
  });
});
