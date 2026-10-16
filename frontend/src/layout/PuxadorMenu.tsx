import { LARGURA_MENU_MAXIMA, LARGURA_MENU_MINIMA, type PropsPuxador } from './larguraMenu';

/**
 * Friso do bordo direito do menu lateral para lhe ajustar a largura (legado: .sidebar-resizer — 12 px de largura a
 * cavalo do bordo, linha fina e pega de 44 px a meio, azul ao passar o rato ou a arrastar). É um separador focável
 * (WAI-ARIA «window splitter»): anuncia a largura actual e os limites; ver larguraMenu.ts para as teclas.
 */
export function PuxadorMenu({ largura, puxador }: { largura: number; puxador: PropsPuxador }) {
  const { aArrastar, ...eventos } = puxador;
  return (
    <div
      className={`erp-menu-puxador${aArrastar ? ' a-arrastar' : ''}`}
      role="separator"
      aria-orientation="vertical"
      aria-label="Largura do menu"
      aria-valuenow={largura}
      aria-valuemin={LARGURA_MENU_MINIMA}
      aria-valuemax={LARGURA_MENU_MAXIMA}
      aria-valuetext={`${largura} píxeis`}
      tabIndex={0}
      title="Arraste para alargar ou estreitar o menu (duplo clique repõe a largura)"
      {...eventos}
    />
  );
}
