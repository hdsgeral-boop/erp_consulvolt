import { describe, expect, it } from 'vitest';
import { alteraRegistos, CONTAS_MASSA, corpoEdicaoMassa, PREFIXO_CONTA_CATEGORIA, RETIRAR_CONTA } from './edicaoMassa';

const CAMPOS = ['codigo_conta', 'conta_custo', 'conta_inventario'];

describe('edição em massa (Sistema › Migração de dados)', () => {
  it('nos produtos não há contas do produto: a categoria sobrepõe-se', () => {
    expect(CONTAS_MASSA.produtos).toEqual([]);
    const c = corpoEdicaoMassa('produtos', [1, 2], { conta_codigo_conta: '611', categoria_produto_id: 5, taxa_imposto: 0 }, CAMPOS);
    expect(c.contas).toEqual({});
    expect(c.dados).toEqual({ categoria_produto_id: 5, taxa_imposto: 0 });
    expect(c.categoria_contas).toBeUndefined();
    expect(alteraRegistos(c)).toBe(true);
  });

  it('contas da categoria: só as preenchidas, com «retirar»', () => {
    const v = { categoria_contas_id: 9, [`${PREFIXO_CONTA_CATEGORIA}codigo_conta`]: '612', [`${PREFIXO_CONTA_CATEGORIA}conta_inventario`]: RETIRAR_CONTA, [`${PREFIXO_CONTA_CATEGORIA}conta_custo`]: undefined };
    const c = corpoEdicaoMassa('produtos', [], v, CAMPOS);
    expect(c.categoria_contas).toEqual({ categoria_produto_id: 9, contas: { codigo_conta: '612', conta_inventario: RETIRAR_CONTA } });
    expect(alteraRegistos(c)).toBe(false);
    // categoria escolhida sem contas preenchidas: nada a enviar
    expect(corpoEdicaoMassa('produtos', [], { categoria_contas_id: 9 }, CAMPOS).categoria_contas).toBeUndefined();
  });

  it('preço só nos produtos e com valor; terceiros mantêm as contas e a moeda', () => {
    expect(corpoEdicaoMassa('produtos', [1], { preco_modo: 'PERCENTAGEM', preco_valor: 0 }).preco).toEqual({ modo: 'PERCENTAGEM', valor: 0 });
    expect(corpoEdicaoMassa('produtos', [1], { preco_modo: 'DEFINIR' }).preco).toBeUndefined();
    const f = corpoEdicaoMassa('fornecedores', [3], { codigo_moeda: 'USD', conta_codigo_conta: '32121', conta_conta_compra_transitoria: '', categoria_contas_id: 9, taxa_imposto: 14 });
    expect(f).toEqual({ ids: [3], dados: { codigo_moeda: 'USD' }, contas: { codigo_conta: '32121' } });
  });
});
