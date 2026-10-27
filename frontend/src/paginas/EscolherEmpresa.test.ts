import { describe, expect, it } from 'vitest';
import { filtrarEmpresas, normalizarPesquisa, ordenarPorRecentes } from './EscolherEmpresa';

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

describe('escolher empresa — recentes', () => {
  it('põe as recentes primeiro, pela ordem de uso, e mantém a ordem das restantes', () => {
    expect(ordenarPorRecentes(empresas, [3, 2]).map((e) => e.id)).toEqual([3, 2, 1]);
  });

  it('ignora recentes a que já não tem acesso', () => {
    expect(ordenarPorRecentes(empresas, [9, 2]).map((e) => e.id)).toEqual([2, 1, 3]);
  });

  it('sem recentes mantém a ordem recebida', () => {
    expect(ordenarPorRecentes(empresas, []).map((e) => e.id)).toEqual([1, 2, 3]);
  });
});
