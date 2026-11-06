import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

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

let permissoes: string[] = [];
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...c: string[]) => c.some((x) => permissoes.includes(x)) }) }));

const imprimirHtml = vi.fn();
vi.mock('@/modulos/pos/comum/impressao', async (original) => ({ ...(await original<typeof import('@/modulos/pos/comum/impressao')>()), imprimirHtml: (h: string) => imprimirHtml(h) }));

import { Balcao } from './Balcao';
import { Senhas } from './Senhas';

function comConsulta(no: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={cliente}>{no}</QueryClientProvider>;
}

beforeEach(() => {
  obter.mockClear();
  enviar.mockClear();
  imprimirHtml.mockClear();
  permissoes = [];
  for (const k of Object.keys(respostas)) delete respostas[k];
  try {
    localStorage.clear();
  } catch {
    /* sem armazenamento */
  }
});

describe('POS de armazém — Atendimento ao Balcão', () => {
  beforeEach(() => {
    respostas['/logistica/armazens'] =[{ id: 5, nome: 'Central', predefinido: true }];
    respostas['/pos/armazem/artigos'] = [
      { produto_id: 1, codigo: 'CAB-01', nome: 'Cabo eléctrico', preco_unitario: '1000.00', quantidade: '12.000', bloqueado: false },
      { produto_id: 2, codigo: 'DIS-02', nome: 'Disjuntor', preco_unitario: '500.00', quantidade: '0.000', bloqueado: false },
    ];
    respostas['/pos/armazem/acerto-stock'] = [
      { produto_id: 1, codigo: 'CAB-01', nome: 'Cabo eléctrico', global: '14.000', armazens: '12.000', local: '12.000', diferenca: '2.000', ajuste: '2.000', limitado: false, fica: '14.000' },
    ];
  });

  it('mostra os cartões, filtra Sem Stock, soma o carrinho e avisa do stock divergente', async () => {
    permissoes = ['pos_armazem_vender', 'pos_armazem_acerto'];
    render(comConsulta(<Balcao armazem={5} aoMudarArmazem={vi.fn()} />));
    expect(await screen.findByText('Cabo eléctrico', {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(screen.getAllByTestId('artigo-pos')).toHaveLength(2);
    expect(screen.getByText('Esgotado')).toBeInTheDocument();
    expect(screen.getAllByText('STOCK DISP.')).toHaveLength(2);
    expect(screen.getByText('Carrinho de Picking vazio.')).toBeInTheDocument();
    expect(await screen.findByText(/1 produto\(s\) têm stock global diferente da soma dos armazéns/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Acertar stock deste armazém/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Acrescentar Disjuntor' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar Cabo eléctrico' }));
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar Cabo eléctrico' }));
    expect(screen.getByTestId('total-picking').textContent).toMatch(/^2[\s.]?000,00 Kz$/);
    expect(screen.queryByText('Carrinho de Picking vazio.')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Sem Stock' }));
    expect(screen.getAllByTestId('artigo-pos')).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: 'Visualização em Lista' }));
    expect(localStorage.getItem('pos_wh_layout_mode')).toBe('lista');
    expect(screen.getByRole('button', { name: /Confirmar Saída e Expedir/ })).toBeInTheDocument();
  });

  it('sem permissões de venda nem de acerto não mostra as acções', async () => {
    permissoes = ['pos_armazem_view'];
    render(comConsulta(<Balcao armazem={5} aoMudarArmazem={vi.fn()} />));
    expect(await screen.findByText(/produto\(s\) têm stock global diferente/, {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Acertar stock deste armazém/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Confirmar Saída e Expedir/ })).toBeNull();
  });
});

describe('POS de armazém — conclusão da saída (F3)', () => {
  it('confirma a saída com cliente: mensagem do legado, voz e talão de levantamento com o n.º da guia e os artigos', async () => {
    permissoes = ['pos_armazem_vender'];
    localStorage.setItem('pos_wh_impressao_auto', 'talao');   // o motor do A4 abre uma iframe: aqui só o talão
    const falas: string[] = [];
    vi.stubGlobal('SpeechSynthesisUtterance', function (this: { text: string }, t: string) { this.text = t; falas.push(t); });
    vi.stubGlobal('speechSynthesis', { speak: vi.fn() });
    Object.defineProperty(window, 'speechSynthesis', { value: { speak: vi.fn() }, configurable: true });
    respostas['/logistica/armazens'] = [{ id: 5, nome: 'Central', predefinido: true }];
    respostas['/pos/armazem/artigos'] = [{ produto_id: 1, codigo: 'CAB-01', nome: 'Cabo eléctrico', preco_unitario: '1000.00', quantidade: '12.000', bloqueado: false }];
    respostas['/pos/armazem/acerto-stock'] = [];
    enviar.mockImplementationOnce(async () => ({
      mensagem: 'Saída concluída.',
      dados: { id: 9, numero_documento: 'GE POS 2026/0009', data: '2026-11-06', tipo: 'VENDA_BALCAO', area_rececao: 'Cliente de balcão', criado_em: '2026-11-06T10:42:00+01:00',
        linhas: [{ id: 1, produto_id: 1, quantidade: '2.000', valor_kz: '1200.00', custo_unitario_kz: '600.00', preco_unitario_kz: '1000.00' }] },
    }));
    render(comConsulta(<Balcao armazem={5} aoMudarArmazem={vi.fn()} />));
    await screen.findByText('Cabo eléctrico', {}, { timeout: 15_000 });
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar Cabo eléctrico' }));
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar Cabo eléctrico' }));
    fireEvent.click(screen.getByRole('button', { name: /Confirmar Saída e Expedir/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Confirmar' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/pos/armazem/vendas', { armazem_id: 5, terceiro_id: undefined, observacoes: undefined, linhas: [{ produto_id: 1, quantidade: 2 }] }));

    expect(await screen.findByText('Saída concluída com sucesso!', {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(screen.getByText(/Guia GE POS 2026\/0009 emitida\./)).toBeInTheDocument();
    expect(falas).toEqual(['Atendimento de Cliente de balcão concluído com sucesso. Guia GE POS 2026/0009 gerada.']);
    await waitFor(() => expect(imprimirHtml).toHaveBeenCalledTimes(1));
    const talao = imprimirHtml.mock.calls[0][0] as string;
    expect(talao).toContain('Documento: GE POS 2026/0009');
    expect(talao).toContain('CAB-01 · Cabo eléctrico');
    expect(talao).toMatch(/2\D000,00 Kz/);
    expect(screen.getByRole('button', { name: /Imprimir Documento A4/ })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Imprimir Talão/ }));
    expect(imprimirHtml).toHaveBeenCalledTimes(2);
    vi.unstubAllGlobals();
  });
});

describe('POS de armazém — Monitor de Chamada de Senhas', () => {
  beforeEach(() => {
    respostas['/pos/armazem/senhas'] = {
      data: '2026-10-08',
      armazem_id: 5,
      actual: { id: 3, codigo: 'P-003', numero: 3, estado: 'CHAMADA', chamadas: 1, chamada_em: '2026-10-08T10:00:00+01:00', criado_em: null },
      fila: [{ id: 4, codigo: 'P-004', numero: 4, estado: 'AGUARDA', chamadas: 0, chamada_em: null, criado_em: null }],
      chamadas: [{ id: 2, codigo: 'P-002', numero: 2, estado: 'CHAMADA', chamadas: 1, chamada_em: null, criado_em: null }],
    };
  });

  it('mostra a senha em chamada, a fila e as últimas; o operador chama a próxima', async () => {
    permissoes = ['pos_armazem_vender'];
    render(comConsulta(<Senhas armazem={5} />));
    expect(await screen.findByTestId('senha-actual', {}, { timeout: 15_000 })).toHaveTextContent('P-003');
    expect(within(screen.getByTestId('fila-senhas')).getByText('P-004')).toBeInTheDocument();
    expect(screen.getByText('P-002')).toBeInTheDocument();
    expect(screen.getByText('SENHA ATUAL EM CHAMADA')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Chamar próxima/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/pos/armazem/senhas/chamar', { armazem_id: 5 }));
    fireEvent.click(screen.getByRole('button', { name: /Tirar Nova Senha/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/pos/armazem/senhas', { armazem_id: 5 }));
  });

  it('só de consulta (monitor público): sem botões de operação', async () => {
    permissoes = ['pos_armazem_view'];
    render(comConsulta(<Senhas armazem={5} />));
    expect(await screen.findByTestId('senha-actual', {}, { timeout: 15_000 })).toHaveTextContent('P-003');
    expect(screen.queryByRole('button', { name: /Tirar Nova Senha/ })).toBeNull();
    expect(screen.getByRole('button', { name: /Voz ligada/ })).toBeInTheDocument();
  });
});
