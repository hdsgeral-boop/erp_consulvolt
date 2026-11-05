import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AvisoMapaAgt, BotaoMapaAgt, ROTULO_MAPA_AGT, resumoAvisos, type VerificacaoMapaAgt } from './BotaoMapaAgt';

const obter = vi.fn();
const descarregar = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  descarregar: (...a: unknown[]) => descarregar(...a),
}));

const verificacao: VerificacaoMapaAgt = {
  colaboradores: 3, avencados_excluidos: 1, ficheiro: 'Mapa Remuneração 2026 - Agosto.xlsx',
  avisos: [{ colaborador_id: 7, nome: 'Carla', codigo: 'SEM_NIF', mensagem: 'NIF do trabalhador em falta' }],
};

function montar(validado = true) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <BotaoMapaAgt periodoId={5} validado={validado} />
      <AvisoMapaAgt periodoId={5} validado={validado} />
    </QueryClientProvider>,
  );
}

describe('BotaoMapaAgt', () => {
  beforeEach(() => { obter.mockReset(); descarregar.mockReset(); });

  it('tem um rótulo profissional (não «Imprimir Formato AGT»)', () => {
    expect(ROTULO_MAPA_AGT).toBe('Exportar Mapa de Remunerações — Modelo AGT (IRT A2.1)');
  });

  it('descarrega o ficheiro do período com o nome do modelo e avisa quem está sem NIF', async () => {
    obter.mockResolvedValue(verificacao);
    descarregar.mockResolvedValue({});
    montar();
    expect(await screen.findByText(/1 colaborador\(es\) sem NIF: Carla/)).toBeInTheDocument();
    const botao = screen.getByRole('button', { name: /Exportar Mapa de Remunerações/ });
    await waitFor(() => expect(botao).toBeEnabled());
    fireEvent.click(botao);
    await waitFor(() => expect(descarregar).toHaveBeenCalledWith('/rh/salarios/periodos/5/mapa-agt', undefined, 'Mapa Remuneração 2026 - Agosto.xlsx'));
    expect(obter).toHaveBeenCalledWith('/rh/salarios/periodos/5/mapa-agt/verificacao');
  });

  it('num período não validado fica desactivado e não consulta o servidor', () => {
    montar(false);
    expect(screen.getByRole('button', { name: /Modelo AGT/ })).toBeDisabled();
    expect(obter).not.toHaveBeenCalled();
  });

  it('resume os avisos por tipo', () => {
    expect(resumoAvisos({ avisos: [...verificacao.avisos, { colaborador_id: 7, nome: 'Carla', codigo: 'SEM_NSS', mensagem: '' }] })).toEqual([
      '1 colaborador(es) sem NIF: Carla', '1 colaborador(es) sem n.º de Segurança Social: Carla']);
  });
});
