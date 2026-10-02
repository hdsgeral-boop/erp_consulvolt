import { describe, expect, it } from 'vitest';
import { formatarKz } from '@/utilitarios/formatacao';
import { documentoRelatorios, documentoSessao, linhasContagem, pedidoRelatorioX, pedidoSessao, tabelaMeios } from './documentos';
import type { PainelRelatorios, RelatorioX, SessaoPOS } from './tipos';

/** Conta as colunas do cabeçalho da n-ésima tabela de impressão do HTML. */
function colunas(html: string, n = 0): number {
  const thead = html.split('<thead>')[n + 1]?.split('</thead>')[0] ?? '';
  return (thead.match(/<th/g) ?? []).length;
}

const sessao = {
  id: 7,
  codigo_sessao: 'T01-2026-0001',
  numero_z: 'Z-T01-2026-0001',
  codigo_terminal: 'T01',
  nome_terminal: 'Loja',
  nome_operador: 'Operador',
  aberto_em: '2026-09-01T08:00:00',
  fechado_em: '2026-09-01T18:00:00',
  fechado_por: 'operador',
  estado: 'FECHADA',
  estado_contabilizacao: 'PENDENTE',
  estado_desvio: 'SEM_DESVIO',
  estado_liquidacao: 'POR_PRESTAR',
  numero_vendas: 2,
  total_vendas: '2000.00',
  fundo_maneio_abertura: '5000.00',
  numerario_esperado: '5500.00',
  numerario_contado: '5500.00',
  desvio: '0.00',
  totais_por_metodo: [
    { meio_id: 'num', tipo: 'NUMERARIO', nome: 'Numerário', quantidade: 1, valor: '500.00', conta_transitoria: '4511' },
    { meio_id: 'tpa', tipo: 'TPA', nome: 'Multicaixa', quantidade: 1, valor: '1500.00', conta_transitoria: '4512' },
  ],
  fechos_tpa: [],
  contagens_numerario: { '1000': 5, '500': 1 },
  vendas: [],
} as unknown as SessaoPOS;

describe('documentos A4 do POS', () => {
  it('ordena a contagem de numerário da maior nota para a menor', () => {
    expect(linhasContagem({ '500': 1, '5000': 2, '1000': 3 }).map((l) => l.d)).toEqual([5000, 1000, 500]);
    expect(linhasContagem(null)).toEqual([]);
  });

  it('totais por meio somam operações e valor', () => {
    const html = tabelaMeios(sessao.totais_por_metodo ?? []);
    expect(colunas(html)).toBe(5);
    expect(html).toContain('<tfoot>');
    expect(html).toContain(formatarKz('2000.00'));
  });

  it('o detalhe da sessão tem resumo, meios, TPA, contagem e vendas; não escreve a empresa à mão', () => {
    const html = documentoSessao(sessao);
    expect(html).toContain('Por meio de pagamento');
    expect(html).toContain('Talões TPA');
    expect(html).toContain('Contagem de numerário');
    expect(html).toContain('Vendas (0)');
    expect(html).not.toContain('imp-empresa');
    const p = pedidoSessao(sessao);
    expect(p.titulo).toBe('Sessão POS T01-2026-0001 · Z-T01-2026-0001');
    expect(p.periodo).toContain(' a ');
  });

  it('relatório X em A4', () => {
    const x = {
      sessao: { id: 7, codigo_sessao: 'T01-2026-0001', codigo_terminal: 'T01', nome_terminal: 'Loja', estado: 'ABERTA', aberto_em: '2026-09-01T08:00:00', fundo_maneio_abertura: '5000.00', nome_operador: 'Op' },
      numero_vendas: 1,
      total_vendas: '2000.00',
      totais_por_metodo: [],
      transferencias: [{ venda_id: 1, numero_documento: 'FR 1', valor: '100.00', referencia: 'C1', meio_id: 'tr' }],
      vendas_numerario: '500.00',
      numerario_esperado: '5500.00',
      lavandaria: { numero_recibos: 0, total_recibos: '0', numero_faturas: 0, total_faturas: '0', movimento: false },
      emitido_em: '2026-09-01T12:00:00',
    } as unknown as RelatorioX;
    const p = pedidoRelatorioX(x);
    expect(p.titulo).toBe('Relatório X · T01-2026-0001');
    expect(String(p.conteudo)).toContain('Transferências');
  });

  it('relatórios POS: a tabela dos fechos Z tem 12 colunas (sai em paisagem)', () => {
    const r = {
      kpis: { facturacao: '0', documentos: 0, ticket_medio: '0', desvios: '0', sessoes_abertas: 0, desvios_por_deliberar: 0, sessoes_por_integrar: 0, sessoes_por_prestar: 0, diferencas_tpa: 0 },
      por_meio: [],
      zs: [],
      diferencas_tpa: [],
      por_produto: [],
      por_terminal: [],
      por_operador: [],
    } as PainelRelatorios;
    const html = documentoRelatorios(r);
    // 1.ª tabela = indicadores (pares, sem thead); a 1.ª com cabeçalho é a dos fechos Z
    expect(colunas(html, 0)).toBe(12);
    expect(html).toContain('Sem diferenças entre os talões TPA e o sistema.');
  });
});
