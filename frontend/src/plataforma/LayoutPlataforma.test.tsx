import { describe, expect, it } from 'vitest';
import { itensConsola } from './LayoutPlataforma';
import { ENDERECO_ECRA_PLATAFORMA } from './enderecosPlataforma';

const chaves = (itens: ReturnType<typeof itensConsola>) => itens.filter((i) => i && 'key' in i && i.key).map((i) => (i as { key: string }).key);

describe('menu da consola /admin', () => {
  it('super-administrador vê a consola completa, com todos os ecrãs que saíram do menu do cliente', () => {
    const k = chaves(itensConsola(true, new Set()));
    for (const caminho of Object.values(ENDERECO_ECRA_PLATAFORMA)) expect(k).toContain(caminho);
    expect(k).toContain('/admin/agentes-suporte');
  });

  it('operador só vê os ecrãs que o servidor lhe devolve, sem divisórias soltas', () => {
    const itens = itensConsola(false, new Set(['plataforma_clientes', 'plataforma_auditoria']));
    expect(chaves(itens)).toEqual(['/admin/clientes', '/admin/auditoria']);
    expect(itens.some((i) => i && 'type' in i && i.type === 'divider')).toBe(true);
    expect((itens[0] as { type?: string }).type).not.toBe('divider');
    expect((itens[itens.length - 1] as { type?: string }).type).not.toBe('divider');
    expect(chaves(itensConsola(false, new Set()))).toEqual([]);
  });
});
