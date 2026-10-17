import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

/**
 * Pontos abertos do RH alinhados com o antigo: IBAN embutido e só com IBAN por omissão (app_v2.js:9963/:10003),
 * motivo da suspensão e rubricas em linhas nos contratos (:4425-4447), ano em lista nas férias (ferias.js:163),
 * mês dentro dos separadores da efectividade (assiduidade_ui.js:485), «Por decidir» e «Rever» nos pedidos do portal.
 */
const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: () => Promise.resolve({
    itens: [{ id: 1, nome_completo: 'Ana Fictícia', nif: '500000001', estado: 'ACTIVO' }, { id: 2, nome_completo: 'Bruno Fictício', nif: '500000002', estado: 'ACTIVO' }],
    paginacao: { ultima_pagina: 1 },
  }),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null, menu: [] }) }));
vi.mock('@/modulos/contab/comum/Seletores', () => ({ SeletorConta: () => null }));

import Bancario, { filtrarIbans, type Linha } from './Bancario';
import Contratos from './Contratos';
import PortalGestao, { POR_DECIDIR } from './PortalGestao';
import Assiduidade from './Assiduidade';
import { detalheEstadoContrato } from './contratos/apresentacao';
import { anosDisponiveis } from './ferias/regrasFerias';
import type { Colaborador, CoordenadaBancaria } from './api';
import { ibanValido } from './comum/regras';

/** IBAN angolano válido (dígitos de controlo calculados) a partir de 21 dígitos fictícios. */
function ibanAo(nib: string): string {
  for (let cc = 0; cc < 100; cc += 1) {
    const i = `AO${String(cc).padStart(2, '0')}${nib}`;
    if (ibanValido(i)) return i;
  }
  throw new Error('sem IBAN válido');
}
const IBAN = ibanAo('000600000123456789012');

vi.setConfig({ testTimeout: 300_000 });

let respostas: Record<string, unknown> = {};

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  respostas = {};
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([]);
    return Promise.resolve(url in respostas ? respostas[url] : []);
  });
  enviar.mockReset().mockResolvedValue({ dados: {}, mensagem: 'ok' });
});

const montar = (ui: ReactNode) => {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>);
};

describe('Coordenadas bancárias', () => {
  const col = (id: number, nome: string) => ({ id, nome_completo: nome, nif: String(id), estado: 'ACTIVO' }) as Colaborador;
  const linhas: Linha[] = [
    { colaborador: col(1, 'Ana'), coordenada: { colaborador_id: 1, banco_id: 3, iban: IBAN } as CoordenadaBancaria },
    { colaborador: col(2, 'Bruno'), coordenada: null },
  ];

  it('filtro: por omissão só com IBAN (como o antigo); «Todos» e «Sem IBAN» mostram os restantes', () => {
    expect(filtrarIbans(linhas, '', 'com').map((l) => l.colaborador.id)).toEqual([1]);
    expect(filtrarIbans(linhas, '', 'todos').map((l) => l.colaborador.id)).toEqual([1, 2]);
    expect(filtrarIbans(linhas, '', 'sem').map((l) => l.colaborador.id)).toEqual([2]);
    expect(filtrarIbans(linhas, 'bru', 'todos').map((l) => l.colaborador.id)).toEqual([2]);
  });

  it('formulário de IBAN embutido na página; editar preenche-o (sem modal) e grava com PUT', async () => {
    respostas['/rh/bancos'] = [{ id: 3, nome: 'Banco Fictício', codigo: '0006' }];
    respostas['/rh/coordenadas-bancarias'] = [{ colaborador_id: 1, banco_id: 3, iban: IBAN, banco: { nome: 'Banco Fictício' } }];
    montar(<Bancario />);
    const cartao = (await screen.findByText('2. IBANs dos Colaboradores')).closest('.ant-card') as HTMLElement;
    expect(within(cartao).getByRole('button', { name: /Adicionar IBAN/ })).toBeInTheDocument();
    // só a Ana (com IBAN) aparece por omissão
    await within(cartao).findByText('Ana Fictícia', { selector: 'strong' });
    expect(within(cartao).queryByText('Bruno Fictício', { selector: 'strong' })).toBeNull();
    fireEvent.click(within(cartao).getByRole('button', { name: 'Editar IBAN' }));
    expect(await within(cartao).findByRole('button', { name: 'Actualizar IBAN' })).toBeInTheDocument();
    expect(document.querySelector('.ant-modal')).toBeNull();
    fireEvent.click(within(cartao).getByRole('button', { name: 'Actualizar IBAN' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('put', '/rh/colaboradores/1/coordenada-bancaria', { banco_id: 3, iban: IBAN }));
  });
});

describe('Contratos', () => {
  it('detalhe do estado: motivo e data da suspensão / cessação, como no antigo', () => {
    const susp = { de: '2026-03-02', ate: null, motivo_codigo: 'X', motivo: 'Doença prolongada', artigo: '', lado: '' };
    expect(detalheEstadoContrato({ estado: 'SUSPENSO', suspensao: susp, cessacao: null })).toEqual({ texto: 'Doença prolongada · desde 02/03/2026', tipo: 'suspensao' });
    expect(detalheEstadoContrato({ estado: 'ACTIVO', suspensao: susp, cessacao: null })).toBeNull();
    expect(detalheEstadoContrato({ estado: 'CESSADO', suspensao: null, cessacao: { data: '2026-05-31', motivo: 'Caducidade' } as never })?.texto).toBe('Caducidade · 31/05/2026');
  });

  it('lista: rubricas uma por linha (célula com erp-quebra), valores por rubrica e o motivo por baixo do estado', async () => {
    // ecrã largo: as colunas «responsive: ['lg']» aparecem (min-width verdadeiro, max-width falso)
    const original = window.matchMedia;
    window.matchMedia = ((q: string) => ({ ...original(q), matches: q.includes('min-width') })) as typeof window.matchMedia;
    try {
      respostas['/rh/infotipos'] = [{ id: 7, nome: 'Salário base', tipo: 'VENCIMENTO' }, { id: 8, nome: 'Subsídio de alimentação', tipo: 'VENCIMENTO' }];
      respostas['/rh/contratos'] = [{
        id: 5, colaborador_id: 1, data_inicio: '2025-01-01', data_fim: null, dias_contrato_mes: 22, horas_por_dia: 8, estado: 'SUSPENSO',
        remuneracoes: [{ infotipo_id: 7, valor_mes: 220000 }, { infotipo_id: 8, valor_mes: 44000 }],
        suspensao: { de: '2026-03-02', ate: null, motivo_codigo: 'X', motivo: 'Doença prolongada', artigo: '', lado: '' },
      }];
      montar(<Contratos />);
      expect(await screen.findByText('Doença prolongada · desde 02/03/2026')).toBeInTheDocument();
      const celula = (await screen.findByText('Subsídio de alimentação', { selector: '.rh-linhas-rubricas > div' })).closest('td') as HTMLElement;
      expect(celula).toHaveClass('erp-quebra');
      expect(within(celula).getByText('Salário base')).toBeInTheDocument();
      // valor/dia por rubrica: 220 000 / 22 = 10 000
      expect(screen.getAllByText(/^10\D?000,00/).length).toBeGreaterThan(0);
    } finally {
      window.matchMedia = original;
    }
  });
});

describe('Férias', () => {
  it('anos da lista: do próximo ano até 5 atrás, mais o escolhido se estiver fora', () => {
    expect(anosDisponiveis(2026, 2026)).toEqual([2027, 2026, 2025, 2024, 2023, 2022, 2021]);
    expect(anosDisponiveis(2026, 2015)).toEqual([2027, 2026, 2025, 2024, 2023, 2022, 2021, 2015]);
  });
});

describe('Efectividade', () => {
  it('o mês escolhe-se dentro do separador (não no cabeçalho)', async () => {
    montar(<Assiduidade />);
    const painel = await waitFor(() => {
      const p = document.querySelector('.ant-tabs-tabpane-active');
      if (!p) throw new Error('sem separador');
      return p as HTMLElement;
    });
    expect(await within(painel).findByLabelText('Mês')).toBeInTheDocument();
    expect(screen.getAllByLabelText('Mês')).toHaveLength(1);
  });
});

describe('Pedidos do Portal', () => {
  it('estado «Por decidir» por omissão (os dois pendentes) e «Rever» nos pedidos de documento', async () => {
    const pedido = { id: 9, colaborador_id: 1, tipo: 'DOCUMENTO', dados: {}, etapas: [{ nivel: 'RH', estado: 'PENDENTE' }], estado: 'PENDENTE_RH', criado_por: null, decidido_em: null, documento: null, criado_em: '2026-10-01' };
    obter.mockImplementation((url: string, params?: Record<string, unknown>) => {
      if (url === '/rh/portal/pedidos') return Promise.resolve(params?.estado === POR_DECIDIR || !params ? [pedido] : []);
      return Promise.resolve([]);
    });
    montar(<PortalGestao />);
    expect(await screen.findByRole('button', { name: /Rever/ })).toBeInTheDocument();
    expect(obter).toHaveBeenCalledWith('/rh/portal/pedidos', expect.objectContaining({ estado: POR_DECIDIR }));
    expect(screen.getByText('Por decidir')).toBeInTheDocument();
    // «Aprovar e emitir» tenta a emissão automática; sem emissão abre a revisão (proposta)
    enviar.mockResolvedValueOnce({ dados: { emitido: false }, mensagem: 'Reveja' });
    fireEvent.click(screen.getByRole('button', { name: /Aprovar e emitir/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/rh/portal/pedidos/9/emitir', {}));
    expect(await screen.findByText(/Emitir documento — pedido #9/)).toBeInTheDocument();
  });
});
