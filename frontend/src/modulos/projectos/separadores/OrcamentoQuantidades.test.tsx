import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const obter = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: () => Promise.resolve({ dados: {}, mensagem: 'ok' }),
  obterPagina: vi.fn(),
  descarregar: vi.fn(),
}));

import { ModalMapearQuantidade } from './OrcamentoQuantidades';
import type { LinhaOrcamento } from '../comum/tipos';

vi.setConfig({ testTimeout: 60_000 });

const LINHA: LinhaOrcamento = { id: 5, tarefa_projeto_id: null, rubrica: 'MAO_DE_OBRA', montante: '1000.00', numero_conta: null, no_organigrama_projeto_id: null, membro_equipa_projeto_id: null };

const tarefa = (id: number, codigo: string, nome: string) => ({
  id, codigo, nome, tarefa_pai_id: null, marco_projeto_id: null, atribuido_a_id: null, valor_contrato: null, ordem: null,
  estado: 'PENDENTE', data_inicio: null, data_fim: null, percentagem_execucao: null, execucao: 0, nivel: 0, horas: 0,
  posicoes: [], subtarefas: [], meta_unidade: 'm2', meta_quantidade: '100', meta_realizado: '0',
});

const DADOS: Record<string, unknown> = {
  '/projetos/1/wbs': { execucao_global: 0, grupos: [{ marco: null, execucao: 0, tarefas: [tarefa(11, 'T1', 'Alvenaria'), tarefa(12, 'T2', 'Pintura')] }] },
  '/projetos/1/orcamento/quantidades': {
    unidades: ['m2'],
    rubricas: [{ linha_id: 5, rubrica: 'MAO_DE_OBRA', montante: 1000, mapeado: 0, saldo: 1000, pct_mapeado: 0, totalmente_mapeada: false, mapas: [] }],
    por_tarefa: [], por_fase: [], por_area: [], por_pessoa: [], total_mapeado: 0,
  },
};

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <ModalMapearQuantidade projectoId={1} linha={LINHA} aoFechar={() => undefined} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(DADOS[url] ?? null));
});

// Lista de tarefas a marcar (escopo «Só as tarefas que eu escolher»): não é uma grelha de valores, mas há um mapa
// local fiável (código/nome → id) para reconhecer o texto colado — a colagem acrescenta à selecção, nunca a substitui.
describe('ModalMapearQuantidade — colagem do Excel na lista de tarefas', () => {
  async function abrirEscolhaDeTarefas() {
    montar();
    fireEvent.mouseDown(await screen.findByRole('combobox', { name: 'Que trabalho entra' }));
    fireEvent.click((await screen.findAllByText('Só as tarefas que eu escolher')).at(-1)!);
    await screen.findByRole('checkbox', { name: /Alvenaria/ });
  }

  it('botão «Colar do Excel»: marca as tarefas coladas pelo código', async () => {
    await abrirEscolhaDeTarefas();
    fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
    fireEvent.change(screen.getByLabelText('Linhas a colar'), { target: { value: 'T1\nT2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
    expect(screen.getByRole('checkbox', { name: /Alvenaria/ })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: /Pintura/ })).toBeChecked();
  });

  it('colagem directa (Ctrl+V) na lista: acrescenta à selecção sem desmarcar as já marcadas', async () => {
    await abrirEscolhaDeTarefas();
    const alvenaria = screen.getByRole('checkbox', { name: /Alvenaria/ });
    fireEvent.click(alvenaria);
    expect(alvenaria).toBeChecked();
    fireEvent.paste(alvenaria, { clipboardData: { getData: () => 'T2' } });
    expect(alvenaria).toBeChecked();
    expect(screen.getByRole('checkbox', { name: /Pintura/ })).toBeChecked();
  });

  it('código não reconhecido não marca nada e avisa', async () => {
    await abrirEscolhaDeTarefas();
    fireEvent.paste(screen.getByRole('checkbox', { name: /Alvenaria/ }), { clipboardData: { getData: () => 'XPTO' } });
    expect(screen.getByRole('checkbox', { name: /Alvenaria/ })).not.toBeChecked();
    expect(screen.getByText(/não reconhecido/)).toBeInTheDocument();
  });
});
