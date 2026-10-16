import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

vi.setConfig({ testTimeout: 180_000 });

const ARMAZENS = [{ id: 1, nome: 'Armazém Central', codigo: null, localizacao: 'Luanda', predefinido: true }];

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { total: 0, pagina: 1, por_pagina: 30, ultima_pagina: 1 } }),
  descarregar: () => Promise.resolve(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: () => true, menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

import Armazens, { dadosArmazem } from './Armazens';
import { dadosAjuste } from './NiveisStock';

const posts = (url: string) => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === url);

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(
    url.startsWith('/sistema/preferencias') ? [...prefs]
      : url === '/logistica/armazens' ? ARMAZENS
      : url === '/logistica/paridade/armazens/valor' ? { total: '0.00', por_armazem: [] }
      : [],
  ));
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('Regras partilhadas entre Grade e Linhas (stock)', () => {
  it('armazém: vazios a nulo e «predefinido» da grade (caixa) ou das linhas (Sim/Não)', () => {
    expect(dadosArmazem({ nome: ' Loja ', codigo: '', localizacao: undefined, predefinido: false })).toEqual({ nome: 'Loja', codigo: null, localizacao: null, predefinido: false });
    expect(dadosArmazem({ nome: 'Loja', predefinido: 'S' })).toMatchObject({ predefinido: true });
  });

  it('ajuste: o custo unitário só segue nas entradas; datas da grade e das linhas iguais', async () => {
    const dayjs = (await import('dayjs')).default;
    const base = { produto_id: 3, armazem_id: 1, quantidade: 2, motivo: ' Quebra no transporte ' };
    expect(dadosAjuste({ ...base, sentido: 'S', custo_unitario: 100, data: '2026-10-01' })).toEqual({ ...base, motivo: 'Quebra no transporte', sentido: 'S', data: '2026-10-01', custo_unitario: undefined });
    expect(dadosAjuste({ ...base, sentido: 'E', custo_unitario: 100, data: dayjs('2026-10-01') })).toMatchObject({ sentido: 'E', custo_unitario: 100, data: '2026-10-01' });
  });
});

describe('Registar Armazém — Grade | Linhas', () => {
  it('em Linhas grava um armazém por linha pelo mesmo POST; editar só tem a grade', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><Armazens /></MemoryRouter>
      </QueryClientProvider>,
    );
    await screen.findByText('Armazém Central', undefined, { timeout: 60_000 });
    fireEvent.click(screen.getByRole('button', { name: /Novo Armazém/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    await screen.findByTestId('painel-linhas');
    fireEvent.paste(screen.getByLabelText('Nome, linha 1'), { clipboardData: { getData: () => 'Loja 1\tL1\tViana\nLoja 2\t\tCacuaco\n' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    await waitFor(() => expect(posts('/logistica/armazens')).toHaveLength(2), { timeout: 20_000 });
    expect(posts('/logistica/armazens')[0][2]).toEqual({ nome: 'Loja 1', codigo: 'L1', localizacao: 'Viana', predefinido: false });
    expect(posts('/logistica/armazens')[1][2]).toEqual({ nome: 'Loja 2', codigo: null, localizacao: 'Cacuaco', predefinido: false });
    // resumo do legado no fim da gravação (o modal fecha; no jsdom o AntD mantém o conteúdo da animação de saída)
    await waitFor(() => expect(document.body.textContent).toMatch(/2 de 2 linha\(s\) gravada\(s\)/), { timeout: 60_000 });

    fireEvent.click(screen.getByRole('button', { name: /Editar Armazém/ }));
    expect((await screen.findAllByText('Editar Armazém')).length).toBeGreaterThan(0);
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
  }, 420_000);
});
