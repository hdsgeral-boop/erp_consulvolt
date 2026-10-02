import { construirDocumento, estiloConteudo, limparNomeFicheiro, nomeFicheiroPadrao } from './documento';
import { clonarParaImpressao, contarColunas, estilosDaPagina } from './dom';
import { cssPagina, decidirFormato, FORMATO_PADRAO, mmParaPx } from './formato';
import { paginarDocumento } from './paginacao';
import type { DocumentoPreparado, FormatoPagina, OpcoesDocumento } from './tipos';

/**
 * Motor de impressão/PDF: monta o documento numa iframe oculta (about:blank, mesma origem e mesma CSP — sem
 * scripts no documento), mede a largura natural do conteúdo, decide papel/orientação/escala, e chama
 * `iframe.contentWindow.print()` a partir da janela principal. «Guardar como PDF» no diálogo dá o PDF
 * (vectorial, texto seleccionável); o título do documento é o nome de ficheiro sugerido.
 *
 * Depois de decidido o formato, o documento é partido em folhas pelo próprio motor (paginacao.ts): cada folha leva o
 * rodapé da empresa e «Página X de Y», o que funciona no Chromium/Edge e no Firefox (que não suporta as caixas de
 * margem do @page). Se a paginação falhar, o documento é reescrito sem ela e o @page volta às caixas de margem
 * (recurso: numeração só no Chromium/Edge).
 */

const ESPERA_MAXIMA_RECURSOS_MS = 4000;

function criarIframe(): HTMLIFrameElement {
  const iframe = document.createElement('iframe');
  iframe.setAttribute('aria-hidden', 'true');
  iframe.setAttribute('tabindex', '-1');
  iframe.title = 'Documento para impressão';
  // Fora do ecrã mas com dimensões reais: a medição precisa de layout (largura ajustada em cada medição).
  Object.assign(iframe.style, { position: 'fixed', left: '-20000px', top: '0', width: '800px', height: '600px', border: '0', opacity: '0', pointerEvents: 'none' });
  document.body.appendChild(iframe);
  return iframe;
}

function escrever(iframe: HTMLIFrameElement, html: string): Document {
  const doc = iframe.contentDocument;
  if (!doc) throw new Error('Não foi possível preparar o documento de impressão.');
  doc.open();
  doc.write(html);
  doc.close();
  return doc;
}

/** Espera pelas imagens (logótipo), folhas de estilo e tipos de letra — com limite, para nunca bloquear. */
async function aguardarRecursos(doc: Document): Promise<void> {
  const esperas: Promise<unknown>[] = [];
  Array.from(doc.images).forEach((img) => {
    if (!img.complete) esperas.push(new Promise((r) => { img.addEventListener('load', r, { once: true }); img.addEventListener('error', r, { once: true }); }));
    else if (typeof img.decode === 'function') esperas.push(img.decode().catch(() => undefined));
  });
  doc.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]').forEach((l) => {
    if (!l.sheet) esperas.push(new Promise((r) => { l.addEventListener('load', r, { once: true }); l.addEventListener('error', r, { once: true }); }));
  });
  const fontes = (doc as Document & { fonts?: { ready: Promise<unknown> } }).fonts;
  if (fontes?.ready) esperas.push(fontes.ready);
  await Promise.race([Promise.all(esperas), new Promise((r) => window.setTimeout(r, ESPERA_MAXIMA_RECURSOS_MS))]);
}

/** Largura natural (px) do conteúdo posto numa coluna de `larguraPx` (a iframe toma essa largura: as media queries batem certo). */
export function medirConteudo(iframe: HTMLIFrameElement, larguraPx: number, quebrar: boolean): number {
  const doc = iframe.contentDocument!;
  iframe.style.width = `${larguraPx}px`;
  doc.body.classList.toggle('imp-quebrar', quebrar);
  const c = doc.querySelector<HTMLElement>('.imp-conteudo')!;
  c.style.zoom = '';
  c.style.width = `${larguraPx}px`;
  const esquerda = c.getBoundingClientRect().left;
  let largura = c.scrollWidth;
  c.querySelectorAll<HTMLElement>('table, pre, svg, img, canvas, .organigrama, [style*="max-content"]').forEach((e) => {
    const r = e.getBoundingClientRect();
    largura = Math.max(largura, Math.ceil(r.right - esquerda), e.scrollWidth || 0);
  });
  c.style.width = '';
  return Math.ceil(largura);
}

/** Aplica o formato ao documento já escrito na iframe (sem o reescrever: o logótipo não é descodificado outra vez). */
function aplicarFormato(doc: Document, f: FormatoPagina, rodape: string | null): void {
  const estilo = doc.getElementById('imp-pagina');
  if (estilo) estilo.textContent = cssPagina(f, rodape);
  doc.body.classList.toggle('imp-quebrar', f.quebrarTexto);
  doc.body.dataset.papel = f.papel;
  doc.body.dataset.orientacao = f.orientacao;
  doc.body.dataset.escala = String(f.escala);
  const c = doc.querySelector<HTMLElement>('.imp-conteudo');
  if (c) c.setAttribute('style', estiloConteudo(f));
}

interface Preparacao extends DocumentoPreparado {
  iframe: HTMLIFrameElement;
}

/**
 * As folhas de estilo copiadas do ecrã são medidas no ecrã mas usadas no papel: as regras `@media print` passam a
 * valer sempre e as `@media screen` deixam de valer, para a medição (e a paginação) bater certo com a impressão.
 */
export function estilosParaImpressao(html: string): string {
  return html
    .replace(/@media\s+print\b/gi, '@media all')
    .replace(/@media\s+screen\b/gi, '@media not all')
    .replace(/(<link[^>]*\smedia=")print(")/gi, '$1all$2')
    .replace(/(<link[^>]*\smedia=")screen(")/gi, '$1not all$2');
}

/** Serializa o documento da iframe (já paginado). */
function serializar(doc: Document): string {
  return `<!doctype html>\n${doc.documentElement.outerHTML}`;
}

async function preparar(o: OpcoesDocumento): Promise<Preparacao> {
  const comEstilos = o.estilosDaPagina ?? typeof o.conteudo !== 'string';
  const conteudo = typeof o.conteudo === 'string' ? o.conteudo : clonarParaImpressao(o.conteudo);
  const emitidoEm = o.emitidoEm ?? new Date();
  const nomeFicheiro = limparNomeFicheiro(o.nomeFicheiro || nomeFicheiroPadrao(o.titulo, o.identidade?.nome, emitidoEm));
  const opcoes = { ...o, conteudo, emitidoEm, nomeFicheiro };
  const estilos = comEstilos ? estilosParaImpressao(estilosDaPagina()) : '';
  const rodape = o.rodape ?? o.identidade?.rodape ?? null;

  const iframe = criarIframe();
  try {
    const doc = escrever(iframe, construirDocumento(opcoes, FORMATO_PADRAO, estilos));
    await aguardarRecursos(doc);
    const raiz = doc.querySelector('.imp-conteudo') ?? doc.body;
    let formato: FormatoPagina;
    try {
      formato = decidirFormato({ medir: (px, q) => medirConteudo(iframe, px, q), colunas: contarColunas(raiz), papel: o.papel, orientacao: o.orientacao });
    } catch {
      formato = FORMATO_PADRAO;
    }
    aplicarFormato(doc, formato, rodape);
    if (o.paginar !== false) {
      // a iframe toma a largura útil da página (as media queries batem certo com a impressão)
      iframe.style.width = `${mmParaPx(formato.larguraUtilMm)}px`;
      try {
        const { paginas } = paginarDocumento(doc, formato, rodape);
        const estilo = doc.getElementById('imp-pagina');
        if (estilo) estilo.textContent = cssPagina(formato, rodape, true);
        return { iframe, formato, nomeFicheiro, paginas, html: serializar(doc) };
      } catch (e) {
        // recurso: documento sem paginação (numeração pelas caixas de margem do @page, só Chromium/Edge)
        console.warn('Paginação do documento de impressão falhou; a usar o @page do navegador.', e);
        const novo = escrever(iframe, construirDocumento(opcoes, formato, estilos));
        await aguardarRecursos(novo);
      }
    }
    iframe.style.width = '800px';
    return { iframe, formato, nomeFicheiro, paginas: 0, html: construirDocumento(opcoes, formato, estilos) };
  } catch (e) {
    iframe.remove();
    throw e;
  }
}

/**
 * Prepara o documento (mede e decide o formato) e devolve o HTML final, sem imprimir.
 * Serve para testes (Playwright: abrir o HTML, `emulateMedia('print')`, `pdf({ preferCSSPageSize: true })`).
 */
export async function prepararDocumento(o: OpcoesDocumento): Promise<DocumentoPreparado> {
  const p = await preparar(o);
  p.iframe.remove();
  return { html: p.html, formato: p.formato, nomeFicheiro: p.nomeFicheiro, paginas: p.paginas };
}

/** Imprime (ou guarda em PDF, pelo diálogo do navegador) o documento. Devolve o formato escolhido. */
export async function imprimirDocumento(o: OpcoesDocumento): Promise<FormatoPagina> {
  const p = await preparar(o);
  const janela = p.iframe.contentWindow;
  if (!janela) {
    p.iframe.remove();
    throw new Error('Não foi possível abrir o documento de impressão.');
  }
  // O Chromium sugere o nome do PDF a partir do título; durante o diálogo, também o da janela principal.
  const tituloOriginal = document.title;
  let limpo = false;
  const limpar = () => {
    if (limpo) return;
    limpo = true;
    document.title = tituloOriginal;
    window.setTimeout(() => p.iframe.remove(), 300);
  };
  janela.addEventListener('afterprint', limpar, { once: true });
  document.title = p.nomeFicheiro;
  try {
    janela.focus();
    janela.print(); // no Chromium é bloqueante até o diálogo fechar
  } finally {
    // Rede de segurança (navegadores com print() não bloqueante e sem afterprint).
    window.setTimeout(limpar, 10 * 60_000);
    window.setTimeout(() => { if (document.title === p.nomeFicheiro && !limpo) document.title = tituloOriginal; }, 5_000);
  }
  return p.formato;
}
