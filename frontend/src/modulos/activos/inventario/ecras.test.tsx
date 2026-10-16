import { fireEvent, render, screen, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';

vi.setConfig({ testTimeout: 180_000 });

// valores fictícios
const CATEGORIAS = [
  { id: 1, nome: 'Equipamento Informático', taxa_anual: '25.0000', vida_util_padrao: 48, conta_gasto: '7313', conta_amortizacao_acumulada: '1813', conta_venda: null, conta_perda: '7811', conta_ativo: '1153', ativos: 2 },
  { id: 2, nome: 'Mobiliário', taxa_anual: '12.5', vida_util_padrao: 96, conta_gasto: null, conta_amortizacao_acumulada: null, conta_venda: null, conta_perda: null, conta_ativo: null, ativos: 0 },
];
const MANUTENCOES = [
  { id: 1, ativo_imobilizado_id: 9, tipo: 'CORRECTIVA', data: '2026-08-21', descricao: 'Limpar', custo: '0.00', estado: 'CONCLUIDA', resolucao: 'Feito', data_execucao: '2026-08-21', ativo_imobilizado: { id: 9, codigo: 'AST-009', descricao: 'Portátil de teste' } },
  { id: 2, ativo_imobilizado_id: 9, tipo: 'PREVENTIVA', data: '2026-09-01', descricao: 'Revisão anual', custo: '1500.00', estado: 'PLANEADA', resolucao: null, data_execucao: null, ativo_imobilizado: { id: 9, codigo: 'AST-009', descricao: 'Portátil de teste' } },
];

const ACTIVOS = [
  { id: 5, codigo: 'AST-005', descricao: 'Viatura fictícia', categoria_ativo_id: 1, centro_custo_id: 3, unidade_negocio_id: 2, fornecedor_id: null, valor_aquisicao: '4800000.00', valor_residual: '0.00',
    vida_util: 48, vida_util_restante: 40, quota_fixa: '0.00', amortizacao_acumulada_inicial: '0.00', acumulado_fim_ano: null, data_aquisicao: '2026-01-15', estado: 'ACTIVO',
    lancamento_contabil_id: null, amortizacao_acumulada: '800000.00', valor_liquido: '4000000.00', taxa: '25.00', bloqueado: true,
    categoria_ativo: { id: 1, nome: 'Equipamento de transporte' }, centro_custo: { id: 3, codigo: 'CC01' }, unidade_negocio: { id: 2, codigo: 'UN01' } },
  { id: 6, codigo: 'AST-006', descricao: 'Secretária fictícia', categoria_ativo_id: 2, centro_custo_id: null, unidade_negocio_id: null, fornecedor_id: null, valor_aquisicao: '50000.00', valor_residual: '0.00',
    vida_util: 96, vida_util_restante: 96, quota_fixa: '0.00', amortizacao_acumulada_inicial: '0.00', acumulado_fim_ano: null, data_aquisicao: '2026-06-01', estado: 'ACTIVO',
    lancamento_contabil_id: null, amortizacao_acumulada: '0.00', valor_liquido: '50000.00', taxa: '12.50', bloqueado: false, categoria_ativo: { id: 2, nome: 'Mobiliário' }, centro_custo: null, unidade_negocio: null },
];
const meses = (v: string | null) => Object.fromEntries(Array.from({ length: 12 }, (_, i) => [String(i + 1), v && i < 2 ? { valor: v, contabilizado: true, amortizacao_id: i + 1 } : null]));
// O Mapa abre no ano corrente: a folha de teste acompanha-o (o teste não depende do ano em que corre).
const ANO_MAPA = new Date().getFullYear();
const MAPA = {
  ano: ANO_MAPA,
  linhas: [{ ativo_imobilizado_id: 5, codigo: 'AST-005', descricao: 'Viatura fictícia', estado: 'ACTIVO', categoria: 'Transporte', taxa: '25.00', anos_vida: '4.0', data_aquisicao: `${ANO_MAPA}-01-15`,
    aquisicao_anos_anteriores: '0.00', aquisicao_ano: '4800000.00', acumulado_anterior: '0.00', meses: meses('100000.00'), ano: '200000.00', acumulado: '200000.00', liquido: '4600000.00' }],
  totais: { aquisicao_anos_anteriores: '0.00', aquisicao_ano: '4800000.00', acumulado_anterior: '0.00', ano: '200000.00', acumulado: '200000.00', liquido: '4600000.00',
    meses: Object.fromEntries(Array.from({ length: 12 }, (_, i) => [String(i + 1), i < 2 ? '100000.00' : '0.00'])) },
};

const obterMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obterMock(...a),
  obterPagina: (url: string) => Promise.resolve(url === '/ativos/manutencoes'
    ? { itens: MANUTENCOES, paginacao: { total: 2, pagina: 1, por_pagina: 50, ultima_pagina: 1 } }
    : url === '/ativos/bens' ? { itens: ACTIVOS, paginacao: { total: 2, pagina: 1, por_pagina: 50, ultima_pagina: 1 } }
    : { itens: [], paginacao: { total: 0, pagina: 1, por_pagina: 50, ultima_pagina: 1 } }),
  enviar: () => Promise.resolve({ dados: null, mensagem: 'ok' }),
  descarregar: () => Promise.resolve(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

const envolver = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

const cabecalhos = () => screen.getAllByRole('columnheader').map((c) => (c.textContent ?? '').trim().toUpperCase()).filter(Boolean);

beforeEach(() => {
  obterMock.mockReset();
  obterMock.mockImplementation((url: string) => Promise.resolve(url === '/ativos/categorias' ? CATEGORIAS : url === '/ativos/mapas/amortizacoes' ? MAPA : url === '/ativos/aquisicoes-pendentes' ? { linhas: [], total_por_inventariar: '150000.00', ativos_sem_lancamento: [] } : []));
});

describe('Categorias e Taxas (13.2.1)', () => {
  it('rótulos curtos do antigo, «---» nas contas vazias e acções Editar/Excluir na linha', async () => {
    permissoes = new Set(['activos_categorias_view', 'activos_cat_gerir']);
    const { default: Categorias } = await import('../Categorias');
    envolver(<Categorias />);
    await screen.findByText('Equipamento Informático', undefined, { timeout: 60_000 });
    expect(screen.getByText('Categorias de Imobilizado')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Nova Categoria/ })).toBeTruthy();
    const h = cabecalhos();
    const ordem = ['NOME', 'TAXA (%)', 'AMORT. EXERC.', 'AMORT. ACUM.', 'C. VENDA', 'C. PERDA'].map((t) => h.findIndex((x) => x.startsWith(t)));
    expect(ordem.every((i) => i >= 0)).toBe(true);
    expect([...ordem].sort((a, b) => a - b)).toEqual(ordem);
    expect(h.some((x) => x.startsWith('VIDA ÚTIL'))).toBe(false); // extra do sistema novo escondido por omissão
    expect(screen.getByText('25,00%')).toBeTruthy();
    expect(screen.getByText('12,50%')).toBeTruthy();
    const linha = screen.getByText('Mobiliário').closest('tr')!;
    expect(within(linha).getByRole('button', { name: 'Editar' })).toBeTruthy();
    expect(within(linha).getByRole('button', { name: /Excluir/ })).toBeTruthy();
  });
});

describe('Manutenções (13.3.1)', () => {
  it('colunas do Histórico do legado (Data, Activo, Ordem, Tipo, Trabalho, Resolução, Custo); «Executar» só nas planeadas', async () => {
    permissoes = new Set(['activos_manutencao_view', 'activos_manut']);
    const { default: Manutencao } = await import('../Manutencao');
    envolver(<Manutencao />);
    // Versão Actual: a Manutenção abre nas Ordens; o registo simples passou para o separador «Histórico»
    fireEvent.click(await screen.findByRole('tab', { name: /Histórico/ }));
    await screen.findByText('Revisão anual', undefined, { timeout: 60_000 });
    expect(screen.getByText(/Histórico de manutenções/)).toBeTruthy();
    expect(screen.getByRole('button', { name: /Registar Manutenção/ })).toBeTruthy();
    const h = cabecalhos();
    const ordem = ['DATA', 'ACTIVO', 'ORDEM', 'TIPO', 'TRABALHO', 'RESOLUÇÃO', 'CUSTO', 'ESTADO', 'ACÇÕES'].map((t) => h.findIndex((x) => x.startsWith(t)));
    expect(ordem.every((i) => i >= 0)).toBe(true);
    expect([...ordem].sort((a, b) => a - b)).toEqual(ordem);
    expect(screen.getAllByText('AST-009 — Portátil de teste')).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: 'Ficha do activo' })).toHaveLength(2);
    expect(screen.getByText('Custo acumulado:')).toBeTruthy();
    const planeada = screen.getByText('Revisão anual').closest('tr')!;
    expect(within(planeada).getByRole('button', { name: /Executar/ })).toBeTruthy();
    const concluida = screen.getByText('Limpar').closest('tr')!;
    expect(within(concluida).queryByRole('button', { name: /Executar/ })).toBeNull();
    expect(within(concluida).getByText('Feito')).toBeTruthy();
    expect(within(concluida).getByText('CONCLUIDA')).toBeTruthy();
  });
});

describe('Inventário de Activos (13.1.1)', () => {
  it('botões do cabeçalho, colunas do antigo e acções por linha (cadeado quando processado)', async () => {
    permissoes = new Set(['activos_view', 'activos_gerir', 'activos_eliminar', 'activos_pendentes_view']);
    const { default: Activos } = await import('../Activos');
    envolver(<Activos />);
    await screen.findByText('Viatura fictícia', undefined, { timeout: 60_000 });
    expect(screen.getByText('Inventário de Imobilizados')).toBeTruthy();
    for (const b of ['Template Excel', 'Importar Excel', 'Eliminar em Massa', 'Edição em Massa', 'Novo Activo']) {
      expect(screen.getByRole('button', { name: new RegExp(b) })).toBeTruthy();
    }
    const h = cabecalhos();
    const ordem = ['Nº INVENTÁRIO', 'DESCRIÇÃO', 'CATEGORIA', 'DATA AQUISIÇÃO', 'U. NEGÓCIO', 'C. CUSTO', 'VIDA ÚTIL', 'TAXA %', 'AQUISIÇÃO', 'AMORT. ACUM.', 'V. C. LÍQUIDO', 'STATUS', 'AÇÕES']
      .map((t) => h.findIndex((x) => x.startsWith(t)));
    expect(ordem.every((i) => i >= 0)).toBe(true);
    expect([...ordem].sort((a, b) => a - b)).toEqual(ordem);
    const processado = screen.getByText('Viatura fictícia').closest('tr')!;
    expect(within(processado).getByRole('button', { name: 'Edição Bloqueada (Processado)' })).toBeTruthy();
    expect(within(processado).getByRole('button', { name: 'Transferir C.Custo' })).toBeTruthy();
    expect(within(processado).getByText('48 m')).toBeTruthy();
    expect(within(processado).getByText('25,00%')).toBeTruthy();
    expect(within(processado).getByText('UN01')).toBeTruthy();
    const livre = screen.getByText('Secretária fictícia').closest('tr')!;
    expect(within(livre).getByRole('button', { name: 'Editar' })).toBeTruthy();
    expect(await screen.findByText('Diferença de Reconciliação Detectada')).toBeTruthy();
  });
});

describe('Mapa de Amortizações (13.5.1)', () => {
  it('título do cartão com o ano, Extrair/Exportar e colunas Anos e «Meses do Ano»', async () => {
    permissoes = new Set(['activos_mapa_view']);
    const { default: Mapa } = await import('../Mapa');
    envolver(<Mapa />);
    await screen.findByText('Viatura fictícia', undefined, { timeout: 60_000 });
    const ano = ANO_MAPA;
    expect(screen.getByText(`Mapa de Amortizações - Ano ${ano}`)).toBeTruthy();
    expect(screen.getByRole('button', { name: /Extrair/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Exportar/ })).toBeTruthy();
    expect(screen.queryByRole('tab', { name: /fiscal/i })).toBeNull();
    const h = cabecalhos();
    const ordem = ['Nº INV.', 'DESCRIÇÃO', 'DATA AQ.', 'V. AQUISIÇÃO (ANTERIOR)', 'AQUISIÇÕES ANO', 'TAXA %', 'ANOS', 'AMORT. ACUM.', `MESES DO ANO ${ano}`, 'TOTAL ANO', 'ACUMULADO FINAL', 'V.C. LÍQUIDO', 'JAN', 'DEZ']
      .map((t) => h.findIndex((x) => x.startsWith(t)));
    expect(ordem.every((i) => i >= 0)).toBe(true);
    expect(screen.getByText('4,0')).toBeTruthy();
    expect(screen.getByText('TOTAIS (FILTRADOS):')).toBeTruthy();
  });
});