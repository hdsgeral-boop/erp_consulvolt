/**
 * Estilo ÚNICO dos gráficos do ERP — o dos gráficos Chart.js do sistema anterior (js/ui_painel_modulos.js:15 e 930-975,
 * Chart.js 4 com as opções por omissão). Usado pelos gráficos SVG partilhados (componentes/graficos/Graficos.tsx) e pelos
 * do Dashboard Global (modulos/geral/comum/GraficoPainel.tsx). Um ecrã novo não define cores nem espessuras: usa
 * `GraficoAuto`/`GraficoBarras`/`GraficoLinhas`/`GraficoDonut` e, quando a série tem cor própria no legado, passa `cor`.
 *
 * Correspondência com o legado:
 *  - paleta categórica PALETA (10 cores, pela mesma ordem) → CORES_SERIES; para lá da 10.ª série: cinzento («Outros»);
 *  - barras: `backgroundColor: cor + 'cc'` (80 %), `borderWidth: 1`, `borderRadius: 6` (0 nas empilhadas), `maxBarThickness: 38`;
 *  - linhas: `tension: 0.35`, `borderWidth: 3` com área (`cor + '22'`, 13 %) ou 2 sem área, `pointRadius: 3`;
 *  - donut: `borderWidth: 2`, `borderColor: '#fff'`;
 *  - legenda no topo com marcadores redondos (usePointStyle, boxWidth 8, 11 px a 600); texto e grelha nas cores do Chart.js
 *    (texto rgba(0,0,0,0.6), grelha rgba(0,0,0,0.1→0.08));
 *  - cor por barra/fatia (`backgroundColor: [...]` do legado, p. ex. «Orçado × realizado» ou «Origem dos custos» dos
 *    projectos): `coresCategorias` (lista pela ordem dos rótulos ou função por categoria) nos gráficos, ou `cores` numa
 *    série vinda da API; sem isso, a paleta acima continua a ser a cor por omissão.
 *
 * Tela nocturna: as cores de texto/grelha/eixo são valores fixos nos atributos SVG; componentes/graficos/graficos.css troca-as
 * por versões claras quando o <html> tem `erp-tela-nocturna` (os seletores usam estes mesmos valores — se os mudar aqui,
 * mude-os lá).
 */
export { CORES_SERIES, COR_OUTROS, corSerie, corCategoria, type CoresCategorias } from './escalas';

export const ESTILO_GRAFICO = {
  /** Texto principal (valores, rótulos das barras horizontais). */
  tinta: 'rgba(0,0,0,0.88)',
  /** Texto secundário (eixos, legendas, rótulos de dados). */
  tintaSecundaria: 'rgba(0,0,0,0.6)',
  /** Linhas da grelha. */
  grelha: 'rgba(0,0,0,0.08)',
  /** Linha do zero / eixo. */
  eixo: 'rgba(0,0,0,0.3)',
  /** Guia vertical do ponto activo (dica das linhas). */
  guia: 'rgba(0,0,0,0.25)',
  /** Tamanho da letra dos eixos e legendas (px). */
  fonte: 11,
  barraOpacidade: 0.8,
  barraRaio: 6,
  barraEspessuraMaxima: 38,
  barraBorda: 1,
  linhaEspessura: 2,
  linhaEspessuraComArea: 3,
  areaOpacidade: 0.13,
  ponto: 3,
  pontoActivo: 5,
  donutBorda: 2,
} as const;
