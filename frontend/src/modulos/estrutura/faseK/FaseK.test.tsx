import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { MinhasDelegacoes, MultaEntregavel, RelatorioImportacaoEntregas } from './tipos';

vi.setConfig({ testTimeout: 180_000 });

// dados fictícios
const COM_ERRO: RelatorioImportacaoEntregas = {
  linhas: [
    { n: 2, estado: 'OK', erros: [], colaborador: { id: 2, nome: 'Rui Fictício' }, data: '2026-02-20', quantidade: '3' },
    { n: 3, estado: 'ERRO', erros: ['Colaborador não encontrado'], colaborador: null, data: '2026-02-21', quantidade: '1' },
  ],
  resumo: { total: 2, validas: 1, erros: 1, duplicadas: 0 },
};
const SEM_ERRO: RelatorioImportacaoEntregas = {
  linhas: [
    { n: 2, estado: 'OK', erros: [], colaborador: { id: 2, nome: 'Rui Fictício' }, data: '2026-02-20', quantidade: '3' },
    { n: 3, estado: 'DUPLICADA', erros: [], colaborador: { id: 3, nome: 'Zé Fictício' }, data: '2026-02-21', quantidade: '1' },
  ],
  resumo: { total: 2, validas: 1, erros: 0, duplicadas: 1 },
};
const MULTAS: MultaEntregavel[] = [
  { id: 1, entregavel_id: 11, colaborador_id: 2, periodo_de: '2026-01-01', periodo_ate: '2026-01-31', limite: '2026-02-10', dias_atraso: 9, valor: '25000', descricao: 'Coima', estado: 'PROPOSTA', entregavel_nome: 'Mapa de IVA', colaborador_nome: 'Rui Fictício', unidade_nome: 'Finanças' },
  { id: 2, entregavel_id: 12, colaborador_id: 3, periodo_de: '2026-01-01', periodo_ate: '2026-01-31', limite: '2026-02-10', dias_atraso: 3, valor: '10000', descricao: null, estado: 'DISPENSADA', motivo_decisao: 'Justificado', entregavel_nome: 'Relatório', colaborador_nome: 'Zé Fictício', unidade_nome: 'Finanças' },
];
const DELEGACOES: MinhasDelegacoes = {
  dadas: [{ id: 5, delegante_colaborador_id: 1, delegado_colaborador_id: 2, delegado_nome: 'Rui Fictício', data_inicio: '2099-01-10', data_fim: '2099-01-20', ambito: ['PORTAL'], motivo: 'Férias', estado: 'ATIVA' }],
  recebidas: [{ id: 6, delegante_colaborador_id: 9, delegado_colaborador_id: 1, delegante_nome: 'Ana Fictícia', data_inicio: '2000-01-01', data_fim: '2099-12-31', ambito: ['ENTREGAVEIS'], motivo: null, estado: 'ATIVA' }],
};

const obterMock = vi.fn();
const enviarMock = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obterMock(...a),
  enviar: (...a: unknown[]) => enviarMock(...a),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { ultima_pagina: 1 } }),
  descarregar: () => Promise.resolve({ nome: 'x.xlsx', cabecalhos: {} }),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' } }) }));
vi.mock('@/modulos/rh/comum/componentes', () => ({ SeletorColaborador: () => null }));

const envolver = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

beforeEach(() => {
  obterMock.mockReset();
  enviarMock.mockReset();
  obterMock.mockImplementation((url: string) => {
    const r: Record<string, unknown> = { '/rh/estrutura/multas': MULTAS, '/rh/portal/delegacoes': DELEGACOES, '/rh/configuracao': { multas_entregavel_max_pct: 0, infotipo_multa_entregavel_id: null }, '/rh/infotipos': [] };
    return url in r ? Promise.resolve(r[url]) : Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: {}, mensagem: 'ok' });
});

describe('Importar Excel de entregas (K2)', () => {
  it('«Importar» só fica activo depois de validar sem erros; grava com simular=0', async () => {
    const { ImportarEntregas } = await import('./ImportarEntregas');
    const { baseElement } = envolver(<ImportarEntregas entregavel={{ id: 10, nome: 'Relatório de fecho' }} aberto aoFechar={() => undefined} />);
    const importar = () => screen.getByRole('button', { name: /Importar$/ }) as HTMLButtonElement;
    const validar = () => screen.getByRole('button', { name: /Validar/ }) as HTMLButtonElement;
    expect(importar().disabled).toBe(true);
    expect(validar().disabled).toBe(true);

    const entrada = baseElement.querySelector('input[type=file]') as HTMLInputElement;
    fireEvent.change(entrada, { target: { files: [new File(['x'], 'entregas.xlsx')] } });
    await waitFor(() => expect(validar().disabled).toBe(false), { timeout: 30_000 });

    // 1.ª validação com um erro: lista a linha e mantém «Importar» desactivado
    enviarMock.mockResolvedValueOnce({ dados: COM_ERRO, mensagem: 'ok' });
    fireEvent.click(validar());
    expect(await screen.findByText('Colaborador não encontrado', undefined, { timeout: 30_000 })).toBeTruthy();
    expect(screen.getByText(/nada é gravado enquanto houver erros/i)).toBeTruthy();
    expect(importar().disabled).toBe(true);
    expect(enviarMock.mock.calls[0][1]).toBe('/rh/estrutura/entregaveis/10/execucoes/importar');
    expect((enviarMock.mock.calls[0][2] as FormData).get('simular')).toBe('1');

    // 2.ª validação sem erros (uma duplicada ignorada): activa «Importar»
    enviarMock.mockResolvedValueOnce({ dados: SEM_ERRO, mensagem: 'ok' });
    fireEvent.click(validar());
    expect((await screen.findAllByText(/já importada/, undefined, { timeout: 30_000 })).length).toBeGreaterThan(0);
    await waitFor(() => expect(importar().disabled).toBe(false), { timeout: 30_000 });
    enviarMock.mockResolvedValueOnce({ dados: SEM_ERRO, mensagem: 'Importadas' });
    fireEvent.click(importar());
    await waitFor(() => expect(enviarMock).toHaveBeenCalledTimes(3), { timeout: 30_000 });
    expect((enviarMock.mock.calls[2][2] as FormData).get('simular')).toBe('0');
    expect(await screen.findByText('Importação concluída', undefined, { timeout: 30_000 })).toBeTruthy();
  });
});

describe('Multas de entregáveis (K3)', () => {
  it('lista, aplica uma proposta, dispensa com motivo e repõe a dispensada', async () => {
    const { default: Multas } = await import('../multas/Multas');
    envolver(<Multas />);
    expect(await screen.findByText('Mapa de IVA', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(screen.getByText('Relatório')).toBeTruthy();
    expect(screen.getByText(/exige decisão de uma pessoa/)).toBeTruthy();

    // aplicar (confirmação)
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar multa 1' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Aplicar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/rh/estrutura/multas/1/aplicar'), { timeout: 30_000 });

    // dispensar exige motivo (mínimo 3 caracteres)
    fireEvent.click(screen.getByRole('button', { name: 'Dispensar multa 1' }));
    const dialogo = await screen.findByRole('dialog', {}, { timeout: 30_000 });
    fireEvent.change(within(dialogo).getByRole('textbox'), { target: { value: 'Justificado pela chefia' } });
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Dispensar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/rh/estrutura/multas/1/dispensar', { motivo: 'Justificado pela chefia' }), { timeout: 30_000 });

    // repor
    fireEvent.click(screen.getByRole('button', { name: 'Repor multa 2' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/rh/estrutura/multas/2/repor'), { timeout: 30_000 });
  });
});

describe('Portal › Delegações (K5)', () => {
  it('mostra as que dei e as que recebi, avisa que está a decidir em nome de outra chefia e revoga', async () => {
    const { PortalDelegacoes } = await import('@/modulos/rh/portal/Delegacoes');
    envolver(<PortalDelegacoes />);
    expect(await screen.findByText('Rui Fictício', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(screen.getAllByText('Ana Fictícia').length).toBeGreaterThan(0);
    expect(screen.getByText(/Está a decidir em nome de Ana Fictícia/)).toBeTruthy();
    expect(screen.getByRole('button', { name: /Dar delegação/ })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /^Revogar$/ }));
    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Revogar' }).length).toBe(2), { timeout: 30_000 });
    fireEvent.click(screen.getAllByRole('button', { name: 'Revogar' })[1]);
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/rh/portal/delegacoes/5/revogar'), { timeout: 30_000 });
  });
});
