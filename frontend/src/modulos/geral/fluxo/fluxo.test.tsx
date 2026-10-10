import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import type { ReactNode } from 'react';
import { CabecalhoFluxo, CartoesProcessos, PainelProcesso, SeparadoresFluxo } from './componentes';
import { VistaVolume, type VistaVolumeConfig } from './VistaVolume';
import { cartaoInicial, cartoesDoFluxo, dataCurta, etapaInicial, temCartoes, tituloCartoes, tituloDiagrama, type LinhaProcesso } from './logica';

const obterMock = vi.fn();
const obterPaginaMock = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), obterPagina: (...a: unknown[]) => obterPaginaMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ menu: [{ id: 'rh', ecras: [{ id: 'processamento', vistas: ['processamento'] }] }] }),
}));
const navegarMock = vi.fn();
vi.mock('react-router-dom', async (orig) => ({ ...(await orig<typeof import('react-router-dom')>()), useNavigate: () => navegarMock }));

const ETAPAS = [
  { id: 'base', nome: 'Dados base', icone: 'users' },
  { id: 'calc', nome: 'Lançar rubricas', icone: 'calculator' },
  { id: 'fecho', nome: 'Fechar cálculo', icone: 'lock' },
  { id: 'valid', nome: 'Processar e validar', icone: 'check-double' },
  { id: 'integ', nome: 'Integrar na contabilidade', icone: 'book' },
  { id: 'pag', nome: 'Liquidar salários e obrigações', icone: 'hand-holding-usd' },
];

const linha = (chave: string, concluidas: number, extra: Partial<LinhaProcesso> = {}): LinhaProcesso => ({
  chave,
  titulo: chave,
  subtitulo: null,
  data: '2026-09',
  valor: null,
  valor_pendente: null,
  total_etapas: 6,
  concluidas,
  etapa_actual: concluidas >= 6 ? null : 'integ',
  etapa_actual_nome: null,
  etapa_actual_estado: null,
  etapa_actual_resumo: null,
  bloqueado: false,
  n_erros: 0,
  n_avisos: 0,
  progresso: { base: 'concluida', calc: 'concluida', fecho: 'concluida', valid: 'concluida', integ: concluidas >= 6 ? 'concluida' : 'curso', pag: concluidas >= 6 ? 'concluida' : 'fazer' },
  ...extra,
});

const envolver = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

describe('Fluxo de Processos — regras do legado (logica.ts)', () => {
  it('fluxos com cartões e títulos das secções e do diagrama', () => {
    expect(['rh', 'vendas', 'compras', 'imobilizado', 'pos', 'ferias', 'avaliacao', 'orcamento', 'crm', 'acrescimos'].every(temCartoes)).toBe(true);
    expect(['lavandaria', 'projetos', 'bancos', 'caixa'].some(temCartoes)).toBe(false);
    expect(tituloCartoes('rh', 3)).toBe('Períodos (últimos 3)');
    expect(tituloCartoes('vendas', 12)).toBe('Processos de venda (12)');
    expect(tituloDiagrama('rh', 'Salários e RH', { titulo: '09/2026', subtitulo: 'Processamento salarial' })).toBe('Processamento salarial · 09/2026');
    expect(tituloDiagrama('vendas', 'Vendas', { titulo: 'FT 1', subtitulo: 'Cliente' })).toBe('Venda · FT 1 · Cliente');
  });

  it('montarLista: até 24 por concluir e concluídos a completar 12; RH mostra os 12 mais recentes', () => {
    const feitos = Array.from({ length: 15 }, (_, i) => linha(`F${i}`, 6));
    const abertos = Array.from({ length: 3 }, (_, i) => linha(`A${i}`, 4));
    const l = cartoesDoFluxo('vendas', [...feitos, ...abertos]);
    expect(l).toHaveLength(12);
    expect(l.slice(0, 3).map((p) => p.chave)).toEqual(['A0', 'A1', 'A2']);
    expect(cartoesDoFluxo('vendas', Array.from({ length: 30 }, (_, i) => linha(`A${i}`, 2)))).toHaveLength(24);
    expect(cartoesDoFluxo('rh', [...feitos, ...abertos]).map((p) => p.chave)).toEqual(feitos.slice(0, 12).map((p) => p.chave));
  });

  it('selecção inicial: bloqueado › por concluir › primeiro; etapa inicial: bloqueada › em curso › por fazer › última', () => {
    expect(cartaoInicial([linha('a', 6), linha('b', 4), linha('c', 3, { bloqueado: true })])).toBe('c');
    expect(cartaoInicial([linha('a', 6), linha('b', 4)])).toBe('b');
    expect(cartaoInicial([linha('a', 6)])).toBe('a');
    expect(cartaoInicial([])).toBeNull();
    expect(etapaInicial(ETAPAS, { base: { estado: 'concluida' }, integ: { estado: 'curso' }, pag: { estado: 'fazer' } })).toBe('integ');
    expect(etapaInicial(ETAPAS, { base: { estado: 'concluida' }, pag: { estado: 'fazer' } })).toBe('calc');
    const tudo = Object.fromEntries(ETAPAS.map((e) => [e.id, { estado: 'concluida' }]));
    expect(etapaInicial(ETAPAS, tudo)).toBe('pag');
    expect(etapaInicial(ETAPAS, { ...tudo, integ: { estado: 'curso' } })).toBe('integ');
    expect(dataCurta('2026-10-02')).toBe('02/10/2026');
    expect(dataCurta('2026-09')).toBe('09/2026');
    expect(dataCurta(null)).toBe('—');
  });
});

describe('Fluxo de Processos — disposição do legado', () => {
  it('cabeçalho: título e subtítulo à esquerda, botões à direita (legado .page-head)', () => {
    const { container } = render(<CabecalhoFluxo subtitulo="Onde está cada período" accoes={<button type="button">Actualizar</button>} />);
    expect(screen.getByRole('heading', { name: 'Fluxo de Processos' })).toBeInTheDocument();
    const filhos = Array.from(container.querySelector('.fluxo-cabecalho')!.children).map((e) => e.className);
    expect(filhos[0]).toBe('fluxo-cabecalho-textos');
    expect(container.querySelector('.fluxo-cabecalho-textos .fluxo-sub')).not.toBeNull();
    expect(filhos[1]).toContain('fluxo-cabecalho-accoes');
  });

  it('separadores sublinhados com ícone; clique e setas mudam de fluxo', () => {
    const aoEscolher = vi.fn();
    const { container } = render(
      <SeparadoresFluxo
        fluxos={[
          { id: 'rh', nome: 'Salários e RH', icone: 'users' },
          { id: 'bancos', nome: 'Bancos (43)', icone: 'university' },
        ]}
        activo="rh"
        aoEscolher={aoEscolher}
      />,
    );
    expect(container.querySelector('.fluxo-tabs')).not.toBeNull();
    const tabs = screen.getAllByRole('tab');
    expect(tabs[0]).toHaveAttribute('aria-selected', 'true');
    expect(tabs[1]).toHaveTextContent('Bancos (43)');
    expect(tabs[1].querySelector('svg')).not.toBeNull();
    fireEvent.click(tabs[1]);
    expect(aoEscolher).toHaveBeenLastCalledWith('bancos');
    fireEvent.keyDown(tabs[0], { key: 'ArrowRight' });
    expect(aoEscolher).toHaveBeenCalledTimes(2);
  });

  it('cartões do RH: período grande, «▶ Em curso»/«✓ Concluído», segmentos e «n de 6 etapas concluídas»', () => {
    const aoSel = vi.fn();
    const { container } = render(
      <CartoesProcessos fluxo="rh" etapas={ETAPAS} lista={[linha('09/2026', 4), linha('01/2026', 6)]} seleccionado="09/2026" aoSeleccionar={aoSel} />,
    );
    const botoes = container.querySelectorAll('.fluxo-periodo');
    expect(botoes).toHaveLength(2);
    expect(botoes[0]).toHaveAttribute('aria-pressed', 'true');
    expect(botoes[0].querySelector('.fluxo-periodo-mes')).toHaveTextContent('09/2026');
    expect(botoes[0].querySelector('.fluxo-pill.curso')).toHaveTextContent('Em curso');
    expect(botoes[1].querySelector('.fluxo-pill.ok')).toHaveTextContent('Concluído');
    expect(botoes[0].querySelectorAll('.fluxo-mini i')).toHaveLength(6);
    expect(screen.getByText('4 de 6 etapas concluídas')).toBeInTheDocument();
    fireEvent.click(botoes[1]);
    expect(aoSel).toHaveBeenCalledWith('01/2026');
  });

  it('painel na página: diagrama, barra «4/6 … 67 %», detalhe sem pendências e botão que abre Processamentos', async () => {
    obterMock.mockResolvedValue({
      chave: 'periodo-120',
      titulo: '09/2026',
      subtitulo: 'Processamento salarial',
      data: '2026-09',
      valor: '0.00',
      valor_pendente: '0.00',
      total_etapas: 6,
      concluidas: 4,
      bloqueado: false,
      documentos: [],
      etapas: {
        base: { estado: 'concluida', resumo: '6 colaboradores', factos: [], problemas: [{ nivel: 'aviso', texto: '6 sem IBAN', ambito: 'empresa' }], accoes: [], narrativa: null },
        calc: { estado: 'concluida', resumo: '23 lançamentos', factos: [], problemas: [], accoes: [], narrativa: null },
        fecho: { estado: 'concluida', resumo: 'Cálculo fechado', factos: [], problemas: [], accoes: [], narrativa: null },
        valid: { estado: 'concluida', resumo: 'Validado', factos: [], problemas: [], accoes: [], narrativa: null },
        integ: {
          estado: 'curso',
          resumo: 'Pronto a integrar',
          factos: [{ rotulo: 'Estado', valor: 'Validado, por integrar', formato: 'texto' }],
          problemas: [],
          accoes: [{ rotulo: 'Integrar em Processamentos', acao: 'processamento', primaria: true }],
          narrativa: null,
        },
        pag: { estado: 'fazer', resumo: 'Depois de integrar', factos: [], problemas: [], accoes: [], narrativa: null },
      },
    });
    envolver(<PainelProcesso fluxo="rh" nomeFluxo="Salários e RH" etapas={ETAPAS} chave="periodo-120" narrativa={false} />);
    expect(await screen.findByText('Processamento salarial · 09/2026')).toBeInTheDocument();
    expect(obterMock).toHaveBeenCalledWith('/gestao/fluxos/rh/processos/periodo-120');
    expect(screen.getByText('4/6')).toBeInTheDocument();
    expect(screen.getByText('67%')).toBeInTheDocument();
    // etapa inicial = a em curso (Integrar na contabilidade), sem pendências
    await waitFor(() => expect(screen.getByRole('heading', { level: 3, name: /Integrar na contabilidade/ })).toBeInTheDocument());
    expect(screen.getByText(/Sem pendências nesta etapa/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Integrar em Processamentos/ }));
    expect(navegarMock).toHaveBeenCalledWith('/m/rh/processamento');
    // o aviso da empresa continua no detalhe dos dados base
    fireEvent.click(screen.getByRole('button', { name: /Dados base/ }));
    expect(await screen.findByText('6 sem IBAN')).toBeInTheDocument();
  });
});

const ETAPAS_LAV = [
  { id: 'recep', nome: 'Recepção', icone: 'inbox' },
  { id: 'orc', nome: 'Orçamento (alfaiataria)', icone: 'cut' },
  { id: 'exec', nome: 'Execução', icone: 'soap' },
  { id: 'ent', nome: 'Entrega ao cliente', icone: 'box-open' },
  { id: 'fact', nome: 'Facturação e contabilização', icone: 'file-invoice-dollar' },
  { id: 'rec', nome: 'Recebimento ou compensação', icone: 'hand-holding-usd' },
];

const VISTA_LAV: VistaVolumeConfig = {
  funil: [
    { id: 'orc', rotulo: 'Aguardam orçamento', parados: 0, com_pendencias: 0, valor: '0.00' },
    { id: 'exec', rotulo: 'Em execução', parados: 3, com_pendencias: 3, valor: '40000.00' },
    { id: 'ent', rotulo: 'Por entregar', parados: 0, com_pendencias: 0, valor: '0.00' },
    { id: 'fact', rotulo: 'Por facturar ou contabilizar', parados: 2, com_pendencias: 2, valor: '40000.00' },
    { id: 'rec', rotulo: 'Por receber', parados: 0, com_pendencias: 0, valor: '0.00' },
    { id: 'concluidos', rotulo: 'Concluídas', parados: 0, com_pendencias: 0, valor: null },
  ],
  filtros: [
    { id: 'curso', rotulo: 'Por concluir', total: 5 },
    { id: 'alerta', rotulo: 'Com pendências', total: 5 },
    { id: 'atrasadas', rotulo: 'Atrasadas', total: 3 },
    { id: 'levantar', rotulo: 'Por levantar', total: 0 },
    { id: 'fora_prazo', rotulo: 'Fora do prazo de levantamento', total: 0 },
    { id: 'receber', rotulo: 'Com valor por receber', total: 1 },
    { id: 'todas', rotulo: 'Todas', total: 5 },
  ],
  filtro_inicial: 'curso',
  colunas: [
    { tipo: 'titulo', rotulo: 'Ordem' },
    { tipo: 'texto', rotulo: 'Cliente', campo: 'cliente' },
    { tipo: 'data', rotulo: 'Recebida', campo: 'data' },
    { tipo: 'data', rotulo: 'Prazo', campo: 'prazo', alerta: 'atrasada' },
    { tipo: 'etapa', rotulo: 'Etapa actual' },
    { tipo: 'progresso', rotulo: 'Progresso' },
    { tipo: 'pendencias', rotulo: 'Pendências', marca: { campo: 'urgente', texto: 'Urgente' } },
    { tipo: 'kz', rotulo: 'Valor', campo: 'valor' },
    { tipo: 'kz', rotulo: 'Por receber', campo: 'valor_pendente', modo: 'destaque' },
  ],
  placeholder: 'Pesquisar ordem ou cliente',
  nome_itens: 'ordens',
  por_pagina: 25,
  vazio: { icone: 'tshirt', titulo: 'Ainda não há ordens de serviço', texto: 'Registe a primeira ordem.', botao: 'Abrir a Frente de Caixa', acao: 'pos' },
};

const ordem = (n: number, extra: Record<string, unknown> = {}) => ({
  ...linha(`ordem-${n}`, 2),
  titulo: `OS/${n}`,
  data: '2026-09-15 11:48:28',
  valor: '10000.00',
  valor_pendente: '0.00',
  etapa_actual: 'exec',
  etapa_actual_nome: 'Execução',
  etapa_actual_estado: 'curso',
  etapa_actual_resumo: '0 de 1 prontas',
  n_erros: 1,
  progresso: { recep: 'concluida', orc: 'concluida', exec: 'curso', ent: 'fazer', fact: 'fazer', rec: 'fazer' },
  cliente: 'Consumidor Final',
  prazo: '2026-09-17',
  atrasada: true,
  urgente: true,
  ...extra,
});

const pagina = (itens: unknown[], total: number, ultima = 1) => ({ itens, paginacao: { pagina_atual: 1, por_pagina: 25, total, ultima_pagina: ultima } });

describe('Fluxo de Processos — vista de volume (fluxo_tabela.js)', () => {
  beforeEach(() => {
    obterMock.mockReset();
    obterPaginaMock.mockReset();
  });

  it('funil, chips e tabela do fluxo com as colunas do legado; filtra no servidor e abre o fluxo por baixo da linha', async () => {
    obterPaginaMock.mockResolvedValue(pagina([ordem(1), ordem(2, { urgente: false, valor_pendente: '10000.00', atrasada: false })], 2));
    obterMock.mockResolvedValue({
      chave: 'ordem-1',
      titulo: 'OS/1',
      subtitulo: null,
      data: null,
      valor: null,
      valor_pendente: null,
      total_etapas: 6,
      concluidas: 2,
      bloqueado: false,
      documentos: [],
      etapas: Object.fromEntries(
        ETAPAS_LAV.map((e) => [e.id, { estado: e.id === 'exec' ? 'curso' : ['recep', 'orc'].includes(e.id) ? 'concluida' : 'fazer', resumo: e.nome, factos: [], problemas: [], accoes: [], narrativa: null }]),
      ),
    });
    const { container } = envolver(<VistaVolume fluxo="lavandaria" nomeFluxo="Lavandaria" etapas={ETAPAS_LAV} vista={VISTA_LAV} total={5} narrativa={false} />);

    // funil «Por etapa actual»: só as colunas do fluxo (sem «Recepção»), valor e «N com pendências»
    const colunas = Array.from(container.querySelectorAll('.ft-coluna'));
    expect(colunas.map((c) => c.querySelector('.ft-coluna-nome')!.textContent)).toEqual(VISTA_LAV.funil.map((f) => f.rotulo));
    expect(colunas[1]).toHaveTextContent('3 com pendências');
    expect(colunas[1].querySelector('.ft-coluna-info')!.textContent).toContain('Kz');
    expect(colunas[0].querySelector('.ft-coluna-info')!.textContent).toBe('');
    // chips com contagens; «Por concluir» activo ao abrir
    const chips = Array.from(container.querySelectorAll('.ft-chip'));
    expect(chips.map((c) => c.textContent)).toEqual(VISTA_LAV.filtros.map((f) => `${f.rotulo}${f.total}`));
    expect(chips[0]).toHaveAttribute('aria-pressed', 'true');
    expect(container.querySelector('input.ft-pesquisa')).toHaveAttribute('placeholder', 'Pesquisar ordem ou cliente');

    // tabela própria (.ft-tabela), sem a barra de exportação genérica
    await screen.findByText('OS/1');
    expect(Array.from(container.querySelectorAll('.ft-tabela th')).map((th) => th.textContent)).toEqual(VISTA_LAV.colunas.map((c) => c.rotulo));
    expect(container.querySelector('.ant-table')).toBeNull();
    expect(screen.queryByText(/registos encontrados/)).toBeNull();
    const l1 = container.querySelectorAll('tr.ft-linha')[0];
    expect(l1).toHaveTextContent('Consumidor Final');
    expect(l1).toHaveTextContent('15/09/2026');
    expect(l1).toHaveTextContent('Urgente');
    expect(l1.querySelectorAll('.fluxo-mini i')).toHaveLength(6);
    expect(screen.getByText('1–2 de 2 ordens · clique numa linha para ver o fluxo')).toBeInTheDocument();
    expect(obterPaginaMock).toHaveBeenLastCalledWith('/gestao/fluxos/lavandaria/processos', expect.objectContaining({ filtro: 'curso', etapa: null, pagina: 1, por_pagina: 25 }));

    fireEvent.click(chips[2]);
    await waitFor(() => expect(obterPaginaMock).toHaveBeenLastCalledWith('/gestao/fluxos/lavandaria/processos', expect.objectContaining({ filtro: 'atrasadas' })));
    // «Concluídas» passa o filtro para «Todas» (os concluídos não estão nos por concluir)
    fireEvent.click(colunas[5]);
    await waitFor(() => expect(obterPaginaMock).toHaveBeenLastCalledWith('/gestao/fluxos/lavandaria/processos', expect.objectContaining({ filtro: 'todas', etapa: 'concluidos' })));
    expect(screen.getByText(/Etapa: Concluídas/)).toBeInTheDocument();

    // o diagrama abre por baixo da linha escolhida
    fireEvent.click(await screen.findByRole('button', { name: 'OS/1' }));
    expect(screen.getByRole('button', { name: 'OS/1' })).toHaveAttribute('aria-expanded', 'true');
    expect(container.querySelector('tr.ft-linha + tr.ft-detalhe-linha')).not.toBeNull();
    await waitFor(() => expect(obterMock).toHaveBeenCalledWith('/gestao/fluxos/lavandaria/processos/ordem-1'));
    expect(await screen.findByRole('heading', { level: 3, name: /Execução/ })).toBeInTheDocument();
  });

  it('pesquisa ao escrever e paginação «Página x de y»', async () => {
    obterPaginaMock.mockResolvedValue(pagina([ordem(1)], 30, 2));
    const { container } = envolver(<VistaVolume fluxo="lavandaria" nomeFluxo="Lavandaria" etapas={ETAPAS_LAV} vista={VISTA_LAV} total={30} narrativa={false} />);
    await screen.findByText('OS/1');
    expect(screen.getByText('Página 1 de 2')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Página seguinte' }));
    await waitFor(() => expect(obterPaginaMock).toHaveBeenLastCalledWith('/gestao/fluxos/lavandaria/processos', expect.objectContaining({ pagina: 2 })));
    fireEvent.change(container.querySelector('input.ft-pesquisa')!, { target: { value: 'maria' } });
    await waitFor(() => expect(obterPaginaMock).toHaveBeenLastCalledWith('/gestao/fluxos/lavandaria/processos', expect.objectContaining({ pesquisa: 'maria', pagina: 1 })));
  });

  it('sem processos mostra o estado vazio do fluxo', () => {
    envolver(<VistaVolume fluxo="lavandaria" nomeFluxo="Lavandaria" etapas={ETAPAS_LAV} vista={VISTA_LAV} total={0} narrativa={false} />);
    expect(screen.getByText('Ainda não há ordens de serviço')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Abrir a Frente de Caixa' })).toBeInTheDocument();
    expect(obterPaginaMock).not.toHaveBeenCalled();
  });
});
