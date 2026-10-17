import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.setConfig({ testTimeout: 180_000 });

let sessaoActual: Record<string, unknown>;
const SESSAO = {
  id: 7, armazem_id: 1, data: '2026-10-01', descricao: 'Inventário Anual', estado: 'REVISAO', tipo: null,
  linhas: [
    { id: 1, produto_id: 11, codigo: 'A-1', nome: 'Cabo', custo_medio: '100.00', quantidade_sistema: '10', diferenca: '-2', quantidade_contada: '8', observacoes: null, justificacao: null, custo_personalizado: null, custo_unitario: null, valor_diferenca: null },
    { id: 2, produto_id: 12, codigo: 'B-2', nome: 'Disjuntor', custo_medio: '50.00', quantidade_sistema: '5', diferenca: '0', quantidade_contada: '5', observacoes: null, justificacao: null, custo_personalizado: null, custo_unitario: null, valor_diferenca: null },
  ],
};

vi.mock('@/api/cliente', () => ({
  obter: (url: string) => Promise.resolve(url === '/logistica/armazens' ? [{ id: 1, nome: 'Central', predefinido: true }] : url === '/logistica/inventarios/7' ? sessaoActual : []),
  obterPagina: () => Promise.resolve({ itens: [sessaoActual], paginacao: { total: 1, pagina_atual: 1, por_pagina: 200, ultima_pagina: 1 } }),
  enviar: () => Promise.resolve({ dados: {}, mensagem: 'ok' }),
  descarregar: () => Promise.resolve(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: () => true, menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

import { textoPorReceber } from './ValidarEntradas';
import { destinoDetalhesInventario, EcraInventario } from './comum/Inventario';

beforeEach(() => {
  sessaoActual = SESSAO;
});

const montar = (modo: 'contagem' | 'revisao') =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/r']}>
        <Routes>
          <Route path="/r/*" element={<EcraInventario modo={modo} />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

describe('Sessões de inventário — «Detalhes» como no legado (viewInvSession, ui_inventory.js:232-237)', () => {
  it('em contagem vai para a folha de contagem; nos outros estados para a revisão; sem acesso, para a ficha', () => {
    const todos = () => true;
    expect(destinoDetalhesInventario({ id: 7, estado: 'EM_CONTAGEM' }, todos)).toBe('/m/stock/inventario_contagem?lote=7');
    expect(destinoDetalhesInventario({ id: 7, estado: 'REVISAO' }, todos)).toBe('/m/stock/inventario_revisao?lote=7');
    expect(destinoDetalhesInventario({ id: 7, estado: 'CONCLUIDA' }, todos)).toBe('/m/stock/inventario_revisao?lote=7');
    expect(destinoDetalhesInventario({ id: 7, estado: 'EM_CONTAGEM' }, () => false)).toBe('7');
  });
});

describe('Efetuar Contagem — todos os artigos sem paginação (ui_inventory.js:441)', () => {
  it('lista virtualizada com deslocamento vertical, sem paginador, e a pesquisa filtra', async () => {
    const linhas = Array.from({ length: 120 }, (_, i) => ({ id: i + 1, produto_id: 100 + i, codigo: `C-${String(i + 1).padStart(3, '0')}`, nome: `Artigo fictício ${i + 1}`,
      custo_medio: '1.00', quantidade_contada: null, observacoes: null, justificacao: null, custo_personalizado: null, custo_unitario: null, valor_diferenca: null }));
    sessaoActual = { ...SESSAO, estado: 'EM_CONTAGEM', linhas };
    const { container } = montar('contagem');
    expect(await screen.findByText('120 artigo(s)', undefined, { timeout: 60_000 })).toBeInTheDocument();
    expect(container.querySelector('.ant-pagination')).toBeNull();
    expect(container.querySelector('.ant-table-tbody-virtual')).not.toBeNull();
    fireEvent.change(screen.getByPlaceholderText('Scan Código Barras / Pesquisa...'), { target: { value: 'C-120' } });
    expect(await screen.findByText('1 de 120 artigo(s)')).toBeInTheDocument();
    expect(screen.getByText('Artigo fictício 120')).toBeInTheDocument();
  });
});

describe('Validar Entradas — «Encomendas por Receber» do legado', () => {
  it('«Produto: quantidade» só das linhas com quantidade por receber', () => {
    const l = (nome: string, q: string, r: string | null) => ({ produto: { id: 1, codigo: null, nome }, quantidade: q, quantidade_recebida: r }) as never;
    expect(textoPorReceber([l('Cabo', '10', '4'), l('Fio', '3', '3'), l('Tubo', '2', null)])).toBe('Cabo: 6 · Tubo: 2');
    expect(textoPorReceber([])).toBe('');
  });
});

describe('Revisão e Regularização — folha de trabalho do legado', () => {
  it('com um só lote abre-o logo: selector, resumo e só as linhas com diferença', async () => {
    montar('revisao');
    expect(await screen.findByText('Revisão de Inventário e Regularização', undefined, { timeout: 60_000 })).toBeInTheDocument();
    expect(screen.getByText('Selecionar Lote:')).toBeInTheDocument();
    expect(await screen.findByText('Lote: #7 | Ref: Inventário Anual', undefined, { timeout: 60_000 })).toBeInTheDocument();
    expect(screen.getByText('Cabo')).toBeInTheDocument();
    expect(screen.queryByText('Disjuntor')).toBeNull();
    expect(screen.getByText('-2 (Quebra)')).toBeInTheDocument();
    expect(screen.getByText('Mostrar artigos sem Diferença')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Aprovar e Regularizar Stock/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Reabrir Contagem/ })).toBeInTheDocument();
  });
});
