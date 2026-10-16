import { Button, Empty, Tooltip, Typography, theme } from 'antd';
import { CaretDownOutlined, CaretRightOutlined } from '@ant-design/icons';
import { forwardRef, useMemo, useRef, useState } from 'react';
import { useEcra } from '@/componentes/responsivo';
import { formatarData } from '@/utilitarios/formatacao';
import { barraGantt, escalaGantt, marcasGantt, posicaoHoje, type CelulaQuantidade, type LinhaGantt } from './regras';
import { formatarQtd, marcasDaEscala, rotuloDependencia, type Escala } from './regrasMetas';

const CORES_ESTADO: Record<string, string> = { PENDENTE: '#94a3b8', EM_CURSO: '#3b82f6', CONCLUIDA: '#10b981', BLOQUEADA: '#ef4444' };
/**
 * Cores por nível (Gantt Global do legado, ui_projects.js renderBar: projecto #8b5cf6 «Purple for project», tarefas
 * #3b82f6). Usadas com `corPor="nivel"`; o Planeamento continua a pintar por estado (CORES_ESTADO).
 */
export const CORES_NIVEL_GANTT = { projecto: '#8b5cf6', tarefa: '#3b82f6' } as const;
const CORES_DEP: Record<string, string> = { FS: '#64748b', SS: '#0891b2', FF: '#9333ea' };
const CORES_QTD: Record<string, string> = { cumprido: '#15803d', aquem: '#b45309' };
const ALTURA = 30;
/** Com as quantidades por período, cada linha ganha uma faixa em baixo para os números. */
const ALTURA_COM_QTD = 46;
/** Centro vertical das barras das tarefas (top 7 + altura 16 / 2). */
const CENTRO_BARRA = 15;
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

/** Coluna à esquerda das barras (ex.: precedência, estrutura orgânica). Em telemóvel não se mostram. */
export interface ColunaGantt {
  titulo: string;
  largura?: number;
  valor: (l: LinhaGantt) => React.ReactNode;
}

interface Arrasto { chave: string; x0: number; y0: number; x: number; y: number }

/**
 * Gantt simples em CSS (sem dependências): uma linha por item, barra posicionada em % da escala, cabeçalho
 * por meses (ou semanas), marca de hoje e barra de progresso quando o item a tem. Sem fim = barra tracejada até ao fim.
 * Desloca na horizontal dentro do contentor (`erp-deslocar-x`); na impressão (ref → motor comum) as barras levam
 * `imp-cor` para saírem com cor mesmo sem «gráficos de fundo», cada linha é indivisível (`imp-sem-quebra`) e a linha
 * de cabeçalho (meses/semanas) leva `imp-repetir` — a paginação do motor repete-a no topo de cada página.
 * Versão Actual (ui_projects.js:868-922, 1700-2060; projectos_hierarquia.js:413-680): escala à escolha (dia…ano),
 * setas de precedência FS/SS/FF entre as barras, colunas extra (precedência, estrutura orgânica), árvore recolhível
 * (`fechadas` + `aoAlternar`), losango da data alvo das fases com início, quantidades por período (`quantidades`) e
 * ligação de tarefas arrastando a pega do fim da barra para outra barra (`aoLigar`); clicar numa seta pede para a
 * remover (`aoRemoverLigacao`). A alternativa por teclado à ligação por arrasto fica no ecrã que usa o Gantt.
 */
export const Gantt = forwardRef<HTMLDivElement, {
  linhas: LinhaGantt[];
  inicio?: string | null;
  fim?: string | null;
  aoClicar?: (l: LinhaGantt) => void;
  escala?: Escala;
  ligacoes?: LigacaoGantt[];
  /** Uma coluna extra (compatibilidade); `colunasExtra` aceita várias. */
  colunaExtra?: ColunaGantt;
  colunasExtra?: ColunaGantt[];
  fechadas?: Set<string>;
  aoAlternar?: (chave: string) => void;
  quantidades?: Map<string, { unidade: string; celulas: CelulaQuantidade[] }>;
  aoLigar?: (origem: LinhaGantt, destino: LinhaGantt) => void;
  aoRemoverLigacao?: (g: LigacaoGantt) => void;
  /**
   * Como se pintam as barras: `estado` (omissão — Planeamento: pendente/em curso/concluída/bloqueada) ou `nivel`
   * (Gantt Global do legado: projecto roxo, fases/tarefas azuis).
   */
  corPor?: 'estado' | 'nivel';
}>(function Gantt({ linhas, inicio, fim, aoClicar, escala: escalaEscolhida, ligacoes, colunaExtra, colunasExtra, fechadas, aoAlternar, quantidades, aoLigar, aoRemoverLigacao, corPor = 'estado' }, ref) {
  const { token } = theme.useToken();
  const { telemovel } = useEcra();
  const LARGURA_NOMES_ACTUAL = telemovel ? LARGURA_NOMES_TELEMOVEL : LARGURA_NOMES;
  const colunas = telemovel ? [] : [...(colunasExtra ?? []), ...(colunaExtra ? [colunaExtra] : [])];
  const LARGURA_EXTRA = colunas.reduce((s, c) => s + (c.largura ?? 150), 0);
  const altura = quantidades ? ALTURA_COM_QTD : ALTURA;
  const refLinhas = useRef<HTMLDivElement>(null);
  const [arrasto, setArrasto] = useState<Arrasto | null>(null);
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
  const porChave = useMemo(() => new Map(linhas.map((l) => [l.chave, l])), [linhas]);
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
    corPor === 'nivel' ? (l.tipo === 'projecto' ? CORES_NIVEL_GANTT.projecto : CORES_NIVEL_GANTT.tarefa)
    : l.tipo === 'projecto' ? token.colorPrimary : l.tipo === 'marco' ? '#8b5cf6' : CORES_ESTADO[l.estado ?? ''] ?? token.colorInfo;
  const setas = (ligacoes ?? []).map((g) => {
    const a = posicoes.get(g.de);
    const b = posicoes.get(g.para);
    if (!a || !b) return null;
    const x1 = g.tipo === 'SS' ? a.ini : a.fim;
    const x2 = g.tipo === 'FF' ? b.fim : b.ini;
    return { ...g, x1, x2, y1: a.linha * altura + CENTRO_BARRA, y2: b.linha * altura + CENTRO_BARRA };
  }).filter((x): x is NonNullable<typeof x> => !!x);

  // ligação por arrasto: coordenadas relativas ao contentor das linhas
  const relativo = (e: React.PointerEvent) => {
    const r = refLinhas.current?.getBoundingClientRect();
    return { x: e.clientX - (r?.left ?? 0), y: e.clientY - (r?.top ?? 0) };
  };
  const iniciarArrasto = (l: LinhaGantt, e: React.PointerEvent<HTMLElement>) => {
    if (!aoLigar || (e.button ?? 0) > 0) return;
    e.preventDefault();
    e.stopPropagation();
    try { e.currentTarget.setPointerCapture?.(e.pointerId); } catch { /* sem captura: continua pelo contentor */ }
    const p = relativo(e);
    setArrasto({ chave: l.chave, x0: p.x, y0: p.y, x: p.x, y: p.y });
  };
  const moverArrasto = (e: React.PointerEvent) => {
    if (!arrasto) return;
    const p = relativo(e);
    setArrasto({ ...arrasto, x: p.x, y: p.y });
  };
  const terminarArrasto = (e: React.PointerEvent) => {
    if (!arrasto) return;
    const origem = porChave.get(arrasto.chave);
    setArrasto(null);
    const sob = typeof document.elementFromPoint === 'function' ? document.elementFromPoint(e.clientX, e.clientY) : null;
    const alvo = sob?.closest('[data-linha]') ?? (e.target as Element | null)?.closest?.('[data-linha]');
    const destino = alvo ? porChave.get(alvo.getAttribute('data-linha') ?? '') : undefined;
    if (origem && destino && destino.chave !== origem.chave && aoLigar) aoLigar(origem, destino);
  };

  const quantidadeTexto = (c: CelulaQuantidade) => (c.estado === 'futuro' ? formatarQtd(c.previsto, 1) : `${formatarQtd(c.realizado, 1)}/${formatarQtd(c.previsto, 1)}`);

  return (
    <div ref={ref} className="erp-deslocar-x erp-gantt" style={{ border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadius }}>
      <div style={{ minWidth: LARGURA_NOMES_ACTUAL + LARGURA_EXTRA + 700 }}>
        {/* imp-repetir: na impressão, a linha dos meses/semanas repete-se no topo de cada página (motor comum) */}
        <div className="erp-gantt-cabecalho imp-repetir imp-sem-quebra imp-cor" style={{ display: 'flex', borderBottom: `1px solid ${token.colorBorderSecondary}`, background: token.colorFillAlter, position: 'sticky', top: 0 }}>
          <div style={{ width: LARGURA_NOMES_ACTUAL, flex: 'none', padding: '6px 8px', fontWeight: 600 }}>Item</div>
          {colunas.map((c) => <div key={c.titulo} style={{ width: c.largura ?? 150, flex: 'none', padding: '6px 8px', fontWeight: 600 }}>{c.titulo}</div>)}
          <div style={{ position: 'relative', flex: 1, height: 32 }}>
            {marcas.map((m, i) => (
              <div key={i} style={{ position: 'absolute', left: `${m.esquerda}%`, width: `${m.largura}%`, top: 0, bottom: 0, borderLeft: `1px solid ${token.colorBorderSecondary}`, fontSize: 12, padding: '8px 4px', overflow: 'hidden', whiteSpace: 'nowrap', textTransform: 'capitalize' }}>
                {m.rotulo}
              </div>
            ))}
          </div>
        </div>
        <div ref={refLinhas} style={{ position: 'relative', userSelect: arrasto ? 'none' : undefined }}
          onPointerMove={arrasto ? moverArrasto : undefined} onPointerUp={arrasto ? terminarArrasto : undefined} onPointerCancel={() => setArrasto(null)}>
          {linhas.map((l) => {
            const b = l.tipo === 'grupo' ? null : barraGantt(l, escala);
            const marco = l.tipo === 'marco' && l.inicio && l.inicio === l.fim;
            const alvo = l.alvo ? barraGantt({ inicio: l.alvo, fim: l.alvo }, escala) : null;
            const qtd = quantidades?.get(l.chave);
            const fechada = !!fechadas?.has(l.chave);
            const podeLigar = !!aoLigar && l.tipo === 'tarefa' && !!l.id;
            const destinoActivo = !!arrasto && arrasto.chave !== l.chave && l.tipo === 'tarefa';
            return (
              <div key={l.chave} data-linha={l.chave} className="erp-gantt-linha imp-sem-quebra"
                style={{ display: 'flex', height: altura, borderBottom: `1px solid ${token.colorSplit}`, background: destinoActivo ? token.colorPrimaryBg : l.tipo === 'grupo' ? token.colorFillQuaternary : undefined }}>
                <div
                  style={{ width: LARGURA_NOMES_ACTUAL, flex: 'none', padding: `0 8px 0 ${(aoAlternar ? 2 : 8) + l.nivel * 16}px`, display: 'flex', alignItems: 'center', overflow: 'hidden', cursor: aoClicar && l.id ? 'pointer' : undefined }}
                  onClick={() => aoClicar && l.id && aoClicar(l)}
                >
                  {aoAlternar && (l.filhos ? (
                    <Button type="text" size="small" className="imp-nao-imprimir" style={{ width: 20, minWidth: 20, padding: 0, flex: 'none' }}
                      icon={fechada ? <CaretRightOutlined /> : <CaretDownOutlined />} aria-expanded={!fechada}
                      aria-label={`${fechada ? 'Expandir' : 'Recolher'} «${l.nome}»`}
                      onClick={(e) => { e.stopPropagation(); aoAlternar(l.chave); }} />
                  ) : <span style={{ width: 20, flex: 'none' }} />)}
                  <Typography.Text ellipsis strong={l.tipo !== 'tarefa'} style={{ fontSize: 13 }}>
                    {l.rotulo ? <Typography.Text type="secondary" style={{ fontSize: 12 }}>{l.rotulo} · </Typography.Text> : null}
                    {l.nome}
                    {/* fase: % de execução junto ao nome (linhaFase, ui_projects.js:2000-2004 — «Aluguer de casa 88%») */}
                    {l.tipo === 'marco' && l.chave !== 'm-sem' && l.progresso !== undefined && (
                      <span className="imp-cor" style={{ marginLeft: 6, fontSize: 12, fontWeight: 700, color: '#059669' }}>{Math.round(l.progresso)}%</span>
                    )}
                  </Typography.Text>
                </div>
                {colunas.map((c) => (
                  <div key={c.titulo} style={{ width: c.largura ?? 150, flex: 'none', padding: '0 8px', display: 'flex', alignItems: 'center', overflow: 'hidden', fontSize: 12 }}>
                    {c.valor(l)}
                  </div>
                ))}
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
                  {alvo && (
                    <div className="imp-cor" data-alvo={l.chave} title={`Data alvo: ${formatarData(l.alvo ?? null)}`}
                      style={{ position: 'absolute', left: `calc(${alvo.esquerda + alvo.largura / 2}% - 6px)`, top: 9, width: 12, height: 12, background: '#ef4444', transform: 'rotate(45deg)' }} />
                  )}
                  {b && podeLigar && (
                    <span
                      role="presentation"
                      className="imp-nao-imprimir erp-gantt-pega"
                      data-pega={l.chave}
                      title="Arraste até outra tarefa para criar uma precedência"
                      onPointerDown={(e) => iniciarArrasto(l, e)}
                      style={{ position: 'absolute', left: `calc(${b.esquerda + b.largura}% - 3px)`, top: 10, width: 10, height: 10, borderRadius: '50%', background: token.colorBgContainer,
                        border: `2px solid ${corBarra(l)}`, cursor: 'crosshair', touchAction: 'none', zIndex: 2 }}
                    />
                  )}
                  {qtd?.celulas.map((c, i) => (
                    <div key={i} className="imp-cor" data-qtd={l.chave} data-estado={c.estado}
                      title={`${c.periodo}: ${c.estado === 'futuro' ? 'previsto' : `executado ${formatarQtd(c.realizado)} de`} ${formatarQtd(c.previsto)} ${qtd.unidade}${c.estado === 'aquem' ? ' — aquém do ritmo previsto' : ''}`}
                      style={{ position: 'absolute', left: `${c.esquerda}%`, width: `${c.largura}%`, top: ALTURA, height: ALTURA_COM_QTD - ALTURA - 2, fontSize: 10, lineHeight: '14px', textAlign: 'center',
                        overflow: 'hidden', whiteSpace: 'nowrap', color: CORES_QTD[c.estado] ?? token.colorTextTertiary, fontWeight: c.estado === 'aquem' ? 600 : undefined,
                        background: c.estado === 'aquem' ? '#fef3c7' : undefined, borderRadius: 2 }}>
                      {quantidadeTexto(c)}
                    </div>
                  ))}
                </div>
              </div>
            );
          })}
          {setas.length > 0 && (
            <div aria-hidden className="imp-cor" style={{ position: 'absolute', top: 0, bottom: 0, left: LARGURA_NOMES_ACTUAL + LARGURA_EXTRA, right: 0, pointerEvents: 'none' }}>
              <svg width="100%" height={linhas.length * altura} style={{ overflow: 'visible', display: 'block' }} data-testid="gantt-precedencias">
                <defs>
                  {Object.entries(CORES_DEP).map(([t, c]) => (
                    <marker key={t} id={`seta-${t}`} markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8 z" fill={c} /></marker>
                  ))}
                </defs>
                {setas.map((s, i) => {
                  const cor = CORES_DEP[s.tipo] ?? CORES_DEP.FS;
                  const remover = aoRemoverLigacao ? () => aoRemoverLigacao({ de: s.de, para: s.para, tipo: s.tipo, desf: s.desf }) : undefined;
                  const clicavel = remover ? { pointerEvents: 'stroke' as const, cursor: 'pointer' } : undefined;
                  return (
                    <g key={i} data-ligacao={`${s.de}>${s.para}`} onClick={remover}>
                      <title>{`${rotuloDependencia(s.tipo, s.desf)}${remover ? ' — clique para remover' : ''}`}</title>
                      <line x1={`${s.x1}%`} y1={s.y1} x2={`${s.x1}%`} y2={s.y2} stroke={cor} strokeWidth={1.2} strokeDasharray={s.tipo === 'FS' ? undefined : '3 2'} />
                      <line x1={`${s.x1}%`} y1={s.y2} x2={`${s.x2}%`} y2={s.y2} stroke={cor} strokeWidth={1.2} markerEnd={`url(#seta-${s.tipo in CORES_DEP ? s.tipo : 'FS'})`} />
                      {remover && (
                        <>
                          <line className="imp-nao-imprimir" x1={`${s.x1}%`} y1={s.y1} x2={`${s.x1}%`} y2={s.y2} stroke="transparent" strokeWidth={8} style={clicavel} />
                          <line className="imp-nao-imprimir" x1={`${s.x1}%`} y1={s.y2} x2={`${s.x2}%`} y2={s.y2} stroke="transparent" strokeWidth={8} style={clicavel} />
                        </>
                      )}
                    </g>
                  );
                })}
              </svg>
            </div>
          )}
          {arrasto && (
            <svg aria-hidden data-testid="gantt-ligacao-temporaria" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', pointerEvents: 'none', overflow: 'visible' }}>
              <line x1={arrasto.x0} y1={arrasto.y0} x2={arrasto.x} y2={arrasto.y} stroke={token.colorPrimary} strokeWidth={2} strokeDasharray="4 3" />
            </svg>
          )}
        </div>
      </div>
    </div>
  );
});
