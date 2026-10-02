import { describe, expect, it } from 'vitest';
import { grelhaHtml, linhasGrelha } from './impressao';
import type { Rubrica } from './tipos';

const rubrica = (id: number, codigo: string, natureza: Rubrica['natureza'], grupo: string): Rubrica => ({
  id, tipo: 'EXPLORACAO', codigo, nome: `Rubrica ${codigo}`, natureza, grupo, contas: [], ordem: id, ativo: true, descricao: null, controlo: null, indutor: null, cambial_pct: null, variavel_pct: null,
});
const rubricas = [rubrica(1, 'P01', 'PROVEITO', 'Proveitos'), rubrica(2, 'C01', 'CUSTO', 'Custos')];
const valores = { 1: { valores: Array(12).fill(100), notas: 'Base' }, 2: { valores: Array(12).fill(40), notas: null } };

describe('impressão da grelha mensal do orçamento', () => {
  it('linhas: grupos, rubricas e totais (resultado = proveitos − custos)', () => {
    const l = linhasGrelha('EXPLORACAO', rubricas, valores);
    expect(l.map((x) => x.rotulo)).toEqual(['Proveitos', 'P01 Rubrica P01', 'Custos', 'C01 Rubrica C01', 'Total proveitos', 'Total custos', 'Resultado']);
    expect(l[6].valores[0]).toBe(60);
  });

  it('HTML: 12 meses + total, grupos e totais marcados, notas opcionais', () => {
    const html = grelhaHtml('EXPLORACAO', rubricas, valores, true);
    expect(html).toContain('>Dez</th>');
    expect(html).toContain('>Notas</th>');
    expect(html.match(/class="imp-grupo"/g)).toHaveLength(2);
    expect(html.match(/class="imp-subtotal"/g)).toHaveLength(3);
    expect(html).toMatch(/720,00/);
    expect(grelhaHtml('TESOURARIA', rubricas, valores)).toContain('Saldo do período');
  });
});
