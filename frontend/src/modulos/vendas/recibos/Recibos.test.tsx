import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
    if (url === '/vendas/configuracao/retencoes') return Promise.resolve({ tipos: [{ id: 3, codigo: 'IND_SERV', descricao: 'Imposto Industrial — prestação de serviços', percentagem: '6.5000', conta_imposto: null, ativo: true }], conta_imposto_padrao: '341', conta_sugerida: '341' });
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

describe('Novo recibo — retenção na fonte', () => {
  it('interruptor «Aplicar retenção na fonte»: mostra a retenção calculada e o valor a receber (líquido) no resumo', async () => {
    ecra({ liquidar: { cliente_id: CLIENTE.id, venda_id: FACTURA.id } });
    const bloco = await screen.findByTestId('bloco-retencao');
    const resumo = () => within(screen.getByTestId('resumo-recibo')).getByText('Valor a receber (líquido) (Kz)').closest('.ant-statistic')!.textContent ?? '';
    await waitFor(() => expect(resumo()).toContain('1'), ESPERA);   // sem retenção: líquido = total
    expect(within(bloco).queryByLabelText('Percentagem da retenção')).toBeNull();
    fireEvent.click(within(bloco).getByRole('switch', { name: 'Aplicar retenção na fonte' }));
    const pct = await within(bloco).findByLabelText('Percentagem da retenção', {}, ESPERA);
    await waitFor(() => expect((pct as HTMLInputElement).value).toContain('6'), ESPERA);   // percentagem do tipo de arranque (6,5)
    const retido = within(bloco).getByLabelText('Valor retido') as HTMLInputElement;
    await waitFor(() => expect(Number(retido.value.replace(/\s/g, '').replace(',', '.'))).toBe(65), ESPERA);   // 6,5 % de 1000
    expect(resumo().replace(/\s/g, '')).toContain('935');
  });
});
