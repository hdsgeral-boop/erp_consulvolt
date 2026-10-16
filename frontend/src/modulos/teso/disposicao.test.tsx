import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import type { MapaReconciliacao } from './api';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn().mockResolvedValue({ itens: [], paginacao: { pagina_atual: 1, por_pagina: 1, total: 0, ultima_pagina: 1 } }),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: { nome_utilizador: 'admin' } }) }));

import Conferencia, { leituraDiferenca } from './Conferencia';
import { resumoConciliacao, saldoCorridoExtrato } from './Conciliacao';

vi.setConfig({ testTimeout: 120_000 });

const mapa = (p: Partial<MapaReconciliacao> = {}): MapaReconciliacao => ({
  conta: '43.1', data: '2026-10-15', saldo_diario: '1000.00', saldo_banco_esperado: '900.00',
  por_reconciliar_diario: { total: '150.00', linhas: [] }, por_reconciliar_extrato: { total: '50.00', linhas: [] }, ...p,
});

describe('Conciliação — quadro-resumo ERP / Banco / Diferença', () => {
  it('com o saldo do extracto: diferença = saldo ERP − saldo banco', () => {
    const r = resumoConciliacao(mapa({ saldo_extrato: '940.00', diario_debito: '5000', diario_credito: '4000' }));
    expect(r).toMatchObject({ saldoErp: '1000.00', saldoBanco: '940.00', diferenca: '60.00', erpDebito: '5000', bancoDebito: null });
  });
  it('sem o saldo do extracto: diferença dos movimentos por reconciliar (zero quando tudo está conciliado)', () => {
    expect(resumoConciliacao(mapa()).diferenca).toBe('100.00');
    expect(resumoConciliacao(mapa({ por_reconciliar_diario: { total: '0', linhas: [] }, por_reconciliar_extrato: { total: '0', linhas: [] } })).diferenca).toBe('0.00');
  });
  it('saldo corrido do extracto (crédito = entrada), sem as anuladas', () => {
    const s = saldoCorridoExtrato([
      { id: 2, data: '2026-10-02', tipo_dc: 'D', valor: '30', estado: 'PENDENTE' },
      { id: 1, data: '2026-10-01', tipo_dc: 'C', valor: '100', estado: 'CONCILIADO' },
      { id: 3, data: '2026-10-03', tipo_dc: 'C', valor: '999', estado: 'ANULADO' },
    ]);
    expect([s.get(1), s.get(2), s.get(3)]).toEqual(['100.00', '70.00', '70.00']);
  });
});

describe('Conferência de caixa — no próprio ecrã, com sub-separadores', () => {
  it('cores e texto da diferença do legado', () => {
    expect(leituraDiferenca('0')).toEqual({ cor: '#10b981', texto: 'Sem desvio' });
    expect(leituraDiferenca('5')).toMatchObject({ texto: 'Sobra' });
    expect(leituraDiferenca('-5')).toMatchObject({ texto: 'Quebra', cor: '#ef4444' });
  });

  beforeEach(() => {
    obter.mockReset().mockImplementation((url: string) => {
      if (url === '/tesouraria/conferencias') return Promise.resolve([{ id: 4, codigo_conta: '45.1', nome_conta: 'Caixa sede', data_conferencia: '2026-10-14', nome_operador: 'ana', denominacoes: null,
        total_fisico: '100.00', total_sistema: '120.00', saldo_externo: null, diferenca: '-20.00', justificacao: null, estado: 'FINALIZADO', referencia_lancamento: null, conta_regularizacao: null, nome_gerente: null, assinado_gerente_em: null }]);
      return Promise.resolve([]);
    });
    enviar.mockReset().mockResolvedValue({ dados: {}, mensagem: 'ok' });
  });

  it('«Nova Conferência» com a contagem por denominação na página; «Histórico» com a contagem e as colunas do legado', async () => {
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={cliente}><MemoryRouter><Conferencia /></MemoryRouter></QueryClientProvider>);
    expect(await screen.findByText('1. Identificação')).toBeInTheDocument();
    expect(screen.getByText('2. Contagem Física')).toBeInTheDocument();
    expect(screen.getByText('3. Comparação')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Quantidade Nota 5 000'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Quantidade Moeda 10'), { target: { value: '3' } });
    await waitFor(() => expect(screen.getAllByText(/10\s?030,00/).length).toBeGreaterThan(0));
    expect(screen.getByText('TOTAL FÍSICO CONFERIDO')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Histórico'));
    expect((await screen.findAllByText('Data/Hora')).length).toBeGreaterThan(0);
    expect(screen.getAllByText('Caixa sede').length).toBeGreaterThan(0);
    expect(screen.getAllByText('-20,00').some((e) => e.style.color === 'rgb(239, 68, 68)')).toBe(true);
  });
});
