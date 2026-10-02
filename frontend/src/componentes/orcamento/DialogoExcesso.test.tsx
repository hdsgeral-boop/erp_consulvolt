import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ErroApi } from '@/api/tipos';

// renderização do Ant Design em jsdom é lenta quando a máquina está carregada
vi.setConfig({ testTimeout: 60_000 });

const enviar = vi.fn(async (_m: string, _u: string, _d?: unknown) => ({
  dados: [{ id: 41, estado: 'PENDENTE', documento: 'LAN-1', valor: '250.00', valor_excesso: '150.00', rubrica_orcamental_id: 7 }],
  mensagem: 'Pedido de aprovação do excesso enviado.',
}));
vi.mock('@/api/cliente', () => ({ enviar: (m: string, u: string, d?: unknown) => enviar(m, u, d), obter: vi.fn(async () => []) }));

let permissoes: string[] = [];
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...c: string[]) => c.some((x) => permissoes.includes(x)) }) }));

import { DialogoExcesso } from './DialogoExcesso';
import { extrairExcesso } from './excesso';

const dados = extrairExcesso(
  new ErroApi('O documento excede o orçamento de 62 FST (115 %): peça a aprovação do excesso.', 422, 'ORCAMENTO_EXIGE_APROVACAO', {
    alertas: [{ orcamento_anual_id: 3, orcamento: 'Orçamento 2026', rubrica_orcamental_id: 7, rubrica: '62 FST', orcado: 1000, consumido: 900, documento: 250, percentagem: 115, excesso: 150, estado: 'APROVACAO' }],
    chave_documento: 'LANCAMENTO|LAN-1',
  }),
);
const contexto = { tipo: 'EXPLORACAO' as const, data: '2026-10-02', linhas: [{ codigo_conta: '6211', valor: 250 }] };

describe('DialogoExcesso', () => {
  beforeEach(() => {
    enviar.mockClear();
    permissoes = [];
  });

  it('pede a aprovação com o motivo e mostra o estado do pedido', async () => {
    permissoes = ['lancamentos_post'];
    render(<DialogoExcesso dados={dados} contexto={contexto} repetir={vi.fn()} aoFechar={vi.fn()} />);
    expect(screen.getByText('62 FST')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Aprovar excesso e gravar' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Pedir aprovação' }));
    expect(await screen.findByText(/pelo menos 5 caracteres/, {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(enviar).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Justificação do excesso'), { target: { value: 'Reparação urgente do gerador' } });
    fireEvent.click(screen.getByRole('button', { name: 'Pedir aprovação' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledTimes(1), { timeout: 15_000 });
    expect(enviar.mock.calls[0][1]).toBe('/orcamento/pedidos-excesso');
    expect(enviar.mock.calls[0][2]).toEqual({ tipo: 'EXPLORACAO', origem: 'LANCAMENTO', documento: 'LAN-1', data: '2026-10-02', linhas: contexto.linhas, motivo: 'Reparação urgente do gerador' });
    expect(await screen.findByText('Pedido de aprovação enviado.', {}, { timeout: 15_000 })).toBeInTheDocument();
    expect(screen.getByText('A aguardar aprovação')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Gravar de novo' })).toBeInTheDocument();
  });

  it('quem pode aprovar grava logo com aprovar_excesso e o motivo', async () => {
    permissoes = ['lancamentos_post', 'orc_aprovar_excesso'];
    const repetir = vi.fn();
    const aoFechar = vi.fn();
    render(<DialogoExcesso dados={dados} contexto={contexto} repetir={repetir} aoFechar={aoFechar} />);
    fireEvent.change(screen.getByLabelText('Justificação do excesso'), { target: { value: 'Contrato já assinado' } });
    fireEvent.click(screen.getByRole('button', { name: 'Aprovar excesso e gravar' }));
    await waitFor(() => expect(repetir).toHaveBeenCalledWith({ aprovar_excesso: true, motivo: 'Contrato já assinado' }), { timeout: 15_000 });
    expect(aoFechar).toHaveBeenCalled();
    expect(enviar).not.toHaveBeenCalled();
  });

  it('sem linhas para o pedido nem permissão de aprovar, explica a alternativa', () => {
    permissoes = ['compras_fact_registar'];
    render(<DialogoExcesso dados={dados} aoFechar={vi.fn()} />);
    expect(screen.queryByRole('button', { name: 'Pedir aprovação' })).toBeNull();
    expect(screen.getByText('O pedido não pode ser preparado a partir deste ecrã.')).toBeInTheDocument();
  });
});
