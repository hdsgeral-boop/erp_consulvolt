import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';

vi.setConfig({ testTimeout: 120_000 });

// dados fictícios — ecrãs de detalhe (ADR-071, R2/R5)
const CRITERIOS = [{ id: 1, codigo: 'PCT', nome: 'Percentagem fixa', criterio: 'PERCENTAGEM', indicador: null, periodo_de: null, periodo_ate: null, notas: null, ativo: true }];
const UNIDADES = [{ id: 11, codigo: 'UN1', nome: 'Comercial', estado: 'ATIVO' }, { id: 12, codigo: 'UN2', nome: 'Industrial', estado: 'ATIVO' }];
const RESUMO = {
  criterio: { id: 1, codigo: 'PCT', nome: 'Percentagem fixa', criterio: 'PERCENTAGEM' },
  unidades: [{ unidade_negocio_id: 11, peso: '60.0000' }, { unidade_negocio_id: 12, peso: '40.0000' }],
  linhas: [
    { unidade_negocio_id: 11, nome: 'UN1 — Comercial', indutor: '60.0000', percentagem: '60.0000', valor: '600.00' },
    { unidade_negocio_id: 12, nome: 'UN2 — Industrial', indutor: '40.0000', percentagem: '40.0000', valor: '400.00' },
  ],
  total: '1000.00',
  erro: null,
};

const obterMock = vi.fn();
const enviarMock = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obterMock(...a),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { total: 0, pagina_atual: 1, por_pagina: 50, ultima_pagina: 1 } }),
  enviar: (...a: unknown[]) => enviarMock(...a),
}));

const envolver = (ui: ReactNode) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{ui}</QueryClientProvider>);

beforeEach(() => {
  obterMock.mockReset();
  enviarMock.mockReset();
  obterMock.mockImplementation((url: string) => Promise.resolve(
    url === '/reparticao/criterios' ? CRITERIOS : url === '/sistema/unidades-negocio' ? UNIDADES
      : url.startsWith('/reparticao/alocacoes/') ? { unidades_alocadas: { criterio_id: 1, unidades: [{ unidade_negocio_id: 11, peso: 60 }, { unidade_negocio_id: 12, peso: 40 }] }, unidade_negocio_id: 11 } : [],
  ));
  enviarMock.mockImplementation(() => Promise.resolve({ dados: RESUMO, mensagem: 'ok' }));
});

describe('Repartição nos detalhes', () => {
  it('textos da UN/CC da linha: «— cabeçalho —» quando a linha não tem', async () => {
    const { textoUNLinha, textoCCLinha, bloqueioAlocacao } = await import('./DetalheReparticao');
    expect(textoUNLinha({})).toBe('— cabeçalho —');
    expect(textoUNLinha({ unidade_negocio_id: 11, unidade_negocio: { id: 11, codigo: 'UN1', nome: 'Comercial' } })).toBe('UN1 — Comercial');
    expect(textoCCLinha({ centro_custo_id: 5, centro_custo: { id: 5, codigo: 'CC1', descricao: 'Obra fictícia' } })).toBe('CC1 — Obra fictícia');
    expect(bloqueioAlocacao({ contabilizado: true })).toMatch(/contabilizado/);
    expect(bloqueioAlocacao({ estado: 'ANULADA' })).toMatch(/anulado/);
    expect(bloqueioAlocacao({ contabilizado: false, estado: 'PENDENTE' })).toBeNull();
  });

  it('cartão só de leitura com as percentagens; documento contabilizado não deixa alterar', async () => {
    const { CartaoAlocacaoUN } = await import('./DetalheReparticao');
    envolver(<CartaoAlocacaoUN tipo="fatura_compra" id={7} resumo={RESUMO} podeAlterar bloqueio="Documento contabilizado: descontabilize-o para alterar a repartição." />);
    expect(screen.getByText('PCT Percentagem fixa')).toBeInTheDocument();
    expect(screen.getByText('UN1 — Comercial')).toBeInTheDocument();
    expect(screen.getByText('60,00 %')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Alterar repartição/ })).toBeDisabled();
  });

  it('modal grava a alocação no objecto (PUT /reparticao/alocacoes)', async () => {
    const { CartaoAlocacaoUN } = await import('./DetalheReparticao');
    envolver(<CartaoAlocacaoUN tipo="recibo_venda" id={9} resumo={null} podeAlterar unidadeCabecalho="UN1" />);
    expect(screen.getByText(/Sem repartição: usa só a unidade do cabeçalho \(UN1\)/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Alterar repartição/ }));
    await waitFor(() => expect(obterMock).toHaveBeenCalledWith('/reparticao/alocacoes/recibo_venda/9'));
    const gravar = await screen.findByRole('button', { name: 'Gravar' });
    await waitFor(() => expect(gravar).not.toBeDisabled());
    fireEvent.click(gravar);
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('put', '/reparticao/alocacoes/recibo_venda/9', {
      unidades_alocadas: { criterio_id: 1, unidades: [{ unidade_negocio_id: 11, peso: 60 }, { unidade_negocio_id: 12, peso: 40 }] },
    }));
  });
});
