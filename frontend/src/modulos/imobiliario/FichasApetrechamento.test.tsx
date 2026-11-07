import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { FICHAS } from './fixturesObras';

vi.setConfig({ testTimeout: 120_000 });
configure({ asyncUtilTimeout: 15_000 });

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));

import FichasApetrechamento from './FichasApetrechamento';

const ecra = (ui: ReactNode) =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>);

const LISTAS = { tipologias: [{ valor: 'T2', rotulo: 'T2' }], finalidades: [{ valor: 'HABITACAO', rotulo: 'Habitação' }] };

beforeEach(() => {
  obterMock.mockReset(); enviarMock.mockReset();
  permissoes = new Set(['imo_fichas_view', 'imo_ficha_editar']);
  obterMock.mockImplementation((url: string) => {
    if (url === '/imobiliario/listas') return Promise.resolve(LISTAS);
    if (url === '/imobiliario/fichas') return Promise.resolve(FICHAS);
    if (url === '/imobiliario/obras/artigos') return Promise.resolve([{ id: 11, codigo: 'CAMA', nome: 'Cama de casal', de_armazem: true }]);
    return Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: { id: 4, n_itens: 1 }, mensagem: 'Ficha gravada.' });
});

describe('Gestão Imobiliária — Fichas de Apetrechamento', () => {
  it('lista as fichas com tipologia, finalidade, artigos e o estado em texto', async () => {
    ecra(<FichasApetrechamento />);
    const linha = (await screen.findByText('T2 mobilado')).closest('tr') as HTMLElement;
    expect(within(linha).getByText('Habitação')).toBeInTheDocument();
    expect(within(linha).getByText('Activa')).toBeInTheDocument();
    expect(within(linha).getByText('2')).toHaveAttribute('title', expect.stringContaining('2 × CAMA — Cama de casal'));
    expect(within(linha).getByText('Kit base')).toBeInTheDocument();
    const loja = screen.getByText('Loja').closest('tr') as HTMLElement;
    expect(within(loja).getByText('Inactiva')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Editar a ficha T2 mobilado' })).toBeInTheDocument();
  });

  it('sem permissão de edição não há botões de criar nem de editar', async () => {
    permissoes = new Set(['imo_fichas_view']);
    ecra(<FichasApetrechamento />);
    await screen.findByText('T2 mobilado');
    expect(screen.queryByRole('button', { name: /Nova ficha/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Editar a ficha/ })).toBeNull();
  });

  it('o nome é obrigatório e a ficha grava-se com as linhas escritas (descrição, quantidade, opcional)', async () => {
    ecra(<FichasApetrechamento />);
    fireEvent.click(await screen.findByRole('button', { name: /Nova ficha/ }));
    const d = await screen.findByRole('dialog', { name: 'Nova ficha de apetrechamento' });
    fireEvent.click(within(d).getByRole('button', { name: 'Gravar' }));
    expect(await within(d).findByText('Dê um nome à ficha de apetrechamento.')).toBeInTheDocument();
    expect(enviarMock).not.toHaveBeenCalled();
    fireEvent.change(within(d).getByLabelText('Nome'), { target: { value: 'Escritório' } });
    fireEvent.change(within(d).getByLabelText('Descrição da linha 1'), { target: { value: 'Secretária' } });
    fireEvent.change(within(d).getByLabelText('Quantidade da linha 1'), { target: { value: '3' } });
    fireEvent.click(within(d).getByRole('switch', { name: 'Obrigatório na linha 1' }));   // passa a opcional
    fireEvent.click(within(d).getByRole('button', { name: /Artigo$/ }));
    fireEvent.change(within(d).getByLabelText('Descrição da linha 2'), { target: { value: 'Cadeira' } });
    fireEvent.click(within(d).getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(1));
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, { nome: string; itens: Record<string, unknown>[] }];
    expect([metodo, url, corpo.nome]).toEqual(['post', '/imobiliario/fichas', 'Escritório']);
    expect(corpo.itens).toEqual([
      { produto_id: null, descricao: 'Secretária', quantidade: 3, obrigatorio: false }, { produto_id: null, descricao: 'Cadeira', quantidade: 1, obrigatorio: true }]);
  });

  it('editar uma ficha mostra as linhas actuais e envia um PUT; retirar uma linha tira-a do envio', async () => {
    ecra(<FichasApetrechamento />);
    fireEvent.click(await screen.findByRole('button', { name: 'Editar a ficha T2 mobilado' }));
    const d = await screen.findByRole('dialog', { name: 'Editar a ficha «T2 mobilado»' });
    expect(within(d).getByDisplayValue('Mesa de jantar')).toBeInTheDocument();
    expect(within(d).getByRole('button', { name: 'Apagar' })).toBeInTheDocument();
    fireEvent.click(within(d).getByRole('button', { name: 'Retirar a linha 2' }));
    fireEvent.click(within(d).getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(1));
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, { itens: { produto_id: number | null }[]; activo: boolean }];
    expect([metodo, url, corpo.activo]).toEqual(['put', '/imobiliario/fichas/2', true]);
    expect(corpo.itens).toHaveLength(1);
    expect(corpo.itens[0].produto_id).toBe(11);
  });
});
