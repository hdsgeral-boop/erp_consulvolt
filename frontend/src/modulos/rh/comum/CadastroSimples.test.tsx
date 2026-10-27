import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Button } from 'antd';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';

const obter = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: vi.fn(),
  obterPagina: vi.fn(),
  descarregar: vi.fn(),
}));

vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null }) }));

import { CadastroSimples, useDestinoAccoes } from './CadastroSimples';

interface Linha {
  id: number;
  nome: string;
}

const LINHAS: Linha[] = [
  { id: 3, nome: 'Contabilista' },
  { id: 1, nome: 'Director' },
  { id: 2, nome: 'Assistente' },
];

function comCliente(no: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}>{no}</QueryClientProvider>);
}

const base = {
  url: '/rh/teste',
  chave: ['rh', 'teste'],
  nomeItem: 'função',
  rotuloNovo: 'Nova Função',
  tituloImpressao: 'Lista de funções',
  podeGerir: true,
  podeEliminar: false,
  pesquisa: (r: Linha) => r.nome,
  colunas: [{ title: 'ID', dataIndex: 'id' }, { title: 'Nome da Função', dataIndex: 'nome' }],
  campos: null,
  accoesExtra: <Button>Template</Button>,
};

/** Página como as do antigo (Gestão de Funções): botões à direita do título. */
function Pagina({ visivel = true }: { visivel?: boolean }) {
  const [destino, elemento] = useDestinoAccoes();
  return (
    <>
      <CabecalhoPagina titulo="Gestão de Funções" accoes={elemento} />
      <CadastroSimples<Linha> {...base} accoesCabecalho={visivel ? destino : null} ordemInicial={(a, b) => a.id - b.id} />
    </>
  );
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  obter.mockReset();
  obter.mockImplementation((url: string) => Promise.resolve(url === '/rh/teste' ? LINHAS : {}));
  window.history.replaceState(null, '', '/m/rh/funcoes');
});

describe('CadastroSimples', { timeout: 30_000 }, () => {
  it('com accoesCabecalho: Template e «+ Nova Função» no cabeçalho da página, lista ordenada e caixa do antigo', async () => {
    comCliente(<Pagina />);
    await waitFor(() => expect(screen.getByText('3 registos encontrados')).toBeInTheDocument(), { timeout: 10_000 });
    const cabecalho = document.querySelector('.cabecalho-pagina') as HTMLElement;
    expect(within(cabecalho).getByRole('button', { name: 'Template' })).toBeInTheDocument();
    expect(within(cabecalho).getByRole('button', { name: /Nova Função/ })).toBeInTheDocument();
    // sem a pesquisa geral (o DataTables do antigo não a tinha); um único botão «Imprimir» abre as opções (Excel/PDF/Imprimir) numa modal
    expect(screen.queryByPlaceholderText(/Pesquisar/i)).toBeNull();
    expect(screen.getByRole('button', { name: /Imprimir/ })).toBeInTheDocument();
    const nomes = screen.getAllByRole('row').map((l) => l.textContent ?? '').filter((t) => /Director|Assistente|Contabilista/.test(t));
    expect(nomes.map((t) => t.match(/Director|Assistente|Contabilista/)![0])).toEqual(['Director', 'Assistente', 'Contabilista']);
  });

  it('separador inactivo (destino null): não põe botões no cabeçalho', async () => {
    comCliente(<Pagina visivel={false} />);
    await waitFor(() => expect(screen.getByText('3 registos encontrados')).toBeInTheDocument(), { timeout: 10_000 });
    expect(screen.queryByRole('button', { name: 'Template' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Nova Função/ })).toBeNull();
  });

  it('por omissão os botões continuam na barra do cartão', async () => {
    comCliente(<CadastroSimples<Linha> {...base} />);
    await waitFor(() => expect(screen.getByText('3 registos encontrados')).toBeInTheDocument(), { timeout: 10_000 });
    const cartao = document.querySelector('.ant-card') as HTMLElement;
    expect(within(cartao).getByRole('button', { name: 'Template' })).toBeInTheDocument();
    expect(within(cartao).getByRole('button', { name: /Nova Função/ })).toBeInTheDocument();
  });
});
