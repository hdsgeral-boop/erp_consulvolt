import { describe, expect, it } from 'vitest';
import type { Pendente } from './api';
import { camposClassificacao, chavePendente, motivoNaoLiquidavel, movimentoDeLiquidacao, totalLiquidacao, validarValor } from './caixaLiquidacao';
import { accoesSessao } from './regras';

const pendente = (extra: Partial<Pendente> = {}): Pendente => ({
  codigo_moeda: 'AOA', saldo_moeda: null, total_moeda: null, terceiro_id: 9, terceiro: ' Fornecedor B ', codigo_conta: '3211', numero_documento: 'FT 2026/15',
  data_documento: '2026-09-30', natureza: 'A_PAGAR', total: '1140.00', liquidado: '0.00', em_liquidacao: '0.00', saldo: '1140.00', liquidar_a: 'D', venda_id: null, fatura_compra_id: 77,
  ...extra,
});

describe('folha de caixa: pagar/receber facturas (A-11)', () => {
  it('só deixa liquidar documentos em Kz, com saldo e no sentido certo', () => {
    expect(motivoNaoLiquidavel(pendente(), 'PAGAR')).toBeNull();
    expect(motivoNaoLiquidavel(pendente(), 'RECEBER')).toMatch(/a pagar/);
    expect(motivoNaoLiquidavel(pendente({ codigo_moeda: 'USD' }), 'PAGAR')).toMatch(/USD/);
    expect(motivoNaoLiquidavel(pendente({ saldo: '0.00' }), 'PAGAR')).toMatch(/saldo/);
    expect(motivoNaoLiquidavel(pendente({ natureza: 'A_RECEBER', liquidar_a: 'C', venda_id: 3, fatura_compra_id: null }), 'RECEBER')).toBeNull();
  });

  it('valida o valor contra o saldo em aberto (tolerância de 1 cêntimo)', () => {
    expect(validarValor(1140, '1140.00')).toBeNull();
    expect(validarValor(1140.01, '1140.00')).toBeNull();
    expect(validarValor(1140.02, '1140.00')).toMatch(/Excede/);
    expect(validarValor(0, '10.00')).toMatch(/positivo/);
    expect(validarValor(null, '10.00')).toMatch(/positivo/);
  });

  it('monta o movimento ligado à factura, com descrição e referência do legado e as notas escolhidas', () => {
    expect(movimentoDeLiquidacao(pendente(), 500.005, 'PAGAR', { data: '2026-10-02', nota_fluxo_caixa_id: 4, nota_demonstracao_id: null })).toEqual({
      tipo: 'PAG', data_documento: '2026-10-02', conta_contrapartida: '3211', valor: 500.01, descricao: 'Pagamento FT 2026/15 - Fornecedor B', terceiro_id: 9,
      numero_documento: 'FT 2026/15', referencia: 'PG-DOC FT 2026/15', fatura_compra_id: 77, nota_fluxo_caixa_id: 4,
    });
    const rec = movimentoDeLiquidacao(pendente({ natureza: 'A_RECEBER', liquidar_a: 'C', codigo_conta: '3111', venda_id: 3, fatura_compra_id: null, terceiro: null }), 10, 'RECEBER', { data: '2026-10-02' });
    expect(rec).toMatchObject({ tipo: 'REC', venda_id: 3, descricao: 'Recebimento FT 2026/15', referencia: 'RC-DOC FT 2026/15' });
    expect(rec).not.toHaveProperty('fatura_compra_id');
  });

  it('soma em cêntimos e identifica cada documento por terceiro, conta e número', () => {
    expect(totalLiquidacao([0.1, 0.2, null, 1000])).toBe('1000.30');
    expect(chavePendente(pendente())).toBe('9|3211|FT 2026/15');
  });

  it('classificação: só os campos escolhidos, ou limpos quando pedido', () => {
    expect(camposClassificacao({ nota_fluxo_caixa_id: 4 })).toEqual({ nota_fluxo_caixa_id: 4 });
    expect(camposClassificacao({ nota_fluxo_caixa_id: 4, centro_custo_id: 2 }, ['centro_custo_id'])).toEqual({ nota_fluxo_caixa_id: 4, centro_custo_id: null });
    expect(camposClassificacao({})).toEqual({});
  });

  it('reabrir só sessões fechadas, com a permissão de fechar; classificar até contabilizar', () => {
    const so = (...p: string[]) => (...c: string[]) => c.some((x) => p.includes(x));
    expect(accoesSessao({ estado: 'FECHADA', movimentos: [{} as never] }, so('teso_caixa_fechar', 'teso_caixa_operar'))).toMatchObject({ podeReabrir: true, podeClassificar: true });
    expect(accoesSessao({ estado: 'FECHADA', movimentos: [] }, so('teso_caixa_operar')).podeReabrir).toBe(false);
    expect(accoesSessao({ estado: 'CONTABILIZADA', movimentos: [{} as never] }, () => true)).toMatchObject({ podeReabrir: false, podeClassificar: false });
    expect(accoesSessao({ estado: 'ABERTA', movimentos: [] }, () => true).podeClassificar).toBe(false);
  });
});
