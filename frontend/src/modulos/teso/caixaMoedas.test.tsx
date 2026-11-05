import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn().mockResolvedValue({ itens: [], total: 0 }),
  descarregar: vi.fn(),
}));
const pode = vi.fn(() => true);
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => (pode as unknown as (...x: string[]) => boolean)(...p), empresa: null, utilizador: { nome_utilizador: 'admin' } }) }));

import { escolhaValida, ModalMoedasCaixa, SaldosPorMoeda } from './caixa/MoedasCaixa';

vi.setConfig({ testTimeout: 120_000 });

function ecra(no: React.ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={cliente}>{no}</QueryClientProvider>);
}

const ESTADO = { activo: true, pode_gerir: true, pode_liquidar: true, habilitadas: ['USD'], tolerancia_pct: '5',
  moedas: [{ codigo: 'EUR', nome: 'Euro', simbolo: '€' }, { codigo: 'USD', nome: 'Dólar', simbolo: '$' }] };

describe('caixa em moeda estrangeira', () => {
  beforeEach(() => {
    obter.mockReset();
    enviar.mockReset();
    pode.mockReturnValue(true);
  });

  it('só se gravam moedas existentes, sem repetidas e ordenadas', () => {
    expect(escolhaValida(['USD', 'EUR', 'USD', 'XXX'], ['EUR', 'USD'])).toEqual(['EUR', 'USD']);
    expect(escolhaValida([], ['EUR', 'USD'])).toEqual([]);
  });

  it('o ecrã de moedas mostra as habilitadas e grava a escolha', async () => {
    obter.mockResolvedValue(ESTADO);
    enviar.mockResolvedValue({ dados: ESTADO, mensagem: 'ok' });
    ecra(<ModalMoedasCaixa aberto aoFechar={() => undefined} />);
    const euro = await screen.findByLabelText(/EUR/);
    const dolar = await screen.findByLabelText(/USD/);
    await waitFor(() => expect((dolar as HTMLInputElement).checked).toBe(true));
    expect((euro as HTMLInputElement).checked).toBe(false);
    fireEvent.click(euro);
    fireEvent.click(screen.getByText('Gravar'));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('put', '/tesouraria/caixa/moedas', { moedas: ['EUR', 'USD'] }));
  });

  it('sem a permissão de gerir não há botão de gravar', async () => {
    pode.mockReturnValue(false);
    obter.mockResolvedValue({ ...ESTADO, pode_gerir: false });
    ecra(<ModalMoedasCaixa aberto aoFechar={() => undefined} />);
    await screen.findByLabelText(/USD/);
    expect(screen.getByText('Gravar').closest('button')?.style.display).toBe('none');
  });

  it('o saldo por moeda só aparece havendo caixas em moeda estrangeira', async () => {
    obter.mockResolvedValue([{ conta: '45.1', descricao: 'Caixa sede', moeda: 'AOA', saldo_moeda: null, saldo_kz: '1000.00' }]);
    const a = ecra(<SaldosPorMoeda />);
    await waitFor(() => expect(obter).toHaveBeenCalled());
    expect(screen.queryByText('Saldo das caixas por moeda')).toBeNull();
    a.unmount();
    obter.mockResolvedValue([{ conta: '45.1', descricao: 'Caixa sede', moeda: 'AOA', saldo_moeda: null, saldo_kz: '1000.00' },
      { conta: '45.2', descricao: 'Caixa USD', moeda: 'USD', saldo_moeda: '10.00', saldo_kz: '10000.00' }]);
    ecra(<SaldosPorMoeda />);
    expect(await screen.findByText('Saldo das caixas por moeda')).toBeTruthy();
    expect(screen.getAllByText(/US\$/).length).toBeGreaterThan(0);
  });
});
