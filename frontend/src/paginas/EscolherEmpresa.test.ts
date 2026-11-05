import { describe, expect, it } from 'vitest';
import { filtrarEmpresas, normalizarPesquisa, ordenarAlfabeticamente, ordenarPorRecentes } from './EscolherEmpresa';

const empresas = [
  { id: 1, nome: 'Demo Comércio, Lda', nif: '5999000001' },
  { id: 2, nome: 'Serviços de Limpeza Exemplo', nif: '5999000002' },
  { id: 3, nome: 'Engenharia Fictícia, SA', nif: null },
];

describe('escolher empresa — pesquisa', () => {
  it('normaliza acentos, maiúsculas e espaços', () => {
    expect(normalizarPesquisa('  Comércio   ÁGUA ')).toBe('comercio agua');
  });

  it('sem pesquisa devolve todas', () => {
    expect(filtrarEmpresas(empresas, '   ')).toHaveLength(3);
  });

  it('encontra pelo nome sem distinguir acentos', () => {
    expect(filtrarEmpresas(empresas, 'comercio').map((e) => e.id)).toEqual([1]);
    expect(filtrarEmpresas(empresas, 'SERVICOS').map((e) => e.id)).toEqual([2]);
  });

  it('encontra pelo NIF e exige todas as palavras', () => {
    expect(filtrarEmpresas(empresas, '000002').map((e) => e.id)).toEqual([2]);
    expect(filtrarEmpresas(empresas, 'demo lda').map((e) => e.id)).toEqual([1]);
    expect(filtrarEmpresas(empresas, 'demo sa')).toHaveLength(0);
  });
});

describe('escolher empresa — empresas recentes primeiro', () => {
  it('põe as recentes no topo pela ordem de uso e mantém a ordem das restantes', () => {
    expect(ordenarPorRecentes(empresas as never, [3, 1]).map((e) => e.id)).toEqual([3, 1, 2]);
    expect(ordenarPorRecentes(empresas as never, []).map((e) => e.id)).toEqual([1, 2, 3]);
    expect(ordenarPorRecentes(empresas as never, [99, 2]).map((e) => e.id)).toEqual([2, 1, 3]);
  });
});

describe('escolher empresa — ordem alfabética', () => {
  it('ordena por nome sem distinguir acentos nem maiúsculas e desempata pelo id', () => {
    const lista = [
      { id: 5, nome: 'Zeta Lda' },
      { id: 2, nome: 'Água Viva' },
      { id: 4, nome: 'alfa Comércio' },
      { id: 3, nome: 'Ábaco' },
      { id: 1, nome: 'Alfa comércio' },
    ];
    expect(ordenarAlfabeticamente(lista).map((e) => e.id)).toEqual([3, 2, 1, 4, 5]);
  });

  it('não altera a lista original', () => {
    const lista = [{ id: 2, nome: 'B' }, { id: 1, nome: 'A' }];
    ordenarAlfabeticamente(lista);
    expect(lista.map((e) => e.id)).toEqual([2, 1]);
  });
});
