import type { CSSProperties, ReactNode } from 'react';

/**
 * Contentor que desloca na horizontal o que for mais largo do que o ecrã (gráficos com largura fixa, Gantt,
 * grelhas personalizadas, `<table>` HTML), sem alargar a página.
 *

 * Tem `tabIndex=0` para quem usa só o teclado conseguir deslocar o conteúdo (WCAG 2.1.1).
 * ```tsx
 * <DeslocamentoHorizontal><Gantt … /></DeslocamentoHorizontal>
 * ```
 */
export function DeslocamentoHorizontal({ children, style, className }: { children: ReactNode; style?: CSSProperties; className?: string }) {
  return (
    <div className={['erp-deslocar-x', className].filter(Boolean).join(' ')} style={style} tabIndex={0}>
      {children}
    </div>
  );
}
