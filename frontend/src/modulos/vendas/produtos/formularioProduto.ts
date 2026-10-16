import type { CampoFormulario } from '@/componentes/formularios';

/** Conversão entre a ficha do produto (ProdutoResource) e o formulário / corpo do pedido (GuardarProdutoRequest). */

export interface ProdutoFicha {
  id: number;
  codigo: string | null;
  nome: string;
  preco_unitario: string | null;
  taxa_imposto: string | null;
  categoria_produto_id: number | null;
  movimenta_stock: boolean;
  e_servico: boolean;
  e_quarto: boolean;
  e_ativo_imobilizado: boolean;
  bloqueado: boolean;
  unidade_fe: string | null;
  tipo_operacao_fe: string | null;
  codigo_isencao_fe: string | null;
  contas: Record<'venda' | 'custo' | 'compra' | 'inventario' | 'iva_liquidado' | 'iva_dedutivel' | 'quebra' | 'sobra' | 'ativo', string | null>;
  hotelaria?: { preco_por_hora: string | null; preco_por_dia: string | null; horas_minimas: string | null };
  lavandaria?: { grupo: string | null; unidade: string | null; dias_entrega: number | null; requer_orcamento: boolean; preco_peca: string | null; preco_kg: string | null };
  imagem_base64?: string | null;
  quantidade_stock_legado?: string | null;
}

export interface ValoresProduto {
  codigo?: string;
  nome?: string;
  preco_unitario?: number | null;
  taxa_imposto?: number | null;
  categoria_produto_id?: number | null;
  movimenta_stock?: boolean;
  e_servico?: boolean;
  e_quarto?: boolean;
  e_ativo_imobilizado?: boolean;
  codigo_conta?: string | null;
  conta_custo?: string | null;
  conta_compra?: string | null;
  conta_inventario?: string | null;
  conta_iva_liquidado?: string | null;
  conta_iva_dedutivel?: string | null;
  conta_quebra?: string | null;
  conta_sobra?: string | null;
  conta_ativo?: string | null;
  unidade_fe?: string | null;
  tipo_operacao_fe?: string | null;
  codigo_isencao_fe?: string | null;
  preco_por_hora?: number | null;
  preco_por_dia?: number | null;
  horas_minimas?: number | null;
  lavandaria_ativa?: boolean;
  lavandaria_grupo?: string | null;
  lavandaria_unidade?: string | null;
  lavandaria_dias_entrega?: number | null;
  lavandaria_requer_orcamento?: boolean;
  lavandaria_preco_peca?: number | null;
  lavandaria_preco_kg?: number | null;
}

const num = (v: string | null | undefined): number | null => (v === null || v === undefined || v === '' ? null : Number(v));

/** Ficha → valores do formulário. Em «copiar», limpa o código e acrescenta «(cópia)» ao nome. */
export function produtoParaFormulario(p: ProdutoFicha, copiar = false): ValoresProduto {
  return {
    codigo: copiar ? '' : p.codigo ?? '',
    nome: copiar ? `${p.nome} (cópia)` : p.nome,
    preco_unitario: num(p.preco_unitario),
    taxa_imposto: num(p.taxa_imposto),
    categoria_produto_id: p.categoria_produto_id,
    movimenta_stock: p.movimenta_stock,
    e_servico: p.e_servico,
    e_quarto: p.e_quarto,
    e_ativo_imobilizado: p.e_ativo_imobilizado,
    codigo_conta: p.contas.venda,
    conta_custo: p.contas.custo,
    conta_compra: p.contas.compra,
    conta_inventario: p.contas.inventario,
    conta_iva_liquidado: p.contas.iva_liquidado,
    conta_iva_dedutivel: p.contas.iva_dedutivel,
    conta_quebra: p.contas.quebra,
    conta_sobra: p.contas.sobra,
    conta_ativo: p.contas.ativo,
    unidade_fe: p.unidade_fe,
    tipo_operacao_fe: p.tipo_operacao_fe,
    codigo_isencao_fe: p.codigo_isencao_fe,
    preco_por_hora: num(p.hotelaria?.preco_por_hora),
    preco_por_dia: num(p.hotelaria?.preco_por_dia),
    horas_minimas: num(p.hotelaria?.horas_minimas),
    lavandaria_ativa: !!p.lavandaria,
    lavandaria_grupo: p.lavandaria?.grupo ?? null,
    lavandaria_unidade: p.lavandaria?.unidade ?? null,
    lavandaria_dias_entrega: p.lavandaria?.dias_entrega ?? null,
    lavandaria_requer_orcamento: p.lavandaria?.requer_orcamento ?? false,
    lavandaria_preco_peca: num(p.lavandaria?.preco_peca),
    lavandaria_preco_kg: num(p.lavandaria?.preco_kg),
  };
}

/** Valores do formulário → corpo do pedido: textos vazios como null; campos de quartos/lavandaria só quando activos. */
export function corpoProduto(v: ValoresProduto): Record<string, unknown> {
  const texto = (x: string | null | undefined) => (x && x.trim() ? x.trim() : null);
  const corpo: Record<string, unknown> = {
    codigo: texto(v.codigo),
    nome: texto(v.nome),
    preco_unitario: v.preco_unitario ?? null,
    taxa_imposto: v.taxa_imposto ?? null,
    categoria_produto_id: v.categoria_produto_id ?? null,
    movimenta_stock: !!v.movimenta_stock,
    e_servico: !!v.e_servico,
    e_quarto: !!v.e_quarto,
    e_ativo_imobilizado: !!v.e_ativo_imobilizado,
    lavandaria_ativa: !!v.lavandaria_ativa,
    unidade_fe: texto(v.unidade_fe),
    tipo_operacao_fe: texto(v.tipo_operacao_fe),
    codigo_isencao_fe: texto(v.codigo_isencao_fe)?.toUpperCase() ?? null,
  };
  for (const k of ['codigo_conta', 'conta_custo', 'conta_compra', 'conta_inventario', 'conta_iva_liquidado', 'conta_iva_dedutivel', 'conta_quebra', 'conta_sobra', 'conta_ativo'] as const) {
    corpo[k] = texto(v[k]);
  }
  if (v.e_quarto) Object.assign(corpo, { preco_por_hora: v.preco_por_hora ?? null, preco_por_dia: v.preco_por_dia ?? null, horas_minimas: v.horas_minimas ?? null });
  if (v.lavandaria_ativa)
    Object.assign(corpo, {
      lavandaria_grupo: texto(v.lavandaria_grupo),
      lavandaria_unidade: texto(v.lavandaria_unidade),
      lavandaria_dias_entrega: v.lavandaria_dias_entrega ?? null,
      lavandaria_requer_orcamento: !!v.lavandaria_requer_orcamento,
      lavandaria_preco_peca: v.lavandaria_preco_peca ?? null,
      lavandaria_preco_kg: v.lavandaria_preco_kg ?? null,
    });
  return corpo;
}

/** Categoria: o mesmo corpo na grade, na edição e na vista Linhas. */
export function corpoCategoria(v: Record<string, unknown>): { nome: string } {
  return { nome: String(v.nome ?? '').trim() };
}

/** Vista Linhas das categorias (legado: formularios_linhas_config.js › categorias). */
export const CAMPOS_CATEGORIA: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Nome da categoria', obrigatorio: true, maxLength: 255, largura: 320, aliases: ['Nome', 'Categoria'] },
];

const SIM_NAO = [{ value: 'S', label: 'Sim' }, { value: 'N', label: 'Não' }];

const CONTAS_LINHAS: [keyof ValoresProduto, string, string[]][] = [
  ['codigo_conta', 'Conta de venda/receita', ['Conta_Venda_Receita', 'Conta de Receita']],
  ['conta_custo', 'Conta de custo', ['Conta_Custo']],
  ['conta_iva_liquidado', 'IVA liquidado', ['Conta_IVA']],
  ['conta_iva_dedutivel', 'IVA dedutível', []],
  ['conta_compra', 'Conta de compras', ['Conta_Compras']],
  ['conta_inventario', 'Conta de existências', ['Conta_Existencias']],
];

/**
 * Vista Linhas dos produtos e serviços (legado: formularios_linhas_config.js › produtos — código, nome, preço, IVA,
 * categoria, gerir stock e contas). O «stock inicial» do legado não entra: aqui o stock só muda por movimentos de inventário.
 */
export function camposProdutoLinhas(categorias: { id: number; nome: string }[]): CampoFormulario[] {
  return [
    { nome: 'codigo', rotulo: 'Código', obrigatorio: true, maxLength: 50, largura: 110, aliases: ['Codigo'] },
    { nome: 'nome', rotulo: 'Nome do produto / serviço', obrigatorio: true, maxLength: 255, largura: 230, aliases: ['Nome'] },
    { nome: 'preco_unitario', rotulo: 'Preço unitário', tipo: 'numero', min: 0, precisao: 2, largura: 110, aliases: ['Preco_Unitario', 'Preço'] },
    { nome: 'taxa_imposto', rotulo: 'IVA %', tipo: 'numero', min: 0, max: 100, omissao: 14, largura: 80, aliases: ['Taxa_IVA', 'IVA', 'Taxa IVA'] },
    { nome: 'categoria_produto_id', rotulo: 'Categoria', tipo: 'select', largura: 150, opcoes: categorias.map((c) => ({ value: c.id, label: c.nome })) },
    { nome: 'movimenta_stock_sn', rotulo: 'Gerir stock', tipo: 'select', omissao: 'S', largura: 90, aliases: ['Gerir_Stock'], opcoes: SIM_NAO },
    { nome: 'e_servico_sn', rotulo: 'Serviço', tipo: 'select', omissao: 'N', largura: 90, emLinhas: 'opcional', seccao: 'Tipo', opcoes: SIM_NAO },
    ...CONTAS_LINHAS.map(([nome, rotulo, aliases]): CampoFormulario => ({ nome, rotulo, aliases, maxLength: 20, largura: 150, emLinhas: 'opcional', seccao: 'Contas', placeholder: 'Código da conta' })),
    { nome: 'codigo_isencao_fe', rotulo: 'Motivo de isenção (AGT)', maxLength: 3, largura: 110, emLinhas: 'opcional', seccao: 'Dados fiscais (AGT)',
      validar: (v) => (String(v ?? '').trim() && !/^[Mm]\d{2}$/.test(String(v).trim()) ? 'Motivo de isenção: código AGT (M00 a M99).' : null) },
  ];
}

/** Linha da vista Linhas → corpo do pedido (pela mesma `corpoProduto` da grade). */
export function corpoProdutoLinha(r: Record<string, unknown>): Record<string, unknown> {
  const v: ValoresProduto = {
    ...(r as ValoresProduto),
    movimenta_stock: r.movimenta_stock_sn !== 'N',
    e_servico: r.e_servico_sn === 'S',
    e_quarto: false,
    e_ativo_imobilizado: false,
    lavandaria_ativa: false,
  };
  return corpoProduto(v);
}
