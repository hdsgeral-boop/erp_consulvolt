import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NovaProposta } from './NovaProposta';

const obter = vi.fn();
const obterPagina = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  obterPagina: (...a: unknown[]) => obterPagina(...a),
  enviar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null }) }));

const CATALOGO = [
  { id: 1, codigo: 'P001', nome: 'Cimento', preco_unitario: '100.00', taxa_imposto: '14', movimenta_stock: true },
  { id: 2, codigo: 'P002', nome: 'Areia', preco_unitario: '50.00', taxa_imposto: '14', movimenta_stock: true },
];

const PEDIDO = {
  id: 5,
  numero_pedido: 'PC-5',
  nome_requerente: 'Ana',
  data: '2026-10-01',
  estado: 'APROVADO',
  descricao: null,
  observacoes: null,
  linhas: [
    { id: 11, produto_id: 1, descricao: null, quantidade: '10' },
    { id: 12, produto_id: 2, descricao: null, quantidade: '5' },
  ],
};

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={['/compras/propostas/nova?pedido=5']}>
        <NovaProposta />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  obter.mockReset().mockImplementation((url: string) => {
    if (url === '/logistica/produtos/catalogo') return Promise.resolve(CATALOGO);
    if (url === '/compras/pedidos/5') return Promise.resolve(PEDIDO);
    return Promise.resolve(null);
  });
  obterPagina.mockReset().mockImplementation(() => Promise.resolve({ itens: [], total: 0, pagina: 1, por_pagina: 200 }));
});

describe('NovaProposta — colagem directa do preço e IVA (linhas fixas pelo pedido)', () => {
  it('preenche preço e IVA a partir da célula clicada, sem criar linhas novas', async () => {
    const { container } = montar();
    await waitFor(() => expect(container.querySelectorAll('[data-colar-linha]').length).toBe(2));
    const celulaPreco = container.querySelector('[data-colar-linha="0"] [data-colar-coluna="0"] input') as HTMLElement;
    fireEvent.paste(celulaPreco, { clipboardData: { getData: () => '95\t14\n48\t15\n999\t20' } });
    await screen.findByText(/Preços actualizados em 2 linha\(s\)\./);
    const linhas = container.querySelectorAll('[data-colar-linha]');
    expect(linhas).toHaveLength(2); // a 3.ª linha colada (sem artigo correspondente) é ignorada
  }, 60_000);
});
