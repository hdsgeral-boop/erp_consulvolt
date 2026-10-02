/**
 * Utilitários responsivos sem estado (podem ser usados fora de componentes).
 */

/** Margem lateral mínima (px, total dos dois lados) dos modais/gavetas em ecrãs estreitos. */
export const MARGEM_ECRA = 32;

/**
 * Largura de um Modal que nunca ultrapassa o ecrã: `min(px, 100vw − 32px)`.
 * Ex.: `<Modal width={larguraModal(900)} …>` — em 1366 px fica com 900 px, num telemóvel de 375 px com 343 px.
 */
export function larguraModal(px: number): string {
  return `min(${Math.round(px)}px, calc(100vw - ${MARGEM_ECRA}px))`;
}

/**
 * Largura de um Drawer lateral: `min(px, 100vw)` (num telemóvel a gaveta ocupa o ecrã todo).
 * Ex.: `<Drawer width={larguraGaveta(720)} …>`.
 */
export function larguraGaveta(px: number): string {
  return `min(${Math.round(px)}px, 100vw)`;
}

/**
 * Propriedade `scroll` recomendada para tabelas: desloca na horizontal dentro do próprio contentor
 * (nunca alarga a página). Com `y`, fixa a altura do corpo (cabeçalho fixo).
 * Ex.: `<Table scroll={scrollTabela()} …>` ou `scrollTabela(400)`.
 */
export function scrollTabela(y?: number | string): { x: 'max-content'; y?: number | string } {
  return y === undefined ? { x: 'max-content' } : { x: 'max-content', y };
}

/**
 * Colunas de uma grelha de Descriptions por ponto de quebra (1 coluna no telemóvel).
 * Ex.: `<Descriptions column={COLUNAS_DESCRICOES} …>`.
 */
export const COLUNAS_DESCRICOES = { xs: 1, sm: 1, md: 2, lg: 2, xl: 3, xxl: 3 } as const;

/**
 * Larguras de Col para campos de formulário/filtros em grelha (Row gutter={[16, 0]}):
 * 1 por linha no telemóvel, 2 no tablet, 3 no portátil, 4 em ecrã grande.
 * Ex.: `<Col {...COL_CAMPO}><Form.Item …/></Col>`; `COL_CAMPO_LARGO` para campos de texto longos.
 */
export const COL_CAMPO = { xs: 24, sm: 12, lg: 8, xxl: 6 } as const;
export const COL_CAMPO_LARGO = { xs: 24, lg: 16, xxl: 12 } as const;
