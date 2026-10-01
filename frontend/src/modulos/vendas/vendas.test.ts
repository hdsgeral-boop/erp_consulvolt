import { calcularKpis } from './relatorios/kpis';
import { nomeFicheiro } from './relatorios/Relatorios';
import { alocacoesParaPedido, distribuirMontante, totalAlocado } from './recibos/alocacao';
import { accoesRecibo, rotuloMeio } from './recibos/tipos';
import { corpoProduto, produtoParaFormulario, type ProdutoFicha } from './produtos/formularioProduto';
import { separadorFaturacao } from './Faturacao';
import type { DocumentoVenda } from './api';

const doc = (o: Partial<DocumentoVenda>) =>
  ({ id: 1, tipo_documento: 'FT', numero_documento: 'FT 1', data_emissao: '2026-01-10', cliente_id: 1, cliente: { id: 1, nome: 'A', nif: null }, total_liquido: '100.00', total_imposto: '14.00', total_bruto: '114.00', valor_pendente: '0.00', estado: 'PAGO', ...o }) as DocumentoVenda;

describe('indicadores de vendas', () => {
  it('soma FT e FR, subtrai NC e ignora anulados e não fiscais', () => {
    const k = calcularKpis([
      doc({ id: 1, valor_pendente: '114.00', estado: 'PENDENTE' }),
      doc({ id: 2, tipo_documento: 'FR', data_emissao: '2026-02-01', cliente_id: 2, cliente: { id: 2, nome: 'B', nif: null }, total_liquido: '200.00', total_imposto: '28.00', total_bruto: '228.00' }),
      doc({ id: 3, tipo_documento: 'NC', total_liquido: '50.00', total_imposto: '7.00', total_bruto: '57.00' }),
      doc({ id: 4, estado: 'ANULADO', total_bruto: '999.00' }),
      doc({ id: 5, tipo_documento: 'OR', total_bruto: '500.00' }),
    ]);
    expect(k).toMatchObject({ liquido: 250, imposto: 35, bruto: 285, notasCredito: 57, aReceber: 114, documentos: 3 });
    expect(k.porMes).toEqual([
      { mes: '2026-01', liquido: 50, bruto: 57 },
      { mes: '2026-02', liquido: 200, bruto: 228 },
    ]);
    expect(k.topClientes.map((c) => c.nome)).toEqual(['B', 'A']);
    expect(k.pendentes.map((d) => d.id)).toEqual([1]);
  });
});

describe('recibos', () => {
  const facturas = [
    { id: 2, data_emissao: '2026-02-01', valor_pendente: '100.00' },
    { id: 1, data_emissao: '2026-01-01', valor_pendente: '50.10' },
  ];

  it('distribui da factura mais antiga para a mais recente', () => {
    expect(distribuirMontante(facturas, 120)).toEqual({ alocacoes: { 1: 50.1, 2: 69.9 }, excedente: 0 });
    expect(distribuirMontante(facturas, 200)).toEqual({ alocacoes: { 1: 50.1, 2: 100 }, excedente: 49.9 });
    expect(distribuirMontante(facturas, 0).alocacoes).toEqual({});
  });

  it('prepara as alocações e o total sem erros de vírgula flutuante', () => {
    expect(alocacoesParaPedido({ 1: 0.1, 2: 0.2, 3: 0, 4: null })).toEqual([{ venda_id: 1, montante: 0.1 }, { venda_id: 2, montante: 0.2 }]);
    expect(totalAlocado({ 1: 0.1, 2: 0.2 })).toBe(0.3);
  });

  it('acções e meios de pagamento', () => {
    expect(accoesRecibo({ estado: 'EMITIDO', contabilizado: false }, () => true)).toEqual({ contabilizar: true, descontabilizar: false, anular: true });
    expect(accoesRecibo({ estado: 'ANULADO', contabilizado: false }, () => true)).toEqual({ contabilizar: false, descontabilizar: false, anular: false });
    expect(rotuloMeio('Transferencia')).toBe('Transferência');
    expect(rotuloMeio(null)).toBe('—');
  });
});

describe('produtos', () => {
  const ficha: ProdutoFicha = {
    id: 1, codigo: 'P1', nome: 'Bomba', preco_unitario: '10.50', taxa_imposto: '14.0000', categoria_produto_id: 3, movimenta_stock: true, e_servico: false,
    e_quarto: false, e_ativo_imobilizado: false, bloqueado: false, unidade_fe: null, tipo_operacao_fe: null, codigo_isencao_fe: null,
    contas: { venda: '62', custo: null, compra: null, inventario: null, iva_liquidado: null, iva_dedutivel: null, quebra: null, sobra: null, ativo: null },
  };

  it('copiar limpa o código e marca o nome', () => {
    expect(produtoParaFormulario(ficha, true)).toMatchObject({ codigo: '', nome: 'Bomba (cópia)', preco_unitario: 10.5, taxa_imposto: 14, codigo_conta: '62' });
  });

  it('o corpo só leva os campos de quartos/lavandaria quando activos', () => {
    const c = corpoProduto({ ...produtoParaFormulario(ficha), codigo_isencao_fe: 'm10', preco_por_dia: 5 });
    expect(c).toMatchObject({ codigo: 'P1', codigo_isencao_fe: 'M10', codigo_conta: '62', conta_custo: null, lavandaria_ativa: false });
    expect(c).not.toHaveProperty('preco_por_dia');
    expect(corpoProduto({ e_quarto: true, preco_por_dia: 5 })).toMatchObject({ preco_por_dia: 5 });
  });
});

describe('navegação e ficheiros', () => {
  it('identifica o separador da facturação pelo caminho', () => {
    expect(separadorFaturacao('/m/vendas/vendas_faturacao')).toBe('documentos');
    expect(separadorFaturacao('/m/vendas/vendas_faturacao/recibos/5')).toBe('recibos');
    expect(separadorFaturacao('/m/vendas/vendas_faturacao/agt')).toBe('agt');
    expect(separadorFaturacao('/m/vendas/vendas_faturacao/123')).toBe('documentos');
  });

  it('lê o nome do ficheiro do Content-Disposition', () => {
    expect(nomeFicheiro('attachment; filename="SAFT_AO_2026.xml"', 'x.xml')).toBe('SAFT_AO_2026.xml');
    expect(nomeFicheiro(undefined, 'x.xml')).toBe('x.xml');
  });
});
