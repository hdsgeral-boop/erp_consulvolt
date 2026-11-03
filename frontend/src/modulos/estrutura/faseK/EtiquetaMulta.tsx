import { Tag, Tooltip } from 'antd';
import { ESTADO_MULTA } from './regras';
import type { EstadoMulta } from './tipos';

/** Etiqueta da multa já proposta para um atraso (K3): «multa proposta / aplicada / dispensada / descontada». */
export function EtiquetaMulta({ multa }: { multa: { id: number; estado: EstadoMulta } }) {
  const e = ESTADO_MULTA[multa.estado];
  return <Tooltip title={e?.dica}><Tag color={e?.cor} style={{ marginInlineEnd: 0 }}>multa {e?.rotulo ?? multa.estado}</Tag></Tooltip>;
}
