import type { MouseEvent } from 'react';

/**
 * «Saltar para o conteúdo» (WCAG 2.4.1, Ignorar blocos): primeiro elemento focável da página; só aparece ao receber o foco
 * por teclado e leva o foco ao <main> (o `Layout.Content` do ERP, ou o `main` das páginas públicas/portal), sem alterar a URL.
 */
export function SaltarParaConteudo() {
  const saltar = (e: MouseEvent<HTMLAnchorElement>) => {
    const alvo = document.querySelector<HTMLElement>('main, [role="main"]');
    if (!alvo) return;
    e.preventDefault();
    if (!alvo.hasAttribute('tabindex')) alvo.setAttribute('tabindex', '-1');
    alvo.focus();
    alvo.scrollIntoView?.({ block: 'start' });
  };
  return (
    <a className="erp-saltar" href="#conteudo-principal" onClick={saltar}>
      Saltar para o conteúdo
    </a>
  );
}
