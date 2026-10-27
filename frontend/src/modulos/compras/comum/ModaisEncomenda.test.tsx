import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { ModalRececao } from './ModaisEncomenda';
import type { EncomendaCompra } from './tipos';

vi.mock('@/api/cliente', () => ({
  obter: vi.fn(),
  obterPagina: vi.fn(),
  enviar: vi.fn(),
}));

const ENCOMENDA = {
  id: 7,
  numero_encomenda: 'EC-7',
  linhas: [
    { id: 101, produto_id: 1, produto: { id: 1, codigo: 'P001', nome: 'Cimento' }, descricao: null, quantidade: '10', quantidade_recebida: '0' },
    { id: 102, produto_id: 2, produto: { id: 2, codigo: 'P002', nome: 'Areia' }, descricao: null, quantidade: '5', quantidade_recebida: '0' },
  ],
} as unknown as EncomendaCompra;

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <ModalRececao encomenda={ENCOMENDA} aberto aoFechar={() => {}} />
    </QueryClientProvider>,
  );
}

describe('ModalRececao — colagem directa da quantidade recebida (linhas fixas pela encomenda)', () => {
  it('preenche a quantidade recebida a partir da célula clicada, sem criar linhas novas', async () => {
    const { baseElement } = montar();
    await waitFor(() => expect(baseElement.querySelectorAll('[data-colar-linha]').length).toBe(2));
    const celula = baseElement.querySelector('[data-colar-linha="0"] [data-colar-coluna="0"] input') as HTMLElement;
    fireEvent.paste(celula, { clipboardData: { getData: () => '8\n4\n999' } });
    await screen.findByText(/Quantidades actualizadas em 2 linha\(s\)\./);
    const linhas = baseElement.querySelectorAll('[data-colar-linha]');
    expect(linhas).toHaveLength(2); // a 3.ª linha colada (sem artigo correspondente) é ignorada
  }, 60_000);
});
