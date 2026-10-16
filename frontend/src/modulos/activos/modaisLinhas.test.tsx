import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

vi.setConfig({ testTimeout: 180_000 });

// valores fictícios
const CATEGORIAS = [
  { id: 1, nome: 'Equipamento Informático', taxa_anual: '25.0000', vida_util_padrao: 48, conta_gasto: '7313', conta_amortizacao_acumulada: '1813', conta_venda: null, conta_perda: null, conta_ativo: null, ativos: 2 },
  { id: 2, nome: 'Mobiliário', taxa_anual: '12.5', vida_util_padrao: 96, conta_gasto: null, conta_amortizacao_acumulada: null, conta_venda: null, conta_perda: null, conta_ativo: null, ativos: 0 },
];

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

import { comVidaDaCategoria, dadosActivo, ModalActivo } from './ModalActivo';
import { ModalCategoria } from './Categorias';
import { dadosManutencao, ModalManutencao } from './Manutencao';
import { dadosPlano } from './manutencao/Planos';
import { dadosAfectacao } from './ModaisActivos';
import type { Activo } from './comum/tipos';

const envolver = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

const posts = (url: string) => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === url);
// os Select do AntD repetem o rótulo (caixa e campo): o primeiro chega para colar/escrever
const celula = (rotulo: string, n: number) => screen.getAllByLabelText(`${rotulo}, linha ${n}`)[0];

/** Abre a vista Linhas, cola o bloco a partir da célula dada, regista e confirma. */
async function colarERegistar(rotuloInicio: string, tsv: string) {
  fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
  await screen.findByTestId('painel-linhas');
  fireEvent.paste(celula(rotuloInicio, 1), { clipboardData: { getData: () => tsv } });
  fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
  fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(
    url.startsWith('/sistema/preferencias') ? [...prefs]
      : url === '/ativos/categorias' ? CATEGORIAS
      : url === '/sistema/unidades-negocio' ? [{ id: 2, codigo: 'UN01', nome: 'Sede' }]
      : url === '/contabilidade/tabelas/centros-custo' ? [{ id: 3, codigo: 'CC01', descricao: 'Administração' }]
      : [],
  ));
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: { id: 99 }, mensagem: 'ok' });
  });
});

describe('Regras partilhadas entre Grade e Linhas', () => {
  it('activo: vazios a nulo, sem estado na criação, vida útil da categoria quando falta', () => {
    expect(dadosActivo({ descricao: 'X', codigo: '', data_aquisicao: '2026-03-15', estado: 'ACTIVO' })).toEqual({ descricao: 'X', codigo: null, data_aquisicao: '2026-03-15' });
    expect(dadosActivo({ descricao: 'X', tipo_ativo: 'BEM', estado: 'INACTIVO' }, { tipo_ativo: 'BEM' } as Pick<Activo, 'tipo_ativo'>)).toEqual({ descricao: 'X', data_aquisicao: null, estado: 'INACTIVO' });
    expect(dadosActivo({ descricao: 'E', tipo_ativo: 'EDIFICIO', edificio_id: 4 })).not.toHaveProperty('edificio_id');
    expect(comVidaDaCategoria({ categoria_ativo_id: 2, vida_util: null }, CATEGORIAS as never)).toMatchObject({ vida_util: 96 });
    expect(comVidaDaCategoria({ categoria_ativo_id: 2, vida_util: 12 }, CATEGORIAS as never)).toMatchObject({ vida_util: 12 });
  });

  it('manutenção, afectação e plano: datas da grade (dayjs) e das linhas (texto) dão o mesmo corpo', async () => {
    const dayjs = (await import('dayjs')).default;
    expect(dadosManutencao({ ativo_imobilizado_id: 9, tipo: 'PREVENTIVA', data: dayjs('2026-10-01') })).toEqual(dadosManutencao({ ativo_imobilizado_id: 9, tipo: 'PREVENTIVA', data: '2026-10-01' }));
    expect(dadosManutencao({ ativo_imobilizado_id: 9, tipo: 'PREVENTIVA', data: '2026-10-01' })).toEqual({ ativo_imobilizado_id: 9, tipo: 'PREVENTIVA', data: '2026-10-01', descricao: null, custo: 0 });
    expect(dadosAfectacao({ ativo_imobilizado_id: 1, projeto_id: 2, data_inicio: '2026-01-01', data_fim: null })).toEqual({ ativo_imobilizado_id: 1, projeto_id: 2, data_inicio: '2026-01-01', data_fim: null });
    // linha sem «Aplica-se a»: com categoria aplica-se à categoria; por contador não leva a próxima data
    const p = dadosPlano({ nome: 'Revisão', tipo: 'PREVENTIVA', categoria_ativo_id: 2, ativo_imobilizado_id: null, modo: 'CONTADOR', intervalo: 250, unidade: 'HORAS', proxima_data: '2026-11-01', proximo_contador: 500 });
    expect(p).toMatchObject({ categoria_ativo_id: 2, ativo_imobilizado_id: null, proxima_data: null, proximo_contador: 500, periodicidade: { modo: 'CONTADOR', intervalo: 250, unidade: 'HORAS' }, tarefas: [], itens: [] });
  });
});

describe('Novo activo — Grade | Linhas', () => {
  it('em Linhas grava um activo por linha pelo mesmo POST (vida útil da categoria)', async () => {
    const { unmount } = envolver(<ModalActivo aberto aoFechar={() => undefined} />);
    expect(await screen.findByText('Novo activo')).toBeTruthy();
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/ativos/categorias'));
    await colarERegistar('Descrição do bem', 'Portátil HP\tEquipamento Informático\tUN01\tCC01\t15/03/2026\t250000\n');
    await waitFor(() => expect(posts('/ativos/bens')).toHaveLength(1), { timeout: 20_000 });
    expect(posts('/ativos/bens')[0][2]).toMatchObject({
      descricao: 'Portátil HP', categoria_ativo_id: 1, unidade_negocio_id: 2, centro_custo_id: 3, data_aquisicao: '2026-03-15', valor_aquisicao: 250000, vida_util: 48, tipo_ativo: 'BEM', codigo: null,
    });
    expect(posts('/ativos/bens')[0][2]).not.toHaveProperty('estado');
    unmount();
  }, 420_000); // 13 colunas com listas: lento no jsdom

  it('editar activo: só a grade, com secções recolhíveis', async () => {

    const activo = { id: 5, codigo: 'AST-005', descricao: 'Viatura', categoria_ativo_id: 1, estado: 'ACTIVO', tipo_ativo: 'BEM', amortizacao_acumulada_inicial: '0.00', edificio_id: null } as unknown as Activo;
    envolver(<ModalActivo aberto activo={activo} aoFechar={() => undefined} />);
    expect(await screen.findByText('Editar activo AST-005')).toBeTruthy();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
    expect(screen.getByRole('button', { name: /Imputação e origem/ })).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('button', { name: /Saldo inicial/ })).toHaveAttribute('aria-expanded', 'false');
    expect(screen.getByRole('button', { name: /Localização física/ })).toHaveAttribute('aria-expanded', 'false');
  });
});

describe('Nova categoria — Grade | Linhas', () => {
  it('em Linhas grava uma categoria por linha pelo mesmo POST (vazios a nulo)', async () => {
    envolver(<ModalCategoria categoria={null} aoFechar={() => undefined} />);
    await colarERegistar('Nome', 'Ferramentas\t10\t60\t7314\t1814\nOutra\t5\t\t7315\t\n');
    await waitFor(() => expect(posts('/ativos/categorias')).toHaveLength(2), { timeout: 20_000 });
    expect(posts('/ativos/categorias')[0][2]).toMatchObject({ nome: 'Ferramentas', taxa_anual: 10, vida_util_padrao: 60, conta_gasto: '7314', conta_amortizacao_acumulada: '1814', conta_venda: null });
    expect(posts('/ativos/categorias')[1][2]).toMatchObject({ nome: 'Outra', vida_util_padrao: null, conta_amortizacao_acumulada: null });
  });

  it('editar: só a grade, com as contas de venda/perda recolhidas quando vazias', async () => {
    envolver(<ModalCategoria categoria={CATEGORIAS[1] as never} aoFechar={() => undefined} />);
    expect(await screen.findByText('Editar categoria «Mobiliário»')).toBeTruthy();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
    expect(screen.getByRole('button', { name: /Contas de venda, perda e do activo/ })).toHaveAttribute('aria-expanded', 'false');
  });
});

describe('Registar manutenção — Grade | Linhas', () => {
  it('na ficha do activo, cada linha é uma manutenção desse activo', async () => {
    envolver(<ModalManutencao aberto activoId={9} aoFechar={() => undefined} />);
    await colarERegistar('Tipo', 'Correctiva\t01/10/2026\t1.500\tTroca de pneus\nPreventiva\t02/10/2026\t\tRevisão\n');
    await waitFor(() => expect(posts('/ativos/manutencoes')).toHaveLength(2), { timeout: 20_000 });
    expect(posts('/ativos/manutencoes')[0][2]).toEqual({ ativo_imobilizado_id: 9, tipo: 'CORRECTIVA', data: '2026-10-01', custo: 1500, descricao: 'Troca de pneus' });
    expect(posts('/ativos/manutencoes')[1][2]).toMatchObject({ tipo: 'PREVENTIVA', custo: 0 });
  });
});
