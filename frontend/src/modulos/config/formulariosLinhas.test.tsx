import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import dayjs from 'dayjs';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

/**
 * Configurações — modais de criação com as vistas «Grade | Linhas» (docs/paridade/versao_actual/07_modais_grade_linhas.md):
 * cada linha é gravada pela mesma chamada (e a mesma transformação) da grade; na edição só há a grade.
 */
const obter = vi.fn();
const enviar = vi.fn();
const obterPagina = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: (...a: unknown[]) => obterPagina(...a),
  descarregar: vi.fn(),
  http: { post: vi.fn() },
}));
// sessão estável (como no contexto real): objectos novos em cada render disparariam os efeitos que dependem de `empresa`
const SESSAO = {
  pode: () => true,
  empresa: { id: 7, nome: 'Empresa Fictícia' },
  empresas: [{ id: 7, nome: 'Empresa Fictícia' }],
  utilizador: { id: 1, papel: 'ADMINISTRADOR' },
  escolherEmpresa: () => Promise.resolve(),
  menu: [],
};
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => SESSAO }));

vi.setConfig({ testTimeout: 420_000 });

import { dadosCambio, dadosMoeda } from './Moedas';
import { dadosConta } from './PlanoContas';
import { corpoUnidade } from './UnidadesNegocio';
import { dadosUtilizador } from './Utilizadores';
import { dadosEmpresa } from './Empresas';

const MOEDAS = [
  { id: 1, codigo: 'AOA', nome: 'Kwanza', simbolo: 'Kz', casas_decimais: 2, ativo: true, base: true },
  { id: 2, codigo: 'USD', nome: 'Dólar', simbolo: '$', casas_decimais: 2, ativo: true, base: false },
];

function envolver(ui: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );
}

const celula = (rotulo: string, n: number) => screen.getByLabelText(`${rotulo}, linha ${n}`) as HTMLInputElement;
const escrever = (el: HTMLElement, v: string) => fireEvent.change(el, { target: { value: v } });
const posts = (url: string) => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === url);

async function abrirLinhas(botao: RegExp) {
  fireEvent.click(await screen.findByRole('button', { name: botao }, { timeout: 60_000 }));
  const dialogo = await screen.findByRole('dialog');
  fireEvent.click(within(dialogo).getByRole('button', { name: /Linhas/ }));
  await screen.findByTestId('painel-linhas');
}

async function colar(texto: string) {
  fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
  escrever(await screen.findByLabelText('Linhas a colar'), texto);
  fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
}

async function registar() {
  fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
  fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    if (url === '/sistema/moedas') return Promise.resolve(MOEDAS);
    if (url === '/sistema/moedas/funcional') return Promise.resolve({ codigo_moeda: 'AOA', nome: 'Kwanza', simbolo: 'Kz', casas_decimais: 2, base: true });
    if (url === '/sistema/perfis') return Promise.resolve([{ id: 3, nome: 'Salários', acesso_total: false }]);
    if (url.startsWith('/sistema/cambios-manuais') || url.startsWith('/sistema/cambios/bai')) return Promise.resolve({ tolerancia_pct: 5, pode_exceder: false, pendentes: [] });
    return Promise.resolve([]);
  });
  obterPagina.mockReset().mockResolvedValue({ itens: [], paginacao: { pagina_atual: 1, por_pagina: 50, total: 0, ultima_pagina: 1 } });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('transformações comuns à grade e às linhas', () => {
  it('moeda e câmbio', () => {
    expect(dadosMoeda({ codigo: ' eur ', nome: 'Euro', simbolo: '€', casas_decimais: 2 })).toEqual({ codigo: 'EUR', nome: 'Euro', simbolo: '€', casas_decimais: 2 });
    expect(dadosCambio({ data_taxa: dayjs('2026-10-01'), codigo_moeda: 'USD', taxa: 925.5, substituir: 1 })).toEqual({ data_taxa: '2026-10-01', codigo_moeda: 'USD', taxa: '925.5', substituir: true });
    expect(dadosCambio({ data_taxa: '2026-10-02', codigo_moeda: 'USD', taxa: 930 })).toMatchObject({ data_taxa: '2026-10-02', taxa: '930' });
  });

  it('conta: vazios a null e dados bancários só nas 42/43 de movimento', () => {
    const d = dadosConta({ codigo: '43.1.1', descricao: 'BAI', tipo: 'M', codigo_moeda: '', natureza_conta: '', banco_iban: ' AO06 ' });
    expect(d).toMatchObject({ codigo: '43.1.1', codigo_moeda: null, natureza_conta: null, banco_iban: 'AO06', banco_nome: null });
    expect(dadosConta({ codigo: '62', descricao: 'Custos', tipo: 'T', banco_iban: 'AO06' })).toMatchObject({ banco_iban: null });
  });

  it('unidade de negócio, utilizador e empresa: 1/0 das linhas em booleano', () => {
    expect(corpoUnidade({ codigo: 'UN1', nome: 'Sede', valido_de: '2026-01-01', tem_vendas: 1, tem_servico: 0, email: '' }))
      .toMatchObject({ valido_de: '2026-01-01', valido_ate: null, tem_vendas: true, tem_servico: false, email: null });
    expect(dadosUtilizador({ nome_utilizador: ' ana ', palavra_passe: 'segredo123', perfil_utilizador_id: 3, ativo: 1, acesso_todas_empresas: 0 }, [{ empresa_id: 7, colaborador_id: null }], false))
      .toEqual({ nome_utilizador: 'ana', nome_completo: null, email: null, perfil_utilizador_id: 3, acesso_todas_empresas: false, ativo: true, empresas: [{ empresa_id: 7, colaborador_id: null }], palavra_passe: 'segredo123' });
    expect(dadosEmpresa({ nome: 'Nova', nif: '500', email: '', moeda_funcional: 'usd', e_consolidacao: 0 })).toEqual({ nome: 'Nova', nif: '500', email: null, moeda_funcional: 'USD', e_consolidacao: false });
  });
});

describe('Moedas — «Nova moeda» e «Novo câmbio» em Linhas', () => {
  it('grava uma moeda por linha pelo mesmo POST (código em maiúsculas); editar só tem a grade', async () => {
    const { default: Moedas } = await import('./Moedas');
    envolver(<Moedas />);
    fireEvent.click(await screen.findByRole('tab', { name: 'Moedas' }));
    await abrirLinhas(/Nova moeda/);
    escrever(celula('Código ISO 4217', 1), 'eur');
    escrever(celula('Nome', 1), 'Euro');
    escrever(celula('Símbolo', 1), '€');
    escrever(celula('Código ISO 4217', 2), 'zar');
    escrever(celula('Nome', 2), 'Rand');
    escrever(celula('Símbolo', 2), 'R');
    await registar();
    await waitFor(() => expect(posts('/sistema/moedas')).toHaveLength(2));
    expect(posts('/sistema/moedas').map((c) => c[2])).toEqual([
      { codigo: 'EUR', nome: 'Euro', simbolo: '€', casas_decimais: 2 },
      { codigo: 'ZAR', nome: 'Rand', simbolo: 'R', casas_decimais: 2 },
    ]);
    await waitFor(() => expect(screen.queryByTestId('painel-linhas')).toBeNull());
    fireEvent.click(screen.getAllByRole('button', { name: 'Editar' })[0]);
    expect(await screen.findByText('Moeda AOA')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
  });

  it('câmbios colados do Excel (data, moeda, taxa) gravam um a um', async () => {
    const { default: Moedas } = await import('./Moedas');
    envolver(<Moedas />);
    await abrirLinhas(/Novo câmbio/);
    // espera pelas moedas (opções da coluna «Moeda»)
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/sistema/moedas'));
    await colar('Data\tMoeda\tTaxa\n01/10/2026\tUSD\t925,50\n02/10/2026\tUSD\t930');
    await registar();
    await waitFor(() => expect(posts('/sistema/cambios')).toHaveLength(2));
    expect(posts('/sistema/cambios')[0][2]).toMatchObject({ data_taxa: '2026-10-01', codigo_moeda: 'USD', taxa: '925.5', ambito: 'TODAS', fonte_dados: 'MANUAL' });
    expect(posts('/sistema/cambios')[1][2]).toMatchObject({ data_taxa: '2026-10-02', taxa: '930' });
  });
});

describe('Plano de contas — «Nova Conta» em Linhas', () => {
  it('grava as contas pelo POST da grade, com tipo por omissão Movimento', async () => {
    const base = obter.getMockImplementation()!;
    obter.mockImplementation((url: string) => (url === '/contabilidade/plano-contas' ? Promise.resolve([{ id: 1, codigo: '62', descricao: 'Custos', tipo: 'T', codigo_moeda: null, natureza_conta: null }]) : base(url)));
    const { default: PlanoContas } = await import('./PlanoContas');
    envolver(<PlanoContas />);
    await abrirLinhas(/Nova Conta/);
    escrever(celula('Código da conta', 1), '62.1.1');
    escrever(celula('Descrição', 1), 'Rendas');
    escrever(celula('Código da conta', 2), '62/1');
    escrever(celula('Descrição', 2), 'Inválida');
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    expect(await screen.findByText(/Só algarismos, letras e pontos/)).toBeInTheDocument();
    expect(posts('/contabilidade/plano-contas')).toHaveLength(0);
    escrever(celula('Código da conta', 2), '62.1.2');
    await registar();
    await waitFor(() => expect(posts('/contabilidade/plano-contas')).toHaveLength(2));
    expect(posts('/contabilidade/plano-contas')[0][2]).toMatchObject({ codigo: '62.1.1', descricao: 'Rendas', tipo: 'M', codigo_moeda: null, banco_iban: null });
  });
});

describe('Utilizadores — «Novo utilizador» em Linhas', () => {
  it('cada linha cria um utilizador na empresa activa; a palavra-passe fica escondida', async () => {
    const { default: Utilizadores } = await import('./Utilizadores');
    envolver(<Utilizadores />);
    await abrirLinhas(/Novo utilizador/);
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/sistema/perfis'));
    expect(celula('Palavra-passe', 1).type).toBe('password');
    await colar('Nome de utilizador\tNome completo\tEmail\tPalavra-passe\tPerfil\nana\tAna Fictícia\tana@exemplo.invalid\tsegredo123\tSalários');
    await registar();
    await waitFor(() => expect(posts('/sistema/utilizadores')).toHaveLength(1));
    expect(posts('/sistema/utilizadores')[0][2]).toEqual({
      nome_utilizador: 'ana', nome_completo: 'Ana Fictícia', email: 'ana@exemplo.invalid', perfil_utilizador_id: 3,
      acesso_todas_empresas: false, ativo: true, empresas: [{ empresa_id: 7, colaborador_id: null }], palavra_passe: 'segredo123',
    });
  });

  it('a grade continua com os campos usados pelos E2E (#nome_utilizador, #perfil_utilizador_id) e as secções abertas', async () => {
    const { default: Utilizadores } = await import('./Utilizadores');
    envolver(<Utilizadores />);
    fireEvent.click(await screen.findByRole('button', { name: /Novo utilizador/ }, { timeout: 60_000 }));
    const dialogo = await screen.findByRole('dialog');
    for (const id of ['nome_utilizador', 'nome_completo', 'email', 'palavra_passe', 'perfil_utilizador_id']) expect(dialogo.querySelector(`#${id}`)).not.toBeNull();
    for (const s of ['Conta', 'Perfil e estado', 'Empresas e colaborador']) expect(within(dialogo).getByRole('button', { name: new RegExp(`(^| )${s}$`) })).toHaveAttribute('aria-expanded', 'true');
  });
});

describe('Empresas — «Nova empresa» em Linhas e ficha em secções', () => {
  it('grava uma empresa por linha; a grade tem as secções da ficha', async () => {
    const { default: Empresas } = await import('./Empresas');
    envolver(<Empresas />);
    fireEvent.click(await screen.findByRole('button', { name: /Nova empresa/ }, { timeout: 60_000 }));
    const dialogo = await screen.findByRole('dialog');
    expect(within(dialogo).getByRole('button', { name: /(^| )Identificação$/ })).toHaveAttribute('aria-expanded', 'true');
    expect(within(dialogo).getByRole('button', { name: /(^| )Documentos$/ })).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(within(dialogo).getByRole('button', { name: /Linhas/ }));
    await screen.findByTestId('painel-linhas');
    escrever(celula('Nome', 1), 'Filial Fictícia');
    escrever(celula('NIF', 1), '5000000000');
    await registar();
    await waitFor(() => expect(posts('/sistema/empresas')).toHaveLength(1));
    expect(posts('/sistema/empresas')[0][2]).toMatchObject({ nome: 'Filial Fictícia', nif: '5000000000', moeda_funcional: 'AOA', endereco: null });
  });
});

describe('Unidades de negócio — «Nova unidade» e «Novo critério» em Linhas', () => {
  it('grava as unidades pela mesma transformação da grade', async () => {
    const { UnidadesNegocio } = await import('./UnidadesNegocio');
    envolver(<UnidadesNegocio />);
    await abrirLinhas(/Nova unidade/);
    await colar('Código\tNome\tInício\nUN2\tFilial Norte\t01/01/2026');
    await registar();
    await waitFor(() => expect(posts('/sistema/unidades-negocio')).toHaveLength(1));
    expect(posts('/sistema/unidades-negocio')[0][2]).toMatchObject({ codigo: 'UN2', nome: 'Filial Norte', estado: 'ATIVO', valido_de: '2026-01-01', tem_vendas: false, tem_servico: false, email: null });
  });

  it('critérios de repartição em Linhas (código em maiúsculas, forma por omissão)', async () => {
    const { CriteriosReparticao } = await import('@/componentes/reparticao');
    envolver(<CriteriosReparticao />);
    await abrirLinhas(/Novo critério/);
    escrever(celula('Código', 1), 'area');
    escrever(celula('Nome', 1), 'Por área ocupada');
    await registar();
    await waitFor(() => expect(posts('/reparticao/criterios')).toHaveLength(1));
    expect(posts('/reparticao/criterios')[0][2]).toMatchObject({ codigo: 'AREA', nome: 'Por área ocupada', criterio: 'PERCENTAGEM', ativo: true, indicador: null, periodo_de: null });
  });
});
