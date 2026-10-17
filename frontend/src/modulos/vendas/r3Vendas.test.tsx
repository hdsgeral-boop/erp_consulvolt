import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from 'antd';
import { useState, type ReactNode } from 'react';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  obterPagina: vi.fn(),
  enviar: (...a: unknown[]) => enviar(...a),
  descarregar: vi.fn(),
}));
let permissoes = new Set(['vendas_fat_emitir', 'vendas_clientes_gerir']);
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), empresa: null }) }));

import { SeletorClienteVenda } from './comum/SeletorClienteVenda';
import { textoContagem } from './comum/BarraVendas';
import {
  ATRASO_PESQUISA_MS, contaClienteValida, juntarClientes, notaCliente, precisaPapelCliente, rotuloCliente, type ClienteResumo,
} from './comum/clientes';

vi.setConfig({ testTimeout: 600_000 });
const ESPERA = { timeout: 30_000 };

const CLIENTE: ClienteResumo = { id: 1, nome: 'Cliente Alfa ', nif: '5000000011', tipo: 'CLIENTE', codigo_moeda: 'USD', e_cliente: true, conta_cliente: '311' };
const FORNECEDOR: ClienteResumo = { id: 2, nome: 'Fornecedor Alfa', nif: null, tipo: 'FORNECEDOR', codigo_moeda: 'AOA', e_cliente: false, conta_cliente: null };

describe('regras do seletor de clientes', () => {
  it('rótulo como o seletor anterior e nota para quem ainda não é cliente', () => {
    expect(rotuloCliente(CLIENTE)).toBe('Cliente Alfa (NIF 5000000011)');
    expect(rotuloCliente(FORNECEDOR)).toBe('Fornecedor Alfa');
    expect(notaCliente(CLIENTE)).toBeNull();
    expect(notaCliente(FORNECEDOR)).toBe('Fornecedor — passa a cliente');
    expect(precisaPapelCliente(FORNECEDOR)).toBe(true);
    expect(precisaPapelCliente(CLIENTE)).toBe(false);
    expect(precisaPapelCliente(undefined)).toBe(false);
  });

  it('conta de cliente só da classe 31 e listas sem repetidos', () => {
    expect(contaClienteValida('3112')).toBe(true);
    expect(contaClienteValida('31.1.2')).toBe(true);
    expect(contaClienteValida('31')).toBe(false);
    expect(contaClienteValida('3211')).toBe(false);
    expect(contaClienteValida('31a')).toBe(false);
    expect(contaClienteValida(undefined)).toBe(false);
    expect(juntarClientes([CLIENTE], [null, { ...CLIENTE, nome: 'outro' }, FORNECEDOR]).map((c) => [c.id, c.nome])).toEqual([[1, 'Cliente Alfa '], [2, 'Fornecedor Alfa']]);
  });
});

function Ecra({ aoMudar, aoEscolher }: { aoMudar: (v?: number) => void; aoEscolher: (c?: ClienteResumo) => void }) {
  const [v, setV] = useState<number>();
  return <SeletorClienteVenda id="cliente_id" value={v} onChange={(x) => { setV(x); aoMudar(x); }} onEscolher={aoEscolher} />;
}

function montar(no: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}><App>{no}</App></QueryClientProvider>);
}

describe('SeletorClienteVenda', () => {
  beforeEach(() => {
    permissoes = new Set(['vendas_fat_emitir', 'vendas_clientes_gerir']);
    obter.mockReset().mockImplementation((url: string, p?: { q?: string }) =>
      Promise.resolve(url === '/vendas/clientes/pesquisa' ? { itens: p?.q ? [CLIENTE, FORNECEDOR] : [CLIENTE], conta_cliente_sugerida: '311' } : []));
    enviar.mockReset().mockResolvedValue({ dados: { ...FORNECEDOR, tipo: 'CLIENTE_FORNECEDOR', e_cliente: true, conta_cliente: '3112' }, mensagem: 'A entidade passou a ser cliente.' });
  });

  it('pesquisa no servidor só depois de parar de escrever, com limite', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      montar(<Ecra aoMudar={vi.fn()} aoEscolher={vi.fn()} />);
      await waitFor(() => expect(obter).toHaveBeenCalledWith('/vendas/clientes/pesquisa', { q: undefined, limite: 20 }), ESPERA);
      const campo = screen.getByRole('combobox');
      fireEvent.mouseDown(campo);
      fireEvent.change(campo, { target: { value: 'a' } });
      fireEvent.change(campo, { target: { value: 'al' } });
      fireEvent.change(campo, { target: { value: 'alf' } });
      await act(async () => { vi.advanceTimersByTime(ATRASO_PESQUISA_MS + 50); });
      await waitFor(() => expect(obter).toHaveBeenCalledWith('/vendas/clientes/pesquisa', { q: 'alf', limite: 20 }), ESPERA);
      // as letras intermédias não chegaram ao servidor
      expect(obter.mock.calls.filter((c) => (c[1] as { q?: string })?.q === 'a' || (c[1] as { q?: string })?.q === 'al')).toHaveLength(0);
    } finally {
      vi.useRealTimers();
    }
  });

  it('escolher um cliente devolve a ficha (moeda) sem diálogo', async () => {
    const aoMudar = vi.fn();
    const aoEscolher = vi.fn();
    montar(<Ecra aoMudar={aoMudar} aoEscolher={aoEscolher} />);
    fireEvent.mouseDown(screen.getByRole('combobox'));
    fireEvent.click(await screen.findByText('Cliente Alfa (NIF 5000000011)', {}, ESPERA));
    expect(aoMudar).toHaveBeenCalledWith(1);
    expect(aoEscolher).toHaveBeenCalledWith(expect.objectContaining({ id: 1, codigo_moeda: 'USD' }));
    expect(screen.queryByText('Usar como cliente')).toBeNull();
  });

  it('fornecedor passa a cliente com a conta da classe 31 e fica escolhido', async () => {
    const aoMudar = vi.fn();
    montar(<Ecra aoMudar={aoMudar} aoEscolher={vi.fn()} />);
    const campo = screen.getByRole('combobox');
    fireEvent.mouseDown(campo);
    fireEvent.change(campo, { target: { value: 'alfa' } });
    expect(await screen.findByText('Fornecedor — passa a cliente', {}, ESPERA)).toBeTruthy();
    fireEvent.click(screen.getByText('Fornecedor Alfa'));
    expect(await screen.findByText('Usar como cliente', {}, ESPERA)).toBeTruthy();
    expect(aoMudar).not.toHaveBeenCalled();   // ainda não é cliente: o valor não muda

    // a conta sugerida (das configurações) vem preenchida; o utilizador troca-a pela sua
    const conta = await screen.findByDisplayValue('311', {}, ESPERA);
    fireEvent.change(conta, { target: { value: '3112' } });
    fireEvent.click(screen.getByRole('button', { name: 'Tornar cliente e escolher' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/vendas/clientes/2/papel-cliente', { conta_cliente: '3112' }), ESPERA);
    await waitFor(() => expect(aoMudar).toHaveBeenCalledWith(2), ESPERA);
  });

  it('sem permissão de gerir clientes o diálogo explica e não deixa confirmar', async () => {
    permissoes = new Set(['vendas_fat_emitir']);
    montar(<Ecra aoMudar={vi.fn()} aoEscolher={vi.fn()} />);
    const campo = screen.getByRole('combobox');
    fireEvent.mouseDown(campo);
    fireEvent.change(campo, { target: { value: 'alfa' } });
    fireEvent.click(await screen.findByText('Fornecedor Alfa', {}, ESPERA));
    expect(await screen.findByText(/Não tem a permissão «Gerir clientes»/, {}, ESPERA)).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Tornar cliente e escolher' }) as HTMLButtonElement).disabled).toBe(true);
    expect(enviar).not.toHaveBeenCalled();
  });
});

describe('distintivos da Facturação', () => {
  it('mostra o n.º de cada subseparador (0 incluído) e nada enquanto não há contagens', () => {
    const c = { orcamentos: 22, encomendas: 0, faturas: 36, guias: 8, recibos: 4 };
    expect(textoContagem(c, 'faturas')).toBe('36');
    expect(textoContagem(c, 'encomendas')).toBe('0');
    expect(textoContagem(undefined, 'faturas')).toBe('');
    expect(textoContagem({}, 'recibos')).toBe('');
  });
});
