import { fireEvent, render, screen } from '@testing-library/react';
import dayjs from 'dayjs';
import { vi } from 'vitest';
import { simularLargura } from '@/componentes/responsivo/testes/simularLargura';
import { Gantt } from './Gantt';
import { descricaoNivelFase, pedidoNivelFase } from './arrastar';
import {
  alternarChave, chavesRecolhiveis, datasParaDataMinima, escalaDasQuantidades, escalaGantt, ligacaoPorArrasto, linhasGanttWbs, numeracaoWbs, quantidadesPorPeriodo,
  type LinhaGantt,
} from './regras';
import { chavesComFilhosMapa, diasUteis, fechadosMapa, linhasVisiveisMapa, ritmoExigido, textoRitmo } from './regrasMetas';
import { ligacaoAssunto, PERMISSOES_ASSUNTOS, textoOrigem } from './OrigemProjecto';
import type { MapaQuantidades, TarefaWbs, Wbs } from './tipos';

// Dados fictícios
const tarefa = (id: number, nome: string, extra: Partial<TarefaWbs> = {}): TarefaWbs => ({
  id, codigo: null, nome, tarefa_pai_id: null, marco_projeto_id: null, atribuido_a_id: null, valor_contrato: null, ordem: null, estado: 'PENDENTE',
  data_inicio: '2026-09-01', data_fim: '2026-09-10', percentagem_execucao: 0, execucao: 0, nivel: 0, horas: 0, posicoes: [], subtarefas: [], ...extra,
});

const wbs: Wbs = {
  execucao_global: 0,
  grupos: [
    { marco: { id: 1, nome: 'Fundações', estado: 'PENDENTE', data_inicio: '2026-09-01', data: '2026-09-30' }, execucao: 0,
      tarefas: [tarefa(10, 'Escavação', { subtarefas: [tarefa(11, 'Marcação'), tarefa(12, 'Abertura', { codigo: 'ESC-2' })] }), tarefa(13, 'Betão')] },
    { marco: { id: 2, nome: 'Estrutura', estado: 'PENDENTE', data: '2026-10-15' }, execucao: 0, tarefas: [tarefa(20, 'Pilares', { data_inicio: '2026-10-01', data_fim: '2026-10-15' })] },
    { marco: null, execucao: 0, tarefas: [tarefa(30, 'Limpeza')] },
  ],
};

describe('Gantt — numeração WBS, losango da data alvo e árvore recolhível', () => {
  it('numera fases, tarefas e subtarefas e usa o código quando existe', () => {
    const n = numeracaoWbs(wbs);
    expect(n.get('m-1')).toBe('1');
    expect(n.get('t-10')).toBe('1.1');
    expect(n.get('t-12')).toBe('1.1.2');
    expect(n.get('t-13')).toBe('1.2');
    expect(n.get('m-sem')).toBe('3');
    const linhas = linhasGanttWbs(wbs);
    expect(linhas.find((l) => l.chave === 't-11')?.rotulo).toBe('1.1.1');
    expect(linhas.find((l) => l.chave === 't-12')?.rotulo).toBe('ESC-2');
  });

  it('a fase com início leva a data alvo (losango); sem início não', () => {
    const linhas = linhasGanttWbs(wbs);
    expect(linhas.find((l) => l.chave === 'm-1')?.alvo).toBe('2026-09-30');
    expect(linhas.find((l) => l.chave === 'm-2')?.alvo).toBeNull();
  });

  it('fases e tarefas fechadas escondem o que está por baixo; a numeração mantém-se', () => {
    const fechadaFase = linhasGanttWbs(wbs, { fasesFechadas: new Set(['m-1']) });
    expect(fechadaFase.map((l) => l.chave)).not.toContain('t-10');
    expect(fechadaFase.find((l) => l.chave === 'm-1')?.filhos).toBe(true);
    const fechadaTarefa = linhasGanttWbs(wbs, { fasesFechadas: new Set(['t-10']) });
    expect(fechadaTarefa.map((l) => l.chave)).toEqual(['m-1', 't-10', 't-13', 'm-2', 't-20', 'm-sem', 't-30']);
    expect(fechadaTarefa.find((l) => l.chave === 't-13')?.rotulo).toBe('1.2');
  });

  it('«Fechar árvore» fecha tudo o que tem filhos; «Só tarefas principais» só as tarefas', () => {
    const linhas = linhasGanttWbs(wbs);
    expect([...chavesRecolhiveis(linhas)].sort()).toEqual(['m-1', 'm-2', 'm-sem', 't-10']);
    expect([...chavesRecolhiveis(linhas, true)]).toEqual(['t-10']);
    expect([...alternarChave(new Set(['a']), 'a')]).toEqual([]);
    expect([...alternarChave(new Set(), 'b')]).toEqual(['b']);
  });
});

describe('Gantt — ligação de tarefas por arrasto', () => {
  const l = (chave: string, tipo: LinhaGantt['tipo'] = 'tarefa'): LinhaGantt => ({ chave, nome: chave.toUpperCase(), nivel: 1, tipo, inicio: '2026-09-01', fim: '2026-09-05', id: Number(chave.slice(2)) || undefined });

  it('cria a ligação da origem (predecessora) para o destino', () => {
    expect(ligacaoPorArrasto(l('t-1'), l('t-2'), [])).toEqual({ ok: true, predecessora: 1, tarefa: 2, substitui: false });
  });

  it('avisa que substitui a predecessora actual do destino', () => {
    expect(ligacaoPorArrasto(l('t-1'), l('t-2'), [{ de: 't-3', para: 't-2' }])).toMatchObject({ ok: true, substitui: true });
  });

  it('largar na própria barra ou fora não faz nada', () => {
    expect(ligacaoPorArrasto(l('t-1'), l('t-1'), [])).toBeNull();
    expect(ligacaoPorArrasto(l('t-1'), undefined, [])).toBeNull();
  });

  it('recusa fases, ligações repetidas e ciclos evidentes', () => {
    expect(ligacaoPorArrasto(l('t-1'), l('m-1', 'marco'), [])).toMatchObject({ ok: false });
    expect(ligacaoPorArrasto(l('t-1'), l('t-2'), [{ de: 't-1', para: 't-2' }])).toMatchObject({ ok: false });
    // t-1 depende de t-3, que depende de t-2: ligar t-1 → t-2 fecharia o ciclo
    const r = ligacaoPorArrasto(l('t-1'), l('t-2'), [{ de: 't-3', para: 't-1' }, { de: 't-2', para: 't-3' }]);
    expect(r).toMatchObject({ ok: false });
    expect(r && !r.ok && r.erro).toContain('circular');
  });

  it('ajusta as datas à data mínima mantendo a duração (FS/SS pelo início, FF pelo fim)', () => {
    expect(datasParaDataMinima('FS', '2026-09-20', '2026-09-01', '2026-09-05')).toEqual({ data_inicio: '2026-09-20', data_fim: '2026-09-24' });
    expect(datasParaDataMinima('FF', '2026-09-20', '2026-09-01', '2026-09-05')).toEqual({ data_inicio: '2026-09-16', data_fim: '2026-09-20' });
    expect(datasParaDataMinima('SS', '2026-09-20', null, null)).toEqual({ data_inicio: '2026-09-20', data_fim: null });
  });

  it('o componente mostra a pega nas tarefas e chama aoLigar ao largar sobre outra linha', () => {
    const aoLigar = vi.fn();
    const linhas: LinhaGantt[] = [l('t-1'), { ...l('t-2'), inicio: '2026-09-06', fim: '2026-09-09' }, l('m-9', 'marco')];
    const { container } = render(<Gantt inicio="2026-09-01" fim="2026-09-30" linhas={linhas} aoLigar={aoLigar} />);
    const pegas = container.querySelectorAll('[data-pega]');
    expect(pegas).toHaveLength(2);
    fireEvent.pointerDown(pegas[0], { button: 0, pointerId: 1, clientX: 10, clientY: 10 });
    expect(screen.getByTestId('gantt-ligacao-temporaria')).toBeInTheDocument();
    const destino = container.querySelector('[data-linha="t-2"]') as HTMLElement;
    fireEvent.pointerUp(destino, { pointerId: 1, clientX: 50, clientY: 40 });
    expect(aoLigar).toHaveBeenCalledWith(expect.objectContaining({ chave: 't-1' }), expect.objectContaining({ chave: 't-2' }));
  });

  it('clicar numa seta pede para remover a ligação; o botão da fase recolhe-a', () => {
    const aoRemover = vi.fn();
    const aoAlternar = vi.fn();
    const linhas: LinhaGantt[] = [{ ...l('m-1', 'marco'), nivel: 0, filhos: true, alvo: '2026-09-20', fim: '2026-09-20' }, l('t-1'), { ...l('t-2'), inicio: '2026-09-06', fim: '2026-09-09' }];
    const { container } = render(<Gantt inicio="2026-09-01" fim="2026-09-30" linhas={linhas} ligacoes={[{ de: 't-1', para: 't-2', tipo: 'FS' }]}
      aoRemoverLigacao={aoRemover} aoAlternar={aoAlternar} fechadas={new Set()} />);
    fireEvent.click(container.querySelector('[data-ligacao="t-1>t-2"]') as Element);
    expect(aoRemover).toHaveBeenCalledWith(expect.objectContaining({ de: 't-1', para: 't-2' }));
    const botao = screen.getByRole('button', { name: 'Recolher «M-1»' });
    expect(botao).toHaveAttribute('aria-expanded', 'true');
    fireEvent.click(botao);
    expect(aoAlternar).toHaveBeenCalledWith('m-1');
    expect(container.querySelector('[data-alvo="m-1"]')).not.toBeNull();
  });

  it('mostra as colunas extra (precedência e estrutura orgânica)', () => {
    simularLargura(1400);
    render(<Gantt inicio="2026-09-01" fim="2026-09-30" linhas={[l('t-1')]} colunasExtra={[{ titulo: 'Precedência', valor: () => '← A' }, { titulo: 'Estrutura orgânica', valor: () => 'Direcção de obra' }]} />);
    expect(screen.getByText('Estrutura orgânica')).toBeInTheDocument();
    expect(screen.getByText('Direcção de obra')).toBeInTheDocument();
    simularLargura(null);
  });
});

describe('Gantt — quantidades por período', () => {
  const mapa: MapaQuantidades = {
    escala: 'semana', de: '2026-09-07', ate: '2026-09-27', calendario: { horas_dia: 8, dias: {} },
    colunas: [
      { de: '2026-09-07', ate: '2026-09-13', rotulo: '7/9', titulo: '2026-09-07 a 2026-09-13', fds: false },
      { de: '2026-09-14', ate: '2026-09-20', rotulo: '14/9', titulo: '2026-09-14 a 2026-09-20', fds: false },
      { de: '2026-09-21', ate: '2026-09-27', rotulo: '21/9', titulo: '2026-09-21 a 2026-09-27', fds: false },
    ],
    quantidades: [
      { unidade: 'm²', meta: 300, linhas: [{ chave: 't-1', tipo: 'tarefa', id: 1, nivel: 1, nome: 'Reboco', previsto: [100, 100, 100], realizado: [120, 40, 0], total_previsto: 300, total_realizado: 160 }],
        totais: { previsto: [100, 100, 100], realizado: [120, 40, 0], total_previsto: 300, total_realizado: 160 } },
      { unidade: 'un', meta: 5, linhas: [{ chave: 't-1', tipo: 'tarefa', id: 1, nivel: 1, nome: 'Reboco', previsto: [1, 1, 1], realizado: [0, 0, 0], total_previsto: 3, total_realizado: 0 }],
        totais: { previsto: [1, 1, 1], realizado: [0, 0, 0], total_previsto: 3, total_realizado: 0 } },
    ],
    horas: { linhas: [], totais: { previsto: [], realizado: [], total_previsto: 0, total_realizado: 0 } },
    valor: { linhas: [], totais: { previsto: [], realizado: [], total_previsto: 0, total_realizado: 0 }, total_mapeado: 0 },
  };
  const escala = escalaGantt([], '2026-09-07', '2026-09-27');

  it('põe cada período na posição da escala e pinta pelo ritmo previsto', () => {
    const q = quantidadesPorPeriodo(mapa, escala, dayjs('2026-09-16'));
    const c = q.get('t-1');
    expect(c?.unidade).toBe('m²');
    expect(c?.celulas.map((x) => x.estado)).toEqual(['cumprido', 'aquem', 'futuro']);
    expect(c?.celulas[0].esquerda).toBe(0);
    expect(Math.round((c?.celulas[1].esquerda ?? 0) * 100) / 100).toBe(33.33);
    expect(Math.round((c?.celulas[2].largura ?? 0) * 100) / 100).toBe(33.33);
  });

  it('sem mapa não há quantidades; a escala automática segue o Gantt', () => {
    expect(quantidadesPorPeriodo(undefined, escala).size).toBe(0);
    expect(escalaDasQuantidades(undefined, escala)).toBe('semana');
    expect(escalaDasQuantidades(undefined, escalaGantt([], '2026-01-01', '2026-12-31'))).toBe('mes');
    expect(escalaDasQuantidades('trimestre', escala)).toBe('trimestre');
  });

  it('o componente desenha a faixa das quantidades com a cor do estado', () => {
    const q = quantidadesPorPeriodo(mapa, escala, dayjs('2026-09-16'));
    const { container } = render(<Gantt inicio="2026-09-07" fim="2026-09-27" quantidades={q}
      linhas={[{ chave: 't-1', id: 1, nome: 'Reboco', nivel: 1, tipo: 'tarefa', inicio: '2026-09-07', fim: '2026-09-27' }]} />);
    const celulas = container.querySelectorAll('[data-qtd="t-1"]');
    expect(celulas).toHaveLength(3);
    expect(celulas[0].textContent).toBe('120/100');
    expect(celulas[1].getAttribute('data-estado')).toBe('aquem');
    expect(celulas[2].textContent).toBe('100');
  });
});

describe('#10 — arrastar fases na WBS', () => {
  it('sobre uma tarefa: a fase passa a subtarefa; sobre outra fase: tarefa principal; sobre si própria: nada', () => {
    expect(pedidoNivelFase(1, { tarefa: 20 })).toEqual({ destino: 'SUB', alvo_id: 20, marco_projeto_id: null, encaixar: true });
    expect(pedidoNivelFase(1, { marco: 2 })).toEqual({ destino: 'RAIZ', alvo_id: null, marco_projeto_id: 2, encaixar: true });
    expect(pedidoNivelFase(1, { marco: null })).toEqual({ destino: 'RAIZ', alvo_id: null, marco_projeto_id: null, encaixar: true });
    expect(pedidoNivelFase(1, { marco: 1 })).toBeNull();
    expect(descricaoNivelFase(pedidoNivelFase(1, { marco: null })!, 'Fundações', 'Sem milestone')).toContain('sem fase');
  });
});

describe('#2 — ritmo exigido pela meta', () => {
  it('reparte a meta pelos dias úteis do calendário (segunda a sexta por omissão)', () => {
    // 2026-09-07 (segunda) a 2026-09-18 (sexta): 10 dias úteis
    expect(diasUteis('2026-09-07', '2026-09-18', null)).toBe(10);
    const r = ritmoExigido(200, 'm²', '2026-09-07', '2026-09-18', { horas_dia: 8, dias: {} });
    expect(r).toMatchObject({ dias: 10, dia: 20, semana: 100, mes: 433, hora: 2.5, unidade: 'm²', porCalendario: false });
    expect(textoRitmo(r!)).toContain('20 m²/dia');
  });

  it('sem meta ou sem início não há ritmo; prazo só em dias de descanso usa os dias corridos', () => {
    expect(ritmoExigido(0, 'm', '2026-09-07', '2026-09-10', null)).toBeNull();
    expect(ritmoExigido(10, 'm', null, '2026-09-10', null)).toBeNull();
    // sábado e domingo
    expect(ritmoExigido(10, 'm', '2026-09-12', '2026-09-13', null)).toMatchObject({ dias: 2, porCalendario: true, dia: 5 });
  });
});

describe('#15 — mapa de quantidades: abrir/fechar por nó e «só tarefas»', () => {
  const linhas = [
    { chave: 'm-1', nivel: 0 }, { chave: 't-1', nivel: 1 }, { chave: 't-2', nivel: 2 }, { chave: 't-3', nivel: 1 }, { chave: 'm-2', nivel: 0 }, { chave: 't-4', nivel: 1 },
  ];
  it('identifica os nós com filhos e esconde os descendentes dos fechados', () => {
    expect([...chavesComFilhosMapa(linhas)]).toEqual(['m-1', 't-1', 'm-2']);
    expect(linhasVisiveisMapa(linhas, new Set(['t-1'])).map((l) => l.chave)).toEqual(['m-1', 't-1', 't-3', 'm-2', 't-4']);
    expect(linhasVisiveisMapa(linhas, new Set(['m-1'])).map((l) => l.chave)).toEqual(['m-1', 'm-2', 't-4']);
  });
  it('«Só tarefas» fecha só as tarefas; «Fechar tudo» deixa as fases', () => {
    expect([...fechadosMapa(linhas, 'tarefas')]).toEqual(['t-1']);
    expect(linhasVisiveisMapa(linhas, fechadosMapa(linhas, 'tudo')).map((l) => l.chave)).toEqual(['m-1', 'm-2']);
  });
});

describe('#25b — origem «Método de Trabalho»', () => {
  it('texto da origem e ligação para o assunto', () => {
    expect(textoOrigem({ origem: 'METODO_TRABALHO', assunto_metodo_id: 7, assunto_metodo: { id: 7, numero: 'MT-2026/0007', titulo: 'Fuga de água', estado: 'EM_CURSO' } }))
      .toBe('Método de Trabalho — assunto MT-2026/0007');
    expect(textoOrigem({ origem: null, assunto_metodo_id: null, assunto_metodo: null })).toBeNull();
    expect(ligacaoAssunto(7)).toBe('/m/metodo/mt_assuntos?assunto=7');
    expect(PERMISSOES_ASSUNTOS).toEqual(['mt_assuntos_view', 'mt_criar', 'mt_aprovar']);
  });
});
