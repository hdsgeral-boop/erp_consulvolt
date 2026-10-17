import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.setConfig({ testTimeout: 180_000 });

const ARMAZENS = [
  { id: 1, nome: 'Armazém Central', codigo: null, localizacao: 'Luanda', predefinido: true },
  { id: 2, nome: 'Obra', codigo: null, localizacao: null, predefinido: false },
];
const CATALOGO = [{ id: 7, codigo: 'P1', nome: 'Bomba', preco_unitario: '1000', taxa_imposto: '14', movimenta_stock: true }];
const linha = (por: string) => ({ id: 70, produto_id: 7, descricao: 'Bomba', produto: { id: 7, codigo: 'P1', nome: 'Bomba' }, movimenta_stock: true, quantidade: '5.000', quantidade_entregue: '0.000', por_entregar: por });
const ENCOMENDAS = [
  { id: 11, numero_documento: 'NE 2026/11', data_emissao: '2026-10-10', cliente_id: 3, cliente: { id: 3, nome: 'Cliente B2B', nif: null }, projeto_id: null, satisfeita: true, compras: [], linhas: [linha('5.000')] },
  { id: 12, numero_documento: 'NE 2026/12', data_emissao: '2026-10-11', cliente_id: 4, cliente: { id: 4, nome: 'Outro Cliente', nif: null }, projeto_id: null, satisfeita: false, compras: [], linhas: [{ ...linha('2.000'), id: 80 }] },
];

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { total: 0, pagina: 1, por_pagina: 30, ultima_pagina: 1 } }),
  descarregar: () => Promise.resolve(),
}));
let permissoes: Set<string> | null = null;   // null = todas
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: (p: string) => !permissoes || permissoes.has(p), menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

import EmitirGuiaSaida, { linhasDaEncomenda, validarGuia } from './EmitirGuiaSaida';

function montar(caminho = '/novo') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[caminho]}>
        <Routes>
          <Route path="/novo" element={<EmitirGuiaSaida />} />
          <Route path="/" element={<div>Lista de guias</div>} />
          <Route path="/:id" element={<div>Detalhe da guia</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const posts = (url: string) => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === url);

beforeEach(() => {
  permissoes = null;
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(
    url === '/logistica/armazens' ? ARMAZENS
      : url === '/logistica/produtos/catalogo' ? CATALOGO
      : url === '/logistica/guias-saida/encomendas-clientes' ? ENCOMENDAS
      : url === '/logistica/guias-saida/stock-armazem' ? { 7: '10.000' }
      : [],
  ));
  enviar.mockReset().mockResolvedValue({ dados: { id: 99, numero_documento: 'GR 2026/1' }, mensagem: 'ok' });
});

describe('Emitir Guia de Saída (Entrega/Consumo) — paridade com showGuiaSaidaForm', () => {
  it('mostra o painel das encomendas vinculadas a compras e, ao clicar, preenche cliente e artigos', async () => {
    montar();
    const painel = await screen.findByTestId('painel-back-to-back');
    expect(within(painel).getByText(/Encomendas Vinculadas a Compras \(Back-to-Back\)/i)).toBeTruthy();
    expect(within(painel).getAllByText(/EXPEDIR AGORA/)).toHaveLength(2);
    expect(within(painel).getByText('Cliente B2B')).toBeTruthy();

    fireEvent.click(within(painel).getByRole('button', { name: /Expedir agora a encomenda NE 2026\/11/ }));
    await waitFor(() => expect((screen.getByLabelText('Cliente / Entidade') as HTMLInputElement).value).toBe('Cliente B2B — NE 2026/11'));
    expect((screen.getByLabelText('Qtd Saída') as HTMLInputElement).value).toBe('5');
    await waitFor(() => expect(screen.getByText('10')).toBeTruthy());   // «Stock Disp.» do armazém predefinido
    expect(screen.getByRole('button', { name: '+ Item' }).hasAttribute('disabled')).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: /Emitir e Baixar Stock/ }));
    await waitFor(() => expect(posts('/logistica/guias-saida/remessa')).toHaveLength(1));
    expect(posts('/logistica/guias-saida/remessa')[0][2]).toMatchObject({
      tipo: 'VENDA', armazem_id: 1, cliente_id: 3, encomenda_id: 11, linhas: [{ produto_id: 7, quantidade: 5, item_origem_id: 70 }],
    });
    expect(await screen.findByText('Lista de guias')).toBeTruthy();
  });

  it('?encomenda= (recepções) abre como Saída de Encomenda Fechada com a encomenda satisfeita', async () => {
    montar('/novo?encomenda=11');
    expect(await screen.findByText('NE 2026/11 - Cliente B2B')).toBeTruthy();   // «Encomendas Satisfeitas» com a encomenda escolhida
    expect(screen.getByText('Saída de Encomenda Fechada (Cliente)')).toBeTruthy();
    expect(screen.getByText('Encomendas Satisfeitas')).toBeTruthy();
    expect(screen.queryByLabelText('Área de Destino')).toBeNull();
    await waitFor(() => expect(screen.getByText('10')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /Emitir e Baixar Stock/ }));
    await waitFor(() => expect(posts('/logistica/guias-saida/remessa')).toHaveLength(1));
    expect(posts('/logistica/guias-saida/remessa')[0][2]).toMatchObject({ tipo: 'BACK_TO_BACK', encomenda_id: 11 });
  });

  it('o tipo Consumo Interno pede a área de destino e emite a guia de consumo', async () => {
    montar();
    await screen.findByTestId('painel-back-to-back');
    fireEvent.mouseDown(screen.getByRole('combobox', { name: 'Tipo de Saída' }));
    fireEvent.click((await screen.findAllByText('Consumo Interno')).at(-1)!);
    const area = await screen.findByLabelText('Área de Destino');
    expect(screen.queryByLabelText('Cliente / Entidade')).toBeNull();
    fireEvent.change(area, { target: { value: 'Oficina' } });
    fireEvent.mouseDown(screen.getByRole('combobox', { name: 'Produto' }));
    fireEvent.click((await screen.findAllByText('P1 — Bomba')).at(-1)!);
    fireEvent.click(screen.getByRole('button', { name: /Emitir e Baixar Stock/ }));
    await waitFor(() => expect(posts('/logistica/guias-saida')).toHaveLength(1));
    expect(posts('/logistica/guias-saida')[0][2]).toMatchObject({ armazem_id: 1, area_rececao: 'Oficina', linhas: [{ produto_id: 7, quantidade: 1 }] });
    expect(await screen.findByText('Detalhe da guia')).toBeTruthy();
  });

  it('sem «emitir documentos de venda» só há consumo interno e não aparece o painel', async () => {
    permissoes = new Set(['armazem_guias_emitir']);
    montar();
    expect(await screen.findByLabelText('Área de Destino')).toBeTruthy();
    expect(screen.queryByTestId('painel-back-to-back')).toBeNull();
    expect(obter.mock.calls.some((c) => c[0] === '/logistica/guias-saida/encomendas-clientes')).toBe(false);
  });
});

describe('regras do formulário', () => {
  const base = { tipo: 'VENDA' as const, armazem: 1, cliente: 3, linhas: [{ chave: 1, produto_id: 7, quantidade: 2 }], stock: { 7: '10.000' }, movimentaStock: () => true };

  it('mensagens do legado (saveGuiaSaida)', () => {
    expect(validarGuia(base)).toBeNull();
    expect(validarGuia({ ...base, armazem: undefined })).toBe('Selecione o Armazém de saída.');
    expect(validarGuia({ ...base, cliente: undefined })).toBe('Selecione o cliente ou a encomenda de origem.');
    expect(validarGuia({ ...base, tipo: 'BACK_TO_BACK' })).toBe('Selecione a encomenda satisfeita a expedir.');
    expect(validarGuia({ ...base, tipo: 'CONSUMO', area: ' ' })).toBe('Indique a área de destino.');
    expect(validarGuia({ ...base, linhas: [{ chave: 1 }] })).toBe('Adicione pelo menos um item.');
    expect(validarGuia({ ...base, linhas: [{ chave: 1, produto_id: 7, quantidade: 6 }, { chave: 2, produto_id: 7, quantidade: 5 }] })).toBe('Quantidade indisponível no Armazém selecionado.');
    expect(validarGuia({ ...base, linhas: [{ chave: 1, produto_id: 7, quantidade: 50 }], movimentaStock: () => false })).toBeNull();   // serviços
  });

  it('as linhas da encomenda trazem só o que falta entregar, ligadas à linha de origem', () => {
    const ls = linhasDaEncomenda({ linhas: [linha('3.000'), { ...linha('0.000'), id: 71 }] });
    expect(ls).toHaveLength(1);
    expect(ls[0]).toMatchObject({ produto_id: 7, quantidade: 3, item_origem_id: 70, maximo: 3 });
  });
});
