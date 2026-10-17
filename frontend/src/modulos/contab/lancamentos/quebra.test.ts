import { describe, expect, it } from 'vitest';
import { estadoInventario } from './ferramentas';
import { intervaloPeriodo, PERIODOS } from './filtros';
import { documentoQuebra, filtrosDoGrupo, rotuloGrupo, VISTAS_LANCAMENTOS, type GrupoQuebra } from './quebra';

const grupo = (p: Partial<GrupoQuebra>): GrupoQuebra => ({ chave: 1, codigo: '500', nome: 'Cliente', linhas: 1, debito: '10.00', credito: '0.00', saldo: '10.00', ...p });

describe('quebra dos lançamentos por terceiro / unidade de negócio / centro de custo', () => {
  it('as vistas são a lista e as três quebras', () => {
    expect(VISTAS_LANCAMENTOS.map((v) => v.rotulo)).toEqual(['Linhas', 'Quebra por Terceiro', 'Quebra por Unidade de Negócio', 'Quebra por Centro de Custo']);
  });

  it('o filtro de cada grupo junta a dimensão aos filtros aplicados; o grupo «sem …» usa o filtro «sem»', () => {
    const f = { data_inicio: '2026-01-01', sem: ['demo'] };
    expect(filtrosDoGrupo(f, 'terceiro', 7)).toEqual({ data_inicio: '2026-01-01', sem: ['demo'], terceiro_id: 7 });
    expect(filtrosDoGrupo(f, 'unidade', null)).toEqual({ data_inicio: '2026-01-01', sem: ['demo', 'un'], unidade_negocio_id: undefined });
    expect(filtrosDoGrupo({ sem: ['cc'] }, 'centro', null).sem).toEqual(['cc']);
    expect(filtrosDoGrupo({}, 'terceiro', null)).toEqual({ terceiro_id: undefined, sem: ['terceiro'] });
  });

  it('rótulo do grupo: «código — nome»; o grupo vazio só com o nome', () => {
    expect(rotuloGrupo(grupo({}))).toBe('500 — Cliente');
    expect(rotuloGrupo(grupo({ chave: null, codigo: null, nome: 'Sem terceiro' }))).toBe('Sem terceiro');
  });

  it('documento de impressão: grupos, subtotais e total geral, com o texto escapado', () => {
    const g = grupo({ nome: '<b>X</b>', detalhe: [{ id: 1, data_documento: '2026-03-01', diario: 'OD', numero_lan: 'OD1', numero_documento: null, codigo_conta: '311', descricao: 'a & b', tipo_dc: 'D', valor: '10.00' }] });
    const total = { grupos: 1, linhas: 1, debito: '10.00', credito: '0.00', saldo: '10.00' };
    const html = documentoQuebra('terceiro', [g], total, true);
    expect(html).toContain('class="imp-grupo"');
    expect(html).toContain('class="imp-subtotal"');
    expect(html).toContain('class="imp-total"');
    expect(html).toContain('&lt;b&gt;X&lt;/b&gt;');
    expect(html).toContain('a &amp; b');
    expect(html).not.toContain('<b>X</b>');
    expect(html).toContain('data-xv="10.00" data-xt="n"');
    const resumo = documentoQuebra('unidade', [g], total, false);
    expect(resumo).toContain('<th>Unidade de negócio</th>');
    expect(resumo).toContain('TOTAL GERAL (1 grupo(s))');
    expect(resumo).not.toContain('imp-grupo');
  });
});

describe('paridade da lista com o legado', () => {
  it('períodos 13 (Aj. Enc.) e 14 (Aj. Esp.) caem em Dezembro', () => {
    expect(PERIODOS.map((p) => p.label).slice(-2)).toEqual(['13 (Aj. Enc.)', '14 (Aj. Esp.)']);
    expect(intervaloPeriodo(2026, 13)).toEqual({ inicio: '2026-12-01', fim: '2026-12-31' });
    expect(intervaloPeriodo(2026, 14)).toEqual({ inicio: '2026-12-01', fim: '2026-12-31' });
    expect(intervaloPeriodo(2026, 2)).toEqual({ inicio: '2026-02-01', fim: '2026-02-28' });
  });

  it('«Inventariar» / «OK» só nas linhas de aquisição 11/12 a débito', () => {
    expect(estadoInventario({ codigo_conta: '1131', tipo_dc: 'D', por_inventariar: '500.00' })).toBe('inventariar');
    expect(estadoInventario({ codigo_conta: '1211', tipo_dc: 'D', por_inventariar: '0.00' })).toBe('ok');
    expect(estadoInventario({ codigo_conta: '1131', tipo_dc: 'C', por_inventariar: '500.00' })).toBeNull();
    expect(estadoInventario({ codigo_conta: '311', tipo_dc: 'D', por_inventariar: '500.00' })).toBeNull();
    expect(estadoInventario({ codigo_conta: '1131', tipo_dc: 'D' })).toBeNull();
  });
});
