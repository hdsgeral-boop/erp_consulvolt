import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from 'antd';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const obterPagina = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  obterPagina: (...a: unknown[]) => obterPagina(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: { nome_utilizador: 'ana' } }) }));

import SeriesNumeracao from './series/SeriesNumeracao';
import Produtos from './produtos/Produtos';
import { dadosSerieFE } from './agt/FaturacaoEletronica';
import { camposProdutoLinhas, corpoProdutoLinha } from './produtos/formularioProduto';
import type { DadosSeries } from './series/regrasSeries';

// antd no jsdom é lento (e a máquina de testes partilhada): margem larga por teste e nas esperas
vi.setConfig({ testTimeout: 600_000 });
const ESPERA = { timeout: 30_000 };

const ANO = new Date().getFullYear();
const SERIES: DadosSeries = {
  ano: ANO,
  regime: { ativo: false, data_inicio: null, exigir_series_agt: false },
  estabelecimentos: [],
  origens: [{ valor: 'GERAL', rotulo: 'Facturação geral' }, { valor: 'POS1', rotulo: 'Terminal POS1' }],
  tipos: { FT: 'Factura', FR: 'Factura-Recibo', NC: 'Nota de Crédito' },
  resumo: [],
  series: [
    { id: 7, tipo: 'FT', ano: ANO, origem: 'GERAL', codigo: 'A' + ANO, estabelecimento: null, proximo_numero: 3, proximo_documento: `FT A${ANO}/3`, emitidos: 2, usada: true,
      ultima_data: null, ultimo_documento: null, estado: 'ATIVA', contingencia: false, agt_codigo: null, agt_primeiro_numero: null, agt_ultimo_numero: null },
  ],
};

function ecra(no: ReactNode, rota = '/') {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={[rota]}>
        <App>{no}</App>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const celula = (rotulo: string, n: number) => screen.getByLabelText(`${rotulo}, linha ${n}`) as HTMLInputElement;
async function registarLinhas() {
  fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
  fireEvent.click(await screen.findByRole('button', { name: 'Registar' }, ESPERA));
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    if (url === '/vendas/series') return Promise.resolve(SERIES);
    if (url === '/logistica/categorias-produtos') return Promise.resolve([{ id: 1, nome: 'Bebidas' }]);
    return Promise.resolve([]);
  });
  obterPagina.mockReset().mockResolvedValue({ itens: [], total: 0, pagina: 1, por_pagina: 25 });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('Vendas — formulários de criação com Grade | Linhas', () => {
  it('«Nova série» em Linhas grava uma série por linha pelo mesmo POST; «Alterar» só tem a grade', async () => {
    ecra(<SeriesNumeracao />);
    fireEvent.click(await screen.findByRole('button', { name: /Nova série/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    await screen.findByTestId('painel-linhas');
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(await screen.findByLabelText('Linhas a colar'), { target: { value: `FR\t${ANO}\tGERAL\tB${ANO}\t501\nNC\t${ANO}\tPOS1\t\t` } });
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
    await waitFor(() => expect(celula('Código da série', 1).value).toBe(`B${ANO}`), ESPERA);
    await registarLinhas();
    const posts = () => enviar.mock.calls.filter((c) => c[1] === '/vendas/series');
    await waitFor(() => expect(posts()).toHaveLength(2), ESPERA);
    expect(posts()[0][2]).toEqual({ tipo: 'FR', ano: ANO, origem: 'GERAL', codigo: `B${ANO}`, proximo_numero: 501, estabelecimento: null, estado: 'ATIVA', contingencia: false });
    // código em branco = o sugerido para o terminal (como a grade)
    expect(posts()[1][2]).toMatchObject({ tipo: 'NC', origem: 'POS1', codigo: `POS1${ANO}`, proximo_numero: 1 });

    fireEvent.click(await screen.findByRole('button', { name: `Alterar a série FT A${ANO}` }));
    expect(await screen.findByText(`Alterar série FT A${ANO}`)).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
  });

  it('na vista Linhas, uma segunda série activa para o mesmo tipo, ano e origem é recusada antes de gravar', async () => {
    ecra(<SeriesNumeracao />);
    fireEvent.click(await screen.findByRole('button', { name: /Nova série/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    await screen.findByTestId('painel-linhas');
    fireEvent.change(celula('Código da série', 1), { target: { value: `Z${ANO}` } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    expect(await within(screen.getByTestId('painel-linhas')).findByText(/Já existe outra série activa para este tipo, ano e origem \(A\d{4}\)/, undefined, ESPERA)).toBeInTheDocument();
    expect(enviar.mock.calls.filter((c) => c[1] === '/vendas/series')).toHaveLength(0);
  });

  it('«Nova Categoria» em Linhas grava uma categoria por linha', async () => {
    ecra(<Produtos />, '/?separador=categorias');
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    fireEvent.change(await screen.findByLabelText('Nome da categoria, linha 1'), { target: { value: 'Serviços' } });
    fireEvent.change(screen.getByLabelText('Nome da categoria, linha 2'), { target: { value: ' Peças ' } });
    await registarLinhas();
    const posts = () => enviar.mock.calls.filter((c) => c[1] === '/logistica/categorias-produtos');
    await waitFor(() => expect(posts()).toHaveLength(2), ESPERA);
    expect(posts().map((c) => c[2])).toEqual([{ nome: 'Serviços' }, { nome: 'Peças' }]);
  });

  it('séries AGT e produtos: grade e linhas passam pela mesma função', () => {
    expect(dadosSerieFE({ tipo: 'FT', ano: 2026, codigo: ' X1 ', origem: '', estado: 'ATIVA', contingencia_sn: 'S' }))
      .toEqual({ tipo: 'FT', ano: 2026, codigo: 'X1', origem: null, origem_nome: null, estabelecimento: null, contingencia: true, estado: 'ATIVA' });
    expect(dadosSerieFE({ tipo: 'NC', ano: 2026, codigo: 'X1', contingencia: true }).contingencia).toBe(true);
    const corpo = corpoProdutoLinha({ codigo: 'P1', nome: 'Água', preco_unitario: 100, taxa_imposto: 14, movimenta_stock_sn: 'N', e_servico_sn: 'S', codigo_isencao_fe: 'm10' });
    expect(corpo).toMatchObject({ codigo: 'P1', nome: 'Água', preco_unitario: 100, movimenta_stock: false, e_servico: true, e_quarto: false, codigo_isencao_fe: 'M10' });
    const isencao = camposProdutoLinhas([]).find((c) => c.nome === 'codigo_isencao_fe')!;
    expect(isencao.validar?.('X1', {})).toMatch(/M00 a M99/);
  });
});
