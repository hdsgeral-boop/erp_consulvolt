/** Filtros da lista de lançamentos (regras puras, testáveis) — ver ListaLancamentos.tsx. */

export type SemCampo = 'demo' | 'fluxo' | 'un' | 'cc';

export const SEM_ROTULOS: { chave: SemCampo; rotulo: string; titulo: string }[] = [
  { chave: 'demo', rotulo: 'Nota Dem.', titulo: 'Linhas sem nota às demonstrações' },
  { chave: 'fluxo', rotulo: 'Nota Fluxo', titulo: 'Linhas sem nota de fluxo de caixa' },
  { chave: 'un', rotulo: 'UN', titulo: 'Linhas sem unidade de negócio' },
  { chave: 'cc', rotulo: 'CC', titulo: 'Linhas sem centro de custo' },
];

/** Valor do selector «alterar em massa» que significa remover (o «[REMOVER NOTA]» do legado). */
export const REMOVER = -1;

export interface FiltrosLista {
  ano?: number;
  mes?: number;
  inicio?: string;
  fim?: string;
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
  const ultimo = new Date(Date.UTC(ano, mes, 0)).getUTCDate();
  return { inicio: `${ano}-${pad(mes)}-01`, fim: `${ano}-${pad(mes)}-${pad(ultimo)}` };
}

/** Filtros aplicados → parâmetros da API (GET /contabilidade/lancamentos e classificação por filtro). */
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
    sem: sem.length ? sem : undefined,
  };
}

/** Selectores da alteração em massa → campos do pedido (vazio = manter; REMOVER = null). */
export function camposMassa(massa: Record<string, number | undefined>): Record<string, number | null> {
  return Object.fromEntries(Object.entries(massa).filter(([, v]) => v !== undefined).map(([k, v]) => [k, v === REMOVER ? null : (v as number)]));
}
