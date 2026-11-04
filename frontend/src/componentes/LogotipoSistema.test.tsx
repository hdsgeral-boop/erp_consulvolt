import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { LogotipoDoSistema } from './LogotipoSistema';

const obter = vi.fn();
vi.mock('@/api/cliente', async (original) => ({ ...(await original<typeof import('@/api/cliente')>()), obter: (...a: unknown[]) => obter(...a) }));

function montar() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <LogotipoDoSistema tamanho={40} />
    </QueryClientProvider>,
  );
}

describe('LogotipoDoSistema', () => {
  beforeEach(() => obter.mockReset());

  it('mostra o logótipo do cliente quando existe', async () => {
    obter.mockResolvedValue({ logotipo: 'data:image/png;base64,AAAA', nome_sistema: 'Alfa ERP' });
    montar();
    const img = await screen.findByTestId('logotipo-cliente');
    expect(img).toHaveAttribute('alt', 'Alfa ERP');
    expect(obter).toHaveBeenCalledWith('/sistema/logotipo-login');
  });

  it('sem logótipo (ou sem resposta) mostra o símbolo padrão em SVG', async () => {
    obter.mockResolvedValue({ logotipo: null, nome_sistema: null });
    montar();
    expect(await screen.findByTestId('logotipo-sistema')).toBeInTheDocument();
    expect(screen.queryByTestId('logotipo-cliente')).toBeNull();
  });
});
