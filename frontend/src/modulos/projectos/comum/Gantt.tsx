import { Empty, Tooltip, Typography, theme } from 'antd';
import { useMemo } from 'react';
import { formatarData } from '@/utilitarios/formatacao';
import { barraGantt, escalaGantt, marcasGantt, posicaoHoje, type LinhaGantt } from './regras';

const CORES_ESTADO: Record<string, string> = { PENDENTE: '#94a3b8', EM_CURSO: '#3b82f6', CONCLUIDA: '#10b981', BLOQUEADA: '#ef4444' };
const ALTURA = 30;
const LARGURA_NOMES = 300;

/**
 * Gantt simples em CSS (sem dependências): uma linha por item, barra posicionada em % da escala, cabeçalho
 * por meses (ou semanas), marca de hoje e barra de progresso quando o item a tem. Sem fim = barra tracejada até ao fim.
 */
export function Gantt({ linhas, inicio, fim, aoClicar }: { linhas: LinhaGantt[]; inicio?: string | null; fim?: string | null; aoClicar?: (l: LinhaGantt) => void }) {
  const { token } = theme.useToken();
  const escala = useMemo(() => escalaGantt(linhas, inicio, fim), [linhas, inicio, fim]);
  const marcas = useMemo(() => marcasGantt(escala), [escala]);
  const hoje = posicaoHoje(escala);
  if (!linhas.length) return <Empty description="Sem tarefas com datas para mostrar" />;

  const corBarra = (l: LinhaGantt) =>
    l.tipo === 'projecto' ? token.colorPrimary : l.tipo === 'marco' ? '#8b5cf6' : CORES_ESTADO[l.estado ?? ''] ?? token.colorInfo;

  return (
    <div style={{ overflowX: 'auto', border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadius }}>
      <div style={{ minWidth: LARGURA_NOMES + 700 }}>
        <div style={{ display: 'flex', borderBottom: `1px solid ${token.colorBorderSecondary}`, background: token.colorFillAlter, position: 'sticky', top: 0 }}>
          <div style={{ width: LARGURA_NOMES, flex: 'none', padding: '6px 8px', fontWeight: 600 }}>Item</div>
          <div style={{ position: 'relative', flex: 1, height: 32 }}>
            {marcas.map((m, i) => (
              <div key={i} style={{ position: 'absolute', left: `${m.esquerda}%`, width: `${m.largura}%`, top: 0, bottom: 0, borderLeft: `1px solid ${token.colorBorderSecondary}`, fontSize: 12, padding: '8px 4px', overflow: 'hidden', whiteSpace: 'nowrap', textTransform: 'capitalize' }}>
                {m.rotulo}
              </div>
            ))}
          </div>
        </div>
        {linhas.map((l) => {
          const b = l.tipo === 'grupo' ? null : barraGantt(l, escala);
          const marco = l.tipo === 'marco' && l.inicio && l.inicio === l.fim;
          return (
            <div key={l.chave} style={{ display: 'flex', height: ALTURA, borderBottom: `1px solid ${token.colorSplit}`, background: l.tipo === 'grupo' ? token.colorFillQuaternary : undefined }}>
              <div
                style={{ width: LARGURA_NOMES, flex: 'none', padding: `0 8px 0 ${8 + l.nivel * 16}px`, display: 'flex', alignItems: 'center', overflow: 'hidden', cursor: aoClicar && l.id ? 'pointer' : undefined }}
                onClick={() => aoClicar && l.id && aoClicar(l)}
              >
                <Typography.Text ellipsis strong={l.tipo !== 'tarefa'} style={{ fontSize: 13 }}>
                  {l.rotulo ? <Typography.Text type="secondary" style={{ fontSize: 12 }}>{l.rotulo} · </Typography.Text> : null}
                  {l.nome}
                </Typography.Text>
              </div>
              <div style={{ position: 'relative', flex: 1 }}>
                {marcas.map((m, i) => <div key={i} style={{ position: 'absolute', left: `${m.esquerda}%`, top: 0, bottom: 0, borderLeft: `1px dashed ${token.colorSplit}` }} />)}
                {hoje !== null && <div style={{ position: 'absolute', left: `${hoje}%`, top: 0, bottom: 0, borderLeft: `2px solid ${token.colorError}`, opacity: 0.5 }} title="Hoje" />}
                {b && (
                  <Tooltip title={<>{l.nome}<br />{formatarData(l.inicio)} → {l.fim ? formatarData(l.fim) : 'sem fim'}{l.progresso !== undefined ? ` · ${l.progresso}%` : ''}</>}>
                    {marco ? (
                      <div style={{ position: 'absolute', left: `calc(${b.esquerda}% - 6px)`, top: 9, width: 12, height: 12, background: corBarra(l), transform: 'rotate(45deg)' }} />
                    ) : (
                      <div
                        style={{
                          position: 'absolute', left: `${b.esquerda}%`, width: `${b.largura}%`, minWidth: 4, top: l.tipo === 'projecto' ? 8 : 7, height: l.tipo === 'projecto' ? 14 : 16,
                          background: b.aberta ? 'transparent' : `${corBarra(l)}55`, border: `1px ${b.aberta ? 'dashed' : 'solid'} ${corBarra(l)}`, borderRadius: 3, overflow: 'hidden',
                        }}
                      >
                        {l.progresso !== undefined && l.progresso > 0 && <div style={{ width: `${Math.min(100, l.progresso)}%`, height: '100%', background: corBarra(l) }} />}
                      </div>
                    )}
                  </Tooltip>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
