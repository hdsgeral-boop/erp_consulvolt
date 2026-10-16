import { theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import type { AquisicoesPendentes } from '../comum/tipos';

/** Há valor por inventariar (mais de 1 cêntimo), como o `hasDiff` do legado. */
export function haDiferenca(total: string | number | null | undefined): boolean {
  return Number(total ?? 0) > 0.01;
}

/**
 * Aviso «Diferença de Reconciliação Detectada» por cima do inventário (renderAssetList do legado): movimentos das classes
 * 11/12 ainda não inventariados; um clique abre as Aquisições Pendentes.
 */
export function AlertaReconciliacao() {
  const { token } = theme.useToken();
  const { pode } = useSessao();
  const navegar = useNavigate();
  const visivel = pode('activos_pendentes_view');
  const q = useQuery({
    queryKey: ['activos', 'aquisicoes-pendentes'],
    queryFn: () => obter<AquisicoesPendentes>('/ativos/aquisicoes-pendentes'),
    enabled: visivel,
    staleTime: 60_000,
  });
  const total = q.data?.total_por_inventariar;
  if (!visivel || !haDiferenca(total)) return null;
  const abrir = () => navegar('/m/activos/activos_pendentes');
  return (
    <div
      role="link"
      tabIndex={0}
      onClick={abrir}
      onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && abrir()}
      style={{ marginBottom: 24, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, flexWrap: 'wrap', cursor: 'pointer',
        background: token.colorWarningBg, border: `1px solid ${token.colorWarningBorder}`, borderLeft: `5px solid ${token.colorWarning}`, borderRadius: token.borderRadius, padding: '12px 16px' }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
        <div style={{ background: token.colorWarningBgHover, color: token.colorWarning, width: 40, height: 40, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '1.2rem' }}>
          <IconeFa nome="exclamation-triangle" />
        </div>
        <div>
          <div style={{ fontWeight: 700, color: token.colorWarningText }}>Diferença de Reconciliação Detectada</div>
          <div style={{ fontSize: '0.85rem', color: token.colorWarningTextHover }}>Existem movimentos contabilísticos (Classes 11/12) que ainda não foram totalmente inventariados.</div>
        </div>
      </div>
      <div style={{ textAlign: 'right', color: token.colorWarningTextHover }}>
        <div style={{ fontWeight: 700, fontSize: '1.1rem' }}>+ {formatarKz(total ?? '0')}</div>
        <div style={{ fontSize: '0.75rem', textTransform: 'uppercase', letterSpacing: 1 }}>Por Inventariar <IconeFa nome="arrow-right" /></div>
      </div>
    </div>
  );
}
