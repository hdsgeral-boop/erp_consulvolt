import { describe, expect, it } from 'vitest';
import type { CargaTrabalho, LinhaFolhaMedicao } from './comum/tipos';
import { agruparPorPessoa, linhasALancar, linhasPorPessoa, medidoPorPessoa } from './comum/folhaMedicao';
import { ordemDeTrabalhoHtml, pedidoOrdemTrabalho } from './separadores/CargaTrabalho';
import { antepassados, descendentes, disporArvore, escalaAjustada, filhosPorPai, iniciais, MEDIDAS } from './separadores/organigrama/layout';
import { organigramaSvg } from './separadores/organigrama/svg';
import { textoVagas } from './separadores/organigrama/QuadroOrganigrama';

/** Ronda 3 (r3_rh_proj): ordem de trabalho por pessoa, folha de medição por pessoa e organigrama em esquema. Dados fictícios. */

const tarefa = (id: number, nome: string) => ({
  tarefa_id: id, codigo: String(id), nome, fase: 'Fase 1', caminho: [], nivel: 0, inicio: '2026-09-01', fim: '2026-09-10', estado: 'PENDENTE', execucao: 0, quota: 1, da_area: false,
  areas: [], areas_sem_gente: [], meta: null, horas_previstas: 16, horas_lancadas: 0, ritmo: null,
});
const carga: CargaTrabalho = {
  projeto: { id: 1, codigo: 'P1', nome: 'Obra' }, calendario: { horas_dia: 8, dias: { 0: 0, 1: 1, 2: 1, 3: 1, 4: 1, 5: 1, 6: 0 } }, areas_sem_gente: [],
  grupos: [
    { chave: 'm1', membro_id: 1, nome: 'Ana', tipo: 'INTERNO', papel: 'Encarregada', horas_alocadas: 8, posicao: { id: 3, titulo: 'Direcção' }, n_tarefas: 1, horas_previstas: 16, horas_lancadas: 0, quantidades: {}, tarefas: [tarefa(1, 'Vala')] },
    { chave: 'm2', membro_id: 2, nome: 'Rui', tipo: 'INTERNO', papel: null, horas_alocadas: 8, posicao: null, n_tarefas: 1, horas_previstas: 16, horas_lancadas: 0, quantidades: {}, tarefas: [tarefa(2, 'Tubo')] },
  ],
};

describe('ordem de trabalho por colaborador', () => {
  it('com a chave imprime só a folha dessa pessoa; sem chave, uma folha por pessoa', () => {
    const so = ordemDeTrabalhoHtml(carga, 'm2');
    expect((so.match(/<section/g) ?? []).length).toBe(1);
    expect(so).toContain('Tubo');
    expect(so).not.toContain('Vala');
    expect((ordemDeTrabalhoHtml(carga).match(/<section/g) ?? []).length).toBe(2);
    expect(ordemDeTrabalhoHtml(carga, 'x')).toBe('<p>Sem tarefas atribuídas.</p>');
    expect(ordemDeTrabalhoHtml(carga, 'm1')).toContain('Direcção');
    expect(pedidoOrdemTrabalho(carga, 'm1').titulo).toBe('Ordem de trabalho — Ana — P1 Obra');
    expect(pedidoOrdemTrabalho(carga).titulo).toBe('Ordem de trabalho — P1 Obra');
  });
});

const linha = (id: number, pessoas: number[], extra: Partial<LinhaFolhaMedicao> = {}): LinhaFolhaMedicao => ({
  id, codigo: String(id), nome: `T${id}`, fase: null, atribuido_a_id: pessoas.length === 1 ? pessoas[0] : null, pessoas, unidade: 'm', meta: 100, realizado: 0, falta: 100, medicoes: [], ...extra,
});

describe('folha de medição por pessoa', () => {
  const linhas = [linha(1, [10]), linha(2, [10, 20]), linha(3, [])];
  it('uma linha por tarefa e pessoa; filtro pela pessoa', () => {
    expect(linhasPorPessoa(linhas).map((l) => l.chave)).toEqual(['1:10', '2:10', '2:20', '3:0']);
    expect(linhasPorPessoa(linhas, 20).map((l) => l.chave)).toEqual(['2:20']);
    expect(linhasPorPessoa(linhas, 10).map((l) => l.linha.id)).toEqual([1, 2]);
  });
  it('agrupa pelo nome, com «Sem pessoa atribuída» no fim', () => {
    const g = agruparPorPessoa(linhasPorPessoa(linhas), (id) => (id === 10 ? 'Zé' : 'Ana'));
    expect(g.map((x) => x.nome)).toEqual(['Ana', 'Zé', 'Sem pessoa atribuída']);
    expect(g[1].linhas).toHaveLength(2);
  });
  it('só as linhas com quantidade, com o executante escolhido ou o da linha', () => {
    const ls = linhasPorPessoa(linhas);
    expect(linhasALancar(ls, { '2:10': 5, '2:20': 0, '3:0': -2 }, { '2:10': ' frente A ' }, { '3:0': 20 })).toEqual([
      { tarefa_id: 2, quantidade: 5, nota: 'frente A', membro_id: 10 },
      { tarefa_id: 3, quantidade: -2, nota: null, membro_id: 20 },
    ]);
  });
  it('medido por pessoa a partir do histórico', () => {
    const m = medidoPorPessoa([linha(1, [10], { medicoes: [{ q: 2.5, data: '', nota: '', por: '', em: '', membro_id: 10 }, { q: 1, data: '', nota: '', por: '', em: '' }, { q: 0.5, data: '', nota: '', por: '', em: '', membro_id: 10 }] })]);
    expect(m.get(10)).toEqual({ m: 3 });
    expect(m.get(null)).toEqual({ m: 1 });
  });
});

describe('organigrama em esquema', () => {
  const nos = [
    { id: 1, pai: null }, { id: 2, pai: 1, ordem: 2 }, { id: 3, pai: 1, ordem: 1 }, { id: 4, pai: 1, apoio: true, ordem: 9 }, { id: 5, pai: 3 },
    { id: 6, pai: 7 }, { id: 7, pai: 6 },   // ciclo: quebrado (ficam raízes)
    { id: 8, pai: 99 },                     // pai inexistente: raiz
  ];
  it('filhos com o apoio primeiro e pela ordem; ciclos e pais inexistentes viram raízes', () => {
    const f = filhosPorPai(nos);
    expect(f.get(1)?.map((n) => n.id)).toEqual([4, 3, 2]);
    expect(f.get(null)?.map((n) => n.id)).toEqual([1, 6, 7, 8]);
    expect(descendentes(1, f).sort()).toEqual([2, 3, 4, 5]);
    expect(antepassados(5, new Map([[5, 3], [3, 1], [1, null]]))).toEqual([3, 1]);
  });

  it('vertical: o pai centrado sobre os filhos, níveis de cima para baixo; horizontal: da esquerda para a direita', () => {
    const v = disporArvore([{ id: 1, pai: null }, { id: 2, pai: 1 }, { id: 3, pai: 1 }], 'vertical');
    const [p, a, b] = [v.nos.get(1)!, v.nos.get(2)!, v.nos.get(3)!];
    expect(a.y).toBe(b.y);
    expect(a.y).toBe(p.y + MEDIDAS.altura + MEDIDAS.niveis);
    expect(p.x + MEDIDAS.largura / 2).toBe((a.x + b.x + MEDIDAS.largura) / 2);
    expect(v.ligacoes).toHaveLength(2);
    expect(v.largura).toBe(MEDIDAS.margem * 2 + MEDIDAS.largura * 2 + MEDIDAS.irmaos);
    const h = disporArvore([{ id: 1, pai: null }, { id: 2, pai: 1 }, { id: 3, pai: 1 }], 'horizontal');
    expect(h.nos.get(2)!.x).toBe(h.nos.get(1)!.x + MEDIDAS.largura + MEDIDAS.niveis);
    expect(h.nos.get(2)!.x).toBe(h.nos.get(3)!.x);
    expect(h.nos.get(3)!.y).toBeGreaterThan(h.nos.get(2)!.y);
  });

  it('recolher esconde o ramo; «em coluna» empilha as subposições finais (só na vertical)', () => {
    const r = disporArvore([{ id: 1, pai: null }, { id: 2, pai: 1 }, { id: 3, pai: 2 }], 'vertical', new Set([2]));
    expect(r.nos.has(3)).toBe(false);
    expect(r.nos.get(2)!.recolhido).toBe(true);
    const c = disporArvore([{ id: 1, pai: null, coluna: true }, { id: 2, pai: 1 }, { id: 3, pai: 1 }], 'vertical');
    expect(c.nos.get(2)!.x).toBe(c.nos.get(3)!.x);
    expect(c.nos.get(3)!.y).toBeGreaterThan(c.nos.get(2)!.y);
    const ch = disporArvore([{ id: 1, pai: null, coluna: true }, { id: 2, pai: 1 }, { id: 3, pai: 1 }], 'horizontal');
    expect(ch.nos.get(2)!.x).toBe(ch.nos.get(3)!.x);
    expect(ch.nos.get(2)!.y).not.toBe(ch.nos.get(3)!.y);
  });

  it('ajuste ao ecrã, iniciais e vagas', () => {
    expect(escalaAjustada(2000, 500, 1016, 2000)).toBe(0.5);
    expect(escalaAjustada(100, 100, 1000, 1000)).toBe(1);
    expect(escalaAjustada(100000, 100, 500, 500)).toBe(0.2);
    expect(iniciais('Ana Maria Fictícia')).toBe('AF');
    expect(iniciais('')).toBe('?');
    expect(textoVagas(3, 2)).toEqual({ texto: '3/2 vaga(s)', excesso: true, falta: false });
    expect(textoVagas(1, 0).texto).toBe('1 membro(s)');
  });

  it('SVG para impressão/PNG: texto escapado, sem HTML embebido', () => {
    const d = disporArvore([{ id: 1, pai: null }], 'vertical');
    const svg = organigramaSvg(d, new Map([[1, { id: 1, titulo: 'Direcção <obra>', area: 'A&B', cor: 'verde', apoio: false, vagas: '1/1 vaga(s)', pessoas: [{ nome: 'Ana Fictícia', responsavel: true }], contacto: '900' }]]));
    expect(svg).toContain('Direcção &lt;obra&gt;');
    expect(svg).toContain('A&amp;B');
    expect(svg).toContain('#16a34a');
    expect(svg).not.toContain('foreignObject');
  });
});
