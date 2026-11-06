import type { CampoFormulario } from '@/componentes/formularios';
import { GRUPOS_LAV } from './dados';
import { chaveOpcao, opcoesPeca, type ModoPreco, type Peca, type PrecoServicoPeca } from './tipos';

/**
 * Formulários «Nova peça» e «Novo serviço» da lavandaria com as vistas Grade | Linhas (legado: lavEditarPeca /
 * lavEditarServico, lavandaria.js:1313-1440). A grade e as linhas usam as mesmas funções para montar o pedido.
 */

const texto = (v: unknown): string | null => {
  const t = String(v ?? '').trim();
  return t ? t : null;
};
const numero = (v: unknown): number | undefined => (v === null || v === undefined || v === '' || Number.isNaN(Number(v)) ? undefined : Number(v));
/** Sim/Não das linhas (`'S'`/`'N'`) ou o booleano da grade (interruptor). */
const simNao = (v: unknown, omissao: boolean): boolean => (v === undefined || v === null || v === '' ? omissao : v === true || v === 'S');

const OPCOES_SIM_NAO = [
  { value: 'S', label: 'Sim' },
  { value: 'N', label: 'Não' },
];

/** Opção de preço no formulário da peça (preço em número, tamanho só no modo Tamanho). */
export interface OpcaoForm {
  modo?: ModoPreco | null;
  tamanho?: string | null;
  preco?: number | string | null;
}

/**
 * Corpo de POST/PUT /pos/lavandaria/pecas (grade e linhas). Com `opcoes_preco` (editor com «+ Opção») segue a lista de opções e a
 * unidade/preço espelham a 1.ª no servidor; sem ela (vista Linhas) seguem unidade e preço da 1.ª opção.
 */
export function dadosPeca(v: Record<string, unknown>): Record<string, unknown> {
  const base: Record<string, unknown> = {
    nome: String(v.nome ?? '').trim(),
    tecido: texto(v.tecido),
    cor: texto(v.cor),
    ativo: simNao(v.ativo, true),
    precos_servico: Array.isArray(v.precos_servico) ? v.precos_servico : [],
  };
  if (Array.isArray(v.opcoes_preco) && v.opcoes_preco.length) {
    return {
      ...base,
      opcoes_preco: (v.opcoes_preco as OpcaoForm[]).map((o) => {
        const modo: ModoPreco = o.modo === 'KG' ? 'KG' : o.modo === 'TAMANHO' ? 'TAMANHO' : 'PECA';
        return { modo, tamanho: modo === 'TAMANHO' ? String(o.tamanho ?? '').trim() : '', preco: numero(o.preco) ?? 0 };
      }),
    };
  }
  return { ...base, unidade: v.unidade === 'KG' ? 'KG' : 'PECA', preco: numero(v.preco) ?? 0 };
}

/** Validações do editor de peças (lavandaria.js:1834-1846): mensagens do legado; null = válido. */
export function validarOpcoesPeca(opcoes: OpcaoForm[] | undefined): string | null {
  if (!opcoes?.length) return 'A peça tem de ter pelo menos uma opção de preço.';
  if (opcoes.some((o) => o.modo === 'TAMANHO' && !String(o.tamanho ?? '').trim())) return 'Dê nome ao tamanho (ex.: M, Casal) ou remova essa opção de preço.';
  const chaves = opcoes.map((o) => chaveOpcao({ modo: o.modo ?? 'PECA', tamanho: o.modo === 'TAMANHO' ? o.tamanho : '' }));
  if (chaves.some((k, i) => chaves.indexOf(k) !== i)) return 'Há duas opções de preço iguais. Cada opção só pode aparecer uma vez.';
  if (opcoes.some((o) => !((numero(o.preco) ?? 0) >= 0))) return 'Há preços base inválidos.';
  return null;
}

/** Matriz do editor: serviço → chave da opção → preço (campo vazio ou 0 = usa o preço da opção). */
export type MatrizPrecos = Record<string, Record<string, number | null | undefined>>;

/** Matriz inicial a partir dos preços por serviço da peça; as entradas sem modo (antigas) valem para a opção única e, havendo várias, ficam à parte. */
export function matrizDaPeca(p: Pick<Peca, 'preco' | 'unidade' | 'opcoes_preco' | 'precos_servico'> | null): { matriz: MatrizPrecos; semModo: PrecoServicoPeca[] } {
  const matriz: MatrizPrecos = {};
  const semModo: PrecoServicoPeca[] = [];
  if (!p) return { matriz, semModo };
  const ops = opcoesPeca(p);
  for (const x of p.precos_servico ?? []) {
    const preco = x.preco === null || x.preco === undefined ? null : Number(x.preco);
    if (x.modo) (matriz[String(x.produto_id)] ??= {})[chaveOpcao(x)] = preco;
    else if (ops.length === 1) (matriz[String(x.produto_id)] ??= {})[chaveOpcao(ops[0])] = preco;
    else semModo.push({ produto_id: x.produto_id, preco });
  }
  return { matriz, semModo };
}

/** `precos_servico` do pedido: uma entrada por serviço e opção com preço > 0 (opções que já não existem descartam-se), mais as antigas sem modo. */
export function precosServicoDaMatriz(matriz: MatrizPrecos | undefined, opcoes: OpcaoForm[], semModo: PrecoServicoPeca[] = []): PrecoServicoPeca[] {
  const saida: PrecoServicoPeca[] = [];
  for (const [sid, porOpcao] of Object.entries(matriz ?? {})) {
    for (const o of opcoes) {
      const modo: ModoPreco = o.modo === 'KG' ? 'KG' : o.modo === 'TAMANHO' ? 'TAMANHO' : 'PECA';
      const tamanho = modo === 'TAMANHO' ? String(o.tamanho ?? '').trim() : '';
      const v = Number(porOpcao?.[chaveOpcao({ modo, tamanho })] ?? 0);
      if (v > 0) saida.push({ produto_id: Number(sid), modo, tamanho, preco: v });
    }
  }
  return [...saida, ...semModo.filter((x) => Number(x.preco ?? 0) > 0)];
}

/** Colunas da vista Linhas de «Nova peça», pela ordem do legado (designação, tecido, cor, venda por, preço base). */
export const CAMPOS_PECA: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Designação da peça', obrigatorio: true, largura: 220, maxLength: 255, aliases: ['Nome', 'Peça', 'Peca', 'Designação'], placeholder: 'Ex.: Camisa' },
  { nome: 'tecido', rotulo: 'Tipo de tecido', largura: 160, maxLength: 255, aliases: ['Tecido'] },
  { nome: 'cor', rotulo: 'Cor', largura: 130, maxLength: 255 },
  {
    nome: 'unidade',
    rotulo: 'Venda por',
    tipo: 'select',
    largura: 150,
    omissao: 'PECA',
    aliases: ['Unidade'],
    opcoes: [
      { value: 'PECA', label: 'Peça' },
      { value: 'KG', label: 'Quilograma (kg)' },
    ],
  },
  { nome: 'preco', rotulo: 'Preço base (IVA incl.)', tipo: 'numero', largura: 150, min: 0, precisao: 2, omissao: 0, aliases: ['Preço', 'Preco', 'Preço base'] },
];

/** Corpo de POST/PUT /pos/lavandaria/servicos (grade e linhas); os campos vazios não seguem (a API usa as omissões). */
export function dadosServico(v: Record<string, unknown>): Record<string, unknown> {
  const dias = numero(v.dias_entrega);
  return {
    nome: String(v.nome ?? '').trim(),
    grupo: v.grupo ?? 'LAVANDARIA',
    codigo_conta: String(v.codigo_conta ?? '').trim(),
    // vazio não segue: a edição não carrega estes dois campos e não os pode apagar
    conta_iva_liquidado: texto(v.conta_iva_liquidado) ?? undefined,
    taxa_imposto: numero(v.taxa_imposto) ?? 14,
    dias_entrega: dias === undefined ? 2 : Math.max(0, Math.round(dias)),
    codigo_isencao_fe: texto(v.codigo_isencao_fe) ?? undefined,
    // na grade é o interruptor «requer_orcamento»; nas linhas a coluna «Orçamento» (Sim/Não)
    requer_orcamento: typeof v.requer_orcamento === 'boolean' ? v.requer_orcamento : simNao(v.orcamento, false),
    ativa: simNao(v.ativa, true),
  };
}

/** Colunas da vista Linhas de «Novo serviço» (legado: designação, grupo, conta 62, IVA, prazo, orçamento). */
export const CAMPOS_SERVICO: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Designação do serviço', obrigatorio: true, largura: 220, maxLength: 255, aliases: ['Nome', 'Serviço', 'Servico'], placeholder: 'Ex.: Lavar e engomar' },
  { nome: 'grupo', rotulo: 'Grupo', tipo: 'select', obrigatorio: true, largura: 140, omissao: 'LAVANDARIA', opcoes: Object.entries(GRUPOS_LAV).map(([value, label]) => ({ value, label })) },
  {
    nome: 'codigo_conta',
    rotulo: 'Conta de proveitos (62)',
    obrigatorio: true,
    largura: 150,
    maxLength: 20,
    aliases: ['Conta', 'Conta de proveito', 'Conta de proveitos'],
    validar: (v) => (v && !String(v).trim().startsWith('62') ? 'A conta de proveitos tem de ser da classe 62.' : null),
  },
  { nome: 'taxa_imposto', rotulo: 'IVA (%)', tipo: 'numero', largura: 90, min: 0, precisao: 2, omissao: 14, aliases: ['IVA', 'Taxa de IVA'] },
  { nome: 'dias_entrega', rotulo: 'Prazo (dias)', tipo: 'numero', largura: 100, min: 0, precisao: 0, omissao: 2, aliases: ['Prazo'] },
  { nome: 'orcamento', rotulo: 'Orçamento', tipo: 'select', largura: 110, omissao: 'N', opcoes: OPCOES_SIM_NAO, aliases: ['Requer orçamento', 'Exige orçamento'] },
  { nome: 'conta_iva_liquidado', rotulo: 'Conta de IVA liquidado', largura: 150, maxLength: 20, emLinhas: 'opcional', seccao: 'Contabilidade' },
  { nome: 'codigo_isencao_fe', rotulo: 'Código de isenção', largura: 120, maxLength: 10, emLinhas: 'opcional', seccao: 'Contabilidade' },
];
