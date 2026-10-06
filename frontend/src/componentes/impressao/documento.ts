import dayjs from 'dayjs';
import { cssPagina, FORMATO_PADRAO } from './formato';
import type { FormatoPagina, IdentidadeImpressao, OpcoesDocumento } from './tipos';

/** Escapa texto para HTML. */
export function esc(texto: unknown): string {
  return String(texto ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c);
}

/** Só aceita logótipos em data URI de imagem (a CSP de produção só permite img-src 'self' data: blob:). */
export function logotipoSeguro(v: string | null | undefined): string | null {
  return v && /^data:image\/(png|jpe?g|gif|webp|svg\+xml);base64,[A-Za-z0-9+/=\s]+$/i.test(v) ? v : null;
}

/** Nome de ficheiro sugerido (o navegador acrescenta «.pdf»). */
export function nomeFicheiroPadrao(titulo: string, empresa?: string | null, data: Date = new Date()): string {
  const partes = [titulo, empresa, dayjs(data).format('YYYY-MM-DD')].filter(Boolean) as string[];
  return limparNomeFicheiro(partes.join(' - '));
}

export function limparNomeFicheiro(nome: string): string {
  return nome.replace(/[\\/:*?"<>|\r\n\t]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 150) || 'Documento';
}

/**
 * CSS base dos documentos impressos. Regras principais:
 *  - nada de barras de deslocação: overflow visível em tudo, sem max-height/scroll das tabelas Ant Design;
 *  - cabeçalho das tabelas repetido em cada página e linhas que não se partem;
 *  - sem sombras nem fundos pesados (cores exactas só no logótipo e nas linhas de totais).
 */
export const CSS_BASE = `
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; color: #000 !important; height: auto !important; min-height: 0 !important; }
body.imp-documento { font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 9.5pt; line-height: 1.35; -webkit-print-color-adjust: economy; print-color-adjust: economy; }
html, body, .imp-documento * { overflow: visible !important; scrollbar-width: none !important; }
.imp-documento ::-webkit-scrollbar { display: none !important; width: 0 !important; height: 0 !important; }
.imp-documento * { box-shadow: none !important; text-shadow: none !important; animation: none !important; transition: none !important; }
.imp-documento .ant-table-body, .imp-documento .ant-table-content, .imp-documento .ant-table-container,
.imp-documento .ant-table-header, .imp-documento .ant-table, .imp-documento .ant-table-wrapper,
.imp-documento .ant-card-body, .imp-documento .ant-modal-body,
.imp-documento [style*="max-height"], .imp-documento [style*="overflow"] { max-height: none !important; height: auto !important; }
.imp-documento .ant-table-cell-fix-left, .imp-documento .ant-table-cell-fix-right, .imp-documento [style*="sticky"] { position: static !important; }
.imp-documento .ant-table-cell-fix-left-last::after, .imp-documento .ant-table-cell-fix-right-first::after,
.imp-documento .ant-table-ping-left::before, .imp-documento .ant-table-ping-right::after { display: none !important; }
.imp-documento .ant-table-wrapper .ant-table table { table-layout: auto !important; }
.imp-documento .ant-table-thead > tr > th, .imp-documento .ant-table-tbody > tr > td, .imp-documento .ant-table-summary td { padding: 3px 6px !important; }
.imp-documento .ant-table-thead > tr > th::before { display: none !important; }
.imp-documento .ant-table-wrapper .ant-table { font-size: inherit !important; }
.imp-documento .ant-table-pagination, .imp-documento .ant-pagination, .imp-documento .ant-table-sticky-scroll,
.imp-documento .ant-table-filter-trigger, .imp-documento .ant-table-column-sorter, .imp-documento .ant-table-selection-column,
.imp-documento .ant-table-row-expand-icon-cell, .imp-documento .ant-table-expand-icon-col,
.imp-documento button, .imp-documento .ant-btn, .imp-documento .ant-slider, .imp-documento .ant-tabs-nav,
.imp-documento .no-print, .imp-documento .imp-nao-imprimir, .imp-documento .rh-nao-imprimir, .imp-documento .sem-impressao,
.imp-documento .imp-so-ecra, .imp-documento script { display: none !important; }
.imp-documento .ant-card { border-color: #d9d9d9 !important; break-inside: auto; }
/* As margens negativas das grelhas (Row com gutter) fariam o conteúdo «transbordar» uns píxeis na medição. */
.imp-documento .ant-row { margin-left: 0 !important; margin-right: 0 !important; }
/* Contentores flex em coluna (Space/Flex verticais) fragmentam mal no Chromium (páginas em branco): passam a bloco. */
.imp-documento .ant-space-vertical, .imp-documento .ant-flex-vertical { display: block !important; }
.imp-documento .ant-space-vertical > .ant-space-item + .ant-space-item, .imp-documento .ant-flex-vertical > * + * { margin-top: 12px; }
.imp-documento .ant-tag { background: transparent !important; }
/* Cores que transmitem informação (legendas dos gráficos, marcas .imp-cor) saem mesmo sem «gráficos de fundo». */
.imp-documento [aria-label="Legenda"] span[style*="background"], .imp-documento .imp-cor { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
thead { display: table-header-group; }
/* Totais só no fim (repetidos em cada página pareceriam totais parciais). */
tfoot { display: table-row-group; }
tr, img, svg, figure, .imp-sem-quebra, .rh-recibo, .ant-card-head, .ant-col > .ant-card, .ant-statistic, .ant-list-item, .ant-descriptions-row { break-inside: avoid; page-break-inside: avoid; }
.ant-card-head { break-after: avoid; page-break-after: avoid; }
h1, h2, h3, h4, caption { break-after: avoid; page-break-after: avoid; }
.imp-quebra-pagina { break-after: page; page-break-after: always; }
img, svg { max-width: 100%; }
table { border-collapse: collapse; }
body.imp-quebrar td, body.imp-quebrar th, body.imp-quebrar td *, body.imp-quebrar th * { white-space: normal !important; overflow-wrap: anywhere; }

/* Cabeçalho da empresa */
.imp-cabecalho { display: flex; justify-content: space-between; align-items: flex-start; gap: 6mm; padding-bottom: 2.5mm; border-bottom: 0.6mm solid #1f1f1f; margin-bottom: 3mm; break-inside: avoid; }
.imp-empresa { display: flex; align-items: center; gap: 4mm; min-width: 0; }
.imp-logotipo { display: block; max-height: 18mm; max-width: 48mm; width: auto; height: auto; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.imp-empresa-nome { font-size: 13pt; font-weight: 700; line-height: 1.2; }
.imp-empresa-dados { font-size: 7.5pt; color: #444; margin-top: 0.8mm; line-height: 1.35; }
.imp-emissao { font-size: 7.5pt; color: #444; text-align: right; white-space: nowrap; line-height: 1.5; }
.imp-titulo-bloco { margin: 0 0 4mm; break-after: avoid; }
.imp-titulo { font-size: 13pt; font-weight: 700; margin: 0; text-transform: none; }
.imp-subtitulo { font-size: 9pt; color: #333; margin-top: 0.5mm; }
.imp-periodo, .imp-filtros { font-size: 8.5pt; color: #333; margin-top: 0.5mm; }
.imp-conteudo { width: 100%; }
.imp-rodape-fim { margin-top: 6mm; font-size: 7.5pt; color: #555; text-align: center; }

/* Folhas da paginação do motor (paginacao.ts): altura útil fixa, rodapé com «Página X de Y» em cada folha */
.imp-pagina { position: relative; width: var(--imp-largura-pagina, auto); height: var(--imp-altura-pagina, auto); margin: 0; padding: 0; break-inside: avoid; page-break-inside: avoid; }
.imp-pagina:not(:last-child) { break-after: page; page-break-after: always; }
.imp-pagina-corpo { height: calc(100% - 7mm); }
.imp-pagina .imp-conteudo { display: flow-root; }
.imp-pagina-rodape { position: absolute; left: 0; right: 0; bottom: 0; min-height: 5mm; display: flex; justify-content: space-between; align-items: flex-end; gap: 6mm; padding-top: 1mm; border-top: 0.2mm solid #bfbfbf; font: 7.5pt/1.25 Arial, 'Helvetica Neue', Helvetica, sans-serif; color: #555; }
.imp-pagina-rodape-empresa { flex: 1 1 auto; min-width: 0; }
.imp-pagina-numero { flex: none; white-space: nowrap; }
.imp-fonte { position: absolute; left: 0; top: 0; }

/* Tabelas geradas por tabelaHtml() */
.imp-tabela { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-bottom: 4mm; }
.imp-tabela caption { text-align: left; font-weight: 700; font-size: 9.5pt; padding: 1mm 0; }
.imp-tabela th, .imp-tabela td { border: 0.2mm solid #bfbfbf; padding: 1mm 1.6mm; vertical-align: top; }
.imp-tabela th { background: #f0f0f0; font-weight: 700; text-align: left; white-space: nowrap; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.imp-tabela td { white-space: nowrap; }
.imp-tabela td.imp-quebra { white-space: normal; min-width: 30mm; }
.imp-tabela .imp-num { text-align: right; font-variant-numeric: tabular-nums; }
.imp-tabela .imp-centro { text-align: center; }
.imp-tabela tr.imp-grupo td { background: #fafafa; font-weight: 700; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.imp-tabela tr.imp-subtotal td { font-weight: 700; border-top: 0.4mm solid #595959; }
.imp-tabela tfoot td, .imp-tabela tr.imp-total td { font-weight: 700; background: #f0f0f0; border-top: 0.5mm solid #1f1f1f; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.imp-tabela .imp-vazio { text-align: center; color: #666; padding: 4mm; }

/*
 * Vias na mesma folha (recibos: original e cópia). Retrato: uma por cima da outra, cada uma em meia folha (a linha de
 * corte fica a meio); paisagem: lado a lado, com a linha de corte vertical. O bloco nunca se parte entre folhas
 * (.imp-uma-folha): se não couber, o motor reduz-o. Também serve markup próprio (ex.: recibo de salário do RH).
 */
.imp-vias { display: flex; flex-direction: column; break-inside: avoid; page-break-inside: avoid; }
.imp-vias > .imp-via { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; break-inside: avoid; }
.imp-vias > .imp-via > .imp-via-corpo { flex: 1 1 auto; }
body.imp-paginado[data-orientacao="retrato"] .imp-vias { min-height: calc((var(--imp-altura-pagina) - 7mm - 2.5mm) / var(--imp-escala, 1)); }
.imp-via .imp-cabecalho { margin-bottom: 2mm; padding-bottom: 1.8mm; }
.imp-via .imp-titulo-bloco { display: flex; justify-content: space-between; align-items: baseline; gap: 4mm; flex-wrap: wrap; margin-bottom: 2.5mm; }
.imp-via .imp-titulo { font-size: 12pt; }
.imp-via-rotulo { display: inline-block; padding: 0.4mm 2.4mm; border: 0.35mm solid #1f1f1f; border-radius: 1mm; font-size: 7.5pt; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; white-space: nowrap; }
.imp-corte { display: flex; align-items: center; gap: 2mm; margin: 3mm 0; font-size: 7pt; color: #666; white-space: nowrap; line-height: 1; }
.imp-corte::before, .imp-corte::after { content: ''; flex: 1 1 auto; border-top: 0.3mm dashed #8c8c8c; }
body[data-orientacao="paisagem"] .imp-vias { flex-direction: row; align-items: stretch; }
body[data-orientacao="paisagem"] .imp-vias > .imp-corte { flex: 0 0 auto; width: 0; margin: 0 4mm; border-left: 0.3mm dashed #8c8c8c; font-size: 0; gap: 0; }
body[data-orientacao="paisagem"] .imp-vias > .imp-corte::before, body[data-orientacao="paisagem"] .imp-vias > .imp-corte::after { display: none; }
body.imp-paginado[data-orientacao="paisagem"] .imp-vias { min-height: calc((var(--imp-altura-pagina) - 7mm - 2.5mm) / var(--imp-escala, 1)); }

@media screen {
  body.imp-documento { padding: 0; }
  /* pré-visualização (testes, abrir o HTML): folhas separadas como no papel */
  .imp-pagina + .imp-pagina { margin-top: 6mm; }
  .imp-pagina { outline: 1px dashed #c8c8c8; outline-offset: 2mm; }
}
`;

function linhaDadosEmpresa(i: IdentidadeImpressao): string {
  const partes = [
    i.nif ? `NIF ${i.nif}` : '',
    i.morada ?? '',
    i.telefone ? `Tel. ${i.telefone}` : '',
    i.email ?? '',
    i.website ?? '',
    i.registo_comercial ? `Reg. Comercial ${i.registo_comercial}` : '',
  ].filter((p) => p && p.trim());
  return partes.map(esc).join(' · ');
}

/** Cabeçalho do documento: logótipo + nome da empresa (NIF, morada…), emissão (data/hora e utilizador). */
export function htmlCabecalho(o: Pick<OpcoesDocumento, 'identidade' | 'utilizador' | 'emitidoEm'>): string {
  const i = o.identidade;
  const logo = logotipoSeguro(i?.logotipo);
  const emitido = dayjs(o.emitidoEm ?? new Date()).format('DD/MM/YYYY HH:mm');
  return `<header class="imp-cabecalho">
<div class="imp-empresa">${logo ? `<img class="imp-logotipo" src="${logo}" alt="Logótipo">` : ''}
<div><div class="imp-empresa-nome">${esc(i?.nome ?? '')}</div>${i ? `<div class="imp-empresa-dados">${linhaDadosEmpresa(i)}</div>` : ''}</div></div>
<div class="imp-emissao">Emitido em ${esc(emitido)}${o.utilizador ? `<br>por ${esc(o.utilizador)}` : ''}</div>
</header>`;
}

function htmlTitulo(o: OpcoesDocumento): string {
  const filtros = Array.isArray(o.filtros) ? o.filtros.filter((f): f is string => !!f).join(' · ') : o.filtros;
  return `<div class="imp-titulo-bloco">
<h1 class="imp-titulo">${esc(o.titulo)}</h1>
${o.subtitulo ? `<div class="imp-subtitulo">${esc(o.subtitulo)}</div>` : ''}
${o.periodo ? `<div class="imp-periodo">Período: ${esc(o.periodo)}</div>` : ''}
${filtros ? `<div class="imp-filtros">${esc(filtros)}</div>` : ''}
</div>`;
}

/** Rótulos por omissão das vias (como no legado: «Original» e «Duplicado»). */
export const ROTULOS_VIAS = ['Original', 'Duplicado'] as const;

/** Normaliza a opção `vias` (null = documento normal). */
export function opcoesVias(v: OpcoesDocumento['vias']): { rotulos: string[]; partes?: { conteudo: string; subtitulo?: string | null }[] } | null {
  if (!v) return null;
  if (v === true) return { rotulos: [...ROTULOS_VIAS] };
  if (Array.isArray(v)) return { rotulos: v.length ? v : [...ROTULOS_VIAS] };
  return { rotulos: v.rotulos?.length ? v.rotulos : [...ROTULOS_VIAS], partes: v.partes };
}

/** Título de uma via: título do documento, subtítulo/período e o rótulo da via à direita. */
function htmlTituloVia(o: OpcoesDocumento, rotulo: string, subtitulo: string | null | undefined): string {
  const filtros = Array.isArray(o.filtros) ? o.filtros.filter((f): f is string => !!f).join(' · ') : o.filtros;
  const sub = subtitulo ?? o.subtitulo;
  return `<div class="imp-titulo-bloco imp-via-titulo"><div>
<h1 class="imp-titulo">${esc(o.titulo)}</h1>
${sub ? `<div class="imp-subtitulo">${esc(sub)}</div>` : ''}
${o.periodo ? `<div class="imp-periodo">Período: ${esc(o.periodo)}</div>` : ''}
${filtros ? `<div class="imp-filtros">${esc(filtros)}</div>` : ''}
</div><span class="imp-via-rotulo">${esc(rotulo)}</span></div>`;
}

/**
 * Corpo de um documento em vias: para cada parte (ou o conteúdo único), um bloco `.imp-vias.imp-uma-folha` com as vias
 * (cabeçalho da empresa + título + rótulo + conteúdo) separadas pela linha de corte; partes seguintes em folha nova.
 */
export function htmlVias(o: OpcoesDocumento & { conteudo: string }): string {
  const v = opcoesVias(o.vias);
  if (!v) return o.conteudo;
  const partes = v.partes?.length ? v.partes : [{ conteudo: o.conteudo, subtitulo: null }];
  const cab = o.cabecalho === false ? '' : htmlCabecalho(o);
  return partes
    .map((p, i) => {
      const vias = v.rotulos.map(
        (r) => `<section class="imp-via" data-via="${esc(r)}">${cab}${o.blocoTitulo === false ? '' : htmlTituloVia(o, r, p.subtitulo)}<div class="imp-via-corpo">${p.conteudo}</div></section>`,
      );
      const quebra = i < partes.length - 1 ? ' imp-quebra-pagina' : '';
      return `<div class="imp-vias imp-uma-folha${quebra}">${vias.join('<div class="imp-corte" aria-hidden="true">✂ cortar pelo tracejado</div>')}</div>`;
    })
    .join('');
}

/** Estilo do contentor do conteúdo para o formato (escala com `zoom`, que afecta a paginação, ao contrário de transform). */
export function estiloConteudo(f: FormatoPagina): string {
  return f.escala < 1 ? `zoom: ${f.escala};` : '';
}

/**
 * Documento HTML autónomo, pronto a imprimir (é também o que os testes Playwright abrem).
 * `conteudo` tem de ser HTML (o motor converte elementos com `clonarParaImpressao`); `estilos` é o HTML das folhas
 * de estilo do ecrã (opcional).
 */
export function construirDocumento(o: OpcoesDocumento & { conteudo: string }, formato: FormatoPagina = FORMATO_PADRAO, estilos = ''): string {
  const rodape = o.rodape ?? o.identidade?.rodape ?? null;
  const titulo = o.nomeFicheiro ? limparNomeFicheiro(o.nomeFicheiro) : nomeFicheiroPadrao(o.titulo, o.identidade?.nome, o.emitidoEm);
  // Em vias, o cabeçalho da empresa e o título vão dentro de cada via (cada metade da folha é um documento completo).
  const vias = !!opcoesVias(o.vias);
  return `<!doctype html>
<html lang="pt"><head><meta charset="utf-8"><title>${esc(titulo)}</title>
${estilos}
<style id="imp-base">${CSS_BASE}${o.cssExtra ?? ''}</style>
<style id="imp-pagina">${cssPagina(formato, rodape)}</style>
</head><body class="imp-documento${formato.quebrarTexto ? ' imp-quebrar' : ''}${vias ? ' imp-com-vias' : ''}" data-papel="${formato.papel}" data-orientacao="${formato.orientacao}" data-escala="${formato.escala}" style="--imp-escala: ${formato.escala};">
${o.cabecalho === false || vias ? '' : htmlCabecalho(o)}
${o.blocoTitulo === false || vias ? '' : htmlTitulo(o)}
<main class="imp-conteudo" style="${estiloConteudo(formato)}">${vias ? htmlVias(o) : o.conteudo}</main>
</body></html>`;
}
