import { describe, expect, it } from 'vitest';
import { linhasTabelaControlo } from './comum/controlo';
import type { LinhaControlo, Previsao } from './comum/tipos';
import { chaveSerie, rotuloRevisao, seriesERevisoes } from './Previsoes';
import { textoAccao } from './Alertas';

const linha = (id: number, natureza: LinhaControlo['natureza'], orcado: string, realizado: string): LinhaControlo => ({
  rubrica_id: id, codigo: `R${id}`, nome: `Rubrica ${id}`, natureza, grupo: null, orcado, orcado_inicial: null, realizado, desvio: String(Number(realizado) - Number(orcado)),
  execucao_pct: null, desvio_pct: null, favoravel: true, desvio_significativo: false, mensal: { orcado: [], realizado: [] },
});
const previsao = (id: number, revisao: number, p: Partial<Previsao> = {}): Previsao => ({
  id, tipo: 'EXPLORACAO', unidade_negocio_id: null, centro_custo_id: null, projeto_id: null, nome: 'Empresa', mes_referencia: '2026-09', revisao, estado: 'RASCUNHO',
  metodo: null, crescimento_pct: null, notas: null, criado_por: null, publicado_por: null, publicado_em: null, ...p,
});

describe('Controlo — tabela com a disposição do legado', () => {
  it('natureza → rubricas → «Total <natureza>» e, no fim, o resultado', () => {
    const c = { orcamento: { id: 1, ano: 2026, tipo: 'EXPLORACAO', nome: null, versao: 1, estado: 'APROVADO', unidade_negocio_id: null, centro_custo_id: null, projeto_id: null },
      linhas: [linha(1, 'PROVEITO', '1000', '1200'), linha(2, 'CUSTO', '600', '500')] } as Parameters<typeof linhasTabelaControlo>[0];
    const l = linhasTabelaControlo(c);
    expect(l.map((x) => x.tipo_linha ?? x.codigo)).toEqual(['natureza', 'R1', 'total', 'natureza', 'R2', 'total', 'resultado']);
    expect(l[0].nome).toBe('Proveitos');
    expect(l[2].nome).toBe('Total proveitos');
    expect(l[6]).toMatchObject({ nome: 'Resultado', orcado: '400', realizado: '700', desvio: '300' });
    expect(l[2].execucao_pct).toBe(120);
  });
  it('tesouraria: «Fluxo de caixa» no fim', () => {
    const c = { orcamento: { tipo: 'TESOURARIA' }, linhas: [linha(1, 'RECEBIMENTO', '10', '10')] } as Parameters<typeof linhasTabelaControlo>[0];
    expect(linhasTabelaControlo(c).at(-1)?.nome).toBe('Fluxo de caixa');
  });
});

describe('Previsões — selectores «Série» e «Revisão» do legado', () => {
  it('uma série por tipo + dimensões; revisões da mais recente para a mais antiga', () => {
    const lista = [previsao(1, 1, { estado: 'PUBLICADA' }), previsao(2, 2), previsao(3, 1, { unidade_negocio_id: 4, nome: 'UN Norte' })];
    const r = seriesERevisoes(lista);
    expect(r.series.map((s) => s.rotulo)).toEqual(['Exploração · Toda a empresa — Empresa', 'Exploração · UN 4 — UN Norte']);
    expect(r.revisoes.map((p) => p.id)).toEqual([2, 1]);
    expect(seriesERevisoes(lista, chaveSerie(lista[2])).revisoes.map((p) => p.id)).toEqual([3]);
    expect(rotuloRevisao(lista[0])).toMatch(/^Rev\. 1 · real até .+ 2026 · publicada$/);
  });
});

describe('Alertas — acções do registo por extenso', () => {
  it('mapa ACC do legado', () => {
    expect(textoAccao('CONTINUOU')).toBe('Continuou após aviso');
    expect(textoAccao('APROVOU_NO_ACTO')).toBe('Aprovou no acto');
    expect(textoAccao(null)).toBe('—');
  });
});
