import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Form, Input } from 'antd';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn(),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null }) }));

import { CadastroSimples } from './CadastroSimples';

vi.setConfig({ testTimeout: 120_000 });

interface Linha { id: number; nome: string }

function ecra() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <CadastroSimples<Linha>
        url="/rh/cargos" chave={['rh', 'cargos']} nomeItem="função" rotuloNovo="Nova Função" tituloNovo="Adicionar Nova Função" podeGerir podeEliminar
        colunas={[{ title: 'Nome', dataIndex: 'nome' }]}
        campos={<Form.Item name="nome" label="Nome da Função" rules={[{ required: true }]}><Input /></Form.Item>}
        linhas={{ chave: 'rh.funcoes', campos: [{ nome: 'nome', rotulo: 'Nome da função', obrigatorio: true }], nomeRegistos: 'funções' }}
      />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  // preferências: o servidor devolve o que foi gravado (como o PUT /sistema/preferencias/interface/{nome})
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(url.startsWith('/sistema/preferencias') ? [...prefs] : [{ id: 1, nome: 'Director' }]));
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('Funções (piloto Grade | Linhas)', () => {
  it('«Nova Função» em Linhas grava uma função por linha pelo mesmo POST; editar só tem a grade', async () => {
    ecra();
    fireEvent.click(await screen.findByRole('button', { name: /Nova Função/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    fireEvent.change(await screen.findByLabelText('Nome da função, linha 1'), { target: { value: 'Contabilista' } });
    fireEvent.change(screen.getByLabelText('Nome da função, linha 2'), { target: { value: 'Motorista' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    const posts = () => enviar.mock.calls.filter((c) => c[1] === '/rh/cargos');
    await waitFor(() => expect(posts()).toHaveLength(2));
    expect(posts()).toEqual([['post', '/rh/cargos', { nome: 'Contabilista' }], ['post', '/rh/cargos', { nome: 'Motorista' }]]);
    await waitFor(() => expect(screen.queryByTestId('painel-linhas')).toBeNull());

    fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
    expect(await screen.findByText('Editar — função')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
  });
});
