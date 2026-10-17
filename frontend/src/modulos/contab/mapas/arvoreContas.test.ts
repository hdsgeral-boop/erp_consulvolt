import { describe, expect, it } from 'vitest';
import { achatar, agruparPorNivel, arvorePorPrefixo, chavesExpansiveis, extratoPorConta } from './arvoreContas';
import { parametrosDetalhe } from './DetalheConta';
import { linhasVistaBalancete } from './MapaBalancete';
import type { LinhaBalancete } from '../api';

const l = (codigo: string, totalizadora = false, extra: Partial<LinhaBalancete> = {}): LinhaBalancete =>
  ({ codigo_conta: codigo, descricao: `Conta ${codigo}`, saldo_inicial: '0.00', saldo_inicial_devedor: '0.00', saldo_inicial_credor: '0.00', debito: '1.00', credito: '0.00',
    saldo_final: '1.00', saldo_devedor: '1.00', saldo_credor: '0.00', ...(totalizadora ? { totalizadora: true } : {}), ...extra }) as LinhaBalancete;

describe('árvore das contas dos mapas', () => {
  it('cada totalizadora abre até às contas de movimento (prefixo sem pontos), com níveis encaixados', () => {
    const linhas = linhasVistaBalancete([l('3', true), l('31', true), l('31.1'), l('31.2'), l('32'), l('4', true), l('43.1'), l('5')]);
    const arvore = arvorePorPrefixo(linhas);
    expect(arvore.map((n) => n.codigo_conta)).toEqual(['3', '4', '5']);
    expect(arvore[0].filhos?.map((n) => n.codigo_conta)).toEqual(['31', '32']);
    expect(arvore[0].filhos?.[0].filhos?.map((n) => n.codigo_conta)).toEqual(['31.1', '31.2']);
    expect(arvore[2].filhos).toBeUndefined();
    expect(chavesExpansiveis(arvore)).toEqual([arvore[0].chave, arvore[0].filhos![0].chave, arvore[1].chave]);
    // impressão/Excel: todas as linhas pela ordem da árvore, com a profundidade
    expect(achatar(arvore).map((n) => [n.codigo_conta, n.nivelArvore])).toEqual([['3', 0], ['31', 1], ['31.1', 2], ['31.2', 2], ['32', 1], ['4', 0], ['43.1', 1], ['5', 0]]);
  });

  it('os terceiros continuam debaixo da conta de movimento e as totalizadoras sem contas não abrem', () => {
    const arvore = arvorePorPrefixo(
      linhasVistaBalancete([l('6', true), l('9', true), l('31', false, { terceiros: [{ ...l('31'), terceiro_id: 5, nome: 'Alfa' } as never] })]),
    );
    expect(arvore.map((n) => [n.codigo_conta, n.filhos?.length ?? 0])).toEqual([['6', 0], ['9', 0], ['31', 1]]);
    const conta31 = arvore.find((n) => n.codigo_conta === '31')!;
    expect(conta31.filhos?.[0].eTerceiro).toBe(true);
  });

  it('agrupa as contas de movimento pela conta agregada do mapa (left(codigo, nivel))', () => {
    const m = agruparPorNivel([{ codigo_conta: '311' }, { codigo_conta: '312' }, { codigo_conta: '43.1' }], 2);
    expect([...m.entries()].map(([k, v]) => [k, v.length])).toEqual([['31', 2], ['43', 1]]);
  });

  it('extracto por conta: subtotais de débito, crédito e movimento por conta', () => {
    const r = extratoPorConta([
      { codigo_conta: '62', descricao_conta: 'B', tipo_dc: 'C', valor: '5.10' },
      { codigo_conta: '31', descricao_conta: 'A', tipo_dc: 'D', valor: '10.20' },
      { codigo_conta: '31', descricao_conta: 'A', tipo_dc: 'C', valor: '0.10' },
    ]);
    expect(r.map((c) => [c.codigo_conta, c.movimentos, c.debito, c.credito, c.saldo])).toEqual([
      ['31', 2, '10.20', '0.10', '10.10'],
      ['62', 1, '0.00', '5.10', '-5.10'],
    ]);
  });

  it('detalhe da conta: extracto com os filtros do mapa que se aplicam', () => {
    expect(parametrosDetalhe({ conta: '311', terceiroId: 4, inicio: '2026-01-01', fim: '2026-12-31', filtros: { diario_id: 2, incluir_classe_9: true, so_movimento: true, nivel: 2 } })).toEqual({
      filtro_contas: '311', terceiro_id: 4, data_inicio: '2026-01-01', data_fim: '2026-12-31', tipo: 'todos', diario_id: 2, incluir_classe_9: 1,
    });
  });
});
