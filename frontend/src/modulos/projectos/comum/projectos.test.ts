import dayjs from 'dayjs';
import {
  accoesProjecto, achatarWbs, barraGantt, consumo, escalaGantt, linhasGanttGlobal, linhasGanttWbs, marcasGantt, posicaoHoje, rotuloRubrica,
  tarefaAtrasada, totaisPorRubrica,
} from './regras';
import type { GanttGlobal, TarefaWbs, Wbs } from './tipos';

const todas = () => true;
const so = (...chaves: string[]) => (...pedidas: string[]) => pedidas.some((p) => chaves.includes(p));

const tarefa = (id: number, nome: string, inicio: string | null, fim: string | null, subtarefas: TarefaWbs[] = [], estado = 'PENDENTE'): TarefaWbs => ({
  id, codigo: null, nome, tarefa_pai_id: null, marco_projeto_id: 1, atribuido_a_id: null, valor_contrato: null, ordem: null, estado,
  data_inicio: inicio, data_fim: fim, percentagem_execucao: 0, execucao: 50, nivel: 0, horas: 0, posicoes: [], subtarefas,
});

describe('Gantt: escala e barras', () => {
  it('a escala vai do menor início ao maior fim (dias inclusivos)', () => {
    const e = escalaGantt([{ inicio: '2026-09-10', fim: '2026-09-19' }, { inicio: '2026-09-01', fim: null }]);
    expect(e.inicio.format('YYYY-MM-DD')).toBe('2026-09-01');
    expect(e.fim.format('YYYY-MM-DD')).toBe('2026-09-19');
    expect(e.dias).toBe(19);
  });

  it('usa os limites do servidor quando os há e troca se invertidos', () => {
    expect(escalaGantt([], '2026-07-07', '2026-12-29').dias).toBe(176);
    const e = escalaGantt([], '2026-01-31', '2026-01-01');
    expect(e.inicio.format('YYYY-MM-DD')).toBe('2026-01-01');
  });

  it('posiciona a barra em % e marca as tarefas sem fim como abertas', () => {
    const e = escalaGantt([], '2026-09-01', '2026-09-10'); // 10 dias
    expect(barraGantt({ inicio: '2026-09-01', fim: '2026-09-10' }, e)).toEqual({ esquerda: 0, largura: 100, aberta: false });
    expect(barraGantt({ inicio: '2026-09-06', fim: '2026-09-06' }, e)).toEqual({ esquerda: 50, largura: 10, aberta: false });
    expect(barraGantt({ inicio: '2026-09-09', fim: null }, e)).toEqual({ esquerda: 80, largura: 20, aberta: true });
    expect(barraGantt({ inicio: null, fim: '2026-09-05' }, e)).toBeNull();
  });

  it('corta as barras fora da escala', () => {
    const e = escalaGantt([], '2026-09-01', '2026-09-10');
    expect(barraGantt({ inicio: '2026-08-25', fim: '2026-09-02' }, e)).toEqual({ esquerda: 0, largura: 20, aberta: false });
    expect(barraGantt({ inicio: '2026-10-01', fim: '2026-10-05' }, e)?.largura).toBe(0);
  });

  it('cabeçalho por meses em períodos longos e por semanas nos curtos', () => {
    const longa = marcasGantt(escalaGantt([], '2026-07-07', '2026-12-29'));
    expect(longa).toHaveLength(6);
    expect(longa[0].esquerda).toBe(0);
    expect(longa.reduce((t, m) => t + m.largura, 0)).toBeCloseTo(100, 6);
    const curta = marcasGantt(escalaGantt([], '2026-09-01', '2026-09-21'));
    expect(curta.length).toBeGreaterThanOrEqual(3);
  });

  it('marca de hoje só dentro da escala', () => {
    const e = escalaGantt([], '2026-09-01', '2026-09-10');
    expect(posicaoHoje(e, dayjs('2026-09-01'))).toBeCloseTo(5, 6);
    expect(posicaoHoje(e, dayjs('2026-10-01'))).toBeNull();
  });
});

describe('Gantt: transformações', () => {
  it('Gantt global: segmento → projecto → tarefas', () => {
    const g: GanttGlobal = {
      inicio: '2026-07-07', fim: '2026-12-29',
      segmentos: [{ tipo: 'EXTERNO', projetos: [{ id: 7, codigo: 'P7', nome: 'Obra', inicio: '2026-07-07', fim: null, tarefas: [{ id: 6, codigo: null, nome: 'Contratos', inicio: '2026-09-08', fim: '2026-10-04' }] }] }],
    };
    const l = linhasGanttGlobal(g);
    expect(l.map((x) => [x.tipo, x.nivel, x.chave])).toEqual([['grupo', 0, 's-EXTERNO'], ['projecto', 1, 'p-7'], ['tarefa', 2, 't-6']]);
    expect(l[0].nome).toBe('Projectos externos (obras)');
    expect(linhasGanttGlobal(g, false)).toHaveLength(2);
    expect(linhasGanttGlobal(undefined)).toEqual([]);
  });

  it('Gantt do projecto a partir da WBS, com subtarefas indentadas e grupos vazios omitidos', () => {
    const wbs: Wbs = {
      execucao_global: 25,
      grupos: [
        { marco: { id: 1, nome: 'Fase 1', estado: 'PENDENTE', data: '2026-09-30' }, execucao: 50, tarefas: [tarefa(10, 'Mãe', '2026-09-01', '2026-09-30', [tarefa(11, 'Filha', '2026-09-02', '2026-09-05')])] },
        { marco: null, execucao: 0, tarefas: [] },
      ],
    };
    const l = linhasGanttWbs(wbs);
    expect(l.map((x) => [x.chave, x.nivel])).toEqual([['m-1', 0], ['t-10', 1], ['t-11', 2]]);
    expect(l[0].inicio).toBe(l[0].fim);
    expect(achatarWbs(wbs).map((t) => [t.id, t.marco])).toEqual([[10, 'Fase 1'], [11, 'Fase 1']]);
  });
});

describe('regras do projecto', () => {
  it('acções conforme o estado e as permissões', () => {
    expect(accoesProjecto({ estado: 'PREPARACAO' }, todas)).toMatchObject({ editar: true, activar: true, encerrar: true, reabrir: false });
    expect(accoesProjecto({ estado: 'ENCERRADO' }, todas)).toMatchObject({ editar: false, gerir: false, encerrar: false, reabrir: true, execucao: false });
    expect(accoesProjecto({ estado: 'ACTIVO' }, so('proj_execucao'))).toMatchObject({ editar: false, execucao: true, encerrar: false, requisitar: false });
    expect(accoesProjecto({ estado: 'ACTIVO' }, so('proj_requisitar'))).toMatchObject({ requisitar: true, gerir: false });
  });

  it('tarefa atrasada: não concluída e com fim no passado', () => {
    const hoje = dayjs('2026-10-01');
    expect(tarefaAtrasada({ estado: 'PENDENTE', data_fim: '2026-09-20' }, hoje)).toBe(true);
    expect(tarefaAtrasada({ estado: 'CONCLUIDA', data_fim: '2026-09-20' }, hoje)).toBe(false);
    expect(tarefaAtrasada({ estado: 'EM_CURSO', data_fim: '2026-10-01' }, hoje)).toBe(false);
    expect(tarefaAtrasada({ estado: 'EM_CURSO', data_fim: null }, hoje)).toBe(false);
  });

  it('consumo e totais por rubrica', () => {
    expect(consumo('500.00', '1000.00')).toBe(50);
    expect(consumo('1', '3')).toBe(33.3);
    expect(consumo('10', '0')).toBeNull();
    expect(totaisPorRubrica([{ rubrica: 'MAO_DE_OBRA', montante: '0.10' }, { rubrica: 'MAO_DE_OBRA', montante: '0.20' }, { rubrica: 'MATERIAIS', montante: 5 }]))
      .toEqual({ MAO_DE_OBRA: '0.30', MATERIAIS: '5.00' });
    expect(rotuloRubrica('MAO_DE_OBRA')).toBe('Mão de obra');
    expect(rotuloRubrica('CUSTOS_EQUIPAMENTO')).toBe('Custos equipamento');
  });
});
