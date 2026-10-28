import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { errosLinha } from '@/componentes/formularios';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
const obterPagina = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: (...a: unknown[]) => obterPagina(...a),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresas: [], empresa: null, utilizador: null, menu: [] }) }));

import TabelasAux from '../TabelasAux';
import { SeparadorTerceiros } from './SeparadorTerceiros';
import { CAMPOS_CONTA, camposRegistoAux, dadosConta, dadosRegistoAux } from './tabelasAux';
import { CAMPOS_TERCEIRO } from './SeparadorTerceiros';

// ecrãs AntD completos no jsdom (lentos com a máquina carregada)
vi.setConfig({ testTimeout: 300_000 });

/** Respostas de GET por URL (as preferências vêm do «servidor» de preferências abaixo). */
let respostas: Record<string, unknown> = {};

beforeEach(() => {
  respostas = {};
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  // preferências: o servidor devolve o que foi gravado (PUT /sistema/preferencias/interface/{nome})
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(url.startsWith('/sistema/preferencias') ? [...prefs] : respostas[url] ?? []));
  obterPagina.mockReset().mockResolvedValue({ itens: [], paginacao: { pagina_atual: 1, por_pagina: 50, total: 0, ultima_pagina: 1 } });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: { id: 99 }, mensagem: 'ok' });
  });
});

function montar(no: React.ReactNode, rota = '/m/contab/tabelas_aux') {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={[rota]}>{no}</MemoryRouter>
    </QueryClientProvider>,
  );
}

const celula = (rotulo: string, n: number) => screen.getByLabelText(`${rotulo}, linha ${n}`) as HTMLInputElement;
const posts = (url: string) => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === url);

describe('Tabelas auxiliares — Grade | Linhas: funções puras', () => {
  it('a grade e as linhas montam o mesmo corpo', () => {
    expect(dadosRegistoAux({ codigo: ' 01 ', descricao: ' Salários ' })).toEqual({ codigo: '01', descricao: 'Salários' });
    expect(dadosConta({ codigo: '43.1', descricao: 'Banco', tipo: 't', codigo_moeda: 'usd', natureza_conta: '' })).toEqual({ codigo: '43.1', descricao: 'Banco', tipo: 'T', codigo_moeda: 'USD', natureza_conta: null });
    expect(dadosConta({ codigo: '61', descricao: 'Vendas' })).toMatchObject({ tipo: 'M', codigo_moeda: null });
  });

  it('as linhas validam com as regras da grade', () => {
    expect(errosLinha(camposRegistoAux(), { codigo: 'X', descricao: '' })).toMatch(/Falta: Descrição/);
    expect(errosLinha(CAMPOS_CONTA, { codigo: '43 1', descricao: 'A', tipo: 'M' })).toMatch(/só algarismos, letras e pontos/);
    expect(errosLinha(CAMPOS_TERCEIRO, { nif: '1', nome: 'A', tipo: 'CLIENTE', conta_cliente: '' })).toMatch(/conta de cliente \(classe 31\)/);
    expect(errosLinha(CAMPOS_TERCEIRO, { nif: '1', nome: 'A', tipo: 'FORNECEDOR', conta_fornecedor: '31.1' })).toMatch(/não é da classe 32, 37\.0, 37\.1 ou 37\.92/);
    expect(errosLinha(CAMPOS_TERCEIRO, { nif: '1', nome: 'A', tipo: 'CLIENTE_FORNECEDOR', conta_cliente: '31.1', conta_fornecedor: '37.0.1' })).toBeNull();
  });
});

describe('Tabelas auxiliares — Grade | Linhas nos cadastros', () => {
  it('Diários: «Novo Diário» em Linhas grava um diário por linha pelo mesmo POST; em edição só há a grade', async () => {
    respostas['/contabilidade/tabelas/diarios'] = [{ id: 1, codigo: '01', descricao: 'Geral' }];
    montar(<TabelasAux />, '/m/contab/tabelas_aux?separador=diarios');
    expect(await screen.findByText('Novo Diário')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Linhas/ }));
    fireEvent.paste(await screen.findByLabelText('Código, linha 1'), { clipboardData: { getData: () => '02\tVendas\n03\tCompras\n' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    await waitFor(() => expect(posts('/contabilidade/tabelas/diarios')).toHaveLength(2));
    expect(posts('/contabilidade/tabelas/diarios').map((c) => c[2])).toEqual([{ codigo: '02', descricao: 'Vendas' }, { codigo: '03', descricao: 'Compras' }]);

    // editar: o cartão passa a «Editar — 01» e o selector desaparece
    fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
    expect(await screen.findByText('Editar — 01')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
  });

  it('Plano de contas: «Nova conta» em Linhas grava cada conta pelo mesmo POST e não grava nada com uma linha inválida', async () => {
    montar(<TabelasAux />, '/m/contab/tabelas_aux?separador=plano');
    fireEvent.click(await screen.findByRole('button', { name: /Nova conta/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    fireEvent.paste(await screen.findByLabelText('Código da conta, linha 1'), { clipboardData: { getData: () => '43.1.1\tBanco BFA\tM\tUSD\n43 2\tBanco BAI\tM\t\n' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    expect(await screen.findByText(/só algarismos, letras e pontos/)).toBeInTheDocument();
    expect(posts('/contabilidade/plano-contas')).toHaveLength(0);

    fireEvent.change(celula('Código da conta', 2), { target: { value: '43.2' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    await waitFor(() => expect(posts('/contabilidade/plano-contas')).toHaveLength(2));
    expect(posts('/contabilidade/plano-contas')[0][2]).toEqual({ codigo: '43.1.1', descricao: 'Banco BFA', tipo: 'M', codigo_moeda: 'USD', natureza_conta: null });
  });

  it('Terceiros: «Novo Terceiro» em Linhas valida as contas de cada papel e grava pelo corpo da grade', async () => {
    montar(<SeparadorTerceiros />);
    expect(await screen.findByText('Novo Terceiro')).toBeInTheDocument();
    // o endereço é uma secção recolhível (fechada num terceiro novo)
    expect(screen.getByRole('button', { name: /Endereço/ })).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(screen.getByRole('button', { name: /Linhas/ }));
    fireEvent.paste(await screen.findByLabelText('NIF, linha 1'), { clipboardData: { getData: () => '5000000001\tCliente Fictício\tCliente\t31.1.1\n5000000002\tFornecedor Fictício\tFornecedor\t\n' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    expect(await screen.findByText(/Indique a conta de fornecedor/)).toBeInTheDocument();
    expect(posts('/terceiros')).toHaveLength(0);

    fireEvent.change(celula('Conta de fornecedor (32/37.0)', 2), { target: { value: '32.1.1' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    await waitFor(() => expect(posts('/terceiros')).toHaveLength(2));
    expect(posts('/terceiros')[0][2]).toMatchObject({ papel: 'CLIENTE', substituir_tipo: true, nif: '5000000001', nome: 'Cliente Fictício', conta_cliente: '31.1.1', codigo_moeda: 'AOA' });
    expect(posts('/terceiros')[1][2]).toMatchObject({ papel: 'FORNECEDOR', conta_fornecedor: '32.1.1' });
    expect(posts('/terceiros')[1][2].conta_cliente).toBeUndefined();
  });
});
