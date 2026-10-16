import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

vi.setConfig({ testTimeout: 180_000 });

// valores fictícios
const CATEGORIAS = [{ id: 1, nome: 'Equipamento Informático', taxa_anual: '25.0000', vida_util_padrao: 48, conta_gasto: '7313', conta_amortizacao_acumulada: '1813', conta_venda: null, conta_perda: null, conta_ativo: null, ativos: 0 }];
const linha = (id: number, data: string, conta: string, restante: string, doc: string) => ({
  id, numero_lan: null, numero_documento: doc, data_documento: data, codigo_conta: conta, descricao: `Compra ${doc}`, valor: restante, terceiro_id: null,
  unidade_negocio_id: null, centro_custo_id: null, diario: 'CP', terceiro: 'Fornecedor fictício', inventariado: '0.00', por_inventariar: restante, ativos: 0, estado: 'PENDENTE',
});
const PENDENTES = {
  linhas: [linha(7, '2026-01-10', '1141', '50000.00', 'FT A/1'), linha(4, '2026-02-05', '1142', '30000.00', 'FT B/2'), linha(9, '2026-03-01', '1141', '1000.00', 'FT C/3')],
  total_por_inventariar: '81000.00',
  ativos_sem_lancamento: [],
};

const obter = vi.fn();
const enviar = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { total: 0, pagina: 1, por_pagina: 30, ultima_pagina: 1 } }),
  descarregar: () => Promise.resolve(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: (...c: string[]) => c.some((x) => permissoes.has(x)), menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

import Pendentes from './Pendentes';
import { combinarPendentes } from './comum/regras';

const envolver = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

const BOTAO = /Inventariar Seleccionados \(Combinar\)/;
const caixaDaLinha = (doc: string) => within(screen.getByText(doc).closest('tr')!).getByRole('checkbox');

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  permissoes = new Set(['activos_view', 'activos_pendentes_view', 'activos_inventariar']);
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(
    url === '/ativos/aquisicoes-pendentes' ? PENDENTES : url === '/ativos/categorias' ? CATEGORIAS : [],
  ));
  enviar.mockReset().mockResolvedValue({ dados: { ativos: [], por_inventariar: '0.00' }, mensagem: 'ok' });
});

describe('combinarPendentes (inventorySelectedPendingMovements)', () => {
  it('ordena por data e id e soma o restante ao cêntimo', () => {
    const r = combinarPendentes([
      { id: 4, data_documento: '2026-02-05', por_inventariar: '30000.10' },
      { id: 9, data_documento: '2026-01-10T00:00:00Z', por_inventariar: '0.20' },
      { id: 7, data_documento: '2026-01-10', por_inventariar: '50000.00' },
    ]);
    expect(r.ids).toEqual([7, 9, 4]);
    expect(r.total).toBe('80000.30');
    expect(combinarPendentes([]).total).toBe('0.00');
  });
});

describe('Aquisições pendentes › Inventariar Seleccionados (Combinar)', () => {
  it('selecciona linhas, abre o modal com o valor somado e envia as linhas para /combinar', async () => {
    envolver(<Pendentes />);
    const botao = await screen.findByRole('button', { name: BOTAO });
    expect(botao).toBeDisabled();

    await screen.findByText('FT B/2');
    fireEvent.click(caixaDaLinha('FT B/2'));
    fireEvent.click(caixaDaLinha('FT A/1'));
    expect(botao).toBeEnabled();
    fireEvent.click(botao);

    const modal = await screen.findByRole('dialog');
    expect(within(modal).getByText(/Inventariar 2 linhas combinadas — múltiplas contas \(1141, 1142\)/)).toBeInTheDocument();
    expect(within(modal).getByText(/FT A\/1 \(1141.*FT B\/2 \(1142/)).toBeInTheDocument();
    // primeiro item com a descrição da primeira linha (data) e o valor somado
    expect(within(modal).getByDisplayValue('Compra FT A/1')).toBeInTheDocument();
    expect(within(modal).getByDisplayValue('80000.00')).toBeInTheDocument();

    // sem categoria o OK fica bloqueado; escolhe-se a categoria
    const ok = within(modal).getByRole('button', { name: /Inventariar 1 activo/ });
    expect(ok).toBeDisabled();
    fireEvent.mouseDown(within(modal).getAllByRole('combobox')[0]);
    fireEvent.click(await screen.findByTitle('Equipamento Informático'));
    await waitFor(() => expect(ok).toBeEnabled());
    fireEvent.click(ok);

    await waitFor(() => expect(enviar).toHaveBeenCalled());
    const [metodo, url, dados] = enviar.mock.calls[0];
    expect([metodo, url]).toEqual(['post', '/ativos/aquisicoes-pendentes/combinar']);
    expect(dados).toMatchObject({ linhas: [7, 4], itens: [{ descricao: 'Compra FT A/1', categoria_ativo_id: 1, valor_aquisicao: 80000, codigo: null }] });
  });

  it('uma só linha seleccionada segue o fluxo normal de inventariar', async () => {
    envolver(<Pendentes />);
    await screen.findByText('FT C/3');
    fireEvent.click(caixaDaLinha('FT C/3'));
    fireEvent.click(screen.getByRole('button', { name: BOTAO }));
    const modal = await screen.findByRole('dialog');
    expect(within(modal).getByText(/Inventariar FT C\/3 — conta 1141/)).toBeInTheDocument();
  });

  it('sem permissão de inventariar não há caixas nem botão', async () => {
    permissoes = new Set(['activos_view', 'activos_pendentes_view']);
    envolver(<Pendentes />);
    await screen.findByText('FT A/1');
    expect(screen.queryByRole('button', { name: BOTAO })).toBeNull();
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
  });
});
