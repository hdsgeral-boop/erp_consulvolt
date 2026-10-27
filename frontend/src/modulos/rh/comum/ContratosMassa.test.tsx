import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { Infotipo } from '../api';
import { ContratosMassa } from './ContratosMassa';

const obter = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: vi.fn(() => Promise.resolve({ dados: {}, mensagem: 'ok' })),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { ultima_pagina: 1 } }),
}));

const INFOTIPOS: Infotipo[] = [
  { id: 1, nome: 'Vencimento Base', tipo: 'VENCIMENTO', sujeito_inss: true, irt: 'true', base_horaria: false, calculo_horas: '' },
  { id: 2, nome: 'Subsídio de Alimentação', tipo: 'VENCIMENTO', sujeito_inss: false, irt: 'false', base_horaria: false, calculo_horas: '' },
];

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  obter.mockReset().mockImplementation((url: string) => (url === '/rh/infotipos' ? Promise.resolve(INFOTIPOS) : Promise.resolve([])));
  return render(<QueryClientProvider client={cliente}><ContratosMassa aberto aoFechar={() => {}} /></QueryClientProvider>);
}

/** Espera as rubricas de vencimento estarem na cache antes de colar (abre e fecha o seletor da 1.ª rubrica,
 * o 2.º combobox — o 1.º é o dos colaboradores). */
async function aguardarInfotiposCarregados() {
  const campo = screen.getAllByRole('combobox')[1];
  fireEvent.mouseDown(campo);
  await screen.findByText('Vencimento Base');
  fireEvent.keyDown(campo, { key: 'Escape' });
}

describe('ContratosMassa — Colar do Excel nas rubricas', () => {
  it('botão «Colar do Excel»: acrescenta rubricas no fim, lendo a rubrica (pelo nome) e o valor mensal', async () => {
    montar();
    await aguardarInfotiposCarregados();
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(await screen.findByLabelText('Linhas a colar'), { target: { value: 'Vencimento Base\t150 000\nSubsídio de Alimentação\t25 000' } });
    fireEvent.click(screen.getByRole('button', { name: /Acrescentar linhas/ }));
    // a linha inicial em branco fica e as 2 coladas são acrescentadas no fim
    await waitFor(() => expect(screen.getAllByRole('spinbutton')).toHaveLength(3));
    // precisão 2 do InputNumber do valor mensal: valores formatados com vírgula
    expect(screen.getAllByRole('spinbutton').map((i) => (i as HTMLInputElement).value)).toEqual(['', '150000,00', '25000,00']);
  }, 60_000);

  it('colagem directa numa célula: preenche a partir da linha/coluna clicada', async () => {
    montar();
    await aguardarInfotiposCarregados();
    const celulaRubrica = document.querySelector('[data-colar-linha="0"] [data-colar-coluna="0"] input') as HTMLElement;
    fireEvent.paste(celulaRubrica, { clipboardData: { getData: () => 'Vencimento Base\t200000' } });
    await waitFor(() => expect((screen.getByRole('spinbutton') as HTMLInputElement).value).toBe('200000,00'));
  }, 60_000);
});
