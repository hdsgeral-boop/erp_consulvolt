/**
 * Gráficos do Relatório e Contas (porte de js/relatorio_contas.js:589-772 do legado).
 *
 * O legado desenhava os gráficos com Chart.js num canvas fora do ecrã e convertia-os em PNG para serem iguais no ecrã e
 * na impressão. Aqui geram-se directamente como SVG estático (texto escapado, sem scripts): o documento impresso não
 * depende de nada desenhado no momento (vectorial, nítido no PDF, compatível com a CSP) e a paginação trata cada gráfico
 * como bloco indivisível. As séries, os títulos e a ordem são os do legado (GRAFICOS por secção do Relatório de Gestão).
 */
import { esc } from '@/componentes/impressao/documento';
import type { ConfigGestao, DadosGestao, Ind } from './textosGestao';

/** Cores do texto/grelha (papel: tinta escura; ecrã: as do tema, incluindo o nocturno). */
export interface CoresGrafico {
  texto: string;
  secundario: string;
  grelha: string;
  /** cor dos separadores entre fatias/segmentos (a superfície) */
  superficie: string;
}

export const CORES_PAPEL: CoresGrafico = { texto: '#0f172a', secundario: '#475569', grelha: '#e2e8f0', superficie: '#ffffff' };

/** Ano N e N-1 (legado: COR_N / COR_P). */
export const COR_N = '#1e3a8a';
export const COR_P = '#94a3b8';
/** Paleta categórica (legado PALETA, 1.ª cor aclarada para passar a banda de luminosidade; ordem fixa, nunca reciclada por posição). */
export const PALETA = ['#2563eb', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#f97316', '#64748b', '#84cc16'];

const FONTE = "Arial, 'Helvetica Neue', Helvetica, sans-serif";

const num = (v: unknown): number => {
  const n = typeof v === 'number' ? v : Number(v ?? 0);
  return Number.isFinite(n) ? n : 0;
};
const div = (a: number, b: number): number => (Math.abs(b) < 0.005 ? 0 : a / b);
const r2 = (v: number) => Math.round(v * 100) / 100;

/** Formato curto dos eixos (legado fmtCurto): «1,2 M», «350 mil», «1,2 mil M». */
export function fmtCurto(v: number): string {
  const a = Math.abs(v);
  if (a >= 1e9) return `${(v / 1e9).toFixed(1).replace('.', ',')} mil M`;
  if (a >= 1e6) return `${(v / 1e6).toFixed(1).replace('.', ',')} M`;
  if (a >= 1e3) return `${Math.round(v / 1e3)} mil`;
  return String(r2(v)).replace('.', ',');
}

/** Escala «bonita» (0 incluído) com ~5 marcas. */
export function escalaEixo(min: number, max: number, marcas = 5): { min: number; max: number; passo: number; valores: number[] } {
  let lo = Math.min(0, min);
  let hi = Math.max(0, max);
  if (hi - lo < 1e-9) hi = lo + 1;
  const bruto = (hi - lo) / marcas;
  const mag = 10 ** Math.floor(Math.log10(bruto));
  const passo = ([1, 2, 2.5, 5, 10].map((m) => m * mag).find((p) => p >= bruto) ?? 10 * mag);
  lo = Math.floor(lo / passo - 1e-9) * passo;
  hi = Math.ceil(hi / passo - 1e-9) * passo;
  const valores: number[] = [];
  for (let v = lo; v <= hi + passo / 2; v += passo) valores.push(Math.round(v / passo) * passo);
  return { min: lo, max: hi, passo, valores };
}

/** Parte um rótulo em até 2 linhas (~`max` caracteres cada). */
function partirRotulo(r: string, max = 16): string[] {
  if (r.length <= max) return [r];
  const palavras = r.split(/\s+/);
  const l1: string[] = [];
  while (palavras.length && [...l1, palavras[0]].join(' ').length <= max) l1.push(palavras.shift()!);
  if (!l1.length) l1.push(palavras.shift()!);
  const resto = palavras.join(' ');
  return resto ? [l1.join(' '), resto.length > max + 4 ? `${resto.slice(0, max + 2)}…` : resto] : [l1.join(' ')];
}

function svg(largura: number, altura: number, titulo: string, corpo: string): string {
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${largura} ${altura}" width="${largura}" height="${altura}" role="img" aria-label="${esc(titulo)}" style="width:100%;height:auto;max-width:${largura}px;aspect-ratio:${largura}/${altura};font-family:${FONTE}">${corpo}</svg>`;
}

const tituloSvg = (t: string, largura: number, cores: CoresGrafico) =>
  `<text x="${largura / 2}" y="22" text-anchor="middle" font-size="15" font-weight="700" fill="${cores.texto}">${esc(t)}</text>`;

/** Legenda em linha, centrada no fundo (quadrado de cor + texto em tinta de texto). */
function legenda(itens: { rotulo: string; cor: string; tracejado?: boolean }[], largura: number, y: number, cores: CoresGrafico): string {
  const larguras = itens.map((i) => 22 + i.rotulo.length * 6.4 + 14);
  const total = larguras.reduce((a, b) => a + b, 0);
  let x = Math.max(4, (largura - total) / 2);
  return itens
    .map((i, k) => {
      const marca = i.tracejado
        ? `<line x1="${x}" y1="${y - 4}" x2="${x + 14}" y2="${y - 4}" stroke="${i.cor}" stroke-width="2" stroke-dasharray="4 3"/>`
        : `<rect x="${x}" y="${y - 10}" width="12" height="12" rx="2" fill="${i.cor}"/>`;
      const s = `${marca}<text x="${x + 18}" y="${y}" font-size="12" fill="${cores.secundario}">${esc(i.rotulo)}</text>`;
      x += larguras[k];
      return s;
    })
    .join('');
}

export interface OpcoesBarras {
  pct?: boolean;
  racio?: boolean;
  referencia?: number;
  referenciaRotulo?: string;
  horizontal?: boolean;
  largura?: number;
  altura?: number;
  cores?: CoresGrafico;
}

const formatoEixo = (o: OpcoesBarras) => (o.pct ? (v: number) => `${r2(v)}%` : o.racio ? (v: number) => String(r2(v)).replace('.', ',') : fmtCurto);

/**
 * Barras agrupadas N vs N-1 (legado barrasComparadas): '' quando todos os valores são ~0. `pct` multiplica por 100.
 * Legenda sempre presente (2 séries); os valores exactos estão nas tabelas por cima de cada gráfico.
 */
export function barrasComparadas(titulo: string, rotulos: string[], valN: unknown[], valP: unknown[], anos: [number | string, number | string], o: OpcoesBarras = {}): string {
  const escala = o.pct ? 100 : 1;
  const n = valN.map((v) => r2(num(v) * escala));
  const p = valP.map((v) => r2(num(v) * escala));
  if (![...n, ...p].some((v) => Math.abs(v) >= 0.005)) return '';
  const cores = o.cores ?? CORES_PAPEL;
  const series = [
    { rotulo: String(anos[0]), cor: COR_N, valores: n },
    { rotulo: String(anos[1]), cor: COR_P, valores: p },
  ];
  return o.horizontal ? barrasH(titulo, rotulos, series, o, cores) : barrasV(titulo, rotulos, series, o, cores);
}

interface Serie {
  rotulo: string;
  cor: string;
  valores: number[];
}

function barrasV(titulo: string, rotulos: string[], series: Serie[], o: OpcoesBarras, cores: CoresGrafico): string {
  const W = o.largura ?? 900;
  const H = o.altura ?? 340;
  const fmt = formatoEixo(o);
  const todos = series.flatMap((s) => s.valores).concat(o.referencia ?? 0);
  const e = escalaEixo(Math.min(...todos), Math.max(...todos));
  const esq = 64, dir = 16, topo = 40, baixo = 78;
  const pw = W - esq - dir, ph = H - topo - baixo;
  const y = (v: number) => topo + ((e.max - v) / (e.max - e.min)) * ph;
  const grupo = pw / rotulos.length;
  const larg = Math.min(44, (grupo * 0.7) / series.length);
  let corpo = tituloSvg(titulo, W, cores);
  e.valores.forEach((v) => {
    corpo += `<line x1="${esq}" y1="${y(v).toFixed(1)}" x2="${W - dir}" y2="${y(v).toFixed(1)}" stroke="${cores.grelha}" stroke-width="1"/>`;
    corpo += `<text x="${esq - 6}" y="${(y(v) + 4).toFixed(1)}" text-anchor="end" font-size="11" fill="${cores.secundario}">${esc(fmt(v))}</text>`;
  });
  const y0 = y(0);
  rotulos.forEach((r, i) => {
    const cx = esq + grupo * i + grupo / 2;
    const x0 = cx - (larg * series.length + 2 * (series.length - 1)) / 2;
    series.forEach((s, k) => {
      const v = s.valores[i] ?? 0;
      const yv = y(v);
      const h = Math.abs(yv - y0);
      if (h < 0.05) return;
      corpo += `<rect x="${(x0 + k * (larg + 2)).toFixed(1)}" y="${Math.min(yv, y0).toFixed(1)}" width="${larg.toFixed(1)}" height="${h.toFixed(1)}" rx="3" fill="${s.cor}"><title>${esc(`${r} ${s.rotulo}: ${fmt(v)}`)}</title></rect>`;
    });
    partirRotulo(r, Math.max(10, Math.floor(grupo / 7.2))).forEach((linha, j) => {
      corpo += `<text x="${cx.toFixed(1)}" y="${(H - baixo + 18 + j * 14).toFixed(1)}" text-anchor="middle" font-size="11.5" fill="${cores.texto}">${esc(linha)}</text>`;
    });
  });
  corpo += `<line x1="${esq}" y1="${y0.toFixed(1)}" x2="${W - dir}" y2="${y0.toFixed(1)}" stroke="${cores.secundario}" stroke-width="1"/>`;
  const itens: { rotulo: string; cor: string; tracejado?: boolean }[] = series.map((s) => ({ rotulo: s.rotulo, cor: s.cor }));
  if (o.referencia !== undefined) {
    const yr = y(o.referencia).toFixed(1);
    corpo += `<line x1="${esq}" y1="${yr}" x2="${W - dir}" y2="${yr}" stroke="#dc2626" stroke-width="2" stroke-dasharray="6 4"/>`;
    itens.push({ rotulo: o.referenciaRotulo ?? 'Referência', cor: '#dc2626', tracejado: true });
  }
  corpo += legenda(itens, W, H - 10, cores);
  return svg(W, H, titulo, corpo);
}

function barrasH(titulo: string, rotulos: string[], series: Serie[], o: OpcoesBarras, cores: CoresGrafico): string {
  const W = o.largura ?? 560;
  const H = o.altura ?? Math.max(260, 90 + rotulos.length * 34);
  const fmt = formatoEixo(o);
  const todos = series.flatMap((s) => s.valores);
  const e = escalaEixo(Math.min(...todos), Math.max(...todos), 4);
  const esq = 190, dir = 20, topo = 40, baixo = 50;
  const pw = W - esq - dir, ph = H - topo - baixo;
  const x = (v: number) => esq + ((v - e.min) / (e.max - e.min)) * pw;
  const grupo = ph / rotulos.length;
  const alt = Math.min(14, (grupo * 0.7) / series.length);
  let corpo = tituloSvg(titulo, W, cores);
  e.valores.forEach((v) => {
    corpo += `<line x1="${x(v).toFixed(1)}" y1="${topo}" x2="${x(v).toFixed(1)}" y2="${H - baixo}" stroke="${cores.grelha}" stroke-width="1"/>`;
    corpo += `<text x="${x(v).toFixed(1)}" y="${H - baixo + 14}" text-anchor="middle" font-size="10.5" fill="${cores.secundario}">${esc(fmt(v))}</text>`;
  });
  const x0 = x(0);
  rotulos.forEach((r, i) => {
    const cy = topo + grupo * i + grupo / 2;
    const y0 = cy - (alt * series.length + 2 * (series.length - 1)) / 2;
    series.forEach((s, k) => {
      const v = s.valores[i] ?? 0;
      const w = Math.abs(x(v) - x0);
      if (w < 0.05) return;
      corpo += `<rect x="${Math.min(x(v), x0).toFixed(1)}" y="${(y0 + k * (alt + 2)).toFixed(1)}" width="${w.toFixed(1)}" height="${alt.toFixed(1)}" rx="3" fill="${s.cor}"><title>${esc(`${r} ${s.rotulo}: ${fmt(v)}`)}</title></rect>`;
    });
    const t = r.length > 30 ? `${r.slice(0, 29)}…` : r;
    corpo += `<text x="${esq - 8}" y="${(cy + 4).toFixed(1)}" text-anchor="end" font-size="11" fill="${cores.texto}">${esc(t)}</text>`;
  });
  corpo += `<line x1="${x0.toFixed(1)}" y1="${topo}" x2="${x0.toFixed(1)}" y2="${H - baixo}" stroke="${cores.secundario}" stroke-width="1"/>`;
  corpo += legenda(series.map((s) => ({ rotulo: s.rotulo, cor: s.cor })), W, H - 10, cores);
  return svg(W, H, titulo, corpo);
}

const pc1 = (v: number) => `${(v * 100).toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`;

/** Circular (anel) com a percentagem de cada parte na legenda (legado circular): só valores positivos. */
export function circular(titulo: string, rotulos: string[], valores: unknown[], o: { largura?: number; altura?: number; cores?: CoresGrafico } = {}): string {
  const pares = rotulos.map((r, i) => ({ r, v: r2(num(valores[i])), cor: PALETA[i % PALETA.length] })).filter((p) => p.v > 0);
  if (!pares.length) return '';
  const cores = o.cores ?? CORES_PAPEL;
  const W = o.largura ?? 560;
  const H = o.altura ?? 340;
  const total = pares.reduce((s, p) => s + p.v, 0);
  const cx = 150, cy = 40 + (H - 40) / 2, R = Math.min(120, (H - 60) / 2), r = R * 0.55;
  let corpo = tituloSvg(titulo, W, cores);
  let ang = -Math.PI / 2;
  const ponto = (a: number, raio: number) => `${(cx + raio * Math.cos(a)).toFixed(2)} ${(cy + raio * Math.sin(a)).toFixed(2)}`;
  pares.forEach((p) => {
    const fr = p.v / total;
    if (fr >= 0.9999) {
      corpo += `<circle cx="${cx}" cy="${cy}" r="${((R + r) / 2).toFixed(2)}" fill="none" stroke="${p.cor}" stroke-width="${(R - r).toFixed(2)}"/>`;
      return;
    }
    const a2 = ang + fr * 2 * Math.PI;
    const grande = fr > 0.5 ? 1 : 0;
    corpo += `<path d="M ${ponto(ang, R)} A ${R} ${R} 0 ${grande} 1 ${ponto(a2, R)} L ${ponto(a2, r)} A ${r} ${r} 0 ${grande} 0 ${ponto(ang, r)} Z" fill="${p.cor}" stroke="${cores.superficie}" stroke-width="2"><title>${esc(`${p.r}: ${pc1(fr)}`)}</title></path>`;
    ang = a2;
  });
  const lx = cx + R + 30;
  const passo = Math.min(24, (H - 70) / pares.length);
  const y0 = cy - (passo * (pares.length - 1)) / 2;
  pares.forEach((p, i) => {
    const y = y0 + i * passo;
    const rot = p.r.length > 34 ? `${p.r.slice(0, 33)}…` : p.r;
    corpo += `<rect x="${lx}" y="${(y - 9).toFixed(1)}" width="12" height="12" rx="2" fill="${p.cor}"/>`;
    corpo += `<text x="${lx + 18}" y="${(y + 1).toFixed(1)}" font-size="12" fill="${cores.texto}">${esc(rot)} <tspan fill="${cores.secundario}">(${esc(pc1(p.v / total))})</tspan></text>`;
  });
  return svg(W, H, titulo, corpo);
}

/** Barras empilhadas (estrutura do balanço: activo N/N-1 vs capital próprio + passivo N/N-1). */
export function barrasEmpilhadas(titulo: string, rotulos: string[], series: Serie[], o: { largura?: number; altura?: number; cores?: CoresGrafico } = {}): string {
  const ss = series.filter((s) => s.valores.some((v) => Math.abs(v) >= 0.005));
  if (!ss.length) return '';
  const cores = o.cores ?? CORES_PAPEL;
  const W = o.largura ?? 900;
  const H = o.altura ?? 380;
  const pos = rotulos.map((_, i) => ss.reduce((t, s) => t + Math.max(0, s.valores[i] ?? 0), 0));
  const neg = rotulos.map((_, i) => ss.reduce((t, s) => t + Math.min(0, s.valores[i] ?? 0), 0));
  const e = escalaEixo(Math.min(...neg), Math.max(...pos));
  const esq = 64, dir = 16, topo = 40, baixo = 84;
  const pw = W - esq - dir, ph = H - topo - baixo;
  const y = (v: number) => topo + ((e.max - v) / (e.max - e.min)) * ph;
  const grupo = pw / rotulos.length;
  const larg = Math.min(110, grupo * 0.55);
  let corpo = tituloSvg(titulo, W, cores);
  e.valores.forEach((v) => {
    corpo += `<line x1="${esq}" y1="${y(v).toFixed(1)}" x2="${W - dir}" y2="${y(v).toFixed(1)}" stroke="${cores.grelha}" stroke-width="1"/>`;
    corpo += `<text x="${esq - 6}" y="${(y(v) + 4).toFixed(1)}" text-anchor="end" font-size="11" fill="${cores.secundario}">${esc(fmtCurto(v))}</text>`;
  });
  rotulos.forEach((r, i) => {
    const x0 = esq + grupo * i + (grupo - larg) / 2;
    let acPos = 0;
    let acNeg = 0;
    ss.forEach((s) => {
      const v = s.valores[i] ?? 0;
      if (Math.abs(v) < 0.005) return;
      const de = v >= 0 ? acPos : acNeg;
      const ate = de + v;
      if (v >= 0) acPos = ate;
      else acNeg = ate;
      const ya = y(Math.max(de, ate));
      const h = Math.abs(y(de) - y(ate));
      // 2 px de superfície entre segmentos
      corpo += `<rect x="${x0.toFixed(1)}" y="${ya.toFixed(1)}" width="${larg.toFixed(1)}" height="${Math.max(0, h).toFixed(1)}" fill="${s.cor}" stroke="${cores.superficie}" stroke-width="1"><title>${esc(`${r} · ${s.rotulo}: ${fmtCurto(v)}`)}</title></rect>`;
    });
    corpo += `<text x="${(x0 + larg / 2).toFixed(1)}" y="${(H - baixo + 18).toFixed(1)}" text-anchor="middle" font-size="11.5" fill="${cores.texto}">${esc(r)}</text>`;
  });
  corpo += `<line x1="${esq}" y1="${y(0).toFixed(1)}" x2="${W - dir}" y2="${y(0).toFixed(1)}" stroke="${cores.secundario}" stroke-width="1"/>`;
  // legenda em duas linhas (até 8 séries)
  const metade = Math.ceil(ss.length / 2);
  corpo += legenda(ss.slice(0, metade).map((s) => ({ rotulo: s.rotulo, cor: s.cor })), W, H - 34, cores);
  corpo += legenda(ss.slice(metade).map((s) => ({ rotulo: s.rotulo, cor: s.cor })), W, H - 12, cores);
  return svg(W, H, titulo, corpo);
}

/** Figura (bloco indivisível na paginação). */
export const figura = (svgHtml: string) => (svgHtml ? `<figure class="rc-graf imp-sem-quebra" style="margin:12px auto">${svgHtml}</figure>` : '');
/** Dois gráficos lado a lado (legado duo). */
export function duo(...partes: string[]): string {
  const v = partes.filter(Boolean);
  if (!v.length) return '';
  if (v.length === 1) return figura(v[0]);
  // estilos em linha para o ecrã (passa a coluna em ecrãs estreitos); na impressão o CSS do documento impõe a linha
  return `<div class="rc-graf-duo imp-sem-quebra" style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0">${v.map((s) => `<figure class="rc-graf" style="flex:1 1 280px;min-width:0;margin:0">${s}</figure>`).join('')}</div>`;
}

/** Dados que os gráficos usam (subconjunto do relatório). */
export interface DadosGraficos extends DadosGestao {
  n: { indicadores: Ind; fluxo?: Record<string, unknown> };
  n1: { indicadores: Ind; fluxo?: Record<string, unknown> };
  composicao?: Record<string, { conta: string; descricao: string | null; n: string; n1: string }[]>;
}

type Gerador = (d: DadosGraficos, c: ConfigGestao, cores: CoresGrafico) => string;

const g = (i: Ind, k: string) => num(i[k]);

/** Gráficos por secção do Relatório de Gestão (legado GRAFICOS, js/relatorio_contas.js:675-771). */
export const GRAFICOS: Record<string, Gerador> = {
  acontecimentos: (d, _c, cores) => {
    const N = d.n.indicadores, P = d.n1.indicadores, anos: [number, number] = [d.ano, d.ano_anterior];
    const k = ['vendas_prestacoes', 'margem_bruta', 'ebitda', 'ebitda_ajustado', 'ebit', 'rl'];
    const margens = (i: Ind) => [div(g(i, 'margem_bruta'), g(i, 'ganhos_op')), div(g(i, 'ebitda'), g(i, 'vendas_prestacoes')), g(i, 'margem_operacional'), g(i, 'ros')];
    return (
      figura(barrasComparadas('Evolução dos principais agregados de resultados', ['Vendas e prestações', 'Margem bruta', 'EBITDA', 'EBITDA ajustado', 'EBIT', 'Resultado líquido'], k.map((x) => N[x]), k.map((x) => P[x]), anos, { cores })) +
      duo(
        circular(`Estrutura dos gastos operacionais ${d.ano}`, ['CMVMC', 'Custos com o pessoal', 'Outros custos operacionais', 'Amortizações'], [N.cmvmc, N.pessoal, N.outros_custos, N.amortizacoes], { cores }),
        barrasComparadas('Margens sobre as vendas', ['Margem bruta', 'Margem EBITDA', 'Margem operacional', 'Margem líquida'], margens(N), margens(P), anos, { pct: true, largura: 560, cores }),
      )
    );
  },
  analise_balanco: (d, _c, cores) => {
    const N = d.n.indicadores, P = d.n1.indicadores;
    const serie = (rotulo: string, k: string, lado: 'A' | 'P', cor: string): Serie => ({ rotulo, cor, valores: lado === 'A' ? [g(N, k), g(P, k), 0, 0] : [0, 0, g(N, k), g(P, k)] });
    const estrutura = barrasEmpilhadas(
      'Estrutura do balanço',
      [`Activo ${d.ano}`, `Activo ${d.ano_anterior}`, `CP + Passivo ${d.ano}`, `CP + Passivo ${d.ano_anterior}`],
      [
        serie('Activos não correntes', 'activo_nao_corrente', 'A', '#1e3a8a'),
        serie('Existências', 'existencias', 'A', '#0ea5e9'),
        serie('Contas a receber', 'contas_receber', 'A', '#38bdf8'),
        serie('Disponibilidades', 'disponibilidades', 'A', '#10b981'),
        serie('Outros activos correntes', 'outros_activos_correntes', 'A', '#a3e635'),
        serie('Capital próprio', 'capital_proprio', 'P', '#8b5cf6'),
        serie('Passivo não corrente', 'passivo_nao_corrente', 'P', '#f59e0b'),
        serie('Passivo corrente', 'passivo_corrente', 'P', '#ef4444'),
      ],
      { cores },
    );
    const k = ['contas_receber', 'disponibilidades', 'existencias', 'contas_pagar', 'outros_passivos_correntes'];
    return (
      figura(estrutura) +
      figura(barrasComparadas('Principais rubricas do fundo de maneio', ['Contas a receber', 'Disponibilidades', 'Existências', 'Contas a pagar', 'Outros passivos correntes'], k.map((x) => N[x]), k.map((x) => P[x]), [d.ano, d.ano_anterior], { cores }))
    );
  },
  recursos_humanos: (d, _c, cores) => {
    const linhas = [...(d.composicao?.['28'] ?? [])].sort((a, b) => Math.abs(num(b.n)) - Math.abs(num(a.n))).slice(0, 10);
    if (!linhas.length) return '';
    const N = d.n.indicadores, P = d.n1.indicadores;
    const peso = (i: Ind) => [div(g(i, 'pessoal'), g(i, 'vendas_prestacoes')), div(g(i, 'pessoal'), g(i, 'gastos_op') + g(i, 'cmvmc'))];
    return duo(
      barrasComparadas('Custos com o pessoal por natureza', linhas.map((r) => String(r.descricao || r.conta).substring(0, 38)), linhas.map((r) => r.n), linhas.map((r) => r.n1), [d.ano, d.ano_anterior], {
        horizontal: true,
        largura: 560,
        altura: Math.max(260, 90 + linhas.length * 34),
        cores,
      }),
      barrasComparadas('Peso dos custos com o pessoal', ['% das vendas e prestações', '% dos gastos operacionais'], peso(N), peso(P), [d.ano, d.ano_anterior], { pct: true, largura: 560, cores }),
    );
  },
  liquidez: (d, c, cores) => {
    const k = ['liquidez_geral', 'liquidez_reduzida', 'liquidez_imediata'];
    return (
      figura(
        barrasComparadas('Rácios de liquidez', ['Liquidez geral', 'Liquidez reduzida', 'Liquidez imediata'], k.map((x) => d.n.indicadores[x]), k.map((x) => d.n1.indicadores[x]), [d.ano, d.ano_anterior], {
          racio: true,
          referencia: 1,
          referenciaRotulo: 'Referência (1,00)',
          cores,
        }),
      ) + GRAFICOS.fluxo(d, c, cores)
    );
  },
  estrutura: (d, _c, cores) => {
    const k = ['autonomia_financeira', 'solvabilidade', 'endividamento_total', 'estrutura_endividamento'];
    return figura(
      barrasComparadas('Indicadores de estrutura financeira', ['Autonomia financeira', 'Solvabilidade', 'Endividamento total', 'Estrutura do endividamento'], k.map((x) => d.n.indicadores[x]), k.map((x) => d.n1.indicadores[x]), [d.ano, d.ano_anterior], {
        racio: true,
        cores,
      }),
    );
  },
  rentabilidade: (d, _c, cores) => {
    const k = ['roe', 'roa', 'ros', 'margem_operacional'];
    return figura(barrasComparadas('Indicadores de rentabilidade', ['ROE', 'ROA', 'ROS', 'Margem operacional'], k.map((x) => d.n.indicadores[x]), k.map((x) => d.n1.indicadores[x]), [d.ano, d.ano_anterior], { pct: true, cores }));
  },
  aplicacao: (d, c, cores) => {
    const rl = g(d.n.indicadores, 'rl');
    if (rl <= 0) return '';
    return figura(
      circular('Proposta de aplicação do resultado', ['Reservas legais', 'Resultados transitados', 'Dividendos'], [(rl * num(c.pct_reservas)) / 100, (rl * num(c.pct_transitados)) / 100, (rl * num(c.pct_dividendos)) / 100], {
        largura: 560,
        altura: 320,
        cores,
      }),
    );
  },
  fluxo: (d, _c, cores) => {
    const N = d.n.fluxo ?? {}, P = d.n1.fluxo ?? {};
    const k = ['operacionais', 'investimento', 'financiamento', 'variacao'];
    return figura(barrasComparadas('Fluxos de caixa por actividade', ['Operacionais', 'Investimento', 'Financiamento', 'Variação de caixa'], k.map((x) => N[x]), k.map((x) => P[x]), [d.ano, d.ano_anterior], { cores }));
  },
};

/** Gráficos de uma secção (legado graficoSeccao): nada quando a configuração desliga os gráficos. */
export function graficoSeccao(id: string, d: DadosGraficos, c: ConfigGestao, cores: CoresGrafico = CORES_PAPEL): string {
  if (c.graficos === false) return '';
  const gerador = Object.prototype.hasOwnProperty.call(GRAFICOS, id) ? GRAFICOS[id] : undefined;
  return gerador && id !== 'fluxo' ? gerador(d, c, cores) : '';
}
