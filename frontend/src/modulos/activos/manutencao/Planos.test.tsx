import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { ModalPlano } from './Planos';
import { enviar } from '@/api/cliente';
import type { Plano } from './tipos';

vi.mock('@/api/cliente', () => ({ obter: vi.fn().mockResolvedValue([]), obterPagina: vi.fn().mockResolvedValue({ dados: [] }), enviar: vi.fn() }));

const PLANO = {
  id: 9, codigo: 'PM-1', nome: 'Revisão', tipo: 'PREVENTIVA', ativo_imobilizado_id: 3, categoria_ativo_id: null,
  periodicidade: { modo: 'TEMPO', intervalo: 3, unidade: 'MESES' }, proxima_data: '2026-12-01', tarefas: [],
  itens: [{ tipo: 'MATERIAL', produto_id: null, descricao: 'Óleo 15W40 (sem ficha)', quantidade: 2 }],
} as unknown as Plano;

describe('ModalPlano — o envio mantém a descrição do material previsto (subcampo sem Form.Item)', () => {
  it('um material só com descrição não se perde ao gravar', async () => {
    vi.mocked(enviar).mockResolvedValue({ dados: {}, mensagem: 'ok' } as never);
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ModalPlano plano={PLANO} aoFechar={() => {}} />
      </QueryClientProvider>,
    );
    fireEvent.click(await screen.findByRole('button', { name: /Gravar/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalled());
    const [metodo, url, dados] = vi.mocked(enviar).mock.calls.at(-1) as [string, string, { itens: { descricao: string; quantidade: number }[] }];
    expect(metodo).toBe('put');
    expect(url).toBe('/ativos/manutencao/planos/9');
    expect(dados.itens).toEqual([{ tipo: 'MATERIAL', produto_id: null, descricao: 'Óleo 15W40 (sem ficha)', quantidade: 2 }]);
  }, 60_000);
});
