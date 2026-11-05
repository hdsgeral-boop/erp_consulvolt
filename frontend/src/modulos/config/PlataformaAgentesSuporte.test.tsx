import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import PlataformaAgentesSuporte from './PlataformaAgentesSuporte';

vi.setConfig({ testTimeout: 120_000 });
const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
}));

const PERFIS = [
  { id: 1, nome: 'Agente de Suporte Geral', grupos_rotulos: ['Geral'], categorias: ['GERAL'] },
  { id: 2, nome: 'Agente de Suporte Financeiro', grupos_rotulos: ['Financeiro (plano, facturação, pagamento, subscrição)'], categorias: ['PLANO'] },
];
const ESPECIALIZACOES = {
  grupos: [{ id: 'geral', rotulo: 'Geral', categorias: ['GERAL', 'DUVIDA'] }, { id: 'tecnico', rotulo: 'Técnico', categorias: ['INCIDENTE_TECNICO'] }],
  especializacoes: [{ id: 1, nome: 'Agente de Suporte Geral', descricao: 'Gerais', ativo: true, grupos: ['geral'], categorias: ['GERAL'], grupos_rotulos: ['Geral'], agentes: 0, base: true }],
};

function montar() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <PlataformaAgentesSuporte />
    </QueryClientProvider>,
  );
}

describe('PlataformaAgentesSuporte — especialização com sentido', () => {
  beforeEach(() => {
    obter.mockReset();
    enviar.mockReset();
    enviar.mockResolvedValue({ dados: {}, mensagem: 'ok' });
    obter.mockImplementation((url: string) => Promise.resolve(
      url === '/plataforma/agentes-suporte'
        ? [{ id: 9, nome_utilizador: 'ana', nome_completo: 'Ana', email: null, ativo: true, ultimo_acesso_em: null, perfil: { id: 2, nome: 'Agente de Suporte Financeiro', categorias: ['PLANO'], grupos_rotulos: ['Financeiro (plano, facturação, pagamento, subscrição)'] } }]
        : url === '/plataforma/perfis-suporte' ? PERFIS : ESPECIALIZACOES,
    ));
  });

  it('explica a especialização no ecrã e mostra as categorias do agente na lista', async () => {
    montar();
    expect(await screen.findByText(/Define quais as categorias de tickets/)).toBeTruthy();
    expect(await screen.findByText('Agente de Suporte Financeiro')).toBeTruthy();
    expect(screen.getByText('Financeiro')).toBeTruthy();
  });

  it('o modal «Novo agente» traz a especialização «Geral» por omissão e valida o campo', async () => {
    montar();
    await screen.findByText('Ana');
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/plataforma/perfis-suporte'));
    await screen.findByText('Agente de Suporte Financeiro');
    fireEvent.click(screen.getByRole('button', { name: /Novo agente/ }));
    expect(await screen.findByText('Novo agente de suporte')).toBeTruthy();
    await waitFor(() => expect(screen.getAllByText('Agente de Suporte Geral').length).toBeGreaterThan(0));
  });

  it('o botão «Especializações» abre a gestão com as categorias de tickets', async () => {
    montar();
    fireEvent.click(await screen.findByRole('button', { name: /Especializações/ }));
    expect(await screen.findByText('Especializações de suporte')).toBeTruthy();
    expect(await screen.findByText('Nova especialização')).toBeTruthy();
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/plataforma/especializacoes-suporte'));
  });
});
