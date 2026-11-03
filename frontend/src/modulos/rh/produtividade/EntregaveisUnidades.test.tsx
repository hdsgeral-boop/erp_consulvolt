import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { AnaliseEntregaveis, Elegibilidade } from '@/modulos/estrutura/tarefas/tipos';
import type { PeriodoProdutividade } from '../api';

vi.setConfig({ testTimeout: 180_000 });

// dados fictícios
const PERIODOS: PeriodoProdutividade[] = [
  { id: 3, mes: '2026-01', data_inicio: '2026-01-01', data_fim: '2026-01-31', observacoes: null, estado: 'ABERTO', fechado_em: null, fechado_por: null, total_fecho: null, registos_fecho: null, lancado_em: null, periodo_processamento_salarial_id: null, motivo_reabertura: null },
  { id: 2, mes: '2025-12', data_inicio: '2025-12-01', data_fim: '2025-12-31', observacoes: null, estado: 'FECHADO', fechado_em: null, fechado_por: null, total_fecho: null, registos_fecho: null, lancado_em: null, periodo_processamento_salarial_id: null, motivo_reabertura: null },
];
const ANALISE: AnaliseEntregaveis = {
  periodo: { id: 3, mes: '2026-01', data_inicio: '2026-01-01', data_fim: '2026-01-31', estado: 'ABERTO' },
  validas: [{ entregavel_id: 10, entregavel: 'Relatório de fecho', tarefa_unidade_id: 5, colaborador_id: 2, colaborador_nome: 'Rui Fictício', item_produtividade_id: 3, item_codigo: 'FECHO', quantidade: '2', registos: 2, preco: '30000',
    origem_chave: 'ORG|e10|c2|i3|p3', registo_existente_id: 9, quantidade_total: '3', valor: '60000.00', valor_total: '90000.00', ja_elegivel: false }],
  excluidas: [{ entregavel_id: 11, entregavel: 'Mapa de IVA', colaborador_id: 4, colaborador_nome: 'Zé Fictício', quantidade: '1', registos: 1, execucoes: [12], codigo: 'NAO_VALIDADA', motivo: 'À espera do visto do responsável' }],
  erros: [],
  por_elegibilizar: [{ colaborador_id: 2, colaborador_nome: 'Rui Fictício', item_produtividade_id: 3, item_codigo: 'FECHO', preco: '30000' }],
  total: '60000.00',
  convertidas: 0,
};
const ELEGIBILIDADE: Elegibilidade = {
  contrato: [{ colaborador_id: 2, colaborador_nome: 'Rui Fictício', item_produtividade_id: 3, item_codigo: 'FECHO', item_descricao: 'Relatório de fecho', origem: 'CONTRATO', ativo: true, contrato_id: 1, preco_aplicado: '30000' }],
  linhas: [{ id: 8, colaborador_id: 4, colaborador_nome: 'Zé Fictício', item_produtividade_id: 3, item_codigo: 'FECHO', item_descricao: 'Relatório de fecho', origem: 'ENTREGAVEL', ativo: true, preco_unitario: '25000', preco_aplicado: '25000', nota: 'Entrega validada' }],
};

const obterMock = vi.fn();
const enviarMock = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a), obterPagina: () => Promise.resolve({ itens: [], paginacao: { ultima_pagina: 1 } }) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, menu: [], empresa: null }) }));

const envolver = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

beforeEach(() => {
  obterMock.mockReset();
  enviarMock.mockReset();
  obterMock.mockImplementation((url: string) => {
    if (url === '/rh/produtividade/periodos/3/entregaveis/analise') return Promise.resolve(ANALISE);
    if (url === '/rh/produtividade/elegibilidade') return Promise.resolve(ELEGIBILIDADE);
    if (url === '/rh/estrutura') return Promise.resolve({ unidades: [], sem_unidade: 0 });
    return Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: { criados: 1, actualizados: 0, total: '60000', elegiveis: 1 }, mensagem: 'ok' });
});

describe('RH › Produtividade › Entregáveis das unidades (Fase J)', () => {
  it('só períodos abertos; analisa, mostra incluídos e excluídos com o motivo e converte', async () => {
    const { EntregaveisUnidades } = await import('./EntregaveisUnidades');
    envolver(<EntregaveisUnidades periodos={PERIODOS} periodoInicial={3} />);
    expect(await screen.findByText('Rui Fictício', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(screen.getByText('Grupos a converter').parentElement!.textContent).toContain('1');
    expect(screen.getByText(/já tinha 1/)).toBeTruthy();
    expect(screen.getByText(/passa a elegível/)).toBeTruthy();
    expect(screen.getByText(/Não entram \(1\)/)).toBeTruthy();
    expect(screen.getAllByText('NAO_VALIDADA').length).toBeGreaterThan(0);
    expect(screen.getByText(/À espera do visto do responsável/)).toBeTruthy();
    expect(screen.getAllByText(/90\s?000,00/).length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: /Converter em produtividade/ }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/rh/produtividade/periodos/3/entregaveis/converter', {}), { timeout: 30_000 });
    expect(screen.getByRole('button', { name: /Anular conversão/ })).toBeTruthy();
    expect(obterMock).not.toHaveBeenCalledWith('/rh/produtividade/periodos/2/entregaveis/analise', expect.anything());
  });
});

describe('RH › Produtividade › Elegibilidade (Fase J)', () => {
  it('contrato (só leitura) e entregável, com o preço próprio e as acções só nas linhas da tabela', async () => {
    const { Elegibilidade } = await import('./Elegibilidade');
    envolver(<Elegibilidade />);
    expect(await screen.findByText('Zé Fictício', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(screen.getByText('contrato')).toBeTruthy();
    expect(screen.getByText('entregável')).toBeTruthy();
    expect(screen.getByText('o do item')).toBeTruthy();
    expect(screen.getAllByText(/25\s?000,00/).length).toBeGreaterThan(0);
    expect(screen.getAllByRole('button', { name: 'Desactivar' })).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Retirar Rui Fictício' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Retirar Zé Fictício' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Desactivar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/rh/produtividade/elegibilidade', { colaborador_id: 4, item_produtividade_id: 3, ativo: false, preco_unitario: 25000, nota: 'Entrega validada' }), { timeout: 30_000 });
    expect(screen.getByRole('button', { name: /Adicionar/ })).toBeTruthy();
  });
});
