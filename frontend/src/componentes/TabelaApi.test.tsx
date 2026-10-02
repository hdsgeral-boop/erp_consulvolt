import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Tag } from 'antd';
import type { Pagina } from '@/api/tipos';

interface Linha {
  id: number;
  nome: string;
  valor: string;
  estado: string;
}

const TOTAL = 450;
const todas: Linha[] = Array.from({ length: TOTAL }, (_, i) => ({ id: i + 1, nome: `Artigo ${i + 1}`, valor: `${i + 1}.50`, estado: i % 2 ? 'ACTIVO' : 'INACTIVO' }));

const obterPagina = vi.fn(async (_url: string, params?: Record<string, unknown>): Promise<Pagina<Linha>> => {
  const pagina = Number(params?.pagina ?? 1);
  const por = Number(params?.por_pagina ?? 25);
  return { itens: todas.slice((pagina - 1) * por, pagina * por), paginacao: { pagina_atual: pagina, por_pagina: por, total: TOTAL, ultima_pagina: Math.ceil(TOTAL / por) } };
});
vi.mock('@/api/cliente', () => ({ obterPagina: (url: string, p?: Record<string, unknown>) => obterPagina(url, p), obter: vi.fn() }));

const imprimirDocumento = vi.fn(async (_o: unknown) => ({ papel: 'A4', orientacao: 'retrato', larguraUtilMm: 190, escala: 1, quebrarTexto: false }));
vi.mock('./impressao/motor', () => ({ imprimirDocumento: (o: unknown) => imprimirDocumento(o), prepararDocumento: vi.fn() }));

vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ empresa: { id: 7, nome: 'Demo E2E Comércio, Lda' }, utilizador: { id: 1, nome_utilizador: 'e2e.admin', nome_completo: 'Admin E2E' } }),
}));

import { colunasParaImpressao, obterTodasAsPaginas, TabelaApi, type ColunaApi } from './TabelaApi';

const colunas: ColunaApi<Linha>[] = [
  { title: 'Nome', dataIndex: 'nome' },
  { title: 'Estado', dataIndex: 'estado', render: (e: string) => <Tag color="green">{e === 'ACTIVO' ? 'Activo' : 'Inactivo'}</Tag> },
  { title: 'Valor', dataIndex: 'valor', align: 'right', totalImpressao: () => 'TOTAL-X' },
  { title: 'Interno', dataIndex: 'id', exportar: false },
  { title: '', key: 'accoes', render: () => <button>Editar</button> },
];

describe('TabelaApi — impressão', () => {
  beforeEach(() => {
    obterPagina.mockClear();
    imprimirDocumento.mockClear();
  });

  it('obterTodasAsPaginas percorre todas as páginas com os mesmos filtros', async () => {
    const r = await obterTodasAsPaginas<Linha>('/x', { estado: 'ACTIVO' });
    expect(r.itens).toHaveLength(TOTAL);
    expect(r.truncado).toBe(false);
    expect(obterPagina).toHaveBeenCalledTimes(3);
    expect(obterPagina.mock.calls.map((c) => c[1])).toEqual([
      { estado: 'ACTIVO', pagina: 1, por_pagina: 200 },
      { estado: 'ACTIVO', pagina: 2, por_pagina: 200 },
      { estado: 'ACTIVO', pagina: 3, por_pagina: 200 },
    ]);
  });

  it('respeita o limite e marca como truncado', async () => {
    const r = await obterTodasAsPaginas<Linha>('/x', {}, 250);
    expect(r.itens).toHaveLength(250);
    expect(r.truncado).toBe(true);
    expect(r.total).toBe(TOTAL);
  });

  it('colunas de impressão: só as visíveis com título, texto simples do render, totais', () => {
    const c = colunasParaImpressao(colunas, todas.slice(0, 2));
    expect(c.map((x) => x.titulo)).toEqual(['Nome', 'Estado', 'Valor']);
    expect(c[1].valor(todas[1], 1)).toBe('Activo');
    expect(c[2].alinhamento).toBe('direita');
    expect(c[2].total).toBe('TOTAL-X');
  });

  it('o botão PDF vai buscar todas as páginas e imprime com o motor comum', async () => {
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    cliente.setQueryData(['sistema', 'identidade', 7], { id: 7, nome: 'Demo E2E Comércio, Lda', logotipo: null });
    render(
      <QueryClientProvider client={cliente}>
        <TabelaApi<Linha> url="/artigos" filtros={{ estado: 'X' }} chaveConsulta={['artigos']} columns={colunas} impressao={{ titulo: 'Lista de artigos', filtros: ['Estado: X'] }} />
      </QueryClientProvider>,
    );
    await screen.findByText('Artigo 1');
    const pdf = screen.getByRole('button', { name: /PDF/ });
    await waitFor(() => expect(pdf).not.toBeDisabled());
    obterPagina.mockClear();
    fireEvent.click(pdf);
    await waitFor(() => expect(imprimirDocumento).toHaveBeenCalledTimes(1), { timeout: 20_000 });
    expect(obterPagina.mock.calls.every((c) => c[0] === '/artigos' && c[1]?.estado === 'X')).toBe(true);
    expect(obterPagina.mock.calls.map((c) => c[1]?.pagina)).toEqual([1, 2, 3]);
    const opcoes = (imprimirDocumento.mock.calls[0] as unknown[])[0] as { titulo: string; conteudo: string; identidade: { nome: string }; utilizador: string; filtros: string[] };
    expect(opcoes.titulo).toBe('Lista de artigos');
    expect(opcoes.identidade.nome).toBe('Demo E2E Comércio, Lda');
    expect(opcoes.utilizador).toBe('Admin E2E');
    expect(opcoes.filtros).toContain('450 registo(s)');
    expect(opcoes.conteudo).toContain('Artigo 450');
    expect(opcoes.conteudo).toContain('<td>Inactivo</td>');
    expect(opcoes.conteudo).not.toContain('Editar');
    expect(opcoes.conteudo).toContain('TOTAL-X');
  }, 30_000);

  it('sem `impressao` mantém-se a tabela simples (API compatível)', async () => {
    const cliente = new QueryClient();
    render(
      <QueryClientProvider client={cliente}>
        <TabelaApi<Linha> url="/artigos" chaveConsulta={['artigos2']} columns={colunas} />
      </QueryClientProvider>,
    );
    await screen.findByText('Artigo 1');
    expect(screen.queryByRole('button', { name: /PDF/ })).toBeNull();
  }, 30_000);
});
