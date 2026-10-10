/**
 * Regras de apresentação dos ecrãs Inventário, Categorias, Manutenções e Mapa de Amortizações, copiadas do sistema anterior
 * (js/ui_assets.js: renderAssetList, renderAssetCategories, renderMaintenanceView, renderAmortizationMap).
 */

/** Nome do ficheiro do modelo de importação (downloadAssetExcelTemplate do legado). */
export const NOME_TEMPLATE_ACTIVOS = 'Template_Migração_Imobilizado.xlsx';

/** Destino da ficha (histórico) de um activo. */
export const destinoActivo = (id: number) => `/m/activos/activos/${id}`;

/** Texto vazio como no legado: «---». */
export function ouTracos(v: string | number | null | undefined): string {
  return v === null || v === undefined || v === '' ? '---' : String(v);
}

/** Número com 2 casas (pt-PT) seguido de «%» — «Taxa %» e «Taxa (%)» do legado. */
export function percentagem(v: string | number | null | undefined): string {
  const n = Number(v ?? 0);
  return `${(Number.isFinite(n) ? n : 0).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
}

/** Taxa do inventário: 100 / (vida útil / 12), como no legado; sem vida útil → «---». */
export function taxaPorVidaUtil(vidaMeses: number | null | undefined): string {
  const v = Number(vidaMeses ?? 0);
  return v > 0 ? percentagem(1200 / v) : '---';
}

/** «Vida útil» do inventário: meses com o sufixo «m». */
export function vidaUtilMeses(vidaMeses: number | null | undefined): string {
  return vidaMeses === null || vidaMeses === undefined ? '---' : `${vidaMeses} m`;
}

/** Coluna «Anos» do mapa: vida útil em anos com 1 casa («4,0»). */
export function anosVida(vidaMeses: number | string | null | undefined): string {
  const v = Number(vidaMeses ?? 0);
  return (v > 0 ? v / 12 : 0).toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}

/** «Taxa %» do mapa: «FIXA» quando o activo tem quota fixa; senão a taxa anual da categoria com 2 casas. */
export function taxaMapa(taxa: string | number | null | undefined): string {
  return taxa === 'FIXA' ? 'FIXA' : percentagem(taxa);
}

/** Valor do mapa: zero (ou vazio) aparece como «-», como no legado. */
export function valorOuHifen(v: string | number | null | undefined, formatar: (v: string) => string): string {
  const n = Number(v ?? 0);
  return !Number.isFinite(n) || n === 0 ? '-' : formatar(String(v));
}

/** «Anos» já em anos (servidor): 1 casa decimal; vazio → «0». */
export function anosTexto(anos: string | number | null | undefined): string {
  const n = Number(anos ?? 0);
  return n > 0 ? n.toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : '0';
}

/** Activo com amortizações calculadas/integradas: o legado mostra o cadeado e bloqueia a eliminação. */
export interface EstadoLinhaActivo {
  bloqueado?: boolean | null;
}

export function dicaEditar(a: EstadoLinhaActivo): string {
  return a.bloqueado ? 'Edição Bloqueada (Processado)' : 'Editar';
}

export function iconeEditar(a: EstadoLinhaActivo): 'lock' | 'edit' {
  return a.bloqueado ? 'lock' : 'edit';
}

/** Razão do bloqueio da eliminação (texto da dica) ou `false` se pode apagar. */
export function bloqueioEliminar(a: EstadoLinhaActivo): string | false {
  return a.bloqueado ? 'Bloqueado: Amortizações Processadas' : false;
}

/** Manutenção ainda por executar (botão «Executar»); as concluídas mostram o ✓✓. */
export function manutencaoPlaneada(estado: string | null | undefined): boolean {
  return (estado || 'PLANEADA').toUpperCase() === 'PLANEADA';
}

/** Texto do estado da manutenção como no legado (sem estado → «PLANEADA»). */
export function estadoManutencao(estado: string | null | undefined): string {
  return (estado || 'PLANEADA').toUpperCase();
}

/** Cor da etiqueta do estado do activo (badge-success se ACTIVO, badge-danger nos restantes). */
export function corEstadoActivo(estado: string | null | undefined): 'success' | 'error' {
  return estado === 'ACTIVO' ? 'success' : 'error';
}

/** Cor da etiqueta do tipo de manutenção (badge-primary preventiva, badge-warning correctiva). */
export function corTipoManutencao(tipo: string | null | undefined): 'processing' | 'warning' {
  return tipo === 'PREVENTIVA' ? 'processing' : 'warning';
}

/** Anos disponíveis no filtro do mapa (o legado mostrava 2023–2027): sempre de 2023 até ao ano seguinte ao actual. */
export function anosMapa(actual: number, seleccionado?: number): number[] {
  const fim = Math.max(actual + 1, seleccionado ?? 0, 2027);
  const inicio = Math.min(2023, seleccionado ?? 2023);
  return Array.from({ length: fim - inicio + 1 }, (_, i) => inicio + i);
}
