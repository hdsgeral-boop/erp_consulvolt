import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';

vi.setConfig({ testTimeout: 180_000 });

// dados fictícios
const RESUMO = { edificios: [{ id: 1, codigo: 'AST-100', descricao: 'Sede fictícia', estado: 'ACTIVO', andares: 2, portas: 6, bens: 3, valor_bens: '4500.00', inventarios: 1 }],
  sem_local: 4, inventarios: 1 };
const PLANTA = {
  edificio: { id: 1, codigo: 'AST-100', descricao: 'Sede fictícia', estado: 'ACTIVO' },
  andares: [{ id: 10, numero: '0', nome: 'Rés-do-chão', notas: null, ordem: 0, n_portas: 1, n_bens: 1, valor: '1500.00',
    portas: [{ id: 100, numero: 'A1', nome: null, tipo: 'Gabinete', capacidade: null, ordem: 1, andar_id: 10, n_bens: 1, valor: '1500.00',
      bens: [{ id: 5, codigo: 'AST-005', descricao: 'Secretária fictícia', valor_aquisicao: '1500.00', categoria: 'Mobiliário' }] }] }],
  sem_local: [{ id: 6, codigo: 'AST-006', descricao: 'Cadeira fictícia', valor_aquisicao: '200.00' }],
  totais: { andares: 1, portas: 1, bens: 1, valor: '1500.00' },
};
const PLANOS = [{ id: 1, codigo: 'PM-2026/0001', nome: 'Revisão trimestral do gerador', ativo_imobilizado_id: 9, categoria_ativo_id: null, tipo: 'PREVENTIVA',
  periodicidade: { modo: 'TEMPO', intervalo: 3, unidade: 'MESES' }, proxima_data: '2026-10-10', proximo_contador: null, contador_atual: '0.00', antecedencia_dias: 7,
  duracao_horas: '0.000', responsavel: null, instrucoes: null, tarefas: [], itens: [], ativo: true, ultima_data: null, execucoes: 0,
  situacao: { chave: 'VENCIDO', rotulo: 'Em atraso 4 dia(s)' }, alvo: 'AST-009 — Gerador fictício', ordens: 0 }];

const obterMock = vi.fn();
const enviarMock = vi.fn(() => Promise.resolve({ dados: null, mensagem: 'ok' }));
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obterMock(...a),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { total: 0, pagina: 1, por_pagina: 50, ultima_pagina: 1 } }),
  enviar: (...a: unknown[]) => enviarMock(...(a as [])),
  descarregar: () => Promise.resolve(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

const envolver = (ui: ReactNode, caminho = '/') =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[caminho]}>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

beforeEach(() => {
  obterMock.mockReset();
  enviarMock.mockClear();
  obterMock.mockImplementation((url: string) => Promise.resolve(
    url === '/ativos/edificios' ? RESUMO : url.endsWith('/planta') ? PLANTA : url === '/ativos/manutencao/planos' ? PLANOS
      : url === '/ativos/manutencao/resumo' ? { ordens: { ABERTAS: 0, CONCLUIDA: 0, CANCELADA: 0, TODAS: 0, EM_ATRASO: 0 }, planos_por_vencer: 1, planos_por_vencer_nomes: [] } : [],
  ));
});

describe('Edifícios e Locais (Fase F)', () => {
  it('cartões com andares, portas, bens e valor; aviso dos bens sem porta', async () => {
    permissoes = new Set(['activos_edificios_view', 'activos_gerir']);
    const { default: Edificios } = await import('./Edificios');
    envolver(<Edificios />);
    await screen.findByText('Sede fictícia', undefined, { timeout: 60_000 });
    expect(screen.getByText(/4 bem\(ns\) ainda sem porta atribuída/)).toBeTruthy();
    expect(screen.getByRole('button', { name: /Novo Edifício \/ Local/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Planta/ })).toBeTruthy();
    expect(screen.getByRole('tab', { name: /Folhas de Inventário/ })).toBeTruthy();
  });

  it('planta: portas com bens, «Bens sem porta» e arrastar um bem para a porta', async () => {
    permissoes = new Set(['activos_edificios_view', 'activos_locais_gerir', 'activos_inventario_fisico']);
    const { default: Edificios } = await import('./Edificios');
    envolver(<Edificios />, '/planta/1');
    const porta = await screen.findByRole('button', { name: 'Porta A1' }, { timeout: 60_000 });
    expect(within(porta).getByText(/Secretária fictícia/)).toBeTruthy();
    expect(screen.getByText('Bens sem porta (1)')).toBeTruthy();
    for (const b of ['Andar', 'Portas', 'Inventário', 'Imprimir planta']) expect(screen.getByRole('button', { name: new RegExp(b) })).toBeTruthy();
    const dados = { getData: () => '6', setData: () => undefined };
    fireEvent.dragOver(porta, { dataTransfer: dados });
    fireEvent.drop(porta, { dataTransfer: dados });
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/ativos/bens/6/localizacao', { porta_id: 100 }));
  });

  it('só de leitura: sem botões de gestão na planta', async () => {
    permissoes = new Set(['activos_edificios_view']);
    const { default: Edificios } = await import('./Edificios');
    envolver(<Edificios />, '/planta/1');
    await screen.findByRole('button', { name: 'Porta A1' }, { timeout: 60_000 });
    expect(screen.queryByRole('button', { name: /^Andar$/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Inventário/ })).toBeNull();
  });
});

describe('Manutenção planeada (Fase F)', () => {
  it('abre nas Ordens e o separador Planos mostra a situação e o aviso dos vencidos', async () => {
    permissoes = new Set(['activos_manutencao_view', 'activos_manut_gerir']);
    const { default: Manutencao } = await import('../Manutencao');
    envolver(<Manutencao />);
    expect(await screen.findByText(/A ordem é o trabalho concreto num activo/, undefined, { timeout: 60_000 })).toBeTruthy();
    fireEvent.click(screen.getByRole('tab', { name: /Planos/ }));
    await screen.findByText('Revisão trimestral do gerador', undefined, { timeout: 60_000 });
    expect(screen.getByText('Em atraso 4 dia(s)')).toBeTruthy();
    expect(screen.getByText('Cada 3 meses')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Gerar ordens dos planos vencidos \(1\)/ })).toBeTruthy();
    expect(screen.getByText(/1 plano\(s\) vencido\(s\) ou a vencer/)).toBeTruthy();
  });
});
