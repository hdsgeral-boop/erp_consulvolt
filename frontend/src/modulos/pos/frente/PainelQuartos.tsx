import { Spin } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Quarto } from '../hotelaria/tipos';
import { contagemQuartos, estadoQuarto } from './quartos';
import { useCoresPos } from '../comum/cores';

/** Mapa de quartos com a ocupação (GET /pos/hotelaria/quartos), partilhado pela frente de caixa e pelo ecrã da hotelaria. */
export function useQuartos(terminalId: number | undefined, activo = true) {
  return useQuery({ queryKey: ['pos', 'hotelaria', 'quartos', terminalId], queryFn: () => obter<Quarto[]>('/pos/hotelaria/quartos', { terminal_pos_id: terminalId }), enabled: activo, refetchInterval: 60_000 });
}

const corPrimaria = '#2563eb';

/**
 * «Mapa de Quartos / Hospedagem em Aberto» do legado dentro da venda (pos-tables-panel, js/ui_sales.js:6499-6539, decorado
 * por decorarEcra, js/hotelaria.js:206-253): os quartos funcionam como as mesas; livre a verde, ocupado com o hóspede, a saída
 * prevista e o total (âmbar; vermelho com a saída atrasada); barra com as contagens e «Balcão (venda directa)».
 */
export function PainelQuartos({ quartos, carregando, erro, activo, aoEscolher, ocupado }: { quartos: Quarto[] | undefined; carregando?: boolean; erro?: boolean; activo: number | null; aoEscolher: (q: Quarto | null) => void; ocupado?: boolean }) {
  const cores = useCoresPos();
  const lista = quartos ?? [];
  const c = contagemQuartos(lista);
  return (
    <div id="pos-tables-panel" style={{ background: cores.fundo, border: `1px solid ${cores.borda}`, padding: '1.25rem', borderRadius: 4, boxShadow: '0 4px 6px -1px rgba(0,0,0,0.05)' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.85rem', gap: '0.5rem', flexWrap: 'wrap' }}>
        <h4 style={{ margin: 0, fontSize: '0.85rem', color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.05em', fontWeight: 700 }}>
          <IconeFa nome="door-open" style={{ color: '#10b981' }} /> Mapa de Quartos / Hospedagem em Aberto
        </h4>
        <div className="hotel-barra" style={{ display: 'flex', gap: '0.4rem', alignItems: 'center', flexWrap: 'wrap', fontSize: '0.75rem' }}>
          <span style={{ color: '#059669', fontWeight: 700 }}>{c.livres} livre(s)</span>
          <span style={{ color: '#b45309', fontWeight: 700 }}>{c.ocupados} ocupado(s)</span>
          {c.atrasados > 0 && <span style={{ color: '#b91c1c', fontWeight: 700 }}>{c.atrasados} com saída atrasada</span>}
          <button
            type="button"
            onClick={() => aoEscolher(null)}
            disabled={ocupado}
            aria-pressed={activo === null}
            style={{
              fontSize: '0.75rem',
              fontWeight: 600,
              padding: '0.2rem 0.6rem',
              borderRadius: 4,
              cursor: ocupado ? 'wait' : 'pointer',
              border: `1px solid ${activo === null ? corPrimaria : '#cbd5e1'}`,
              background: activo === null ? corPrimaria : cores.fundo,
              color: activo === null ? '#fff' : '#334155',
            }}
          >
            <IconeFa nome="cash-register" /> Balcão (venda directa)
          </button>
        </div>
      </div>
      {carregando ? (
        <Spin />
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: '0.75rem' }}>
          {lista.map((q) => {
            const e = estadoQuarto(q, activo);
            const s = q.estadia;
            const cor = e.situacao === 'ATRASADO' ? '#ef4444' : e.situacao === 'OCUPADO' ? '#f59e0b' : '#e2e8f0';
            const fundo = e.situacao === 'ATRASADO' ? 'rgba(239,68,68,0.05)' : e.situacao === 'OCUPADO' ? 'rgba(245,158,11,0.05)' : cores.fundo;
            return (
              <button
                key={q.produto_id}
                type="button"
                className="table-card"
                onClick={() => aoEscolher(q)}
                disabled={ocupado}
                aria-pressed={e.activo}
                style={{
                  padding: '0.75rem',
                  borderRadius: 4,
                  border: `2px solid ${e.activo ? corPrimaria : cor}`,
                  background: e.activo ? 'rgba(59,130,246,0.05)' : fundo,
                  cursor: ocupado ? 'wait' : 'pointer',
                  textAlign: 'center',
                  boxShadow: e.activo ? '0 4px 12px rgba(59,130,246,0.1)' : 'none',
                }}
              >
                <strong style={{ display: 'block', fontSize: '0.85rem', paddingTop: '0.25rem', color: e.activo ? corPrimaria : s ? '#b45309' : '#334155' }}>{q.nome}</strong>
                <span style={{ fontSize: '0.7rem', display: 'block', marginTop: 4, fontWeight: 600, color: !s ? '#059669' : s.atrasado ? '#b91c1c' : '#b45309' }}>
                  {s ? (
                    <>
                      <b>{s.nome_hospede || 'Ocupado'}</b>
                      <br />
                      saída {dayjs(s.saida_prevista_em).format('DD/MM HH:mm')}
                      {s.atrasado && (
                        <>
                          {' · '}
                          <b>atrasado</b>
                        </>
                      )}
                      <br />
                      {formatarKz(s.total_em_aberto)} Kz
                    </>
                  ) : (
                    'Livre'
                  )}
                </span>
                {Number(q.preco_por_dia) > 0 && <span style={{ fontSize: '0.65rem', display: 'block', marginTop: 2, color: '#94a3b8', fontWeight: 500 }}>diária: {formatarKz(q.preco_por_dia)} Kz</span>}
              </button>
            );
          })}
          {!lista.length && (
            <div style={{ gridColumn: '1/-1', color: erro ? '#b91c1c' : '#b45309', fontSize: '0.82rem' }}>
              <IconeFa nome="info-circle" />{' '}
              {erro ? 'Não foi possível carregar o mapa de quartos.' : 'Não há quartos. Crie-os em Vendas → Produtos, marcando «É quarto» e indicando os preços por hora e por diária e a conta de proveitos.'}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
