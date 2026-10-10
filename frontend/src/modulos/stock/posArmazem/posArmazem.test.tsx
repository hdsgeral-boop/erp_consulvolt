import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

vi.setConfig({ testTimeout: 60_000 });

const respostas: Record<string, unknown> = {};
const obter = vi.fn(async (url: string) => respostas[url] ?? []);
const enviar = vi.fn(async (_m: string, _u: string, _d?: unknown) => ({ dados: {}, mensagem: 'Feito.' }));
vi.mock('@/api/cliente', () => ({
  obter: (url: string) => obter(url),
  obterPagina: vi.fn(async () => ({ dados: [], meta: { total: 0 } })),
  enviar: (m: string, u: string, d?: unknown) => enviar(m, u, d),
}));

let permissoes: string[] = [];
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...c: string[]) => c.some((x) => permissoes.includes(x)) }) }));

import { Balcao } from './Balcao';
import { Senhas } from './Senhas';

function comConsulta(no: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={cliente}>{no}</QueryClientProvider>;
}

beforeEach(() => {
  obter.mockClear();
  enviar.mockClear();
  permissoes = [];
  for (const k of Object.keys(respostas)) delete respostas[k];
  try {
    localStorage.clear();
  } catch {
    /* sem armazenamento */
  }
});

describe('POS de armazém — Atendimento ao Balcão', () => {
  beforeEach(() => {
    respostas['/logistica/armazens'] =[{ id: 5, nome: 'Central', predefinido: true }];
    respostas['/pos/armazem/artigos'] = [
      { produto_id: 1, codigo: 'CAB-01', nome: 'Cabo eléctrico', preco_unitario: '1000.00', quantidade: '12.000', bloqueado: false },
      { produto_id: 2, codigo: 'DIS-02', nome: 'Disjuntor', preco_unitario: '500.00', quantidade: '0.000', bloqueado: false },
    ];
    respostas['/pos/armazem/acerto-stock'] = [
      { produto_id: 1, codigo: 'CAB-01', nome: 'Cabo eléctrico', global: '14.000', armazens: '12.000', local: '12.000', diferenca: '2.000', ajuste: '2.000', limitado: false, fica: '14.000' },
    ];
  });

  it('mostra os cartões, filtra Sem Stock, soma o carrinho e avisa do stock divergente', async () => {
    permissoes = ['pos_armazem_vender', 'pos_armazem_acerto'];
    render(comConsulta(<Balcao armazem={5} aoMudarArmazem={vi.fn()} />));
    expect(await screen.findByText('Cabo eléctrico', {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(screen.getAllByTestId('artigo-pos')).toHaveLength(2);
    expect(screen.getByText('Esgotado')).toBeInTheDocument();
    expect(screen.getAllByText('STOCK DISP.')).toHaveLength(2);
    expect(screen.getByText('Carrinho de Picking vazio.')).toBeInTheDocument();
    expect(await screen.findByText(/1 produto\(s\) têm stock global diferente da soma dos armazéns/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Acertar stock deste armazém/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Acrescentar Disjuntor' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar Cabo eléctrico' }));
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar Cabo eléctrico' }));
    expect(screen.getByTestId('total-picking').textContent).toMatch(/^2[\s.]?000,00 Kz$/);
    expect(screen.queryByText('Carrinho de Picking vazio.')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Sem Stock' }));
    expect(screen.getAllByTestId('artigo-pos')).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: 'Visualização em Lista' }));
    expect(localStorage.getItem('pos_wh_layout_mode')).toBe('lista');
    expect(screen.getByRole('button', { name: /Confirmar Saída e Expedir/ })).toBeInTheDocument();
  });

  it('sem permissões de venda nem de acerto não mostra as acções', async () => {
    permissoes = ['pos_armazem_view'];
    render(comConsulta(<Balcao armazem={5} aoMudarArmazem={vi.fn()} />));
    expect(await screen.findByText(/produto\(s\) têm stock global diferente/, {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Acertar stock deste armazém/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Confirmar Saída e Expedir/ })).toBeNull();
  });
});

describe('POS de armazém — Monitor de Chamada de Senhas', () => {
  beforeEach(() => {
    respostas['/pos/armazem/senhas'] = {
      data: '2026-10-08',
      armazem_id: 5,
      actual: { id: 3, codigo: 'P-003', numero: 3, estado: 'CHAMADA', chamadas: 1, chamada_em: '2026-10-08T10:00:00+01:00', criado_em: null },
      fila: [{ id: 4, codigo: 'P-004', numero: 4, estado: 'AGUARDA', chamadas: 0, chamada_em: null, criado_em: null }],
      chamadas: [{ id: 2, codigo: 'P-002', numero: 2, estado: 'CHAMADA', chamadas: 1, chamada_em: null, criado_em: null }],
    };
  });

  it('mostra a senha em chamada, a fila e as últimas; o operador chama a próxima', async () => {
    permissoes = ['pos_armazem_vender'];
    render(comConsulta(<Senhas armazem={5} />));
    expect(await screen.findByTestId('senha-actual', {}, { timeout: 15_000 })).toHaveTextContent('P-003');
    expect(within(screen.getByTestId('fila-senhas')).getByText('P-004')).toBeInTheDocument();
    expect(screen.getByText('P-002')).toBeInTheDocument();
    expect(screen.getByText('SENHA ATUAL EM CHAMADA')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Chamar próxima/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/pos/armazem/senhas/chamar', { armazem_id: 5 }));
    fireEvent.click(screen.getByRole('button', { name: /Tirar Nova Senha/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/pos/armazem/senhas', { armazem_id: 5 }));
  });

  it('só de consulta (monitor público): sem botões de operação', async () => {
    permissoes = ['pos_armazem_view'];
    render(comConsulta(<Senhas armazem={5} />));
    expect(await screen.findByTestId('senha-actual', {}, { timeout: 15_000 })).toHaveTextContent('P-003');
    expect(screen.queryByRole('button', { name: /Tirar Nova Senha/ })).toBeNull();
    expect(screen.getByRole('button', { name: /Voz ligada/ })).toBeInTheDocument();
  });
});
