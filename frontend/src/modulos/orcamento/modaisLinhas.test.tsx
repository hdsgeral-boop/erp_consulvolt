import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
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

import Rubricas, { contasDeTexto, dadosRubrica } from './Rubricas';
import Cenarios, { dadosCenario } from './Cenarios';

// antd no jsdom é lento: margem larga por teste (como nos pilotos)
vi.setConfig({ testTimeout: 120_000 });

const ORCAMENTOS = {
  por_omissao: 5,
  orcamentos: [{ id: 5, orcamento_pai_id: null, estado: 'APROVADO', ano: 2026, tipo: 'EXPLORACAO', versao: 1, nome: null, nivel: 0, dimensoes: 'Toda a empresa', total_rubricas: '0', ultima_accao: { accao: 'APROVADO', por: null, em: null }, rotulo: '2026 · Exploração · Toda a empresa · v1 (Aprovado)' }],
};

function ecra(no: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}><MemoryRouter>{no}</MemoryRouter></QueryClientProvider>);
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
    if (url === '/orcamento/paridade/orcamentos') return Promise.resolve(ORCAMENTOS);
    return Promise.resolve([]);
  });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: { id: 9 }, mensagem: 'ok' });
  });
});

describe('regras partilhadas entre Grade e Linhas', () => {
  it('rubrica: contas da grade (lista) ou das linhas («62; 63») e controlo só em custos/pagamentos', () => {
    expect(contasDeTexto('62; 631, 7111', false)).toEqual([{ codigo: '62', prefixo: false }, { codigo: '631', prefixo: false }, { codigo: '7111', prefixo: false }]);
    const grade = dadosRubrica({ tipo: 'EXPLORACAO', natureza: 'PROVEITO', codigo: 'R1', nome: 'Vendas', ativo: true, contas: [{ codigo: ' 71 ', prefixo: true }, { codigo: '', prefixo: true }], controlo: { modo: 'AVISAR' } });
    expect(grade).toMatchObject({ contas: [{ codigo: '71', prefixo: true }], controlo: null, ativo: true, grupo: null });
    const linhas = dadosRubrica({ tipo: 'EXPLORACAO', natureza: 'CUSTO', codigo: 'R2', nome: 'Pessoal', contas_texto: '72', contas_prefixo: 'N', activa: 'N', controlo_modo: 'BLOQUEAR', aviso_pct: 80, limite_pct: null });
    expect(linhas).toMatchObject({ contas: [{ codigo: '72', prefixo: false }], ativo: false, controlo: { modo: 'BLOQUEAR', aviso_pct: 80, limite_pct: 100, base: 'ACUMULADO' } });
  });
  it('cenário: variáveis em % das linhas (zeros e vazios ficam de fora)', () => {
    expect(dadosCenario({ nome: ' Crise ', tipo: 'PESSIMISTA', vendas_volume_pct: -10, pessoal_pct: 0, cambio_pct: null }, 5))
      .toEqual({ orcamento_anual_id: 5, nome: 'Crise', tipo: 'PESSIMISTA', variaveis: { vendas_volume_pct: -10 }, ajustes: {}, notas: null });
  });
});

describe('Rubricas — «Nova rubrica» em Grade | Linhas', () => {
  it('valida a natureza pelo tipo e grava uma rubrica por linha pelo mesmo POST', async () => {
    ecra(<Rubricas />);
    fireEvent.click(await screen.findByText(/Nova rubrica/));
    await colar('Exploração\tCusto\tR10\tPessoal\t72; 73\tSim\nTesouraria\tCusto\tR11\tErrada\t32\tNão');
    await waitFor(() => expect(screen.getByLabelText('Nome, linha 2')).toHaveValue('Errada'));
    clicarTexto('Registar linhas');
    expect(await screen.findByText(/A natureza não é deste tipo de rubrica\./)).toBeInTheDocument();
    expect(posts('/orcamento/rubricas')).toHaveLength(0);
    fireEvent.click(screen.getByLabelText('Remover linha 2'));
    await registar();
    await waitFor(() => expect(posts('/orcamento/rubricas')).toHaveLength(1));
    expect(posts('/orcamento/rubricas')[0][2]).toEqual({
      tipo: 'EXPLORACAO', natureza: 'CUSTO', codigo: 'R10', nome: 'Pessoal', ordem: null, grupo: null, descricao: null, indutor: null, variavel_pct: null, cambial_pct: null,
      ativo: true, controlo: { modo: 'NENHUM', aviso_pct: 90, limite_pct: 100, base: 'ACUMULADO' }, contas: [{ codigo: '72', prefixo: true }, { codigo: '73', prefixo: true }],
    });
  });
});

describe('Cenários — «Novo cenário» em Grade | Linhas', () => {
  it('grava um cenário por linha sobre o orçamento base escolhido', async () => {
    ecra(<Cenarios />);
    fireEvent.click(await screen.findByText(/Novo cenário/));
    await colar('Expansão\tOtimista\nContenção\tPessimista');
    await waitFor(() => expect(screen.getByLabelText('Nome, linha 2')).toHaveValue('Contenção'));
    await registar();
    await waitFor(() => expect(posts('/orcamento/cenarios')).toHaveLength(2));
    expect(posts('/orcamento/cenarios').map((c) => c[2])).toEqual([
      { orcamento_anual_id: 5, nome: 'Expansão', tipo: 'OTIMISTA', variaveis: {}, ajustes: {}, notas: null },
      { orcamento_anual_id: 5, nome: 'Contenção', tipo: 'PESSIMISTA', variaveis: {}, ajustes: {}, notas: null },
    ]);
  });
});

describe('Cenários — grelha de cartões, um por cenário (legado)', () => {
  it('cada cartão tem as 6 variáveis, o resultado face à base e os botões Gravar / Ajustes / Nova versão', async () => {
    const calc = {
      cenario: { id: 7, orcamento_anual_id: 5, nome: 'Otimista', tipo: 'OTIMISTA', variaveis: { vendas_volume_pct: 10 }, ajustes: { 1: 5 }, notas: null },
      orcamento_id: 5, resultado_base: '1000.00', resultado_cenario: '1250.00',
      linhas: [{ rubrica_id: 1, codigo: 'P1', nome: 'Vendas', natureza: 'PROVEITO', indutor: 'vendas_volume_pct', base: '1000.00', cenario: '1250.00', variacao: '250.00', valores: [] }],
    };
    obter.mockImplementation((url: string) => {
      if (url.startsWith('/sistema/preferencias')) return Promise.resolve([]);
      if (url === '/orcamento/paridade/orcamentos') return Promise.resolve(ORCAMENTOS);
      if (url === '/orcamento/orcamentos/5/cenarios') return Promise.resolve([calc.cenario]);
      if (url === '/orcamento/cenarios/7') return Promise.resolve(calc);
      return Promise.resolve([]);
    });
    ecra(<Cenarios />);
    expect(await screen.findByTestId('cartao-cenario')).toBeInTheDocument();
    await waitFor(() => expect((screen.getByLabelText('Volume de vendas (%) — Otimista') as HTMLInputElement).value).toMatch(/^10[.,]00$/));
    expect(screen.getByText(/vs base/)).toBeInTheDocument();
    expect(screen.getByText('Ajustes (1)')).toBeInTheDocument();
    expect(screen.getByText('Nova versão')).toBeInTheDocument();
  });
});
