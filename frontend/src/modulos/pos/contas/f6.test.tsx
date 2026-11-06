import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { App as AppAntd } from 'antd';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { enviar } = vi.hoisted(() => ({ enviar: vi.fn() }));
vi.mock('@/api/cliente', async (original) => ({ ...(await original<typeof import('@/api/cliente')>()), enviar }));

import { ModalPreVisualizacaoIntegracao } from './PreVisualizacaoIntegracao';

const pre = {
  sessoes: [{ sessao_pos_id: 1, numero_z: 'Z-T01-2026-0001', codigo_terminal: 'T01', nome_terminal: 'Loja', nome_operador: 'Ana', lancamentos: [],
    impedimentos: [{ codigo: 'IVA_EM_FALTA', mensagem: 'IVA em falta: indique a conta de IVA liquidado para S2.' }], iva_em_falta: [{ id: 7, codigo: 'S2', nome: 'Serviço Dois', linhas: 1 }] }],
  total_debito: '0.00', total_credito: '0.00', impedimentos: 1,
};

beforeEach(() => enviar.mockReset());

describe('F6 — atalho «IVA em falta» da integração', () => {
  it('lista os artigos e grava a conta indicada nesses artigos', async () => {
    enviar.mockImplementation(async (_m: string, url: string) => ((url ?? '').endsWith('pre-visualizacao') ? { dados: pre } : { dados: { artigos_actualizados: 1 } }));
    render(
      <MemoryRouter>
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
          <AppAntd>
            <ModalPreVisualizacaoIntegracao ids={[1]} aoFechar={() => undefined} aoConcluir={() => undefined} />
          </AppAntd>
        </QueryClientProvider>
      </MemoryRouter>,
    );
    expect(await screen.findByTestId('iva-em-falta')).toHaveTextContent('S2');
    const botao = screen.getByRole('button', { name: /Gravar a conta nesses artigos/ });
    expect(botao).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Conta de IVA liquidado'), { target: { value: '3452' } });
    fireEvent.click(await screen.findByRole('button', { name: /Gravar 3452 nesses artigos/ }));
    await waitFor(() =>
      expect(enviar).toHaveBeenCalledWith('post', '/pos/integracao/iva-em-falta', { conta_iva: '3452', produto_ids: [7], definir_omissao: false }),
    );
  });
});
