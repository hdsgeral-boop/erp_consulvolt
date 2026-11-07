import { describe, expect, it } from 'vitest';
import { accoesDaOrdem, custoDaOrdem, emAtraso, formatarQuantidade, proporDatas, temFalta, tituloComparacao } from './logicaObras';
import type { FichaOrdem, Necessidade } from './apiObras';

const pode = (...ks: string[]) => (...p: string[]) => p.some((x) => ks.includes(x));
const nec = (estado: Necessidade['estado']): Necessidade => ({ id: 1, ordem_id: 1, produto_id: 1, descricao: null, quantidade: '2.000', estado, quantidade_recebida: '0.000', quantidade_armazem: '0.000',
  quantidade_compra: '0.000', nome: 'Cama', de_armazem: true, requisicao_numero: null, requisicao_estado: null, pedido_numero: null, pedido_estado: null, falta_receber: '0.000' });
const ordem = (o: Partial<FichaOrdem> = {}) => ({ estado: 'ABERTA', aprovada: false, por_pedir: 0, por_debitar: false, debitado_em: null, necessidades_lista: [] as Necessidade[], ...o }) as Parameters<typeof accoesDaOrdem>[0];

describe('Obras — lógica de apresentação', () => {
  it('formata quantidades de 3 casas sem zeros inúteis', () => {
    expect(formatarQuantidade('2.500')).toBe('2,5');
    expect(formatarQuantidade('3.000')).toBe('3');
    expect(formatarQuantidade(null)).toBe('0');
    expect(formatarQuantidade('x')).toBe('—');
  });

  it('quem aprova vê Aprovar, quem não aprova vê o pedido de aprovação, e pedir só depois de aprovada', () => {
    const a = accoesDaOrdem(ordem({ por_pedir: 2 }), pode('imo_obra_aprovar', 'imo_obra_pedir', 'imo_obra_editar'));
    expect([a.aprovar, a.pedirAprovacao, a.pedir, a.editar]).toEqual([true, false, false, true]);
    const b = accoesDaOrdem(ordem({ por_pedir: 2 }), pode('imo_obra_pedir'));
    expect([b.aprovar, b.pedirAprovacao, b.pedir]).toEqual([false, true, false]);
    const c = accoesDaOrdem(ordem({ aprovada: true, por_pedir: 2 }), pode('imo_obra_pedir'));
    expect([c.aprovar, c.pedirAprovacao, c.pedir]).toEqual([false, false, true]);
    expect(accoesDaOrdem(ordem({ aprovada: true, por_pedir: 0 }), pode('imo_obra_pedir')).pedir).toBe(false);   // nada por pedir
    expect(accoesDaOrdem(ordem({ aprovada: true, por_pedir: 1 }), pode()).pedir).toBe(false);   // sem permissão
  });

  it('registar entregas exige material já pedido; quem aprova não recebe (permissões distintas)', () => {
    expect(accoesDaOrdem(ordem(), pode('imo_obra_receber')).receber).toBe(false);
    expect(accoesDaOrdem(ordem({ estado: 'AGUARDA_MATERIAL', necessidades_lista: [nec('PEDIDO_COMPRA')] }), pode('imo_obra_receber')).receber).toBe(true);
    expect(accoesDaOrdem(ordem({ estado: 'AGUARDA_MATERIAL', necessidades_lista: [nec('PEDIDO_COMPRA')] }), pode('imo_obra_aprovar')).receber).toBe(false);
    expect(accoesDaOrdem(ordem({ estado: 'EM_CURSO', necessidades_lista: [nec('RECEBIDO')] }), pode('imo_obra_receber')).receber).toBe(false);
  });

  it('as ordens fechadas já não se mexem; debitar só a concluída, aprovada e por debitar; desfazer só a debitada', () => {
    const todas = pode('imo_obra_editar', 'imo_obra_aprovar', 'imo_obra_pedir', 'imo_obra_receber', 'imo_obra_fechar', 'imo_sub_debitar');
    const fechada = accoesDaOrdem(ordem({ estado: 'CONCLUIDA', aprovada: true, por_debitar: true }), todas);
    expect([fechada.editar, fechada.aprovar, fechada.pedir, fechada.concluir, fechada.cancelar, fechada.apagar, fechada.debitar]).toEqual([false, false, false, false, false, false, true]);
    expect(accoesDaOrdem(ordem({ estado: 'CONCLUIDA', aprovada: false, por_debitar: true }), todas).debitar).toBe(false);
    expect(accoesDaOrdem(ordem({ estado: 'CONCLUIDA', aprovada: true, por_debitar: true }), pode('imo_renda_aprovar')).debitar).toBe(false);
    const debitada = accoesDaOrdem(ordem({ estado: 'CONCLUIDA', aprovada: true, por_debitar: false, debitado_em: '2026-03-20T10:00:00+01:00' }), todas);
    expect([debitada.debitar, debitada.desfazer]).toEqual([false, true]);
    expect(accoesDaOrdem(ordem({ estado: 'EM_CURSO', debitado_em: '2026-03-20' }), todas).cancelar).toBe(false);
  });

  it('só se começa a aberta e só se apaga a aberta sem material pedido', () => {
    const todas = pode('imo_obra_editar', 'imo_obra_fechar');
    expect(accoesDaOrdem(ordem(), todas).iniciar).toBe(true);
    expect(accoesDaOrdem(ordem({ estado: 'EM_CURSO' }), todas).iniciar).toBe(false);
    expect(accoesDaOrdem(ordem(), todas).apagar).toBe(true);
    expect(accoesDaOrdem(ordem({ necessidades_lista: [nec('REQUISITADO')] }), todas).apagar).toBe(false);
    expect(accoesDaOrdem(ordem({ estado: 'EM_CURSO' }), todas).apagar).toBe(false);
  });

  it('a comparação diz o que falta e o título conta os obrigatórios', () => {
    expect(temFalta({ falta: '1.000' })).toBe(true);
    expect(temFalta({ falta: '0.000' })).toBe(false);
    expect(tituloComparacao(0, 0)).toBe('A fracção tem tudo o que a ficha pede');
    expect(tituloComparacao(2, 1)).toBe('Faltam 2 artigo(s) — 1 obrigatório(s)');
    expect(tituloComparacao(1, 0)).toBe('Faltam 1 artigo(s)');
  });

  it('o custo mostrado é o real do material quando existe, senão a estimativa; e o atraso só conta nas ordens abertas', () => {
    expect(custoDaOrdem({ custo_estimado: 99999, custo_real: { armazem: '1500.00', compras: '0.00', total: '1500.00' } })).toEqual({ valor: 1500, real: true });
    expect(custoDaOrdem({ custo_estimado: 99999, custo_real: { armazem: '0.00', compras: '0.00', total: '0.00' } })).toEqual({ valor: 99999, real: false });
    expect(custoDaOrdem({ custo_estimado: null, custo_real: { armazem: '0.00', compras: '0.00', total: '0.00' } })).toEqual({ valor: null, real: false });
    expect(emAtraso({ data_prevista: '2026-03-10', estado: 'ABERTA' }, '2026-03-20')).toBe(true);
    expect(emAtraso({ data_prevista: '2026-03-10', estado: 'CONCLUIDA' }, '2026-03-20')).toBe(false);
    expect(emAtraso({ data_prevista: '2026-03-20', estado: 'ABERTA' }, '2026-03-20')).toBe(false);
    expect(emAtraso({ data_prevista: null, estado: 'ABERTA' }, '2026-03-20')).toBe(false);
  });

  it('propõe as datas a partir do dia original, como o servidor (as mesmas fixtures à mão)', () => {
    expect(proporDatas('2026-01-31', 'MENSAL', 4)).toEqual(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
    expect(proporDatas('2028-01-31', 'MENSAL', 2)).toEqual(['2028-01-31', '2028-02-29']);
    expect(proporDatas('2026-11-30', 'TRIMESTRAL', 3)).toEqual(['2026-11-30', '2027-02-28', '2027-05-30']);
    expect(proporDatas('2028-02-29', 'ANUAL', 5)).toEqual(['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29']);
    expect(proporDatas('2026-05-15', 'BIANUAL', 2)).toEqual(['2026-05-15', '2028-05-15']);
    expect(proporDatas('2026-04-10', 'MANUAL', 5)).toEqual(['2026-04-10']);
    expect(proporDatas('2026-04-10', 'QUINZENAL', 5)).toEqual(['2026-04-10']);
    expect(proporDatas('2026-04-10', 'ANUAL', 0)).toEqual(['2026-04-10']);
    expect(proporDatas('2026-01-01', 'MENSAL', 99)).toHaveLength(60);
    expect(proporDatas('2026-02-30', 'MENSAL', 3)).toEqual([]);
    expect(proporDatas('31/01/2026', 'MENSAL', 3)).toEqual([]);
  });
});
