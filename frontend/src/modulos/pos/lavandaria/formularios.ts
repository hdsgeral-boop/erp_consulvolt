import type { CampoFormulario } from '@/componentes/formularios';
import { GRUPOS_LAV } from './dados';

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

/** Corpo de POST/PUT /pos/lavandaria/pecas (grade e linhas). */
export function dadosPeca(v: Record<string, unknown>): Record<string, unknown> {
  return {
    nome: String(v.nome ?? '').trim(),
    tecido: texto(v.tecido),
    cor: texto(v.cor),
    unidade: v.unidade === 'KG' ? 'KG' : 'PECA',
    preco: numero(v.preco) ?? 0,
    ativo: simNao(v.ativo, true),
    precos_servico: Array.isArray(v.precos_servico) ? v.precos_servico : [],
  };
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
