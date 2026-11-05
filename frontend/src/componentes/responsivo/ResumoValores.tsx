import type { CSSProperties, ReactNode } from 'react';

export interface ItemResumoValores {
  /** Chave estável; por omissão usa-se o rótulo. */
  chave?: string;
  rotulo: ReactNode;
  valor: ReactNode;
  /** Realça o bloco (ex.: «Valor a pagar»). */
  destaque?: boolean;
}

export interface ResumoValoresProps {
  itens: ItemResumoValores[];
  /** `compacto` ≈ 130 px mínimos por bloco; `largo` ≈ 200 px. Omissão ≈ 150 px. */
  densidade?: 'compacto' | 'normal' | 'largo';
  className?: string;
  style?: CSSProperties;
}

/**
 * Resumo de valores responsivo (rótulo sobre valor) em grelha `auto-fit`: cada bloco ocupa o espaço de que precisa,
 * os valores nunca partem a meio e, ao faltar largura, os blocos passam à linha seguinte (sem barra horizontal).
 * Substitui `<Descriptions bordered column={4}>` em resumos monetários.
 */
export function ResumoValores({ itens, densidade = 'normal', className, style }: ResumoValoresProps) {
  const classes = ['erp-resumo-valores', densidade !== 'normal' ? `erp-resumo-valores--${densidade}` : '', className ?? '']
    .filter(Boolean)
    .join(' ');
  return (
    <div className={classes} style={style} role="list">
      {itens.map((it, i) => (
        <div
          key={it.chave ?? (typeof it.rotulo === 'string' ? it.rotulo : i)}
          role="listitem"
          className={`erp-resumo-valores__item${it.destaque ? ' erp-resumo-valores__item--destaque' : ''}`}
        >
          <span className="erp-resumo-valores__rotulo">{it.rotulo}</span>
          <span className="erp-resumo-valores__valor">{it.valor}</span>
        </div>
      ))}
    </div>
  );
}
