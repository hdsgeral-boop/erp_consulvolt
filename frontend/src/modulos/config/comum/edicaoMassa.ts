/**
 * Sistema › Migração de dados › Edição em massa: corpo de POST /sistema/migracao/edicao-massa/{entidade}.
 * Ronda 3: nos produtos, as contas contabilísticas vêm da CATEGORIA (sobrepõe-se ao produto) — já não se gravam no
 * produto. Muda-se a categoria dos produtos seleccionados (dados.categoria_produto_id) ou editam-se as contas de uma
 * categoria (categoria_contas, com a permissão vendas_categorias_contas; vale para todos os produtos da categoria).
 */

export type EntidadeMassa = 'clientes' | 'fornecedores' | 'produtos';

export const RETIRAR_CONTA = '__RETIRAR__';

/** Contas que se gravam no próprio registo (só terceiros: nos produtos vêm da categoria). */
export const CONTAS_MASSA: Record<EntidadeMassa, [string, string][]> = {
  clientes: [['codigo_conta', 'Conta do cliente']],
  fornecedores: [['codigo_conta', 'Conta do fornecedor'], ['conta_compra_transitoria', 'Conta de compras transitória']],
  produtos: [],
};

/** Prefixo dos campos de conta da categoria no formulário (para não colidir com as contas do registo). */
export const PREFIXO_CONTA_CATEGORIA = 'catconta_';

export interface CorpoEdicaoMassa {
  ids: number[];
  dados: Record<string, unknown>;
  contas: Record<string, string>;
  preco?: { modo: string; valor: number };
  categoria_contas?: { categoria_produto_id: number; contas: Record<string, string> };
}

const preenchido = (x: unknown) => x !== undefined && x !== null && x !== '';

/** Valores do formulário → corpo do pedido (só o que foi escolhido). */
export function corpoEdicaoMassa(entidade: EntidadeMassa, ids: number[], v: Record<string, unknown>, camposContaCategoria: string[] = []): CorpoEdicaoMassa {
  const dados: Record<string, unknown> = {};
  const camposDados = entidade === 'produtos' ? ['taxa_imposto', 'categoria_produto_id', 'movimenta_stock', 'e_servico', 'bloqueado'] : ['codigo_moeda'];
  for (const k of camposDados) if (preenchido(v[k])) dados[k] = v[k];
  const contas: Record<string, string> = {};
  for (const [k] of CONTAS_MASSA[entidade]) if (preenchido(v[`conta_${k}`])) contas[k] = String(v[`conta_${k}`]);
  const corpo: CorpoEdicaoMassa = { ids, dados, contas };
  if (entidade === 'produtos' && v.preco_modo && preenchido(v.preco_valor)) corpo.preco = { modo: String(v.preco_modo), valor: Number(v.preco_valor) };
  if (entidade === 'produtos' && preenchido(v.categoria_contas_id)) {
    const daCategoria: Record<string, string> = {};
    for (const campo of camposContaCategoria) {
      const x = v[`${PREFIXO_CONTA_CATEGORIA}${campo}`];
      if (preenchido(x)) daCategoria[campo] = String(x);
    }
    if (Object.keys(daCategoria).length) corpo.categoria_contas = { categoria_produto_id: Number(v.categoria_contas_id), contas: daCategoria };
  }
  return corpo;
}

/** Há alguma alteração a aplicar aos registos seleccionados (dados, contas do registo ou preço)? */
export function alteraRegistos(c: CorpoEdicaoMassa): boolean {
  return Object.keys(c.dados).length > 0 || Object.keys(c.contas).length > 0 || !!c.preco;
}
