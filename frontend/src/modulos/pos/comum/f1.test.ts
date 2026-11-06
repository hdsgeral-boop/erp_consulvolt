import { describe, expect, it } from 'vitest';
import { COR_TIPO_MEIO, MENSAGENS_STOCK, motivoNaoAdicionar, motivoQuantidadeExcede, pilulasDeSaldo } from './frente';
import { avisoErrosAgt, htmlTalaoVenda, NIF_CONSUMIDOR_FINAL, PREFERENCIAS_PADRAO } from './impressao';
import type { ProdutoPOS, VendaEmitida } from './tipos';

const venda = {
  id: 7,
  numero_documento: 'FR T012026/15',
  data_emissao: '2026-10-01',
  total_liquido: '3000.00',
  total_imposto: '420.00',
  total_bruto: '3420.00',
  desconto: '0.00',
  pos_troco: '580.00',
  pos_operador: 'Maria Operadora',
  pos_pagamentos: [
    { tipo: 'NUMERARIO', nome: 'Numerário', valor: '2000.00' },
    { tipo: 'TPA', nome: 'Multicaixa (TPA)', valor: '1420.00', referencia: 'L123' },
  ],
  itens_venda: [{ id: 1, produto_id: 1, descricao: 'Artigo', quantidade: '3', preco_unitario: '1140.00', taxa_imposto: '14', total: '3420.00' }],
} as unknown as VendaEmitida;
const cab = { empresa: 'Demo, Lda', nif: '5000000000', terminal: 'T01 — Caixa 1', utilizador: 'Utilizador da sessão' };
const fiscal = {
  fe_regime: true,
  serie: 'FR T012026',
  qr_svg: '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
  cliente_nome: 'Ana Cliente',
  cliente_nif: '5417000055',
  coordenadas_bancarias: [{ nome: 'BAI Kwanzas', iban: 'AO06004000008888888810158', swift: 'BKBKAOLU' }],
};

describe('talão do POS (F-070/F-071)', () => {
  const html = htmlTalaoVenda(venda, cab, PREFERENCIAS_PADRAO, new Map(), fiscal);

  it('«Talão nº», operador real, cliente e NIF do cliente', () => {
    expect(html).toContain('Talão nº: <b>FR T012026/15</b>');
    expect(html).toContain('Operador: Maria Operadora');
    expect(html).not.toContain('CAIXA POS');
    expect(html).toContain('Cliente: Ana Cliente');
    expect(html).toContain('NIF Cliente: 5417000055');
  });

  it('QR da AGT como imagem (data URL), série e texto de consulta', () => {
    expect(html).toContain('<img class="qr" src="data:image/svg+xml;charset=utf-8,');
    expect(html).toContain('Facturação electrónica · Série FR T012026');
    expect(html).toContain('Consulte este documento no portal da AGT');
    expect(html).not.toContain('<svg'); // o SVG nunca entra como marcação
  });

  it('meios de pagamento, valor entregue (líquido + troco) e troco', () => {
    expect(html).toContain('<b>Meios de Pagamento:</b>');
    expect(html).toContain('- Multicaixa (TPA) (L123)');
    expect(html).toMatch(/Valor Entregue \(Dinheiro\):<\/td><td class="direita">2\s?580,00/);
    expect(html).toMatch(/Troco:<\/td><td class="direita">580,00/);
    expect(html).toContain('TOTAL A PAGAR');
  });

  it('IBAN/SWIFT dos meios activos e «Processado por computador»', () => {
    expect(html).toContain('Coordenadas Bancárias:');
    expect(html).toContain('BAI Kwanzas: AO06004000008888888810158 · SWIFT BKBKAOLU');
    expect(html).toContain('Processado por computador (Software ERP)');
  });

  it('sem dados do servidor: sem QR nem IBAN, NIF 999999999 e «Consumidor Final»; o operador cai no utilizador', () => {
    const h = htmlTalaoVenda({ ...venda, pos_operador: null } as VendaEmitida, cab, PREFERENCIAS_PADRAO);
    expect(h).not.toContain('class="qr"');
    expect(h).not.toContain('Coordenadas Bancárias');
    expect(h).toContain(`NIF Cliente: ${NIF_CONSUMIDOR_FINAL}`);
    expect(h).toContain('Cliente: Consumidor Final');
    expect(h).toContain('Operador: Utilizador da sessão');
    expect(h).toContain('Processado por computador');
  });

  it('só pagamento por TPA: sem «Valor Entregue» nem troco', () => {
    const h = htmlTalaoVenda({ ...venda, pos_troco: '0.00', pos_pagamentos: [{ tipo: 'TPA', nome: 'TPA', valor: '3420.00' }] } as unknown as VendaEmitida, cab, PREFERENCIAS_PADRAO);
    expect(h).not.toContain('Valor Entregue');
    expect(h).not.toContain('Troco:');
  });

  it('escapa o texto vindo do servidor', () => {
    const h = htmlTalaoVenda(venda, cab, PREFERENCIAS_PADRAO, new Map(), { ...fiscal, cliente_nome: '<script>x</script>', coordenadas_bancarias: [{ nome: '<b>', iban: 'AO"1' }] });
    expect(h).not.toContain('<script>x');
    expect(h).toContain('&lt;script&gt;');
  });
});

describe('aviso de erros da AGT (F-068)', () => {
  it('só avisa com erros', () => {
    expect(avisoErrosAgt({ fe_estado: 'PRONTO', fe_erros: [] })).toBeNull();
    expect(avisoErrosAgt({ fe_estado: 'COM_ERROS', fe_erros: [{ codigo: 'E03', mensagem: 'Imposto inválido' }, 'Outro erro'] })).toBe(
      'O documento ficou com erros de validação da AGT: Imposto inválido; Outro erro. Peça a revisão em Vendas › Facturação electrónica.',
    );
  });
});

const prod = (extra: Partial<ProdutoPOS> = {}): ProdutoPOS => ({ id: 1, codigo: 'P1', nome: 'Água', preco_unitario: '100', taxa_imposto: '14', movimenta_stock: true, ...extra });

describe('mensagens de stock do legado (F-033/F-034/F-035)', () => {
  it('bloqueado', () => {
    expect(motivoNaoAdicionar(prod({ bloqueado: true }), 0, 1, 10)).toBe('O artigo "Água" está bloqueado para venda.');
  });
  it('fora de stock (saldo zero ou negativo)', () => {
    expect(motivoNaoAdicionar(prod(), 0, 1, 0)).toBe('O artigo Água encontra-se fora de stock.');
    expect(motivoNaoAdicionar(prod(), 0, 1, -2)).toBe(MENSAGENS_STOCK.foraDeStock('Água'));
  });
  it('mais unidades do que o stock', () => {
    expect(motivoNaoAdicionar(prod(), 5, 1, 5)).toBe('Não é possível faturar mais unidades deste produto do que o stock existente (5).');
    expect(motivoNaoAdicionar(prod(), 0, 8, 5)).toBe('Quantidade limite de stock disponível para Água atingida (5).');
    expect(motivoNaoAdicionar(prod(), 4, 1, 5)).toBeNull();
  });
  it('serviços e stock desconhecido não limitam', () => {
    expect(motivoNaoAdicionar(prod({ movimenta_stock: false }), 0, 99, 0)).toBeNull();
    expect(motivoNaoAdicionar(prod(), 0, 99, null)).toBeNull();
  });
  it('+/− e campo: «Quantidade limite de stock disponível»', () => {
    expect(motivoQuantidadeExcede('Água', 6, 5)).toBe('Quantidade limite de stock disponível para Água atingida (5).');
    expect(motivoQuantidadeExcede('Água', 5, 5)).toBeNull();
    expect(motivoQuantidadeExcede('Água', 6, null)).toBeNull();
  });
  it('quantidades fraccionadas em português', () => {
    expect(motivoNaoAdicionar(prod(), 2, 1, 2.5)).toContain('(2,5)');
  });
});

describe('pílulas de saldo por meio (F-017)', () => {
  const meios = [
    { id: 'pm_num', tipo: 'NUMERARIO', nome: 'Numerário' },
    { id: 'pm_tpa', tipo: 'TPA', nome: 'Multicaixa (TPA)' },
    { id: 'pm_trf', tipo: 'TRANSFERENCIA', nome: 'Transferência bancária' },
  ];
  it('numerário = esperado em caixa; TPA = cobrado; transferência só com movimento', () => {
    const p = pilulasDeSaldo(meios, [{ meio_id: 'pm_tpa', tipo: 'TPA', valor: '1420.00' }], '52000.00');
    expect(p.map((x) => [x.nome, x.valor])).toEqual([
      ['Numerário', 52000],
      ['Multicaixa (TPA)', 1420],
    ]);
    const q = pilulasDeSaldo(meios, [{ meio_id: 'pm_trf', tipo: 'TRANSFERENCIA', valor: '300.00' }], '50000.00');
    expect(q.map((x) => x.nome)).toContain('Transferência bancária');
  });
  it('cores do legado por tipo', () => {
    expect(COR_TIPO_MEIO.NUMERARIO.cor).toBe('#047857');
    expect(COR_TIPO_MEIO.TPA.cor).toBe('#1d4ed8');
    expect(COR_TIPO_MEIO.TRANSFERENCIA.cor).toBe('#6d28d9');
  });
});
