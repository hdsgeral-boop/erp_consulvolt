import { describe, expect, it } from 'vitest';
import { construirDocumento } from '@/componentes/impressao';
import { dadosEncomendaCompra, dadosFaturaCompra } from '@/modulos/compras/comum/impressao';
import type { DocumentoVenda } from '../api';
import { htmlDocumentoComercial, pedidoDocumentoComercial, resumoImpostos, valorPorExtenso } from './documentoComercial';
import { dadosDocumentoVenda } from './documentoVenda';
import { dadosRecibo } from './documentoRecibo';

/** Os separadores de milhares do Intl são espaços especiais: normalizam-se para comparar. */
const esp = (s: string) => s.replace(/[  ]/g, ' ');

const ft: DocumentoVenda = {
  id: 7,
  tipo_documento: 'FT',
  numero_documento: 'FT A/2026/12',
  data_emissao: '2026-09-30',
  cliente: { id: 1, nome: 'Cliente Demo Alfa, Lda', nif: '5000000001' },
  cliente_id: 1,
  total_liquido: '7000.00',
  total_imposto: '980.00',
  total_bruto: '7980.00',
  valor_pago: null,
  valor_pendente: '7980.00',
  estado: 'PENDENTE',
  contabilizado: false,
  numero_lan_contabilizacao: null,
  codigo_moeda: 'AOA',
  meio_pagamento: null,
  motivo_nota_credito: null,
  observacoes: 'Entrega no armazém <central>',
  data_vencimento: '2026-10-30',
  valido_ate: null,
  faturacao_eletronica: { serie: 'A2026', numero: 12, estado: 'VALIDO', regime: true, erros: [], avisos: [], hash: 'AbCd' },
  linhas: [
    { id: 1, produto_id: 3, descricao: 'Caixa de parafusos', quantidade: '2', preco_unitario: '2500.00', taxa_imposto: '14', valor: '5000.00', total: '5700.00' },
    { id: 2, produto_id: 4, descricao: 'Serviço de montagem', quantidade: '1', preco_unitario: '2000.00', taxa_imposto: '14', valor: '2000.00', total: '2280.00' },
  ],
};

describe('documento comercial impresso', () => {
  it('escreve o valor por extenso em português (kwanzas e cêntimos)', () => {
    expect(valorPorExtenso('7980.00')).toBe('Sete mil novecentos e oitenta kwanzas');
    expect(valorPorExtenso('1.01')).toBe('Um kwanza e um cêntimo');
    expect(valorPorExtenso('1000000')).toBe('Um milhão de kwanzas');
    expect(valorPorExtenso('250.5', 'USD')).toBe('Duzentos e cinquenta dólares e cinquenta cêntimos');
  });

  it('resume o IVA por taxa com aritmética em cêntimos', () => {
    expect(resumoImpostos([{ descricao: 'a', taxa: 14, total: '0.10' }, { descricao: 'b', taxa: 14, total: '0.10' }, { descricao: 'c', taxa: 0, total: '5' }])).toEqual([
      { imposto: 'IVA 14 %', base: '0.20', valor: '0.02' },
      { imposto: 'IVA 0 % (isento)', base: '5.00', valor: '0.00' },
    ]);
  });

  it('factura FT: cliente, linhas, IVA, totais, série/hash AGT e QR; A4 retrato; texto escapado', () => {
    const pedido = pedidoDocumentoComercial(dadosDocumentoVenda(ft, { imagem: 'data:image/svg+xml;charset=utf-8,%3Csvg%3E%3C%2Fsvg%3E', url: 'https://agt.example/consulta' }));
    expect(pedido.titulo).toBe('Factura FT A/2026/12');
    expect(pedido.orientacao).toBe('retrato');
    expect(pedido.papel).toBe('A4');
    const html = esp(pedido.conteudo as string);
    expect(html).toContain('Cliente Demo Alfa, Lda');
    expect(html).toContain('NIF: 5000000001');
    expect(html).toContain('Caixa de parafusos');
    expect(html).toContain('IVA 14 %');
    expect(html).toContain('7980,00 Kz');
    expect(html).toContain('Sete mil novecentos e oitenta kwanzas');
    expect(html).toContain('Série A2026 / 12');
    expect(html).toContain('Hash: AbCd');
    expect(html).toContain('QR code de consulta');
    expect(html).toContain('&lt;central&gt;');
    expect(html).not.toContain('<central>');
    // o nome/logótipo da empresa nunca vêm do documento: é o motor que os põe
    const doc = construirDocumento({ ...pedido, conteudo: html, identidade: { nome: 'Empresa Teste, Lda', logotipo: null } });
    expect(doc.match(/Empresa Teste, Lda/g)?.length).toBeGreaterThanOrEqual(1);
    expect(doc).toContain('dc-documento');
  });

  it('documentos sem valor fiscal e guias levam os avisos e assinaturas próprios', () => {
    const or = htmlDocumentoComercial(dadosDocumentoVenda({ ...ft, tipo_documento: 'OR', faturacao_eletronica: undefined }));
    expect(or).toContain('Este documento não serve de factura');
    expect(or).not.toContain('Documento processado por computador');
    const gr = htmlDocumentoComercial(dadosDocumentoVenda({ ...ft, tipo_documento: 'GR' }));
    expect(gr).toContain('Documento de transporte');
    expect(gr).toContain('Expedidor');
  });

  it('recibo: documentos liquidados e total recebido, sem resumo de IVA', () => {
    const html = htmlDocumentoComercial(
      dadosRecibo({
        id: 1, numero_recibo: 'RC 2026/3', data: '2026-09-30', cliente_id: 1, cliente: { id: 1, nome: 'Cliente Beta', nif: null }, montante_total: '1500.00',
        meio_pagamento: 'TPA', codigo_conta: '4511', referencia_pagamento: null, estado: 'EMITIDO', contabilizado: false, numero_lan_contabilizacao: null,
        venda_origem_id: null, anulado_em: null, motivo_anulacao: null, alocacoes: [{ venda_id: 7, numero_documento: 'FT A/2026/12', montante: '1500.00' }],
      }),
    );
    expect(html).toContain('FT A/2026/12');
    expect(html).toContain('Total recebido');
    expect(html).toContain('TPA (Multicaixa)');
    expect(html).not.toContain('Imposto</th>');
  });

  it('encomenda e factura de fornecedor: fornecedor, totais (a factura já traz o IVA no montante)', () => {
    const enc = esp(htmlDocumentoComercial(
      dadosEncomendaCompra({
        id: 5, numero_encomenda: 'ENC 2026/5', pedido_compra_id: 2, cotacao_compra_id: 3, fornecedor_id: 9, fornecedor: { id: 9, nome: 'Fornecedor X', nif: '123' }, data: '2026-09-01',
        estado: 'EM_PROCESSAMENTO', contabilizado: false, contrato_fornecedor_id: null, codigo_moeda: 'AOA', taxa_cambio: null, montante_total: '1000.00', montante_total_moeda: null,
        total_imposto: '140.00', total_com_imposto: '1140.00', data_entrega_prevista: null, anulado_em: null, motivo_anulacao: null, linhas: [],
      }),
    ));
    expect(enc).toContain('Fornecedor X');
    expect(enc).toContain('1140,00 Kz');
    expect(enc).toContain('O fornecedor (aceitação)');
    const fat = esp(htmlDocumentoComercial(
      dadosFaturaCompra({
        id: 1, numero_fatura: 'FF 77', encomenda_compra_id: null, fornecedor_id: 9, fornecedor: { id: 9, nome: 'Fornecedor X' }, data: '2026-09-02', data_vencimento: null,
        estado: 'PENDENTE', contabilizado: false, numero_lan_contabilizacao: null, codigo_moeda: 'AOA', taxa_cambio: null, montante_total: '1140.00', total_imposto: '140.00',
        montante_total_moeda: null, total_imposto_moeda: null, anulado_em: null, motivo_anulacao: null,
      }, []),
    ));
    expect(fat).toContain('1000,00 Kz');
    expect(fat).toContain('1140,00 Kz');
    expect(fat).toContain('Factura directa');
  });
});
