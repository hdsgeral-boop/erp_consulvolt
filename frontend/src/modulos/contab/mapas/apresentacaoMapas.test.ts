import { describe, expect, it } from 'vitest';
import type { Balanco, LinhaBalancete } from '../api';
import { analiseHorizontal, analiseVertical, classeLinhaDemonstracao } from '../comum/Demonstracao';
import { linhasBalanco } from '../comum/demonstracoes';
import { classeLinhaBalancete, linhasVistaBalancete } from './MapaBalancete';

const linha = (codigo: string, extra: Partial<LinhaBalancete> = {}): LinhaBalancete => ({
  codigo_conta: codigo,
  descricao: `Conta ${codigo}`,
  saldo_inicial: '0.00',
  saldo_inicial_devedor: '0.00',
  saldo_inicial_credor: '0.00',
  debito: '10.00',
  credito: '0.00',
  saldo_final: '10.00',
  saldo_devedor: '10.00',
  saldo_credor: '0.00',
  ...extra,
});

describe('apresentação do balancete (legado js/ui_reports.js:530-650)', () => {
  it('totalizadoras Σ com a classe de 1.º nível, contas filhas recuadas e terceiros como sub-linhas', () => {
    const v = linhasVistaBalancete([
      linha('1', { totalizadora: true }),
      linha('11', { totalizadora: true }),
      linha('111', { terceiros: [{ ...linha('x'), terceiro_id: 7, nome: 'Cliente A' }] }),
      linha('2'),
    ]);
    expect(classeLinhaBalancete(v[0])).toBe('erp-bal-totalizadora erp-bal-classe');
    expect(classeLinhaBalancete(v[1])).toBe('erp-bal-totalizadora');
    expect(classeLinhaBalancete(v[2])).toBe('erp-bal-movimento erp-bal-filha');
    expect(classeLinhaBalancete(v[3])).toBe('erp-bal-movimento');
    expect(v[2].filhos).toHaveLength(1);
    expect(v[2].filhos?.[0]).toMatchObject({ codigo_conta: '111', descricao: 'Cliente A', eTerceiro: true, chave: '111|t7' });
    expect(classeLinhaBalancete(v[2].filhos![0])).toBe('erp-bal-terceiro');
    expect(v[3].filhos).toBeUndefined();
    expect(new Set(v.map((l) => l.chave)).size).toBe(v.length);
  });
});

describe('demonstrações: AV % / AH % e realces do legado', () => {
  it('AV % sobre a base do agregado; sem base → «-»', () => {
    expect(analiseVertical('25.00', '100.00')).toBe('25.0%');
    expect(analiseVertical('25.00', '0.00')).toBe('-');
    expect(analiseVertical(undefined, '100.00')).toBe('-');
  });

  it('AH % com sinal; ano anterior zero → «+100.0%»; ambos zero → «-»', () => {
    expect(analiseHorizontal('150.00', '100.00')).toEqual({ texto: '+50.0%', sentido: 'sobe' });
    expect(analiseHorizontal('50.00', '-100.00')).toEqual({ texto: '+150.0%', sentido: 'sobe' });
    expect(analiseHorizontal('80.00', '100.00')).toEqual({ texto: '-20.0%', sentido: 'desce' });
    expect(analiseHorizontal('10.00', '0.00')).toEqual({ texto: '+100.0%', sentido: 'sobe' });
    expect(analiseHorizontal('0.00', '0.00')).toEqual({ texto: '-', sentido: 'igual' });
  });

  it('Balanço com os rótulos do legado e as bases da análise vertical', () => {
    const seccao = (atual: string) => ({ linhas: [{ nota: '4', descricao: 'Imobilizado', atual, anterior: '0.00' }], total: { atual, anterior: '0.00', descricao: 'Total API' } });
    const b = {
      seccoes: { activo_nao_corrente: seccao('10.00'), activo_corrente: seccao('5.00'), capital_proprio: seccao('7.00'), passivo_nao_corrente: seccao('0.00'), passivo_corrente: seccao('8.00') },
      totais: { atual: { activo: '15.00', passivo: '8.00', capital_proprio_passivo: '15.00' }, anterior: { activo: '0.00', passivo: '0.00', capital_proprio_passivo: '0.00' } },
    } as unknown as Balanco;
    const l = linhasBalanco(b);
    expect(l.find((x) => x.chave === 'anc-t')).toMatchObject({ descricao: 'ACTIVO NÃO CORRENTE', estilo: 'subseccao' });
    expect(l.find((x) => x.chave === 'anc-0')).toMatchObject({ base: '10.00' });
    expect(l.find((x) => x.chave === 'anc-s')).toMatchObject({ descricao: 'SUBTOTAL ACTIVO NÃO CORRENTE', base: '15.00' });
    expect(l.find((x) => x.chave === 'total-activo')).toMatchObject({ descricao: 'TOTAL DO ACTIVO' });
    expect(l.find((x) => x.chave === 'total-cpp')).toMatchObject({ descricao: 'TOTAL DO CAPITAL E PASSIVO' });
    expect(classeLinhaDemonstracao(l.find((x) => x.chave === 'anc-t')!)).toBe('erp-dem-subseccao');
    expect(classeLinhaDemonstracao(l.find((x) => x.chave === 'total-activo')!)).toBe('erp-dem-total');
    expect(classeLinhaDemonstracao({ chave: 'rl', tipo: 'total', descricao: 'RL', estilo: 'final' })).toBe('erp-dem-total erp-dem-final');
    expect(classeLinhaDemonstracao({ chave: 'tp', tipo: 'subtotal', descricao: 'T', estilo: 'proveitos' })).toBe('erp-dem-proveitos');
  });
});
