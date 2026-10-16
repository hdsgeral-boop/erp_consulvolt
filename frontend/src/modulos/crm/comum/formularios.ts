import dayjs from 'dayjs';
import type { CampoFormulario, OpcaoCampo } from '@/componentes/formularios';
import { dataDe, emailInvalido, numeroOuNull, textoOuNull } from '@/modulos/vendas/comum/valoresFormulario';
import { totalItens, type Funil, type ItemOportunidade } from './tipos';

/**
 * Formulários de criação do CRM com as vistas «Grade | Linhas»: colunas da vista Linhas e a função pura que monta o
 * corpo do pedido, a mesma na grade e nas linhas (datas em `dayjs` ou 'AAAA-MM-DD'; caixas de selecção da grade como
 * Sim/Não nas linhas, em campos `*_sn`).
 */

const SIM_NAO: OpcaoCampo[] = [{ value: 'S', label: 'Sim' }, { value: 'N', label: 'Não' }];
const marcado = (v: Record<string, unknown>, campo: string) => v[campo] === true || v[`${campo}_sn`] === 'S';

// ---------------- Contas (prospects) ----------------

export function dadosConta(v: Record<string, unknown>): Record<string, unknown> {
  return {
    nome: String(v.nome ?? '').trim(),
    nif: textoOuNull(v.nif),
    email: textoOuNull(v.email),
    telefone: textoOuNull(v.telefone),
    setor: textoOuNull(v.setor),
    website: textoOuNull(v.website),
    origem: textoOuNull(v.origem),
    responsavel: textoOuNull(v.responsavel),
    morada: textoOuNull(v.morada),
    notas: textoOuNull(v.notas),
  };
}

export function camposConta(origens: string[], responsavel?: string | null): CampoFormulario[] {
  return [
    { nome: 'nome', rotulo: 'Nome', obrigatorio: true, maxLength: 255, largura: 220, aliases: ['Entidade', 'Empresa', 'Prospect'] },
    { nome: 'nif', rotulo: 'NIF', maxLength: 30, largura: 120 },
    { nome: 'email', rotulo: 'Email', maxLength: 150, largura: 180, aliases: ['E-mail'], validar: emailInvalido },
    { nome: 'telefone', rotulo: 'Telefone', maxLength: 50, largura: 120 },
    { nome: 'setor', rotulo: 'Sector', maxLength: 255, largura: 140 },
    { nome: 'origem', rotulo: 'Origem', tipo: 'select', largura: 140, opcoes: origens.map((o) => ({ value: o, label: o })) },
    { nome: 'responsavel', rotulo: 'Responsável', maxLength: 100, largura: 130, omissao: responsavel ?? '' },
    { nome: 'website', rotulo: 'Website', maxLength: 255, largura: 160, emLinhas: 'opcional', seccao: 'Classificação' },
    { nome: 'morada', rotulo: 'Morada', tipo: 'textoLongo', maxLength: 1000, largura: 200, emLinhas: 'opcional', seccao: 'Morada e notas', aliases: ['Endereço'] },
    { nome: 'notas', rotulo: 'Notas', tipo: 'textoLongo', maxLength: 4000, largura: 200, emLinhas: 'opcional', seccao: 'Morada e notas' },
  ];
}

// ---------------- Contactos ----------------

export function dadosContacto(v: Record<string, unknown>): Record<string, unknown> {
  return {
    nome: String(v.nome ?? '').trim(),
    cargo: textoOuNull(v.cargo),
    email: textoOuNull(v.email),
    telefone: textoOuNull(v.telefone),
    principal: marcado(v, 'principal'),
  };
}

export const CAMPOS_CONTACTO: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Nome', obrigatorio: true, maxLength: 255, largura: 220 },
  { nome: 'cargo', rotulo: 'Cargo', maxLength: 100, largura: 140, aliases: ['Função'] },
  { nome: 'email', rotulo: 'Email', maxLength: 150, largura: 180, aliases: ['E-mail'], validar: emailInvalido },
  { nome: 'telefone', rotulo: 'Telefone', maxLength: 50, largura: 120 },
  { nome: 'principal_sn', rotulo: 'Principal', tipo: 'select', omissao: 'N', largura: 90, opcoes: SIM_NAO },
];

// ---------------- Actividades ----------------

type ContextoActividade = { oportunidade_crm_id?: number | null; conta_crm_id?: number | null; contacto_crm_id?: number | null };

export function dadosActividade(v: Record<string, unknown>, base: ContextoActividade = {}): Record<string, unknown> {
  return {
    ...base,
    tipo: v.tipo,
    titulo: textoOuNull(v.titulo),
    descricao: textoOuNull(v.descricao),
    responsavel: textoOuNull(v.responsavel),
    concluida: marcado(v, 'concluida'),
    data_prevista: dataDe(v.data_prevista),
  };
}

export function camposActividade(tipos: Record<string, string>, responsavel?: string | null): CampoFormulario[] {
  return [
    { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', obrigatorio: true, omissao: 'TAREFA', largura: 140, opcoes: Object.entries(tipos).map(([value, label]) => ({ value, label })) },
    { nome: 'data_prevista', rotulo: 'Data prevista', tipo: 'data', largura: 140, omissao: () => dayjs().format('YYYY-MM-DD'), aliases: ['Data'] },
    { nome: 'responsavel', rotulo: 'Responsável', maxLength: 100, largura: 130, omissao: responsavel ?? '' },
    { nome: 'titulo', rotulo: 'Título', maxLength: 255, largura: 230, aliases: ['Assunto'] },
    { nome: 'descricao', rotulo: 'Descrição', tipo: 'textoLongo', maxLength: 4000, largura: 220, emLinhas: 'opcional', seccao: 'Detalhe' },
    { nome: 'concluida_sn', rotulo: 'Já concluída', tipo: 'select', omissao: 'N', largura: 100, emLinhas: 'opcional', seccao: 'Detalhe', opcoes: SIM_NAO },
  ];
}

// ---------------- Oportunidades ----------------

/** Corpo da oportunidade: com linhas, o valor é o total das linhas (s/ IVA); sem linhas, o valor indicado (ou 0). */
export function dadosOportunidade(v: Record<string, unknown>): Record<string, unknown> {
  const itens = (Array.isArray(v.itens) ? v.itens : []) as ItemOportunidade[];
  return {
    titulo: String(v.titulo ?? '').trim(),
    conta_crm_id: v.conta_crm_id,
    contacto_crm_id: v.contacto_crm_id ?? null,
    funil_vendas_crm_id: v.funil_vendas_crm_id,
    etapa_codigo: textoOuNull(v.etapa_codigo),
    valor: itens.length ? totalItens(itens) : (numeroOuNull(v.valor) ?? 0),
    probabilidade: numeroOuNull(v.probabilidade),
    data_fecho_prevista: dataDe(v.data_fecho_prevista),
    responsavel: textoOuNull(v.responsavel),
    origem: textoOuNull(v.origem),
    notas: textoOuNull(v.notas),
    itens,
  };
}

/** Etapas abertas dos funis activos (uma opção por código; o código repetido em vários funis mostra os funis). */
function opcoesEtapas(funis: Funil[]): OpcaoCampo[] {
  const mapa = new Map<string, { nome: string; funis: string[] }>();
  for (const f of funis.filter((x) => x.ativo)) {
    for (const e of f.etapas.filter((x) => x.tipo === 'ABERTA')) {
      const o = mapa.get(e.id) ?? { nome: e.nome, funis: [] };
      o.funis.push(f.nome);
      mapa.set(e.id, o);
    }
  }
  return [...mapa.entries()].map(([value, o]) => ({ value, label: `${o.nome} (${o.funis.join(', ')})` }));
}

export function camposOportunidade(opts: { contas: OpcaoCampo[]; funis: Funil[]; origens: string[]; responsavel?: string | null }): CampoFormulario[] {
  const activos = opts.funis.filter((f) => f.ativo);
  return [
    { nome: 'titulo', rotulo: 'Título', obrigatorio: true, maxLength: 255, largura: 230, aliases: ['Oportunidade'] },
    { nome: 'conta_crm_id', rotulo: 'Conta', tipo: 'select', obrigatorio: true, largura: 210, opcoes: opts.contas, aliases: ['Cliente', 'Prospect'] },
    { nome: 'funil_vendas_crm_id', rotulo: 'Funil', tipo: 'select', obrigatorio: true, largura: 150, omissao: activos[0]?.id ?? null, opcoes: activos.map((f) => ({ value: f.id, label: f.nome })) },
    { nome: 'etapa_codigo', rotulo: 'Etapa', tipo: 'select', largura: 170, emLinhas: 'opcional', seccao: 'Funil', opcoes: opcoesEtapas(opts.funis), placeholder: 'A primeira',
      validar: (e, r) => {
        if (!e) return null;
        const f = opts.funis.find((x) => x.id === r.funil_vendas_crm_id);
        return f && !f.etapas.some((x) => x.id === e && x.tipo === 'ABERTA') ? `A etapa não pertence ao funil «${f.nome}».` : null;
      } },
    { nome: 'data_fecho_prevista', rotulo: 'Fecho previsto', tipo: 'data', largura: 140, aliases: ['Data de fecho'] },
    { nome: 'valor', rotulo: 'Valor (Kz)', tipo: 'numero', min: 0, precisao: 2, largura: 130, aliases: ['Valor'] },
    { nome: 'probabilidade', rotulo: 'Probabilidade (%)', tipo: 'numero', min: 0, max: 100, precisao: 0, largura: 110, emLinhas: 'opcional', seccao: 'Funil' },
    { nome: 'responsavel', rotulo: 'Responsável', maxLength: 100, largura: 130, omissao: opts.responsavel ?? '' },
    { nome: 'origem', rotulo: 'Origem', tipo: 'select', largura: 140, emLinhas: 'opcional', seccao: 'Responsável e origem', opcoes: opts.origens.map((o) => ({ value: o, label: o })) },
    { nome: 'notas', rotulo: 'Notas', tipo: 'textoLongo', maxLength: 4000, largura: 200, emLinhas: 'opcional', seccao: 'Responsável e origem' },
  ];
}
