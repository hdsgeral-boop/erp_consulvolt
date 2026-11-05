import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { ModalFaturaEncomenda, ModalRececao } from './ModaisEncomenda';
import { enviar } from '@/api/cliente';
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

describe('ModalRececao / ModalFaturaEncomenda — o envio leva o item_encomenda_id (subcampo sem Form.Item)', () => {
  it('recepção: as linhas enviadas trazem item_encomenda_id além da quantidade', async () => {
    vi.mocked(enviar).mockResolvedValue({ dados: {}, mensagem: 'ok' } as never);
    const { baseElement } = montar();
    await waitFor(() => expect(baseElement.querySelectorAll('[data-colar-linha]').length).toBe(2));
    fireEvent.change(screen.getByLabelText('N.º da guia do fornecedor'), { target: { value: 'G-1' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar recepção/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalled());
    const [, url, dados] = vi.mocked(enviar).mock.calls.at(-1) as [string, string, { linhas: { item_encomenda_id: number; quantidade: number }[] }];
    expect(url).toBe('/compras/encomendas/7/rececoes');
    expect(dados.linhas).toEqual([{ item_encomenda_id: 101, quantidade: 10 }, { item_encomenda_id: 102, quantidade: 5 }]);
  }, 60_000);

  it('factura: as linhas enviadas trazem item_encomenda_id, quantidade e taxa_imposto', async () => {
    vi.mocked(enviar).mockResolvedValue({ dados: {}, mensagem: 'ok' } as never);
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { baseElement } = render(
      <QueryClientProvider client={cliente}>
        <ModalFaturaEncomenda encomenda={ENCOMENDA} aberto aoFechar={() => {}} />
      </QueryClientProvider>,
    );
    await waitFor(() => expect(baseElement.querySelectorAll('input').length).toBeGreaterThan(3));
    fireEvent.change(screen.getByLabelText(/N\.º da factura|Número da factura|N\.º da fatura/i), { target: { value: 'F-1' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar factura/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalled());
    const [, url, dados] = vi.mocked(enviar).mock.calls.at(-1) as [string, string, { linhas: { item_encomenda_id: number }[] }];
    expect(url).toBe('/compras/encomendas/7/faturas');
    expect(dados.linhas.map((l) => l.item_encomenda_id)).toEqual([101, 102]);
  }, 60_000);
});
