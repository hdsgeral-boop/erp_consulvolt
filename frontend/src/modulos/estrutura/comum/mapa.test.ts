import { linhasPorUnidade, somarContagens, taxaOcupacao, type UnidadeMapa } from './mapa';

const u = (id: number, pai: number | null, previstos: number, ocupados: number, massa?: string, extra: Partial<UnidadeMapa> = {}): UnidadeMapa => ({
  unidade_organica_id: id, codigo: `U${id}`, nome: `Unidade ${id}`, unidade_organica_pai_id: pai, ativo: true,
  previstos, ocupados, em_aberto: Math.max(0, previstos - ocupados), acima: Math.max(0, ocupados - previstos), postos: previstos ? 1 : 0, colaboradores: ocupados,
  ...(massa !== undefined ? { massa_salarial: massa } : {}), ...extra,
});

describe('mapa de pessoal por unidade (GET /rh/estrutura/mapa)', () => {
  it('ordena em árvore e acumula o ramo, com a massa salarial ao cêntimo', () => {
    const l = linhasPorUnidade([u(3, 1, 2, 3, '0.10'), u(1, null, 1, 1, '100.10'), u(2, 1, 3, 1, '0.20')]);
    expect(l.map((x) => [x.unidade.unidade_organica_id, x.nivel])).toEqual([[1, 0], [3, 1], [2, 1]]);
    expect(l[0].ramo).toMatchObject({ previstos: 6, ocupados: 5, em_aberto: 2, acima: 1, colaboradores: 5, massa_salarial: '100.40' });
    expect(l[0].propria.previstos).toBe(1);
  });

  it('sem salários não inventa massa; pai inexistente, ciclos e inactivas', () => {
    const r = linhasPorUnidade([u(1, 99, 1, 0), u(2, 3, 1, 1), u(3, 2, 1, 1), u(4, 1, 5, 5, undefined, { ativo: false })], true);
    expect(r.map((x) => x.unidade.unidade_organica_id).sort()).toEqual([1, 2, 3]);
    expect(r.find((x) => x.unidade.unidade_organica_id === 1)?.ramo.massa_salarial).toBeUndefined();
    expect(r.find((x) => x.unidade.unidade_organica_id === 1)?.nivel).toBe(0);
  });

  it('soma e taxa de ocupação', () => {
    expect(somarContagens(u(1, null, 2, 1, '0.10'), u(2, null, 1, 1, '0.20')).massa_salarial).toBe('0.30');
    expect(taxaOcupacao({ previstos: 3, ocupados: 2 })).toBe(67);
    expect(taxaOcupacao({ previstos: 0, ocupados: 2 })).toBeNull();
  });
});
