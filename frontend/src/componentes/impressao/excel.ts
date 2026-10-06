import dayjs from 'dayjs';
import { clonarParaImpressao } from './dom';
import { limparNomeFicheiro, nomeFicheiroPadrao } from './documento';
import type { OpcoesDocumento } from './tipos';

/**
 * Exportação Excel (.xlsx) comum — lacuna M-01 da análise de paridade (`exportTableToExcel`/`export_setup.js` do legado).
 *
 * Decisão: gerada NO CLIENTE, a partir do MESMO documento que o motor de impressão recebe (o `conteudo` do pedido de
 * impressão: HTML de `tabelaHtml` ou um elemento do ecrã), sem bibliotecas:
 *  - fica automaticamente disponível em todos os ecrãs que já têm «Imprimir/PDF» (BotoesExportar, CabecalhoPagina
 *    `impressao`, TabelaApi `impressao`) sem tocar nos módulos, com os mesmos filtros, colunas visíveis, totais e
 *    cabeçalho da empresa — o servidor não conhece as colunas nem os `render` de cada ecrã;
 *  - a TabelaApi já recolhe todas as páginas do endpoint (até 5 000 linhas) para a impressão; o Excel reaproveita-a;
 *  - um .xlsx é um ZIP de XML: um escritor mínimo (ZIP sem compressão + SpreadsheetML) tem ~300 linhas, enquanto as
 *    bibliotecas (SheetJS ~900 KB, ExcelJS ~1 MB) pesariam no pacote; não precisa de `eval` (CSP `script-src 'self'`).
 *
 * Tipos das células: números como números (formato `#,##0.00` para valores com 2 casas, `#,##0` para inteiros),
 * datas como datas (`dd/mm/yyyy`), percentagens como percentagens. O tipo vem dos atributos `data-xv`/`data-xt` que o
 * `tabelaHtml` escreve (valor bruto) ou, na falta deles, do texto formatado em pt-PT («1 234 567,89», «31/12/2026»,
 * «12,5 %»). Inteiros sem separadores só passam a número em colunas alinhadas à direita (códigos de conta, NIF e
 * números de documento ficam texto) e nunca com zeros à esquerda.
 */

export type TipoCelula = 'texto' | 'numero' | 'data' | 'dataHora' | 'percentagem';
export type PapelCelula = 'normal' | 'cabecalho' | 'total' | 'grupo' | 'titulo' | 'info';

export interface CelulaExcel {
  valor: string | number | null;
  tipo: TipoCelula;
  /** casas decimais observadas (números) */
  casas?: number;
  papel: PapelCelula;
  colunas?: number;
  linhas?: number;
}

export interface FolhaExcel {
  nome: string;
  linhas: (CelulaExcel | null)[][];
  /** linha (0-based) do cabeçalho a fixar e filtrar (só quando há uma única tabela de dados) */
  cabecalho?: { linha: number; colunas: number; ultimaLinha: number };
  larguras?: number[];
}

// ---------------------------------------------------------------------------------------------------------------
// Leitura de valores (texto formatado em pt-PT)
// ---------------------------------------------------------------------------------------------------------------

const ESPACOS = /[\s   ]/g;
const SUFIXOS = /\s*(kz|aoa|akz|usd|eur|us\$|€|\$)\s*$/i;

/** Lê um número formatado em pt-PT («1 234,56», «1.234,56 Kz», «-12,5 %»); `null` se não for um número. */
export function lerNumeroPt(texto: string, permitirInteiroSimples = true): { valor: number; casas: number; percentagem: boolean } | null {
  let t = texto.trim().replace(/−/g, '-');
  if (!t || t.length > 40) return null;
  const percentagem = /%\s*$/.test(t);
  t = t.replace(/\s*%\s*$/, '').replace(SUFIXOS, '').replace(/^(kz|aoa|usd|eur|€|\$)\s*/i, '');
  const sinal = /^[-+]/.test(t) ? (t[0] === '-' ? -1 : 1) : 1;
  t = t.replace(/^[-+]\s*/, '');
  const semEspacos = t.replace(ESPACOS, '');
  let inteiro: string;
  let decimal = '';
  if (/^\d{1,3}([    ]\d{3})+(,\d+)?$/.test(t) || /^\d{1,3}(\.\d{3})+(,\d+)?$/.test(semEspacos)) {
    [inteiro, decimal = ''] = semEspacos.replace(/\./g, '').split(',');
  } else if (/^\d+,\d+$/.test(semEspacos)) {
    [inteiro, decimal] = semEspacos.split(',');
  } else if (/^\d+$/.test(semEspacos) && semEspacos === t) {
    if (!permitirInteiroSimples && !percentagem) return null;
    if (semEspacos.length > 1 && semEspacos.startsWith('0')) return null;
    if (semEspacos.length > 15) return null;
    inteiro = semEspacos;
  } else return null;
  const valor = sinal * Number(`${inteiro}${decimal ? `.${decimal}` : ''}`);
  return Number.isFinite(valor) ? { valor, casas: decimal.length, percentagem } : null;
}

/** Data dd/mm/aaaa (opcionalmente com hh:mm) → número de série do Excel (dias desde 30/12/1899). */
export function lerDataPt(texto: string): { serie: number; comHora: boolean } | null {
  const m = /^(\d{2})\/(\d{2})\/(\d{4})(?:[ ,]+(\d{2}):(\d{2})(?::(\d{2}))?)?$/.exec(texto.trim());
  if (!m) return null;
  const [d, mes, a] = [Number(m[1]), Number(m[2]), Number(m[3])];
  if (mes < 1 || mes > 12 || d < 1 || d > 31) return null;
  const hora = m[4] ? Number(m[4]) * 3600 + Number(m[5]) * 60 + Number(m[6] ?? 0) : 0;
  return { serie: Date.UTC(a, mes - 1, d) / 86_400_000 + 25_569 + hora / 86_400, comHora: !!m[4] };
}

/** Data ISO (AAAA-MM-DD[THH:MM]) → série do Excel. */
function lerDataIso(texto: string): { serie: number; comHora: boolean } | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(texto.trim());
  if (!m) return null;
  const hora = m[4] ? Number(m[4]) * 3600 + Number(m[5]) * 60 : 0;
  return { serie: Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) / 86_400_000 + 25_569 + hora / 86_400, comHora: !!m[4] };
}

/** Converte o texto (e o valor bruto opcional) de uma célula de dados no valor Excel. */
export function interpretarCelula(texto: string, opcoes: { bruto?: string | null; tipoBruto?: string | null; alinhadaDireita?: boolean } = {}): Omit<CelulaExcel, 'papel'> {
  const t = texto.replace(/\s+/g, ' ').trim();
  if (opcoes.bruto !== undefined && opcoes.bruto !== null && opcoes.bruto !== '') {
    if (opcoes.tipoBruto === 'n' && /^-?\d+(\.\d+)?(e[-+]?\d+)?$/i.test(opcoes.bruto)) {
      const casas = (opcoes.bruto.split('.')[1] ?? '').length;
      const pct = /%\s*$/.test(t);
      return { valor: pct ? Number(opcoes.bruto) / 100 : Number(opcoes.bruto), tipo: pct ? 'percentagem' : 'numero', casas };
    }
    if (opcoes.tipoBruto === 'd') {
      const d = lerDataIso(opcoes.bruto);
      if (d) return { valor: d.serie, tipo: d.comHora ? 'dataHora' : 'data' };
    }
  }
  if (!t || t === '—' || t === '-') return { valor: t === '—' ? null : t || null, tipo: 'texto' };
  const data = lerDataPt(t);
  if (data) return { valor: data.serie, tipo: data.comHora ? 'dataHora' : 'data' };
  const n = lerNumeroPt(t, !!opcoes.alinhadaDireita);
  if (n) return n.percentagem ? { valor: n.valor / 100, tipo: 'percentagem', casas: n.casas } : { valor: n.valor, tipo: 'numero', casas: n.casas };
  return { valor: t, tipo: 'texto' };
}

// ---------------------------------------------------------------------------------------------------------------
// Extracção das tabelas do documento
// ---------------------------------------------------------------------------------------------------------------

function alinhadaDireita(c: Element): boolean {
  const estilo = (c.getAttribute('style') ?? '').replace(/\s/g, '').toLowerCase();
  return c.classList.contains('imp-num') || c.classList.contains('num') || c.classList.contains('ant-table-cell-align-right') || estilo.includes('text-align:right');
}

function textoDe(no: Element): string {
  // quebras de linha visuais (<br>, blocos) passam a espaço
  const clone = no.cloneNode(true) as Element;
  clone.querySelectorAll('br').forEach((b) => b.replaceWith(' '));
  clone.querySelectorAll('.no-print, .imp-nao-imprimir, .imp-so-ecra, button, .ant-btn').forEach((b) => b.remove());
  return (clone.textContent ?? '').replace(/\s+/g, ' ').trim();
}

/** Linhas de uma tabela HTML (com colspan/rowspan) em células Excel. */
export function lerTabela(tabela: HTMLTableElement): { linhas: (CelulaExcel | null)[][]; cabecalhos: number; colunas: number } {
  const grelha: (CelulaExcel | null)[][] = [];
  const ocupado: boolean[][] = [];
  let cabecalhos = 0;
  const trs = Array.from(tabela.rows).filter((tr) => tr.closest('table') === tabela);
  trs.forEach((tr, r) => {
    grelha[r] ??= [];
    ocupado[r] ??= [];
    const seccao = tr.parentElement?.tagName.toLowerCase();
    const eCabecalho = seccao === 'thead';
    if (eCabecalho) cabecalhos = r + 1;
    const total = seccao === 'tfoot' || tr.classList.contains('imp-total') || tr.classList.contains('imp-subtotal') || tr.classList.contains('ant-table-summary');
    const grupo = tr.classList.contains('imp-grupo');
    let c = 0;
    Array.from(tr.cells).forEach((celula) => {
      while (ocupado[r][c]) c++;
      const colunas = Math.max(1, celula.colSpan || 1);
      const linhas = Math.max(1, celula.rowSpan || 1);
      const texto = textoDe(celula);
      const papel: PapelCelula = eCabecalho || (celula.tagName === 'TH' && !total) ? 'cabecalho' : total ? 'total' : grupo ? 'grupo' : 'normal';
      const valor =
        papel === 'cabecalho' || papel === 'grupo' || celula.classList.contains('imp-vazio')
          ? { valor: texto || null, tipo: 'texto' as const }
          : interpretarCelula(texto, { bruto: celula.getAttribute('data-xv'), tipoBruto: celula.getAttribute('data-xt'), alinhadaDireita: alinhadaDireita(celula) });
      grelha[r][c] = { ...valor, papel, colunas: colunas > 1 ? colunas : undefined, linhas: linhas > 1 ? linhas : undefined };
      for (let dr = 0; dr < linhas; dr++) {
        ocupado[r + dr] ??= [];
        grelha[r + dr] ??= [];
        for (let dc = 0; dc < colunas; dc++) {
          ocupado[r + dr][c + dc] = true;
          if (dr || dc) grelha[r + dr][c + dc] = null;
        }
      }
      c += colunas;
    });
  });
  const colunas = grelha.reduce((m, l) => Math.max(m, l.length), 0);
  return { linhas: grelha.map((l) => Array.from({ length: colunas }, (_, i) => l[i] ?? null)), cabecalhos, colunas };
}

/** Documento a partir do `conteudo` do pedido de impressão (HTML ou elemento do ecrã, limpo como na impressão). */
function documentoDe(conteudo: string | Element): Document {
  const html = typeof conteudo === 'string' ? conteudo : clonarParaImpressao(conteudo);
  return new DOMParser().parseFromString(`<!doctype html><html><body>${html}</body></html>`, 'text/html');
}

/** Tabelas de topo do documento (as tabelas dentro de células ficam no texto da célula). */
export function tabelasDoConteudo(conteudo: string | Element): { legenda: string | null; tabela: HTMLTableElement }[] {
  const doc = documentoDe(conteudo);
  return Array.from(doc.querySelectorAll('table'))
    .filter((t) => !t.parentElement?.closest('table'))
    .map((t) => ({ legenda: t.caption ? textoDe(t.caption) : null, tabela: t as HTMLTableElement }));
}

/** Monta a folha: cabeçalho do documento (título, empresa, período, filtros, emissão) e as tabelas empilhadas. */
export function montarFolha(o: Pick<OpcoesDocumento, 'titulo' | 'subtitulo' | 'periodo' | 'filtros' | 'identidade' | 'utilizador' | 'emitidoEm' | 'conteudo'>): FolhaExcel {
  const linhas: (CelulaExcel | null)[][] = [];
  const info = (texto: string, papel: PapelCelula = 'info') => linhas.push([{ valor: texto, tipo: 'texto', papel }]);
  info(o.titulo, 'titulo');
  if (o.identidade?.nome) info([o.identidade.nome, o.identidade.nif ? `NIF ${o.identidade.nif}` : null].filter(Boolean).join(' · '));
  if (o.subtitulo) info(o.subtitulo);
  if (o.periodo) info(`Período: ${o.periodo}`);
  const filtros = (Array.isArray(o.filtros) ? o.filtros : [o.filtros]).filter((f): f is string => !!f);
  if (filtros.length) info(filtros.join(' · '));
  info(`Emitido em ${dayjs(o.emitidoEm ?? new Date()).format('DD/MM/YYYY HH:mm')}${o.utilizador ? ` por ${o.utilizador}` : ''}`);
  linhas.push([]);

  const tabelas = tabelasDoConteudo(o.conteudo);
  let cabecalho: FolhaExcel['cabecalho'];
  const dados = tabelas.filter(({ tabela }) => !tabela.classList.contains('imp-pares'));
  tabelas.forEach(({ legenda, tabela }, i) => {
    if (legenda) info(legenda, 'grupo');
    const t = lerTabela(tabela);
    const inicio = linhas.length;
    linhas.push(...t.linhas);
    if (dados.length === 1 && dados[0].tabela === tabela && t.cabecalhos === 1 && t.linhas.length > 1) {
      cabecalho = { linha: inicio, colunas: t.colunas, ultimaLinha: inicio + t.linhas.length - 1 };
    }
    if (i < tabelas.length - 1) linhas.push([]);
  });
  if (!tabelas.length) {
    // documento sem tabelas (ex.: uma carta): o texto, parágrafo a parágrafo
    const doc = documentoDe(o.conteudo);
    Array.from(doc.body.querySelectorAll('h1, h2, h3, h4, p, li, dt, dd'))
      .map((e) => textoDe(e))
      .filter(Boolean)
      .forEach((t) => info(t, 'normal'));
  }
  const larguras: number[] = [];
  linhas.forEach((l) =>
    l.forEach((c, i) => {
      if (!c || c.papel === 'titulo' || c.papel === 'info' || (c.colunas ?? 1) > 1) return;
      const tamanho = c.tipo === 'texto' ? String(c.valor ?? '').length : c.tipo === 'data' ? 10 : c.tipo === 'dataHora' ? 16 : String(c.valor ?? '').length + 4;
      larguras[i] = Math.min(60, Math.max(larguras[i] ?? 8, tamanho + 2));
    }),
  );
  return { nome: o.titulo, linhas, cabecalho, larguras };
}

// ---------------------------------------------------------------------------------------------------------------
// Escrita do .xlsx (SpreadsheetML + ZIP sem compressão)
// ---------------------------------------------------------------------------------------------------------------

const xml = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, '');

/** Letra(s) da coluna (0 → A, 26 → AA). */
export function letraColuna(i: number): string {
  let s = '';
  for (let n = i + 1; n > 0; n = Math.floor((n - 1) / 26)) s = String.fromCharCode(65 + ((n - 1) % 26)) + s;
  return s;
}

/** Nome de folha válido (≤ 31 caracteres, sem []:*?/\). */
export function nomeFolha(nome: string): string {
  return nome.replace(/[[\]:*?/\\]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 31).trim() || 'Dados';
}

// Estilos (índices de cellXfs em ESTILOS)
const E = { normal: 0, cabecalho: 1, num2: 2, data: 3, pct: 4, inteiro: 5, texto: 6, titulo: 7, totalNum2: 8, totalTexto: 9, grupo: 10, numGeral: 11, dataHora: 12, totalInteiro: 13, totalNumGeral: 14, info: 15 };

const ESTILOS = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="4"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/><numFmt numFmtId="166" formatCode="0.00%"/><numFmt numFmtId="167" formatCode="dd/mm/yyyy hh:mm"/></numFmts>
<fonts count="4"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="14"/><name val="Arial"/></font><font><sz val="9"/><color rgb="FF555555"/><name val="Arial"/></font></fonts>
<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill></fills>
<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="16">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="164" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="49" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="49" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
<xf numFmtId="167" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>
<xf numFmtId="3" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>`;

function estilo(c: CelulaExcel): number {
  const total = c.papel === 'total';
  switch (c.papel) {
    case 'titulo':
      return E.titulo;
    case 'info':
      return E.info;
    case 'cabecalho':
      return E.cabecalho;
    case 'grupo':
      return E.grupo;
    default:
      break;
  }
  switch (c.tipo) {
    case 'numero':
      if (c.casas === 2) return total ? E.totalNum2 : E.num2;
      if (!c.casas) return total ? E.totalInteiro : E.inteiro;
      return total ? E.totalNumGeral : E.numGeral;
    case 'percentagem':
      return E.pct;
    case 'data':
      return E.data;
    case 'dataHora':
      return E.dataHora;
    default:
      return total ? E.totalTexto : E.texto;
  }
}

/** XML da folha. */
export function xmlFolha(f: FolhaExcel): string {
  const fusoes: string[] = [];
  const linhasXml = f.linhas
    .map((l, r) => {
      const celulas = l
        .map((c, i) => {
          if (!c) return '';
          const ref = `${letraColuna(i)}${r + 1}`;
          if ((c.colunas ?? 1) > 1 || (c.linhas ?? 1) > 1) fusoes.push(`${ref}:${letraColuna(i + (c.colunas ?? 1) - 1)}${r + (c.linhas ?? 1)}`);
          const s = estilo(c);
          if (c.valor === null || c.valor === '') return `<c r="${ref}" s="${s}"/>`;
          if (typeof c.valor === 'number' && c.tipo !== 'texto') return `<c r="${ref}" s="${s}"><v>${c.valor}</v></c>`;
          return `<c r="${ref}" s="${s}" t="inlineStr"><is><t xml:space="preserve">${xml(String(c.valor))}</t></is></c>`;
        })
        .join('');
      return `<row r="${r + 1}">${celulas}</row>`;
    })
    .join('');
  const cab = f.cabecalho;
  const vista = cab
    ? `<sheetViews><sheetView workbookViewId="0"><pane ySplit="${cab.linha + 1}" topLeftCell="A${cab.linha + 2}" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/></sheetView></sheetViews>`
    : '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
  const colunas = f.larguras?.length ? `<cols>${f.larguras.map((w, i) => `<col min="${i + 1}" max="${i + 1}" width="${w ?? 10}" customWidth="1"/>`).join('')}</cols>` : '';
  const filtro = cab ? `<autoFilter ref="A${cab.linha + 1}:${letraColuna(cab.colunas - 1)}${cab.ultimaLinha + 1}"/>` : '';
  const fusoesXml = fusoes.length ? `<mergeCells count="${fusoes.length}">${fusoes.map((m) => `<mergeCell ref="${m}"/>`).join('')}</mergeCells>` : '';
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">${vista}<sheetFormatPr defaultRowHeight="13"/>${colunas}<sheetData>${linhasXml}</sheetData>${filtro}${fusoesXml}<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>`;
}

/** Ficheiros do pacote .xlsx. */
export function ficheirosXlsx(folhas: FolhaExcel[]): Record<string, string> {
  const nomes = folhas.map((f, i) => {
    const base = nomeFolha(f.nome);
    return folhas.slice(0, i).some((g) => nomeFolha(g.nome) === base) ? `${base.slice(0, 28)} ${i + 1}` : base;
  });
  const ficheiros: Record<string, string> = {
    '[Content_Types].xml': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>${folhas.map((_, i) => `<Override PartName="/xl/worksheets/sheet${i + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('')}</Types>`,
    '_rels/.rels': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>`,
    'xl/workbook.xml': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>${nomes.map((n, i) => `<sheet name="${xml(n)}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join('')}</sheets>${definicoesFiltro(folhas, nomes)}</workbook>`,
    'xl/_rels/workbook.xml.rels': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${folhas.map((_, i) => `<Relationship Id="rId${i + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${i + 1}.xml"/>`).join('')}<Relationship Id="rId${folhas.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>`,
    'xl/styles.xml': ESTILOS,
  };
  folhas.forEach((f, i) => (ficheiros[`xl/worksheets/sheet${i + 1}.xml`] = xmlFolha(f)));
  return ficheiros;
}

function definicoesFiltro(folhas: FolhaExcel[], nomes: string[]): string {
  const defs = folhas
    .map((f, i) => {
      const c = f.cabecalho;
      if (!c) return '';
      const nome = nomes[i].replace(/'/g, "''");
      return `<definedName name="_xlnm._FilterDatabase" localSheetId="${i}" hidden="1">'${xml(nome)}'!$A$${c.linha + 1}:$${letraColuna(c.colunas - 1)}$${c.ultimaLinha + 1}</definedName>`;
    })
    .join('');
  return defs ? `<definedNames>${defs}</definedNames>` : '';
}

// ZIP (método 0 — sem compressão), com CRC-32

const TABELA_CRC = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c >>> 0;
  }
  return t;
})();

export function crc32(dados: Uint8Array): number {
  let c = 0xffffffff;
  for (let i = 0; i < dados.length; i++) c = TABELA_CRC[(c ^ dados[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

export function zipSemCompressao(ficheiros: Record<string, string | Uint8Array>, data: Date = new Date()): Uint8Array {
  const cod = new TextEncoder();
  const horaDos = ((data.getHours() & 0x1f) << 11) | ((data.getMinutes() & 0x3f) << 5) | (Math.floor(data.getSeconds() / 2) & 0x1f);
  const dataDos = (((data.getFullYear() - 1980) & 0x7f) << 9) | (((data.getMonth() + 1) & 0x0f) << 5) | (data.getDate() & 0x1f);
  const locais: Uint8Array[] = [];
  const centrais: Uint8Array[] = [];
  let deslocamento = 0;
  for (const [nome, conteudo] of Object.entries(ficheiros)) {
    const nomeB = cod.encode(nome);
    const dados = typeof conteudo === 'string' ? cod.encode(conteudo) : conteudo;
    const crc = crc32(dados);
    const local = new Uint8Array(30 + nomeB.length);
    const v = new DataView(local.buffer);
    v.setUint32(0, 0x04034b50, true);
    v.setUint16(4, 20, true);
    v.setUint16(6, 0x0800, true); // nomes em UTF-8
    v.setUint16(8, 0, true);
    v.setUint16(10, horaDos, true);
    v.setUint16(12, dataDos, true);
    v.setUint32(14, crc, true);
    v.setUint32(18, dados.length, true);
    v.setUint32(22, dados.length, true);
    v.setUint16(26, nomeB.length, true);
    local.set(nomeB, 30);
    const central = new Uint8Array(46 + nomeB.length);
    const w = new DataView(central.buffer);
    w.setUint32(0, 0x02014b50, true);
    w.setUint16(4, 20, true);
    w.setUint16(6, 20, true);
    w.setUint16(8, 0x0800, true);
    w.setUint16(10, 0, true);
    w.setUint16(12, horaDos, true);
    w.setUint16(14, dataDos, true);
    w.setUint32(16, crc, true);
    w.setUint32(20, dados.length, true);
    w.setUint32(24, dados.length, true);
    w.setUint16(28, nomeB.length, true);
    w.setUint32(42, deslocamento, true);
    central.set(nomeB, 46);
    locais.push(local, dados);
    centrais.push(central);
    deslocamento += local.length + dados.length;
  }
  const tamanhoCentral = centrais.reduce((s, c) => s + c.length, 0);
  const fim = new Uint8Array(22);
  const f = new DataView(fim.buffer);
  f.setUint32(0, 0x06054b50, true);
  f.setUint16(8, centrais.length, true);
  f.setUint16(10, centrais.length, true);
  f.setUint32(12, tamanhoCentral, true);
  f.setUint32(16, deslocamento, true);
  const total = new Uint8Array(deslocamento + tamanhoCentral + 22);
  let p = 0;
  for (const parte of [...locais, ...centrais, fim]) {
    total.set(parte, p);
    p += parte.length;
  }
  return total;
}

export const TIPO_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/** Gera o .xlsx (bytes) do documento de impressão. */
export function gerarXlsx(o: Parameters<typeof montarFolha>[0]): { bytes: Uint8Array; linhas: number } {
  const folha = montarFolha(o);
  return { bytes: zipSemCompressao(ficheirosXlsx([folha])), linhas: folha.linhas.length };
}

/** Gera e descarrega o .xlsx; devolve o nome do ficheiro. */
export function descarregarExcel(o: Parameters<typeof montarFolha>[0] & { nomeFicheiro?: string | null }): string {
  const { bytes } = gerarXlsx(o);
  const nome = `${limparNomeFicheiro(o.nomeFicheiro || nomeFicheiroPadrao(o.titulo, o.identidade?.nome))}.xlsx`;
  const url = URL.createObjectURL(new Blob([bytes as BlobPart], { type: TIPO_XLSX }));
  const a = document.createElement('a');
  a.href = url;
  a.download = nome;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  return nome;
}
