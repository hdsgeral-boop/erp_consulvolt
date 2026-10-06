import type { CSSProperties } from 'react';
import { ICONES_FA } from './iconesFa';

/**
 * Ícone do sistema anterior (Font Awesome 6.4 Free, que o legado carregava por CDN) desenhado em SVG inline, sem fontes
 * nem bibliotecas: o nome é o da versão 5 usado no legado (ex.: «hand-holding-usd», «check-double»). Nome desconhecido →
 * círculo vazio (nunca falha). Decorativo por omissão (aria-hidden); passe `rotulo` para ser anunciado.
 */
export function IconeFa({ nome, rotulo, style, className }: { nome: string | null | undefined; rotulo?: string; style?: CSSProperties; className?: string }) {
  const [largura, altura, caminho] = ICONES_FA[nome ?? ''] ?? ICONES_FA['circle-regular'];
  return (
    <svg
      className={['fx-icone', className].filter(Boolean).join(' ')}
      viewBox={`0 0 ${largura} ${altura}`}
      width={`${(largura / altura).toFixed(3)}em`}
      height="1em"
      fill="currentColor"
      style={{ display: 'inline-block', verticalAlign: '-0.125em', flex: 'none', ...style }}
      role={rotulo ? 'img' : undefined}
      aria-label={rotulo}
      aria-hidden={rotulo ? undefined : true}
      focusable="false"
    >
      <path d={caminho} />
    </svg>
  );
}

/** Existe desenho para o nome (testes e verificação do catálogo). */
export function temIconeFa(nome: string): boolean {
  return nome in ICONES_FA;
}
