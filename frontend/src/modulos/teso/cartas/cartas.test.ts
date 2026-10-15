import { describe, expect, it } from 'vitest';
import type { DocumentoTesouraria } from '../api';
import { accoesDocumento } from '../pagamentos/ListaDocumentos';
import type { BeneficiarioCarta, CartaPagamento } from './api';
import { accoesCarta } from './CartasPagamento';
import { htmlCarta, pedidoCarta } from './impressao';
import {
  aplicarVariaveis, chaveBeneficiario, contextoCarta, corpoBeneficiarios, dataPorExtenso, eContaBancaria, formatarIban, mesPorExtenso, seleccaoInicial,
  totalSeleccionado,
} from './regras';

const benef = (p: Partial<BeneficiarioCarta> = {}): BeneficiarioCarta => ({
  tipo: 'TERCEIRO', terceiro_id: 1, colaborador_id: null, nome: 'Alfa Ficticia', nif: null, valor: '100.00', referencia: null, conta_bancaria_id: 10,
  banco: 'Banco Um', iban: 'AO06004000000000000000011', numero_conta: null, titular: null, contas: [], ja_na_carta: null, ...p,
});

const carta = (p: Partial<CartaPagamento> = {}): CartaPagamento => ({
  id: 7, tipo: 'TERCEIRO', tipo_efectivo: 'TERCEIRO', numero: 'CP 2026/001', sequencia: 1, ano: 2026, estado: 'EMITIDA', estado_efectivo: 'EMITIDA',
  data: '2026-10-15', documento_tesouraria_id: 3, periodo_processamento_salarial_id: null, mes_ano: '2026-10', referencia_movimento: 'PAG 2026/12',
  codigo_conta_bancaria: '4311', banco_nome: 'Banco Ficticio SA', banco_balcao: null, banco_numero_conta: '0001', banco_iban: 'AO06005500000000000000017',
  banco_swift: 'TSTFAOLU', banco_titular: 'Empresa Ficticia', codigo_moeda: 'AOA', assunto: 'Transferência {{NUMERO}}',
  corpo: 'Transfiram {{TOTAL}} ({{TOTAL_EXTENSO}}) a {{BENEFICIARIO}}, IBAN {{IBAN_BENEFICIARIO}}. {{DESCONHECIDA}}', nome_assinatura: 'Director <b>', cargo_assinatura: 'Gerência',
  local: null, montante_total: '1250.50', criado_por: 'teste', anulada_em: null, anulada_por: null, motivo_anulacao: null,
  itens: [{ id: 1, colaborador_id: null, terceiro_id: 1, nome: 'Alfa <script>alert(1)</script>', nif: '500', banco: 'Banco Um', iban: 'AO06004000000000000000011', numero_conta: null, titular: null, referencia: null, montante: '1250.50' }],
  empresa: { nome: 'Empresa Ficticia', nif: '5417000000', endereco: null, municipio: 'Luanda', provincia: null, telefone: null, email: null },
  ...p,
});

describe('Fase D — regras das cartas de pagamento', () => {
  it('só as contas 42 e 43 emitem cartas', () => {
    expect(eContaBancaria('4311')).toBe(true);
    expect(eContaBancaria('42.1')).toBe(true);
    expect(eContaBancaria('4511')).toBe(false);
    expect(eContaBancaria(null)).toBe(false);
  });

  it('datas e mês por extenso (RH «MM/AAAA» e «AAAA-MM»)', () => {
    expect(mesPorExtenso('03/2026')).toBe('Março de 2026');
    expect(mesPorExtenso('2026-10')).toBe('Outubro de 2026');
    expect(dataPorExtenso('2026-10-05')).toBe('5 de Outubro de 2026');
    expect(formatarIban('AO06004000000000000000011')).toBe('AO06 0040 0000 0000 0000 0001 1');
  });

  it('substitui as variáveis conhecidas e deixa as desconhecidas', () => {
    expect(aplicarVariaveis('{{A}} e {{B}} {{C}}', { A: 'x', B: 2 })).toBe('x e 2 {{C}}');
    const ctx = contextoCarta(carta(), carta().empresa);
    expect(ctx.TOTAL).toMatch(/^1.250,50 Kz$/);
    expect(ctx.TOTAL_EXTENSO).toBe('Mil duzentos e cinquenta kwanzas e cinquenta cêntimos');
    expect(ctx.BENEFICIARIO).toBe('Alfa <script>alert(1)</script>');
    expect(ctx.LOCAL).toBe('Luanda');
    expect(contextoCarta(carta({ itens: [] })).BENEFICIARIO).toBe('diversos beneficiários (ver quadro)');
  });

  it('selecção inicial: com conta e ainda sem carta; o pedido só leva a escolha', () => {
    const lista = [benef(), benef({ terceiro_id: 2, iban: null }), benef({ terceiro_id: 3, ja_na_carta: 'CP 2026/001' }), benef({ tipo: 'SALARIOS', terceiro_id: null, colaborador_id: 9, valor: '50.25' })];
    expect(seleccaoInicial(lista)).toEqual(['t1', 'c9']);
    expect(totalSeleccionado(lista, ['t1', 'c9'])).toBe('150.25');
    expect(corpoBeneficiarios(lista, ['t1', 'c9'], { t1: 11 })).toEqual([{ terceiro_id: 1, conta_bancaria_id: 11 }, { colaborador_id: 9 }]);
    expect(chaveBeneficiario(lista[3])).toBe('c9');
  });
});

describe('Fase D — impressão da carta', () => {
  it('escapa todo o texto do modelo e dos beneficiários (nada de HTML/JS injectado)', () => {
    const html = htmlCarta(carta());
    expect(html).not.toContain('<script>');
    expect(html).toContain('Alfa &lt;script&gt;alert(1)&lt;/script&gt;');
    expect(html).toContain('Director &lt;b&gt;');
    expect(html).toContain('Assunto: Transferência CP 2026/001');
    expect(html).toContain('Mil duzentos e cinquenta kwanzas e cinquenta cêntimos');
    expect(html).toContain('{{DESCONHECIDA}}');
    expect(html).toContain('15 de Outubro de 2026');
    expect(html).not.toContain('CARTA ANULADA');
  });

  it('carta anulada leva a marca e o motivo; pedido A4 retrato', () => {
    expect(htmlCarta(carta({ estado_efectivo: 'ANULADA', motivo_anulacao: 'IBAN <errado>' }))).toContain('CARTA ANULADA — IBAN &lt;errado&gt;');
    const p = pedidoCarta(carta(), 'pdf');
    expect(p).toMatchObject({ titulo: 'Carta de ordem de pagamento', subtitulo: 'CP 2026/001', orientacao: 'retrato', papel: 'A4', modo: 'pdf', nomeFicheiro: 'Carta CP 2026-001' });
  });
});

describe('Fase D — acções', () => {
  const o = { podeEmitir: true, ver: () => {}, imprimir: () => {}, anular: () => {} };
  it('lista de cartas: Ver, Imprimir, PDF e Anular (só emitidas, com permissão)', () => {
    expect(accoesCarta(carta(), o).filter((a) => !a.oculto).map((a) => a.chave)).toEqual(['ver', 'imprimir', 'pdf', 'anular']);
    expect(accoesCarta(carta({ estado_efectivo: 'ANULADA' }), o).filter((a) => !a.oculto).map((a) => a.chave)).toEqual(['ver', 'imprimir', 'pdf']);
    expect(accoesCarta(carta(), { ...o, podeEmitir: false }).filter((a) => !a.oculto).map((a) => a.chave)).toEqual(['ver', 'imprimir', 'pdf']);
  });

  it('atalho na lista de pagamentos: só pagamentos 42/43 não anulados e com teso_carta_emitir', () => {
    const doc = (p: Partial<DocumentoTesouraria> = {}) => ({ id: 1, tipo: 'PAGAMENTO', estado: 'PENDENTE', conta_financeira: '4311', ...p }) as DocumentoTesouraria;
    const base = { podeEmitir: true, podeEliminar: true, abrir: () => {}, copiar: () => {}, editar: () => {}, excluir: () => {} };
    const comCarta = { ...base, emitirCarta: () => {} };
    expect(accoesDocumento(doc(), comCarta).map((a) => a.chave)).toEqual(['detalhes', 'copiar', 'carta', 'editar', 'excluir']);
    expect(accoesDocumento(doc(), base).map((a) => a.chave)).not.toContain('carta');
    expect(accoesDocumento(doc({ conta_financeira: '4511' }), comCarta).map((a) => a.chave)).not.toContain('carta');
    expect(accoesDocumento(doc({ tipo: 'RECEBIMENTO' }), comCarta).map((a) => a.chave)).not.toContain('carta');
    expect(accoesDocumento(doc({ estado: 'ANULADO' }), comCarta).map((a) => a.chave)).not.toContain('carta');
  });
});
