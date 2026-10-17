import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import type { ReactNode } from 'react';
import Dashboard, { mostraComparacao, opcoesComparacao } from './Dashboard';
import { ResumoHolding, ROTA_COMPARACAO_GRUPO, ROTA_CONSOLIDACAO } from './comum/ResumoHolding';

/** Separador «Comparar empresas» (/m/geral/dashboard?separador=__comparacao) e Início da holding (agente r3_holding). */

const obterMock = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: vi.fn(), descarregar: vi.fn() }));
const sessao = {
  menu: [],
  empresas: [
    { id: 1, nome: 'Alfa, Lda' },
    { id: 2, nome: 'Beta, SA' },
    { id: 9, nome: 'Grupo AB', e_consolidacao: true },
  ],
  empresa: { id: 1, nome: 'Alfa, Lda' },
  pode: () => true,
  utilizador: { nome_utilizador: 'teste' },
};
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => sessao }));

// testes com Dashboard completo (antd + gráficos): mais tempo em máquinas carregadas
vi.setConfig({ testTimeout: 30_000 });

const envolver = (ui: ReactNode, rota = '/') =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[rota]}>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

const QUADRO = {
  id: 'quadro_comparativo',
  titulo: 'Quadro Comparativo das Empresas',
  colunas: [
    { id: 'nome', rotulo: 'Empresa', formato: 'texto' },
    { id: 'proveitos', rotulo: 'Proveitos', formato: 'kz' },
  ],
  linhas: [
    { chave: '1', nome: 'Alfa, Lda', proveitos: '800.00' },
    { chave: '2', nome: 'Beta, SA', proveitos: '300.00' },
    { chave: 'TOTAL', nome: 'Total', proveitos: '1100.00' },
  ],
};

/** Resposta da comparação como o servidor a devolvia antes da correcção: sem `modulo` (o separador rebentava). */
const COMPARACAO_SEM_MODULO = {
  periodo: { ano: 2026, mes: 10, meses: [] },
  aviso: null,
  empresas: [],
  kpis: [{ id: 'proveitos', rotulo: 'Proveitos', valor: '1100.00', formato: 'kz' }],
  graficos: [],
  tabelas: [QUADRO],
};

describe('Dashboard › Comparar empresas', () => {
  beforeEach(() => obterMock.mockReset());

  it('mostra o separador a quem tem várias empresas (holdings não contam) ou está numa holding', () => {
    const contab = [{ id: 'contabilidade' }];
    expect(mostraComparacao([{}, {}], contab, false)).toBe(true);
    expect(mostraComparacao([{}, { e_consolidacao: true }], contab, false)).toBe(false);
    expect(mostraComparacao([{ e_consolidacao: true }], contab, true)).toBe(true);
    expect(mostraComparacao([{}, {}], [{ id: 'rh' }], false)).toBe(false);
  });

  it('abre ?separador=__comparacao sem rebentar, mesmo com uma resposta sem `modulo`, e não oferece holdings na escolha', async () => {
    obterMock.mockImplementation((url: string) =>
      Promise.resolve(url === '/gestao/paineis' ? { holding: false, paineis: [{ id: 'contabilidade', nome: 'Contabilidade', tipo: 'PAINEL' }] } : COMPARACAO_SEM_MODULO),
    );
    envolver(<Dashboard />, '/m/geral/dashboard?separador=__comparacao');
    expect(await screen.findByText('Quadro Comparativo das Empresas', undefined, { timeout: 15_000 })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Comparar empresas/, pressed: true })).toBeTruthy();
    expect(screen.getAllByText('Proveitos').length).toBeGreaterThan(0);
    const pedido = obterMock.mock.calls.find((c) => c[0] === '/gestao/paineis/comparacao');
    expect(pedido?.[1]).toMatchObject({ ano: expect.any(Number), mes: expect.any(Number) });
    expect(pedido?.[1].empresas).toBeUndefined();

    expect(opcoesComparacao(sessao.empresas).map((o) => o.value)).toEqual([1, 2]);
  });

  it('na holding o separador existe e o texto indica as empresas do grupo', async () => {
    obterMock.mockImplementation((url: string) =>
      Promise.resolve(
        url === '/gestao/paineis'
          ? { holding: true, paineis: [{ id: 'grupo', nome: 'Comparação das Empresas', tipo: 'COMPARACAO' }, { id: 'contabilidade', nome: 'Contabilidade', tipo: 'PAINEL' }] }
          : { ...COMPARACAO_SEM_MODULO, modulo: { id: '__comparacao', nome: 'Comparar empresas' } },
      ),
    );
    envolver(<Dashboard />, '/m/geral/dashboard?separador=__comparacao');
    expect(await screen.findByText('Empresas do grupo de consolidação (a que tem acesso)', undefined, { timeout: 15_000 })).toBeTruthy();
    expect(screen.getByText('Consolidação')).toBeTruthy();
  });
});

describe('Início da holding', () => {
  it('mostra os indicadores consolidados, o quadro por empresa e os atalhos permitidos', () => {
    envolver(
      <ResumoHolding
        dados={{
          grupo: { id: 3, nome: 'Grupo AB' },
          ultima_consolidacao: { id: 7, data_fim: '2026-12-31', moeda: 'AOA' },
          empresas: 2,
          aviso: '2 empresas · dados da última consolidação.',
          kpis: [{ id: 'resultado', rotulo: 'Resultado Consolidado', valor: '1000.00', formato: 'kz' }],
          quadro: QUADRO,
        }}
        rotaPermitida={(r) => r === ROTA_COMPARACAO_GRUPO}
      />,
    );
    expect(screen.getByText('Grupo AB')).toBeTruthy();
    expect(screen.getByText('2 empresas')).toBeTruthy();
    expect(screen.getByText(/Consolidado até 31\/12\/2026 em AOA/)).toBeTruthy();
    expect(screen.getByText('Resultado Consolidado')).toBeTruthy();
    expect(screen.getByText('Quadro Comparativo das Empresas')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Comparar empresas/ })).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Consolidação$/ })).toBeNull();
    expect(ROTA_CONSOLIDACAO).toBe('/m/contab/consolidacao');
  });
});
