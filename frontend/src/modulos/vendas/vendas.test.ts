import { barrasMensais, emCentimos } from './relatorios/kpis';
import { nomeDoContentDisposition as nomeFicheiro } from '@/api/cliente';
import { alocacoesParaPedido, distribuirMontante, totalAlocado } from './recibos/alocacao';
import { accoesRecibo, rotuloMeio } from './recibos/tipos';
import { corpoProduto, produtoParaFormulario, type ProdutoFicha } from './produtos/formularioProduto';
import { separadorFaturacao } from './Faturacao';
import { accoesAgt, svgComoDataUrl } from './agt/accoesDocumento';

describe('indicadores de vendas (resumo do servidor)', () => {
  it('converte texto decimal em cêntimos sem erros de vírgula flutuante', () => {
    expect(emCentimos('1234.56')).toBe(123456);
    expect(emCentimos('-100.5')).toBe(-10050);
    expect(emCentimos('0.1')).toBe(10);
    expect(emCentimos(null)).toBe(0);
  });

  it('calcula as barras mensais face ao maior valor absoluto', () => {
    expect(
      barrasMensais([
        { mes: '2026-01', liquido: '50.00', bruto: '57.00', documentos: 2 },
        { mes: '2026-02', liquido: '200.00', bruto: '228.00', documentos: 1 },
        { mes: '2026-03', liquido: '-10.00', bruto: '-11.40', documentos: 1 },
      ]),
    ).toEqual([
      { mes: '2026-01', bruto: '57.00', percentagem: 25, negativo: false },
      { mes: '2026-02', bruto: '228.00', percentagem: 100, negativo: false },
      { mes: '2026-03', bruto: '-11.40', percentagem: 5, negativo: true },
    ]);
    expect(barrasMensais([])).toEqual([]);
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
    expect(accoesRecibo({ estado: 'EMITIDO', contabilizado: false }, () => true)).toEqual({ alocar: false, contabilizar: true, descontabilizar: false, anular: true });
    expect(accoesRecibo({ estado: 'ANULADO', contabilizado: false }, () => true)).toEqual({ alocar: false, contabilizar: false, descontabilizar: false, anular: false });
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

describe('acções AGT avançadas no detalhe da venda', () => {
  const todas = () => true;
  const fe = (o: Record<string, unknown>) => ({ serie: 'A', numero: 1, estado: 'PRONTO', regime: true, erros: [], avisos: [], selado_em: '2026-01-10T10:00:00Z', envio: 'VALIDO', ...o });

  it('revalidar só para erros locais por enviar ou inválidos/rejeitados pela AGT, com vendas_fat_emitir', () => {
    expect(accoesAgt({ estado: 'PENDENTE', faturacao_eletronica: fe({ envio: 'REJEITADO' }) }, todas).revalidar).toBe(true);
    expect(accoesAgt({ estado: 'PENDENTE', faturacao_eletronica: fe({ envio: 'INVALIDO' }) }, todas).revalidar).toBe(true);
    expect(accoesAgt({ estado: 'PENDENTE', faturacao_eletronica: fe({ estado: 'COM_ERROS', envio: null }) }, todas).revalidar).toBe(true);
    expect(accoesAgt({ estado: 'PENDENTE', faturacao_eletronica: fe({ estado: 'COM_ERROS', envio: 'VALIDO' }) }, todas).revalidar).toBe(false);
    expect(accoesAgt({ estado: 'PENDENTE', faturacao_eletronica: fe({ envio: 'VALIDO' }) }, todas).revalidar).toBe(false);
    expect(accoesAgt({ estado: 'PENDENTE', faturacao_eletronica: fe({ envio: 'REJEITADO', regime: false }) }, todas).revalidar).toBe(false);
    expect(accoesAgt({ estado: 'PENDENTE', faturacao_eletronica: fe({ envio: 'REJEITADO' }) }, (p) => p !== 'vendas_fat_emitir').revalidar).toBe(false);
  });

  it('QR e pedido assinado só para documentos do regime já selados, cada um com a sua permissão', () => {
    expect(accoesAgt({ estado: 'PAGO', faturacao_eletronica: fe({}) }, todas)).toMatchObject({ qr: true, pedidoAssinado: true });
    expect(accoesAgt({ estado: 'PAGO', faturacao_eletronica: fe({ selado_em: null }) }, todas)).toMatchObject({ qr: false, pedidoAssinado: false });
    expect(accoesAgt({ estado: 'PAGO', faturacao_eletronica: undefined }, todas)).toEqual({ revalidar: false, qr: false, pedidoAssinado: false });
    expect(accoesAgt({ estado: 'PAGO', faturacao_eletronica: fe({}) }, (p) => p === 'vendas_faturacao_view')).toMatchObject({ qr: true, pedidoAssinado: false });
  });

  it('o SVG do QR vai para um data URL codificado', () => {
    expect(svgComoDataUrl('<svg a="1"/>')).toBe('data:image/svg+xml;charset=utf-8,%3Csvg%20a%3D%221%22%2F%3E');
  });
});
