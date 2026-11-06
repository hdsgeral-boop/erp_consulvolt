import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn(),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: () => true, empresa: { id: 1, nome: 'Empresa' }, empresas: [{ id: 1, nome: 'Empresa' }], utilizador: { id: 1, nome: 'Teste' } }),
}));

import { TabelaPecas } from './Tabelas';

vi.setConfig({ testTimeout: 300_000 });

/** Peça migrada do legado com 3 opções e a matriz serviço × opção (modo/tamanho) que a edição não pode apagar. */
const PECA = {
  id: 7,
  codigo: 'PC0007',
  nome: 'Fato',
  tecido: 'Lã',
  cor: null,
  unidade: 'PECA',
  preco: '10000.00',
  ativo: true,
  opcoes_preco_origem: 'LEGADO',
  opcoes_preco: [
    { modo: 'PECA', tamanho: '', preco: '10000.00' },
    { modo: 'KG', tamanho: '', preco: '800.00' },
    { modo: 'TAMANHO', tamanho: 'M', preco: '12000.00' },
  ],
  precos_servico: [
    { produto_id: 5, modo: 'KG', tamanho: '', preco: '900.00' },
    { produto_id: 5, modo: 'TAMANHO', tamanho: 'M', preco: '15000.00' },
  ],
};
const SERVICO = { id: 5, codigo: 'SV0001', nome: 'Lavar', lavandaria_grupo: 'LAVANDARIA', codigo_conta: '6221', taxa_imposto: '14', lavandaria_dias_entrega: 2, lavandaria_requer_orcamento: false, lavandaria_ativa: true };

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  obter.mockReset().mockImplementation((url: string) => {
    if (url === '/pos/lavandaria/pecas') return Promise.resolve([PECA]);
    if (url === '/pos/lavandaria/servicos') return Promise.resolve([SERVICO]);
    return Promise.resolve([]);
  });
  enviar.mockReset().mockResolvedValue({ dados: {}, mensagem: 'ok' });
});

describe('Lavandaria — editor de peças com opções de preço', () => {
  it('a tabela mostra as opções; editar sem tocar em nada reenvia opções e matriz completas (não apaga modo/tamanho)', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <TabelaPecas />
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Tamanho M 12 000,00')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Editar' }));
    expect(await screen.findByText('Peça Fato')).toBeInTheDocument();
    // 3 opções no editor, a matriz aberta (a peça tem preços por serviço)
    expect((await screen.findByLabelText('Preço da opção 3')) as HTMLInputElement).toHaveValue('12000,00');
    expect(screen.getByLabelText('Tamanho da opção 3')).toHaveValue('M');
    expect(screen.getByLabelText('SV0001 · Lavar — Quilograma (kg)')).toHaveValue('900,00');
    expect(screen.getByLabelText('SV0001 · Lavar — Tamanho M')).toHaveValue('15000,00');
    fireEvent.click(screen.getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviar).toHaveBeenCalled());
    const [metodo, url, dados] = enviar.mock.calls[0] as [string, string, Record<string, unknown>];
    expect([metodo, url]).toEqual(['put', '/pos/lavandaria/pecas/7']);
    expect(dados).toMatchObject({
      nome: 'Fato',
      opcoes_preco: [
        { modo: 'PECA', tamanho: '', preco: 10000 },
        { modo: 'KG', tamanho: '', preco: 800 },
        { modo: 'TAMANHO', tamanho: 'M', preco: 12000 },
      ],
      precos_servico: [
        { produto_id: 5, modo: 'KG', tamanho: '', preco: 900 },
        { produto_id: 5, modo: 'TAMANHO', tamanho: 'M', preco: 15000 },
      ],
    });
    expect(dados).not.toHaveProperty('unidade');
  });
});
