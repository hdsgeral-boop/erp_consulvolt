import { Tooltip } from 'antd';
import { CORES_SEMAFORO, ROTULOS_SEMAFORO, avaliarSemaforo } from './regraSemaforo';
import type { IndicadoresSemaforo } from './tipos';

/** Círculo do semáforo da carteira (fa-circle do legado) com a explicação na dica; regra em semaforo.ts. */
export function Semaforo({ estado, indicadores }: { estado: string | null | undefined; indicadores: IndicadoresSemaforo | null | undefined }) {
  const { cor, motivos } = avaliarSemaforo(estado, indicadores);
  const titulo = (
    <>
      <strong>{ROTULOS_SEMAFORO[cor]}</strong>
      {motivos.map((m) => <div key={m}>{m}</div>)}
    </>
  );
  return (
    <Tooltip title={titulo}>
      <span role="img" aria-label={`${ROTULOS_SEMAFORO[cor]}. ${motivos.join(' ')}`} data-cor={cor}
        style={{ display: 'inline-block', width: 14, height: 14, borderRadius: '50%', background: CORES_SEMAFORO[cor], verticalAlign: 'middle' }} />
    </Tooltip>
  );
}

/** Cabeçalho da coluna (fa-traffic-light do legado): semáforo desenhado em SVG, anunciado como «Semáforo». */
export function CabecalhoSemaforo() {
  return (
    <Tooltip title="Semáforo: prazo e orçamento (passe o rato sobre a cor para ver o motivo)">
      <svg viewBox="0 0 10 20" width="0.6em" height="1.2em" role="img" aria-label="Semáforo" style={{ verticalAlign: '-0.2em' }}>
        <rect x="0.5" y="0.5" width="9" height="19" rx="2.5" fill="none" stroke="currentColor" />
        <circle cx="5" cy="4.6" r="2.2" fill="currentColor" />
        <circle cx="5" cy="10" r="2.2" fill="currentColor" />
        <circle cx="5" cy="15.4" r="2.2" fill="currentColor" />
      </svg>
    </Tooltip>
  );
}
