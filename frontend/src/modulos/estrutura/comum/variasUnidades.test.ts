import { describe, expect, it } from 'vitest';
import { ESTRUTURA_BASE, lerListaColada, validarNovasUnidades } from './variasUnidades';

describe('várias unidades e estrutura base', () => {
  it('lê a lista colada do Excel (código e nome opcionais)', () => {
    expect(lerListaColada('DFIN\tFinanças\nCompras\n\n')).toEqual([{ codigo: 'DFIN', nome: 'Finanças' }, { nome: 'Compras' }]);
  });

  it('valida nomes repetidos no mesmo nível e códigos repetidos na empresa', () => {
    const existentes = [{ nome: 'Compras', codigo: 'DCOMP', unidade_organica_pai_id: 1 }];
    expect(validarNovasUnidades([{ nome: 'compras ' }], 1, existentes)).toHaveLength(1);
    expect(validarNovasUnidades([{ nome: 'Compras' }], 2, existentes)).toEqual([]);
    expect(validarNovasUnidades([{ nome: 'A', codigo: 'dcomp' }, { nome: 'A' }], null, existentes)).toHaveLength(2);
    expect(validarNovasUnidades([{ nome: '  ' }], null, [])).toEqual(['Indique pelo menos uma unidade.']);
  });

  it('a estrutura base tem os pais declarados antes dos filhos', () => {
    const vistos = new Set<string>();
    for (const [chave, , , pai] of ESTRUTURA_BASE) {
      if (pai) expect(vistos.has(pai)).toBe(true);
      vistos.add(chave);
    }
    expect(ESTRUTURA_BASE).toHaveLength(11);
  });
});
