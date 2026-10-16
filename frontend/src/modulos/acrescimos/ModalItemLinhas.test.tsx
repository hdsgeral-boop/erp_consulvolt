import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import dayjs from 'dayjs';
import { errosLinha } from '@/componentes/formularios';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn(() => Promise.resolve({ itens: [], paginacao: { pagina_atual: 1, por_pagina: 30, total: 0, ultima_pagina: 1 } })),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresas: [], empresa: null, utilizador: null, menu: [] }) }));

import { ModalItem } from './ModalItem';
import { camposItem, dadosItem } from './comum/formularioItem';

// ecrãs AntD completos no jsdom (lentos com a máquina carregada)
vi.setConfig({ testTimeout: 300_000 });

const DEFINICOES = {
  contas: { ACRESCIMO_PROVEITO: '37.1.1', ACRESCIMO_CUSTO: '37.1.2', DIFERIMENTO_CUSTO: '37.2.1', DIFERIMENTO_PROVEITO: '37.2.2' },
  diario_id: 1, prazo_documento_dias: 60, rotulos_contas: {}, modelos: [], tipos: {}, naturezas: {}, estados: {}, tipos_linha: {},
};

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) =>
    Promise.resolve(url.startsWith('/sistema/preferencias') ? [...prefs] : url === '/acrescimos/definicoes' ? DEFINICOES : []),
  );
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: { item: { id: 7 }, parcial: false }, mensagem: 'ok' });
  });
});

describe('Acréscimos e diferimentos — registo em Grade | Linhas', () => {
  it('dadosItem: a grade (dayjs) e as linhas (texto) dão o mesmo corpo; sem conta 37 usa a das Definições', () => {
    const grade = dadosItem(
      { modelo: 'x', tipo: 'DIFERIMENTO', natureza: 'CUSTO', valor: 1200, descricao: 'Seguro', conta_resultado: '75.2', conta_balanco: '37.2.1',
        data_inicio: dayjs('2026-01-01'), data_fim: dayjs('2026-12-31'), data_documento: dayjs('2025-12-15'), documento_em_balanco: false, terceiro_id: undefined, notas: '' },
      { definicoes: DEFINICOES },
    );
    const linha = dadosItem(
      { tipo: 'DIFERIMENTO', natureza: 'CUSTO', valor: 1200, descricao: 'Seguro', conta_resultado: '75.2', conta_37: '', data_inicio: '2026-01-01', data_fim: '2026-12-31',
        data_documento: '2025-12-15', reparticao: 'MESES', data_limite: null, unidade_negocio_id: null, centro_custo_id: null, notas: '' },
      { definicoes: DEFINICOES },
    );
    for (const d of [grade, linha]) {
      expect(d).toMatchObject({ conta_balanco: '37.2.1', data_inicio: '2026-01-01', data_fim: '2026-12-31', data_documento: '2025-12-15', data_limite: null, notas: null, origem: null });
      expect(d).not.toHaveProperty('modelo');
      expect(d).not.toHaveProperty('conta_37');
    }
    expect(grade.terceiro_id).toBeNull();
  });

  it('as linhas validam fim ≥ início e a data do documento num diferimento', () => {
    const campos = camposItem({ definicoes: DEFINICOES });
    const base = { tipo: 'DIFERIMENTO', natureza: 'CUSTO', descricao: 'X', valor: 10, conta_resultado: '75', data_inicio: '2026-02-01', data_fim: '2026-01-01' };
    expect(errosLinha(campos, base)).toMatch(/Fim anterior ao início.*obrigatória num diferimento/);
    expect(errosLinha(campos, { ...base, tipo: 'ACRESCIMO', data_fim: '2026-03-31' })).toBeNull();
    expect(errosLinha(camposItem({ definicoes: { contas: { ...DEFINICOES.contas, ACRESCIMO_CUSTO: null } } }), { ...base, tipo: 'ACRESCIMO', data_fim: '2026-03-31' })).toMatch(/Indique a conta 37/);
  });

  it('«Novo registo» em Linhas grava um registo por linha pelo mesmo POST; a edição só tem a grade (em secções)', async () => {
    const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { rerender } = render(
      <QueryClientProvider client={cliente}>
        <MemoryRouter><ModalItem aberto aoFechar={() => undefined} /></MemoryRouter>
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Novo registo de acréscimo / diferimento')).toBeInTheDocument();
    // grade em secções: a imputação analítica começa fechada
    expect(screen.getByRole('button', { name: /Imputação analítica/ })).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(screen.getByRole('button', { name: /Linhas/ }));
    fireEvent.paste((await screen.findAllByLabelText('Tipo, linha 1'))[0], {
      clipboardData: { getData: () => 'Diferimento\tGasto\tSeguro anual\t1.200,00\t75.2\t\t01/01/2026\t31/12/2026\t15/12/2025\nAcréscimo\tGasto\tFérias a pagar\t500\t72.1\t37.9\t01/01/2026\t31/03/2026\t\n' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    const posts = () => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === '/acrescimos/itens');
    await waitFor(() => expect(posts()).toHaveLength(2));
    expect(posts()[0][2]).toMatchObject({ tipo: 'DIFERIMENTO', natureza: 'CUSTO', descricao: 'Seguro anual', valor: 1200, conta_resultado: '75.2', conta_balanco: '37.2.1', data_documento: '2025-12-15', origem: null });
    expect(posts()[1][2]).toMatchObject({ tipo: 'ACRESCIMO', conta_balanco: '37.9', data_inicio: '2026-01-01', data_fim: '2026-03-31' });

    rerender(
      <QueryClientProvider client={cliente}>
        <MemoryRouter>
          <ModalItem aberto item={{ id: 3, tipo: 'ACRESCIMO', natureza: 'CUSTO', descricao: 'Água', valor: '10', conta_resultado: '72', conta_balanco: '37.1', data_inicio: '2026-01-01', data_fim: '2026-01-31' }} aoFechar={() => undefined} />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Editar registo #3')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
  });
});
