import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';

vi.setConfig({ testTimeout: 60_000 });

const respostas: Record<string, unknown> = {};
const obter = vi.fn(async (url: string) => respostas[url] ?? []);
const enviar = vi.fn(async (_m: string, _u: string, _d?: unknown) => ({ dados: {}, mensagem: 'Feito.' }));
vi.mock('@/api/cliente', () => ({
  obter: (url: string) => obter(url),
  obterAutomatico: (url: string) => obter(url),
  obterPagina: vi.fn(async () => ({ dados: [], meta: { total: 0 } })),
  enviar: (m: string, u: string, d?: unknown) => enviar(m, u, d),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, utilizador: { nome_utilizador: 'ana', nome_completo: 'Ana Silva' } }) }));
const imprimirHtml = vi.fn();
vi.mock('@/modulos/pos/comum/impressao', async (original) => ({ ...(await original<typeof import('@/modulos/pos/comum/impressao')>()), imprimirHtml: (h: string) => imprimirHtml(h) }));

import PosArmazem from './PosArmazem';

/** Fila de picking: a encomenda NE mostra o valor total correcto (total_bruto), não o campo `total_value` inexistente do legado (BUG-03). */
describe('POS de armazém — Fila de Picking e expedição (F3)', () => {
  beforeEach(() => {
    obter.mockClear();
    enviar.mockClear();
    imprimirHtml.mockClear();
    localStorage.clear();
    localStorage.setItem('pos_wh_impressao_auto', 'talao');
    respostas['/logistica/armazens'] = [{ id: 5, nome: 'Central', predefinido: true }];
    respostas['/pos/armazem/picking'] = [{ id: 4, numero_documento: 'NE A/2026/8', data_emissao: '2026-11-05', cliente_id: 2, cliente: 'Beta, SA', total_bruto: '6270.00', estado: 'PENDENTE', estado_picking: 'POR_EXPEDIR', linhas_por_expedir: 1 }];
    respostas['/pos/armazem/picking/4'] = {
      encomenda: { id: 4, numero_documento: 'NE A/2026/8', cliente_id: 2, estado: 'PENDENTE', total_bruto: '6270.00' },
      armazem_id: 5,
      pode_expedir: true,
      linhas: [{ item_id: 11, produto_id: 1, codigo: 'CAB-01', nome: 'Cabo eléctrico', quantidade: '5.000', quantidade_entregue: '0.000', por_expedir: '5.000', movimenta_stock: true, stock_armazem: '10.000', disponivel: true }],
    };
  });

  it('mostra o valor da encomenda; ao expedir fala a conclusão e imprime o talão com a guia de remessa', async () => {
    const falas: string[] = [];
    vi.stubGlobal('SpeechSynthesisUtterance', function (this: { text: string }, t: string) { this.text = t; falas.push(t); });
    Object.defineProperty(window, 'speechSynthesis', { value: { speak: vi.fn() }, configurable: true });
    enviar.mockImplementationOnce(async () => ({
      mensagem: 'Picking concluído.',
      dados: { numero_documento: 'GR A/2026/3', data_emissao: '2026-11-06', criado_em: '2026-11-06T15:05:00+01:00', itens_venda: [{ produto_id: 1, descricao: 'Cabo', quantidade: '5.000', preco_unitario: '1000.00' }] },
    }));
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={cliente}><MemoryRouter initialEntries={['/m/stock/pos_armazem?separador=picking']}><PosArmazem /></MemoryRouter></QueryClientProvider>);

    expect(await screen.findByText('NE A/2026/8', {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(screen.getAllByText(/6\D270,00/).length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: /Iniciar Picking/ }));
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Recolhido: Cabo eléctrico' }));
    fireEvent.click(screen.getByRole('button', { name: /Finalizar Picking/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Expedir' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/pos/armazem/picking/4/expedir', { armazem_id: 5, recolhidos: [11], observacoes: undefined }));

    expect(await screen.findByText('Picking e expedição validados com sucesso!', {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(falas).toEqual(['Picking concluído e expedido para Beta, SA.']);
    await waitFor(() => expect(imprimirHtml).toHaveBeenCalledTimes(1));
    const talao = imprimirHtml.mock.calls[0][0] as string;
    for (const t of ['Documento: GR A/2026/3', 'Encomenda: NE A/2026/8', 'Destinatário: Beta, SA', 'CAB-01 · Cabo eléctrico']) expect(talao).toContain(t);
    expect(talao).toMatch(/5\D000,00 Kz/);
    vi.unstubAllGlobals();
  });

  it('sem armazéns: aviso e botão com os textos do legado (com acentos)', async () => {
    respostas['/logistica/armazens'] = [];
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={cliente}><MemoryRouter initialEntries={['/m/stock/pos_armazem']}><PosArmazem /></MemoryRouter></QueryClientProvider>);
    expect(await screen.findByText('Nenhum Armazém Configurado', {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(screen.getByText('Por favor, configure pelo menos um Armazém antes de aceder ao POS.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Configurar Armazéns' })).toBeInTheDocument();
  });
});
