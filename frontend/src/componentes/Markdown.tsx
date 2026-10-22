import { Fragment, type ReactNode } from 'react';

/**
 * Markdown simples para as respostas do assistente IA: títulos, parágrafos, listas, citações, blocos de código,
 * tabelas, **negrito**, *itálico*, `código` e [ligações](https://…). Gera elementos React (nunca HTML injectado):
 * o texto vem de um modelo e não é de confiança. Ligações só http(s), abertas noutro separador.
 */
export function Markdown({ texto }: { texto: string }) {
  return <div className="erp-markdown">{blocos(texto)}</div>;
}

function inline(texto: string, chave = 'i'): ReactNode[] {
  const partes: ReactNode[] = [];
  const re = /(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\((https?:\/\/[^)\s]+)\)|\*[^*\s][^*]*\*|_[^_\s][^_]*_)/g;
  let ultimo = 0;
  let m: RegExpExecArray | null;
  let n = 0;
  while ((m = re.exec(texto)) !== null) {
    if (m.index > ultimo) partes.push(texto.slice(ultimo, m.index));
    const t = m[0];
    const k = `${chave}-${n++}`;
    if (t.startsWith('**')) partes.push(<strong key={k}>{inline(t.slice(2, -2), k)}</strong>);
    else if (t.startsWith('`')) partes.push(<code key={k}>{t.slice(1, -1)}</code>);
    else if (t.startsWith('[')) {
      const rotulo = t.slice(1, t.indexOf(']'));
      partes.push(<a key={k} href={m[2]} target="_blank" rel="noopener noreferrer">{rotulo}</a>);
    } else partes.push(<em key={k}>{inline(t.slice(1, -1), k)}</em>);
    ultimo = m.index + t.length;
  }
  if (ultimo < texto.length) partes.push(texto.slice(ultimo));
  return partes;
}

function celulas(linha: string): string[] {
  return linha.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((c) => c.trim());
}

function blocos(texto: string): ReactNode[] {
  const linhas = texto.replace(/\r\n/g, '\n').split('\n');
  const saida: ReactNode[] = [];
  let i = 0;
  let k = 0;
  while (i < linhas.length) {
    const l = linhas[i];
    if (l.trim() === '') {
      i++;
      continue;
    }
    if (l.trim().startsWith('```')) {
      const codigo: string[] = [];
      i++;
      while (i < linhas.length && !linhas[i].trim().startsWith('```')) codigo.push(linhas[i++]);
      i++;
      saida.push(<pre key={k++}><code>{codigo.join('\n')}</code></pre>);
      continue;
    }
    const titulo = /^(#{1,4})\s+(.*)$/.exec(l);
    if (titulo) {
      const nivel = Math.min(6, titulo[1].length + 2);
      const Tag = `h${nivel}` as 'h3';
      saida.push(<Tag key={k++}>{inline(titulo[2])}</Tag>);
      i++;
      continue;
    }
    if (l.trim().startsWith('|') && i + 1 < linhas.length && /^\s*\|?\s*:?-{2,}/.test(linhas[i + 1])) {
      const cab = celulas(l);
      i += 2;
      const corpo: string[][] = [];
      while (i < linhas.length && linhas[i].trim().startsWith('|')) corpo.push(celulas(linhas[i++]));
      saida.push(
        <div key={k++} className="erp-markdown-tabela">
          <table>
            <thead><tr>{cab.map((c, j) => <th key={j}>{inline(c)}</th>)}</tr></thead>
            <tbody>{corpo.map((r, j) => <tr key={j}>{r.map((c, x) => <td key={x}>{inline(c)}</td>)}</tr>)}</tbody>
          </table>
        </div>,
      );
      continue;
    }
    if (/^\s*([-*•]|\d+[.)])\s+/.test(l)) {
      const ordenada = /^\s*\d+[.)]/.test(l);
      const itens: string[] = [];
      while (i < linhas.length && /^\s*([-*•]|\d+[.)])\s+/.test(linhas[i])) itens.push(linhas[i++].replace(/^\s*([-*•]|\d+[.)])\s+/, ''));
      const Lista = ordenada ? 'ol' : 'ul';
      saida.push(<Lista key={k++}>{itens.map((t, j) => <li key={j}>{inline(t)}</li>)}</Lista>);
      continue;
    }
    if (l.startsWith('>')) {
      const citacao: string[] = [];
      while (i < linhas.length && linhas[i].startsWith('>')) citacao.push(linhas[i++].replace(/^>\s?/, ''));
      saida.push(<blockquote key={k++}>{inline(citacao.join(' '))}</blockquote>);
      continue;
    }
    const paragrafo: string[] = [];
    while (i < linhas.length && linhas[i].trim() !== '' && !/^(#{1,4}\s|```|\s*([-*•]|\d+[.)])\s+|>|\|)/.test(linhas[i])) paragrafo.push(linhas[i++]);
    if (paragrafo.length === 0) paragrafo.push(linhas[i++]);
    saida.push(<p key={k++}>{paragrafo.map((p, j) => <Fragment key={j}>{j > 0 && <br />}{inline(p, `p${j}`)}</Fragment>)}</p>);
  }
  return saida;
}
