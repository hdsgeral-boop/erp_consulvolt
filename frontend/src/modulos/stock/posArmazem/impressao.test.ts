import { describe, expect, it } from 'vitest';
import type { CabecalhoTalao, PreferenciasImpressao } from '@/modulos/pos/comum/impressao';
import { htmlDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import type { GuiaSaida } from '../comum/tipos';
import {
  conclusaoBalcao, conclusaoPicking, dadosGuiaSaidaPOS, fraseConclusao, htmlTalaoLevantamento, mensagemConclusao, totalConclusao, totalLinha, type ConclusaoPOS,
} from './impressao';

const guia = {
  id: 9, numero_documento: 'GE POS 2026/0009', data: '2026-11-06', tipo: 'VENDA_BALCAO', tipo_original: null, terceiro_id: null, armazem_id: 5,
  area_rececao: 'Cliente de balcão', estado: 'CONCLUIDO', venda_relacionada_id: null, contabilizado: true, observacoes: null, criado_por: 'ana',
  numero_lan_contabilizacao: null, anulado_em: null, motivo_anulacao: null, criado_em: '2026-11-06T10:42:00+01:00',
  linhas: [
    { id: 1, produto_id: 1, quantidade: '3.000', valor_kz: '1800.00', custo_unitario_kz: '600.00', preco_unitario_kz: '1000.00' },
    { id: 2, produto_id: 2, quantidade: '2.500', valor_kz: '25.00', custo_unitario_kz: '10.00', preco_unitario_kz: '19.90' },
  ],
} as GuiaSaida;
const catalogo = [{ produto_id: 1, codigo: 'CAB-01', nome: 'Cabo eléctrico' }, { produto_id: 2, codigo: 'FIT-03', nome: 'Fita isoladora' }];

const cabecalho: CabecalhoTalao = { empresa: 'Consulvolt, Lda', nif: '5417000060', morada: 'Rua A, Luanda', telefone: '923111222', operador: 'ana' };
const prefs: PreferenciasImpressao = { formato: 'TERMICO', largura: 80, automatico: true, consulta: 'PREVISUALIZAR', rodape: 'Obrigado pela preferência!' };

describe('POS de armazém — impressos da conclusão', () => {
  it('totais à mão: 3 × 1000,00 + 2,5 × 19,90 = 3 049,75', () => {
    const c = conclusaoBalcao(guia, catalogo, 'Central', 'Ana');
    expect(c.linhas.map(totalLinha)).toEqual(['3000.00', '49.75']);
    expect(totalConclusao(c.linhas)).toBe('3049.75');
    expect(totalConclusao([{ codigo: null, descricao: 'x', quantidade: '1', preco: null }])).toBeNull();
  });

  it('conclusão do balcão: guia, hora, destinatário e artigos do catálogo (nunca o custo)', () => {
    const c = conclusaoBalcao(guia, catalogo, 'Central', 'Ana');
    expect([c.numero, c.hora, c.destinatario, c.armazem, c.operador]).toEqual(['GE POS 2026/0009', '10:42', 'Cliente de balcão', 'Central', 'Ana']);
    expect(c.linhas[0]).toEqual({ codigo: 'CAB-01', descricao: 'Cabo eléctrico', quantidade: '3.000', preco: '1000.00' });
    const cliente = conclusaoBalcao({ ...guia, area_rececao: 'Beta' }, catalogo, null, null, { nome: ' Beta, SA ', nif: '5000000001' });
    expect([cliente.destinatario, cliente.nif]).toEqual(['Beta, SA', '5000000001']);
  });

  it('frases da voz e mensagens do legado, sem acentos corrompidos', () => {
    const c = conclusaoBalcao(guia, catalogo, 'Central', 'Ana');
    expect(fraseConclusao(c)).toBe('Atendimento de Cliente de balcão concluído com sucesso. Guia GE POS 2026/0009 gerada.');
    expect(mensagemConclusao(c)).toEqual({ titulo: 'Saída concluída com sucesso!', texto: 'Guia GE POS 2026/0009 emitida.' });
    const p: ConclusaoPOS = { ...c, tipo: 'PICKING', destinatario: 'Beta, SA', numero: 'GR A/12' };
    expect(fraseConclusao(p)).toBe('Picking concluído e expedido para Beta, SA.');
    expect(mensagemConclusao(p).titulo).toBe('Picking e expedição validados com sucesso!');
  });

  it('guia de saída A4: colunas do legado, total geral, aviso e assinaturas; sem custo', () => {
    const d = dadosGuiaSaidaPOS(conclusaoBalcao(guia, catalogo, 'Central', 'Ana'));
    expect(d.tipo).toBe('Guia de Saída');
    expect(d.colunas?.map((c) => c.titulo)).toEqual(['#', 'Código', 'Descrição do Artigo', 'Quantidade', 'Preço Unit.', 'Total Líquido']);
    expect(d.linhas?.[1]).toEqual([2, 'FIT-03', 'Fita isoladora', '2,5', '19,90', '49,75']);
    expect(d.totais).toEqual({ total: '3049.75', rotuloTotal: 'TOTAL GERAL' });
    expect(d.assinaturas).toEqual(['O Operador do Armazém', 'O Cliente / Recebedor']);
    const html = htmlDocumentoComercial(d);
    expect(html).toContain('Este documento não serve de fatura');
    expect(html).toContain('Levantamento Imediato no POS');
    expect(html).not.toContain('600');
  });

  it('guia sem preços (guias antigas): só quantidades, sem total', () => {
    const sem = { ...guia, linhas: guia.linhas?.map((l) => ({ ...l, preco_unitario_kz: null })) } as GuiaSaida;
    const d = dadosGuiaSaidaPOS(conclusaoBalcao(sem, catalogo, 'Central', 'Ana'));
    expect(d.colunas?.map((c) => c.titulo)).toEqual(['#', 'Código', 'Descrição do Artigo', 'Quantidade']);
    expect(d.totais).toBeNull();
  });

  it('talão de levantamento: número da guia, artigos, total e rodapé do balcão', () => {
    const html = htmlTalaoLevantamento(conclusaoBalcao(guia, catalogo, 'Central', 'Ana'), cabecalho, prefs);
    for (const t of ['Talão de Levantamento / Picking', 'Documento: GE POS 2026/0009', 'Destinatário: Cliente de balcão', 'Operação: Logística / Balcão', 'CAB-01 · Cabo eléctrico', 'Fita isoladora',
      'TOTAL GERAL', 'Obrigado pelo seu atendimento no nosso Balcão de Armazém!', 'Consulvolt, Lda', 'Operador: Ana']) {
      expect(html).toContain(t);
    }
    expect(html).toMatch(/3\D049,75 Kz/);
    expect(html).not.toContain('Obrigado pela preferência');
  });

  it('picking: a guia de remessa usa os nomes da lista de recolha e o cliente da fila', () => {
    const gr = { numero_documento: 'GR A/2026/3', data_emissao: '2026-11-06', criado_em: '2026-11-06T15:05:00+01:00', itens_venda: [
      { produto_id: 1, descricao: 'Cabo', quantidade: '5.000', preco_unitario: '1000.00' },
      { produto_id: 7, descricao: 'Montagem', quantidade: '1.000', preco_unitario: '500.00' },
    ] };
    const lista = { encomenda: { id: 4, numero_documento: 'NE A/2026/8', cliente_id: 2, estado: null, total_bruto: '6270.00' },
      linhas: [{ item_id: 1, produto_id: 1, codigo: 'CAB-01', nome: 'Cabo eléctrico', quantidade: '5.000', quantidade_entregue: '0.000', por_expedir: '5.000', movimenta_stock: true, stock_armazem: '10.000', disponivel: true }] };
    const c = conclusaoPicking(gr, lista, ' Beta, SA ', 'Central', 'Ana');
    expect([c.tipo, c.numero, c.encomenda, c.destinatario, c.hora]).toEqual(['PICKING', 'GR A/2026/3', 'NE A/2026/8', 'Beta, SA', '15:05']);
    expect(c.linhas.map((l) => [l.codigo, l.descricao])).toEqual([['CAB-01', 'Cabo eléctrico'], [null, 'Montagem']]);
    expect(totalConclusao(c.linhas)).toBe('5500.00');
    expect(conclusaoPicking(gr, lista, null, null, null).destinatario).toBe('Cliente Geral');
  });
});
