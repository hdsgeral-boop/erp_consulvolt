import { describe, expect, it } from 'vitest';
import type { ModuloMenu } from '@/api/tipos';
import { caminhoInternoSeguro, disponibilidade, ecraDoCaminho, ecraPorNome, percentagemQuota, quotaEsgotada, resolverAccao, validarAnexos } from './regras';

const MENU: ModuloMenu[] = [
  { id: 'contab', nome: 'Contabilidade', ecras: [
    { id: 'contab_lancamentos', nome: 'Lançamentos', vistas: [], pai: null },
    { id: 'contab_plano', nome: 'Plano de Contas', vistas: [], pai: null },
    { id: 'contab_mapas', nome: 'Mapas Contabilísticos', vistas: [], pai: null },
    { id: 'contab_mapas_fiscais', nome: 'Mapas Fiscais', vistas: [], pai: null },
  ] },
];

describe('quota', () => {
  it('sem limite nunca esgota; com limite esgota ao atingi-lo', () => {
    expect(quotaEsgotada({ quota_tokens_mes: null, tokens_usados_mes: 999 })).toBe(false);
    expect(quotaEsgotada({ quota_tokens_mes: 1000, tokens_usados_mes: 999 })).toBe(false);
    expect(quotaEsgotada({ quota_tokens_mes: 1000, tokens_usados_mes: 1000 })).toBe(true);
    expect(quotaEsgotada(undefined)).toBe(false);
  });

  it('percentagem limitada a 100 e nula sem limite', () => {
    expect(percentagemQuota({ quota_tokens_mes: 200, tokens_usados_mes: 50 })).toBe(25);
    expect(percentagemQuota({ quota_tokens_mes: 200, tokens_usados_mes: 900 })).toBe(100);
    expect(percentagemQuota({ quota_tokens_mes: null, tokens_usados_mes: 5 })).toBeNull();
  });

  it('disponibilidade: a verificar, inactiva, quota esgotada ou disponível', () => {
    expect(disponibilidade(undefined, true)).toBe('a_verificar');
    expect(disponibilidade(undefined, false)).toBe('indisponivel');
    expect(disponibilidade({ ativo: false, quota_tokens_mes: null, tokens_usados_mes: 0 }, false)).toBe('indisponivel');
    expect(disponibilidade({ ativo: true, quota_tokens_mes: 10, tokens_usados_mes: 10 }, false)).toBe('quota_esgotada');
    expect(disponibilidade({ ativo: true, quota_tokens_mes: null, tokens_usados_mes: 10 }, false)).toBe('disponivel');
    // resposta inesperada (ex.: lista vazia de um mock) conta como inactivo
    expect(disponibilidade([] as never, false)).toBe('indisponivel');
  });
});

describe('ecraDoCaminho', () => {
  it('lê /m/{modulo}/{ecra} e ignora o resto', () => {
    expect(ecraDoCaminho('/m/contab/contab_plano')).toEqual({ modulo: 'contab', ecra: 'contab_plano' });
    expect(ecraDoCaminho('/m/contab/contab_plano/novo')).toEqual({ modulo: 'contab', ecra: 'contab_plano' });
    expect(ecraDoCaminho('/inicio')).toBeNull();
    expect(ecraDoCaminho('/m/contab')).toBeNull();
    expect(ecraDoCaminho('/admin/ia')).toBeNull();
  });
});

describe('validarAnexos', () => {
  it('aceita extensões permitidas até 10 MB e recusa o resto', () => {
    expect(validarAnexos([{ name: 'a.pdf', size: 1000 }, { name: 'B.XLSX', size: 5 }])).toEqual([]);
    expect(validarAnexos([{ name: 'a.exe', size: 1 }])[0].motivo).toMatch(/PDF/);
    expect(validarAnexos([{ name: 'grande.pdf', size: 10 * 1024 * 1024 + 1 }])[0].motivo).toMatch(/10 MB/);
    expect(validarAnexos([{ name: 'semextensao', size: 1 }])).toHaveLength(1);
    const seis = Array.from({ length: 6 }, (_, i) => ({ name: `f${i}.txt`, size: 1 }));
    expect(validarAnexos(seis)[0].motivo).toMatch(/No máximo 5/);
  });
});

describe('caminhoInternoSeguro', () => {
  it.each(['/terceiros', '/contabilidade/lancamentos', '/m/contab/contab_plano'])('aceita %s', (u) => expect(caminhoInternoSeguro(u)).toBe(true));
  it.each(['', 'terceiros', '//evil.com/x', 'https://evil.com', 'javascript:alert(1)', '/../etc', '/a/../b', '/a\\b', '/a\nb', '/https://x', undefined, 5])('recusa %s', (u) =>
    expect(caminhoInternoSeguro(u)).toBe(false));
});

describe('ecraPorNome', () => {
  it('encontra por nome (sem acentos/maiúsculas) ou id e só devolve resultado inequívoco', () => {
    expect(ecraPorNome(MENU, 'plano de contas')?.ecra).toBe('contab_plano');
    expect(ecraPorNome(MENU, 'LANCAMENTOS')?.ecra).toBe('contab_lancamentos');
    expect(ecraPorNome(MENU, 'contab_plano')?.modulo).toBe('contab');
    expect(ecraPorNome(MENU, 'Mapas')).toBeNull(); // ambíguo
    expect(ecraPorNome(MENU, 'inexistente')).toBeNull();
    expect(ecraPorNome(MENU, '  ')).toBeNull();
  });
});

describe('resolverAccao', () => {
  const base = { tipo: 'terceiro', metodo: 'POST', url: '/terceiros', dados: { nome: 'X' } };

  it('navegar resolve para /m/ pelo menu do utilizador (nunca para um ecrã que ele não vê)', () => {
    expect(resolverAccao({ tipo: 'navegar', metodo: 'GET', url: '', dados: { ecra: 'Plano de Contas' } }, MENU))
      .toEqual({ tipo: 'navegar', caminho: '/m/contab/contab_plano', rotulo: 'Plano de Contas' });
    expect(resolverAccao({ tipo: 'navegar', metodo: 'GET', url: '', dados: { ecra: 'Salários' } }, MENU).tipo).toBe('invalida');
    expect(resolverAccao({ tipo: 'navegar', metodo: 'GET', url: 'https://evil.com', dados: { ecra: 'x' } }, MENU).tipo).toBe('invalida');
    expect(resolverAccao({ tipo: 'navegar', metodo: 'GET', url: '/m/vendas/x', dados: {} }, MENU).tipo).toBe('navegar');
  });

  it('escritas só POST/PUT/DELETE para caminhos internos seguros', () => {
    expect(resolverAccao(base, MENU)).toEqual({ tipo: 'pedido', metodo: 'POST', url: '/terceiros', dados: { nome: 'X' } });
    expect(resolverAccao({ ...base, metodo: 'put' }, MENU)).toMatchObject({ tipo: 'pedido', metodo: 'PUT' });
    expect(resolverAccao({ ...base, metodo: 'GET' }, MENU).tipo).toBe('invalida');
    expect(resolverAccao({ ...base, metodo: 'PATCH' }, MENU).tipo).toBe('invalida');
    expect(resolverAccao({ ...base, url: 'https://evil.com/x' }, MENU).tipo).toBe('invalida');
    expect(resolverAccao({ ...base, url: '//evil.com/x' }, MENU).tipo).toBe('invalida');
    expect(resolverAccao({ ...base, url: '/ia/regras' }, MENU).tipo).toBe('invalida');
  });

  it('dados em formato inesperado viram objecto vazio', () => {
    expect(resolverAccao({ ...base, dados: [1, 2] as never }, MENU)).toMatchObject({ tipo: 'pedido', dados: {} });
  });
});
