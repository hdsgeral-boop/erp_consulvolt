import dayjs from 'dayjs';
import { acumular, descricaoCalendario, diasDoCalendario, interpretarColagem, marcasDaEscala, modeloDe, numeroTexto, partesIguais, pctDaMeta, prazoRepartido, rotuloDependencia } from './regrasMetas';
import { ligacoesWbs, linhasGanttWbs } from './regras';
import { ordemDeTrabalhoHtml } from '../separadores/CargaTrabalho';
import type { CargaTrabalho, TarefaWbs, Wbs } from './tipos';

/** Fase E (Versão Actual): regras de apresentação das metas, do calendário, das dependências e do Gantt. Dados fictícios. */
describe('calendário de trabalho', () => {
  it('dias por omissão, modelo e descrição', () => {
    expect(diasDoCalendario(null)).toEqual([0, 1, 1, 1, 1, 1, 0]);
    expect(modeloDe([0, 1, 1, 1, 1, 1, 0.5])).toBe('SEG_SEX_SAB12');
    expect(modeloDe([1, 0, 1, 0, 1, 0, 1])).toBe('PERSONALIZADO');
    expect(descricaoCalendario({ horas_dia: 7.5, dias: { 0: 0, 1: 1, 2: 1, 3: 1, 4: 1, 5: 1, 6: 1 } })).toBe('Segunda a sábado · 7,5 h/dia');
    expect(descricaoCalendario({ horas_dia: 8, dias: { 0: 0, 1: 1, 2: 0, 3: 1, 4: 0, 5: 0, 6: 0.5 } })).toBe('Segunda, Quarta, Sábado (meio dia) · 8 h/dia');
  });
});

describe('folha de medição', () => {
  it('lê números em formato português e inglês', () => {
    expect(numeroTexto('1 234,56')).toBe(1234.56);
    expect(numeroTexto('1.234,56')).toBe(1234.56);
    expect(numeroTexto('1,234.56')).toBe(1234.56);
    expect(numeroTexto('12,5')).toBe(12.5);
    expect(numeroTexto('-3')).toBe(-3);
    expect(numeroTexto('abc')).toBeNull();
  });

  it('cola duas colunas pelo código ou pelo nome (sem acentos) e lista o que não reconhece', () => {
    const tarefas = [{ id: 1, codigo: '1.1', nome: 'Betonar laje' }, { id: 2, codigo: null, nome: 'Pavimentação' }];
    const r = interpretarColagem('1.1\t120\npavimentacao\t85,5\nX\t3\n1.1\tnada', tarefas);
    expect([...r.quantidades.entries()]).toEqual([[1, 120], [2, 85.5]]);
    expect(r.naoReconhecidas).toEqual(['X', '1.1\tnada']);
    expect([...interpretarColagem('1.1|10\nBetonar laje|20', tarefas).quantidades.entries()]).toEqual([[1, 20]]);
  });

  it('curva acumulada e % da meta', () => {
    expect(acumular([120, 150, 30, 0])).toEqual([120, 270, 300, 300]);
    expect(pctDaMeta(35, 100)).toBe(35);
    expect(pctDaMeta(150, 100)).toBe(100);
    expect(pctDaMeta(5, 0)).toBeNull();
  });
});

describe('assistente e dependências', () => {
  it('reparte a meta e o prazo em partes sequenciais', () => {
    expect(partesIguais(1000, 3)).toEqual([333.33, 333.33, 333.34]);
    expect(prazoRepartido('2026-09-01', '2026-09-30', 3)).toEqual([
      { inicio: '2026-09-01', fim: '2026-09-10' }, { inicio: '2026-09-11', fim: '2026-09-20' }, { inicio: '2026-09-21', fim: '2026-09-30' }]);
  });

  it('rótulo da precedência', () => {
    expect(rotuloDependencia('FS', 2)).toBe('Fim → Início +2d');
    expect(rotuloDependencia('SS', 0)).toBe('Início → Início');
    expect(rotuloDependencia(null, -1)).toBe('Fim → Início -1d');
  });
});

describe('Gantt: escala, período e ligações', () => {
  it('marcas alinhadas à unidade (semana à segunda, trimestres)', () => {
    const sem = marcasDaEscala(dayjs('2026-09-09'), dayjs('2026-09-20'), 'semana');
    expect(sem.map((m) => m.de.format('YYYY-MM-DD'))).toEqual(['2026-09-07', '2026-09-14']);
    expect(marcasDaEscala(dayjs('2026-08-15'), dayjs('2026-11-02'), 'trimestre').map((m) => m.rotulo)).toEqual(['T3 2026', 'T4 2026']);
  });

  const t = (id: number, inicio: string | null, fim: string | null, extra: Partial<TarefaWbs> = {}): TarefaWbs => ({
    id, codigo: null, nome: `T${id}`, tarefa_pai_id: null, marco_projeto_id: 1, atribuido_a_id: null, valor_contrato: null, ordem: null, estado: 'PENDENTE',
    data_inicio: inicio, data_fim: fim, percentagem_execucao: 0, execucao: 0, nivel: 1, horas: 0, posicoes: [], subtarefas: [], ...extra,
  });
  const wbs: Wbs = {
    execucao_global: 0,
    grupos: [
      { marco: { id: 1, nome: 'Fase 1', estado: 'PENDENTE', data: '2026-09-30', data_inicio: '2026-09-01' }, execucao: 0,
        tarefas: [t(1, '2026-09-01', '2026-09-10'), t(2, '2026-09-11', '2026-09-20', { tarefa_predecessora_id: 1, tipo_dependencia: 'FS', desfasamento_dias: 0, subtarefas: [t(3, '2026-10-05', '2026-10-06')] })] },
      { marco: { id: 2, nome: 'Fase 2', estado: 'PENDENTE', data: '2026-12-31' }, execucao: 0, tarefas: [t(4, '2026-12-01', '2026-12-10')] },
    ],
  };

  it('a fase vai do início à data alvo; o período mantém os pais das tarefas que ficam', () => {
    const todas = linhasGanttWbs(wbs);
    expect(todas[0]).toMatchObject({ chave: 'm-1', inicio: '2026-09-01', fim: '2026-09-30' });
    const out = linhasGanttWbs(wbs, { periodo: { de: '2026-10-01', ate: '2026-10-31' } });
    expect(out.map((l) => l.chave)).toEqual(['m-1', 't-2', 't-3']);
    expect(linhasGanttWbs(wbs, { nivelMaximo: 0 }).map((l) => l.chave)).toEqual(['m-1', 'm-2']);
    expect(linhasGanttWbs(wbs, { fasesFechadas: new Set(['m-1']) }).map((l) => l.chave)).toEqual(['m-1', 'm-2', 't-4']);
  });

  it('ligações de precedência', () => {
    expect(ligacoesWbs(wbs)).toEqual([{ de: 't-1', para: 't-2', tipo: 'FS', desf: 0 }]);
  });
});

describe('ordem de trabalho', () => {
  it('uma folha por pessoa, agrupada por fase, com o que falta e o ritmo; o nome é escapado', () => {
    const c: CargaTrabalho = {
      projeto: { id: 1, codigo: 'P1', nome: 'Obra' }, calendario: { horas_dia: 8, dias: { 0: 0, 1: 1, 2: 1, 3: 1, 4: 1, 5: 1, 6: 0 } }, areas_sem_gente: [],
      grupos: [
        { chave: 'm1', membro_id: 1, nome: 'Ana <Teste>', tipo: 'INTERNO', papel: 'Encarregada', horas_alocadas: 8, posicao: null, n_tarefas: 1, horas_previstas: 80, horas_lancadas: 0, quantidades: { m: 300 },
          tarefas: [{ tarefa_id: 1, codigo: '1', nome: 'Vala', fase: 'Troço 1', caminho: [], nivel: 0, inicio: '2026-09-01', fim: '2026-09-14', estado: 'EM_CURSO', execucao: 33, quota: 1, da_area: false,
            areas: [], areas_sem_gente: [], meta: { unidade: 'm', quantidade: 300, realizado: 100 }, horas_previstas: 80, horas_lancadas: 0,
            ritmo: { dias: 10, corridos: 14, uteis: 10, horas: 80, por_calendario: false, dia: 30, semana: 150, mes: 649.5, hora: 3.75, unidade: 'm' } }] },
        { chave: 'm2', membro_id: 2, nome: 'Sem tarefas', tipo: 'INTERNO', papel: null, horas_alocadas: 0, posicao: null, n_tarefas: 0, horas_previstas: 0, horas_lancadas: 0, quantidades: {}, tarefas: [] },
      ],
    };
    const html = ordemDeTrabalhoHtml(c);
    expect(html).toContain('Ana &lt;Teste&gt;');
    expect(html).toContain('Troço 1');
    expect(html).toContain('200 m');
    expect(html).toContain('30 m/dia');
    expect(html).not.toContain('Sem tarefas');
    expect((html.match(/<section/g) ?? []).length).toBe(1);
  });
});
