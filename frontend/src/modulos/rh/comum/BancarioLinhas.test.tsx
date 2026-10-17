import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: () => Promise.resolve({
    itens: [{ id: 1, nome_completo: 'Ana Silva', nif: '500000001', estado: 'ACTIVO' }, { id: 2, nome_completo: 'Bruno Costa', nif: '500000002', estado: 'ACTIVO' }],
    paginacao: { ultima_pagina: 1 },
  }),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null, menu: [] }) }));
// o seletor de contas da contabilidade (só no formulário dos bancos) não interessa aqui
vi.mock('@/modulos/contab/comum/Seletores', () => ({ SeletorConta: () => null }));

import Bancario from '../Bancario';
import { ibanValido } from './regras';

vi.setConfig({ testTimeout: 480_000 });

/** IBAN angolano válido (dígitos de controlo calculados) a partir de 21 dígitos do NIB. */
function ibanAo(nib: string): string {
  for (let cc = 0; cc < 100; cc += 1) {
    const i = `AO${String(cc).padStart(2, '0')}${nib}`;
    if (ibanValido(i)) return i;
  }
  throw new Error('sem IBAN válido');
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    return url === '/rh/bancos' ? Promise.resolve([{ id: 3, nome: 'BFA', codigo: '0006' }]) : Promise.resolve([]);
  });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('Configurações bancárias › «Adicionar IBAN» (Grade | Linhas)', () => {
  it('um PUT por colaborador com o IBAN sem espaços; um IBAN inválido não deixa gravar nenhuma linha', async () => {
    const a = ibanAo('000600000123456789012');
    const b = ibanAo('000600000987654321098');
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={cliente}><MemoryRouter><Bancario /></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: /Vários IBAN/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    await screen.findByTestId('painel-linhas');
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(await screen.findByLabelText('Linhas a colar'), { target: { value: `Ana Silva\tBFA\t${a.replace(/(.{4})/g, '$1 ')}\n500000002\t0006\tAO06123` } });
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
    await waitFor(() => expect(screen.getAllByTestId('linha-formulario')).toHaveLength(2));
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    expect(await screen.findByText(/IBAN inválido/)).toBeInTheDocument();
    expect(enviar.mock.calls.filter((c) => String(c[1]).includes('coordenada-bancaria'))).toHaveLength(0);

    fireEvent.change(screen.getByLabelText('IBAN, linha 2'), { target: { value: b } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    const puts = () => enviar.mock.calls.filter((c) => String(c[1]).includes('coordenada-bancaria'));
    await waitFor(() => expect(puts()).toHaveLength(2));
    expect(puts()).toEqual([
      ['put', '/rh/colaboradores/1/coordenada-bancaria', { banco_id: 3, iban: a }],
      ['put', '/rh/colaboradores/2/coordenada-bancaria', { banco_id: 3, iban: b }],
    ]);
  });
});
