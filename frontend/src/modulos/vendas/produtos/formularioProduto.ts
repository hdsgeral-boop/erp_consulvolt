import type { CampoFormulario } from '@/componentes/formularios';

/**
 * Conversão entre a ficha do produto (ProdutoResource) e o formulário / corpo do pedido (GuardarProdutoRequest).
 * Ronda 3: as contas contabilísticas definem-se na CATEGORIA (o produto herda-as; a API recusa contas no produto), a
 * categoria é obrigatória, e o produto tem níveis de stock e ficha técnica.
 */

export type ChaveConta = 'venda' | 'custo' | 'compra' | 'inventario' | 'iva_liquidado' | 'iva_dedutivel' | 'quebra' | 'sobra' | 'ativo';

export interface NiveisStock {
  stock_minimo: string | null;
  stock_seguranca: string | null;
  ponto_encomenda: string | null;
  stock_maximo: string | null;
}

export interface Caracteristica {
  chave: string;
  valor: string;
}

export interface FichaTecnica {
  marca: string | null;
  modelo: string | null;
  comprimento: string | null;
  largura: string | null;
  altura: string | null;
  unidade_dimensao: string | null;
  peso_liquido: string | null;
  peso_bruto: string | null;
  caracteristicas: Caracteristica[];
  observacoes_tecnicas: string | null;
}

export interface ProdutoFicha {
  id: number;
  codigo: string | null;
  nome: string;
  preco_unitario: string | null;
  taxa_imposto: string | null;
  categoria_produto_id: number | null;
  categoria?: string | null;
  movimenta_stock: boolean;
  e_servico: boolean;
  e_quarto: boolean;
  e_ativo_imobilizado: boolean;
  bloqueado: boolean;
  unidade_fe: string | null;
  tipo_operacao_fe: string | null;
  codigo_isencao_fe: string | null;
  /** Contas EFECTIVAS (da categoria; na falta, as de recurso do produto antigo). */
  contas: Record<ChaveConta, string | null>;
  contas_origem?: Partial<Record<ChaveConta, 'categoria' | 'produto' | null>>;
  niveis_stock?: NiveisStock;
  ficha_tecnica?: FichaTecnica;
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
  // níveis de stock
  stock_minimo?: number | null;
  stock_seguranca?: number | null;
  ponto_encomenda?: number | null;
  stock_maximo?: number | null;
  // ficha técnica
  marca?: string | null;
  modelo?: string | null;
  comprimento?: number | null;
  largura?: number | null;
  altura?: number | null;
  unidade_dimensao?: string | null;
  peso_liquido?: number | null;
  peso_bruto?: number | null;
  caracteristicas?: Partial<Caracteristica>[];
  observacoes_tecnicas?: string | null;
}

const num = (v: string | null | undefined): number | null => (v === null || v === undefined || v === '' ? null : Number(v));

/** Ficha → valores do formulário. Em «copiar», limpa o código e acrescenta «(cópia)» ao nome. */
export function produtoParaFormulario(p: ProdutoFicha, copiar = false): ValoresProduto {
  const f = p.ficha_tecnica;
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
    stock_minimo: num(p.niveis_stock?.stock_minimo),
    stock_seguranca: num(p.niveis_stock?.stock_seguranca),
    ponto_encomenda: num(p.niveis_stock?.ponto_encomenda),
    stock_maximo: num(p.niveis_stock?.stock_maximo),
    marca: f?.marca ?? null,
    modelo: f?.modelo ?? null,
    comprimento: num(f?.comprimento),
    largura: num(f?.largura),
    altura: num(f?.altura),
    unidade_dimensao: f?.unidade_dimensao ?? null,
    peso_liquido: num(f?.peso_liquido),
    peso_bruto: num(f?.peso_bruto),
    caracteristicas: f?.caracteristicas?.length ? f.caracteristicas.map((c) => ({ ...c })) : [],
    observacoes_tecnicas: f?.observacoes_tecnicas ?? null,
  };
}

/** Rótulos dos níveis, pela ordem exigida (mínimo ≤ segurança ≤ ponto de encomenda ≤ máximo). */
export const NIVEIS: [keyof NiveisStock, string][] = [
  ['stock_minimo', 'Stock mínimo'],
  ['stock_seguranca', 'Stock de segurança'],
  ['ponto_encomenda', 'Ponto de encomenda'],
  ['stock_maximo', 'Stock máximo'],
];

/**
 * Valida a ordem dos níveis preenchidos (a mesma regra do servidor, ServicoNiveisStock::validarNiveis): devolve a
 * mensagem do primeiro erro ou null.
 */
export function erroNiveis(v: Partial<Record<keyof NiveisStock, number | null | undefined>>): string | null {
  let anterior: [string, number] | null = null;
  for (const [campo, rotulo] of NIVEIS) {
    const x = v[campo];
    if (x === null || x === undefined || Number.isNaN(x)) continue;
    if (x < 0) return `${rotulo}: não pode ser negativo.`;
    if (anterior && x < anterior[1]) return `${rotulo} não pode ser inferior ao ${anterior[0].toLowerCase()} (mínimo ≤ segurança ≤ ponto de encomenda ≤ máximo).`;
    anterior = [rotulo, x];
  }
  return null;
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
  // níveis de stock: só para artigos que movimentam stock
  for (const [k] of NIVEIS) corpo[k] = v.movimenta_stock ? v[k] ?? null : null;
  // ficha técnica (só quando o formulário a traz: a vista Linhas não a tem)
  if ('marca' in v || 'caracteristicas' in v || 'observacoes_tecnicas' in v) {
    Object.assign(corpo, {
      marca: texto(v.marca),
      modelo: texto(v.modelo),
      comprimento: v.comprimento ?? null,
      largura: v.largura ?? null,
      altura: v.altura ?? null,
      unidade_dimensao: v.unidade_dimensao || null,
      peso_liquido: v.peso_liquido ?? null,
      peso_bruto: v.peso_bruto ?? null,
      caracteristicas: (v.caracteristicas ?? [])
        .map((c) => ({ chave: (c?.chave ?? '').trim(), valor: (c?.valor ?? '').trim() }))
        .filter((c) => c.chave || c.valor),
      observacoes_tecnicas: texto(v.observacoes_tecnicas),
    });
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

// ───────────── Categorias (contas contabilísticas) ─────────────

/** Campo de conta da categoria → rótulo e prefixos de classe aceites (o mesmo que CategoriaProduto::CONTAS no servidor). */
export const CONTAS_CATEGORIA: { campo: string; chave: ChaveConta; rotulo: string; prefixos: string[] }[] = [
  { campo: 'codigo_conta', chave: 'venda', rotulo: 'Proveitos (vendas 61 / serviços 62)', prefixos: ['6'] },
  { campo: 'conta_custo', chave: 'custo', rotulo: 'Custo das vendas — CMV (71/75)', prefixos: ['7'] },
  { campo: 'conta_compra', chave: 'compra', rotulo: 'Compras (21)', prefixos: ['21'] },
  { campo: 'conta_inventario', chave: 'inventario', rotulo: 'Existências (22 a 27)', prefixos: ['22', '23', '24', '25', '26', '27'] },
  { campo: 'conta_iva_liquidado', chave: 'iva_liquidado', rotulo: 'IVA liquidado (345)', prefixos: ['34'] },
  { campo: 'conta_iva_dedutivel', chave: 'iva_dedutivel', rotulo: 'IVA dedutível (345)', prefixos: ['34'] },
  { campo: 'conta_quebra', chave: 'quebra', rotulo: 'Quebras de inventário (custo, 7)', prefixos: ['7'] },
  { campo: 'conta_sobra', chave: 'sobra', rotulo: 'Sobras de inventário (proveito, 6)', prefixos: ['6'] },
  { campo: 'conta_ativo', chave: 'ativo', rotulo: 'Activo imobilizado (classe 1)', prefixos: ['1'] },
];

/** Categoria tal como a API a devolve (CategoriaProdutoController). */
export interface Categoria {
  id: number;
  nome: string;
  descricao?: string | null;
  produtos?: number;
  codigo_conta?: string | null;
  conta_custo?: string | null;
  conta_compra?: string | null;
  conta_inventario?: string | null;
  conta_iva_liquidado?: string | null;
  conta_iva_dedutivel?: string | null;
  conta_quebra?: string | null;
  conta_sobra?: string | null;
  conta_ativo?: string | null;
}

/** A conta indicada pertence às classes aceites? (vazio = válido). */
export function contaNaClasse(codigo: string | null | undefined, prefixos: string[]): boolean {
  const c = String(codigo ?? '').replace(/[.\s]/g, '');
  return !c || prefixos.some((p) => c.startsWith(p));
}

/**
 * Categoria: o mesmo corpo na grade, na edição e na vista Linhas. `comContas` envia todas as contas (null limpa); sem
 * ele, só as preenchidas (a vista Linhas não apaga nada).
 */
export function corpoCategoria(v: Record<string, unknown>, comContas = false): Record<string, unknown> {
  const corpo: Record<string, unknown> = { nome: String(v.nome ?? '').trim() };
  const texto = (x: unknown) => (typeof x === 'string' && x.trim() ? x.trim().replace(/[.\s]/g, '') : null);
  const descricao = typeof v.descricao === 'string' && v.descricao.trim() ? v.descricao.trim() : null;
  if (comContas || descricao) corpo.descricao = descricao;
  for (const c of CONTAS_CATEGORIA) {
    const x = texto(v[c.campo]);
    if (comContas || x) corpo[c.campo] = x;
  }
  return corpo;
}

/** Vista Linhas das categorias (legado: formularios_linhas_config.js › categorias; ronda 3: com as contas). */
export const CAMPOS_CATEGORIA: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Nome da categoria', obrigatorio: true, maxLength: 255, largura: 320, aliases: ['Nome', 'Categoria'] },
  { nome: 'descricao', rotulo: 'Descrição', maxLength: 2000, largura: 240, emLinhas: 'opcional', seccao: 'Dados' },
  ...CONTAS_CATEGORIA.map((c): CampoFormulario => ({
    nome: c.campo, rotulo: c.rotulo, maxLength: 20, largura: 150, emLinhas: 'opcional', seccao: 'Contas', placeholder: 'Código da conta',
    validar: (x) => (contaNaClasse(x as string, c.prefixos) ? null : `${c.rotulo}: a conta tem de começar por ${c.prefixos.join(', ')}.`),
  })),
];

const SIM_NAO = [{ value: 'S', label: 'Sim' }, { value: 'N', label: 'Não' }];

/**
 * Vista Linhas dos produtos e serviços (legado: formularios_linhas_config.js › produtos — código, nome, preço, IVA,
 * categoria e gerir stock). O «stock inicial» do legado não entra (só movimentos de inventário) e as contas também não
 * (ronda 3: vêm da categoria, que passa a ser obrigatória).
 */
export function camposProdutoLinhas(categorias: { id: number; nome: string }[]): CampoFormulario[] {
  return [
    { nome: 'codigo', rotulo: 'Código', obrigatorio: true, maxLength: 50, largura: 110, aliases: ['Codigo'] },
    { nome: 'nome', rotulo: 'Nome do produto / serviço', obrigatorio: true, maxLength: 255, largura: 230, aliases: ['Nome'] },
    { nome: 'preco_unitario', rotulo: 'Preço unitário', tipo: 'numero', min: 0, precisao: 2, largura: 110, aliases: ['Preco_Unitario', 'Preço'] },
    { nome: 'taxa_imposto', rotulo: 'IVA %', tipo: 'numero', min: 0, max: 100, omissao: 14, largura: 80, aliases: ['Taxa_IVA', 'IVA', 'Taxa IVA'] },
    { nome: 'categoria_produto_id', rotulo: 'Categoria', tipo: 'select', obrigatorio: true, largura: 170, opcoes: categorias.map((c) => ({ value: c.id, label: c.nome })) },
    { nome: 'movimenta_stock_sn', rotulo: 'Gerir stock', tipo: 'select', omissao: 'S', largura: 90, aliases: ['Gerir_Stock'], opcoes: SIM_NAO },
    { nome: 'e_servico_sn', rotulo: 'Serviço', tipo: 'select', omissao: 'N', largura: 90, emLinhas: 'opcional', seccao: 'Tipo', opcoes: SIM_NAO },
    ...NIVEIS.map(([nome, rotulo]): CampoFormulario => ({ nome, rotulo, tipo: 'numero', min: 0, precisao: 3, largura: 110, emLinhas: 'opcional', seccao: 'Níveis de stock' })),
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
