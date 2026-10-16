import dayjs from 'dayjs';
import type { CampoFormulario, OpcaoCampo } from './tipos';

/**
 * Conversões dos valores das linhas (colagem do Excel, ligação à grade, validação), portadas do legado
 * (js/shared/formulario_linhas.js: normTxt, numeroTexto, dataTexto, opcaoPara, definirValor, colarExcel, gravarLote).
 */

/** Texto normalizado para comparação: sem acentos, minúsculas, espaços simples. */
export const normTxt = (s: unknown): string =>
  String(s ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim();

/** Número em formato português ou inglês: «70 004,54», «1.234,56», «1,234.56», «1234.5» → 70004.54; `''` vazio; `null` inválido. */
export function numeroTexto(s: unknown): number | '' | null {
  let t = String(s ?? '')
    .trim()
    .replace(/[\s  ]/g, '')
    .replace(/(kz|aoa|usd|eur)$/i, '');
  if (!t) return '';
  if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(t) || /^-?\d+,\d+$/.test(t)) t = t.replace(/\./g, '').replace(',', '.');
  else if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(t)) t = t.replace(/,/g, '');
  return /^-?\d+(\.\d+)?$/.test(t) ? Number(t) : null;
}

/** Data «16/07/2026», «16-07-2026», «2026-07-16» ou nº de série do Excel → «2026-07-16»; `''` vazio; `null` inválida. */
export function dataTexto(s: unknown): string | null {
  const t = String(s ?? '').trim();
  if (!t) return '';
  let m = t.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/);
  let r: string | null = null;
  if (m) r = `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
  else if ((m = t.match(/^(\d{4})[/.-](\d{1,2})[/.-](\d{1,2})/))) r = `${m[1]}-${m[2].padStart(2, '0')}-${m[3].padStart(2, '0')}`;
  else if (/^\d{5}$/.test(t)) return new Date(Math.round((parseInt(t, 10) - 25569) * 864e5)).toISOString().slice(0, 10);
  return r && dayjs(r, 'YYYY-MM-DD', true).isValid() ? r : null;
}

/** Opção de uma lista: valor, texto, código antes de « - », texto entre parênteses (ex.: NIF) ou início único do texto. */
export function opcaoPara(opcoes: OpcaoCampo[], s: string): OpcaoCampo | undefined {
  const alvo = normTxt(s);
  const ops = opcoes.filter((o) => o.value !== '');
  return (
    ops.find((o) => String(o.value) === s.trim()) ??
    ops.find((o) => normTxt(o.label) === alvo) ??
    ops.find((o) => normTxt(o.label.split(' - ')[0]) === alvo) ??
    ops.find((o) => {
      const p = o.label.match(/\(([^)]+)\)\s*$/);
      return !!p && normTxt(p[1].replace(/^\s*(nif|bi|cod(igo)?)\s*:\s*/i, '')) === alvo;
    }) ??
    (() => {
      const inicio = ops.filter((o) => normTxt(o.label).startsWith(alvo));
      return inicio.length === 1 ? inicio[0] : undefined;
    })()
  );
}

/** Converte um texto colado para o valor do campo. `ok: false` = valor não vazio que não foi reconhecido. */
export function converterTexto(campo: CampoFormulario, texto: string): { ok: boolean; valor: unknown } {
  const s = String(texto ?? '').trim();
  if (!s) return { ok: true, valor: valorVazio(campo) };
  switch (campo.tipo ?? 'texto') {
    case 'numero': {
      const n = numeroTexto(s);
      return n === null || n === '' ? { ok: false, valor: null } : { ok: true, valor: n };
    }
    case 'data': {
      const d = dataTexto(s);
      return d ? { ok: true, valor: d } : { ok: false, valor: null };
    }
    case 'select': {
      const o = opcaoPara(campo.opcoes ?? [], s);
      return o ? { ok: true, valor: o.value } : { ok: false, valor: null };
    }
    case 'personalizado': {
      if (!campo.converter) return { ok: true, valor: s };
      const v = campo.converter(s);
      return v === undefined ? { ok: false, valor: null } : { ok: true, valor: v };
    }
    default:
      return { ok: true, valor: campo.maxLength ? s.slice(0, campo.maxLength) : s };
  }
}

export const valorVazio = (campo: CampoFormulario): unknown => ((campo.tipo ?? 'texto') === 'texto' || campo.tipo === 'textoLongo' ? '' : null);

export const estaVazio = (v: unknown): boolean => v === null || v === undefined || (typeof v === 'string' && v.trim() === '') || (Array.isArray(v) && v.length === 0);

/** Valor por omissão de uma linha nova. */
export function omissaoDe(campo: CampoFormulario): unknown {
  const o = typeof campo.omissao === 'function' ? (campo.omissao as () => unknown)() : campo.omissao;
  return o === undefined ? valorVazio(campo) : o;
}

/** Uma linha é «em branco» quando todos os valores são vazios ou iguais aos valores por omissão (legado: `vazia`). */
export function linhaVazia(campos: CampoFormulario[], valores: Record<string, unknown>, omissoes: Record<string, unknown>): boolean {
  return campos.every((c) => {
    const v = valores[c.nome];
    return estaVazio(v) || String(v) === String(omissoes[c.nome] ?? '');
  });
}

/** Erros de uma linha antes de gravar: obrigatórios em falta, valores colados por corrigir e validações próprias. */
export function errosLinha(campos: CampoFormulario[], valores: Record<string, unknown>, invalidos?: Record<string, string>): string | null {
  const faltam = campos.filter((c) => c.obrigatorio && estaVazio(valores[c.nome])).map((c) => c.rotulo);
  const partes: string[] = [];
  if (faltam.length) partes.push(`Falta: ${faltam.join(', ')}.`);
  const porCorrigir = Object.entries(invalidos ?? {}).map(([k, t]) => `${campos.find((c) => c.nome === k)?.rotulo ?? k}: «${t}»`);
  if (porCorrigir.length) partes.push(`Valor(es) não reconhecido(s): ${porCorrigir.join('; ')}.`);
  for (const c of campos) {
    const m = c.validar?.(valores[c.nome], valores);
    if (m) partes.push(m);
  }
  return partes.length ? partes.join(' ') : null;
}

/** Valor da linha → valor do `Form` da grade (datas como `dayjs`). */
export function paraFormulario(campo: CampoFormulario, v: unknown): unknown {
  if (campo.tipo === 'data') return typeof v === 'string' && v ? dayjs(v) : undefined;
  return estaVazio(v) ? undefined : v;
}

/** Valor do `Form` da grade → valor da linha. */
export function deFormulario(campo: CampoFormulario, v: unknown): unknown {
  if (v === undefined || v === null || v === '') return undefined;
  if (campo.tipo === 'data') return dayjs.isDayjs(v) ? v.format('YYYY-MM-DD') : typeof v === 'string' ? dataTexto(v) || undefined : undefined;
  return v;
}

/** Separa o texto colado (tabulação do Excel; senão «|» ou «;») em células. */
export function partirTexto(texto: string): string[][] {
  const linhas = texto.replace(/\r/g, '').split('\n').filter((l) => l.trim());
  if (!linhas.length) return [];
  const sep = linhas.some((l) => l.includes('\t')) ? '\t' : linhas.every((l) => l.includes('|')) ? '|' : linhas.every((l) => l.includes(';')) ? ';' : '\t';
  return linhas.map((l) => l.split(sep).map((v) => v.trim()));
}

const chaveNome = (s: string) => normTxt(s).replace(/[^a-z0-9]/g, '');

/**
 * Linhas coladas → valores pela ordem das colunas. Sem cabeçalho, as colunas são lidas pela ordem dada; com a linha
 * de cabeçalho (≥ 2 nomes reconhecidos e pelo menos metade das células), pelo nome (rótulo ou `aliases`).
 */
export function mapearColagem(celulas: string[][], campos: CampoFormulario[]): { linhas: string[][]; comCabecalho: boolean } {
  if (!celulas.length) return { linhas: [], comCabecalho: false };
  const nomes = campos.map((c) => [c.rotulo, c.nome, ...(c.aliases ?? [])].map(chaveNome));
  const primeira = celulas[0].map(chaveNome);
  const mapa = primeira.map((h) => {
    if (!h) return -1;
    const exacto = nomes.findIndex((ns) => ns.includes(h));
    if (exacto >= 0) return exacto;
    let melhor = -1;
    let tam = 0;
    nomes.forEach((ns, i) =>
      ns.forEach((n) => {
        if (n && h.length > 3 && n.length > 3 && (n.startsWith(h) || h.startsWith(n)) && Math.min(n.length, h.length) > tam) {
          melhor = i;
          tam = Math.min(n.length, h.length);
        }
      }),
    );
    return melhor;
  });
  const usados = new Set<number>();
  mapa.forEach((c, i) => {
    if (c >= 0) {
      if (usados.has(c)) mapa[i] = -1;
      else usados.add(c);
    }
  });
  const reconhecidas = mapa.filter((i) => i >= 0).length;
  const comCabecalho = reconhecidas >= 2 && reconhecidas >= primeira.filter(Boolean).length / 2;
  if (!comCabecalho) return { linhas: celulas.map((l) => campos.map((_, i) => l[i] ?? '')), comCabecalho };
  return {
    comCabecalho,
    linhas: celulas.slice(1).map((l) => {
      const v = campos.map(() => '');
      l.forEach((x, i) => {
        if (mapa[i] >= 0) v[mapa[i]] = x;
      });
      return v;
    }),
  };
}

/** Mensagem de um erro de gravação (ErroApi com detalhes por campo, Error, texto). */
export function mensagemErro(e: unknown): string {
  if (!e) return 'Não foi gravada.';
  const base = e instanceof Error ? e.message : typeof e === 'string' ? e : 'Erro ao gravar.';
  const erros = (e as { erros?: Record<string, unknown> }).erros;
  if (!erros || typeof erros !== 'object') return base || 'Erro ao gravar.';
  const det = Object.values(erros).flatMap((v) => (Array.isArray(v) ? v.filter((x) => typeof x === 'string') : typeof v === 'string' ? [v] : [])) as string[];
  const extra = det.filter((d) => d !== base).slice(0, 4);
  return [base, ...extra].filter(Boolean).join(' ');
}
