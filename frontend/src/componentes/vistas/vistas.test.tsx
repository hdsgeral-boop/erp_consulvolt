import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Button, Tag } from 'antd';
import type { ReactNode } from 'react';
import { simularLargura } from '@/componentes/responsivo/testes/simularLargura';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn(),
}));

import { recarregarPreferenciasVistaLocal } from './preferenciaVista';
import { CHAVE_LOCAL_VISTAS, NOME_PREFERENCIA_VISTAS, TabelaComModos, derivarEstrutura, reporPreferenciasVistaLocal, type ColunaVista } from './index';

interface Cliente {
  id: number;
  codigo: string;
  nome: string;
  nif: string;
  saldo: number;
  estado: 'ACTIVO' | 'INACTIVO';
}

const linhas: Cliente[] = [
  { id: 1, codigo: 'C001', nome: 'Alfa, Lda', nif: '5000000001', saldo: 1234.5, estado: 'ACTIVO' },
  { id: 2, codigo: 'C002', nome: 'Beta, SA', nif: '5000000002', saldo: 0, estado: 'INACTIVO' },
];

let servidor: { nome: string; valor: unknown }[] = [];
const editar = vi.fn();
const eliminar = vi.fn();
const abrir = vi.fn();

const colunas: ColunaVista<Cliente>[] = [
  { title: 'Código', dataIndex: 'codigo' },
  { title: 'Nome', dataIndex: 'nome' },
  { title: 'NIF', dataIndex: 'nif' },
  { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: number) => v.toLocaleString('pt-PT', { minimumFractionDigits: 2 }) },
  { title: 'Estado', dataIndex: 'estado', render: (e: string) => <Tag color={e === 'ACTIVO' ? 'green' : 'default'}>{e === 'ACTIVO' ? 'Activo' : 'Inactivo'}</Tag> },
  { title: 'Interno', dataIndex: 'id', noCartao: false },
  {
    title: '',
    key: 'accoes',
    render: (_: unknown, r: Cliente) => (
      <>
        <Button size="small" onClick={() => editar(r.id)}>
          Editar
        </Button>
        <Button size="small" danger onClick={() => eliminar(r.id)}>
          Eliminar
        </Button>
      </>
    ),
  },
];

function comCliente(no: ReactNode, cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } })) {
  return render(<QueryClientProvider client={cliente}>{no}</QueryClientProvider>);
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  obter.mockReset();
  enviar.mockReset();
  editar.mockReset();
  eliminar.mockReset();
  abrir.mockReset();
  // servidor simulado: guarda o que recebe e devolve-o nas leituras seguintes
  servidor = [];
  obter.mockImplementation(async () => servidor);
  enviar.mockImplementation(async (_m: string, url: string, corpo: { valor: unknown }) => {
    const nome = decodeURIComponent(url.split('/').pop() ?? '');
    servidor = [...servidor.filter((p) => p.nome !== nome), { nome, valor: corpo.valor }];
    return { dados: null, mensagem: 'ok' };
  });
  window.history.replaceState(null, '', '/m/vendas/vendas_clientes');
});

afterEach(() => simularLargura(null));

describe('derivarEstrutura — cartão a partir das colunas', () => {
  it('código + nome: o nome é o título, o código o subtítulo; estado como etiqueta; acções no rodapé', () => {
    const e = derivarEstrutura(colunas);
    expect(e.titulo?.title).toBe('Nome');
    expect(e.subtitulo?.title).toBe('Código');
    expect(e.etiquetas.map((c) => c.title)).toEqual(['Estado']);
    expect(e.campos.map((c) => c.title)).toEqual(['NIF', 'Saldo']);
    expect(e.accoes?.key).toBe('accoes');
  });

  it('respeita `principal`, `noCartao` e o máximo de campos', () => {
    const c: ColunaVista<Cliente>[] = [
      { title: 'Nome', dataIndex: 'nome' },
      { title: 'NIF', dataIndex: 'nif', principal: true },
      { title: 'Saldo', dataIndex: 'saldo' },
      { title: 'Código', dataIndex: 'codigo', noCartao: true },
    ];
    const e = derivarEstrutura(c, 1);
    expect(e.titulo?.title).toBe('NIF');
    expect(e.campos.map((x) => x.title)).toEqual(['Código']);
  });
});

describe('TabelaComModos', { timeout: 30_000 }, () => {
  it('em portátil começa em «Linhas» (tabela) e alterna para «Grade» (cartões)', async () => {
    comCliente(<TabelaComModos<Cliente> rowKey="id" columns={colunas} dataSource={linhas} />);
    expect(screen.getByRole('button', { name: 'Vista em linhas' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getAllByRole('row').length).toBeGreaterThan(1);
    fireEvent.click(screen.getByRole('button', { name: 'Vista em grade' }));
    await screen.findAllByRole('listitem', { name: 'Alfa, Lda' }, { timeout: 10_000 });
    const lista = document.querySelector('.erp-grade-cartoes') as HTMLElement;
    expect(screen.queryByRole('table')).toBeNull();
    const cartoes = within(lista).getAllByRole('listitem');
    expect(cartoes).toHaveLength(2);
    const alfa = within(lista).getByRole('listitem', { name: 'Alfa, Lda' });
    expect(within(alfa).getByText('C001')).toBeInTheDocument();
    expect(within(alfa).getByText('Activo')).toBeInTheDocument();
    expect(within(alfa).getByText('NIF')).toBeInTheDocument();
    expect(within(alfa).getByText((1234.5).toLocaleString('pt-PT', { minimumFractionDigits: 2 }))).toBeInTheDocument();
    expect(within(alfa).queryByText('Interno')).toBeNull();
    expect(screen.getByRole('button', { name: 'Vista em grade' })).toHaveAttribute('aria-pressed', 'true');
  });

  it('as acções da coluna de acções funcionam no cartão', async () => {
    comCliente(<TabelaComModos<Cliente> rowKey="id" columns={colunas} dataSource={linhas} onRow={(r) => ({ onClick: () => abrir(r.id) })} />);
    fireEvent.click(screen.getByRole('button', { name: 'Vista em grade' }));
    const beta = await screen.findByRole('listitem', { name: 'Beta, SA' }, { timeout: 10_000 });
    fireEvent.click(within(beta).getByRole('button', { name: 'Editar' }));
    fireEvent.click(within(beta).getByRole('button', { name: 'Eliminar' }));
    expect(editar).toHaveBeenCalledWith(2);
    expect(eliminar).toHaveBeenCalledWith(2);
    // clicar numa acção não abre o registo; clicar no cartão abre
    expect(abrir).not.toHaveBeenCalled();
    fireEvent.click(within(beta).getByText('Beta, SA'));
    expect(abrir).toHaveBeenCalledWith(2);
  });

  it('guarda a escolha no servidor (por ecrã) e no navegador', async () => {
    comCliente(<TabelaComModos<Cliente> rowKey="id" columns={colunas} dataSource={linhas} />);
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/sistema/preferencias/interface'), { timeout: 10_000 });
    fireEvent.click(screen.getByRole('button', { name: 'Vista em grade' }));
    await waitFor(() => expect(enviar).toHaveBeenCalled(), { timeout: 10_000 });
    const [metodo, url, corpo] = enviar.mock.calls[0];
    expect(metodo).toBe('put');
    expect(url).toBe(`/sistema/preferencias/interface/${NOME_PREFERENCIA_VISTAS}`);
    expect(corpo).toEqual({ valor: { ecras: { '/m/vendas/vendas_clientes': 'grade' } } });
    const local = JSON.parse(window.localStorage.getItem(CHAVE_LOCAL_VISTAS) ?? '{}');
    expect(local.valor.ecras['/m/vendas/vendas_clientes']).toBe('grade');
    await waitFor(() => expect(JSON.parse(window.localStorage.getItem(CHAVE_LOCAL_VISTAS) ?? '{}').pendente).toBe(false), { timeout: 10_000 });
  });

  it('lê a preferência do servidor (escolha do ecrã e omissão global)', async () => {
    servidor = [{ nome: NOME_PREFERENCIA_VISTAS, valor: { omissao: 'grade', ecras: { '/m/outro': 'linhas' } } }];
    comCliente(<TabelaComModos<Cliente> rowKey="id" columns={colunas} dataSource={linhas} />);
    expect(await screen.findByRole('listitem', { name: 'Alfa, Lda' }, { timeout: 10_000 })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Vista em grade' })).toHaveAttribute('aria-pressed', 'true');
  });

  it('sem servidor (offline) usa e mantém a escolha do navegador', async () => {
    obter.mockRejectedValue(new Error('Network Error'));
    enviar.mockRejectedValue(new Error('Network Error'));
    window.localStorage.setItem(CHAVE_LOCAL_VISTAS, JSON.stringify({ valor: { ecras: { '/m/vendas/vendas_clientes': 'grade' } }, pendente: true }));
    // o estado local é lido no arranque; simula-se um novo carregamento da página
    recarregarPreferenciasVistaLocal();
    comCliente(<TabelaComModos<Cliente> rowKey="id" columns={colunas} dataSource={linhas} />);
    expect(await screen.findByRole('listitem', { name: 'Alfa, Lda' }, { timeout: 10_000 })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Vista em linhas' }));
    await waitFor(() => expect(screen.getByRole('table')).toBeInTheDocument(), { timeout: 10_000 });
    expect(JSON.parse(window.localStorage.getItem(CHAVE_LOCAL_VISTAS) ?? '{}')).toEqual({ valor: { ecras: { '/m/vendas/vendas_clientes': 'linhas' } }, pendente: true });
  });

  it('em telemóvel a omissão é a grade', async () => {
    simularLargura(375);
    comCliente(<TabelaComModos<Cliente> rowKey="id" columns={colunas} dataSource={linhas} />);
    expect(await screen.findByRole('listitem', { name: 'Alfa, Lda' }, { timeout: 10_000 })).toBeInTheDocument();
  });

  it('omissão global pelo menu «Opções da vista»', async () => {
    comCliente(<TabelaComModos<Cliente> rowKey="id" columns={colunas} dataSource={linhas} />);
    fireEvent.click(screen.getByRole('button', { name: 'Opções da vista' }));
    fireEvent.click(await screen.findByText('Grade em todos os ecrãs', undefined, { timeout: 10_000 }));
    await waitFor(() => expect(enviar).toHaveBeenCalled(), { timeout: 10_000 });
    expect(enviar.mock.calls[0][2]).toEqual({ valor: { omissao: 'grade' } });
    expect(await screen.findByRole('listitem', { name: 'Alfa, Lda' }, { timeout: 10_000 })).toBeInTheDocument();
  });

  it('cartão personalizado e paginação local da grade', async () => {
    const muitas = Array.from({ length: 30 }, (_, i) => ({ ...linhas[0], id: i + 1, nome: `Cliente ${i + 1}` }));
    comCliente(
      <TabelaComModos<Cliente>
        rowKey="id"
        columns={colunas}
        dataSource={muitas}
        modoOmissao="grade"
        pagination={{ pageSize: 10 }}
        cartao={{ render: (l, _i, { accoes }) => <div data-testid="meu-cartao">{l.nome}{accoes}</div> }}
      />,
    );
    expect(await screen.findAllByTestId('meu-cartao', undefined, { timeout: 10_000 })).toHaveLength(10);
    await act(async () => {
      fireEvent.click(screen.getByTitle('3'));
    });
    expect(screen.getByText('Cliente 21')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Editar' })).toHaveLength(10);
  });

  it('`modos={false}` mantém só a tabela, sem alternância', () => {
    comCliente(<TabelaComModos<Cliente> modos={false} rowKey="id" columns={colunas} dataSource={linhas} />);
    expect(screen.queryByRole('button', { name: 'Vista em grade' })).toBeNull();
    expect(screen.getByRole('table')).toBeInTheDocument();
  });

  it('funciona sem QueryClientProvider (só o navegador)', () => {
    render(<TabelaComModos<Cliente> rowKey="id" columns={colunas} dataSource={linhas} />);
    fireEvent.click(screen.getByRole('button', { name: 'Vista em grade' }));
    expect(screen.getByRole('listitem', { name: 'Alfa, Lda' })).toBeInTheDocument();
  });
});
