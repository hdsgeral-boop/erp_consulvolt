import { Empty, Tooltip, Typography, theme } from 'antd';
import { forwardRef, useMemo } from 'react';
import { useEcra } from '@/componentes/responsivo';
import { formatarData } from '@/utilitarios/formatacao';
import { barraGantt, escalaGantt, marcasGantt, posicaoHoje, type LinhaGantt } from './regras';
import { marcasDaEscala, rotuloDependencia, type Escala } from './regrasMetas';

const CORES_ESTADO: Record<string, string> = { PENDENTE: '#94a3b8', EM_CURSO: '#3b82f6', CONCLUIDA: '#10b981', BLOQUEADA: '#ef4444' };
const CORES_DEP: Record<string, string> = { FS: '#64748b', SS: '#0891b2', FF: '#9333ea' };
const ALTURA = 30;
const LARGURA_NOMES = 300;
/** Em telemóvel a coluna dos nomes estreita (o gráfico desloca na horizontal dentro do próprio contentor). */
const LARGURA_NOMES_TELEMOVEL = 160;

/** Ligação de precedência entre duas linhas (chaves das linhas), desenhada em cotovelo (projDesenharPrecedencias). */
export interface LigacaoGantt {
  de: string;
  para: string;
  tipo: 'FS' | 'SS' | 'FF' | string;
  desf?: number;
}

/**
 * Gantt simples em CSS (sem dependências): uma linha por item, barra posicionada em % da escala, cabeçalho
 * por meses (ou semanas), marca de hoje e barra de progresso quando o item a tem. Sem fim = barra tracejada até ao fim.
 * Desloca na horizontal dentro do contentor (`erp-deslocar-x`); na impressão (ref → motor comum) as barras levam
 * `imp-cor` para saírem com cor mesmo sem «gráficos de fundo», cada linha é indivisível (`imp-sem-quebra`) e a linha
 * de cabeçalho (meses/semanas) leva `imp-repetir` — a paginação do motor repete-a no topo de cada página.
 * Versão Actual (ui_projects.js:868-922, 1700-2060; projectos_hierarquia.js:413-680): escala à escolha (dia…ano),
 * setas de precedência FS/SS/FF entre as barras e uma coluna extra (ex.: precedência ou estrutura orgânica).
 */
export const Gantt = forwardRef<HTMLDivElement, {
  linhas: LinhaGantt[];
  inicio?: string | null;
  fim?: string | null;
  aoClicar?: (l: LinhaGantt) => void;
  escala?: Escala;
  ligacoes?: LigacaoGantt[];
  colunaExtra?: { titulo: string; largura?: number; valor: (l: LinhaGantt) => React.ReactNode };
}>(function Gantt({ linhas, inicio, fim, aoClicar, escala: escalaEscolhida, ligacoes, colunaExtra }, ref) {
  const { token } = theme.useToken();
  const { telemovel } = useEcra();
  const LARGURA_NOMES_ACTUAL = telemovel ? LARGURA_NOMES_TELEMOVEL : LARGURA_NOMES;
  const LARGURA_EXTRA = colunaExtra && !telemovel ? colunaExtra.largura ?? 150 : 0;
  const escala = useMemo(() => escalaGantt(linhas, inicio, fim), [linhas, inicio, fim]);
  const marcas = useMemo(() => {
    if (!escalaEscolhida) return marcasGantt(escala);
    return marcasDaEscala(escala.inicio, escala.fim, escalaEscolhida).map((m) => {
      const a = Math.max(0, m.de.diff(escala.inicio, 'day'));
      const b = Math.min(escala.dias, m.ate.diff(escala.inicio, 'day') + 1);
      return { rotulo: m.rotulo, esquerda: (a / escala.dias) * 100, largura: (Math.max(0, b - a) / escala.dias) * 100 };
    }).filter((m) => m.largura > 0);
  }, [escala, escalaEscolhida]);
  const hoje = posicaoHoje(escala);
  // posição (em % da largura das barras) e linha de cada item, para as setas de precedência
  const posicoes = useMemo(() => {
    const mapa = new Map<string, { linha: number; ini: number; fim: number }>();
    linhas.forEach((l, i) => {
      const b = l.tipo === 'grupo' ? null : barraGantt(l, escala);
      if (b) mapa.set(l.chave, { linha: i, ini: b.esquerda, fim: b.esquerda + b.largura });
    });
    return mapa;
  }, [linhas, escala]);
  if (!linhas.length) return <Empty description="Sem tarefas com datas para mostrar" />;

  const corBarra = (l: LinhaGantt) =>
    l.tipo === 'projecto' ? token.colorPrimary : l.tipo === 'marco' ? '#8b5cf6' : CORES_ESTADO[l.estado ?? ''] ?? token.colorInfo;
  const setas = (ligacoes ?? []).map((g) => {
    const a = posicoes.get(g.de);
    const b = posicoes.get(g.para);
    if (!a || !b) return null;
    const x1 = g.tipo === 'SS' ? a.ini : a.fim;
    const x2 = g.tipo === 'FF' ? b.fim : b.ini;
    return { ...g, x1, x2, y1: a.linha * ALTURA + ALTURA / 2, y2: b.linha * ALTURA + ALTURA / 2 };
  }).filter((x): x is NonNullable<typeof x> => !!x);

  return (
    <div ref={ref} className="erp-deslocar-x erp-gantt" style={{ border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadius }}>
      <div style={{ minWidth: LARGURA_NOMES_ACTUAL + LARGURA_EXTRA + 700 }}>
        {/* imp-repetir: na impressão, a linha dos meses/semanas repete-se no topo de cada página (motor comum) */}
        <div className="erp-gantt-cabecalho imp-repetir imp-sem-quebra imp-cor" style={{ display: 'flex', borderBottom: `1px solid ${token.colorBorderSecondary}`, background: token.colorFillAlter, position: 'sticky', top: 0 }}>
          <div style={{ width: LARGURA_NOMES_ACTUAL, flex: 'none', padding: '6px 8px', fontWeight: 600 }}>Item</div>
          {LARGURA_EXTRA > 0 && <div style={{ width: LARGURA_EXTRA, flex: 'none', padding: '6px 8px', fontWeight: 600 }}>{colunaExtra?.titulo}</div>}
          <div style={{ position: 'relative', flex: 1, height: 32 }}>
            {marcas.map((m, i) => (
              <div key={i} style={{ position: 'absolute', left: `${m.esquerda}%`, width: `${m.largura}%`, top: 0, bottom: 0, borderLeft: `1px solid ${token.colorBorderSecondary}`, fontSize: 12, padding: '8px 4px', overflow: 'hidden', whiteSpace: 'nowrap', textTransform: 'capitalize' }}>
                {m.rotulo}
              </div>
            ))}
          </div>
        </div>
        <div style={{ position: 'relative' }}>
          {linhas.map((l) => {
            const b = l.tipo === 'grupo' ? null : barraGantt(l, escala);
            const marco = l.tipo === 'marco' && l.inicio && l.inicio === l.fim;
            return (
              <div key={l.chave} className="erp-gantt-linha imp-sem-quebra" style={{ display: 'flex', height: ALTURA, borderBottom: `1px solid ${token.colorSplit}`, background: l.tipo === 'grupo' ? token.colorFillQuaternary : undefined }}>
                <div
                  style={{ width: LARGURA_NOMES_ACTUAL, flex: 'none', padding: `0 8px 0 ${8 + l.nivel * 16}px`, display: 'flex', alignItems: 'center', overflow: 'hidden', cursor: aoClicar && l.id ? 'pointer' : undefined }}
                  onClick={() => aoClicar && l.id && aoClicar(l)}
                >
                  <Typography.Text ellipsis strong={l.tipo !== 'tarefa'} style={{ fontSize: 13 }}>
                    {l.rotulo ? <Typography.Text type="secondary" style={{ fontSize: 12 }}>{l.rotulo} · </Typography.Text> : null}
                    {l.nome}
                  </Typography.Text>
                </div>
                {LARGURA_EXTRA > 0 && (
                  <div style={{ width: LARGURA_EXTRA, flex: 'none', padding: '0 8px', display: 'flex', alignItems: 'center', overflow: 'hidden', fontSize: 12 }}>
                    {colunaExtra?.valor(l)}
                  </div>
                )}
                <div style={{ position: 'relative', flex: 1 }}>
                  {marcas.map((m, i) => <div key={i} style={{ position: 'absolute', left: `${m.esquerda}%`, top: 0, bottom: 0, borderLeft: `1px dashed ${token.colorSplit}` }} />)}
                  {hoje !== null && <div className="imp-cor" style={{ position: 'absolute', left: `${hoje}%`, top: 0, bottom: 0, borderLeft: `2px solid ${token.colorError}`, opacity: 0.5 }} title="Hoje" />}
                  {b && (
                    <Tooltip title={<>{l.nome}<br />{formatarData(l.inicio)} → {l.fim ? formatarData(l.fim) : 'sem fim'}{l.progresso !== undefined ? ` · ${l.progresso}%` : ''}</>}>
                      {marco ? (
                        <div className="imp-cor" style={{ position: 'absolute', left: `calc(${b.esquerda}% - 6px)`, top: 9, width: 12, height: 12, background: corBarra(l), transform: 'rotate(45deg)' }} />
                      ) : (
                        <div
                          className="imp-cor"
                          data-bar={l.chave}
                          style={{
                            position: 'absolute', left: `${b.esquerda}%`, width: `${b.largura}%`, minWidth: 4, top: l.tipo === 'projecto' ? 8 : 7, height: l.tipo === 'projecto' ? 14 : 16,
                            background: b.aberta ? 'transparent' : `${corBarra(l)}55`, border: `1px ${b.aberta ? 'dashed' : 'solid'} ${corBarra(l)}`, borderRadius: 3, overflow: 'hidden',
                          }}
                        >
                          {l.progresso !== undefined && l.progresso > 0 && <div className="imp-cor" style={{ width: `${Math.min(100, l.progresso)}%`, height: '100%', background: corBarra(l) }} />}
                        </div>
                      )}
                    </Tooltip>
                  )}
                </div>
              </div>
            );
          })}
          {setas.length > 0 && (
            <div aria-hidden className="imp-cor" style={{ position: 'absolute', top: 0, bottom: 0, left: LARGURA_NOMES_ACTUAL + LARGURA_EXTRA, right: 0, pointerEvents: 'none' }}>
              <svg width="100%" height={linhas.length * ALTURA} style={{ overflow: 'visible', display: 'block' }} data-testid="gantt-precedencias">
                <defs>
                  {Object.entries(CORES_DEP).map(([t, c]) => (
                    <marker key={t} id={`seta-${t}`} markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8 z" fill={c} /></marker>
                  ))}
                </defs>
                {setas.map((s, i) => {
                  const cor = CORES_DEP[s.tipo] ?? CORES_DEP.FS;
                  return (
                    <g key={i}>
                      <title>{rotuloDependencia(s.tipo, s.desf)}</title>
                      <line x1={`${s.x1}%`} y1={s.y1} x2={`${s.x1}%`} y2={s.y2} stroke={cor} strokeWidth={1.2} strokeDasharray={s.tipo === 'FS' ? undefined : '3 2'} />
                      <line x1={`${s.x1}%`} y1={s.y2} x2={`${s.x2}%`} y2={s.y2} stroke={cor} strokeWidth={1.2} markerEnd={`url(#seta-${s.tipo in CORES_DEP ? s.tipo : 'FS'})`} />
                    </g>
                  );
                })}
              </svg>
            </div>
          )}
        </div>
      </div>
    </div>
  );
});
