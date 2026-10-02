import { describe, expect, it } from 'vitest';
import { htmlDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import type { Disponibilidades, DocumentoTesouraria, ExtratoConta, Pendente } from './api';
import { dadosDocumentoTesouraria, pedidoDisponibilidades, pedidoExtrato, pedidoPendentes } from './impressao';

/** Os separadores do Intl podem ser espaços especiais: normalizam-se para comparar. */
const esp = (s: string) => s.replace(/[  ]/g, ' ');

describe('impressão da tesouraria', () => {
  it('nota de pagamento: beneficiário, linhas D/C, total por extenso e assinaturas', () => {
    const d = {
      id: 3, tipo: 'PAGAMENTO', data_documento: '2026-09-30', conta_financeira: '4311', descricao: 'Pagamento a fornecedor', valor_total: '1500.00', estado: 'PENDENTE',
      referencia: 'TRF-1', codigo_moeda: 'AOA', taxa_cambio: null, valor_total_moeda: null, numero_documento: 'PG 2026/3', numero_lan_contabilizacao: null,
      linhas: [{ id: 1, codigo_conta: '3211', terceiro_id: 9, terceiro: { id: 9, nome: 'Fornecedor X', nif: '123' }, numero_documento: 'FF 77', descricao: 'Liquidação', valor: '1500.00', tipo_dc: 'D' }],
    } as unknown as DocumentoTesouraria;
    const html = esp(htmlDocumentoComercial(dadosDocumentoTesouraria(d)));
    expect(html).toContain('Beneficiário');
    expect(html).toContain('Fornecedor X');
    expect(html).toContain('FF 77');
    expect(html).toContain('Total pago');
    expect(html).toContain('Mil e quinhentos kwanzas');
    expect(html).toContain('Recebi (beneficiário)');
  });

  it('disponibilidades agrupadas por bancos e caixa com subtotais e total', () => {
    const s: Disponibilidades = {
      data: '2026-09-30',
      contas: [
        { codigo_conta: '4511', descricao: 'Caixa', grupo: '45', tipo: 'CAIXA', meio_pagamento: null, codigo_moeda: null, saldo: '100.00' },
        { codigo_conta: '4311', descricao: 'Banco A', grupo: '43', tipo: 'BANCO', meio_pagamento: 'Banco A', codigo_moeda: 'AOA', saldo: '900.00' },
      ],
      totais: { bancos: '900.00', caixa: '100.00', total: '1000.00' },
    };
    const html = esp(pedidoDisponibilidades(s).conteudo as string);
    expect(html.indexOf('Bancos (43)')).toBeLessThan(html.indexOf('Caixa (45)'));
    expect(html).toContain('Subtotal Bancos (43)');
    expect(html).toContain('Total disponível');
    expect(html).toContain('1000,00');
  });

  it('extracto com saldo inicial, totais do servidor e saldo final', () => {
    const e = {
      codigo_conta: '4311', descricao: 'Banco A', data_inicio: '2026-09-01', data_fim: '2026-09-30', saldo_inicial: '10.00', debito: '5.00', credito: '2.00', saldo_final: '13.00',
      movimentos: [{ id: 1, data_documento: '2026-09-02', numero_lan: 'BC 1', numero_documento: null, descricao: 'x', tipo_dc: 'D', valor: '5.00', saldo: '15.00', diario: 'BC', terceiro_id: null, terceiro: null, estorno_de_id: null, estornado_por_id: null }],
    } as ExtratoConta;
    const p = pedidoExtrato(e);
    expect(p.titulo).toContain('4311');
    const html = esp(p.conteudo as string);
    expect(html).toContain('Saldo inicial: 10,00 Kz');
    expect(html).toContain('13,00');
  });

  it('pendentes agrupados por terceiro com subtotais', () => {
    const base = { codigo_moeda: null, saldo_moeda: null, total_moeda: null, codigo_conta: '3111', natureza: 'A_RECEBER', liquidado: '0.00', em_liquidacao: '0.00', liquidar_a: 'C', venda_id: null, fatura_compra_id: null } as const;
    const linhas: Pendente[] = [
      { ...base, terceiro_id: 2, terceiro: 'Beta', numero_documento: 'FT 2', data_documento: '2026-09-02', total: '20.00', saldo: '20.00' },
      { ...base, terceiro_id: 1, terceiro: 'Alfa', numero_documento: 'FT 1', data_documento: '2026-09-01', total: '10.00', saldo: '10.00' },
    ];
    const html = pedidoPendentes(linhas).conteudo as string;
    expect(html.indexOf('Alfa')).toBeLessThan(html.indexOf('Beta'));
    expect(html).toContain('Subtotal Alfa');
    expect(html).toContain('Total geral');
  });
});
