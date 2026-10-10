/**
 * Separadores do ecrã único «Relatórios e mapas contabilísticos» (renderRelatoriosContabeis, js/ui_reports.js:114-126),
 * pela ordem do sistema anterior. Cada separador tem a sua rota (`/m/contab/{ecra}`, com `?separador=` quando partilha
 * o ecrã de permissões — Balancete do Razão e Mapa IVA dependem, como no legado, de `contab_mapa_balancete`).
 */
export type ChaveSeparadorRelatorio = 'extrato' | 'balancete' | 'razao' | 'balanco' | 'dr' | 'fluxo' | 'evolucao' | 'iva';

export interface DefinicaoSeparadorRelatorio {
  chave: ChaveSeparadorRelatorio;
  rotulo: string;
  /** id do ecrã (catálogo de permissões) */
  ecra: string;
  /** valor de `?separador=` quando o ecrã é partilhado */
  query?: string;
  icone?: string;
  dica?: string;
}

export const SEPARADORES_RELATORIOS: DefinicaoSeparadorRelatorio[] = [
  { chave: 'extrato', rotulo: 'Extrato', ecra: 'contab_mapa_extrato' },
  { chave: 'balancete', rotulo: 'Balancete', ecra: 'contab_mapa_balancete' },
  { chave: 'razao', rotulo: 'Balancete do Razão', ecra: 'contab_mapa_balancete', query: 'razao' },
  { chave: 'balanco', rotulo: 'Balanço', ecra: 'contab_mapa_balanco' },
  { chave: 'dr', rotulo: 'Demonstração de resultados', ecra: 'contab_mapa_dr' },
  { chave: 'fluxo', rotulo: 'Fluxo de Caixa', ecra: 'contab_mapa_fluxo' },
  { chave: 'evolucao', rotulo: 'Evolução Mensal', ecra: 'contab_mapa_evolucao' },
  { chave: 'iva', rotulo: 'Mapa IVA', ecra: 'contab_mapa_balancete', query: 'iva', icone: 'percent', dica: 'Mapa de Reconciliação de IVA' },
];

/** Rota do separador. */
export function destinoSeparador(s: DefinicaoSeparadorRelatorio): string {
  return `/m/contab/${s.ecra}${s.query ? `?separador=${s.query}` : ''}`;
}

/** Separadores que o utilizador pode ver (permissão `{ecra}_view`). */
export function separadoresPermitidos(pode: (permissao: string) => boolean): DefinicaoSeparadorRelatorio[] {
  return SEPARADORES_RELATORIOS.filter((s) => pode(`${s.ecra}_view`));
}

/**
 * Separador a mostrar: o da rota (com `?separador=razao|iva` no ecrã do balancete); na entrada «Mapas e relatórios»
 * (`relatorios_contabeis`) ou sem permissão para o pedido, o primeiro permitido (js/ui_reports.js:88-99).
 */
export function separadorActivo(
  pedido: ChaveSeparadorRelatorio | null,
  pesquisa: string,
  pode: (permissao: string) => boolean,
): DefinicaoSeparadorRelatorio | null {
  const permitidos = separadoresPermitidos(pode);
  let chave = pedido;
  if (chave === 'balancete') {
    const q = new URLSearchParams(pesquisa).get('separador');
    if (q === 'razao' || q === 'iva') chave = q;
  }
  return permitidos.find((s) => s.chave === chave) ?? permitidos[0] ?? null;
}

/** Texto do âmbito do extracto aberto sem filtros (o último lançamento da empresa). */
export function descreverUltimo(u: { diario: string | null; numero_lan: string | null; numero_documento: string | null; data_documento: string } | null | undefined): string {
  if (!u) return 'Sem lançamentos nesta empresa.';
  const partes = [u.diario ? `diário ${u.diario}` : null, u.numero_lan ? `lançamento ${u.numero_lan}` : null, u.numero_documento ? `documento ${u.numero_documento}` : null];
  const [a, m, d] = u.data_documento.slice(0, 10).split('-');
  return `Último lançamento registado (${partes.filter(Boolean).join(' · ')}${partes.some(Boolean) ? ' · ' : ''}${d}/${m}/${a}). Indique conta(s) ou terceiro e carregue em «Gerar Extrato».`;
}

/** Normaliza o texto colado (Excel: uma conta por linha, ou separadas por vírgula/;) para o filtro de contas «21, 24, 26». */
export function normalizarContasColadas(texto: string): string {
  return [...new Set(texto.split(/[\n\r,;\t]+/).map((s) => s.replace(/\s+/g, '')).filter(Boolean))].join(', ');
}

/** Texto do filtro de contas → linhas para a caixa «Colar múltiplas contas» (legado: vírgulas → mudança de linha). */
export function contasParaColar(filtro: string | undefined): string {
  return (filtro ?? '').replace(/,/g, '\n').replace(/ /g, '');
}
