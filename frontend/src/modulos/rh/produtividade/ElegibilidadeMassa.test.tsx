import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ItemProdutividade } from '../api';
import { ProdutividadeMassa } from './ProdutividadeMassa';

const obter = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: vi.fn(() => Promise.resolve({ dados: {}, mensagem: 'ok' })),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { ultima_pagina: 1 } }),
}));

const ITENS: ItemProdutividade[] = [
  { id: 1, codigo: 'PROD001', descricao: 'Produção', metrica: null, unidade: 'un', preco_unitario: '150', infotipo_salarial_id: 1, minimo: null, maximo: null, ativo: true },
  { id: 2, codigo: 'PROD002', descricao: 'Qualidade', metrica: null, unidade: null, preco_unitario: '80', infotipo_salarial_id: 2, minimo: null, maximo: null, ativo: true },
];

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  obter.mockReset().mockImplementation((url: string) => (url === '/rh/produtividade/itens' ? Promise.resolve(ITENS) : Promise.resolve([])));
  return render(<QueryClientProvider client={cliente}><ProdutividadeMassa /></QueryClientProvider>);
}

/** Espera a lista de itens activos estar na cache antes de colar (abre e fecha o seletor do 1.º item). */
async function aguardarItensCarregados() {
  const campo = screen.getAllByRole('combobox')[0];
  fireEvent.mouseDown(campo);
  await screen.findByText(/PROD001/);
  fireEvent.keyDown(campo, { key: 'Escape' });
}

describe('ProdutividadeMassa — Colar do Excel nos itens', () => {
  it('botão «Colar do Excel»: acrescenta itens no fim, lendo item (pelo código) e preço pela ordem das colunas', async () => {
    montar();
    await aguardarItensCarregados();
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(await screen.findByLabelText('Linhas a colar'), { target: { value: 'PROD001\t100\nPROD002\t50' } });
    fireEvent.click(screen.getByRole('button', { name: /Acrescentar linhas/ }));
    // a linha inicial (em branco, dos valores iniciais do formulário) fica e as 2 coladas são acrescentadas no fim
    await waitFor(() => expect(screen.getAllByRole('spinbutton')).toHaveLength(3));
    // precisão 4 do InputNumber do preço: valores formatados com vírgula
    expect(screen.getAllByRole('spinbutton').map((i) => (i as HTMLInputElement).value)).toEqual(['', '100,0000', '50,0000']);
  }, 60_000);

  it('colagem directa numa célula: preenche a partir da linha/coluna clicada', async () => {
    montar();
    await aguardarItensCarregados();
    const celulaItem = document.querySelector('[data-colar-linha="0"] [data-colar-coluna="0"] input') as HTMLElement;
    fireEvent.paste(celulaItem, { clipboardData: { getData: () => 'PROD001\t200' } });
    await waitFor(() => expect((screen.getByRole('spinbutton') as HTMLInputElement).value).toBe('200,0000'));
  }, 60_000);
});
