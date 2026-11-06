import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TabelaLegado } from './TabelaLegado';

const exportarExcel = vi.fn();
vi.mock('../impressao/useImpressao', async (importar) => {
  const real = await importar<typeof import('../impressao/useImpressao')>();
  return { ...real, useImpressao: () => ({ ...real.useImpressao(), exportarExcel }) };
});
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: { nome_utilizador: 'ana' } }) }));
vi.mock('@/api/cliente', () => ({ obter: vi.fn(() => Promise.resolve({})), enviar: vi.fn(), obterPagina: vi.fn() }));

interface Linha { id: number; codigo: string; nome: string; total: number }
const dados: Linha[] = [
  { id: 1, codigo: 'C1', nome: 'Alfa', total: 10 },
  { id: 2, codigo: 'C2', nome: 'Beta', total: 20 },
];

const montar = (ui: ReactElement) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{ui}</QueryClientProvider>);

beforeEach(() => exportarExcel.mockReset().mockResolvedValue('ficheiro.xlsx'));

describe('TabelaLegado — exportar para Excel só as colunas seleccionadas', () => {
  it('Ctrl+clique no cabeçalho mostra «Exportar Excel» e exporta só essas colunas', async () => {
    montar(
      <TabelaLegado<Linha>
        rowKey="id"
        chaveLista="teste-exportar-colunas"
        dataSource={dados}
        impressao={{ titulo: 'Lista de teste', nomeFicheiro: 'lista-teste' }}
        columns={[
          { title: 'Código', dataIndex: 'codigo' },
          { title: 'Nome', dataIndex: 'nome' },
          { title: 'Total', dataIndex: 'total' },
        ]}
      />,
    );
    expect(screen.queryByRole('button', { name: /Exportar Excel/ })).toBeNull();
    fireEvent.click(screen.getByText('Nome'), { ctrlKey: true });
    fireEvent.click(screen.getByText('Total'), { ctrlKey: true });
    expect(screen.getByText('2 colunas seleccionadas')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Exportar Excel/ }));
    await waitFor(() => expect(exportarExcel).toHaveBeenCalledTimes(1));
    const pedido = exportarExcel.mock.calls[0][0] as { conteudo: string; titulo: string };
    expect(pedido.titulo).toBe('Lista de teste');
    expect(pedido.conteudo).toContain('Nome');
    expect(pedido.conteudo).toContain('Total');
    expect(pedido.conteudo).toContain('Beta');
    expect(pedido.conteudo).not.toContain('Código');
    expect(pedido.conteudo).not.toContain('C2');
  });

  it('com a exportação Excel desligada na lista, o botão não aparece', () => {
    montar(
      <TabelaLegado<Linha>
        rowKey="id"
        chaveLista="teste-exportar-colunas"
        dataSource={dados}
        impressao={{ titulo: 'Lista de teste', excel: false }}
        columns={[{ title: 'Código', dataIndex: 'codigo' }, { title: 'Nome', dataIndex: 'nome' }]}
      />,
    );
    fireEvent.click(screen.getByText('Código'), { ctrlKey: true });
    expect(screen.getByRole('button', { name: /Copiar/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Exportar Excel/ })).toBeNull();
  });
});
