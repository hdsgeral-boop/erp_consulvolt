import type { CSSProperties, ReactNode } from 'react';

/**
 * Contentor que desloca na horizontal o que for mais largo do que o ecrã (gráficos com largura fixa, Gantt,
 * grelhas personalizadas, `<table>` HTML), sem alargar a página.
 *
 * ```tsx
 * <DeslocamentoHorizontal><Gantt … /></DeslocamentoHorizontal>
 * ```
 */
export function DeslocamentoHorizontal({ children, style, className }: { children: ReactNode; style?: CSSProperties; className?: string }) {
  return (
    <div className={['erp-deslocar-x', className].filter(Boolean).join(' ')} style={style}>
      {children}
    </div>
  );
}
