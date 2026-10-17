import { describe, expect, it } from 'vitest';
import type { PeriodoSalarial } from '../api';
import { gruposPeriodos, periodoCorresponde, periodoPorOmissao } from './periodos';

/** RH › Calcular: escolher o período numa lista dos existentes (em vez de digitar o Mês/Ano). Dados fictícios. */
const per = (id: number, mes_ano: string, estado: PeriodoSalarial['estado']): PeriodoSalarial => ({
  id, mes_ano, estado, contabilizado: false, fechado_em: null, fechado_por: null, validado_em: null, validado_por: null, numero_lan_contabilizacao: null, modo_calculo: null,
});
const lista = [per(1, '01/2026', 'VALIDADO'), per(2, '02/2026', 'FECHADO'), per(3, '03/2026', 'ABERTO'), per(4, '12/2025', 'VALIDADO'), per(5, '04/2026', 'ABERTO')];

describe('períodos do Calcular', () => {
  it('agrupa por estado (abertos, encerrados, validados), do mais recente para o mais antigo, sem grupos vazios', () => {
    const g = gruposPeriodos(lista);
    expect(g.map((x) => x.estado)).toEqual(['ABERTO', 'FECHADO', 'VALIDADO']);
    expect(g[0].periodos.map((p) => p.mes_ano)).toEqual(['04/2026', '03/2026']);
    expect(g[2].periodos.map((p) => p.mes_ano)).toEqual(['01/2026', '12/2025']);
    expect(gruposPeriodos([per(1, '01/2026', 'FECHADO')]).map((x) => x.estado)).toEqual(['FECHADO']);
    expect(gruposPeriodos(undefined)).toEqual([]);
  });

  it('pesquisa por MM/AAAA (também 7/2026 ou 072026), nome do mês sem acentos, ano e estado', () => {
    const p = per(9, '03/2026', 'FECHADO');
    expect(periodoCorresponde('3/2026', p)).toBe(true);
    expect(periodoCorresponde('032026', p)).toBe(true);
    expect(periodoCorresponde('04/2026', p)).toBe(false);
    expect(periodoCorresponde('marco', p)).toBe(true);
    expect(periodoCorresponde('Março 2026', p)).toBe(true);
    expect(periodoCorresponde('fechado', p)).toBe(true);
    expect(periodoCorresponde('aberto', p)).toBe(false);
    expect(periodoCorresponde('', p)).toBe(true);
  });

  it('por omissão: o aberto mais recente; sem abertos, o mais recente', () => {
    expect(periodoPorOmissao(lista)?.id).toBe(5);
    expect(periodoPorOmissao([per(1, '01/2026', 'VALIDADO'), per(2, '02/2026', 'FECHADO')])?.id).toBe(2);
    expect(periodoPorOmissao([])).toBeUndefined();
  });
});
