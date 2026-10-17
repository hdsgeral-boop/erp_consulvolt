import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BarraAccoesMassa, listaRetidas, rotuloSeleccao } from './AccoesMassaPlano';

const montar = (ids: number[], podeModificar: boolean, podeEliminar: boolean) =>
  render(
    <QueryClientProvider client={new QueryClient()}>
      <BarraAccoesMassa ids={ids} podeModificar={podeModificar} podeEliminar={podeEliminar} aoLimpar={() => undefined} />
    </QueryClientProvider>,
  );

describe('Plano de Contas — acções em massa (legado js/app_v2.js:8828-9037)', () => {
  it('rótulo da selecção e lista das contas retidas como o legado', () => {
    expect(rotuloSeleccao(1)).toBe('1 conta seleccionada');
    expect(rotuloSeleccao(3)).toBe('3 contas seleccionadas');
    expect(listaRetidas([{ codigo: '3111', descricao: 'Clientes', movimentos: 2 }], 'mov.')).toEqual(['3111 – Clientes (2 mov.)']);
  });

  it('sem selecção não mostra a barra; com selecção mostra só as acções permitidas', () => {
    const { container, unmount } = montar([], true, true);
    expect(container).toBeEmptyDOMElement();
    unmount();
    montar([1, 2], true, false);
    expect(screen.getByText('2 contas seleccionadas')).toBeInTheDocument();
    expect(screen.getByText('Modificar em Massa')).toBeInTheDocument();
    expect(screen.queryByText('Eliminar Seleccionadas')).not.toBeInTheDocument();
  }, 60_000);
});
