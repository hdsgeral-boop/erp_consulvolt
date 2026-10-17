import { LeftOutlined, RightOutlined } from '@ant-design/icons';
import { LARGURA_MENU_MAXIMA, LARGURA_MENU_MINIMA, type PropsPuxador } from './larguraMenu';

/**
 * Bordo direito do menu lateral:
 *  - friso para ajustar a largura (legado: .sidebar-resizer — 12 px a cavalo do bordo, linha fina, azul ao passar o rato
 *    ou a arrastar). É um separador focável (WAI-ARIA «window splitter») que anuncia a largura actual e os limites
 *    (ver larguraMenu.ts para as teclas e o duplo clique). Só existe com o menu expandido;
 *  - a meio do bordo, o botão redondo «Recolher menu» / «Expandir menu»: recolhido, o menu fica estreito só com os
 *    ícones de cada módulo (o nome aparece ao passar o rato e os sub-menus abrem ao lado); expandir volta à largura anterior.
 */
export function PuxadorMenu({
  largura,
  puxador,
  recolhido,
  aoAlternar,
  controla,
}: {
  largura: number;
  puxador: PropsPuxador;
  recolhido: boolean;
  aoAlternar: () => void;
  /** id do menu controlado pelo botão (aria-controls) */
  controla?: string;
}) {
  const { aArrastar, ...eventos } = puxador;
  const rotulo = recolhido ? 'Expandir menu' : 'Recolher menu';
  return (
    <>
      {!recolhido && (
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
      )}
      <button
        type="button"
        className="erp-menu-alternar"
        aria-label={rotulo}
        aria-expanded={!recolhido}
        aria-controls={controla}
        title={recolhido ? 'Expandir menu (mostrar os nomes)' : 'Recolher menu (só os ícones)'}
        onClick={aoAlternar}
        // o duplo clique no botão não chega ao friso (repor a largura)
        onDoubleClick={(e) => e.stopPropagation()}
      >
        {recolhido ? <RightOutlined aria-hidden /> : <LeftOutlined aria-hidden />}
      </button>
    </>
  );
}
