import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from 'antd';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const obterPagina = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  obterPagina: (...a: unknown[]) => obterPagina(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null }) }));

import { ModalContrato, dadosContrato } from './contratos/ModalContrato';
import { CAMPOS_MARCO, dadosMarco } from './contratos/DetalheContrato';
import { camposTerceiro, corpoTerceiro } from './comum/GestaoTerceiros';
import type { ContratoCompra } from './comum/tipos';
import dayjs from 'dayjs';

// antd no jsdom é lento (e a máquina de testes partilhada): margem larga por teste e nas esperas
vi.setConfig({ testTimeout: 600_000 });
const ESPERA = { timeout: 30_000 };

function ecra(no: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <App>{no}</App>
    </QueryClientProvider>,
  );
}

const celula = (rotulo: string, n: number) => screen.getByLabelText(`${rotulo}, linha ${n}`) as HTMLInputElement;

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(url.startsWith('/sistema/preferencias') ? [...prefs] : []));
  obterPagina.mockReset().mockImplementation((url: string) =>
    Promise.resolve(url === '/terceiros'
      ? { itens: [{ id: 5, nome: 'Fornecedor Alfa, Lda', nif: '5000000001' }, { id: 6, nome: 'Beta Comercial', nif: '5000000002' }], total: 2, pagina: 1, por_pagina: 500 }
      : { itens: [], total: 0, pagina: 1, por_pagina: 25 }));
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: { id: 1 }, mensagem: 'ok' });
  });
});

describe('Compras — formulários de criação com Grade | Linhas', () => {
  it('«Novo contrato» em Linhas: colar do Excel e gravar um contrato por linha pelo mesmo POST', async () => {
    const aoFechar = vi.fn();
    ecra(<ModalContrato aberto aoFechar={aoFechar} />);
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    await screen.findByTestId('painel-linhas', undefined, ESPERA);
    // a lista de fornecedores da coluna «Fornecedor» chega antes de colar (os nomes são reconhecidos pela lista)
    await waitFor(() => expect(obterPagina).toHaveBeenCalledWith('/terceiros', expect.objectContaining({ papel: 'FORNECEDOR' })), ESPERA);
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(await screen.findByLabelText('Linhas a colar'), {
      target: { value: 'Fornecedor\tReferência\tObjecto\tInício\tFim\tValor\nFornecedor Alfa\tCT-01\tLimpeza\t01/01/2026\t31/12/2026\t1.200.000,50\n5000000002\tCT-02\t\t01/02/2026\t\t300000' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
    await waitFor(() => expect(celula('Referência', 2).value).toBe('CT-02'), ESPERA);
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }, ESPERA));
    const posts = () => enviar.mock.calls.filter((c) => c[1] === '/compras/contratos');
    await waitFor(() => expect(posts()).toHaveLength(2), ESPERA);
    expect(posts()[0][2]).toEqual({ fornecedor_id: 5, referencia: 'CT-01', descricao: 'Limpeza', data_inicio: '2026-01-01', data_fim: '2026-12-31', valor_total: 1200000.5 });
    expect(posts()[1][2]).toEqual({ fornecedor_id: 6, referencia: 'CT-02', descricao: null, data_inicio: '2026-02-01', data_fim: null, valor_total: 300000 });
    await waitFor(() => expect(aoFechar).toHaveBeenCalled(), ESPERA);
  });

  it('«Editar contrato» só tem a grade', async () => {
    const contrato = { id: 3, fornecedor_id: 5, referencia: 'CT-09', descricao: null, data_inicio: '2026-01-01', data_fim: null, valor_total: '10', estado: 'ATIVO' } as unknown as ContratoCompra;
    ecra(<ModalContrato aberto contrato={contrato} aoFechar={() => {}} />);
    expect(await screen.findByText('Editar contrato CT-09', undefined, ESPERA)).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
  });

  it('contratos, marcos e fichas de terceiros: as mesmas regras na grade e nas linhas', () => {
    expect(dadosContrato({ fornecedor_id: 5, referencia: ' R1 ', descricao: '', data_inicio: dayjs('2026-03-01'), data_fim: null, valor_total: 10 }))
      .toEqual({ fornecedor_id: 5, referencia: 'R1', descricao: null, data_inicio: '2026-03-01', data_fim: null, valor_total: 10 });
    expect(dadosMarco({ titulo: 'Adiantamento', data_prevista: '2026-05-01', montante: 50 })).toEqual({ titulo: 'Adiantamento', data_prevista: '2026-05-01', montante: 50 });
    expect(CAMPOS_MARCO.filter((c) => c.obrigatorio).map((c) => c.nome)).toEqual(['titulo', 'montante']);
    const conta = camposTerceiro('FORNECEDOR').find((c) => c.nome === 'conta_fornecedor')!;
    expect(conta.validar?.('31.1.1', {})).toMatch(/classe 32 ou 37\.0/);
    expect(conta.validar?.('32.1.1', {})).toBeNull();
    expect(conta.validar?.('37.0.1', {})).toBeNull();
    expect(camposTerceiro('CLIENTE').some((c) => c.nome === 'conta_compra_transitoria')).toBe(false);
    expect(corpoTerceiro('CLIENTE', { nome: ' Cliente X ', conta_cliente: '31.1.1', codigo_moeda: 'usd' } as never)).toMatchObject({ papel: 'CLIENTE', nome: 'Cliente X', conta_cliente: '31.1.1', codigo_moeda: 'USD' });
  });
});
