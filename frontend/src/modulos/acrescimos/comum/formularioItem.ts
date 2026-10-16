import dayjs from 'dayjs';
import type { CampoFormulario, OpcaoCampo } from '@/componentes/formularios';
import { contaBalancoPadrao } from './regras';
import type { Definicoes, NaturezaAD, Origem, TipoAD } from './tipos';

/**
 * Formulário do registo de acréscimo/diferimento: a transformação «valores → corpo do pedido» partilhada pela grade e
 * pela vista Linhas (docs/paridade/versao_actual/07_modais_grade_linhas.md, «Como aplicar»).
 */

const DATAS = ['data_inicio', 'data_fim', 'data_documento', 'data_limite'] as const;
const OPCIONAIS = ['terceiro_id', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'notas'] as const;

/** Data da grade (`dayjs`) ou da linha (`'AAAA-MM-DD'`) → `'AAAA-MM-DD'` ou null. */
export function dataItem(v: unknown): string | null {
  if (!v) return null;
  if (dayjs.isDayjs(v)) return v.isValid() ? v.format('YYYY-MM-DD') : null;
  if (typeof v === 'string') return /^\d{4}-\d{2}-\d{2}/.test(v) ? v.slice(0, 10) : null;
  return null;
}

/**
 * Valores do formulário → corpo de POST/PUT /acrescimos/itens. As datas vêm em `dayjs` (grade) ou texto (linhas); os
 * opcionais vazios seguem como null; sem conta de balanço usa a das Definições para o tipo/natureza (a mesma que a
 * grade preenche ao mudar o tipo); `origem` só existe quando o registo nasce de um documento («Diferir»).
 */
export function dadosItem(v: Record<string, unknown>, contexto: { origem?: Origem | null; definicoes?: Pick<Definicoes, 'contas'> } = {}): Record<string, unknown> {
  const dados: Record<string, unknown> = { ...v };
  for (const k of DATAS) dados[k] = dataItem(v[k]);
  for (const k of OPCIONAIS) if (dados[k] === undefined || dados[k] === '') dados[k] = null;
  const conta37 = (typeof v.conta_balanco === 'string' && v.conta_balanco.trim()) || (typeof v.conta_37 === 'string' && v.conta_37.trim()) || '';
  dados.conta_balanco = conta37 || contaBalancoPadrao(contexto.definicoes, v.tipo as TipoAD, v.natureza as NaturezaAD) || null;
  if ('documento_em_balanco' in v) dados.documento_em_balanco = v.documento_em_balanco === true || v.documento_em_balanco === 1;
  delete dados.modelo;
  delete dados.conta_37;
  dados.origem = contexto.origem ?? null;
  return dados;
}

export const OPCOES_TIPO_AD: OpcaoCampo[] = [
  { value: 'ACRESCIMO', label: 'Acréscimo' },
  { value: 'DIFERIMENTO', label: 'Diferimento' },
];
export const OPCOES_NATUREZA_AD: OpcaoCampo[] = [
  { value: 'CUSTO', label: 'Gasto' },
  { value: 'PROVEITO', label: 'Rendimento' },
];
export const OPCOES_REPARTICAO: OpcaoCampo[] = [
  { value: 'MESES', label: 'Por meses (cada mês vale 1)' },
  { value: 'DIAS', label: 'Por dias' },
];

/**
 * Colunas da vista Linhas (um registo por linha), com as regras da grade: obrigatórios, fim ≥ início e data do
 * documento obrigatória num diferimento. A conta de balanço da linha («conta_37») é opcional: vazia → a das Definições
 * para o tipo/natureza da própria linha (não herda a da grade, que pode ser de outro tipo).
 */
export function camposItem(opcoes: { definicoes?: Pick<Definicoes, 'contas'>; unidades?: OpcaoCampo[]; centros?: OpcaoCampo[] } = {}): CampoFormulario[] {
  return [
    { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', obrigatorio: true, largura: 130, omissao: 'DIFERIMENTO', opcoes: OPCOES_TIPO_AD },
    { nome: 'natureza', rotulo: 'Natureza', tipo: 'select', obrigatorio: true, largura: 120, omissao: 'CUSTO', opcoes: OPCOES_NATUREZA_AD },
    { nome: 'descricao', rotulo: 'Descrição', obrigatorio: true, largura: 240, maxLength: 1000 },
    { nome: 'valor', rotulo: 'Valor (Kz, sem IVA)', tipo: 'numero', obrigatorio: true, largura: 130, min: 0, precisao: 2, aliases: ['Valor', 'Valor total'] },
    { nome: 'conta_resultado', rotulo: 'Conta de resultados', obrigatorio: true, largura: 130, maxLength: 20, aliases: ['Conta de gasto', 'Conta de rendimento', 'Conta resultado'] },
    {
      nome: 'conta_37', rotulo: 'Conta de balanço (37)', largura: 140, maxLength: 20, placeholder: 'das Definições', aliases: ['Conta de balanço', 'Conta 37', 'conta_balanco'],
      validar: (v, r) => (!v && !contaBalancoPadrao(opcoes.definicoes, r.tipo as TipoAD, r.natureza as NaturezaAD) ? 'Indique a conta 37 (não há conta por omissão nas Definições).' : null),
    },
    { nome: 'data_inicio', rotulo: 'Início', tipo: 'data', obrigatorio: true, largura: 130, aliases: ['Data de início', 'Período de'] },
    {
      nome: 'data_fim', rotulo: 'Fim', tipo: 'data', obrigatorio: true, largura: 130, aliases: ['Data de fim', 'até'],
      validar: (v, r) => (typeof v === 'string' && typeof r.data_inicio === 'string' && v && r.data_inicio && v < r.data_inicio ? 'Fim anterior ao início.' : null),
    },
    {
      nome: 'data_documento', rotulo: 'Data do documento', tipo: 'data', largura: 130,
      validar: (v, r) => (r.tipo === 'DIFERIMENTO' && !v ? 'Data do documento: obrigatória num diferimento.' : null),
    },
    { nome: 'reparticao', rotulo: 'Repartição', tipo: 'select', largura: 150, omissao: 'MESES', opcoes: OPCOES_REPARTICAO, emLinhas: 'opcional', seccao: 'Período' },
    { nome: 'data_limite', rotulo: 'Data limite do documento', tipo: 'data', largura: 130, emLinhas: 'opcional', seccao: 'Período' },
    { nome: 'unidade_negocio_id', rotulo: 'Unidade de negócio', tipo: 'select', largura: 150, opcoes: opcoes.unidades ?? [], emLinhas: 'opcional', seccao: 'Imputação' },
    { nome: 'centro_custo_id', rotulo: 'Centro de custo', tipo: 'select', largura: 150, opcoes: opcoes.centros ?? [], emLinhas: 'opcional', seccao: 'Imputação' },
    { nome: 'notas', rotulo: 'Notas', largura: 200, maxLength: 4000, emLinhas: 'opcional' },
  ];
}
