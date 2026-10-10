import { estadoDe } from '@/componentes/fluxos';

/**
 * Regras do ecrã Fluxo de Processos do legado (js/fluxo_processos.js, fluxo_vendas.js:43-89, fluxo_tabela.js) que não
 * dependem do React: que fluxos mostram cartões no topo, que cartões aparecem, qual fica seleccionado e a etapa inicial.
 */

/** Linha da lista de processos (GET /gestao/fluxos/{id}/processos). */
export interface LinhaProcesso {
  chave: string;
  titulo: string;
  subtitulo: string | null;
  /** Linha do cartão ao lado da etiqueta (legado linhaData); sem ela mostra «data · valor». */
  linha_data?: string | null;
  data: string | null;
  valor: string | null;
  valor_pendente: string | null;
  total_etapas: number;
  concluidas: number;
  etapa_actual: string | null;
  etapa_actual_nome: string | null;
  etapa_actual_estado: string | null;
  etapa_actual_resumo: string | null;
  bloqueado: boolean;
  n_erros: number;
  n_avisos: number;
  progresso: Record<string, string>;
}

/**
 * Apresentação de cada fluxo no legado. Os fluxos com cartões (fluxo_processos.js e desenharPainel de fluxo_vendas.js) têm
 * a lista de cartões no topo, com o título da secção e o prefixo do diagrama; os restantes (Lavandaria, Projectos, Bancos e
 * Folha de Caixa) usam a vista de volume (funil + tabela, fluxo_tabela.js) e o diagrama abre por baixo da tabela.
 */
export const CARTOES: Readonly<Record<string, { lista: string; prefixo: string }>> = {
  rh: { lista: 'Períodos', prefixo: 'Processamento salarial' },
  ferias: { lista: 'Planos de férias', prefixo: 'Plano de férias' },
  avaliacao: { lista: 'Avaliações', prefixo: 'Avaliação' },
  vendas: { lista: 'Processos de venda', prefixo: 'Venda' },
  pos: { lista: 'Sessões de caixa', prefixo: 'POS' },
  compras: { lista: 'Processos de compra', prefixo: 'Compra' },
  imobilizado: { lista: 'Aquisições', prefixo: 'Compra de imobilizado' },
  orcamento: { lista: 'Orçamentos', prefixo: 'Orçamento' },
  crm: { lista: 'Oportunidades', prefixo: 'Oportunidade' },
  acrescimos: { lista: 'Registos', prefixo: 'Registo' },
};

export const temCartoes = (fluxo: string) => fluxo in CARTOES;

/** Título da secção dos cartões: «Períodos (últimos 3)» no RH, «Processos de venda (12)» nos outros. */
export function tituloCartoes(fluxo: string, n: number): string {
  const c = CARTOES[fluxo];
  if (!c) return '';
  return fluxo === 'rh' ? `${c.lista} (últimos ${n})` : `${c.lista} (${n})`;
}

/** Título do diagrama: «Processamento salarial · 09/2026» (RH) ou «Venda · FT 1 · Cliente» (restantes). */
export function tituloDiagrama(fluxo: string, nomeFluxo: string, p: { titulo: string; subtitulo?: string | null }): string {
  const c = CARTOES[fluxo];
  if (fluxo === 'rh') return `${c.prefixo} · ${p.titulo}`;
  return `${c?.prefixo ?? nomeFluxo} · ${p.titulo}${p.subtitulo ? ` · ${p.subtitulo}` : ''}`;
}

const concluido = (p: LinhaProcesso) => p.concluidas >= p.total_etapas;

/**
 * Cartões do topo (as linhas vêm por data, mais recentes primeiro). RH: os períodos avaliados (os 12 mais recentes,
 * fluxo_processos.js:674). Restantes: montarLista (fluxo_vendas.js:79-91) — até 24 por concluir e, a completar até 12,
 * os concluídos mais recentes.
 */
export function cartoesDoFluxo(fluxo: string, linhas: LinhaProcesso[]): LinhaProcesso[] {
  if (fluxo === 'rh') return linhas.slice(0, 12);
  const porConcluir = linhas.filter((p) => !concluido(p)).slice(0, 24);
  const feitos = linhas.filter(concluido);
  return [...porConcluir, ...feitos].slice(0, Math.max(12, porConcluir.length));
}

/** Cartão seleccionado ao abrir: o primeiro bloqueado, senão o primeiro por concluir, senão o primeiro. */
export function cartaoInicial(lista: LinhaProcesso[]): string | null {
  return (lista.find((p) => p.bloqueado) ?? lista.find((p) => !concluido(p)) ?? lista[0])?.chave ?? null;
}

/** Etapa mostrada ao abrir um processo (fluxo_processos.js:373): a bloqueada, senão a em curso, a por fazer ou a última. */
export function etapaInicial(etapas: { id: string }[], estados: Record<string, { estado: string } | undefined>): string | null {
  const com = (e: string) => etapas.find((x) => estadoDe(estados[x.id]?.estado) === e);
  return (com('bloqueada') ?? com('curso') ?? com('fazer') ?? etapas[etapas.length - 1])?.id ?? null;
}

/** «02/10/2026» a partir de «2026-10-02» (ou «2026-10» → «10/2026»); vazio → «—». */
export function dataCurta(d: string | null | undefined): string {
  if (!d) return '—';
  const s = String(d).slice(0, 10);
  const m = /^(\d{4})-(\d{2})(?:-(\d{2}))?$/.exec(s);
  if (!m) return s;
  return m[3] ? `${m[3]}/${m[2]}/${m[1]}` : `${m[2]}/${m[1]}`;
}
