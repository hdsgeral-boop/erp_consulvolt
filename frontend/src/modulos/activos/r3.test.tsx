import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { accoesActivo } from './comum/regras';
import { eAlheio, textoContrato } from './comum/propriedade';
import { formaEdificio, ordenarParaDesenho, pisosComAndar, pisosComPortas, rotuloPorta } from './edificios/regras';
import type { PisoDesenho } from './edificios/tipos';
import { podeConcluirTarefas, progressoTarefas, tarefasParaEnviar, textoProgresso } from './manutencao/regras';
import { dadosOrdemAvulsa } from './manutencao/Ordens';
import { dadosActivo, obrigatorioSeProprio } from './ModalActivo';

vi.setConfig({ testTimeout: 180_000 });

// dados fictícios (r3_activos)
const PISOS: PisoDesenho[] = [
  { id: 10, numero: '0', nome: 'Rés-do-chão', ordem: 0, portas: [{ id: 100, numero: 'A1', n_bens: 2 }, { id: 101, numero: 'A2', n_bens: 0 }] },
  { id: 11, numero: '1', nome: '1.º andar', ordem: 1, portas: [{ id: 110, numero: 'B1', n_bens: 0 }] },
  { id: 9, numero: '-1', nome: 'Cave', ordem: -1, portas: [] },
];

describe('Propriedade do bem (próprio / alheio)', () => {
  it('alheio não abate nem integra; texto do contrato; obrigatórios só nos próprios', () => {
    const todas = () => true;
    expect(accoesActivo({ estado: 'ACTIVO', propriedade: 'ALHEIO' }, todas)).toMatchObject({ editar: true, abater: false, integrar: false });
    expect(accoesActivo({ estado: 'ACTIVO', propriedade: 'PROPRIO' }, todas)).toMatchObject({ abater: true, integrar: true });
    expect(eAlheio({ propriedade: null })).toBe(false);
    expect(textoContrato({ regime_alheio: 'ARRENDADO', proprietario_nome: 'Senhorio fictício', contrato_referencia: 'CT-1', contrato_inicio: null, contrato_fim: '2028-01-31' }))
      .toBe('Arrendado / alugado · Senhorio fictício · contrato CT-1 · até 31/01/2028');
    expect(obrigatorioSeProprio(null, { propriedade: 'ALHEIO' })).toBeNull();
    expect(obrigatorioSeProprio('', {})).toMatch(/obrigatórios num bem próprio/);
    expect(obrigatorioSeProprio('2026-01-01', {})).toBeNull();
  });

  it('gravação: alheio sem campos de amortização; próprio sem dados do contrato', () => {
    const alheio = dadosActivo({ descricao: 'X', propriedade: 'ALHEIO', vida_util: 48, quota_fixa: 10, contrato_inicio: '2026-02-01', regime_alheio: 'CEDIDO' });
    expect(alheio).toMatchObject({ propriedade: 'ALHEIO', contrato_inicio: '2026-02-01', regime_alheio: 'CEDIDO' });
    expect(alheio).not.toHaveProperty('vida_util');
    expect(alheio).not.toHaveProperty('quota_fixa');
    const proprio = dadosActivo({ descricao: 'Y', propriedade: 'PROPRIO', proprietario_nome: 'Z', vida_util: 12 });
    expect(proprio).not.toHaveProperty('proprietario_nome');
    expect(proprio).toMatchObject({ vida_util: 12 });
  });
});

describe('Desenho do edifício', () => {
  it('último andar em cima, caves em baixo; casa até um piso, prédio com mais', () => {
    expect(ordenarParaDesenho(PISOS).map((p) => p.nome)).toEqual(['1.º andar', 'Rés-do-chão', 'Cave']);
    expect(formaEdificio(PISOS)).toBe('PREDIO');
    expect(formaEdificio([PISOS[0], PISOS[2]])).toBe('CASA');
    expect(rotuloPorta(PISOS[0].portas[0], PISOS[0])).toBe('Porta A1 — Rés-do-chão, 2 bens');
  });

  it('pré-visualização: andar novo em destaque e portas novas sem repetir as existentes', () => {
    const comAndar = pisosComAndar(PISOS, { numero: '2', nome: '' });
    expect(comAndar.find((p) => p.novo)).toMatchObject({ nome: '2.º andar', ordem: 2, destaque: true });
    expect(ordenarParaDesenho(comAndar)[0].novo).toBe(true);
    const editado = pisosComAndar(PISOS, { numero: '1', nome: 'Escritórios' }, 11);
    expect(editado.find((p) => p.id === 11)).toMatchObject({ nome: 'Escritórios', destaque: true });
    expect(editado).toHaveLength(3);
    const comPortas = pisosComPortas(PISOS, 10, 'A', 1, 4);
    expect(comPortas.find((p) => p.id === 10)?.portas.map((p) => `${p.numero}${p.nova ? '*' : ''}`)).toEqual(['A1', 'A2', 'A3*', 'A4*']);
    expect(pisosComPortas(PISOS, 10, 'A', 5, 2)).toBe(PISOS);
  });
});

describe('Tarefas da ordem de manutenção', () => {
  it('progresso «N de M», situação derivada e conclusão só sem obrigatórias por fazer', () => {
    const p = progressoTarefas([{ texto: 'a', feito: true }, { texto: 'b', feito: false }, { texto: 'c', feito: false, obrigatoria: false }]);
    expect(p).toMatchObject({ total: 3, feitas: 1, obrigatorias_por_fazer: 1, opcionais_por_fazer: 1, situacao: 'EM_CURSO', percentagem: 33 });
    expect(textoProgresso(p)).toBe('1 de 3 feitas');
    expect(podeConcluirTarefas(p)).toBe(false);
    expect(progressoTarefas([{ texto: 'a', feito: true }, { texto: 'c', feito: false, obrigatoria: false }]).situacao).toBe('PRONTA');
    expect(progressoTarefas([]).situacao).toBe('SEM_TAREFAS');
    expect(tarefasParaEnviar(['  Desligar  ', '', { texto: 'Limpar', obrigatoria: false }, { texto: ' ' }])).toEqual([
      { texto: 'Desligar', obrigatoria: true }, { texto: 'Limpar', obrigatoria: false }]);
    expect(dadosOrdemAvulsa({ ativo_imobilizado_id: 1, tipo: 'CORRECTIVA', data_prevista: '2026-10-01', descricao: 'x', tarefas: [{ texto: 'T', obrigatoria: true }] }))
      .toMatchObject({ tarefas: [{ texto: 'T', obrigatoria: true }] });
    expect(dadosOrdemAvulsa({ ativo_imobilizado_id: 1, tipo: 'CORRECTIVA', data_prevista: '2026-10-01', descricao: 'x' })).not.toHaveProperty('tarefas');
  });
});

// ───────────── ecrãs ─────────────

const RESUMO = {
  edificios: [{ id: 1, codigo: 'AST-100', descricao: 'Sede fictícia', estado: 'ACTIVO', andares: 2, portas: 3, bens: 2, valor_bens: '4500.00', inventarios: 0,
    propriedade: 'ALHEIO', regime_alheio: 'ARRENDADO', pisos: PISOS.slice(0, 2) }],
  sem_local: 0, inventarios: 0,
};
const PLANTA = {
  edificio: { id: 1, codigo: 'AST-100', descricao: 'Sede fictícia', estado: 'ACTIVO' },
  andares: [{ id: 10, numero: '0', nome: 'Rés-do-chão', notas: null, ordem: 0, n_portas: 1, n_bens: 2, valor: '4500.00',
    portas: [{ id: 100, numero: 'A1', nome: null, tipo: 'Gabinete', capacidade: null, ordem: 1, andar_id: 10, n_bens: 2, valor: '4500.00',
      bens: [{ id: 5, codigo: 'AST-005', descricao: 'Secretária fictícia', valor_aquisicao: '1500.00', categoria: 'Mobiliário' },
        { id: 6, codigo: 'AST-006', descricao: 'Armário fictício', valor_aquisicao: '3000.00', categoria: 'Mobiliário' }] }] }],
  sem_local: [], outros_edificios: [], totais: { andares: 1, portas: 1, bens: 2, valor: '4500.00', sem_porta: 0 },
};
const ORDEM = {
  id: 7, numero: 'OM 2026/0007', ativo_imobilizado_id: 9, plano_manutencao_id: null, tipo: 'CORRECTIVA', prioridade: 'NORMAL', estado: 'EM_CURSO',
  data_prevista: '2026-10-10', data_inicio: '2026-10-10', data_fim: null, descricao: 'Avaria fictícia', responsavel: null, horas: '0', custo_mao_obra: '0', custo_materiais: '0',
  custo_servicos: '0', resolucao: null, leitura_contador: null, motivo_cancelamento: null, custo_total: '0.00',
  tarefas: [{ id: 't1', texto: 'Desligar quadro', obrigatoria: true, feito: true, feito_por: 'tecnico', feito_em: '2026-10-10T09:15:00+01:00' },
    { id: 't2', texto: 'Medir isolamento', obrigatoria: true, feito: false }, { id: 't3', texto: 'Limpar sala', obrigatoria: false, feito: false }],
  progresso: { total: 3, feitas: 1, obrigatorias: 2, obrigatorias_por_fazer: 1, opcionais_por_fazer: 1, percentagem: 33, situacao: 'EM_CURSO' },
  historico: [{ em: '2026-10-10T09:15:00+01:00', por: 'tecnico', accao: 'TAREFA_CONCLUIDA', tarefa: 't1', texto: 'Desligar quadro' }],
  itens: [], por_pedir: 0, requisicoes: [], pedidos_compra: [], custo_servicos_sugerido: '0.00',
  ativo_imobilizado: { id: 9, codigo: 'AST-009', descricao: 'Gerador fictício' },
};

const obterMock = vi.fn();
const enviarMock = vi.fn((..._a: unknown[]) => Promise.resolve({ dados: ORDEM, mensagem: 'ok' }));
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obterMock(...a),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { total: 0, pagina: 1, por_pagina: 50, ultima_pagina: 1 } }),
  enviar: (...a: unknown[]) => enviarMock(...a),
  descarregar: () => Promise.resolve(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [], empresa: { id: 1, nome: 'Empresa Fictícia' }, utilizador: null, preferencias: {} }),
}));

const envolver = (ui: ReactNode, caminho = '/') =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[caminho]}>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );

beforeEach(() => {
  obterMock.mockReset();
  enviarMock.mockClear();
  obterMock.mockImplementation((url: string) => Promise.resolve(url === '/ativos/edificios' ? RESUMO : url.endsWith('/planta') ? PLANTA
    : url.startsWith('/ativos/manutencao/ordens/') ? ORDEM : []));
});

describe('Ecrãs (r3_activos)', () => {
  it('edifício desenhado com andares e portas; a porta mostra os bens; acções sempre à vista', async () => {
    permissoes = new Set(['activos_edificios_view', 'activos_inventario_fisico']);
    const { default: Edificios } = await import('./edificios/Edificios');
    envolver(<Edificios />);
    const desenho = await screen.findByRole('group', { name: /Desenho do edifício AST-100/ }, { timeout: 60_000 });
    expect(within(desenho).getAllByRole('listitem').map((l) => l.getAttribute('aria-label'))).toEqual([
      '1.º andar: 1 porta(s), 0 bem(ns)', 'Rés-do-chão: 2 porta(s), 2 bem(ns)']);
    expect(screen.getByText('Arrendado / alugado')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Planta/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Inventário' })).toBeTruthy();
    fireEvent.click(within(desenho).getByRole('button', { name: 'Porta A1 — Rés-do-chão, 2 bens' }));
    expect(await screen.findByText('Armário fictício', undefined, { timeout: 30_000 })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Abrir a planta do edifício/ })).toBeTruthy();
  });

  it('ordem: progresso, «Concluir» bloqueado com obrigatórias por fazer e marcar tarefa uma a uma', async () => {
    permissoes = new Set(['activos_manutencao_view', 'activos_manut_gerir']);
    const { DetalheOrdemModal } = await import('./manutencao/Ordens');
    envolver(<DetalheOrdemModal id={7} aoFechar={() => undefined} />);
    expect(await screen.findByText('Medir isolamento', undefined, { timeout: 60_000 })).toBeTruthy();
    expect(screen.getAllByText('1 de 3 feitas').length).toBeGreaterThan(0);
    expect(screen.getByText(/Feita por tecnico em 10\/10\/2026/)).toBeTruthy();
    expect(screen.getByText('opcional')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Concluir/ })).toBeDisabled();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Concluir a tarefa «Medir isolamento»' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/ativos/manutencao/ordens/7/tarefas/t2/estado', { feito: true }));
    const nova = screen.getByRole('textbox', { name: 'Nova tarefa' });
    fireEvent.change(nova, { target: { value: 'Verificar ligações' } });
    fireEvent.keyDown(nova, { key: 'Enter', code: 'Enter', keyCode: 13 });
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/ativos/manutencao/ordens/7/tarefas', { texto: 'Verificar ligações', obrigatoria: true }));
    expect(screen.getByText(/Histórico \(1\)/)).toBeTruthy();
  });
});
