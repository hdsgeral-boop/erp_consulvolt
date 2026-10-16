import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from 'antd';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
const obterPagina = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: (...a: unknown[]) => obterPagina(...a),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null, menu: [], preferencias: {} }) }));

import { ModalProjecto } from './ModalProjecto';
import { SeparadorHoras } from './separadores/Horas';
import { SeparadorOrcamento } from './separadores/Orcamento';
import { SeparadorEquipa } from './separadores/Equipa';
import { SeparadorOrganigrama } from './separadores/Organigrama';
import type { PropsSeparador } from './DetalheProjecto';
import type { FichaProjecto } from './comum/tipos';

// os separadores completos (tabelas, gráficos) são lentos no jsdom, sobretudo com a máquina carregada
vi.setConfig({ testTimeout: 420_000 });

// dados fictícios
const PROJECTO = { id: 1, codigo: 'PRJ-T', nome: 'Obra fictícia', tipo: 'INTERNO', estado: 'ACTIVO' } as unknown as FichaProjecto;
const ACC = { gerir: true, execucao: true, eliminar: true, revisao: true } as unknown as PropsSeparador['acc'];
const TAREFA = { id: 11, codigo: null, nome: 'Fundações', nivel: 0, estado: 'PENDENTE', subtarefas: [], data_inicio: null, data_fim: null };
const DADOS: Record<string, unknown> = {
  '/projetos/1/horas': [],
  '/projetos/1/equipamentos': { usos: [], total: '0.00', por_equipamento: [], afetacoes: [] },
  '/projetos/1/wbs': { grupos: [{ marco: null, execucao: 0, tarefas: [TAREFA] }] },
  '/projetos/1/equipa': { equipa: { id: 1, nome: 'Equipa fictícia' }, membros: [{ id: 21, nome: 'Ana Fictícia', papel: null, tipo: 'INTERNO', colaborador_id: 5, horas_alocadas: '8', posicao: null, nif: null }] },
  '/projetos/1/orcamento': { total: '0.00', linhas: [] },
  '/projetos/1/aditamentos': { total_aprovado: '0.00', aditamentos: [] },
  '/projetos/1/custos-tarefas': {},
  '/projetos/1/organigrama': { posicoes: [], sem_posicao: [], totais: { orcamento: '0', executado: '0', vagas: 0, membros: 0, alocados: 0, tarefas_sem_posicao: 0, orcamento_sem_responsavel: 0 } },
  '/sistema/unidades-negocio': [{ id: 1, codigo: 'UN1', nome: 'Produção' }],
  '/contabilidade/tabelas/centros-custo': [{ id: 2, codigo: 'CC1', descricao: 'Fábrica' }],
};

function ecra(ui: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={cliente}><App>{ui}</App></QueryClientProvider>);
}

const escrever = (el: HTMLElement, v: string) => fireEvent.change(el, { target: { value: v } });

/** Passa à vista Linhas, cola as linhas pelo «Colar do Excel», grava e confirma. */
async function colarEGravar(texto: string) {
  fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
  await screen.findByTestId('painel-linhas');
  fireEvent.click(screen.getByRole('button', { name: /Colar do Excel/ }));
  escrever(await screen.findByLabelText('Linhas a colar'), texto);
  fireEvent.click(screen.getByRole('button', { name: 'Acrescentar linhas' }));
  fireEvent.click(await screen.findByRole('button', { name: /Registar linhas/ }));
  fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
}

const posts = (url: string) => enviar.mock.calls.filter((c) => c[0] === 'post' && c[1] === url).map((c) => c[2]);

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(url.startsWith('/sistema/preferencias') ? [...prefs] : (DADOS[url] ?? null)));
  obterPagina.mockReset().mockResolvedValue({ itens: [], paginacao: { pagina_atual: 1, por_pagina: 500, total: 0, ultima_pagina: 1 } });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('Projectos — modais de criação com «Grade | Linhas»', () => {
  it('«Novo projecto» em Linhas grava um projecto por linha pelo mesmo POST (calendário por omissão)', async () => {
    ecra(<ModalProjecto aberto aoFechar={() => undefined} />);
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/contabilidade/tabelas/centros-custo'));
    await colarEGravar('INTERNO\t\tObra A\tUN1 — Produção\tCC1 — Fábrica\t\t\tEm preparação\nINTERNO\tOB-2\tObra B\tUN1\tCC1\t\t\tActivo');
    await waitFor(() => expect(posts('/projetos')).toHaveLength(2));
    expect(posts('/projetos')[0]).toEqual(expect.objectContaining({
      tipo: 'INTERNO', codigo: null, nome: 'Obra A', unidade_negocio_id: 1, centro_custo_id: 2, cliente_id: null, encomenda_venda_id: null, estado: 'PREPARACAO',
      calendario: { modelo: 'SEG_SEX', horas_dia: 8, dias: expect.any(Array) },
    }));
    expect(posts('/projetos')[1]).toEqual(expect.objectContaining({ codigo: 'OB-2', nome: 'Obra B', estado: 'ACTIVO' }));
  });

  it('«Novo projecto»: uma linha interna sem unidade de negócio não grava nada', async () => {
    ecra(<ModalProjecto aberto aoFechar={() => undefined} />);
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/sistema/unidades-negocio'));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    escrever(await screen.findByLabelText('Designação, linha 1'), 'Obra sem UN');
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    expect(await screen.findByText(/Unidade de negócio obrigatória num projecto interno/)).toBeInTheDocument();
    expect(posts('/projetos')).toHaveLength(0);
  });

  it('editar o projecto: só a grade, com o calendário recolhido', async () => {
    ecra(<ModalProjecto aberto projecto={{ ...PROJECTO, calendario: null } as unknown as FichaProjecto} aoFechar={() => undefined} />);
    const dialogo = await screen.findByRole('dialog');
    expect(within(dialogo).getByText('Editar projecto PRJ-T')).toBeInTheDocument();
    expect(within(dialogo).queryByRole('button', { name: /Linhas/ })).toBeNull();
    const calendario = within(dialogo).getByRole('button', { name: /Calendário de trabalho/ });
    expect(calendario).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(calendario);
    expect(calendario).toHaveAttribute('aria-expanded', 'true');
  });

  it('«Registar horas» em Linhas: um registo por linha (membro, tarefa, data, horas)', async () => {
    ecra(<SeparadorHoras projecto={PROJECTO} acc={ACC} />);
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/projetos/1/equipa'));
    fireEvent.click(await screen.findByRole('button', { name: /Registar horas/ }));
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/projetos/1/wbs'));
    await colarEGravar('Ana Fictícia\tFundações\t01/10/2026\t6\nAna Fictícia\tFundações\t02/10/2026\t7,5');
    await waitFor(() => expect(posts('/projetos/1/horas')).toHaveLength(2));
    expect(posts('/projetos/1/horas')).toEqual([
      { membro_equipa_projeto_id: 21, tarefa_projeto_id: 11, data: '2026-10-01', horas: 6 },
      { membro_equipa_projeto_id: 21, tarefa_projeto_id: 11, data: '2026-10-02', horas: 7.5 },
    ]);
  });

  it('«Novo aditamento» em Linhas grava cada aditamento com o estado por omissão', async () => {
    ecra(<SeparadorOrcamento projecto={PROJECTO} acc={ACC} />);
    fireEvent.click(await screen.findByRole('button', { name: /Aditamento/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    escrever(await screen.findByLabelText('Descrição do trabalho, linha 1'), 'Muro extra');
    escrever(screen.getByLabelText('Valor, linha 1'), '1500');
    escrever(screen.getByLabelText('Descrição do trabalho, linha 2'), 'Menos pintura');
    escrever(screen.getByLabelText('Valor, linha 2'), '-200');
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    await waitFor(() => expect(posts('/projetos/1/aditamentos')).toHaveLength(2));
    expect(posts('/projetos/1/aditamentos')).toEqual([
      { descricao: 'Muro extra', montante: 1500, estado: 'PENDENTE' },
      { descricao: 'Menos pintura', montante: -200, estado: 'PENDENTE' },
    ]);
  });

  it('«Adicionar membro» em Linhas: cada linha leva só a coluna do tipo escolhido', async () => {
    ecra(<SeparadorEquipa projecto={PROJECTO} acc={ACC} />);
    fireEvent.click(await screen.findByRole('button', { name: /Adicionar membro/ }));
    await colarEGravar('LIVRE\t\t\tJoão Livre\tEncarregado\t6');
    await waitFor(() => expect(posts('/projetos/1/equipa/membros')).toHaveLength(1));
    expect(posts('/projetos/1/equipa/membros')[0]).toEqual({ tipo: 'LIVRE', colaborador_id: null, terceiro_id: null, nome_externo: 'João Livre', papel: 'Encarregado', horas_alocadas: 6 });
  });

  it('«Nova posição» em Linhas grava uma posição por linha', async () => {
    ecra(<SeparadorOrganigrama projecto={PROJECTO} acc={ACC} />);
    fireEvent.click(await screen.findByRole('button', { name: /Nova posição/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }));
    escrever(await screen.findByLabelText('Título, linha 1'), 'Director de obra');
    escrever(screen.getByLabelText('Título, linha 2'), 'Encarregado');
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    await waitFor(() => expect(posts('/projetos/1/organigrama/posicoes')).toHaveLength(2));
    expect(posts('/projetos/1/organigrama/posicoes')[0]).toEqual({ titulo: 'Director de obra', area: null, descricao: null, vagas: 1, no_pai_id: null, cor: 'azul', apoio: false });
  });
});
