import { useEffect, type CSSProperties, type ReactNode } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import type { Dispositivo } from '../comum/frente';

/** Dimensões do emulador de dispositivo do legado (css/styles.css:1868-1922). */
const MOLDURA: Record<Dispositivo, CSSProperties> = {
  pc: { width: '100%', maxWidth: '100%', minHeight: 'calc(100vh - 60px)' },
  tablet: { width: 1024, maxWidth: '100%', height: 768, maxHeight: 'calc(100vh - 124px)', border: '16px solid #1e293b', borderRadius: 28, boxShadow: '0 25px 50px -12px rgba(0,0,0,0.5)', overflowY: 'auto' },
  mobile: { width: 412, maxWidth: '100%', height: 844, maxHeight: 'calc(100vh - 124px)', border: '16px solid #1e293b', borderRadius: 36, boxShadow: '0 25px 50px -12px rgba(0,0,0,0.5)', overflowY: 'auto' },
};

const DISPOSITIVOS: { valor: Dispositivo; rotulo: string; icone: string }[] = [
  { valor: 'pc', rotulo: 'PC', icone: 'desktop' },
  { valor: 'tablet', rotulo: 'Tablet', icone: 'tablet-alt' },
  { valor: 'mobile', rotulo: 'Mobile', icone: 'mobile-alt' },
];

/** Entra/sai do ecrã inteiro do navegador quando a API existe (pedido feito no clique do utilizador). */
export function pedirEcraInteiro(): void {
  try {
    const el = document.documentElement as HTMLElement & { requestFullscreen?: () => Promise<void> };
    if (!document.fullscreenElement && typeof el.requestFullscreen === 'function') void el.requestFullscreen().catch(() => undefined);
  } catch {
    /* sem Fullscreen API: fica a camada fixa a ocupar a janela */
  }
}

export function sairEcraInteiro(): void {
  try {
    if (document.fullscreenElement && typeof document.exitFullscreen === 'function') void document.exitFullscreen().catch(() => undefined);
  } catch {
    /* nada a fazer */
  }
}

/**
 * Ambiente imersivo da frente de caixa (renderExclusivePOSLayout, js/ui_sales.js:9235-9287): camada fixa a ocupar a
 * janela (por cima do menu, da barra superior e do cabeçalho do módulo), barra escura «Frente de Caixa (POS) · Ambiente
 * Imersivo Dedicado», selector PC / Tablet / Mobile (muda a largura e a disposição) e «← Voltar ao Office ERP».
 * Fica abaixo dos modais e listas do Ant Design (z-index 1000+), para a cobrança, o fecho Z, etc. continuarem visíveis.
 */
export function ModoImersivo({ dispositivo, aoMudarDispositivo, aoSair, children }: { dispositivo: Dispositivo; aoMudarDispositivo: (d: Dispositivo) => void; aoSair: () => void; children: ReactNode }) {
  useEffect(() => {
    const antes = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = antes;
      sairEcraInteiro();
    };
  }, []);

  return (
    <div className="pos-imersivo" role="region" aria-label="Frente de Caixa (POS) — ambiente imersivo" style={{ position: 'fixed', inset: 0, zIndex: 990, display: 'flex', flexDirection: 'column', background: '#0f172a' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', padding: '0.75rem 1.5rem', minHeight: 60, background: '#1e293b', color: '#fff', borderBottom: '1px solid #334155' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <IconeFa nome="cash-register" style={{ color: '#10b981', fontSize: '1.4rem' }} />
          <div>
            <h3 style={{ margin: 0, fontSize: '1.1rem', fontWeight: 700, lineHeight: 1.2, color: '#fff' }}>Frente de Caixa (POS)</h3>
            <span style={{ fontSize: '0.75rem', color: '#94a3b8' }}>Ambiente Imersivo Dedicado</span>
          </div>
        </div>
        <div role="group" aria-label="Dispositivo" style={{ display: 'flex', background: '#0f172a', padding: 4, borderRadius: 10, border: '1px solid #334155', gap: 4 }}>
          {DISPOSITIVOS.map((d) => {
            const activo = d.valor === dispositivo;
            return (
              <button
                key={d.valor}
                type="button"
                aria-pressed={activo}
                onClick={() => aoMudarDispositivo(d.valor)}
                style={{ background: activo ? '#2563eb' : 'transparent', color: activo ? '#fff' : '#94a3b8', border: 'none', padding: '6px 14px', borderRadius: 6, fontSize: '0.8rem', fontWeight: 600, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6, boxShadow: activo ? '0 4px 6px -1px rgba(37,99,235,0.3)' : 'none' }}
              >
                <IconeFa nome={d.icone} /> {d.rotulo}
              </button>
            );
          })}
        </div>
        <button
          type="button"
          onClick={aoSair}
          style={{ background: '#ef444415', color: '#f87171', border: '1px solid #ef444430', padding: '8px 16px', borderRadius: 10, fontWeight: 700, fontSize: '0.85rem', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <IconeFa nome="arrow-left" /> Voltar ao Office ERP
        </button>
      </div>
      <div style={{ flex: 1, display: 'flex', justifyContent: 'center', alignItems: dispositivo === 'pc' ? 'stretch' : 'flex-start', overflowY: 'auto', padding: dispositivo === 'pc' ? 0 : '2rem' }}>
        <div className={`pos-imersivo-corpo modo-${dispositivo}`} style={{ background: '#f8fafc', display: 'flex', flexDirection: 'column', ...MOLDURA[dispositivo] }}>
          <div style={{ padding: dispositivo === 'mobile' ? '0.75rem' : '1.5rem', flex: 1, display: 'flex', flexDirection: 'column', width: '100%' }}>{children}</div>
        </div>
      </div>
    </div>
  );
}
