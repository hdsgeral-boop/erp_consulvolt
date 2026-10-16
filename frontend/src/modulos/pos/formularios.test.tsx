import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn(),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: () => true, empresa: { id: 1, nome: 'Empresa' }, empresas: [{ id: 1, nome: 'Empresa' }], utilizador: { id: 1, nome: 'Teste' } }),
}));

import { TabelaPecas, TabelaServicos } from './lavandaria/Tabelas';
import { PainelMesas } from './frente/PainelMesas';
import Terminais from './Terminais';

// antd no jsdom é lento: margem larga por teste (como nos pilotos)
vi.setConfig({ testTimeout: 300_000 });

const PECA = { id: 7, codigo: 'PC001', nome: 'Camisa', tecido: null, cor: null, unidade: 'PECA', preco: '1500', ativo: true, precos_servico: [] };

function ecra(filho: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}>{filho}</QueryClientProvider>);
}

const escrever = (rotulo: string, v: string) => fireEvent.change(screen.getByLabelText(rotulo), { target: { value: v } });
async function registarLinhas() {
  fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
  fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
}
const posts = (url: string) => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === url);

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    if (url === '/pos/lavandaria/pecas') return Promise.resolve([PECA]);
    return Promise.resolve([]);
  });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('POS — cadastros com Grade | Linhas', () => {
  it('«Nova peça» em Linhas grava uma peça por linha pelo mesmo POST', async () => {
    ecra(<TabelaPecas />);
    fireEvent.click(await screen.findByRole('button', { name: /Nova peça/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    escrever('Designação da peça, linha 1', 'Calças');
    escrever('Designação da peça, linha 2', 'Fato');
    escrever('Preço base (IVA incl.), linha 2', '3 500,00');
    await registarLinhas();
    await waitFor(() => expect(posts('/pos/lavandaria/pecas')).toHaveLength(2));
    expect(posts('/pos/lavandaria/pecas').map((c) => c[2])).toEqual([
      { nome: 'Calças', tecido: null, cor: null, unidade: 'PECA', preco: 0, ativo: true, precos_servico: [] },
      { nome: 'Fato', tecido: null, cor: null, unidade: 'PECA', preco: 3500, ativo: true, precos_servico: [] },
    ]);
  });

  it('editar uma peça só tem a grade, com a secção dos preços por serviço', async () => {
    ecra(<TabelaPecas />);
    fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
    expect(await screen.findByText('Peça Camisa')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
    // sem preços específicos a secção abre fechada
    expect(screen.getByRole('button', { name: /Preços específicos por serviço/ })).toHaveAttribute('aria-expanded', 'false');
  });

  it('«Novo serviço» em Linhas grava pelo mesmo POST com as omissões do legado', async () => {
    ecra(<TabelaServicos />);
    fireEvent.click(await screen.findByRole('button', { name: /Novo serviço/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    escrever('Designação do serviço, linha 1', 'Lavar e engomar');
    // a validação da classe 62 nas linhas está em lavandaria/formularios.test.ts
    escrever('Conta de proveitos (62), linha 1', '6211');
    await registarLinhas();
    await waitFor(() => expect(posts('/pos/lavandaria/servicos')).toHaveLength(1));
    expect(posts('/pos/lavandaria/servicos')[0][2]).toMatchObject({ nome: 'Lavar e engomar', grupo: 'LAVANDARIA', codigo_conta: '6211', taxa_imposto: 14, dias_entrega: 2, requer_orcamento: false, ativa: true });
  });

  it('«Criar mesa» em Linhas cria várias mesas do terminal', async () => {
    ecra(<PainelMesas terminal={3} activa={null} aoEscolher={() => undefined} />);
    fireEvent.click(await screen.findByRole('button', { name: /Criar mesa/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    escrever('Nome da mesa, linha 1', 'Mesa 1');
    escrever('Nome da mesa, linha 2', ' Terraço 2 ');
    await registarLinhas();
    await waitFor(() => expect(posts('/pos/terminais/3/mesas')).toHaveLength(2));
    expect(posts('/pos/terminais/3/mesas').map((c) => c[2])).toEqual([{ nome: 'Mesa 1' }, { nome: 'Terraço 2' }]);
  });

  it('«Novo terminal» fica só em grade, com as secções recolhíveis do editor (analítica fechada)', async () => {
    ecra(<Terminais />);
    fireEvent.click(await screen.findByRole('button', { name: /Novo terminal/ }));
    const dialogo = await screen.findByRole('dialog');
    expect(within(dialogo).queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
    expect(within(dialogo).getByRole('button', { name: /Identificação e operação/ })).toHaveAttribute('aria-expanded', 'true');
    const analitica = within(dialogo).getByRole('button', { name: /Contabilidade analítica/ });
    expect(analitica).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(analitica);
    expect(analitica).toHaveAttribute('aria-expanded', 'true');
    expect(within(dialogo).getByRole('button', { name: /Meios de pagamento aceites/ })).toHaveAttribute('aria-expanded', 'true');
    expect(within(dialogo).getByRole('button', { name: /Gravar terminal/ })).toBeInTheDocument();
  });
});
