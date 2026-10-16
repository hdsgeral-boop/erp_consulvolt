import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';
import type { Estrutura as DadosEstrutura } from './comum/arvore';

/** Estrutura orgânica — «Nova unidade», «Cargo» (posto) e «Novo cargo» com as vistas Grade | Linhas. */
vi.setConfig({ testTimeout: 420_000 });

const ESTRUTURA: DadosEstrutura = {
  sem_unidade: 0,
  unidades: [
    { id: 1, codigo: 'DG', nome: 'Direcção-Geral', tipo: 'DIRECCAO_GERAL', unidade_organica_pai_id: null, colaborador_responsavel_id: null, missao: null, atribuicoes: null, centro_custo_id: null, unidade_negocio_id: null, ordem: 1, ativo: true, apoio: false, cor: null, disposicao: null, membros: 0, postos: [] },
  ],
};
const CARGOS = [{ id: 5, nome: 'Contabilista' }, { id: 6, nome: 'Motorista' }];

const obterMock = vi.fn();
const enviarMock = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, menu: [] }) }));
vi.mock('@/modulos/rh/comum/consultas', () => ({
  useColaboradores: () => ({ lista: [], mapa: new Map(), isLoading: false, nome: () => '—' }),
  useCargos: () => ({ lista: CARGOS, isLoading: false, nome: (id: number | null) => CARGOS.find((c) => c.id === id)?.nome ?? '—' }),
}));
vi.mock('@/modulos/rh/comum/componentes', () => ({ SeletorColaborador: () => null }));
vi.mock('@/modulos/contab/comum/dados', () => ({ useTabelaAux: () => ({ data: [] }), useUnidadesNegocio: () => ({ data: [] }) }));

import { dadosPosto, dadosUnidade } from './Estrutura';

const envolver = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );
const celula = (rotulo: string, n: number) => screen.getByLabelText(`${rotulo}, linha ${n}`) as HTMLInputElement;
const escrever = (el: HTMLElement, v: string) => fireEvent.change(el, { target: { value: v } });
const posts = (url: string) => enviarMock.mock.calls.filter((c) => c[0] === 'post' && c[1] === url);
async function linhas(dialogo: HTMLElement) {
  fireEvent.click(within(dialogo).getByRole('button', { name: /Linhas/ }));
  await screen.findByTestId('painel-linhas');
}
async function registar() {
  fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
  fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  const prefs: { nome: string; valor: unknown }[] = [];
  obterMock.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    if (url === '/rh/estrutura') return Promise.resolve(ESTRUTURA);
    return Promise.resolve([]);
  });
  enviarMock.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: { id: 99 }, mensagem: 'ok' });
  });
});

describe('transformações comuns à grade e às linhas', () => {
  it('unidade e cargo: caixas 1/0 (ou booleanas) em booleano, «Activa» por omissão', () => {
    expect(dadosUnidade({ nome: 'Compras', tipo: 'DEPARTAMENTO', apoio: 1, cor: { x: 1 } })).toMatchObject({ apoio: true, ativo: true, cor: null });
    expect(dadosUnidade({ nome: 'X', ativo: false, apoio: false, cor: '#ff0000' })).toMatchObject({ apoio: false, ativo: false, cor: '#ff0000' });
    expect(dadosPosto({ cargo_funcao_id: 5, vagas: 2, chefia: 0 }, 1)).toEqual({ cargo_funcao_id: 5, vagas: 2, chefia: false, unidade_organica_id: 1 });
  });
});

describe('Estrutura orgânica — Linhas', () => {
  it('«Nova unidade» em Linhas grava as unidades abaixo da seleccionada; a grade tem secções recolhíveis', async () => {
    const { default: Estrutura } = await import('./Estrutura');
    envolver(<Estrutura />);
    await screen.findByRole('navigation', { name: 'Unidades' }, { timeout: 60_000 });
    fireEvent.click(screen.getAllByRole('button', { name: /Nova unidade/ })[0]);
    const dialogo = await screen.findByRole('dialog');
    expect(within(dialogo).getByRole('button', { name: /(^| )Identificação$/ })).toHaveAttribute('aria-expanded', 'true');
    expect(within(dialogo).getByRole('button', { name: /(^| )Apresentação no organigrama$/ })).toHaveAttribute('aria-expanded', 'false');
    await linhas(dialogo);
    escrever(celula('Nome', 1), 'Compras');
    escrever(celula('Nome', 2), 'Logística');
    await registar();
    await waitFor(() => expect(posts('/rh/estrutura/unidades')).toHaveLength(2));
    expect(posts('/rh/estrutura/unidades').map((c) => c[2])).toEqual([
      expect.objectContaining({ nome: 'Compras', tipo: 'DEPARTAMENTO', unidade_organica_pai_id: 1, ativo: true, apoio: false, cor: null }),
      expect.objectContaining({ nome: 'Logística', tipo: 'DEPARTAMENTO', unidade_organica_pai_id: 1 }),
    ]);
  });

  it('«Cargo» da unidade em Linhas exige a função e grava os cargos com a unidade', async () => {
    const { default: Estrutura } = await import('./Estrutura');
    envolver(<Estrutura />);
    await screen.findByRole('navigation', { name: 'Unidades' }, { timeout: 60_000 });
    fireEvent.click(screen.getByRole('button', { name: /^Cargo$/ }));
    const dialogo = await screen.findByRole('dialog');
    expect(within(dialogo).getByText('Novo cargo — Direcção-Geral')).toBeInTheDocument();
    await linhas(dialogo);
    escrever(celula('Título do cargo', 1), 'Sem função');
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    expect(await screen.findByText('Falta: Função.')).toBeInTheDocument();
    expect(posts('/rh/estrutura/postos')).toHaveLength(0);
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    escrever(await screen.findByLabelText('Linhas a colar'), 'Função\tTítulo do cargo\tVagas\nContabilista\tContabilista sénior\t2\nMotorista\t\t3');
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
    fireEvent.click(screen.getByRole('button', { name: 'Remover linha 1' }));
    await registar();
    await waitFor(() => expect(posts('/rh/estrutura/postos')).toHaveLength(2));
    expect(posts('/rh/estrutura/postos')[0][2]).toMatchObject({ cargo_funcao_id: 5, titulo: 'Contabilista sénior', vagas: 2, chefia: false, unidade_organica_id: 1 });
    expect(posts('/rh/estrutura/postos')[1][2]).toMatchObject({ cargo_funcao_id: 6, vagas: 3, unidade_organica_id: 1 });
  });

  it('«Novo cargo» (Cargos e funções) em Linhas grava pelo POST /rh/cargos', async () => {
    const { default: Estrutura } = await import('./Estrutura');
    envolver(<Estrutura />);
    fireEvent.click(await screen.findByRole('tab', { name: 'Cargos e funções' }, { timeout: 60_000 }));
    fireEvent.click(await screen.findByRole('button', { name: /Novo cargo/ }));
    await linhas(await screen.findByRole('dialog'));
    escrever(celula('Nome', 1), 'Tesoureiro');
    await registar();
    await waitFor(() => expect(posts('/rh/cargos')).toHaveLength(1));
    expect(posts('/rh/cargos')[0][2]).toEqual({ nome: 'Tesoureiro', descricao: null });
  });
});
