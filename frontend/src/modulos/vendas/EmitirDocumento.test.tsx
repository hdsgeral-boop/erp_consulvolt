import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from 'antd';
import { MemoryRouter } from 'react-router-dom';

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
// ecrã largo (no jsdom o Grid do AntD não tem largura e daria «telemóvel», com as colunas empilhadas)
vi.mock('@/componentes/responsivo', async (original) => ({
  ...(await original<typeof import('@/componentes/responsivo')>()),
  useEcra: () => ({ sm: true, md: true, lg: true, xl: true, xxl: false, actual: 'xl', telemovel: false, tablet: false, pequeno: false }),
}));

import { EmitirDocumento } from './EmitirDocumento';
import type { ProdutoCatalogo } from './api';

vi.setConfig({ testTimeout: 600_000 });
const ESPERA = { timeout: 30_000 };

const PRODUTOS: ProdutoCatalogo[] = [
  { id: 1, codigo: 'P001', nome: 'Cimento', preco_unitario: '1000', taxa_imposto: '14', movimenta_stock: true },
  { id: 2, codigo: 'P002', nome: 'Areia', preco_unitario: '500', taxa_imposto: '14', movimenta_stock: true },
];

function ecra() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={['/']}>
        <App><EmitirDocumento /></App>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  obter.mockReset().mockImplementation((url: string) => {
    if (url === '/logistica/produtos/catalogo') return Promise.resolve(PRODUTOS);
    if (url === '/vendas/clientes/pesquisa') return Promise.resolve({ itens: [] });
    return Promise.resolve([]);
  });
  obterPagina.mockReset().mockResolvedValue({ itens: [], total: 0, pagina: 1, por_pagina: 25 });
  enviar.mockReset().mockResolvedValue({ dados: {}, mensagem: 'ok' });
});

/** Célula (linha/coluna) pelos atributos `data-colar-*` (ficam em elementos diferentes — ver colagemGrade.ts). */
const celula = (container: HTMLElement, linha: number, coluna: number) =>
  container.querySelector(`[data-colar-linha="${linha}"] [data-colar-coluna="${coluna}"]`) as HTMLElement;

const numeroDe = (el: Element | null) => Number((el as HTMLInputElement | null)?.value.replace(',', '.'));
const totalEstimativa = () => screen.getByText('Total (estimativa)').closest('.ant-statistic')!.querySelector('.ant-statistic-content-value')!.textContent;

describe('EmitirDocumento — Colar do Excel (itens da venda)', () => {
  it('botão «Colar do Excel»: acrescenta linhas no fim, resolvendo o produto pelo código e sugerindo o preço da ficha quando a colagem o não traz', async () => {
    const { container } = ecra();

    // espera o catálogo carregar antes de colar (confirma-se abrindo o selector de produto da 1.ª linha)
    const comboProduto = celula(container, 0, 0).querySelector('input') as HTMLElement;
    fireEvent.mouseDown(comboProduto);
    await screen.findByText('P001 — Cimento', {}, ESPERA);
    fireEvent.keyDown(comboProduto, { key: 'Escape', code: 'Escape' });

    const totalAntes = totalEstimativa();

    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(await screen.findByLabelText('Linhas a colar', {}, ESPERA), {
      target: { value: 'P001\tSaco 50kg\t3\t900\tEntrega rápida\nP002\t\t2\t\t' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));

    // linha inicial (em branco) + as 2 coladas
    expect(container.querySelectorAll('[data-colar-linha]').length).toBe(3);

    expect(celula(container, 1, 0).querySelector('.ant-select-selection-item')?.textContent).toBe('P001 — Cimento');
    expect((celula(container, 1, 1).querySelector('input') as HTMLInputElement).value).toBe('Saco 50kg');
    expect(numeroDe(celula(container, 1, 2).querySelector('input'))).toBe(3);
    expect(numeroDe(screen.getByLabelText('Preço da linha 2'))).toBe(900);
    expect(screen.getByLabelText('Observação do artigo da linha 2')).toHaveValue('Entrega rápida');

    // 2.ª linha colada: sem descrição nem preço — ficam o nome e o preço da ficha do produto (como ao escolher no selector)
    expect(celula(container, 2, 0).querySelector('.ant-select-selection-item')?.textContent).toBe('P002 — Areia');
    expect((celula(container, 2, 1).querySelector('input') as HTMLInputElement).value).toBe('Areia');
    expect(numeroDe(celula(container, 2, 2).querySelector('input'))).toBe(2);
    expect(numeroDe(screen.getByLabelText('Preço da linha 3'))).toBe(500);

    // os totais recalculam como ao preencher à mão
    expect(totalEstimativa()).not.toBe(totalAntes);
  });

  it('colagem directa numa célula: preenche a partir da linha/coluna clicada, cria linhas em falta e actualiza os totais', async () => {
    const { container } = ecra();
    const totalAntes = totalEstimativa();

    // cola a partir da coluna «Quantidade» (índice 2): não toca no produto nem na descrição
    const celulaQuantidade = celula(container, 0, 2).querySelector('input') as HTMLElement;
    fireEvent.paste(celulaQuantidade, { clipboardData: { getData: () => '6\t1100\tPrioritário\n2\t400\t' } });

    // linha inicial + 1 criada pela colagem
    expect(container.querySelectorAll('[data-colar-linha]').length).toBe(2);

    expect(numeroDe(celula(container, 0, 2).querySelector('input'))).toBe(6);
    expect(numeroDe(screen.getByLabelText('Preço da linha 1'))).toBe(1100);
    expect(screen.getByLabelText('Observação do artigo da linha 1')).toHaveValue('Prioritário');

    expect(numeroDe(celula(container, 1, 2).querySelector('input'))).toBe(2);
    expect(numeroDe(screen.getByLabelText('Preço da linha 2'))).toBe(400);

    expect(totalEstimativa()).not.toBe(totalAntes);
  });
});
