import { describe, expect, it } from 'vitest';
import type { MapeamentoEmFalta } from '../api';
import { linhasComErro } from './ImportarExcel';
import { chaveMapeamento, corpoMapeamentos } from './MapeamentosEmFalta';
import { validarTabelaIrt } from './TabelaIrt';
import { linhasRecibo } from './ReciboSalario';

describe('tabela de IRT (decisão 1)', () => {
  const ok = [{ max: 150000, taxa: 0, fixo: 0, excesso: 0 }, { max: 200000, taxa: 16, fixo: 12500, excesso: 150000 }, { max: null, taxa: 25, fixo: 20000, excesso: 200000 }];
  it('aceita limites crescentes com o último aberto', () => expect(validarTabelaIrt(ok)).toBeNull());
  it('recusa limites não crescentes, último fechado e taxa inválida', () => {
    expect(validarTabelaIrt([ok[0], { ...ok[1], max: 100000 }, ok[2]])).toMatch(/crescentes/);
    expect(validarTabelaIrt([ok[0], ok[1], { ...ok[2], max: 300000 }])).toMatch(/último/);
    expect(validarTabelaIrt([ok[0], { ...ok[1], taxa: 120 }, ok[2]])).toMatch(/Taxa/);
    expect(validarTabelaIrt([ok[2]])).toMatch(/dois/);
  });
});

describe('assistente de mapeamentos em falta (A-10)', () => {
  const lista: MapeamentoEmFalta[] = [
    { tipo: 'RUBRICA', descricao: 'Rubrica X', infotipo_salarial_id: 5, rubrica: 'X', tipo_organizacao_id: 2, avencado: false },
    { tipo: 'SISTEMA', descricao: 'NET_PAY_CREDIT', codigo: 'NET_PAY_CREDIT', tipo_organizacao_id: 2, avencado: true },
    { tipo: 'SISTEMA', descricao: 'IRT_CREDIT', codigo: 'IRT_CREDIT', tipo_organizacao_id: 2, avencado: false },
  ];
  it('só envia as contas escolhidas, com a coluna avençado sem tipo de organização', () => {
    const contas = { [chaveMapeamento(lista[0])]: '7211', [chaveMapeamento(lista[1])]: '3611' };
    expect(corpoMapeamentos(lista, contas)).toEqual({
      rubricas: [{ infotipo_salarial_id: 5, tipo_organizacao_id: 2, avencado: false, numero_conta: '7211' }],
      sistema: [{ codigo: 'NET_PAY_CREDIT', tipo_organizacao_id: null, avencado: true, numero_conta: '3611' }],
    });
  });
});

describe('importações e recibo', () => {
  it('junta e ordena as linhas rejeitadas e recusadas', () => {
    expect(linhasComErro({ rejeitadas: [{ linha: 5, motivo: 'a' }], erros: [{ linha: 2, motivo: 'b' }] }).map((l) => l.linha)).toEqual([2, 5]);
  });
  it('recibo: sem rubricas informativas, com INSS e IRT nas linhas de desconto', () => {
    const l = linhasRecibo({
      colaborador_id: 1, tipo_organizacao_id: 1, unidade_negocio_id: null, centro_custo_id: null, avencado: false, reformado: false, dias_contrato: '22', dias_trabalhados: '22',
      bruto: '100', base_inss: '100', inss_trabalhador: '3', inss_patronal: '8', isencoes: '0', base_irt: '97', irt: '1', descontos: '0', liquido: '96', avisos: [], modo_calculo: 'ATUAL',
      rubricas: [{ nome: 'Sal. Base', tipo: 'VENCIMENTO', valor: '100', infotipo_id: 7 }, { nome: 'Dias', tipo: 'OUTROS', valor: '22', infotipo_id: 8, informativa: true }],
    }, { taxa_inss_trabalhador: 3 });
    expect(l.map((x) => x.nome)).toEqual(['Salário Base', 'Segurança Social — INSS (3%)', 'Retenção na fonte — IRT Grupo A']);
    expect(l[0]).toMatchObject({ cod: '007', v: 100 });
  });
});
