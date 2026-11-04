import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from 'antd';
import { MemoryRouter } from 'react-router';

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

import { NovoRecibo } from './Recibos';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';

vi.setConfig({ testTimeout: 60_000 });
const ESPERA = { timeout: 15_000 };
const CLIENTE = { id: 7150, nome: 'Construções Alfa, Lda', nif: '5417000123' };
const FACTURA = { id: 55, numero_documento: 'FT 2026/55', data_emissao: '2026-10-01', data_vencimento: null, total_bruto: '1000.00', valor_pendente: '1000.00', cliente_id: 7150, contabilizado: true };

function ecra(state?: unknown) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={[{ pathname: '/', state }]}>
        <App><NovoRecibo /></App>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  obter.mockReset().mockImplementation((url: string) => {
    if (url === `/terceiros/${CLIENTE.id}`) return Promise.resolve(CLIENTE);
    if (url === `/vendas/documentos/${FACTURA.id}`) return Promise.resolve(FACTURA);
    if (url === '/vendas/retencoes/tipos') return Promise.resolve([]);
    return Promise.resolve([]);
  });
  obterPagina.mockReset().mockImplementation((url: string, p: { cliente_id?: number }) =>
    Promise.resolve(url === '/vendas/documentos' && p.cliente_id === CLIENTE.id ? { itens: [FACTURA], total: 1 } : { itens: [], total: 0 }));
  enviar.mockReset().mockResolvedValue({ dados: { id: 1 }, mensagem: 'ok' });
});

describe('Novo recibo — cliente', () => {
  it('vindo do Liquidar: o cliente vem pelo nome (nunca o id), bloqueado, com a factura pré-seleccionada', async () => {
    ecra({ liquidar: { cliente_id: CLIENTE.id, venda_id: FACTURA.id } });
    const campo = await screen.findByTestId('cliente-bloqueado');
    await waitFor(() => expect((campo.querySelector('input') as HTMLInputElement).value).toBe('Construções Alfa, Lda (NIF 5417000123)'), ESPERA);
    expect(screen.queryByText('7150')).toBeNull();
    expect(screen.queryByPlaceholderText('Pesquisar cliente (nome ou NIF)')).toBeNull();
    expect(await screen.findByText('FT 2026/55', {}, ESPERA)).toBeTruthy();
    await waitFor(() => expect(screen.getAllByRole('spinbutton').some((i) => (i as HTMLInputElement).value === '1 000,00' || (i as HTMLInputElement).value === '1000.00' || (i as HTMLInputElement).value === '1000,00')).toBe(true), ESPERA);
  });

  it('vindo do Liquidar sem cliente no estado: obtém-no pela factura', async () => {
    ecra({ liquidar: { cliente_id: null, venda_id: FACTURA.id } });
    const campo = await screen.findByTestId('cliente-bloqueado');
    await waitFor(() => expect((campo.querySelector('input') as HTMLInputElement).value).toContain('Construções Alfa'), ESPERA);
  });

  it('Novo recibo directo: o campo Cliente existe, é obrigatório e não está bloqueado', async () => {
    ecra();
    expect(await screen.findByText('Cliente')).toBeTruthy();
    expect(screen.queryByTestId('cliente-bloqueado')).toBeNull();
    expect(screen.getByText('Cliente').closest('.ant-form-item-label')?.querySelector('.ant-form-item-required')).toBeTruthy();
  });
});

describe('SeletorTerceiro — rótulo do valor pré-seleccionado', () => {
  it('resolve o nome pela ficha quando o cliente não está na lista carregada (não mostra o id)', async () => {
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={cliente}><App><SeletorTerceiro papel="CLIENTE" value={CLIENTE.id} /></App></QueryClientProvider>);
    expect(await screen.findByText('Construções Alfa, Lda (NIF 5417000123)', {}, ESPERA)).toBeTruthy();
    expect(screen.queryByText('7150')).toBeNull();
  });
});
