import { describe, expect, it } from 'vitest';
import { SEPARADORES_ARMAZEM, SEPARADORES_INVENTARIO } from './BarraArmazem';
import { estadosFluxoRececao, FILTRO_SITUACAO, referenciaEncomenda, rotuloTipoMovimento, statusStock } from './paridade';

describe('paridade da Logística com o legado', () => {
  it('separadores com os rótulos e a ordem do legado', () => {
    expect(SEPARADORES_ARMAZEM.map((s) => s.rotulo)).toEqual(['níveis de Stock', 'Validar Entradas (Compras)', 'Guias de Saída (Entregas)', 'histórico', 'Config. Armazens']);
    expect(SEPARADORES_INVENTARIO.map((s) => [s.rotulo, s.icone])).toEqual([
      ['Sessões de Inventário', 'tasks'],
      ['Efetuar Contagem', 'list-ol'],
      ['Revisão e Regularização', 'balance-scale'],
    ]);
  });

  it('referência da encomenda com o número (não o id interno)', () => {
    expect(referenciaEncomenda({ numero_encomenda: 'ORD-4205', encomenda_compra_id: 49 })).toBe('#ORD-4205');
    expect(referenciaEncomenda({ numero_encomenda: null, encomenda_compra_id: 49 })).toBe('#49');
    expect(referenciaEncomenda({ numero_encomenda: null, encomenda_compra_id: null })).toBe('N/D');
  });

  it('filtro de situação começa em «Todas»', () => {
    expect(FILTRO_SITUACAO[0]).toEqual({ value: 'TODAS', label: 'Todas' });
    expect(FILTRO_SITUACAO.map((f) => f.value)).toContain('PENDENTE');
  });

  it('fluxo da recepção', () => {
    const base = { contabilizado: false, numero_encomenda: 'ORD-1', encomenda_compra_id: 1, numero_entrega: 'GF-1', armazem: 'Central' };
    const pend = estadosFluxoRececao({ ...base, situacao: 'PENDENTE' });
    expect([pend.estados.val.estado, pend.estados.cont.estado, pend.concluidas]).toEqual(['curso', 'fazer', 2]);
    const fim = estadosFluxoRececao({ ...base, situacao: 'VALIDADO', contabilizado: true });
    expect([fim.concluidas, fim.global.estado]).toEqual([4, 'concluida']);
    expect(estadosFluxoRececao({ ...base, situacao: 'ANULADO' }).global.estado).toBe('bloqueada');
  });

  it('tipo dos movimentos sem redundância e acertos da migração', () => {
    expect(rotuloTipoMovimento({ tipo: 'SAIDA', sentido: 'S', documento_tipo: null })).toBe('Saída');
    expect(rotuloTipoMovimento({ tipo: 'ENTRADA', sentido: 'E', documento_tipo: 'RECECAO' })).toBe('Entrada');
    expect(rotuloTipoMovimento({ tipo: 'AJUSTE', sentido: 'S', documento_tipo: 'AJUSTE' })).toBe('Ajuste (saída)');
    expect(rotuloTipoMovimento({ tipo: 'TRANSFERENCIA', sentido: 'E', documento_tipo: 'TRANSFERENCIA' })).toBe('Transferência (entrada)');
    expect(rotuloTipoMovimento({ tipo: 'AJUSTE', sentido: 'S', documento_tipo: 'MIGRACAO' })).toBe('Saldo inicial (migração)');
  });

  it('status do stock como no legado', () => {
    expect(statusStock('40').rotulo).toBe('OK');
    expect(statusStock(3).rotulo).toBe('Baixo');
    expect(statusStock('0').rotulo).toBe('Esgotado');
    expect(statusStock(-1).rotulo).toBe('Esgotado');
  });
});
