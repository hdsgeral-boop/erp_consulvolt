import { describe, expect, it } from 'vitest';
import { ehContaRoot, podeBackofficeSuporte, podeConsolaPlataforma } from './areas';

describe('áreas do SaaS por conta', () => {
  it('Super Administrador da plataforma abre consola e suporte', () => {
    const u = { papel: 'SUPER_ADMINISTRADOR', area_plataforma: true, area_suporte: true };
    expect(podeConsolaPlataforma(u)).toBe(true);
    expect(podeBackofficeSuporte(u)).toBe(true);
  });

  it('Super Administrador de um cliente tem o papel mas não entra (o servidor devolve 403 a tudo)', () => {
    const u = { papel: 'SUPER_ADMINISTRADOR', area_plataforma: false, area_suporte: false };
    expect(podeConsolaPlataforma(u)).toBe(false);
    expect(podeBackofficeSuporte(u)).toBe(false);
  });

  it('operador abre a consola mas não o suporte; agente o contrário', () => {
    expect(podeConsolaPlataforma({ papel: 'OPERADOR_PLATAFORMA', area_plataforma: true })).toBe(true);
    expect(podeBackofficeSuporte({ papel: 'OPERADOR_PLATAFORMA', area_suporte: false })).toBe(false);
    expect(podeBackofficeSuporte({ papel: 'AGENTE_SUPORTE', area_suporte: true })).toBe(true);
    expect(podeConsolaPlataforma({ papel: 'AGENTE_SUPORTE', area_plataforma: false })).toBe(false);
  });

  it('servidor antigo sem os campos: cai no critério do papel; utilizador comum nunca entra', () => {
    expect(podeConsolaPlataforma({ papel: 'SUPER_ADMINISTRADOR' })).toBe(true);
    expect(podeBackofficeSuporte({ papel: 'AGENTE_SUPORTE' })).toBe(true);
    expect(podeConsolaPlataforma({ papel: 'UTILIZADOR', area_plataforma: true })).toBe(false);
    expect(podeConsolaPlataforma(null)).toBe(false);
    expect(podeBackofficeSuporte(undefined)).toBe(false);
  });
});

describe('conta root global (ADR-084)', () => {
  it('só o true literal do servidor conta: papel, nome ou outros valores nunca bastam', () => {
    expect(ehContaRoot({ papel: 'SUPER_ADMINISTRADOR', conta_root: true })).toBe(true);
    expect(ehContaRoot({ papel: 'SUPER_ADMINISTRADOR', conta_root: false })).toBe(false);
    expect(ehContaRoot({ papel: 'SUPER_ADMINISTRADOR' })).toBe(false);
    expect(ehContaRoot({ papel: 'SUPER_ADMINISTRADOR', conta_root: 'true' })).toBe(false);
    expect(ehContaRoot({ papel: 'SUPER_ADMINISTRADOR', conta_root: 1 })).toBe(false);
    expect(ehContaRoot({ papel: 'UTILIZADOR', nome_utilizador: 'admin' } as never)).toBe(false);
    expect(ehContaRoot(null)).toBe(false);
    expect(ehContaRoot(undefined)).toBe(false);
  });

  it('a root abre consola e suporte mesmo sem pertencer à plataforma (áreas a false no tenant de origem)', () => {
    const root = { papel: 'SUPER_ADMINISTRADOR', conta_root: true, area_plataforma: false, area_suporte: false };
    expect(podeConsolaPlataforma(root)).toBe(true);
    expect(podeBackofficeSuporte(root)).toBe(true);
  });

  it('o super-administrador de um cliente continua sem entrar: conta_root false não ajuda', () => {
    const cliente = { papel: 'SUPER_ADMINISTRADOR', conta_root: false, area_plataforma: false, area_suporte: false };
    expect(podeConsolaPlataforma(cliente)).toBe(false);
    expect(podeBackofficeSuporte(cliente)).toBe(false);
  });
});
