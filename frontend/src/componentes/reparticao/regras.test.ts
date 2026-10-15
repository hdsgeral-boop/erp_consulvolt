import { describe, expect, it } from 'vitest';
import { alocacaoDeApi, alocacaoParaApi, alocacaoVazia, linhasRepartidas, somaCentimos, validarAlocacao } from './regras';
import { juntarColunas } from './TabelaPorUnidade';

// dados fictícios
describe('repartição por unidades de negócio — regras', () => {
  it('alocação vazia: sem critério ou sem unidades', () => {
    expect(alocacaoVazia(null)).toBe(true);
    expect(alocacaoVazia({ criterio_id: null, unidades: [{ unidade_negocio_id: 1 }] })).toBe(true);
    expect(alocacaoVazia({ criterio_id: 3, unidades: [{}, {}] })).toBe(true);
    expect(alocacaoVazia({ criterio_id: 3, unidades: [{ unidade_negocio_id: 1 }] })).toBe(false);
  });

  it('valida como o legado: ≥ 2 unidades, sem repetidas, sem negativos, pesos nas formas manuais', () => {
    const a = (unidades: { unidade_negocio_id?: number; peso?: number | null }[]) => ({ criterio_id: 1, unidades });
    expect(validarAlocacao(a([{ unidade_negocio_id: 1, peso: 1 }]), 'PERCENTAGEM')).toMatch(/duas unidades/);
    expect(validarAlocacao(a([{ unidade_negocio_id: 1, peso: 1 }, { unidade_negocio_id: 1, peso: 1 }]), 'PERCENTAGEM')).toMatch(/mais do que uma vez/);
    expect(validarAlocacao(a([{ unidade_negocio_id: 1, peso: -1 }, { unidade_negocio_id: 2, peso: 5 }]), 'AREA')).toMatch(/negativos/);
    expect(validarAlocacao(a([{ unidade_negocio_id: 1, peso: 0 }, { unidade_negocio_id: 2 }]), 'AREA')).toBe('Indique área (m²) em pelo menos uma unidade.');
    expect(validarAlocacao(a([{ unidade_negocio_id: 1 }, { unidade_negocio_id: 2 }]), 'PARTES_IGUAIS')).toBeNull();
    expect(validarAlocacao(a([{ unidade_negocio_id: 1, peso: 60 }, { unidade_negocio_id: 2, peso: 40 }]), 'PERCENTAGEM')).toBeNull();
    expect(validarAlocacao({ criterio_id: null, unidades: [] }, 'PERCENTAGEM')).toBeNull();   // sem critério = sem repartição
  });

  it('corpo para a API: linhas vazias fora e, nas formas automáticas, sem peso', () => {
    expect(alocacaoParaApi({ criterio_id: 2, unidades: [{ unidade_negocio_id: 5, peso: 9 }, {}, { unidade_negocio_id: 6, peso: '' }] }, 'COLABORADORES'))
      .toEqual({ criterio_id: 2, unidades: [{ unidade_negocio_id: 5, peso: null }, { unidade_negocio_id: 6, peso: null }] });
    expect(alocacaoParaApi({ criterio_id: 2, unidades: [{ unidade_negocio_id: 5, peso: '60,5' }, { unidade_negocio_id: 6, peso: 39.5 }] }, 'PERCENTAGEM'))
      .toEqual({ criterio_id: 2, unidades: [{ unidade_negocio_id: 5, peso: 60.5 }, { unidade_negocio_id: 6, peso: 39.5 }] });
    expect(alocacaoParaApi({ criterio_id: null, unidades: [{ unidade_negocio_id: 5 }] })).toBeNull();
  });

  it('lê a alocação gravada, incluindo texto JSON e a chave do legado business_unit_id', () => {
    expect(alocacaoDeApi('{"criterio_id":4,"unidades":[{"business_unit_id":7,"peso":"60.0000"},{"unidade_negocio_id":8,"peso":null}]}'))
      .toEqual({ criterio_id: 4, unidades: [{ unidade_negocio_id: 7, peso: 60 }, { unidade_negocio_id: 8, peso: null }] });
    expect(alocacaoDeApi('não é JSON')).toBeNull();
    expect(alocacaoDeApi(null)).toBeNull();
  });

  it('«Repartir linha»: substitui a linha por uma por unidade com o mesmo total e a descrição «xx,xx% unidade»', () => {
    const linha = { codigo_conta: '6211', tipo_dc: 'D' as const, valor: 100, descricao: 'Renda da sede', terceiro_id: 9 };
    const novas = linhasRepartidas(linha, { linhas: [
      { unidade_negocio_id: 1, nome: 'UN1 — Comercial', indutor: '1.0000', percentagem: '33.3333', valor: '33.33' },
      { unidade_negocio_id: 2, nome: 'UN2 — Industrial', indutor: '1.0000', percentagem: '33.3333', valor: '33.33' },
      { unidade_negocio_id: 3, nome: 'UN3 — Serviços', indutor: '1.0000', percentagem: '33.3334', valor: '33.34' },
    ] });
    expect(novas).toHaveLength(3);
    expect(somaCentimos(novas.map((l) => l.valor))).toBe(10000);
    expect(novas[0]).toEqual({ ...linha, unidade_negocio_id: 1, valor: 33.33, descricao: 'Renda da sede · 33,33% UN1 — Comercial' });
    expect(novas.every((l) => l.codigo_conta === '6211' && l.tipo_dc === 'D' && l.terceiro_id === 9)).toBe(true);
    // parcelas a zero não geram linha; sem descrição fica só a percentagem
    const pequenas = linhasRepartidas({ valor: 0.01 }, { linhas: [
      { unidade_negocio_id: 1, nome: 'UN1', indutor: '1', percentagem: '50.0000', valor: '0.00' },
      { unidade_negocio_id: 2, nome: 'UN2', indutor: '1', percentagem: '50.0000', valor: '0.01' },
    ] });
    expect(pequenas).toEqual([{ unidade_negocio_id: 2, valor: 0.01, descricao: '50,00% UN2' }]);
  });

  it('mapas por unidade: a estrutura vem do Total e cada coluna é alinhada pela chave da linha', () => {
    type M = { v: Record<string, string> };
    const linhas = (m: M) => [
      { chave: 's', tipo: 'seccao' as const, descricao: 'Proveitos' },
      { chave: '22', tipo: 'linha' as const, descricao: 'Vendas', nota: '22', atual: m.v['22'] },
      { chave: 't', tipo: 'total' as const, descricao: 'Total', atual: m.v.t },
    ];
    const filas = juntarColunas<M>({ colunas: [
      { unidade_negocio_id: 1, rotulo: 'UN1', mapa: { v: { '22': '600.00', t: '600.00' } } },
      { unidade_negocio_id: null, rotulo: 'Sem unidade', mapa: { v: { '22': '0.00', t: '0.00' } } },
    ], total: { v: { '22': '600.00', t: '600.00' } } }, linhas);
    expect(filas.map((f) => [f.chave, f.valores, f.total])).toEqual([['s', [undefined, undefined], undefined], ['22', ['600.00', '0.00'], '600.00'], ['t', ['600.00', '0.00'], '600.00']]);
  });
});
