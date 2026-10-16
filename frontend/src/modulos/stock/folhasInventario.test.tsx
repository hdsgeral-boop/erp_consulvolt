import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';

vi.setConfig({ testTimeout: 180_000 });

const SESSAO = {
  id: 7, armazem_id: 1, data: '2026-10-01', descricao: 'Inventário Anual', estado: 'REVISAO', tipo: null,
  linhas: [
    { id: 1, produto_id: 11, codigo: 'A-1', nome: 'Cabo', custo_medio: '100.00', quantidade_sistema: '10', diferenca: '-2', quantidade_contada: '8', observacoes: null, justificacao: null, custo_personalizado: null, custo_unitario: null, valor_diferenca: null },
    { id: 2, produto_id: 12, codigo: 'B-2', nome: 'Disjuntor', custo_medio: '50.00', quantidade_sistema: '5', diferenca: '0', quantidade_contada: '5', observacoes: null, justificacao: null, custo_personalizado: null, custo_unitario: null, valor_diferenca: null },
  ],
};

vi.mock('@/api/cliente', () => ({
  obter: (url: string) => Promise.resolve(url === '/logistica/armazens' ? [{ id: 1, nome: 'Central', predefinido: true }] : url === '/logistica/inventarios/7' ? SESSAO : []),
  obterPagina: () => Promise.resolve({ itens: [SESSAO], paginacao: { total: 1, pagina_atual: 1, por_pagina: 200, ultima_pagina: 1 } }),
  enviar: () => Promise.resolve({ dados: {}, mensagem: 'ok' }),
  descarregar: () => Promise.resolve(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: () => true, menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

import { textoPorReceber } from './ValidarEntradas';
import { EcraInventario } from './comum/Inventario';

describe('Validar Entradas — «Encomendas por Receber» do legado', () => {
  it('«Produto: quantidade» só das linhas com quantidade por receber', () => {
    const l = (nome: string, q: string, r: string | null) => ({ produto: { id: 1, codigo: null, nome }, quantidade: q, quantidade_recebida: r }) as never;
    expect(textoPorReceber([l('Cabo', '10', '4'), l('Fio', '3', '3'), l('Tubo', '2', null)])).toBe('Cabo: 6 · Tubo: 2');
    expect(textoPorReceber([])).toBe('');
  });
});

describe('Revisão e Regularização — folha de trabalho do legado', () => {
  it('com um só lote abre-o logo: selector, resumo e só as linhas com diferença', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={['/r']}>
          <Routes>
            <Route path="/r/*" element={<EcraInventario modo="revisao" />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
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
