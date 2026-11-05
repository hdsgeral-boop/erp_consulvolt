import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AMinhaEquipa } from './AMinhaEquipa';
import { enviar } from '@/api/cliente';

const EQUIPA = {
  ciclo: { id: 1, nome: 'C', ano: 2026, periodo: 'ANUAL', estado: 'ABERTO' },
  membros: [{
    colaborador_id: 5, nome: 'Ana', feedbacks: [],
    criterios: [{ id: 1, chave: 'pontualidade', nome: 'Pontualidade', tipo: 'CRITERIO' }],
    objetivos: [{ id: 2, chave: 'vendas', nome: 'Vendas', tipo: 'OBJECTIVO', natureza: 'QUANTITATIVO' }],
    avaliacao: {
      estado: 'RASCUNHO', fase: 'POR_AVALIAR', nota_final: null,
      criterios: [{ chave: 'pontualidade', nota: 4, comentario: 'bom' }],
      objetivos: [{ chave: 'vendas', atingido: 90, nota_qual: null }],
    },
  }],
};

vi.mock('@/api/cliente', () => ({
  obter: vi.fn(() => Promise.resolve(EQUIPA)),
  obterPagina: vi.fn(),
  enviar: vi.fn(() => Promise.resolve({ dados: {}, mensagem: 'ok' })),
}));

describe('AMinhaEquipa — a avaliação enviada leva a chave de cada critério/objectivo (subcampo sem Form.Item)', () => {
  it('Gravar rascunho envia criterios[].chave e objetivos[].chave', async () => {
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><AMinhaEquipa /></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: /Avaliar/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Gravar rascunho/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalled());
    const dados = vi.mocked(enviar).mock.calls.at(-1)![2] as { criterios: { chave: string; nota: number }[]; objetivos: { chave: string; atingido: number }[]; concluir: boolean };
    expect(dados.criterios).toEqual([{ chave: 'pontualidade', nota: 4, comentario: 'bom' }]);
    expect(dados.objetivos).toMatchObject([{ chave: 'vendas', atingido: 90 }]);
    expect(dados.concluir).toBe(false);
  }, 60_000);
});
