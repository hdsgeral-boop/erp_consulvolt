/** Filtros da lista de lançamentos (regras puras, testáveis) — ver ListaLancamentos.tsx. */

export type SemCampo = 'demo' | 'fluxo' | 'un' | 'cc';

export const SEM_ROTULOS: { chave: SemCampo; rotulo: string; titulo: string }[] = [
  { chave: 'demo', rotulo: 'Nota Dem.', titulo: 'Filtrar linhas sem Nota Demonstrativa' },
  { chave: 'fluxo', rotulo: 'Nota Fluxo', titulo: 'Filtrar linhas sem Nota de Fluxo' },
  { chave: 'un', rotulo: 'UN', titulo: 'Filtrar linhas sem Unidade de Negócio' },
  { chave: 'cc', rotulo: 'CC', titulo: 'Filtrar linhas sem Centro de Custo' },
];

/** Períodos do filtro rápido (legado: 1–12, 13 (Aj. Enc.) e 14 (Aj. Esp.), js/ui_lancamentos.js:484-491). */
export const PERIODOS: { value: number; label: string }[] = [
  ...Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: String(i + 1) })),
  { value: 13, label: '13 (Aj. Enc.)' },
  { value: 14, label: '14 (Aj. Esp.)' },
];

/** Valor do selector «alterar em massa» que significa remover (o «[REMOVER NOTA]» do legado). */
export const REMOVER = -1;

export interface FiltrosLista {
  ano?: number;
  mes?: number;
  /** Data fiscal (início/fim) — data_documento. */
  inicio?: string;
  fim?: string;
  /** Data do documento original (legado entry_date → data_lancamento). */
  docInicio?: string;
  docFim?: string;
  /** Data do lançamento no sistema (legado created_at → criado_em). */
  criadoInicio?: string;
  criadoFim?: string;
  contas?: string;
  diario?: number;
  numeroLan?: string;
  numeroDoc?: string;
  referencia?: string;
  terceiro?: number;
  pesquisa?: string;
  classe9?: boolean;
}

const pad = (n: number) => String(n).padStart(2, '0');

/** Ano/período fiscal → intervalo de datas (legado: setPeriodFromMonthYear). Sem ano, não mexe nas datas. */
export function intervaloPeriodo(ano?: number, mes?: number): { inicio?: string; fim?: string } {
  if (!ano) return {};
  if (!mes) return { inicio: `${ano}-01-01`, fim: `${ano}-12-31` };
  // períodos 13 (Aj. Enc.) e 14 (Aj. Esp.): ajustes que, como no legado (js/ui_reports.js:1916-1919), caem no mês 12
  if (mes === 13 || mes === 14) return { inicio: `${ano}-12-01`, fim: `${ano}-12-31` };
  const ultimo = new Date(Date.UTC(ano, mes, 0)).getUTCDate();
  return { inicio: `${ano}-${pad(mes)}-01`, fim: `${ano}-${pad(mes)}-${pad(ultimo)}` };
}

/** Filtros iniciais da lista: o Ano vem pré-seleccionado com o ano corrente (intervalo 01/01–31/12); o utilizador pode limpá-lo. */
export function filtrosIniciais(ano: number = new Date().getFullYear()): FiltrosLista {
  return { ano, ...intervaloPeriodo(ano) };
}

/** Filtros aplicados → parâmetros da API (GET /contabilidade/lancamentos, classificação e descontabilização por filtro). */
export function filtrosLancamentos(f: FiltrosLista, sem: SemCampo[]): Record<string, unknown> {
  const t = (v?: string) => (v && v.trim() ? v.trim() : undefined);
  return {
    diario_id: f.diario,
    filtro_contas: t(f.contas),
    terceiro_id: f.terceiro,
    numero_lan: t(f.numeroLan),
    numero_documento: t(f.numeroDoc),
    referencia: t(f.referencia),
    pesquisa: t(f.pesquisa),
    incluir_classe_9: f.classe9 ? 1 : undefined,
    data_inicio: f.inicio,
    data_fim: f.fim,
    data_doc_inicio: f.docInicio,
    data_doc_fim: f.docFim,
    criado_inicio: f.criadoInicio,
    criado_fim: f.criadoFim,
    sem: sem.length ? sem : undefined,
  };
}

/**
 * Nenhum critério aplicado: as operações «a todas as filtradas» (descontabilizar, alterar em massa) exigem pelo menos um
 * (o legado exigia data, conta, diário ou documento antes de consultar).
 */
export function semCriterios(f: FiltrosLista, sem: SemCampo[]): boolean {
  return Object.entries(filtrosLancamentos(f, sem)).every(([k, v]) => k === 'incluir_classe_9' || v === undefined);
}

/** Selectores da alteração em massa → campos do pedido (vazio = manter; REMOVER = null). */
export function camposMassa(massa: Record<string, number | undefined>): Record<string, number | null> {
  return Object.fromEntries(Object.entries(massa).filter(([, v]) => v !== undefined).map(([k, v]) => [k, v === REMOVER ? null : (v as number)]));
}
