import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';

vi.setConfig({ testTimeout: 120_000 });

// dados fictícios
const CRITERIOS = [
  { id: 1, codigo: 'IGUAIS', nome: 'Partes iguais', criterio: 'PARTES_IGUAIS', indicador: null, periodo_de: null, periodo_ate: null, notas: null, ativo: true },
  { id: 2, codigo: 'AREA', nome: 'Área ocupada', criterio: 'AREA', indicador: null, periodo_de: null, periodo_ate: null, notas: null, ativo: true },
];
const UNIDADES = [{ id: 11, codigo: 'UN1', nome: 'Comercial', estado: 'ATIVO' }, { id: 12, codigo: 'UN2', nome: 'Industrial', estado: 'ATIVO' }];
const SIMULACAO = {
  alocacao: { criterio_id: 1, unidades: [{ unidade_negocio_id: 11, peso: null }, { unidade_negocio_id: 12, peso: null }] },
  linhas: [
    { unidade_negocio_id: 11, nome: 'UN1 — Comercial', indutor: '1.0000', percentagem: '50.0000', valor: '50.01' },
    { unidade_negocio_id: 12, nome: 'UN2 — Industrial', indutor: '1.0000', percentagem: '50.0000', valor: '50.00' },
  ],
  total: '100.01',
};

const obterMock = vi.fn();
const enviarMock = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obterMock(...a),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { total: 0, pagina_atual: 1, por_pagina: 50, ultima_pagina: 1 } }),
  enviar: (...a: unknown[]) => enviarMock(...a),
}));
let permissoes = new Set<string>();
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)) }) }));

const envolver = (ui: ReactNode) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{ui}</QueryClientProvider>);

beforeEach(() => {
  obterMock.mockReset();
  enviarMock.mockReset();
  obterMock.mockImplementation((url: string) => Promise.resolve(
    url === '/reparticao/criterios' ? CRITERIOS : url === '/sistema/unidades-negocio' ? UNIDADES : url === '/contabilidade/tabelas/centros-custo' ? [] : [],
  ));
  enviarMock.mockImplementation((_m: string, url: string) => Promise.resolve(url === '/reparticao/simular' ? { dados: SIMULACAO, mensagem: 'Repartição calculada.' } : { dados: null, mensagem: 'ok' }));
});

describe('AlocacaoUN', () => {
  it('sem critério: usa a unidade do campo normal', async () => {
    const { AlocacaoUN } = await import('./AlocacaoUN');
    envolver(<AlocacaoUN value={null} />);
    expect(await screen.findByText(/Sem repartição: o registo usa a unidade de negócio/)).toBeInTheDocument();
    expect(enviarMock).not.toHaveBeenCalled();
  });

  it('partes iguais: linhas «parte igual» e pré-visualização das parcelas calculadas no servidor', async () => {
    const { AlocacaoUN } = await import('./AlocacaoUN');
    envolver(<AlocacaoUN value={{ criterio_id: 1, unidades: [{ unidade_negocio_id: 11 }, { unidade_negocio_id: 12 }] }} valor={100.01} />);
    expect(await screen.findByText('UN1 — Comercial', undefined, { timeout: 60_000 })).toBeInTheDocument();
    expect(screen.getAllByText('parte igual')).toHaveLength(2);
    expect((await screen.findAllByText(/100,01/, undefined, { timeout: 60_000 })).length).toBeGreaterThan(0);   // total da repartição
    expect(screen.getAllByText(/50,01/).length).toBeGreaterThan(0);   // a primeira parcela, calculada no servidor
    expect(enviarMock).toHaveBeenCalledWith('post', '/reparticao/simular', { unidades_alocadas: SIMULACAO.alocacao, valor: 100.01 });
  });

  it('área sem pesos: aviso e nenhum pedido ao servidor', async () => {
    const { AlocacaoUN } = await import('./AlocacaoUN');
    envolver(<AlocacaoUN value={{ criterio_id: 2, unidades: [{ unidade_negocio_id: 11 }, { unidade_negocio_id: 12 }] }} />);
    expect(await screen.findByText('Indique área (m²) em pelo menos uma unidade.', undefined, { timeout: 60_000 })).toBeInTheDocument();
    expect(enviarMock).not.toHaveBeenCalled();
  });

  it('retirar uma unidade chama onChange com a alocação sem ela', async () => {
    const { AlocacaoUN } = await import('./AlocacaoUN');
    const mudar = vi.fn();
    envolver(<AlocacaoUN value={{ criterio_id: 1, unidades: [{ unidade_negocio_id: 11 }, { unidade_negocio_id: 12 }] }} onChange={mudar} />);
    fireEvent.click(await screen.findByRole('button', { name: 'Retirar a unidade 2' }));
    expect(mudar).toHaveBeenCalledWith({ criterio_id: 1, unidades: [{ unidade_negocio_id: 11 }] });
  });
});

describe('ModalRepartirLinha', () => {
  it('sem critérios: indica onde os criar e não deixa repartir', async () => {
    obterMock.mockImplementation((url: string) => Promise.resolve(url === '/reparticao/criterios' ? [] : UNIDADES));
    const { ModalRepartirLinha } = await import('./ModalRepartirLinha');
    envolver(<ModalRepartirLinha valor={100} aoFechar={vi.fn()} aoRepartir={vi.fn()} />);
    expect(await screen.findByText(/Não há critérios de repartição/, undefined, { timeout: 60_000 })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Repartir' })).toBeDisabled();
  });
});

describe('CriteriosReparticao', () => {
  it('lista as formas e só mostra «Novo critério» a quem gere as tabelas auxiliares', async () => {
    permissoes = new Set(['tabelas_aux_view']);
    const { CriteriosReparticao } = await import('./CriteriosReparticao');
    const { unmount } = envolver(<CriteriosReparticao />);
    expect(await screen.findByText('IGUAIS', undefined, { timeout: 60_000 })).toBeInTheDocument();
    expect(screen.getAllByText('Partes iguais').length).toBeGreaterThan(0);   // nome da forma
    expect(screen.getByText('Área ocupada (m²)')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Novo critério/ })).toBeNull();
    unmount();
    permissoes = new Set(['tabelas_aux_view', 'aux_gerir']);
    envolver(<CriteriosReparticao />);
    await waitFor(() => expect(screen.getByRole('button', { name: /Novo critério/ })).toBeInTheDocument(), { timeout: 60_000 });
  });

  it('corpo do critério: código em maiúsculas, indicador só no personalizado', async () => {
    const { corpoCriterio } = await import('./CriteriosReparticao');
    expect(corpoCriterio({ codigo: ' hm ', nome: ' Horas ', criterio: 'PERSONALIZADO', indicador: 'horas-máquina', ativo: true }))
      .toEqual({ codigo: 'HM', nome: 'Horas', criterio: 'PERSONALIZADO', ativo: true, notas: null, indicador: 'horas-máquina', periodo_de: null, periodo_ate: null });
    expect(corpoCriterio({ codigo: 'a', nome: 'A', criterio: 'AREA', indicador: 'ignorado', ativo: false }).indicador).toBeNull();
  });
});
