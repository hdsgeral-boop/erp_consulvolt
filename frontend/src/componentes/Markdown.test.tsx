import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import { Markdown } from './Markdown';

function html(texto: string) {
  return render(<Markdown texto={texto} />).container;
}

describe('Markdown', () => {
  it('desenha títulos, listas, citações, código e tabelas', () => {
    const c = html('# Título\n\n- um\n- **dois**\n\n1. a\n2. b\n\n> citação\n\n```\nlinha 1\n```\n\n| A | B |\n|---|---|\n| 1 | 2 |');
    expect(c.querySelector('h3')?.textContent).toBe('Título');
    expect(c.querySelectorAll('ul li')).toHaveLength(2);
    expect(c.querySelector('ul li strong')?.textContent).toBe('dois');
    expect(c.querySelectorAll('ol li')).toHaveLength(2);
    expect(c.querySelector('blockquote')?.textContent).toBe('citação');
    expect(c.querySelector('pre code')?.textContent).toBe('linha 1');
    expect(c.querySelectorAll('th')).toHaveLength(2);
    expect(c.querySelector('td')?.textContent).toBe('1');
  });

  it('negrito, itálico e código em linha', () => {
    const c = html('Isto é **forte**, *inclinado* e `código`.');
    expect(c.querySelector('strong')?.textContent).toBe('forte');
    expect(c.querySelector('em')?.textContent).toBe('inclinado');
    expect(c.querySelector('code')?.textContent).toBe('código');
  });

  it('HTML cru nunca é interpretado: aparece como texto', () => {
    const c = html('<script>window.__pwned = 1</script><img src=x onerror="window.__pwned=1"><b>x</b>');
    expect(c.querySelector('script')).toBeNull();
    expect(c.querySelector('img')).toBeNull();
    expect(c.querySelector('b')).toBeNull();
    expect(c.textContent).toContain('<script>');
    expect((window as unknown as { __pwned?: number }).__pwned).toBeUndefined();
  });

  it('HTML dentro de código, títulos, listas e tabelas continua inerte', () => {
    const c = html('# <img src=x onerror=alert(1)>\n\n- <script>x</script>\n\n```\n<iframe src="javascript:alert(1)"></iframe>\n```\n\n| <svg onload=alert(1)> |\n|---|\n| <a href="javascript:x">y</a> |');
    expect(c.querySelector('img, script, iframe, svg, a')).toBeNull();
  });

  it('só liga http(s) e com rel seguro; javascript:, data: e vbscript: ficam como texto', () => {
    const c = html('[bom](https://exemplo.pt/a) [mau](javascript:alert(1)) [dados](data:text/html;base64,AAAA) [vb](vbscript:x) [rel](/interno)');
    const ligacoes = c.querySelectorAll('a');
    expect(ligacoes).toHaveLength(1);
    expect(ligacoes[0].getAttribute('href')).toBe('https://exemplo.pt/a');
    expect(ligacoes[0].getAttribute('target')).toBe('_blank');
    expect(ligacoes[0].getAttribute('rel')).toBe('noopener noreferrer');
    expect(c.textContent).toContain('[mau](javascript:alert(1))');
  });

  it('texto vazio ou só espaços não gera blocos nem falha', () => {
    expect(html('').querySelector('.erp-markdown')?.children).toHaveLength(0);
    expect(html('  \n\n ').querySelector('.erp-markdown')?.children).toHaveLength(0);
  });

  it('o conteúdo gerado só tem elementos do subconjunto (sem dangerouslySetInnerHTML)', () => {
    const c = html('# a\n\ntexto **b** [c](http://x.pt)\n\n- d\n\n> e\n\n| f |\n|---|\n| g |');
    const permitidos = new Set(['DIV', 'H3', 'P', 'STRONG', 'A', 'UL', 'LI', 'BLOCKQUOTE', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'BR']);
    c.querySelectorAll('*').forEach((e) => expect(permitidos.has(e.tagName)).toBe(true));
  });
});
