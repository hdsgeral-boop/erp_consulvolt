import { lazy, Suspense } from 'react';
import { useLocation } from 'react-router-dom';
import { Spin } from 'antd';

const Mapa = lazy(() => import('../Mapa'));
const MapaFiscal = lazy(() => import('../MapaFiscal'));

/** O caminho é o do «Mapa Fiscal» (/m/activos/activos_mapa/fiscal)? */
export function eRotaFiscal(caminho: string): boolean {
  return /\/activos_mapa\/fiscal\/?$/.test(caminho);
}

/**
 * Ecrã activos_mapa: o legado tem dois separadores com a mesma permissão — «Mapa de Amortizações» e «Mapa Fiscal»
 * (renderAmortizationMap / renderFiscalAssetMap). A sub-rota /fiscal mostra o segundo.
 */
export default function RotaMapa() {
  const { pathname } = useLocation();
  return (
    <Suspense fallback={<Spin style={{ display: 'block', margin: 48 }} />}>
      {eRotaFiscal(pathname) ? <MapaFiscal /> : <Mapa />}
    </Suspense>
  );
}
