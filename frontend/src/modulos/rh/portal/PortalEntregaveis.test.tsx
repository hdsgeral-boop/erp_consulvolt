import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { MeusEntregaveis } from '@/modulos/estrutura/tarefas/tipos';

vi.setConfig({ testTimeout: 180_000 });

// dados fictícios
const MEUS: MeusEntregaveis = {
  contadores: { recusadas: 0, devolvidas: 1 },
  entregaveis: [{
    id: 10, tarefa_unidade_id: 5, nome: 'Relatório de fecho', descricao: null, metrica: 'QUANTIDADE', unidade: 'relatórios', meta: '1', item_produtividade_id: 3, colaborador_id: 2, origem_dados: 'MANUAL', crm_filtro: null,
    prazo_dia: 15, prazo_mes: 2, paga_fora_prazo: false, alerta: true, multa_valor: null, multa_descricao: null, avaliacao: false, peso_avaliacao: '1', ativo: true, ordem: 0, copiado_de_id: null,
    papel: 'DONO', tarefa: { id: 5, codigo: 'FIN-01', nome: 'Fecho mensal', periodicidade: 'MENSAL', url_instrucoes: 'https://exemplo.invalid/fecho' }, unidade_nome: 'Finanças',
    resumo: { periodo_de: '2026-01-01', periodo_ate: '2026-01-31', limite: '2026-02-15', realizado: '0', meta: '1', pct: 0, estado: 'EM_ATRASO', pendentes: 0, devolvidas: 1, recusadas: 0 },
    execucoes: [{ id: 7, entregavel_id: 10, tarefa_unidade_id: 5, unidade_organica_id: 2, colaborador_id: 2, quantidade: '1', data: '2026-02-20', observacoes: null, url_evidencia: null, registado_por: 'rui', origem: 'PORTAL',
      validacao: 'DEVOLVIDA', validacao_motivo: 'Falta o anexo', validada_por: 'ana', validada_em: '2026-02-21', validada_como: 'RESPONSAVEL', periodo_produtividade_id: null, pode_reenviar: true }],
    impedimentos: [],
  }],
};
const PEDIDO = { id: 5, colaborador_id: 2, tipo: 'FERIAS', dados: { data_inicio: '2026-03-02', data_fim: '2026-03-06', dias: 5 }, etapas: [{ nivel: 'CHEFIA', estado: 'PENDENTE' }], estado: 'PENDENTE_CHEFIA', criado_por: 'Rui Fictício', decidido_em: null, documento: null, criado_em: '2026-02-01' };
const ANALISE = {
  pedido: { id: 5, tipo: 'FERIAS', estado: 'PENDENTE_CHEFIA', criado_em: '2026-02-01', dados: { data_inicio: '2026-03-02', data_fim: '2026-03-06', dias: 5 }, etapas: [] },
  colaborador: { id: 2, nome_completo: 'Rui Fictício', data_admissao: '2020-01-15', unidade: 'Finanças', chefia_colaborador_id: 1 },
  saldo_ferias: { ano: 2026, direito: 22, marcados: 9, gozados: 0, saldo: 13, dias_pedidos: 5 },
  equipa_ausente: [{ colaborador_id: 3, nome: 'Zé Fictício', tipo: 'FERIAS', estado: 'APROVADO', de: '2026-03-04', ate: '2026-03-10' }],
  ultimos_pedidos: [{ id: 2, estado: 'APROVADO', criado_em: '2025-07-01', dados: { data_inicio: '2025-08-04', data_fim: '2025-08-08', dias: 5 }, decidido_em: '2025-07-02', decisao: ['CHEFIA: APROVADO'] }],
  avisos: ['Aprovar deixa a equipa com 2 pessoa(s) ausente(s) no mesmo período.'], anexos: [],
};
const ENTREGA = { id: 100, entregavel_id: 10, tarefa_unidade_id: 5, unidade_organica_id: 2, colaborador_id: 2, colaborador_nome: 'Rui Fictício', quantidade: '2', data: '2026-02-20', observacoes: null, url_evidencia: 'https://exemplo.invalid/r.pdf',
  registado_por: 'rui', origem: 'PORTAL', validacao: 'PENDENTE', validacao_motivo: null, validada_por: null, validada_em: null, validada_como: null, periodo_produtividade_id: null, pode_validar: true,
  entregavel: 'Relatório de fecho', unidade_medida: 'relatórios', tarefa: 'Fecho mensal', unidade_nome: 'Finanças' };
const ATRASO = { entregavel_id: 11, entregavel: { id: 11, nome: 'Mapa de IVA', unidade: 'un', metrica: 'QUANTIDADE', alerta: true, paga_fora_prazo: true, prazo_texto: 'dia 10 do mês seguinte' }, tarefa: { id: 5, nome: 'Fecho mensal', codigo: 'FIN-01', periodicidade: 'MENSAL' }, unidade: { id: 2, nome: 'Finanças' },
  periodo_de: '2026-01-01', periodo_ate: '2026-01-31', limite: '2026-02-10', dias_atraso: 9, realizado: '0', meta: null, falta: null, multa_valor: '25000', multa_descricao: 'Coima', impedimento: null, dono: null };
const MEMBRO = { id: 2, nome_completo: 'Rui Fictício', data_admissao: '2020-01-15', unidade_organica_id: 2, unidade: 'Finanças', cargo: 'Contabilista', ferias: { ano: 2026, direito: 22, marcados: 4, saldo: 18, periodos: [] }, ultimo_pedido: null };
const COMPRA = { id: 40, numero_pedido: 'PC 2026/004', nome_requerente: 'Zé Fictício', data: '2026-02-01', valor_estimado: '120000', nivel_atual: 'Responsável da unidade', niveis_aprovados: 0, niveis_total: 2 };

const obterMock = vi.fn();
const enviarMock = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a), obterPagina: () => Promise.resolve({ itens: [], paginacao: { ultima_pagina: 1 } }) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' } }) }));

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
    const r: Record<string, unknown> = {
      '/rh/portal/entregaveis': MEUS, '/rh/portal/aprovacoes': [PEDIDO], '/rh/portal/pedidos/5/analise': ANALISE, '/rh/portal/aprovacoes/entregas': [ENTREGA],
      '/rh/portal/aprovacoes/impedimentos': [], '/rh/portal/aprovacoes/atrasos': [ATRASO], '/rh/portal/compras/aprovacoes': [COMPRA], '/rh/portal/equipa': [MEMBRO],
    };
    return url in r ? Promise.resolve(r[url]) : Promise.reject(new Error('404'));
  });
  enviarMock.mockResolvedValue({ dados: {}, mensagem: 'ok' });
});

describe('Portal › Entregáveis (Fase J)', () => {
  it('mostra o entregável, o prazo ultrapassado, a devolução com o motivo e reenvia depois de corrigir', async () => {
    const { PortalEntregaveis } = await import('./PortalEntregaveis');
    envolver(<PortalEntregaveis />);
    expect(await screen.findByText('Relatório de fecho', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(screen.getByText(/Finanças › FIN-01 · Fecho mensal/)).toBeTruthy();
    expect(screen.getByText(/1 entrega\(s\) devolvida\(s\) para corrigir/)).toBeTruthy();
    expect(screen.getByText(/ultrapassado/)).toBeTruthy();
    expect(screen.getByText(/fora do prazo não é pago/)).toBeTruthy();
    expect(screen.getByText('Falta o anexo')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Não há condições de executar/ })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /Reenviar/ }));
    await waitFor(() => expect(enviarMock.mock.calls.some((c) => c[0] === 'post' && c[1] === '/rh/portal/execucoes/7/reenviar')).toBe(true), { timeout: 30_000 });
    // registar uma entrega
    fireEvent.click(screen.getByRole('button', { name: /^Entregar$/ }));
    expect(await screen.findByRole('dialog')).toBeTruthy();
  });
});

describe('Portal › Aprovações por secções (Fase J)', () => {
  it('pedidos com «Analisar», compras, entregas a validar, impedimentos, atrasos com multa e equipa directa', async () => {
    const { Aprovacoes } = await import('./Aprovacoes');
    envolver(<Aprovacoes />);
    expect(await screen.findByText('Pedidos à sua espera (1)', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(await screen.findByText('Pedidos de compra à sua espera (1)', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(await screen.findByText('Entregas à sua espera (1)', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(await screen.findByText('Impedimentos à sua espera (0)', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(await screen.findByText('Entregáveis em atraso nas suas unidades (1)', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(await screen.findByText('A minha equipa directa (1)', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(screen.getByText('PC 2026/004')).toBeTruthy();
    expect(screen.getByText('Mapa de IVA')).toBeTruthy();
    expect(screen.getAllByText(/25\s?000,00/).length).toBeGreaterThan(0);
    // análise do pedido antes de decidir (portal_ui.js:1303-1387)
    fireEvent.click(screen.getByRole('button', { name: /Analisar/ }));
    const dialogo = await screen.findByRole('dialog', {}, { timeout: 60_000 });
    expect(await within(dialogo).findByText(/Aprovar deixa a equipa com 2 pessoa\(s\) ausente\(s\) no mesmo período/, undefined, { timeout: 60_000 })).toBeTruthy();
    expect(within(dialogo).getByText('Zé Fictício — 04/03/2026 a 10/03/2026 (férias, aprovado)')).toBeTruthy();
    expect(within(dialogo).getByText('CHEFIA: APROVADO')).toBeTruthy();
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Fechar' }));
    // validar uma entrega pelo portal
    fireEvent.click(screen.getByRole('button', { name: /^Validar$/ }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/rh/portal/execucoes/100/validar', { decisao: 'VALIDADA', motivo: null }), { timeout: 30_000 });
    // aprovar um pedido de compra pelo portal
    fireEvent.click(screen.getByRole('button', { name: /^Aprovar$/ }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/rh/portal/compras/40/decidir', { decisao: 'APROVAR', nota: undefined }), { timeout: 30_000 });
  });
});
