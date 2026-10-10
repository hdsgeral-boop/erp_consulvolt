import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import type { ReactNode } from 'react';
import { estiloKpi, estiloPainel } from './comum/estiloPainel';
import { GraficoPainel, inclinarRotulos, marcasPainel } from './comum/GraficoPainel';
import RelatoriosGestao from './RelatoriosGestao';
import { formatarRg, formatarVariacao, iconeModulo, legendaPeriodos, rotuloPeriodo, setaVariacao } from './relatorios/regras';

/** Paridade visual do Dashboard Global e dos Relatórios de Gestão (verificação de 2026-10-08, linhas 1.1.x e 1.2.x). */

const obterMock = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: vi.fn(), descarregar: vi.fn() }));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ menu: [], empresas: [{ id: 18, nome: 'Empresa Teste' }], empresa: { id: 18, nome: 'Empresa Teste' }, pode: () => true, utilizador: { nome: 'Teste' } }),
}));

const envolver = (ui: ReactNode, rota = '/') =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[rota]}>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

const MESES = ['Jan/26', 'Fev/26', 'Mar/26', 'Abr/26', 'Mai/26', 'Jun/26', 'Jul/26', 'Ago/26', 'Set/26', 'Out/26', 'Nov/26', 'Dez/26'];

describe('Dashboard Global — estilos do legado', () => {
  it('cada painel tem o ícone e a cor do antigo (Visão Geral escura)', () => {
    expect(estiloPainel('geral')).toEqual({ icone: 'th-large', cor: '#0f172a' });
    expect(estiloPainel('rh').icone).toBe('users');
    expect(estiloPainel('acrescimos')).toEqual({ icone: 'exchange-alt', cor: '#0d9488' });
    expect(estiloPainel('desconhecido').icone).toBe('chart-line');
  });

  it('cartões: ícone/cor por indicador; resultado verde ou vermelho pelo sinal; alertas só coloridos quando > 0', () => {
    expect(estiloKpi('geral', 'vendas_mes', '10')).toEqual({ icone: 'shopping-cart', cor: '#2563eb' });
    expect(estiloKpi('geral', 'resultado_ano', '-5.00').cor).toBe('#ef4444');
    expect(estiloKpi('geral', 'resultado_ano', '5.00').cor).toBe('#10b981');
    expect(estiloKpi('crm', 'atividades_atraso', 0).cor).toBe('#64748b');
    expect(estiloKpi('crm', 'atividades_atraso', 3).cor).toBe('#dc2626');
    // mesmo id, cor do painel (Clientes a Receber: índigo na Visão Geral, verde em Vendas)
    expect(estiloKpi('vendas', 'clientes_receber', 1).cor).toBe('#10b981');
    // comparação de empresas usa os comuns; indicador novo usa o ícone do painel
    expect(estiloKpi('comparacao', 'eliminacoes', 1).icone).toBe('exchange-alt');
    expect(estiloKpi('rh', 'novo_indicador', 1)).toEqual(estiloPainel('rh'));
  });

  it('eixo dos valores mais fino (≈ 34 px por marca) e rótulos inclinados só quando não cabem', () => {
    expect(marcasPainel(210)).toBe(6);
    expect(marcasPainel(60)).toBe(4);
    expect(marcasPainel(1000)).toBe(10);
    expect(inclinarRotulos(MESES, 60)).toBe(false);
    expect(inclinarRotulos(MESES, 30)).toBe(true);
  });

  it('linhas: legenda centrada com círculos vazios, todos os meses no eixo e um marcador por ponto', () => {
    const { container } = envolver(
      <GraficoPainel
        grafico={{
          id: 'proveitos_custos',
          titulo: 'Proveitos vs Custos (12 meses)',
          tipo: 'linhas',
          rotulos: MESES,
          series: [
            { id: 'proveitos', rotulo: 'Proveitos (classe 6)', valores: MESES.map((_, i) => i * 1000), cor: '#10b981' },
            { id: 'custos', rotulo: 'Custos (classe 7)', valores: MESES.map((_, i) => i * 500), cor: '#ef4444' },
          ],
        }}
      />,
    );
    const legenda = screen.getByRole('list', { name: 'Legenda' });
    expect(legenda.className).toBe('painel-legenda');
    expect(legenda.querySelectorAll('i.vazio')).toHaveLength(2);
    expect(container.querySelectorAll('text.painel-rotulo-x')).toHaveLength(12);
    expect(container.querySelectorAll('circle.painel-marcador')).toHaveLength(24);
  });

  it('barras: legenda com círculos cheios e todos os meses', () => {
    const { container } = envolver(
      <GraficoPainel
        grafico={{
          id: 'vendas_compras',
          titulo: 'Vendas vs Compras (12 meses)',
          tipo: 'barras',
          rotulos: MESES,
          series: [
            { id: 'vendas', rotulo: 'Vendas', valores: MESES.map(() => 30_000_000), cor: '#2563eb' },
            { id: 'compras', rotulo: 'Compras', valores: MESES.map(() => 5_000_000), cor: '#ea580c' },
          ],
        }}
      />,
    );
    expect(screen.getByRole('list', { name: 'Legenda' }).querySelectorAll('i.vazio')).toHaveLength(0);
    expect(container.querySelectorAll('text.painel-rotulo-x')).toHaveLength(12);
    expect(container.querySelectorAll('text.painel-marca-y').length).toBeGreaterThanOrEqual(5);
  });
});

describe('Relatórios de Gestão — regras do legado', () => {
  it('períodos «dd/mm/aaaa a dd/mm/aaaa (N dias)»', () => {
    const a = { inicio: '2026-10-01', fim: '2026-10-31', dias: 31 };
    expect(rotuloPeriodo(a)).toBe('01/10/2026 a 31/10/2026');
    expect(legendaPeriodos(a, { inicio: '2025-10-01', fim: '2025-10-31', dias: 31 })).toEqual({ a: '01/10/2026 a 31/10/2026 (31 dias)', b: '01/10/2025 a 31/10/2025 (31 dias)' });
    expect(legendaPeriodos(a, null).b).toBeNull();
  });

  it('variação como formatarVar: p.p. nas percentagens, % entre parênteses nos restantes; setas ▲/▼', () => {
    expect(formatarVariacao(2.5, 10, 'pct')).toBe('+2,5 p.p.');
    expect(formatarVariacao(-3, -50, 'num')).toBe('-3 (-50,0 %)');
    expect(formatarVariacao(null, null, 'kz')).toBe('—');
    expect(formatarRg(12.345, 'pct', null)).toBe('12,3 %');
    expect(formatarRg(null, 'kz', null)).toBe('—');
    expect(setaVariacao(5)).toBe('▲');
    expect(setaVariacao('-1.00')).toBe('▼');
    expect(setaVariacao(0)).toBe('•');
    expect(iconeModulo('tesouraria')).toBe('vault');
    expect(iconeModulo('resumo')).toBe('star');
  });
});

const kpi = (chave: string, a: unknown, b: unknown, pct: number | null, leitura: string) => ({
  chave,
  rotulo: chave.toUpperCase(),
  formato: 'num',
  sentido: 'sobe',
  ajuda: 'Ajuda',
  actual: false,
  casas: null,
  a,
  b,
  variacao: { abs: Number(a) - Number(b), pct },
  leitura,
});

describe('Relatórios de Gestão — ecrã', () => {
  beforeEach(() => {
    obterMock.mockReset();
    obterMock.mockImplementation((url: string) => {
      if (url === '/gestao/relatorios')
        return Promise.resolve({
          modulos: [
            { id: 'financas', nome: 'Finanças', descricao: 'x' },
            { id: 'vendas', nome: 'Vendas', descricao: 'y' },
          ],
          presets_a: [
            { id: 'mes', nome: 'Mês actual' },
            { id: 'livre', nome: 'Datas à escolha' },
          ],
          comparacoes: [
            { id: 'homologo', nome: 'Homólogo (ano anterior)' },
            { id: 'livre', nome: 'Datas à escolha' },
            { id: 'nenhum', nome: 'Sem comparação' },
          ],
        });
      const periodos = {
        a: { inicio: '2026-10-01', fim: '2026-10-31', dias: 31, nome: 'Out 2026', rotulo: '' },
        b: { inicio: '2025-10-01', fim: '2025-10-31', dias: 31, nome: 'Homólogo', rotulo: '' },
        duracao_diferente: false,
      };
      if (url === '/gestao/relatorios/periodos') return Promise.resolve(periodos);
      if (url === '/gestao/relatorios/resumo')
        return Promise.resolve({
          periodos,
          blocos: [
            { modulo: { id: 'financas', nome: 'Finanças' }, kpis: [kpi('vn', 120, 100, 20, 'boa')] },
            { modulo: { id: 'vendas', nome: 'Vendas' }, kpis: [kpi('liq', 80, 100, -20, 'ma')] },
          ],
          destaques: [],
        });
      return Promise.reject(new Error(`inesperado ${url}`));
    });
  });

  it('título com a empresa, Imprimir todos, De/Até, Actualizar, legenda dos períodos, separadores com ícone e resumo em grelha', async () => {
    const { container } = envolver(<RelatoriosGestao />);
    expect(await screen.findByText('Relatórios de Gestão')).toBeTruthy();
    expect(screen.getByText('Empresa Teste · Indicadores por módulo, com comparação entre períodos')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Imprimir todos/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Actualizar/ })).toBeTruthy();
    expect(screen.getByLabelText('Período em análise (A)')).toBeTruthy();
    expect(screen.getByLabelText('Comparar com (B)')).toBeTruthy();
    await waitFor(() => expect(container.querySelector('.rg-legenda')?.textContent).toContain('01/10/2026 a 31/10/2026 (31 dias)'));
    expect(container.querySelector('.rg-legenda')?.textContent).toContain('01/10/2025 a 31/10/2025 (31 dias)');
    // B só editável com «Datas à escolha»
    expect((container.querySelector('#rg-b-ini') as HTMLInputElement).disabled).toBe(true);
    const tabs = screen.getAllByRole('tab');
    expect(tabs.map((t) => t.textContent?.trim())).toEqual(['Resumo executivo', 'Finanças', 'Vendas']);
    expect(tabs.every((t) => t.querySelector('svg'))).toBe(true);
    expect(tabs[0].getAttribute('aria-selected')).toBe('true');
    // resumo: sem destaques → texto do antigo; módulos em grelha com «Ver módulo» e mini-cartões
    expect(await screen.findByText('Nenhum indicador variou 20 % ou mais entre os períodos.')).toBeTruthy();
    expect(container.querySelector('.rg-grid2')?.children).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: 'Ver módulo' })).toHaveLength(2);
    const boa = container.querySelector('.rg-kpi.boa') as HTMLElement;
    expect(within(boa).getByText('B: 100')).toBeTruthy();
    expect(boa.querySelector('.rg-var')?.textContent).toBe('▲ +20 (+20,0 %)');
    expect(container.querySelector('.rg-kpi.ma .rg-var')?.textContent).toBe('▼ -20 (-20,0 %)');
    // «Ver módulo» abre o separador
    obterMock.mockImplementationOnce(() => new Promise(() => undefined));
    fireEvent.click(screen.getAllByRole('button', { name: 'Ver módulo' })[1]);
    expect(screen.getAllByRole('tab')[2].getAttribute('aria-selected')).toBe('true');
  }, 30_000);
});

describe('Dashboard Global — ecrã', () => {
  beforeEach(() => {
    obterMock.mockReset();
    obterMock.mockImplementation((url: string) => {
      if (url === '/gestao/paineis')
        return Promise.resolve({
          holding: false,
          paineis: [
            { id: 'geral', nome: 'Visão Geral', tipo: 'PAINEL' },
            { id: 'rh', nome: 'Recursos Humanos', tipo: 'PAINEL' },
            { id: 'cubo', nome: 'Análise Dinâmica', tipo: 'CUBO' },
          ],
        });
      if (url === '/gestao/paineis/geral')
        return Promise.resolve({
          modulo: { id: 'geral', nome: 'Visão Geral' },
          periodo: { ano: 2026, mes: 10, meses: [] },
          kpis: [{ id: 'vendas_mes', rotulo: 'Vendas do Mês', valor: '1000.00', formato: 'kz', subtitulo: null, ir: 'vendas' }],
          graficos: [],
          tabelas: [],
          atalhos: [],
        });
      return Promise.resolve([]);
    });
  });

  it('título «Dashboard» com a empresa e o período; pílulas com ícone; activo pelo endereço; cartão com ícone e barra à esquerda', async () => {
    const { default: Dashboard } = await import('./Dashboard');
    const { container } = envolver(<Dashboard />, '/m/geral/dashboard?separador=geral');
    expect(await screen.findByText('Dashboard')).toBeTruthy();
    expect(screen.getByText('Empresa Teste')).toBeTruthy();
    expect(container.textContent).toContain('Período de referência:');
    const pilulas = container.querySelectorAll('.painel-modulo-btn');
    expect(Array.from(pilulas).map((b) => b.textContent)).toEqual(['Visão Geral', 'Recursos Humanos', 'Análise Dinâmica']);
    expect(Array.from(pilulas).every((b) => b.querySelector('svg'))).toBe(true);
    expect(pilulas[0].className).toContain('activo');
    expect((pilulas[0] as HTMLElement).style.getPropertyValue('--painel-cor')).toBe('#0f172a');
    await waitFor(() => expect(container.querySelector('.erp-kpi')).toBeTruthy());
    const cartao = container.querySelector('.erp-kpi') as HTMLElement;
    expect(cartao.className).toContain('erp-kpi-barra-esquerda');
    expect(cartao.querySelector('.erp-kpi-icone')).toBeTruthy();
    fireEvent.click(pilulas[1]);
    expect(container.querySelectorAll('.painel-modulo-btn')[1].className).toContain('activo');
  }, 30_000);
});
