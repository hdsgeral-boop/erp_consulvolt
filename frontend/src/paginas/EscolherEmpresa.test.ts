import { describe, expect, it } from 'vitest';
import { armazenamento } from '@/sessao/armazenamento';
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

describe('escolher empresa — empresas recentes primeiro', () => {
  it('põe as recentes no topo pela ordem de uso e mantém a ordem das restantes', () => {
    expect(ordenarPorRecentes(empresas as never, [3, 1]).map((e) => e.id)).toEqual([3, 1, 2]);
    expect(ordenarPorRecentes(empresas as never, []).map((e) => e.id)).toEqual([1, 2, 3]);
    expect(ordenarPorRecentes(empresas as never, [99, 2]).map((e) => e.id)).toEqual([2, 1, 3]);
  });

  it('o armazenamento guarda até 5 empresas por utilizador, sem repetir, da mais recente para a mais antiga', () => {
    localStorage.clear();
    for (const id of [1, 2, 3, 4, 5, 6, 3]) armazenamento.registarEmpresaRecente(10, id);
    expect(armazenamento.empresasRecentes(10)).toEqual([3, 6, 5, 4, 2]);
    expect(armazenamento.empresasRecentes(11)).toEqual([]);
  });
});
