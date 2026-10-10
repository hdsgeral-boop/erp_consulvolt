import type { PeriodoFerias, ResumoFerias } from '../api';
import { barraNoAno, corEstadoFerias, ESTADOS_FERIAS, rotuloEstadoFerias } from './regrasFerias';

const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
const fmt = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;

/**
 * «Mapa anual {ano}» do Programa de Férias (legado htmlGantt): uma faixa por colaborador com uma barra por período,
 * na cor do estado; linha laranja no dia de hoje; clicar numa barra abre o período.
 */
export function MapaAnualFerias({ ano, resumo, porColab, aoAbrir }: {
  ano: number;
  resumo: ResumoFerias[];
  porColab: Map<number, PeriodoFerias[]>;
  aoAbrir?: (p: PeriodoFerias) => void;
}) {
  const hoje = new Date();
  const hojePct = hoje.getFullYear() === ano
    ? ((Math.round((Date.UTC(ano, hoje.getMonth(), hoje.getDate()) - Date.UTC(ano, 0, 1)) / 86_400_000) + 0.5) / (Date.UTC(ano + 1, 0, 1) / 86_400_000 - Date.UTC(ano, 0, 1) / 86_400_000)) * 100
    : null;
  const grelha = { backgroundImage: 'linear-gradient(to right, #e2e8f0 1px, transparent 1px)', backgroundSize: 'calc(100% / 12) 100%' };
  return (
    <div>
      <h3 style={{ fontSize: '0.95rem', margin: '1.25rem 0 0.5rem' }}>Mapa anual {ano}</h3>
      <div style={{ border: '1px solid #e2e8f0', borderRadius: 4, overflowX: 'auto' }} role="figure" aria-label={`Mapa anual de férias ${ano}`}>
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(120px, 200px) 1fr', minWidth: 760, borderBottom: '1px solid #f1f5f9', background: '#f8fafc' }}>
          <div style={{ padding: '0.3rem 0.5rem', fontSize: '0.8rem', fontWeight: 600, color: '#475569', borderRight: '1px solid #e2e8f0' }}>Colaborador</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(12, 1fr)' }}>
            {MESES.map((m) => <div key={m} style={{ fontSize: '0.75rem', color: '#475569', textAlign: 'center', padding: '0.3rem 0', borderLeft: '1px solid #e2e8f0', fontWeight: 600 }}>{m}</div>)}
          </div>
        </div>
        {resumo.map((r) => (
          <div key={r.colaborador_id} style={{ display: 'grid', gridTemplateColumns: 'minmax(120px, 200px) 1fr', minWidth: 760, borderBottom: '1px solid #f1f5f9' }}>
            <div title={r.nome} style={{ padding: '0.3rem 0.5rem', fontSize: '0.8rem', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', borderRight: '1px solid #e2e8f0' }}>{r.nome}</div>
            <div style={{ position: 'relative', height: 26, ...grelha }}>
              {(porColab.get(r.colaborador_id) ?? []).map((p) => {
                const b = barraNoAno(p, ano);
                if (!b) return null;
                const rotulo = `${r.nome}: ${fmt(p.data_inicio)} a ${fmt(p.data_fim)} · ${p.dias} dias úteis · ${rotuloEstadoFerias(p.estado)}`;
                return (
                  <button key={p.id} type="button" title={rotulo} aria-label={rotulo} onClick={() => aoAbrir?.(p)}
                    style={{ position: 'absolute', top: 5, height: 16, left: `${b.esquerda}%`, width: `${b.largura}%`, minWidth: 3, borderRadius: 4, border: 'none', padding: 0,
                      background: corEstadoFerias(p.estado), opacity: p.estado === 'CANCELADO' ? 0.35 : 0.9, cursor: aoAbrir ? 'pointer' : 'default' }} />
                );
              })}
              {hojePct !== null && <div style={{ position: 'absolute', top: 0, bottom: 0, width: 2, background: '#f59e0b', left: `${hojePct}%`, pointerEvents: 'none' }} />}
            </div>
          </div>
        ))}
      </div>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.8rem', fontSize: '0.78rem', color: '#475569', marginTop: '0.5rem' }}>
        {ESTADOS_FERIAS.map((e) => <span key={e.k}><i style={{ display: 'inline-block', width: 12, height: 12, borderRadius: 2, verticalAlign: -2, marginRight: 4, background: e.cor }} />{e.r}</span>)}
        {hojePct !== null && <span><i style={{ display: 'inline-block', width: 3, height: 12, verticalAlign: -2, marginRight: 4, background: '#f59e0b' }} />Hoje</span>}
      </div>
    </div>
  );
}
