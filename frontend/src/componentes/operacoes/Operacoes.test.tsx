import { act, render, screen, waitFor } from '@testing-library/react';
import { useEffect } from 'react';
import { OperacoesProvider, useOperacoes } from './Operacoes';

const obterMock = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: vi.fn() }));

/** Componente de teste: acompanha um lote assim que monta. */
function Acompanhar({ id = 'lote-1', titulo = 'Contabilizar 3 documento(s)', aoConcluir }: { id?: string; titulo?: string; aoConcluir?: () => void }) {
  const { acompanharLote } = useOperacoes();
  useEffect(() => {
    acompanharLote(id, titulo, aoConcluir);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  return null;
}

const montar = (props?: Parameters<typeof Acompanhar>[0]) =>
  render(
    <OperacoesProvider>
      <Acompanhar {...props} />
    </OperacoesProvider>,
  );

describe('OperacoesProvider — acompanhar um lote em segundo plano (M-05)', () => {
  beforeEach(() => obterMock.mockReset());

  it('mostra a operação em curso, actualiza o progresso e remove-a quando concluída (botão «Fechar»)', async () => {
    obterMock
      .mockResolvedValueOnce({ id: 'lote-1', titulo: 'Contabilizar 3 documento(s)', progresso: 33, total: 3, pendentes: 2, falhados: 0, estado: 'EM_CURSO' })
      .mockResolvedValueOnce({ id: 'lote-1', titulo: 'Contabilizar 3 documento(s)', progresso: 100, total: 3, pendentes: 0, falhados: 0, estado: 'CONCLUIDA' });
    const aoConcluir = vi.fn();
    montar({ aoConcluir });

    await screen.findByText('Operações em curso (1)');
    await waitFor(() => expect(screen.getByText(/1 de 3/)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /Cancelar Contabilizar 3 documento\(s\)/ })).toBeInTheDocument();

    await waitFor(() => expect(screen.getByText('Operações concluídas')).toBeInTheDocument(), { timeout: 4000 });
    expect(screen.getByText(/3 de 3/)).toBeInTheDocument();
    expect(aoConcluir).toHaveBeenCalledTimes(1);

    act(() => screen.getByRole('button', { name: /Fechar/ }).click());
    expect(screen.queryByLabelText('Operações em segundo plano')).not.toBeInTheDocument();
  }, 15_000);

  it('mostra «X com erro» quando o lote termina com trabalhos falhados, e não chama aoConcluir enquanto estiver em curso', async () => {
    obterMock
      .mockResolvedValueOnce({ id: 'lote-2', titulo: 'Contabilizar 2 documento(s)', progresso: 50, total: 2, pendentes: 1, falhados: 0, estado: 'EM_CURSO' })
      .mockResolvedValueOnce({ id: 'lote-2', titulo: 'Contabilizar 2 documento(s)', progresso: 100, total: 2, pendentes: 0, falhados: 1, estado: 'CONCLUIDA_COM_FALHAS' });
    const aoConcluir = vi.fn();
    montar({ id: 'lote-2', titulo: 'Contabilizar 2 documento(s)', aoConcluir });

    await screen.findByText(/1 de 2/);
    expect(aoConcluir).not.toHaveBeenCalled();

    await waitFor(() => expect(screen.getByText(/2 de 2 · 1 com erro/)).toBeInTheDocument(), { timeout: 4000 });
    expect(aoConcluir).toHaveBeenCalledTimes(1);
  }, 15_000);

  it('erro a consultar o estado marca a operação como falhada (sem repetir)', async () => {
    obterMock.mockRejectedValueOnce(new Error('Falha de rede'));
    montar({ id: 'lote-3' });

    await waitFor(() => expect(screen.getByText('Falha de rede')).toBeInTheDocument());
    expect(obterMock).toHaveBeenCalledTimes(1);
  });
});
