/**
 * Regras puras das Séries de numeração (js/vendas_series.js e js/facturacao_agt.js:125-186 do legado novo). O servidor
 * valida tudo outra vez (ServicoConfigFaturacaoEletronica::gravarSerie); aqui servem para avisar antes de gravar.
 */

export const TIPOS_SERIE = [
  { tipo: 'FT', nome: 'Factura', ajuda: 'Factura de cliente a pagar mais tarde' },
  { tipo: 'FR', nome: 'Factura-Recibo', ajuda: 'Factura paga no acto (balcão, POS)' },
  { tipo: 'NC', nome: 'Nota de Crédito', ajuda: 'Correcção ou anulação de uma factura' },
] as const;

export type TipoSerie = (typeof TIPOS_SERIE)[number]['tipo'];

export const NUMERO_MAXIMO = 99_999_999;

export interface SerieNumeracao {
  id: number;
  tipo: string;
  tipo_nome?: string;
  ano: number;
  origem: string;
  origem_rotulo?: string | null;
  codigo: string;
  estabelecimento: string | null;
  proximo_numero: number;
  proximo_documento: string;
  emitidos: number;
  usada: boolean;
  ultima_data: string | null;
  ultimo_documento: string | null;
  estado: 'ATIVA' | 'FECHADA' | string;
  contingencia: boolean;
  agt_codigo: string | null;
  agt_primeiro_numero: number | null;
  agt_ultimo_numero: number | null;
}

export interface ResumoTipo {
  tipo: string;
  nome: string;
  ano: number;
  serie_id: number | null;
  codigo: string | null;
  proximo_documento: string | null;
}

export interface DadosSeries {
  ano: number;
  regime: { ativo: boolean; data_inicio: string | null; exigir_series_agt: boolean };
  estabelecimentos: { numero: string; nome: string | null }[];
  origens: { valor: string; rotulo: string }[];
  tipos: Record<string, string>;
  resumo: ResumoTipo[];
  series: SerieNumeracao[];
}

export const nomeTipo = (tipo: string) => TIPOS_SERIE.find((t) => t.tipo === tipo)?.nome ?? tipo;

/** Código da série: só letras e números, sem espaços nem «/», até 30 caracteres. */
export function erroCodigo(codigo: string | null | undefined): string | null {
  const c = String(codigo ?? '').trim();
  if (!c) return 'Indique o código da série.';
  if (!/^[A-Za-z0-9]{1,30}$/.test(c)) return 'O código só pode ter letras e números (sem espaços nem «/»), até 30 caracteres.';
  return null;
}

/** Número inicial: inteiro entre 1 e 99 999 999 (proximoValido). Vazio = 1. */
export function erroNumeroInicial(valor: number | string | null | undefined): string | null {
  const t = String(valor ?? '').trim();
  if (!t) return null;
  const n = Number(t);
  if (!Number.isInteger(n) || n < 1 || n > NUMERO_MAXIMO) return 'O número inicial da série tem de ser um inteiro entre 1 e 99 999 999.';
  return null;
}

/** Número do próximo documento no formato SAF-T «TIPO SÉRIE/N» (ex.: FT A2026/1). */
export function proximoDocumento(tipo: string, codigo: string | null | undefined, numero: number | string | null | undefined): string | null {
  const c = String(codigo ?? '').trim();
  if (!c) return null;
  const n = Math.max(1, Math.trunc(Number(numero) || 1));
  return `${tipo} ${c}/${n}`;
}

/** «Usada» = já emitiu documentos (não «próximo > 1»: uma série virgem pode arrancar em 501). */
export const serieUsada = (s: Pick<SerieNumeracao, 'emitidos'>) => s.emitidos > 0;

/** O número inicial, o tipo, o ano, a origem e o código só se alteram enquanto a série está virgem. */
export const podeAlterarNumeracao = (s: Pick<SerieNumeracao, 'emitidos'> | null) => !s || !serieUsada(s);

/** Série com documentos não se apaga: fecha-se. */
export const podeEliminar = (s: Pick<SerieNumeracao, 'emitidos'>) => !serieUsada(s);

/** Uma só série activa por tipo + ano + origem: devolve a outra série activa que impede gravar, se houver. */
export function outraActiva(
  series: Pick<SerieNumeracao, 'id' | 'tipo' | 'ano' | 'origem' | 'estado' | 'codigo'>[],
  dados: { id?: number | null; tipo: string; ano: number; origem: string; estado: string },
) {
  if (dados.estado !== 'ATIVA') return null;
  return series.find((s) => s.id !== dados.id && s.estado === 'ATIVA' && s.tipo === dados.tipo && s.ano === dados.ano && s.origem === (dados.origem || 'GERAL')) ?? null;
}

/** Texto do cartão de cada tipo: série atribuída hoje ou o que acontece sem ela. */
export function textoCartao(r: ResumoTipo, regime: DadosSeries['regime']): { numero: string; nota: string; atribuida: boolean } {
  if (r.serie_id && r.codigo) return { numero: r.proximo_documento ?? `${r.tipo} ${r.codigo}/1`, nota: `Série ${r.codigo} de ${r.ano}`, atribuida: true };
  if (regime.exigir_series_agt) {
    return { numero: `${r.tipo} — sem série AGT`, nota: `Sem série atribuída pela AGT para ${r.ano}: a emissão é recusada até haver uma.`, atribuida: false };
  }
  // no erp_laravel a «numeração automática» é a série A<ano>, criada pelo sistema no primeiro documento do ano
  return { numero: `${r.tipo} A${r.ano}/…`, nota: `Sem série para ${r.ano}: o sistema numera sozinho (série A${r.ano}, criada no primeiro documento).`, atribuida: false };
}

/** Código sugerido para uma série nova: A<ano> na facturação geral, <terminal><ano> num terminal POS. */
export function codigoSugerido(origem: string, ano: number): string {
  const base = origem && origem !== 'GERAL' ? origem.replace(/[^A-Za-z0-9]/g, '').toUpperCase() || 'T' : 'A';
  return `${base}${ano}`.slice(0, 30);
}
