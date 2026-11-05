import type { CSSProperties, ReactNode } from 'react';

export interface ValorMonetarioProps {
  /** Valor já formatado (ex.: `formatarNumero(x)`), ou um número (formatado em pt-AO com 2 decimais). */
  valor: number | string | null | undefined;
  moeda?: string;
  /** Sufixo, ex.: «/mês». */
  sufixo?: ReactNode;
  forte?: boolean;
  className?: string;
  style?: CSSProperties;
}

/** Formata um número em pt-AO (separador de milhares, 2 decimais). */
export function formatarValor(n: number): string {
  return new Intl.NumberFormat('pt-AO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
}

/** Valor monetário que nunca parte a meio (nowrap + algarismos tabulares). Moeda e sufixo ficam ligados ao número. */
export function ValorMonetario({ valor, moeda, sufixo, forte, className, style }: ValorMonetarioProps) {
  if (valor === null || valor === undefined || valor === '') return <span className="erp-valor">—</span>;
  const texto = typeof valor === 'number' ? formatarValor(valor) : valor;
  const Etiqueta = forte ? 'strong' : 'span';
  return (
    <Etiqueta className={`erp-valor${className ? ` ${className}` : ''}`} style={style}>
      {texto}
      {moeda ? ` ${moeda}` : ''}
      {sufixo ?? ''}
    </Etiqueta>
  );
}
