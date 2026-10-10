import { describe, expect, it } from 'vitest';
import type { ModuloMenu } from '@/api/tipos';
import { temIconeFa } from '@/componentes/fluxos/IconeFa';
import { separadorDaQuery } from '@/modulos/teso/pagamentos/ListaDocumentos';
import { ICONES_PENDENTES, MODULOS_INICIO, modulosDoInicio, textoComunicado } from './Inicio';

const m = (id: string, nome = id): ModuloMenu => ({ id, nome, ecras: [{ id: `${id}_x`, nome: 'x', vistas: [], pai: null }] });

describe('Início (0.3)', () => {
  it('lista «Módulos» pela ordem e com os nomes do legado; Geral fora; módulos novos no fim', () => {
    const menu = [m('geral'), m('contab', 'Contabilidade'), m('teso'), m('pos', 'POS, Lavandaria e Hotelaria'), m('rh'), m('stock'), m('futuro', 'Futuro')];
    expect(modulosDoInicio(menu).map((x) => x.nome)).toEqual(['Recursos Humanos', 'Tesouraria', 'Ponto de Venda (POS)', 'Contabilidade Geral', 'Logística', 'Futuro']);
  });

  it('comunicado da avaliação só aparece com texto (sem barra amarela vazia)', () => {
    expect(textoComunicado(null)).toBeNull();
    expect(textoComunicado({ nome: 'Ciclo 2026', comunicado: null })).toBeNull();
    expect(textoComunicado({ nome: 'Ciclo 2026', comunicado: { titulo: '', texto: '   ' } })).toBeNull();
    expect(textoComunicado({ nome: 'Ciclo 2026', comunicado: 'Leia o regulamento.' })).toEqual({ titulo: 'Avaliação de desempenho — Ciclo 2026', texto: 'Leia o regulamento.' });
    expect(textoComunicado({ comunicado: { titulo: 'Aviso', texto: 'Texto' } })).toEqual({ titulo: 'Aviso', texto: 'Texto' });
  });

  it('cada pendente e cada módulo tem um ícone desenhado', () => {
    for (const [nome] of Object.values(ICONES_PENDENTES)) expect(temIconeFa(nome), nome).toBe(true);
    for (const d of MODULOS_INICIO) expect(temIconeFa(d.icone), d.icone).toBe(true);
    expect(Object.keys(ICONES_PENDENTES)).toContain('portal_pedidos');
  });

  it('Tesouraria › Pagamentos/Recebimentos abre filtrado pela query string', () => {
    expect(separadorDaQuery('PAGAMENTO')).toBe('PAGAMENTO');
    expect(separadorDaQuery('RECEBIMENTO')).toBe('RECEBIMENTO');
    expect(separadorDaQuery(null)).toBe('TODOS');
    expect(separadorDaQuery('<script>')).toBe('TODOS');
  });
});
