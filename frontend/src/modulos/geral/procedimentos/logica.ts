import type { ChaveOpcao, ChaveTexto, DefinicoesManual, OrigemProcesso, Processo, Recolha } from './api';

/** Documentos que vivem noutro sítio: um processo cruza-se com eles mas não são procedimentos deste manual. */
export const EXTERNOS: Record<string, string> = {
  estrutura_manual: 'Estrutura Orgânica — Manual da Organização',
  estrategia_plano: 'Planeamento Estratégico — Documento do Plano',
};

export const ROTULO_ORIGEM: Record<OrigemProcesso, { rotulo: string; cor: string }> = {
  FABRICA: { rotulo: 'do ERP', cor: 'default' },
  EDITADO: { rotulo: 'adaptado', cor: 'cyan' },
  PROPRIO: { rotulo: 'próprio', cor: 'purple' },
};

export const ROTULO_OPCAO: Record<ChaveOpcao, { rotulo: string; ajuda?: string }> = {
  incluir_diagrama: { rotulo: 'Diagrama das etapas em cada processo' },
  incluir_legenda: { rotulo: '«Como ler este manual»', ajuda: 'explica os campos' },
  incluir_matriz: { rotulo: 'Matriz de intervenientes por processo' },
  incluir_controlos: { rotulo: 'Quadro dos controlos-chave', ajuda: 'a lista por onde se audita' },
  incluir_cruzamentos: { rotulo: 'Matriz geral — onde os processos se encontram', ajuda: 'e, em cada ficha, o que entrega e o que recebe' },
  incluir_cobertura: { rotulo: 'Cobertura: módulos do ERP × processos descritos', ajuda: 'útil na revisão, não no manual que circula' },
  conferir_funcoes: { rotulo: 'Conferir os intervenientes com RH › Funções' },
};

export interface BlocoTexto { tipo: 'p' | 'ul'; itens: string[] }

/** Texto livre → blocos: parágrafos separados por linha em branco; vários itens por linha (um por linha) viram lista. */
export function blocosDeTexto(texto: string | null | undefined): BlocoTexto[] {
  return String(texto ?? '')
    .replace(/\r\n?/g, '\n')
    .split(/\n\s*\n/)
    .map((b) => b.split('\n').map((l) => l.trim()).filter(Boolean))
    .filter((l) => l.length > 0)
    .map((l) => (l.length > 1 ? { tipo: 'ul' as const, itens: l.map((x) => x.replace(/^[-•*]\s+/, '')) } : { tipo: 'p' as const, itens: l }));
}

/** B-03: o que se envia ao servidor são os processos EXCLUÍDOS (os que ficaram por marcar), não os incluídos. */
export function excluidosDe(marcados: readonly string[], todos: readonly string[]): string[] {
  const m = new Set(marcados);
  return todos.filter((k) => !m.has(k));
}

export const nomeDoProcesso = (chave: string, porChave: Map<string, Processo>): string => {
  const p = porChave.get(chave);
  if (p) return p.titulo;
  return EXTERNOS[chave] ? `${EXTERNOS[chave]} (fora deste manual)` : chave;
};

export interface Capitulo { id: string; titulo: string; n: number }

/** Os capítulos que o documento leva, pela ordem e com as condições do legado; `n` é o número do capítulo. */
export function capitulos(f: Pick<DefinicoesManual, ChaveTexto | ChaveOpcao>, d: Pick<Recolha, 'intervenientes' | 'cruzamentos' | 'cobertura' | 'controlos'>): Capitulo[] {
  const lista: [string, string, boolean][] = [
    ['apresentacao', 'Apresentação', !!f.apresentacao?.trim()],
    ['objetivo', 'Objectivo e âmbito', !!f.objetivo?.trim()],
    ['legenda', 'Como ler este manual', !!f.incluir_legenda],
    ['principios', 'Princípios e regras gerais', !!f.principios?.trim()],
    ['mapa', 'Mapa dos processos', true],
    ['matriz', 'Intervenientes por processo', !!(f.incluir_matriz && d.intervenientes.length)],
    ['cruzamentos', 'Matriz geral — onde os processos se encontram', !!(f.incluir_cruzamentos && d.cruzamentos.ligacoes.length)],
    ['cobertura', 'Cobertura: módulos do ERP e processos descritos', !!(f.incluir_cobertura && d.cobertura)],
    ['processos', 'Os procedimentos', true],
    ['controlos', 'Controlos-chave', !!(f.incluir_controlos && d.controlos.length)],
    ['notas', 'Disposições finais', !!f.notas?.trim()],
  ];
  let n = 0;
  return lista.filter(([, , incluir]) => incluir).map(([id, titulo]) => ({ id, titulo, n: ++n }));
}
