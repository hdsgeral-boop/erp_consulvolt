import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { Unidade } from '../comum/arvore';
import type { TarefasDaUnidade } from './tipos';

vi.setConfig({ testTimeout: 180_000 });

// dados fictícios
const UNIDADE: Unidade = { id: 2, codigo: 'DFIN', nome: 'Finanças', tipo: 'DEPARTAMENTO', unidade_organica_pai_id: 1, colaborador_responsavel_id: 1, missao: null, atribuicoes: null, centro_custo_id: null, unidade_negocio_id: null, ordem: 1, ativo: true, apoio: false, cor: null, membros: 1, postos: [] };
const TAREFAS: TarefasDaUnidade = {
  tarefas: [{
    id: 5, unidade_organica_id: 2, codigo: 'FIN-01', nome: 'Fecho mensal', descricao: null, url_instrucoes: 'https://exemplo.invalid/fecho', periodicidade: 'MENSAL', colaborador_responsavel_id: null, posto_trabalho_id: null, ativo: true, ordem: 10,
    entregaveis: [{
      id: 10, tarefa_unidade_id: 5, nome: 'Relatório de fecho', descricao: null, metrica: 'QUANTIDADE', unidade: 'relatórios', meta: '1', item_produtividade_id: 3, colaborador_id: 2, origem_dados: 'MANUAL', crm_filtro: null,
      prazo_dia: 15, prazo_mes: 2, paga_fora_prazo: false, alerta: true, multa_valor: '50000', multa_descricao: 'Coima da AGT', avaliacao: true, peso_avaliacao: '1', ativo: true, ordem: 0, copiado_de_id: null,
      resumo: { periodo_de: '2026-01-01', periodo_ate: '2026-01-31', limite: '2026-02-15', realizado: '0', meta: '1', pct: 0, estado: 'EM_ATRASO', pendentes: 1, devolvidas: 0, recusadas: 0 },
      item: { id: 3, codigo: 'FECHO', descricao: 'Relatório de fecho', metrica: 'QUANTIDADE', unidade: 'relatórios' }, dono: { id: 2, nome: 'Rui Fictício' }, impedimento_aberto: null,
    }],
  }],
  atrasos: [{
    entregavel: { id: 10, nome: 'Relatório de fecho', unidade: 'relatórios', prazo_dia: 15, prazo_mes: 2 }, tarefa: { id: 5, nome: 'Fecho mensal', codigo: 'FIN-01' }, unidade: { id: 2, nome: 'Finanças' },
    periodo_de: '2026-01-01', periodo_ate: '2026-01-31', limite: '2026-02-15', dias_atraso: 12, realizado: '0', meta: '1', falta: '1', multa_valor: '50000', multa_descricao: 'Coima da AGT', impedimento: null, dono: { id: 2, nome: 'Rui Fictício' },
  }],
};
const EXECUCOES = [
  { id: 100, entregavel_id: 10, tarefa_unidade_id: 5, unidade_organica_id: 2, colaborador_id: 2, colaborador_nome: 'Rui Fictício', quantidade: '1', data: '2026-02-20', observacoes: 'Entregue tarde', url_evidencia: 'https://exemplo.invalid/r.pdf',
    registado_por: 'rui', origem: 'PORTAL', validacao: 'PENDENTE', validacao_motivo: null, validada_por: null, validada_em: null, validada_como: null, periodo_produtividade_id: null, pode_validar: true, pode_reenviar: false, convertida: false },
  { id: 101, entregavel_id: 10, tarefa_unidade_id: 5, unidade_organica_id: 2, colaborador_id: 2, colaborador_nome: 'Rui Fictício', quantidade: '1', data: '2026-01-10', observacoes: null, url_evidencia: null,
    registado_por: 'rui', origem: 'MANUAL', validacao: 'VALIDADA', validacao_motivo: null, validada_por: 'ana', validada_em: '2026-01-12', validada_como: 'RESPONSAVEL', periodo_produtividade_id: 7, pode_validar: false, pode_reenviar: false, convertida: true },
];

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a), obterPagina: () => Promise.resolve({ itens: [], paginacao: { ultima_pagina: 1 } }) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));
vi.mock('@/modulos/rh/comum/consultas', () => ({
  useColaboradores: () => ({ lista: [], mapa: new Map(), isLoading: false, nome: (id: number | null) => (id ? `#${id}` : '—') }),
  useCargos: () => ({ lista: [], isLoading: false, nome: () => '—' }),
  useInfotipos: () => ({ lista: [], isLoading: false, nome: () => '—' }),
}));
vi.mock('@/modulos/rh/comum/componentes', () => ({ SeletorColaborador: () => null }));

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
    if (url === '/rh/estrutura/unidades/2/tarefas') return Promise.resolve(TAREFAS);
    if (url === '/rh/estrutura/entregaveis/10/execucoes') return Promise.resolve(EXECUCOES);
    return Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: {}, mensagem: 'ok' });
});

describe('Estrutura › Tarefas e entregáveis da unidade (Fase J)', () => {
  it('lista a tarefa e o entregável com meta, prazo, realizado, estado, dono e item; faixa de atrasos com multa; botões com est_editar', async () => {
    permissoes = new Set(['est_estrutura_view', 'est_editar', 'rh_prod_config', 'rh_prod_registar']);
    const { TarefasUnidade } = await import('./TarefasUnidade');
    envolver(<TarefasUnidade unidade={UNIDADE} />);
    expect(await screen.findByText('Tarefas e entregáveis (1)', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(screen.getByText('FIN-01 · Fecho mensal')).toBeTruthy();
    expect(screen.getAllByText('Relatório de fecho').length).toBeGreaterThan(0);
    expect(screen.getByText('Mensal')).toBeTruthy();
    expect(screen.getAllByText(/Rui Fictício/).length).toBeGreaterThan(0);
    expect(screen.getByText(/remunerável · FECHO/)).toBeTruthy();
    expect(screen.getByText('avaliação')).toBeTruthy();
    expect(screen.getAllByText('em atraso').length).toBeGreaterThan(0);
    expect(screen.getByText(/1 por validar/)).toBeTruthy();
    // faixa de atrasos (portal_ui.js:1504-1528): exposição a multas
    expect(screen.getByText(/Entregáveis em atraso \(1\)/)).toBeTruthy();
    expect(screen.getAllByText(/50\s?000,00/).length).toBeGreaterThan(0);
    for (const b of ['Tarefa', 'Entregável', 'Editar tarefa Fecho mensal', 'Editar entregável Relatório de fecho', 'Copiar entregável Relatório de fecho', 'Eliminar entregável Relatório de fecho', 'Entregas de Relatório de fecho', 'Impedimentos de Relatório de fecho', 'Converter em produtividade']) {
      expect(screen.getAllByRole('button', { name: new RegExp(`^${b}$`) }).length).toBeGreaterThan(0);
    }
    // com item ligado não há «Criar item»
    expect(screen.queryByRole('button', { name: /Criar item de/ })).toBeNull();
  });

  it('a gaveta das entregas lista, mostra quem decide e valida pelo servidor; a convertida não tem acções', async () => {
    permissoes = new Set(['est_estrutura_view', 'est_editar']);
    const { TarefasUnidade } = await import('./TarefasUnidade');
    envolver(<TarefasUnidade unidade={UNIDADE} />);
    fireEvent.click(await screen.findByRole('button', { name: 'Entregas de Relatório de fecho' }, { timeout: 60_000 }));
    const gaveta = await screen.findByRole('dialog', {}, { timeout: 60_000 });
    await within(gaveta).findByRole('button', { name: 'Validar entrega 100' }, { timeout: 60_000 });
    expect(within(gaveta).getByText(/Só entregas validadas vão para a produtividade/)).toBeTruthy();
    expect(within(gaveta).getByText(/1 por validar/)).toBeTruthy();
    expect(within(gaveta).getByText('convertida')).toBeTruthy();
    expect(within(gaveta).getByText('Total validado')).toBeTruthy();
    expect(within(gaveta).queryByRole('button', { name: 'Validar entrega 101' })).toBeNull();
    expect(within(gaveta).getByRole('button', { name: 'Registar entrega' })).toBeTruthy();
    fireEvent.click(within(gaveta).getByRole('button', { name: 'Validar entrega 100' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/rh/estrutura/execucoes/100/validar', { decisao: 'VALIDADA', motivo: null }), { timeout: 30_000 });
    // devolver pede o motivo
    fireEvent.click(within(gaveta).getByRole('button', { name: 'Devolver entrega 100' }));
    expect(await screen.findByText('Devolver para correcção')).toBeTruthy();
  });

  it('sem est_editar: só consulta (entregas e impedimentos), sem botões de escrita', async () => {
    permissoes = new Set(['est_estrutura_view']);
    const { TarefasUnidade } = await import('./TarefasUnidade');
    envolver(<TarefasUnidade unidade={UNIDADE} />);
    await screen.findByText('Tarefas e entregáveis (1)', undefined, { timeout: 60_000 });
    expect(screen.queryByRole('button', { name: /^Tarefa$/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Editar entregável/ })).toBeNull();
    expect(screen.getByRole('button', { name: 'Entregas de Relatório de fecho' })).toBeTruthy();
  });
});
