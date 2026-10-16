import dayjs from 'dayjs';
import { dataApi } from '@/utilitarios/formatacao';
import { MODELOS_CAL } from './regrasMetas';

/**
 * Regras de gravação dos modais de Projectos, comuns à vista Grade (valores do `Form`, datas em `dayjs`) e à vista
 * Linhas (valores simples, datas 'AAAA-MM-DD') — legado: formularios_linhas_config.js (projectos, projecto_equipa,
 * projecto_orcamento, projecto_horas, projecto_equipamento, projecto_aditamentos). Cada linha é gravada pelo mesmo
 * pedido da grade, com o corpo dado por estas funções.
 */

/** Data do formulário (dayjs) ou da vista Linhas ('AAAA-MM-DD') → API. */
export const dataDe = (d: unknown): string | null => (dayjs.isDayjs(d) ? dataApi(d) ?? null : typeof d === 'string' && d ? d : null);

const textoOuNulo = (v: unknown): string | null => (typeof v === 'string' ? v.trim() || null : v === null || v === undefined ? null : String(v));
const numeroOuNulo = (v: unknown): number | null => (v === null || v === undefined || v === '' ? null : Number(v));

export const ESTADOS_PROJECTO_INICIAL = [{ value: 'PREPARACAO', label: 'Em preparação' }, { value: 'ACTIVO', label: 'Activo' }];
export const TIPOS_PROJECTO = [{ value: 'INTERNO', label: 'Interno' }, { value: 'EXTERNO', label: 'Externo (obra)' }];

/** Projecto (saveNewProject): INTERNO leva UN e CC; EXTERNO leva cliente e encomenda; calendário por omissão Seg–Sex, 8 h. */
export function dadosProjecto(v: Record<string, unknown>, edicao = false): Record<string, unknown> {
  const { cal_modelo: modelo, cal_horas: horas, cal_dias: diasCal, ...resto } = v as Record<string, unknown> & { cal_modelo?: string; cal_horas?: number; cal_dias?: number[] };
  const dados: Record<string, unknown> = {
    ...resto,
    codigo: textoOuNulo(v.codigo),
    // calendário de trabalho (ui_projects.js:246-334): o servidor valida horas (0,5 a 24) e dias (0, ½ ou 1)
    calendario: { modelo: modelo ?? 'SEG_SEX', horas_dia: horas ?? 8, dias: modelo && modelo !== 'PERSONALIZADO' ? MODELOS_CAL[modelo].dias : diasCal ?? MODELOS_CAL.SEG_SEX.dias },
    descricao: textoOuNulo(v.descricao),
    unidade_negocio_id: v.unidade_negocio_id ?? null,
    centro_custo_id: v.centro_custo_id ?? null,
    cliente_id: v.tipo === 'EXTERNO' ? v.cliente_id ?? null : null,
    encomenda_venda_id: v.tipo === 'EXTERNO' ? v.encomenda_venda_id ?? null : null,
  };
  if (edicao) delete dados.estado;
  return dados;
}

/** Validações da linha do projecto (as mesmas regras do formulário). */
export function validarLinhaProjecto(campo: 'unidade_negocio_id' | 'centro_custo_id' | 'cliente_id' | 'encomenda_venda_id', valor: unknown, r: Record<string, unknown>): string | null {
  const vazio = valor === null || valor === undefined || valor === '';
  const tipo = r.tipo ?? 'INTERNO';
  if (!vazio) return null;
  if (tipo === 'INTERNO' && campo === 'unidade_negocio_id') return 'Unidade de negócio obrigatória num projecto interno.';
  if (tipo === 'INTERNO' && campo === 'centro_custo_id') return 'Centro de custo obrigatório num projecto interno.';
  if (tipo === 'EXTERNO' && campo === 'cliente_id') return 'Indique o cliente.';
  if (tipo === 'EXTERNO' && campo === 'encomenda_venda_id') return 'Indique a encomenda.';
  return null;
}

export const TIPOS_MEMBRO = [{ value: 'INTERNO', label: 'Colaborador' }, { value: 'TERCEIRO', label: 'Terceiro' }, { value: 'LIVRE', label: 'Nome livre' }];

/** Membro da equipa (saveTeamMember): só a coluna do tipo escolhido segue para o servidor. */
export function dadosMembro(v: Record<string, unknown>): Record<string, unknown> {
  return {
    tipo: v.tipo, colaborador_id: v.tipo === 'INTERNO' ? v.colaborador_id ?? null : null, terceiro_id: v.tipo === 'TERCEIRO' ? v.terceiro_id ?? null : null,
    nome_externo: v.tipo === 'LIVRE' ? textoOuNulo(v.nome_externo) : null, papel: textoOuNulo(v.papel), horas_alocadas: numeroOuNulo(v.horas_alocadas),
  };
}

/** A coluna do membro tem de corresponder ao tipo (legado: «escolha o tipo e preencha a coluna correspondente»). */
export function validarLinhaMembro(campo: 'colaborador_id' | 'terceiro_id' | 'nome_externo', valor: unknown, r: Record<string, unknown>): string | null {
  const vazio = valor === null || valor === undefined || (typeof valor === 'string' && !valor.trim());
  const tipo = r.tipo ?? 'INTERNO';
  if (!vazio) return null;
  if (tipo === 'INTERNO' && campo === 'colaborador_id') return 'Escolha o colaborador.';
  if (tipo === 'TERCEIRO' && campo === 'terceiro_id') return 'Escolha o terceiro.';
  if (tipo === 'LIVRE' && campo === 'nome_externo') return 'Indique o nome.';
  return null;
}

/** Registo de horas (saveTimesheet). */
export function dadosHoras(v: Record<string, unknown>): Record<string, unknown> {
  return { membro_equipa_projeto_id: v.membro_equipa_projeto_id, tarefa_projeto_id: v.tarefa_projeto_id, data: dataDe(v.data), horas: numeroOuNulo(v.horas) };
}

/** Uso de equipamento (saveEquipmentLog): custo = horas × custo/hora (calculado no servidor). */
export function dadosEquipamento(v: Record<string, unknown>): Record<string, unknown> {
  return { ativo_imobilizado_id: v.ativo_imobilizado_id, tarefa_projeto_id: v.tarefa_projeto_id, data: dataDe(v.data), horas: numeroOuNulo(v.horas), custo_hora: numeroOuNulo(v.custo_hora) };
}

/** Linha do orçamento (saveBudgetLine): a mão de obra não leva conta. */
export function dadosLinhaOrcamento(v: Record<string, unknown>): Record<string, unknown> {
  return { tarefa_projeto_id: v.tarefa_projeto_id ?? null, rubrica: v.rubrica, numero_conta: v.rubrica === 'MAO_DE_OBRA' ? null : textoOuNulo(v.numero_conta), montante: numeroOuNulo(v.montante) };
}

export const ESTADOS_ADITAMENTO = [{ value: 'PENDENTE', label: 'Pendente' }, { value: 'APROVADO', label: 'Aprovado' }, { value: 'REJEITADO', label: 'Rejeitado' }];

/** Aditamento / trabalho a mais (saveChangeOrder): valor negativo = trabalhos a menos. */
export function dadosAditamento(v: Record<string, unknown>): Record<string, unknown> {
  return { descricao: v.descricao, montante: numeroOuNulo(v.montante), estado: v.estado ?? 'PENDENTE' };
}

/** Cores das posições do organigrama (valor gravado → cor da etiqueta). */
export const CORES_POSICAO: Record<string, string> = { azul: 'blue', verde: 'green', laranja: 'orange', roxo: 'purple', cinza: 'default', vermelho: 'red', ciano: 'cyan' };

/** Posição do organigrama (Versão Actual: projectos_organigrama.js). */
export function dadosPosicao(v: Record<string, unknown>): Record<string, unknown> {
  return { titulo: v.titulo, area: textoOuNulo(v.area), descricao: textoOuNulo(v.descricao), vagas: numeroOuNulo(v.vagas) ?? 0, no_pai_id: v.no_pai_id ?? null, cor: v.cor ?? 'azul', apoio: !!v.apoio };
}

/** «Sim/Não» colado do Excel → booleano (undefined = não reconhecido). */
export function simNao(texto: string): boolean | undefined {
  const s = texto.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  if (['s', 'sim', '1', 'x', 'true', 'verdadeiro'].includes(s)) return true;
  if (['n', 'nao', '0', 'false', 'falso'].includes(s)) return false;
  return undefined;
}
