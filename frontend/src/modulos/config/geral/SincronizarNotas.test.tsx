import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { Modal } from 'antd';

// renderização do Ant Design em jsdom é lenta quando a máquina está carregada
vi.setConfig({ testTimeout: 60_000 });

const base = {
  linhas_a_atribuir: 15,
  por_nota: [
    {
      nota: '4',
      nota_demonstracao_id: 11,
      descricao: 'Imobilizações corpóreas',
      linhas: 12,
      contas: ['1111', '1112', '1113', '1114', '1115', '1116', '1117'].map((c, i) => ({ codigo_conta: c, linhas: i === 0 ? 6 : 1 })),
    },
    { nota: '22', nota_demonstracao_id: 22, descricao: 'Custo das mercadorias', linhas: 3, contas: [{ codigo_conta: '6111', linhas: 3 }] },
  ],
  sem_regra: [{ prefixo: '88', linhas: 2 }],
  nota_em_falta: [{ nota: '35', linhas: 1 }],
  exercicios_encerrados: [2024],
  linhas_em_exercicios_encerrados: 4,
};
const enviar = vi.fn(async (_m: string, _u: string, d?: unknown) => ({
  dados: { ...base, aplicado: !!(d as { aplicar?: boolean })?.aplicar },
  mensagem: 'ok',
}));
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  enviar: (m: string, u: string, d?: unknown) => enviar(m, u, d),
  obter: vi.fn(async () => ({ logotipo: null })),
  descarregar: vi.fn(),
}));

let permissoes: string[] = [];
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: (...c: string[]) => c.some((x) => permissoes.includes(x)), utilizador: { papel: 'ADMINISTRADOR' }, empresa: { id: 1, nome: 'Empresa Teste' }, empresas: [] }),
}));
vi.mock('../comum/NavConfig', () => ({ NavConfig: () => null }));
vi.mock('../UnidadesNegocio', () => ({ UnidadesNegocio: () => null }));

import ConfigGeral from '../ConfigGeral';
import { SincronizarNotas, URL_SINCRONIZAR_NOTAS, amostraContas } from './SincronizarNotas';

const cartao = () => within(screen.getByText('Recuperar Dados Contabilísticos').closest('.ant-card') as HTMLElement);
const comCliente = (no: ReactNode) => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{no}</QueryClientProvider>);

describe('Recuperar Dados Contabilísticos — Sincronizar Notas Automática (legado js/app_v2.js:8676-8680, :9785)', () => {
  beforeEach(() => {
    enviar.mockClear();
    permissoes = [];
  });
  afterEach(() => {
    // os diálogos de Modal.confirm ficam no body entre testes (por isso os botões procuram-se dentro do cartão)
    Modal.destroyAll();
  });

  it('amostra de contas limita a 5 e conta as restantes', () => {
    expect(amostraContas(base.por_nota[0].contas)).toBe('1111 (6), 1112 (1), 1113 (1), 1114 (1), 1115 (1) +2');
    expect(amostraContas(base.por_nota[1].contas)).toBe('6111 (3)');
  });

  it('o cartão só aparece com config_ferramentas', () => {
    permissoes = ['config_backup'];
    const { unmount } = comCliente(<ConfigGeral />);
    expect(screen.queryByText('Recuperar Dados Contabilísticos')).toBeNull();
    unmount();
    permissoes = ['config_ferramentas'];
    comCliente(<ConfigGeral />);
    expect(screen.getByText('Recuperar Dados Contabilísticos')).toBeInTheDocument();
    expect(screen.getByText(/Associa automaticamente as «Notas às Demonstrações» aos Lançamentos antigos/)).toBeInTheDocument();
  });

  it('simular mostra linhas e contas por nota, amostra e o que fica por tratar, sem gravar', async () => {
    comCliente(<SincronizarNotas />);
    expect(cartao().getByRole('button', { name: /Aplicar/ })).toBeDisabled();
    fireEvent.click(cartao().getByRole('button', { name: /Simular/ }));
    await waitFor(() => expect(enviar).toHaveBeenCalledTimes(1));
    expect(enviar.mock.calls[0]).toEqual(['post', URL_SINCRONIZAR_NOTAS, { aplicar: false }]);
    expect(await screen.findByText('Simulação: 15 linha(s) sem nota receberiam nota pela conta. Nada foi gravado.')).toBeInTheDocument();
    const linhaNota4 = screen.getByText('Imobilizações corpóreas').closest('tr')!;
    expect(within(linhaNota4).getByText('12')).toBeInTheDocument();
    expect(within(linhaNota4).getByText('7')).toBeInTheDocument();
    expect(within(linhaNota4).getByText('1111 (6), 1112 (1), 1113 (1), 1114 (1), 1115 (1) +2')).toBeInTheDocument();
    expect(screen.getByText('88: 2')).toBeInTheDocument();
    expect(screen.getByText('Nota 35: 1')).toBeInTheDocument();
    expect(screen.getByText(/4 linha\(s\) \(2024\)/)).toBeInTheDocument();
    expect(cartao().getByRole('button', { name: /Aplicar/ })).toBeEnabled();
  });

  it('aplicar pede confirmação e só grava depois de confirmar', async () => {
    comCliente(<SincronizarNotas />);
    fireEvent.click(cartao().getByRole('button', { name: /Simular/ }));
    await screen.findByText(/Simulação: 15 linha/);
    fireEvent.click(cartao().getByRole('button', { name: /Aplicar/ }));
    const dialogo = await screen.findByRole('dialog');
    expect(within(dialogo).getAllByText('Sincronizar Notas Automática?').length).toBeGreaterThan(0);
    expect(enviar).toHaveBeenCalledTimes(1);
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Aplicar' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledTimes(2));
    expect(enviar.mock.calls[1]).toEqual(['post', URL_SINCRONIZAR_NOTAS, { aplicar: true }]);
    expect((await screen.findAllByText('Sucesso! 15 linhas de Lançamento foram atualizadas com as notas correspondentes.')).length).toBeGreaterThan(0);
  });

  it('sem nada a mapear mostra a mensagem do legado e não deixa aplicar', async () => {
    enviar.mockResolvedValueOnce({ dados: { ...base, aplicado: false, linhas_a_atribuir: 0, por_nota: [], nota_em_falta: [], sem_regra: [], linhas_em_exercicios_encerrados: 0 }, mensagem: 'ok' });
    comCliente(<SincronizarNotas />);
    fireEvent.click(cartao().getByRole('button', { name: /Simular/ }));
    expect(await screen.findByText('Nenhum Lançamento pendente de atualização foi encontrado com os critérios de prefixo.')).toBeInTheDocument();
    expect(cartao().getByRole('button', { name: /Aplicar/ })).toBeDisabled();
  });
});
