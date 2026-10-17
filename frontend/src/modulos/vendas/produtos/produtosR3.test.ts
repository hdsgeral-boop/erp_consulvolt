import { describe, expect, it } from 'vitest';
import { CAMPOS_CATEGORIA, camposProdutoLinhas, contaNaClasse, corpoCategoria, corpoProduto, corpoProdutoLinha, erroNiveis, produtoParaFormulario, type ProdutoFicha } from './formularioProduto';
import { pedidoFichaTecnica, textoDimensoes } from './fichaTecnica';

const ficha: ProdutoFicha = {
  id: 7, codigo: 'B1', nome: 'Bomba <CR>', preco_unitario: '1000.00', taxa_imposto: '14.0000', categoria_produto_id: 2, categoria: 'Bombas',
  movimenta_stock: true, e_servico: false, e_quarto: false, e_ativo_imobilizado: false, bloqueado: false, unidade_fe: 'UN', tipo_operacao_fe: null, codigo_isencao_fe: null,
  contas: { venda: '611', custo: '711', compra: null, inventario: '261', iva_liquidado: '3453', iva_dedutivel: null, quebra: null, sobra: null, ativo: null },
  contas_origem: { venda: 'categoria', custo: 'produto' },
  niveis_stock: { stock_minimo: '2.000', stock_seguranca: '4.000', ponto_encomenda: '6.000', stock_maximo: '20.000' },
  ficha_tecnica: { marca: 'Grundfos', modelo: 'CR-5', comprimento: '30.000', largura: null, altura: '50.000', unidade_dimensao: 'cm', peso_liquido: '12.500', peso_bruto: null,
    caracteristicas: [{ chave: 'Potência', valor: '1,5 kW' }], observacoes_tecnicas: 'Uso <industrial>' },
};

describe('níveis de stock (mínimo ≤ segurança ≤ ponto de encomenda ≤ máximo)', () => {
  it('aceita os preenchidos por ordem e ignora os vazios', () => {
    expect(erroNiveis({ stock_minimo: 2, stock_seguranca: 4, ponto_encomenda: 6, stock_maximo: 20 })).toBeNull();
    expect(erroNiveis({ stock_minimo: 2, stock_maximo: 20 })).toBeNull();
    expect(erroNiveis({})).toBeNull();
  });
  it('recusa a ordem errada e os negativos', () => {
    expect(erroNiveis({ stock_minimo: 10, stock_seguranca: 5 })).toMatch(/Stock de segurança não pode ser inferior ao stock mínimo/);
    expect(erroNiveis({ ponto_encomenda: 8, stock_maximo: 3 })).toMatch(/Stock máximo/);
    expect(erroNiveis({ stock_minimo: -1 })).toMatch(/negativo/);
  });
});

describe('produto: categoria obrigatória, sem contas, com níveis e ficha técnica', () => {
  it('o formulário traz os níveis e a ficha; o corpo não leva contas', () => {
    const v = produtoParaFormulario(ficha);
    expect(v).toMatchObject({ stock_minimo: 2, ponto_encomenda: 6, marca: 'Grundfos', comprimento: 30, caracteristicas: [{ chave: 'Potência', valor: '1,5 kW' }] });
    const c = corpoProduto({ ...v, caracteristicas: [...(v.caracteristicas ?? []), { chave: ' ', valor: '' }] });
    expect(c).toMatchObject({ categoria_produto_id: 2, stock_maximo: 20, marca: 'Grundfos', unidade_dimensao: 'cm', caracteristicas: [{ chave: 'Potência', valor: '1,5 kW' }] });
    expect(Object.keys(c).some((k) => k.startsWith('conta_') || k === 'codigo_conta')).toBe(false);
  });
  it('sem gerir stock os níveis vão vazios', () => {
    expect(corpoProduto({ ...produtoParaFormulario(ficha), movimenta_stock: false })).toMatchObject({ stock_minimo: null, stock_maximo: null });
  });
  it('vista Linhas: categoria obrigatória, sem colunas de contas, sem ficha técnica', () => {
    const campos = camposProdutoLinhas([{ id: 1, nome: 'Geral' }]);
    expect(campos.find((c) => c.nome === 'categoria_produto_id')?.obrigatorio).toBe(true);
    expect(campos.some((c) => c.nome.startsWith('conta_') || c.nome === 'codigo_conta')).toBe(false);
    expect(corpoProdutoLinha({ codigo: 'X', nome: 'Y', categoria_produto_id: 1, ponto_encomenda: 5 })).not.toHaveProperty('marca');
  });
});

describe('categorias com contas', () => {
  it('classe da conta e corpo do pedido', () => {
    expect(contaNaClasse('2611', ['22', '23', '24', '25', '26', '27'])).toBe(true);
    expect(contaNaClasse('2111', ['22', '23', '24', '25', '26', '27'])).toBe(false);
    expect(contaNaClasse('', ['6'])).toBe(true);
    expect(corpoCategoria({ nome: ' Bebidas ', codigo_conta: '61.1' })).toEqual({ nome: 'Bebidas', codigo_conta: '611' });
    const completo = corpoCategoria({ nome: 'Bebidas', conta_custo: '' }, true);
    expect(completo).toMatchObject({ nome: 'Bebidas', descricao: null, conta_custo: null, codigo_conta: null });
    expect(Object.keys(completo)).toHaveLength(11);
    const venda = CAMPOS_CATEGORIA.find((c) => c.nome === 'codigo_conta')!;
    expect(venda.validar?.('711', {})).toMatch(/começar por 6/);
    expect(venda.validar?.('6111', {})).toBeFalsy();
  });
});

describe('ficha técnica impressa', () => {
  it('dimensões e conteúdo com escape de HTML', () => {
    expect(textoDimensoes(ficha.ficha_tecnica)).toMatch(/30.*× — ×.*50.* cm/);
    expect(textoDimensoes({ ...ficha.ficha_tecnica!, comprimento: null, altura: null })).toBe('');
    const pedido = pedidoFichaTecnica(ficha, '5.000');
    expect(pedido.titulo).toBe('Ficha técnica — B1 Bomba <CR>');
    const html = String(pedido.conteudo);
    expect(html).toContain('Grundfos');
    expect(html).toContain('Potência');
    expect(html).toContain('Uso &lt;industrial&gt;');
    expect(html).toContain('Stock actual');
    expect(html).not.toContain('<industrial>');
  });
});
