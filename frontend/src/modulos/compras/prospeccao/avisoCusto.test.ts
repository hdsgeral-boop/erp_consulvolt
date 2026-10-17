import { describe, expect, it } from 'vitest';
import { avaliarLinhas, cambioKz, nivelDaRazao } from './avisoCusto';

const refs = { '1': { preco_kz: '100000.00', documentos: ['NE A2026/1'] }, '2': { preco_kz: '50000.00', documentos: ['NE A2026/1'] } };

describe('aviso de custo da proposta face à encomenda do cliente', () => {
  it('classifica: abaixo de 70 % ok, de 70 % a 100 % aviso, acima de 100 % alerta', () => {
    expect(nivelDaRazao(0.69)).toBe('ok');
    expect(nivelDaRazao(0.7)).toBe('aviso');
    expect(nivelDaRazao(1)).toBe('aviso');
    expect(nivelDaRazao(1.01)).toBe('acima');
    expect(nivelDaRazao(null)).toBe('ok');
  });

  it('compara o preço unitário em Kz com a referência da encomenda, por artigo', () => {
    const r = avaliarLinhas([{ produto_id: 1, preco_unitario: 120000 }, { produto_id: 2, preco_unitario: 36000 }, { produto_id: 3, preco_unitario: 5 }], refs, 1);
    expect(r.map((a) => [a.produto_id, a.nivel])).toEqual([[1, 'acima'], [2, 'aviso']]);
    expect(r[1].razao).toBeCloseTo(0.72);
  });

  it('converte a moeda estrangeira pelo câmbio e não compara sem câmbio', () => {
    expect(cambioKz('AOA')).toBe(1);
    expect(cambioKz('USD')).toBeNull();
    expect(avaliarLinhas([{ produto_id: 1, preco_unitario: 100 }], refs, cambioKz('USD', 900))[0].nivel).toBe('aviso');
    expect(avaliarLinhas([{ produto_id: 1, preco_unitario: 100 }], refs, null)[0].razao).toBeNull();
  });

  it('sem encomenda de origem não há avaliação', () => {
    expect(avaliarLinhas([{ produto_id: 1, preco_unitario: 1 }], undefined, 1)).toEqual([]);
    expect(avaliarLinhas([{ produto_id: 1, preco_unitario: 1 }], {}, 1)).toEqual([]);
  });
});
