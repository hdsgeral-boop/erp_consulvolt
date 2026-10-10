import { describe, expect, it } from 'vitest';
import { SEPARADORES_LAV } from './Lavandaria';
import { atrasada, cartoesOrdens, corSituacao, detalheIndicadores, emCurso, ESTADOS_FILTRO } from './indicadores';
import type { IndicadoresOrdem } from './tipos';

const ind = (extra: Partial<IndicadoresOrdem> = {}): IndicadoresOrdem => ({
  responsavel: null,
  atribuida_em: null,
  pronta_em: null,
  entregue_em: null,
  dias_aberta: 23,
  atraso: 21,
  no_prazo: null,
  situacao: 'Atrasada 21d',
  ...extra,
});

describe('lavandaria — paridade com o legado (3.8)', () => {
  it('separadores do antigo pela mesma ordem, a abrir em «Peças»', () => {
    expect(SEPARADORES_LAV.map((s) => s.rotulo)).toEqual(['Peças', 'Serviços', 'Ordens de serviço', 'Relatórios', 'Danos e reclamações', 'Definições']);
  });

  it('cartões KPI das ordens com o prazo de levantamento configurado', () => {
    const c = cartoesOrdens(45);
    expect(c.map((x) => x.rotulo)).toEqual([
      'Recebidas hoje',
      'Sem responsável',
      'Orçamentos por aprovar',
      'Por executar / em execução',
      'Prontas para levantar',
      'Atrasadas',
      'Não levantadas (+45 dias)',
    ]);
    expect(cartoesOrdens(undefined).at(-1)?.rotulo).toBe('Não levantadas (+30 dias)');
    expect(c.every((x) => ESTADOS_FILTRO.some((e) => e.valor === x.filtro))).toBe(true);
  });

  it('indicadores, atraso e ordens em curso', () => {
    expect(detalheIndicadores(ind({ execucao: 2, espera_levantamento: 5 }))).toBe('23d em aberto · execução 2d · a aguardar levantamento 5d');
    expect(atrasada({ estado: 'EM_EXECUCAO', indicadores: ind() })).toBe(true);
    expect(atrasada({ estado: 'ENTREGUE', indicadores: ind() })).toBe(false);
    expect(atrasada({ estado: 'RECEBIDA', indicadores: ind({ pronta_em: '2026-10-01' }) })).toBe(false);
    expect(emCurso({ estado: 'ANULADA' })).toBe(false);
    expect(corSituacao('Atrasada 21d')).toBe('#b91c1c');
    expect(corSituacao('Concluída no prazo')).toBe('#059669');
  });
});
