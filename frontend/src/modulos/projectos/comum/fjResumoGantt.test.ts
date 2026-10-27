import { contarTarefasFase, linhasGanttWbs, numeracaoWbs, ordenarGruposWbs, pct1, ritmoPrazo } from './regras';
import type { TarefaWbs, Wbs } from './tipos';

const tarefa = (id: number, inicio: string | null, subtarefas: TarefaWbs[] = []): TarefaWbs => ({
  id, codigo: null, nome: `T${id}`, tarefa_pai_id: null, marco_projeto_id: null, atribuido_a_id: null, valor_contrato: null, ordem: null, estado: 'PENDENTE',
  data_inicio: inicio, data_fim: inicio, percentagem_execucao: 0, execucao: 0, nivel: 0, horas: 0, posicoes: [], subtarefas,
});
const marco = (id: number, nome: string, data: string, data_inicio: string | null = null) => ({ id, nome, estado: 'PENDENTE', data, data_inicio });

// como o projecto 7: a fase criada primeiro (id 6) tem a data alvo mais tarde e as tarefas mais tarde
const wbs: Wbs = {
  execucao_global: 79,
  grupos: [
    { marco: null, execucao: 0, tarefas: [tarefa(99, '2026-01-01')] },
    { marco: marco(8, 'Alimentação', '2026-09-08'), execucao: 40, tarefas: [tarefa(30, '2026-09-01')] },
    { marco: marco(7, 'Comprar casso', '2026-09-08', '2026-07-01'), execucao: 100, tarefas: [tarefa(20, '2026-07-07')] },
    { marco: marco(6, 'Aluguer de casa', '2026-09-20'), execucao: 88, tarefas: [tarefa(10, '2026-09-08', [tarefa(11, '2026-09-08'), tarefa(12, '2026-09-09', [tarefa(13, '2026-09-10')])])] },
  ],
};

describe('FJ: ordem das fases como no legado (ordem de criação, não datas)', () => {
  it('ordena as fases pelo id e deixa as tarefas sem fase no fim', () => {
    expect(ordenarGruposWbs(wbs.grupos).map((g) => g.marco?.id ?? 'sem')).toEqual([6, 7, 8, 'sem']);
    expect(ordenarGruposWbs(undefined)).toEqual([]);
    // não altera o array recebido
    expect(wbs.grupos[0].marco).toBeNull();
  });

  it('R4: um marco com «ordem» própria (botões ↑/↓) passa à frente dos marcos antigos sem valor', () => {
    const comOrdem: Wbs['grupos'] = [
      { marco: { ...marco(6, 'Aluguer de casa', '2026-09-20'), ordem: null }, execucao: 0, tarefas: [] },
      { marco: { ...marco(7, 'Comprar casso', '2026-09-08'), ordem: null }, execucao: 0, tarefas: [] },
      { marco: { ...marco(8, 'Alimentação', '2026-09-08'), ordem: 5 }, execucao: 0, tarefas: [] },
    ];
    // marco 8 subiu para o topo (ordem 5 < 1 000 000 + 6/7, o fallback dos marcos sem ordem)
    expect(ordenarGruposWbs(comOrdem).map((g) => g.marco?.id)).toEqual([8, 6, 7]);
  });

  it('o Gantt e a numeração WBS seguem a mesma ordem', () => {
    const fases = linhasGanttWbs(wbs).filter((l) => l.tipo === 'marco');
    expect(fases.map((l) => l.nome)).toEqual(['Aluguer de casa', 'Comprar casso', 'Alimentação', 'Sem milestone']);
    expect(fases[0].progresso).toBe(88);
    const n = numeracaoWbs(wbs);
    expect([n.get('m-6'), n.get('m-7'), n.get('m-8'), n.get('m-sem')]).toEqual(['1', '2', '3', '4']);
    expect(n.get('t-13')).toBe('1.1.2.1');
  });

  it('conta as tarefas da fase com as subtarefas («N tarefa(s)» na coluna Responsável)', () => {
    expect(contarTarefasFase(wbs.grupos[3])).toBe(4);
    expect(contarTarefasFase({ tarefas: [] })).toBe(0);
  });
});

describe('FJ: resumo — ritmo do prazo e percentagens', () => {
  it('ritmo = execução ÷ tempo decorrido, com 1 casa e cor pelo limiar do legado', () => {
    expect(ritmoPrazo(1.3766)).toEqual({ texto: '137,7%', cor: '#16a34a' });
    expect(ritmoPrazo(0.95)?.cor).toBe('#d97706');
    expect(ritmoPrazo(0.5)?.cor).toBe('#dc2626');
    expect(ritmoPrazo(null)).toBeNull();
  });

  it('percentagem com 1 casa à portuguesa', () => {
    expect(pct1(61.17)).toBe('61,2%');
    expect(pct1(100)).toBe('100,0%');
    expect(pct1(undefined)).toBe('0,0%');
  });
});
