import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { Catalogos, DetalhePlano, Indicador, MetasIndicador, Serie } from './api';
import { escada, formatarValorIndicador, lerPasso, rotuloPeriodo, trimestresDoCiclo } from './comum';

vi.setConfig({ testTimeout: 120_000 });

const PLANO = {
  id: 1, nome: 'Plano 2026-2028', ano_inicio: 2026, ano_fim: 2028, missao: 'Servir bem.', visao: null, valores: null, estado: 'RASCUNHO', estado_rotulo: 'Em elaboração', versao: 1,
  congelado: false, em_vigor: false, aprovado_em: null, aprovado_por: null, aprovacao_excepcao: null, motivo_reabertura: null,
} as const;
const DETALHE = (estado: 'RASCUNHO' | 'APROVADO' = 'RASCUNHO'): DetalhePlano => ({
  plano: { ...PLANO, estado, estado_rotulo: estado, congelado: estado !== 'RASCUNHO', em_vigor: estado === 'APROVADO' },
  perspectivas: [
    { id: 10, plano_estrategico_id: 1, ordem: 10, nome: 'Financeira', descricao: 'Rentabilidade.', cor: '#0f766e', icone: 'fa-coins' },
    { id: 11, plano_estrategico_id: 1, ordem: 20, nome: 'Clientes', descricao: null, cor: '#1d4ed8', icone: 'fa-handshake' },
  ],
  objectivos: [
    { id: 100, plano_estrategico_id: 1, perspectiva_id: 10, objectivo_pai_id: null, unidade_organica_id: null, responsavel_colaborador_id: null, responsavel_externo: 'Direcção', nome: 'Crescer a margem', descricao: null, horizonte: 'PLANO', periodo: null, periodo_rotulo: null, estado: 'ACTIVO', estado_rotulo: 'Activo', ordem: 10 },
    { id: 101, plano_estrategico_id: 1, perspectiva_id: 10, objectivo_pai_id: 100, unidade_organica_id: null, responsavel_colaborador_id: null, responsavel_externo: null, nome: 'Fechar contratos de manutenção', descricao: null, horizonte: 'TRIMESTRE', periodo: '2026-T2', periodo_rotulo: '2.º trimestre de 2026', estado: 'ACTIVO', estado_rotulo: 'Activo', ordem: 20 },
  ],
  indicadores_por_objectivo: { '100': { total: 1, activos: 1 } },
});
const RESUMO = (estado = 'RASCUNHO') => ({ id: 1, nome: PLANO.nome, ano_inicio: 2026, ano_fim: 2028, estado, estado_rotulo: estado, versao: 1, congelado: estado !== 'RASCUNHO', em_vigor: estado === 'APROVADO' });

const ind = (x: Partial<Indicador>): Indicador => ({
  id: 7, plano_estrategico_id: 1, objectivo_id: 100, objectivo: { id: 100, nome: 'Crescer a margem', perspectiva_id: 10, horizonte: 'PLANO' }, nome: 'Taxa de entrega', descricao: 'Entregas a tempo', origem: 'MANUAL', origem_rotulo: 'Recolha manual', fonte: {},
  polaridade: 'SOBE', tipo: 'RESULTADO', formato: 'pct', frequencia: 'TRIMESTRAL', agregacao: 'MEDIA', unidade_medida: null, linha_base: '80.0000', linha_base_periodo: '2025', oculto: false,
  dono_colaborador_id: null, dono_externo: 'Ana Dono', prazo_dias: null, tolerancia_verde: '100.00', tolerancia_amarela: '90.00', ativo: true, metas_count: 0, medicoes_count: 0, ...x,
});
const INDICADORES: Indicador[] = [
  ind({}),
  ind({ id: 8, nome: 'Entregas validadas', descricao: null, origem: 'MODULO', origem_rotulo: 'Dados de um módulo', fonte: { modulo: 'entregaveis', operacao: 'SOMA', campo: 'quantidade', filtros: [] }, dono_externo: null, metas_count: 4, formato: 'kz', oculto: true, linha_base: null }),
];
const CATALOGOS = {
  origens: [{ id: 'KPI_GESTAO', nome: 'Indicador de gestão', disponivel: false }, { id: 'MODULO', nome: 'Dados de um módulo', disponivel: true }, { id: 'MANUAL', nome: 'Recolha manual', disponivel: true }],
  polaridades: [{ id: 'SOBE', nome: 'Quanto maior, melhor' }], tipos: [{ id: 'RESULTADO', nome: 'Resultado (lagging)' }], frequencias: [{ id: 'TRIMESTRAL', nome: 'Trimestral' }, { id: 'MENSAL', nome: 'Mensal' }],
  formatos: [{ id: 'pct', nome: 'Percentagem' }], agregacoes: [], operacoes: [{ id: 'SOMA', nome: 'Somar um campo' }], operadores: [{ id: 'IGUAL', nome: '=' }], semaforos: [],
  estados_plano: [], estados_objectivo: [{ id: 'ACTIVO', nome: 'Activo' }], horizontes: [],
  fontes_modulo: [{ id: 'entregaveis', nome: 'Entregáveis das unidades', campos: [{ id: 'quantidade', nome: 'Quantidade entregue' }], filtros: [] }],
  unidades: [], colaboradores: [],
} as unknown as Catalogos;
const METAS: MetasIndicador = {
  indicador_id: 7, frequencia: 'TRIMESTRAL', polaridade: 'SOBE', formato: 'pct', oculto: false, metas: [{ periodo: '2026-T1', valor: '90.0000', valor_minimo: null, nota: null }],
  anos: [2026, 2027, 2028].map((a) => ({ ano: a, periodos: [1, 2, 3, 4].map((t) => ({ chave: `${a}-T${t}`, rotulo: `${t}.º trimestre de ${a}` })) })),
};
const SERIE: Serie = {
  indicador_id: 7, ano: 2026, frequencia: 'TRIMESTRAL', formato: 'pct', polaridade: 'SOBE', oculto: false,
  periodos: [
    { periodo: '2026-T1', rotulo: '1.º trimestre de 2026', meta: '9.0000', valor: '8.1000', cumprimento: '90.0000', semaforo: 'AMARELO', semaforo_rotulo: 'Em risco', oculto: false, nota: null, medido_por: 'ana', medido_em: null },
    { periodo: '2026-T2', rotulo: '2.º trimestre de 2026', meta: null, valor: null, cumprimento: null, semaforo: 'SEM_META', semaforo_rotulo: 'Sem meta', oculto: false, nota: null, medido_por: null, medido_em: null },
  ],
  resumo: { total: 2, VERDE: 0, AMARELO: 1, VERMELHO: 0, SEM_META: 1, SEM_MEDIDA: 0, cumpridos_pct: '0.0' },
};

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
let estadoPlano: 'RASCUNHO' | 'APROVADO' = 'RASCUNHO';
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));

import PlanoObjectivos from './PlanoObjectivos';
import Indicadores from './Indicadores';

const dialogoDe = async (titulo: string | RegExp) => {
  const el = await waitFor(() => {
    const t = [...document.querySelectorAll('.ant-modal-title')].find((n) => (typeof titulo === 'string' ? n.textContent === titulo : titulo.test(n.textContent ?? '')));
    if (!t) throw new Error(`diálogo «${String(titulo)}» não encontrado`);
    return t;
  });
  return el.closest('.ant-modal') as HTMLElement;
};
const ecra = (ui: ReactNode) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={['/?plano=1']}>{ui}</MemoryRouter></QueryClientProvider>);

beforeEach(() => {
  obterMock.mockReset(); enviarMock.mockReset();
  estadoPlano = 'RASCUNHO';
  permissoes = new Set(['est_plano_view', 'est_plano_editar', 'est_indicadores_view', 'est_ind_editar', 'est_metas_editar', 'est_entregar']);
  obterMock.mockImplementation((url: string) => {
    if (url === '/estrategia/planos') return Promise.resolve({ planos: [RESUMO(estadoPlano)] });
    if (url === '/estrategia/planos/1') return Promise.resolve(DETALHE(estadoPlano));
    if (url === '/estrategia/planos/1/indicadores') return Promise.resolve({ indicadores: INDICADORES });
    if (url === '/estrategia/catalogos') return Promise.resolve(CATALOGOS);
    if (url === '/estrategia/indicadores/7/metas') return Promise.resolve(METAS);
    if (url === '/estrategia/indicadores/7/serie') return Promise.resolve(SERIE);
    return Promise.resolve(null);
  });
  enviarMock.mockResolvedValue({ dados: { ...PLANO, estado: 'APROVADO' }, mensagem: 'ok' });
});

describe('Planeamento Estratégico — cálculo no cliente', () => {
  it('a escada de metas dá os mesmos valores que a fixture do servidor (decimal, sem erros de vírgula flutuante)', () => {
    expect(escada('100', '10', 4, false)).toEqual(['100.00', '110.00', '120.00', '130.00']);
    expect(escada('100', '5', 4, true)).toEqual(['100.00', '105.00', '110.25', '115.76']);
    expect(escada('1000', '-100', 3, false)).toEqual(['1000.00', '900.00', '800.00']);
    expect(escada('0,1', '0,2', 3, false)).toEqual(['0.10', '0.30', '0.50']);
    expect(escada('', '', 2, false)).toEqual(['0.00', '0.00']);
    expect(lerPasso('5%')).toEqual({ passo: '5', percentual: true });
    expect(lerPasso(' 7 ')).toEqual({ passo: '7', percentual: false });
  });

  it('rótulos de período, trimestres do ciclo e formatação por formato', () => {
    expect(rotuloPeriodo('2027-03')).toBe('Março de 2027');
    expect(rotuloPeriodo('2027-T2')).toBe('2.º trimestre de 2027');
    expect(rotuloPeriodo('2027-S1')).toBe('1.º semestre de 2027');
    expect(rotuloPeriodo('2027')).toBe('2027');
    expect(trimestresDoCiclo(2026, 2027)).toHaveLength(8);
    expect(formatarValorIndicador('90.0000', 'pct')).toMatch(/90 %/);
    expect(formatarValorIndicador(null, 'num')).toBe('—');
    expect(formatarValorIndicador('1000', 'kz', true)).toBe('Reservado');
  });
});

describe('Plano Estratégico — ecrã', () => {
  it('mostra missão, perspectivas, objectivos com OKR a tracejado e aviso de objectivo sem indicador', async () => {
    ecra(<PlanoObjectivos />);
    expect(await screen.findByText('Crescer a margem')).toBeInTheDocument();
    expect(screen.getByText('Servir bem.')).toBeInTheDocument();
    expect(screen.getAllByText('por preencher').length).toBe(2);
    const okr = screen.getByRole('button', { name: 'Abrir o objectivo Fechar contratos de manutenção' });
    expect(okr).toHaveClass('est-okr');
    expect(okr).toHaveTextContent('OKR · 2.º trimestre de 2026');
    expect(screen.getByText('sem indicador — não é medido')).toBeInTheDocument();
    expect(screen.getByText('Em elaboração')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Editar plano/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Aprovar plano/ })).toBeNull();   // sem est_plano_aprovar
  });

  it('só quem tem a permissão de aprovação vê «Aprovar plano» e a confirmação chama o servidor', async () => {
    permissoes = new Set(['est_plano_view', 'est_plano_aprovar']);
    ecra(<PlanoObjectivos />);
    fireEvent.click(await screen.findByRole('button', { name: /Aprovar plano/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Aprovar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/estrategia/planos/1/aprovar', { motivo: undefined, excepcao: undefined }));
    expect(screen.queryByRole('button', { name: /Novo objectivo/ })).toBeNull();   // sem est_plano_editar
  });

  it('um plano aprovado está congelado: sem aprovação não há botões de edição; com aprovação pede-se o motivo', async () => {
    estadoPlano = 'APROVADO';
    ecra(<PlanoObjectivos />);
    expect(await screen.findByText('Plano aprovado: a estrutura está congelada.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Novo objectivo/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Editar plano/ })).toBeNull();
  });

  it('com est_plano_aprovar num plano aprovado, alterar um objectivo pede o motivo antes de gravar', async () => {
    estadoPlano = 'APROVADO';
    permissoes = new Set(['est_plano_view', 'est_plano_editar', 'est_plano_aprovar']);
    ecra(<PlanoObjectivos />);
    fireEvent.click(await screen.findByRole('button', { name: 'Abrir o objectivo Crescer a margem' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Gravar' }));
    const dialogo = await dialogoDe('Alterar um plano aprovado');
    fireEvent.change(within(dialogo).getByRole('textbox'), { target: { value: 'Correcção de uma gralha no texto' } });
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Continuar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('put', '/estrategia/planos/1/objectivos/100', expect.objectContaining({ motivo_excepcao: 'Correcção de uma gralha no texto', nome: 'Crescer a margem' })));
  });
});

describe('Indicadores e Metas — ecrã', () => {
  it('lista os indicadores com a origem descrita, o dono, o aviso de metas em falta e o valor financeiro reservado', async () => {
    ecra(<Indicadores />);
    expect(await screen.findByText('Taxa de entrega')).toBeInTheDocument();
    expect(screen.getByText('entregue por quem o recolhe')).toBeInTheDocument();
    expect(screen.getByText('Entregáveis das unidades · Somar um campo')).toBeInTheDocument();
    expect(screen.getByText('Ana Dono')).toBeInTheDocument();
    expect(screen.getByText('1 indicador(es) sem metas — não serão avaliados.')).toBeInTheDocument();
    expect(screen.getByText('Reservado')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Novo indicador/ })).toBeEnabled();
  });

  it('filtra pelo texto procurado (nome, objectivo, origem ou dono)', async () => {
    ecra(<Indicadores />);
    await screen.findByText('Taxa de entrega');
    fireEvent.change(screen.getByLabelText('Procurar'), { target: { value: 'validadas' } });
    await waitFor(() => expect(screen.queryByText('Taxa de entrega')).toBeNull());
    expect(screen.getByText('Entregas validadas')).toBeInTheDocument();
  });

  it('metas por período: a escada preenche todas as caixas e só se enviam os períodos com valor ou que tinham meta', async () => {
    ecra(<Indicadores />);
    await screen.findByText('Taxa de entrega');
    fireEvent.click(screen.getByRole('button', { name: 'Metas por período de Taxa de entrega' }));
    const t1 = await screen.findByLabelText('Meta de 1.º trimestre de 2026');
    await waitFor(() => expect(t1).toHaveValue('90'));
    fireEvent.click(screen.getByRole('button', { name: 'Preencher em escada' }));
    const dialogo = await dialogoDe('Preencher em escada');
    const caixas = within(dialogo).getAllByRole('textbox');
    fireEvent.change(caixas[0], { target: { value: '100' } });
    fireEvent.change(caixas[1], { target: { value: '5%' } });
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Preencher' }));
    await waitFor(() => expect(screen.getByLabelText('Meta de 4.º trimestre de 2026')).toHaveValue('115.76'));
    expect(screen.getByLabelText('Meta de 1.º trimestre de 2028')).toHaveValue('147.75');   // 100 × 1,05^8 = 147,7455… (o 9.º período)
    fireEvent.click(screen.getByRole('button', { name: 'Gravar metas' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('put', '/estrategia/indicadores/7/metas', expect.objectContaining({ metas: expect.arrayContaining([{ periodo: '2026-T1', valor: '100.00' }, { periodo: '2028-T4', valor: '171.03' }]) })));
    expect((enviarMock.mock.calls[0][2] as { metas: unknown[] }).metas).toHaveLength(12);
  });

  it('série: mostra meta, valor, cumprimento e o semáforo com texto (nunca só cor) e permite entregar valores manuais', async () => {
    ecra(<Indicadores />);
    await screen.findByText('Taxa de entrega');
    fireEvent.click(screen.getByRole('button', { name: 'Série e semáforo de Taxa de entrega' }));
    expect(await screen.findByText('Em risco')).toBeInTheDocument();
    expect(screen.getByText('Sem meta')).toBeInTheDocument();
    expect(screen.getByText(/90 %/, { selector: '.est-valor' })).toBeInTheDocument();
    const botoes = screen.getAllByRole('button', { name: 'Entregar' });
    fireEvent.click(botoes[1]);
    const dialogo = await dialogoDe(/Entregar valor/);
    fireEvent.change(within(dialogo).getByLabelText('Valor medido'), { target: { value: '8,1' } });
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Entregar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/estrategia/indicadores/7/medicoes', { periodo: '2026-T2', valor: '8.1', nota: '' }));
  });

  it('sem est_entregar não há botão de entregar; sem est_ind_editar o formulário é só de consulta', async () => {
    permissoes = new Set(['est_plano_view', 'est_indicadores_view']);
    ecra(<Indicadores />);
    await screen.findByText('Taxa de entrega');
    expect(screen.queryByRole('button', { name: /Novo indicador/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Série e semáforo de Taxa de entrega' }));
    await screen.findByText('Em risco');
    expect(screen.queryByRole('button', { name: 'Entregar' })).toBeNull();
    expect(screen.getAllByRole('button', { name: /^Ver / }).length).toBeGreaterThan(0);
  });
});
