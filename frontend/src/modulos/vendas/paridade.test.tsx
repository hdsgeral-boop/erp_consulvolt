import { fireEvent, render, screen } from '@testing-library/react';
import { accoesLinhaDocumento, bloqueioEliminar, type AccoesDocumento } from './accoesLinhaDocumento';
import { SEPARADORES_VENDAS, separadorVendas, subseparadorFaturacao } from './comum/BarraVendas';
import { GuiasPendentes, MeiosPagamentoDocumento } from './formulario/QuadrosFormulario';
import { pagamentosImpressos } from './impressao/documentoVenda';
import { accoesProduto, corStock } from './produtos/regrasProdutos';
import { COLUNAS_SAFT } from './relatorios/EcraSaft';
import type { DocumentoVenda, GuiaSaidaPendente, MeioPagamentoVenda } from './api';

const tudo = () => true;
const nada = () => false;
const fazer: AccoesDocumento = {
  verProcesso: () => {}, imprimir: () => {}, copiar: () => {}, contabilizar: () => {}, descontabilizar: () => {}, converter: () => {},
  pedidoCompra: () => {}, liquidar: () => {}, limparEstado: () => {}, eliminar: () => {},
};
const doc = (d: Partial<DocumentoVenda>): DocumentoVenda => ({ id: 1, tipo_documento: 'FT', numero_documento: 'FT 2026/1', estado: 'PENDENTE', contabilizado: false, valor_pago: '0', total_bruto: '1000', ...d }) as DocumentoVenda;
const chaves = (d: DocumentoVenda, g: Parameters<typeof accoesLinhaDocumento>[1], pode = tudo) => accoesLinhaDocumento(d, g, pode, fazer).map((a) => a.chave);

describe('agregador «Gestão de Vendas» (4.0.1)', () => {
  it('tem os separadores do legado pela mesma ordem', () => {
    expect(SEPARADORES_VENDAS.map((s) => s.rotulo)).toEqual(['Dashboard', 'Clientes', 'Categorias', 'Produtos/Serviços', 'Faturação', 'Exportar SAFT-AO', 'Séries', 'Facturação electrónica']);
  });
  it('identifica o separador e o subseparador activos', () => {
    expect(separadorVendas('/m/vendas/vendas_relatorios/saft')).toBe('saft');
    expect(separadorVendas('/m/vendas/vendas_relatorios')).toBe('dashboard');
    expect(separadorVendas('/m/vendas/vendas_produtos', '?separador=categorias')).toBe('categorias');
    expect(separadorVendas('/m/vendas/vendas_faturacao/agt')).toBe('fe');
    expect(separadorVendas('/m/vendas/vendas_faturacao/series')).toBe('series');
    expect(subseparadorFaturacao('/m/vendas/vendas_faturacao/series')).toBeNull();
    expect(subseparadorFaturacao('/m/vendas/vendas_faturacao')).toBe('faturas');
    expect(subseparadorFaturacao('/m/vendas/vendas_faturacao/guias')).toBe('guias');
    expect(subseparadorFaturacao('/m/vendas/vendas_faturacao/123')).toBeNull();
  });
});

describe('acções por linha da Faturação (4.2.5 / 4.8.3)', () => {
  it('factura: processo, imprimir, copiar, contabilizar, limpar estado e eliminar bloqueado (fiscal)', () => {
    const a = accoesLinhaDocumento(doc({}), 'faturas', tudo, fazer);
    expect(a.map((x) => x.chave)).toEqual(['processo', 'imprimir', 'copiar', 'contabilizar', 'limpar', 'eliminar']);
    expect(a.find((x) => x.chave === 'eliminar')?.desactivado).toMatch(/Nota de Crédito/);
  });
  it('factura contabilizada por pagar: descontabilizar e liquidar', () => {
    expect(chaves(doc({ contabilizado: true }), 'faturas')).toEqual(expect.arrayContaining(['descontabilizar', 'contabilizado', 'liquidar']));
  });
  it('encomenda: converter e converter em pedido de compra; sem permissões só ver e imprimir', () => {
    expect(chaves(doc({ tipo_documento: 'NE' }), 'encomendas')).toEqual(expect.arrayContaining(['converter', 'pedido']));
    expect(chaves(doc({ tipo_documento: 'NE' }), 'encomendas', nada)).toEqual(['processo', 'imprimir']);
  });
  it('bloqueia eliminar documentos contabilizados, com liquidações ou convertidos', () => {
    expect(bloqueioEliminar({ tipo_documento: 'OR', estado: 'PENDENTE', contabilizado: true, valor_pago: '0' })).toMatch(/Bloqueada/);
    expect(bloqueioEliminar({ tipo_documento: 'NE', estado: 'CONCLUIDO', contabilizado: false, valor_pago: '0' })).toMatch(/convertido/);
    expect(bloqueioEliminar({ tipo_documento: 'OR', estado: 'PENDENTE', contabilizado: false, valor_pago: '0' })).toBeNull();
  });
});

describe('produtos (4.1.3 / 4.1.4)', () => {
  it('cor do stock como no legado', () => {
    expect([corStock(0), corStock(3), corStock(10)]).toEqual(['red', 'orange', 'green']);
  });
  it('acções Editar, Copiar, Bloquear e Apagar conforme as permissões', () => {
    const f = { editar: () => {}, copiar: () => {}, bloquear: () => {}, eliminar: () => {} };
    expect(accoesProduto({ nome: 'X', bloqueado: false }, { podeGerir: true, podeEliminar: true }, f).map((a) => a.titulo)).toEqual(['Editar', 'Copiar', 'Bloquear Artigo', 'Apagar']);
    expect(accoesProduto({ nome: 'X', bloqueado: true }, { podeGerir: true, podeEliminar: false }, f).map((a) => a.titulo)).toEqual(['Editar', 'Copiar', 'Desbloquear Artigo']);
  });
});

describe('formulário da factura (4.2.9 / 4.2.12)', () => {
  const guia: GuiaSaidaPendente = { id: 9, numero_documento: 'GS 2026/7', data: '2026-09-01', estado: 'PENDENTE', cliente: { id: 3, nome: 'Cliente Teste' }, armazem_id: 1, projeto_id: null, linhas: [] };
  it('mostra as guias pendentes e «FATURAR AGORA»', () => {
    const escolher = vi.fn();
    render(<GuiasPendentes guias={[guia]} aoEscolher={escolher} />);
    expect(screen.getByText(/Guias de Saída Pendentes de Faturação/i)).toBeTruthy();
    fireEvent.click(screen.getByRole('listitem', { name: /Facturar agora a guia GS 2026\/7/ }));
    expect(escolher).toHaveBeenCalledWith(guia);
  });
  it('«Adicionar Conta» acrescenta uma linha e o aviso aparece sem meios configurados', () => {
    const mudar = vi.fn();
    const meios: MeioPagamentoVenda[] = [{ id: 1, nome: 'Banco Teste', codigo_conta: '4311', iban: null, swift: null, predefinido: true, codigo_moeda: 'AOA' }];
    const { rerender } = render(<MeiosPagamentoDocumento meios={meios} contas={[1]} aoMudar={mudar} />);
    fireEvent.click(screen.getByRole('button', { name: /Adicionar Conta/ }));
    expect(mudar).toHaveBeenCalledWith([1, undefined]);
    rerender(<MeiosPagamentoDocumento meios={[]} contas={[]} aoMudar={mudar} />);
    expect(screen.getByText(/Tesouraria ▸ Gestão ▸ Meios de Pagamento/)).toBeTruthy();
  });
  it('a impressão mostra as contas e respeita «Ocultar quadro de Meios de Pagamento»', () => {
    const contas = [{ meio_pagamento_id: 1, nome: 'Banco Teste', codigo_conta: '4311', iban: 'AO06000000000000000000000', swift: null }];
    expect(pagamentosImpressos({ tipo_documento: 'FT', total_bruto: '10', contas_pagamento: contas, ocultar_meios_pagamento: false })[0].descricao).toMatch(/Banco Teste — IBAN/);
    expect(pagamentosImpressos({ tipo_documento: 'FT', total_bruto: '10', contas_pagamento: contas, ocultar_meios_pagamento: true })).toEqual([]);
    expect(pagamentosImpressos({ tipo_documento: 'OR', total_bruto: '10', contas_pagamento: contas, ocultar_meios_pagamento: false })).toEqual([]);
  });
});

describe('SAF-T (4.6.1)', () => {
  it('dados detalhados com as colunas do legado', () => {
    expect(COLUNAS_SAFT.map((c) => c.title)).toEqual(['Data', 'Documento', 'Cliente', 'Artigo', 'Qtd', 'P.Unit s/IVA', 'P.Unit c/IVA', 'Doc. Liq', 'Doc. IVA', 'Doc. Bruto', 'Dt. Pag.', 'M. Pag.']);
  });
});
