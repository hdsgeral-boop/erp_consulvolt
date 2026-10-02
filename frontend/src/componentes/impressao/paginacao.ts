import { esc } from './documento';
import { ALTURA_RODAPE_MM, alturaPaginaMm, FOLGA_PAGINA_MM, PX_POR_MM } from './formato';
import type { FormatoPagina } from './tipos';

/**
 * Paginação feita pelo próprio motor (funciona em Chromium/Edge E Firefox).
 *
 * O Firefox não suporta as caixas de margem do `@page` (`@bottom-right { content: counter(page) … }`), por isso o
 * «Página X de Y» e o rodapé da empresa não podem vir do navegador. Depois de decidido o formato (papel, orientação e
 * escala), o documento já escrito na iframe é partido em folhas `<section class="imp-pagina">` com a altura útil da
 * página, cada uma com o seu rodapé («rodapé da empresa · Página X de Y») e `break-after: page`:
 *
 *  - os nós do conteúdo são movidos, um a um, para a folha corrente; se o nó não cabe e é divisível (contentor com
 *    blocos — Card, div, tabela, tbody…) é aberto e os filhos são colocados um a um, recriando a cadeia de
 *    contentores («cascas», cópias sem filhos) na folha seguinte;
 *  - nós indivisíveis (linhas de tabela, imagens, SVG, parágrafos, títulos, `break-inside: avoid`, linhas flex sem
 *    quebra — ex.: as linhas do Gantt) passam inteiros para a folha seguinte;
 *  - o `<thead>`/`<colgroup>` das tabelas e os elementos marcados com `.imp-repetir` (ex.: cabeçalho de meses do Gantt)
 *    repetem-se no topo da casca em cada folha; o `<tfoot>` (totais) fica só no fim;
 *  - as larguras das colunas de cada tabela são fixadas (percentagens medidas antes de partir), para que todas as
 *    folhas tenham as mesmas colunas;
 *  - títulos, legendas e linhas de grupo (`tr.imp-grupo`) não ficam sozinhos no fim de uma folha;
 *  - quebras pedidas (`break-before/after: page`, `.imp-quebra-pagina`) são respeitadas;
 *  - um bloco indivisível mais alto do que uma folha inteira é reduzido (`zoom`) para caber.
 *
 * O cabeçalho da empresa (logótipo, nome, emissão) e o título ficam só na primeira folha, como no sistema anterior.
 * Tudo é feito com DOM e CSS no documento da iframe — sem scripts no documento impresso (CSP respeitada).
 */

export interface ResultadoPaginacao {
  paginas: number;
}

interface Folha {
  seccao: HTMLElement;
  corpo: HTMLElement;
  conteudo: HTMLElement;
  /** nós de conteúdo colocados nesta folha (as cascas e os cabeçalhos repetidos não contam) */
  folhas: number;
  limite: number;
}

interface Nivel {
  original: HTMLElement;
  casca: HTMLElement;
  /** filhos já colocados que se repetem no topo da casca em cada folha nova (thead, colgroup, .imp-repetir) */
  repetir: HTMLElement[];
}

const TAG_INDIVISIVEL = new Set(['TR', 'THEAD', 'TFOOT', 'CAPTION', 'COLGROUP', 'COL', 'IMG', 'SVG', 'CANVAS', 'VIDEO', 'IFRAME', 'PICTURE', 'FIGURE', 'P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'PRE', 'BLOCKQUOTE', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'LABEL', 'LI', 'DT', 'DD', 'HR', 'BR']);
const SELECTOR_MANTER_COM_SEGUINTE = 'h1, h2, h3, h4, h5, h6, caption, .ant-card-head, tr.imp-grupo, .imp-manter-com-seguinte, .imp-titulo-bloco';
const ATRIBUTO_CASCA = 'data-imp-casca';
const ATRIBUTO_REPETIDO = 'data-imp-repetido';
const ZOOM_MINIMO = 0.25;

const ehElemento = (n: Node | null): n is HTMLElement => !!n && n.nodeType === 1;
const ehTextoVazio = (n: Node) => n.nodeType === 3 && !(n.textContent ?? '').trim();

function estilo(el: Element): CSSStyleDeclaration | null {
  const vista = el.ownerDocument.defaultView;
  try {
    return vista ? vista.getComputedStyle(el) : null;
  } catch {
    return null;
  }
}

const ehQuebraPagina = (v: string | undefined) => !!v && /^(page|always|left|right|recto|verso)$/.test(v);

/** Filhos que se repetem em cada folha (o cabeçalho das tabelas e o que estiver marcado com `.imp-repetir`). */
function ehRepetivel(el: HTMLElement): boolean {
  return el.tagName === 'THEAD' || el.tagName === 'COLGROUP' || el.classList.contains('imp-repetir');
}

/** O elemento é atómico pela sua natureza (não se pode abrir, mesmo que não caiba numa folha inteira). */
function indivisivelPorNatureza(el: HTMLElement): boolean {
  if (TAG_INDIVISIVEL.has(el.tagName.toUpperCase())) return true;
  if (el.namespaceURI === 'http://www.w3.org/2000/svg') return true;
  if (!el.firstElementChild) return true;
  // texto solto ao lado de elementos (parágrafo com <strong>, <span>…) → bloco de texto
  return Array.from(el.childNodes).some((n) => n.nodeType === 3 && !!(n.textContent ?? '').trim());
}

/**
 * Pode abrir-se para partir os filhos por folhas? (chamado com o elemento ligado ao documento — o estilo calculado
 * só existe para elementos no documento). `forcar` ignora as preferências de «não partir» (bloco maior do que a folha).
 */
function divisivel(el: HTMLElement, forcar: boolean): boolean {
  if (indivisivelPorNatureza(el)) return false;
  if (!forcar && el.classList.contains('imp-sem-quebra')) return false;
  const cs = estilo(el);
  if (!cs) return true;
  const filhos = Array.from(el.children) as HTMLElement[];
  // só filhos em linha (texto formatado) → bloco de texto
  if (filhos.every((f) => (estilo(f)?.display ?? 'block').startsWith('inline'))) return false;
  if (forcar) return true;
  if (cs.breakInside === 'avoid' || cs.breakInside === 'avoid-page' || cs.pageBreakInside === 'avoid') return false;
  // linha flex sem quebra (colunas lado a lado: nome + barra do Gantt, rótulo + valor) → indivisível
  if ((cs.display === 'flex' || cs.display === 'inline-flex') && !cs.flexDirection.startsWith('column') && cs.flexWrap === 'nowrap') return false;
  return true;
}

/** Casca: cópia sem filhos (e sem id, para não duplicar ids entre folhas). */
function criarCasca(original: HTMLElement): HTMLElement {
  const casca = original.cloneNode(false) as HTMLElement;
  casca.removeAttribute('id');
  casca.setAttribute(ATRIBUTO_CASCA, '');
  return casca;
}

function copiaRepetida(el: HTMLElement): HTMLElement {
  const c = el.cloneNode(true) as HTMLElement;
  c.setAttribute(ATRIBUTO_REPETIDO, '');
  c.removeAttribute('id');
  c.querySelectorAll('[id]').forEach((x) => x.removeAttribute('id'));
  return c;
}

/** Casca sem conteúdo próprio (só cabeçalhos repetidos, espaços ou outras cascas vazias). */
function cascaVazia(el: HTMLElement): boolean {
  if (el.tagName === 'TABLE') return !el.querySelector(':scope > tbody > tr, :scope > tfoot > tr, :scope > tr');
  return Array.from(el.childNodes).every(
    (n) => ehTextoVazio(n) || n.nodeType === 8 || (ehElemento(n) && (n.hasAttribute(ATRIBUTO_REPETIDO) || (n.hasAttribute(ATRIBUTO_CASCA) && cascaVazia(n)))),
  );
}

/** Retira as cascas vazias de uma folha (das mais fundas para cima). */
function limparCascasVazias(raiz: HTMLElement): void {
  const cascas = Array.from(raiz.querySelectorAll<HTMLElement>(`[${ATRIBUTO_CASCA}]`)).reverse();
  cascas.forEach((c) => {
    if (c.isConnected && cascaVazia(c)) c.remove();
  });
}

/**
 * Fixa as larguras das colunas das tabelas de topo (percentagens do contentor), medidas no layout final, para que os
 * pedaços de cada tabela em folhas diferentes tenham exactamente as mesmas colunas. Percentagens não dependem do zoom.
 */
export function fixarColunas(raiz: HTMLElement): void {
  const doc = raiz.ownerDocument;
  const tabelas = Array.from(raiz.querySelectorAll<HTMLTableElement>('table')).filter((t) => !t.parentElement?.closest('table'));
  const medidas = tabelas.map((t) => {
    const pai = t.parentElement;
    if (!pai || t.rows.length === 0) return null;
    const sonda = doc.createElement('div');
    sonda.style.cssText = 'width:100%;height:0;margin:0;padding:0;border:0;display:block;';
    pai.insertBefore(sonda, t);
    const larguraPai = sonda.getBoundingClientRect().width;
    sonda.remove();
    const larguraTabela = t.getBoundingClientRect().width;
    if (!larguraPai || !larguraTabela) return null;
    let maxColunas = 0;
    const linhas = Array.from(t.rows).slice(0, 60);
    linhas.forEach((tr) => {
      maxColunas = Math.max(maxColunas, Array.from(tr.cells).reduce((s, c) => s + (c.colSpan || 1), 0));
    });
    const linha = linhas.find((tr) => tr.cells.length === maxColunas && Array.from(tr.cells).every((c) => (c.colSpan || 1) === 1));
    if (!linha) return null;
    const colunas = Array.from(linha.cells).map((c) => c.getBoundingClientRect().width);
    return { t, pct: (larguraTabela / larguraPai) * 100, colunas: colunas.map((w) => (w / larguraTabela) * 100) };
  });
  medidas.forEach((m) => {
    if (!m) return;
    const { t, pct, colunas } = m;
    t.querySelectorAll(':scope > colgroup').forEach((c) => c.remove());
    const grupo = doc.createElement('colgroup');
    colunas.forEach((w) => {
      const col = doc.createElement('col');
      col.style.width = `${w.toFixed(3)}%`;
      grupo.appendChild(col);
    });
    t.insertBefore(grupo, t.firstChild);
    t.style.tableLayout = 'fixed';
    t.style.width = `${Math.min(pct, 400).toFixed(3)}%`;
    t.style.minWidth = '0';
    // os totais (tfoot) vão sempre para o fim da tabela: só aparecem no último pedaço
    t.querySelectorAll(':scope > tfoot').forEach((f) => t.appendChild(f));
  });
}

class Paginador {
  private readonly doc: Document;
  private readonly folhasCriadas: Folha[] = [];
  private atual!: Folha;
  private pilha: Nivel[] = [];

  constructor(
    private readonly contentor: HTMLElement,
    private readonly modeloConteudo: HTMLElement,
    private readonly rodape: string | null,
    private readonly folgaPx: number,
    private readonly primeiros: HTMLElement[],
  ) {
    this.doc = contentor.ownerDocument;
    this.novaFolha();
  }

  get folhas(): Folha[] {
    return this.folhasCriadas;
  }

  private novaFolha(): void {
    const d = this.doc;
    const seccao = d.createElement('section');
    seccao.className = 'imp-pagina';
    const corpo = d.createElement('div');
    corpo.className = 'imp-pagina-corpo';
    if (!this.folhasCriadas.length) this.primeiros.forEach((e) => corpo.appendChild(e));
    const conteudo = this.modeloConteudo.cloneNode(false) as HTMLElement;
    conteudo.removeAttribute('id');
    corpo.appendChild(conteudo);
    const pe = d.createElement('footer');
    pe.className = 'imp-pagina-rodape';
    pe.innerHTML = `<span class="imp-pagina-rodape-empresa">${esc(this.rodape ?? '')}</span><span class="imp-pagina-numero"></span>`;
    seccao.append(corpo, pe);
    this.contentor.appendChild(seccao);
    const folha: Folha = { seccao, corpo, conteudo, folhas: 0, limite: corpo.getBoundingClientRect().bottom - this.folgaPx };
    this.folhasCriadas.push(folha);
    this.atual = folha;
    // recria a cadeia de contentores abertos, com os cabeçalhos repetidos no topo de cada um
    let pai = conteudo;
    this.pilha = this.pilha.map((n) => {
      const casca = criarCasca(n.original);
      n.repetir.forEach((r) => casca.appendChild(copiaRepetida(r)));
      pai.appendChild(casca);
      pai = casca;
      return { ...n, casca };
    });
  }

  private destino(): HTMLElement {
    return this.pilha.length ? this.pilha[this.pilha.length - 1].casca : this.atual.conteudo;
  }

  private cabe(): boolean {
    return this.atual.conteudo.getBoundingClientRect().bottom <= this.atual.limite;
  }

  /**
   * Passa para uma folha nova levando o último título/legenda/linha de grupo da folha actual, para não ficar sozinho
   * no fundo (só se a folha não ficar vazia).
   */
  private irParaNovaFolha(): void {
    const niveis = [this.atual.conteudo, ...this.pilha.map((n) => n.casca)];
    let nivel = niveis.length - 1;
    while (nivel > 0 && cascaVazia(niveis[nivel])) nivel--;
    let candidato = niveis[nivel].lastElementChild as HTMLElement | null;
    if (candidato && candidato === niveis[nivel + 1]) candidato = candidato.previousElementSibling as HTMLElement | null;
    let levar: HTMLElement | null = null;
    if (candidato && this.atual.folhas > 1 && !candidato.hasAttribute(ATRIBUTO_REPETIDO) && !candidato.hasAttribute(ATRIBUTO_CASCA) && candidato.matches(SELECTOR_MANTER_COM_SEGUINTE)) {
      levar = candidato;
      levar.remove();
      this.atual.folhas--;
    }
    this.novaFolha();
    if (levar) {
      const novos = [this.atual.conteudo, ...this.pilha.map((n) => n.casca)];
      novos[nivel].insertBefore(levar, novos[nivel + 1] ?? null);
      this.atual.folhas++;
    }
  }

  /** Reduz um bloco indivisível mais alto do que o espaço livre de uma folha vazia. */
  private encolher(el: HTMLElement): void {
    const r = el.getBoundingClientRect();
    const livre = this.atual.limite - r.top;
    if (r.height > 0 && livre > 0 && r.height > livre) {
      const zoomActual = parseFloat(el.style.getPropertyValue('zoom')) || 1;
      const zoom = String(Math.max(ZOOM_MINIMO, Math.floor((livre / r.height) * zoomActual * 1000) / 1000));
      el.style.setProperty('zoom', zoom);
      el.dataset.impZoom = zoom;
    }
  }

  colocar(no: Node): void {
    if (no.nodeType === 8) {
      no.parentNode?.removeChild(no);
      return;
    }
    const destino = this.destino();
    if (!ehElemento(no)) {
      destino.appendChild(no);
      if (ehTextoVazio(no) || this.cabe()) return;
      // texto solto que não cabe: vai para a folha seguinte (se esta já tiver conteúdo)
      destino.removeChild(no);
      if (this.atual.folhas > 0) this.irParaNovaFolha();
      this.destino().appendChild(no);
      this.atual.folhas++;
      return;
    }
    destino.appendChild(no);
    const cs = estilo(no);
    if (this.atual.folhas > 0 && (ehQuebraPagina(cs?.breakBefore) || ehQuebraPagina(cs?.pageBreakBefore))) {
      destino.removeChild(no);
      this.novaFolha();
      this.colocar(no);
      return;
    }
    if (this.cabe()) {
      this.registar(no, destino);
      if (ehQuebraPagina(cs?.breakAfter) || ehQuebraPagina(cs?.pageBreakAfter) || no.classList.contains('imp-quebra-pagina')) this.novaFolha();
      return;
    }
    const vazia = this.atual.folhas === 0;
    const podeAbrir = divisivel(no, vazia);
    destino.removeChild(no);
    if (podeAbrir) {
      this.abrir(no);
      return;
    }
    if (vazia) {
      // nem numa folha vazia cabe e não se pode partir: fica, reduzido
      this.destino().appendChild(no);
      this.encolher(no);
      this.registar(no, this.destino());
      return;
    }
    this.irParaNovaFolha();
    this.colocar(no);
  }

  private registar(no: HTMLElement, destino: HTMLElement): void {
    const nivel = this.pilha[this.pilha.length - 1];
    if (nivel && destino === nivel.casca && ehRepetivel(no)) {
      nivel.repetir.push(no);
      return; // o cabeçalho não conta como conteúdo
    }
    this.atual.folhas++;
  }

  /** Abre um contentor: casca na folha actual e cada filho colocado por ordem. */
  private abrir(el: HTMLElement): void {
    const casca = criarCasca(el);
    this.destino().appendChild(casca);
    this.pilha.push({ original: el, casca, repetir: [] });
    Array.from(el.childNodes).forEach((filho) => this.colocar(filho));
    this.pilha.pop();
  }
}

/**
 * Pagina o documento da iframe (já com o formato aplicado). Devolve o número de folhas.
 * `rodape` = rodapé da empresa (texto) escrito em cada folha, à esquerda; à direita «Página X de Y».
 */
export function paginarDocumento(doc: Document, formato: FormatoPagina, rodape: string | null): ResultadoPaginacao {
  const body = doc.body;
  const fonte = body.querySelector<HTMLElement>(':scope > .imp-conteudo');
  if (!fonte) return { paginas: 0 };
  const primeiros = Array.from(body.querySelectorAll<HTMLElement>(':scope > .imp-cabecalho, :scope > .imp-titulo-bloco'));

  // fonte medida à largura útil da página (larguras finais das tabelas); depois fica fora do layout
  const medida = doc.createElement('div');
  medida.className = 'imp-fonte';
  medida.style.width = `${formato.larguraUtilMm}mm`;
  body.appendChild(medida);
  medida.appendChild(fonte);
  fixarColunas(fonte);

  const contentor = doc.createElement('div');
  contentor.className = 'imp-paginas';
  body.insertBefore(contentor, body.firstChild);
  body.classList.add('imp-paginado');
  body.style.setProperty('--imp-largura-pagina', `${formato.larguraUtilMm}mm`);
  body.style.setProperty('--imp-altura-pagina', `${alturaPaginaMm(formato)}mm`);
  medida.style.display = 'none';

  const paginador = new Paginador(contentor, fonte, rodape, FOLGA_PAGINA_MM * PX_POR_MM, primeiros);
  Array.from(fonte.childNodes).forEach((n) => paginador.colocar(n));

  let folhas = paginador.folhas;
  folhas.forEach((f) => limparCascasVazias(f.conteudo));
  // folha final sem conteúdo (ex.: depois de uma quebra pedida no último bloco)
  while (folhas.length > 1 && folhas[folhas.length - 1].folhas === 0 && !folhas[folhas.length - 1].conteudo.firstElementChild) {
    folhas[folhas.length - 1].seccao.remove();
    folhas = folhas.slice(0, -1);
  }
  const total = folhas.length;
  folhas.forEach((f, i) => {
    f.seccao.dataset.pagina = String(i + 1);
    const n = f.seccao.querySelector('.imp-pagina-numero');
    if (n) n.textContent = `Página ${i + 1} de ${total}`;
  });
  medida.remove();
  body.dataset.paginas = String(total);
  return { paginas: total };
}

/** Altura (mm) reservada em cada folha para o rodapé (exportada para os testes). */
export const RESERVA_RODAPE_MM = ALTURA_RODAPE_MM;
