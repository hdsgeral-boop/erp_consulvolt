import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: () => Promise.resolve({ itens: [{ id: 1, nome_completo: 'Ana Silva', nif: '500000001', estado: 'ACTIVO' }], paginacao: { ultima_pagina: 1 } }),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null, menu: [] }) }));

import Contratos from '../Contratos';

vi.setConfig({ testTimeout: 900_000 });

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  // preferências: o servidor devolve o que foi gravado (como o PUT /sistema/preferencias/interface/{nome})
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    if (url === '/rh/infotipos') return Promise.resolve([{ id: 7, nome: 'Salário base', tipo: 'VENCIMENTO' }, { id: 8, nome: 'Subsídio de alimentação', tipo: 'VENCIMENTO' }, { id: 9, nome: 'Adiantamento', tipo: 'DESCONTO' }]);
    return Promise.resolve([]);
  });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('Contratos › «Novo contrato» (Grade | Linhas)', () => {
  it('uma coluna de valor mensal por rubrica de vencimento; cada linha é um POST /rh/contratos com as remunerações', async () => {
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(<QueryClientProvider client={cliente}><MemoryRouter><Contratos /></MemoryRouter></QueryClientProvider>);
    fireEvent.click(await screen.findByRole('button', { name: /Novo Contrato/ }));
    // grade: secções recolhíveis (o horário fechado num contrato novo)
    expect(await screen.findByRole('button', { name: /Horário de trabalho/ })).toHaveAttribute('aria-expanded', 'false');
    expect(screen.getByRole('button', { name: /Remunerações mensais/ })).toHaveAttribute('aria-expanded', 'true');
    fireEvent.click(screen.getByRole('button', { name: /Linhas/ }));
    await screen.findByTestId('painel-linhas');
    expect(screen.getByLabelText('Salário base (mensal), linha 1')).toBeInTheDocument();
    expect(screen.queryByLabelText('Adiantamento (mensal), linha 1')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(await screen.findByLabelText('Linhas a colar'), { target: { value: 'Ana Silva\t300 000,00\t45000\t22\t8\t01/01/2026' } });
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
    await waitFor(() => expect(screen.getAllByTestId('linha-formulario')).toHaveLength(1));
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    await waitFor(() => expect(enviar.mock.calls.filter((c) => c[1] === '/rh/contratos')).toHaveLength(1));
    const [, , dados] = enviar.mock.calls.find((c) => c[1] === '/rh/contratos')!;
    expect(dados).toMatchObject({
      colaborador_id: 1, data_inicio: '2026-01-01', data_fim: null, dias_contrato_mes: 22, horas_por_dia: 8, estado: 'ACTIVO', codigo_moeda: 'AOA', horario: null,
      remuneracoes: [{ infotipo_salarial_id: 7, valor_mes: 300000 }, { infotipo_salarial_id: 8, valor_mes: 45000 }],
    });
  });
});
