import { Grid } from 'antd';

/**
 * Pontos de quebra do Ant Design 5 (largura mínima, px). Iguais aos de Row/Col (xs/sm/md/lg/xl/xxl) e aos do CSS
 * global (src/estilos/global.css) — não os altere num sítio sem alterar no outro.
 */
export const PONTOS_QUEBRA = { sm: 576, md: 768, lg: 992, xl: 1200, xxl: 1600 } as const;
export type PontoQuebra = keyof typeof PONTOS_QUEBRA;
export type PontoQuebraActual = 'xs' | PontoQuebra;

export interface EstadoEcra {
  sm: boolean;
  md: boolean;
  lg: boolean;
  xl: boolean;
  xxl: boolean;
  /** maior ponto de quebra activo */
  actual: PontoQuebraActual;
  /** < 768 px (telemóvel) */
  telemovel: boolean;
  /** 768–991 px (tablet) */
  tablet: boolean;
  /** < 992 px: menu lateral em gaveta, layouts empilhados */
  pequeno: boolean;
}

/** Leitura síncrona (1.º render, antes de o Grid.useBreakpoint subscrever) para evitar um «salto» de layout. */
function correspondeAgora(ponto: PontoQuebra): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return true;
  return window.matchMedia(`(min-width: ${PONTOS_QUEBRA[ponto]}px)`).matches;
}

/**
 * Estado do ecrã por pontos de quebra (Grid.useBreakpoint do Ant Design, re-renderiza ao redimensionar).
 * Para decisões de layout em JS (gaveta vs. painel, colunas visíveis, tamanho da tabela). Para espaçamentos e
 * grelhas prefira Row/Col com xs/sm/md/lg ou as classes de src/estilos/global.css.
 */
export function useEcra(): EstadoEcra {
  const bp = Grid.useBreakpoint();
  const ler = (p: PontoQuebra) => bp[p] ?? correspondeAgora(p);
  const sm = ler('sm');
  const md = ler('md');
  const lg = ler('lg');
  const xl = ler('xl');
  const xxl = ler('xxl');
  const actual: PontoQuebraActual = xxl ? 'xxl' : xl ? 'xl' : lg ? 'lg' : md ? 'md' : sm ? 'sm' : 'xs';
  return { sm, md, lg, xl, xxl, actual, telemovel: !md, tablet: md && !lg, pequeno: !lg };
}

/**
 * `true` quando a largura é inferior ao ponto de quebra indicado (por omissão `md` = 768 px, telemóvel).
 * Ex.: `const pequeno = useEcraPequeno();` → `<Table size={pequeno ? 'small' : 'middle'} />`.
 * O layout principal usa `useEcraPequeno('lg')` para trocar o menu lateral pela gaveta.
 */
export function useEcraPequeno(abaixoDe: PontoQuebra = 'md'): boolean {
  const e = useEcra();
  return !e[abaixoDe];
}
