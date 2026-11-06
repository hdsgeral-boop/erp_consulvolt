import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { htmlRelatorioSessao, PREFERENCIAS_PADRAO } from '../comum/impressao';
import type { RelatorioX, Terminal } from '../comum/tipos';

const { obter } = vi.hoisted(() => ({ obter: vi.fn() }));
vi.mock('@/api/cliente', async (original) => ({ ...(await original<typeof import('@/api/cliente')>()), obter, enviar: vi.fn() }));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: (...c: string[]) => c.some((x) => ['pos_movimento_caixa', 'vendas_fat_emitir'].includes(x)), empresa: { id: 1, nome: 'E' }, utilizador: null }),
}));
vi.mock('@/sessao/identidade', () => ({ useIdentidade: () => ({ data: null }) }));

import { MovimentosCaixaModal } from './MovimentosCaixaModal';
import { VendasSessaoModal } from './VendasSessaoModal';

const envolver = (n: React.ReactNode) => (
  <MemoryRouter>
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{n}</QueryClientProvider>
  </MemoryRouter>
);

beforeEach(() => obter.mockReset());

describe('F1b — relatório X com suprimentos e sangrias', () => {
  it('mostra as duas linhas só quando há movimentos', () => {
    const x = { sessao: { id: 1, codigo_sessao: 'S1', codigo_terminal: 'T1', nome_terminal: 'C', estado: 'ABERTA', aberto_em: '2026-10-01T08:00:00Z', fundo_maneio_abertura: '5000.00', nome_operador: 'Op' },
      numero_vendas: 1, total_vendas: '1140.00', totais_por_metodo: [], transferencias: [], vendas_numerario: '1140.00', numerario_esperado: '5140.00',
      suprimentos: '2000.00', sangrias: '3000.00', emitido_em: '2026-10-01T09:00:00Z' } as unknown as RelatorioX;
    const html = htmlRelatorioSessao(x, { empresa: 'E' }, PREFERENCIAS_PADRAO);
    expect(html).toMatch(/Suprimentos<\/td><td class="direita">2\s?000,00/);
    expect(html).toMatch(/Sangrias<\/td><td class="direita">-3\s?000,00/);
    const sem = htmlRelatorioSessao({ ...x, suprimentos: '0.00', sangrias: '0.00' }, { empresa: 'E' }, PREFERENCIAS_PADRAO);
    expect(sem).not.toContain('Sangrias');
  });
});

describe('F1b — modais', () => {
  it('movimentos: lista, totais e formulário com a permissão', async () => {
    obter.mockResolvedValue({ suprimentos: '2000.00', sangrias: '3000.00', movimentos: [{ id: 1, tipo: 'SANGRIA', valor: '3000.00', motivo: 'Depósito no cofre', estado: 'ATIVO', nome_operador: 'Ana', criado_em: '2026-10-01T10:00:00Z', motivo_anulacao: null }] });
    render(envolver(<MovimentosCaixaModal sessaoId={5} aberto aoFechar={() => undefined} />));
    expect(await screen.findByText('Depósito no cofre')).toBeTruthy();
    expect(screen.getByLabelText('Motivo')).toBeTruthy();
    expect(screen.getByText('Anular')).toBeTruthy();
    expect(obter).toHaveBeenCalledWith('/pos/sessoes/5/movimentos');
  });

  it('vendas da sessão: «Emitir NC» desactivado quando o servidor bloqueia', async () => {
    obter.mockResolvedValue([
      { id: 9, numero_documento: 'FR T012026/1', tipo_documento: 'FR', estado: 'PAGO', criado_em: '2026-10-01T10:00:00Z', cliente_id: 1, cliente_nome: 'Consumidor Final', total_bruto: '1140.00',
        pos_pagamentos: [{ nome: 'Numerário', tipo: 'NUMERARIO', valor: '1140.00' }], pos_operador: 'Ana', notas_credito: [], saldo_creditavel: '1140.00', nc_permitida: false,
        nc_motivo_bloqueio: 'A sessão já está integrada na contabilidade' },
    ]);
    render(envolver(<VendasSessaoModal sessaoId={5} terminal={{ id: 1, codigo: 'T01', nome: 'Caixa' } as Terminal} aberto aoFechar={() => undefined} />));
    expect(await screen.findByText('FR T012026/1')).toBeTruthy();
    expect((screen.getByText('Emitir NC').closest('button') as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByText('Reimprimir').closest('button') as HTMLButtonElement).disabled).toBe(false);
  });
});
