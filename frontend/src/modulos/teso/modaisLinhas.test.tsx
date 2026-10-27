import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn().mockResolvedValue({ itens: [], total: 0 }),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: { nome_utilizador: 'admin' } }) }));

import MeiosPagamento, { dadosMeio } from './MeiosPagamento';
import FolhaCaixa, { dadosMovimento, totaisMovimentos } from './FolhaCaixa';
import { ModelosTexto, dadosModelo } from './cartas/Modelos';

// antd no jsdom é lento: margem larga por teste (como nos pilotos)
vi.setConfig({ testTimeout: 120_000 });

const PLANO = [
  { codigo: '43.1.1', descricao: 'Banco BAI', tipo: 'M' },
  { codigo: '45.1', descricao: 'Caixa sede', tipo: 'M' },
  { codigo: '62.1', descricao: 'Fornecimentos', tipo: 'M' },
  { codigo: '43', descricao: 'Depósitos à ordem', tipo: 'T' },
];
const SESSAO = {
  id: 7, codigo_conta: '45.1', operador: 'admin', data_abertura: '2026-10-15', data_fecho: null, saldo_abertura: '1000.00', saldo_fecho: null, saldo_fisico: null,
  estado: 'ABERTA', codigo_moeda: null, numeros_lan_contabilizacao: null, fechado_por: null, contabilizado_em: null, saldo_sistema: '1050.00', diferenca: null,
  movimentos: [
    { id: 1, tipo: 'REC', valor: '100.00', data_documento: '2026-10-15', contabilizado: false },
    { id: 2, tipo: 'PAG', valor: '50.00', data_documento: '2026-10-15', contabilizado: false },
  ],
};
const MODELOS = { modelos: [], padrao: { TERCEIRO: { assunto: 'Ordem de pagamento', corpo: 'Exmos. Senhores', cargo_assinatura: 'Gerente' }, SALARIOS: { assunto: 'Salários', corpo: 'Texto', cargo_assinatura: 'Gerente' } }, variaveis: {} };

function ecra(no: ReactNode, inicial = '/') {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={[inicial]}><Routes><Route path="/*" element={no} /></Routes></MemoryRouter>
    </QueryClientProvider>,
  );
}

// consultas por texto (as consultas por papel são muito lentas com o DOM das páginas inteiras no jsdom)
const clicarTexto = (t: string | RegExp) => fireEvent.click(screen.getByText(t));
async function colar(texto: string) {
  const grupo = await waitFor(() => { const g = document.querySelector('.erp-alternar-formulario'); if (!g) throw new Error('sem selector'); return g as HTMLElement; });
  fireEvent.click(within(grupo).getByText('Linhas'));
  await screen.findByTestId('painel-linhas');
  clicarTexto('Colar do Excel');
  fireEvent.change(await screen.findByLabelText('Linhas a colar'), { target: { value: texto } });
  clicarTexto('Acrescentar linhas');
}
async function registar() {
  clicarTexto('Registar linhas');
  fireEvent.click(await screen.findByText('Registar', { selector: 'button span' }));
}
const posts = (url: string) => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === url);

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    if (url === '/contabilidade/plano-contas') return Promise.resolve(PLANO);
    if (url === '/tesouraria/caixa/sessoes/7') return Promise.resolve(SESSAO);
    if (url === '/tesouraria/cartas/modelos') return Promise.resolve(MODELOS);
    if (url === '/tesouraria/meios-pagamento') return Promise.resolve([{ id: 3, nome: 'Caixa', codigo_conta: '45.1', iban: null, swift: null, ativo: true, predefinido: true }]);
    return Promise.resolve([]);
  });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('regras partilhadas entre Grade e Linhas', () => {
  it('meio de pagamento: caixas da grade ou «Sim/Não» das linhas', () => {
    expect(dadosMeio({ nome: ' BAI ', codigo_conta: '43.1.1', iban: '', ativo: true, predefinido: false })).toEqual({ nome: 'BAI', codigo_conta: '43.1.1', iban: undefined, swift: undefined, ativo: true, predefinido: false });
    expect(dadosMeio({ nome: 'Caixa', codigo_conta: '45.1', activo_sn: 'N', padrao_sn: 'S' })).toMatchObject({ ativo: false, predefinido: true });
  });
  it('movimento de caixa: data dayjs ou texto e vazios omitidos; totais de entradas e saídas', () => {
    expect(dadosMovimento({ tipo: 'PAG', data_documento: '2026-10-16', valor: 10, descricao: ' Táxi ', referencia: '', terceiro_id: null })).toEqual({ tipo: 'PAG', data_documento: '2026-10-16', valor: 10, descricao: 'Táxi' });
    expect(totaisMovimentos([{ tipo: 'REC', valor: '100.10' }, { tipo: 'REC', valor: '0.20' }, { tipo: 'PAG', valor: '50' }])).toEqual({ entradas: 100.3, saidas: 50 });
  });
  it('modelo de carta: o tipo só vai na criação', () => {
    expect(dadosModelo({ nome: 'A', assunto: 'B', corpo: 'C', predefinido_sn: 'S' }, 'SALARIOS')).toMatchObject({ nome: 'A', predefinido: true, tipo: 'SALARIOS' });
    expect(dadosModelo({ nome: 'A', assunto: 'B', corpo: 'C', predefinido: false })).not.toHaveProperty('tipo');
  });
});

describe('Meios de pagamento — «Novo Meio de Pagamento» em Grade | Linhas', () => {
  it('cola do Excel e grava um meio por linha pelo mesmo POST e fecha no fim', async () => {
    ecra(<MeiosPagamento />);
    fireEvent.click(await screen.findByText('Novo Meio de Pagamento'));
    await colar('Banco BAI\t43.1.1\tAO06 0040\tBAIAAOLU\nCaixa loja\t45.1\t\t\tNão');
    await waitFor(() => expect(screen.getByLabelText('Nome / Designação, linha 2')).toHaveValue('Caixa loja'));
    await registar();
    await waitFor(() => expect(posts('/tesouraria/meios-pagamento')).toHaveLength(2));
    expect(posts('/tesouraria/meios-pagamento').map((c) => c[2])).toEqual([
      { nome: 'Banco BAI', codigo_conta: '43.1.1', iban: 'AO06 0040', swift: 'BAIAAOLU', ativo: true, predefinido: false },
      { nome: 'Caixa loja', codigo_conta: '45.1', iban: undefined, swift: undefined, ativo: false, predefinido: false },
    ]);
    await waitFor(() => expect(screen.queryByTestId('painel-linhas')).toBeNull());
  });
});

describe('Folha de caixa — «Registar movimento de caixa» em Grade | Linhas', () => {
  it('resumo da sessão em cartões e um movimento por linha no POST da sessão', async () => {
    ecra(<FolhaCaixa />, '/7');
    expect(await screen.findByText('Saldo Inicial')).toBeInTheDocument();
    expect(screen.getAllByText('+100,00').length).toBeGreaterThan(0);
    expect(screen.getAllByText('-50,00').length).toBeGreaterThan(0);
    expect(screen.getByText('Movimentos da Sessão (2)')).toBeInTheDocument();
    clicarTexto('Registar Saída / Despesa');
    // coluna nova «Bem/Serviço» entre a conta e a descrição (IBAN automático): tabulação vazia para a saltar
    await colar('Saída\t16/10/2026\t1.500,00\t62.1\t\tCompra de material\nEntrada\t16/10/2026\t200\t62.1\t\tDevolução');
    await waitFor(() => expect(screen.getByLabelText('Descrição, linha 2')).toHaveValue('Devolução'));
    await registar();
    await waitFor(() => expect(posts('/tesouraria/caixa/sessoes/7/movimentos')).toHaveLength(2));
    expect(posts('/tesouraria/caixa/sessoes/7/movimentos')[0][2]).toEqual({ tipo: 'PAG', data_documento: '2026-10-16', valor: 1500, conta_contrapartida: '62.1', descricao: 'Compra de material' });
    expect(posts('/tesouraria/caixa/sessoes/7/movimentos')[1][2]).toMatchObject({ tipo: 'REC', valor: 200 });
  });
});

describe('Cartas › Modelo de texto — como o legado (um texto por tipo)', () => {
  it('sem modelo gravado mostra o texto padrão e «Guardar modelo» cria o modelo activo do tipo', async () => {
    ecra(<ModelosTexto />);
    expect(await screen.findByDisplayValue('Ordem de pagamento')).toBeInTheDocument();
    expect(screen.queryByText(/Novo modelo/)).toBeNull();
    fireEvent.change(screen.getByLabelText('Assinado por'), { target: { value: 'Director Ficticio' } });
    clicarTexto('Guardar modelo');
    await waitFor(() => expect(posts('/tesouraria/cartas/modelos')).toHaveLength(1));
    expect(posts('/tesouraria/cartas/modelos')[0][2]).toMatchObject({ assunto: 'Ordem de pagamento', corpo: 'Exmos. Senhores', nome_assinatura: 'Director Ficticio', tipo: 'TERCEIRO', predefinido: true });
  });

  it('com vários modelos edita o predefinido e guarda os outros («Usar este»)', async () => {
    const lista = [
      { id: 1, tipo: 'TERCEIRO', nome: 'Antigo', assunto: 'Assunto antigo', corpo: 'Texto antigo', nome_assinatura: null, cargo_assinatura: null, local: null, predefinido: false },
      { id: 2, tipo: 'TERCEIRO', nome: 'Actual', assunto: 'Assunto actual', corpo: 'Texto actual', nome_assinatura: null, cargo_assinatura: null, local: null, predefinido: true },
    ];
    obter.mockImplementation((url: string) => Promise.resolve(url === '/tesouraria/cartas/modelos' ? { ...MODELOS, modelos: lista } : []));
    ecra(<ModelosTexto />);
    expect(await screen.findByDisplayValue('Assunto actual')).toBeInTheDocument();
    expect(screen.getByText('Outros modelos guardados deste tipo (1)')).toBeInTheDocument();
    clicarTexto('Guardar modelo');
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('put', '/tesouraria/cartas/modelos/2', expect.objectContaining({ nome: 'Actual', assunto: 'Assunto actual', predefinido: true })));
  });
});
