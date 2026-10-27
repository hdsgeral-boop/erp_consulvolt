import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.setConfig({ testTimeout: 180_000 });

let sessaoActual: Record<string, unknown>;
const SESSAO = {
  id: 7,
  armazem_id: 1,
  data: '2026-10-01',
  descricao: 'Inventário Anual',
  estado: 'EM_CONTAGEM',
  tipo: null,
  linhas: [
    { id: 1, produto_id: 11, codigo: 'A-1', nome: 'Cabo', custo_medio: '100.00', quantidade_contada: null, observacoes: null, justificacao: null, custo_personalizado: null, custo_unitario: null, valor_diferenca: null },
    { id: 2, produto_id: 12, codigo: 'A-2', nome: 'Disjuntor', custo_medio: '50.00', quantidade_contada: null, observacoes: null, justificacao: null, custo_personalizado: null, custo_unitario: null, valor_diferenca: null },
    { id: 3, produto_id: 13, codigo: 'A-3', nome: 'Tomada', custo_medio: '20.00', quantidade_contada: null, observacoes: null, justificacao: null, custo_personalizado: null, custo_unitario: null, valor_diferenca: null },
  ],
};

// a colagem resolve-se pela ordem das linhas visíveis (sem mapa de produtos — o produto/código fica de fora da colagem)
vi.mock('@/api/cliente', () => ({
  obter: (url: string) => Promise.resolve(url === '/logistica/armazens' ? [{ id: 1, nome: 'Central', predefinido: true }] : url === '/logistica/inventarios/7' ? sessaoActual : []),
  obterPagina: () => Promise.resolve({ itens: [sessaoActual], paginacao: { total: 1, pagina_atual: 1, por_pagina: 200, ultima_pagina: 1 } }),
  enviar: () => Promise.resolve({ dados: {}, mensagem: 'ok' }),
  descarregar: () => Promise.resolve(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: () => true, menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

import { EcraInventario } from './Inventario';

beforeEach(() => {
  sessaoActual = SESSAO;
});

const montar = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/r']}>
        <Routes>
          <Route path="/r/*" element={<EcraInventario modo="contagem" />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

describe('Inventário — colagem do Excel na contagem (quantidade e observações)', () => {
  it('colagem numa célula: preenche a partir da linha/coluna clicadas, por colunas (quantidade, observações)', async () => {
    const { container } = montar();
    await screen.findByText('Cabo', undefined, { timeout: 60_000 });
    const celula = container.querySelector('[data-colar-linha="0"][data-colar-coluna="0"] input') as HTMLInputElement;
    expect(celula).toBeTruthy();
    fireEvent.paste(celula, { clipboardData: { getData: () => '8\tsem avarias\n5' } });

    const qtd0 = container.querySelector('[data-colar-linha="0"][data-colar-coluna="0"] input') as HTMLInputElement;
    const obs0 = container.querySelector('[data-colar-linha="0"][data-colar-coluna="1"] input') as HTMLInputElement;
    const qtd1 = container.querySelector('[data-colar-linha="1"][data-colar-coluna="0"] input') as HTMLInputElement;
    expect(qtd0.value).toBe('8');
    expect(obs0.value).toBe('sem avarias');
    expect(qtd1.value).toBe('5');
  }, 60_000);

  it('botão «Colar do Excel»: aplica às linhas visíveis a partir da 1.ª e avisa dos valores que não couberam', async () => {
    const { container } = montar();
    await screen.findByText('Cabo', undefined, { timeout: 60_000 });
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(screen.getByLabelText('Linhas a colar'), { target: { value: '8\n5\n2\n99' } });
    fireEvent.click(screen.getByRole('button', { name: /Acrescentar linhas/ }));

    const qtd0 = container.querySelector('[data-colar-linha="0"][data-colar-coluna="0"] input') as HTMLInputElement;
    const qtd1 = container.querySelector('[data-colar-linha="1"][data-colar-coluna="0"] input') as HTMLInputElement;
    const qtd2 = container.querySelector('[data-colar-linha="2"][data-colar-coluna="0"] input') as HTMLInputElement;
    expect(qtd0.value).toBe('8');
    expect(qtd1.value).toBe('5');
    expect(qtd2.value).toBe('2');
    // só há 3 artigos na folha: o 4.º valor (99) não tem linha onde entrar
    expect(container.querySelectorAll('[data-colar-linha]').length).toBe(6); // 3 linhas × 2 colunas pasteáveis
    expect(await screen.findByText(/não coube/)).toBeInTheDocument();
  }, 60_000);
});
