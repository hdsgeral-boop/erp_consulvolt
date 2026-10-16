import { theme } from 'antd';
import type { ReactNode } from 'react';

/** Raio e espessura do mostrador do legado (projectos_dashboard.js:247-259: viewBox 84, r = 34, stroke-width 8). */
const RAIO = 34;
const ESPESSURA = 8;
const CIRCUNFERENCIA = 2 * Math.PI * RAIO;

/** Valor limitado a 0–100 e o `stroke-dasharray` do arco, como no legado (`(cir × v / 100).toFixed(1) cir.toFixed(1)`). */
export function geometriaAnel(valor: number | string | null | undefined): { valor: number; tracejado: string } {
  const n = Number(valor);
  const v = Number.isFinite(n) ? Math.max(0, Math.min(100, n)) : 0;
  return { valor: v, tracejado: `${((CIRCUNFERENCIA * v) / 100).toFixed(1)} ${CIRCUNFERENCIA.toFixed(1)}` };
}

/**
 * Anel de progresso (mostrador SVG do legado, .pdash-gauge): 84 px, fundo do anel e texto pelos tokens do tema
 * (no tema claro são os do legado, #e2e8f0 e #1e293b; na tela nocturna seguem o escuro), arco na cor indicada a partir
 * das 12 horas, percentagem arredondada ao centro e, ao lado, a legenda e a nota.
 */
export function AnelProgresso({ valor, cor, legenda, nota, tamanho = 84 }: { valor: number | string | null | undefined; cor: string; legenda: string; nota?: ReactNode; tamanho?: number }) {
  const { token } = theme.useToken();
  const g = geometriaAnel(valor);
  const pct = Math.round(g.valor);
  return (
    // whiteSpace/letterSpacing: dentro do valor do CartaoKpi (nowrap, 800) a legenda e a nota quebram linha normalmente
    <div style={{ display: 'flex', alignItems: 'center', gap: '0.7rem', whiteSpace: 'normal', letterSpacing: 'normal', lineHeight: 1.35 }}>
      <svg viewBox="0 0 84 84" width={tamanho} height={tamanho} role="img" aria-label={`${legenda}: ${pct}%`} style={{ flex: `0 0 ${tamanho}px` }}>
        <circle cx="42" cy="42" r={RAIO} fill="none" stroke={token.colorBorderSecondary} strokeWidth={ESPESSURA} />
        <circle data-testid="anel-arco" cx="42" cy="42" r={RAIO} fill="none" stroke={cor} strokeWidth={ESPESSURA} strokeLinecap="butt"
          strokeDasharray={g.tracejado} transform="rotate(-90 42 42)" />
        <text x="42" y="46" textAnchor="middle" fontSize="19" fontWeight="700" fill={token.colorText}>{pct}%</text>
      </svg>
      <div style={{ minWidth: 0 }}>
        <div style={{ fontSize: '0.85rem', fontWeight: 700, color: token.colorText }}>{legenda}</div>
        {nota && <div style={{ fontSize: 12, fontWeight: 400, color: token.colorTextSecondary }}>{nota}</div>}
      </div>
    </div>
  );
}
