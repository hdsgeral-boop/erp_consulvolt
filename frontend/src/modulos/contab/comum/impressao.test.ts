import { describe, expect, it } from 'vitest';
import { filtrosDosParametros, periodoDosParametros, tabelaDeColunas } from './impressao';
import { documentoEvolucao } from '../mapas/MapaEvolucao';
import type { Evolucao } from '../api';

describe('impressão dos mapas contabilísticos', () => {
  it('descreve o período dos parâmetros do mapa', () => {
    expect(periodoDosParametros({ data_inicio: '2026-01-01', data_fim: '2026-03-31' })).toBe('01/01/2026 a 31/03/2026');
    expect(periodoDosParametros({ data_fim: '2026-12-31' })).toBe('Até 31/12/2026');
    expect(periodoDosParametros({ ano: 2026 })).toBe('Ano 2026');
    expect(periodoDosParametros(null)).toBeUndefined();
  });

  it('descreve os filtros (contas, nível, identificadores e opções)', () => {
    expect(filtrosDosParametros({ filtro_contas: '31*', nivel: 3, diario_id: 4, por_terceiro: 1, excluir_estornos: 1 }, { diario_id: 'OD' })).toEqual([
      'Contas: 31*',
      'Nível: 3',
      'Diário: OD',
      'Por terceiro',
      'Excluir estornos',
    ]);
    expect(filtrosDosParametros({ terceiro_id: 9 })).toEqual(['Terceiro: n.º 9']);
  });

  it('tabela de colunas Ant Design: exclui acções, usa valorImpressao e totalImpressao', async () => {
    const html = await tabelaDeColunas<{ c: string; v: string }>(
      [
        { title: 'Conta', dataIndex: 'c', valorImpressao: (l) => `>${l.c}` },
        { title: 'Valor', dataIndex: 'v', align: 'right', totalImpressao: () => '99,00' },
        { title: '', key: 'a', render: () => 'botão' },
      ],
      [{ c: '11', v: '1.00' }],
    );
    expect(html).toContain('&gt;11');
    expect(html).not.toContain('botão');
    expect(html).toContain('99,00');
    expect((html.split('<thead>')[1].match(/<th/g) ?? []).length).toBe(2);
  });

  it('evolução mensal: conta + descrição + meses + saldo, com terceiros indentados', () => {
    const d = {
      ano: 2026,
      meses_ativos: [1, 2],
      contas: [{ codigo_conta: '62', descricao: 'Serviços', meses: { '1': '10.00', '2': '5.00' }, saldo: '15.00', terceiros: [{ terceiro_id: 1, terceiro: 'Fornecedor', meses: { '1': '10.00' }, saldo: '10.00' }] }],
      totais: { '1': '10.00', '2': '5.00' },
      saldo_total: '15.00',
    } as unknown as Evolucao;
    const html = documentoEvolucao(d, [1, 2]);
    expect((html.split('<thead>')[1].match(/<th/g) ?? []).length).toBe(5);
    expect(html).toContain('· Fornecedor');
    expect(html).toContain('Totais');
  });
});
