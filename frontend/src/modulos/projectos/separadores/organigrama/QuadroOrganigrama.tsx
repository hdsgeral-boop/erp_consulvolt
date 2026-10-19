import { Avatar, Button, Dropdown, Flex, Segmented, Space, Tag, Tooltip, Typography, message, theme, type MenuProps } from 'antd';
import {
  CompressOutlined, ExpandOutlined, FileImageOutlined, FullscreenExitOutlined, FullscreenOutlined, MailOutlined, MinusOutlined, MoreOutlined, NodeCollapseOutlined,
  NodeExpandOutlined, PhoneOutlined, PlusOutlined,
} from '@ant-design/icons';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent as KE, type PointerEvent as PE } from 'react';
import { BotoesExportar } from '@/componentes/impressao';
import type { Membro, Posicao } from '../../comum/tipos';
import { antepassados, descendentes, disporArvore, escalaAjustada, filhosPorPai, iniciais, MEDIDAS, type NoEntrada, type Orientacao } from './layout';
import { CORES_HEX, descarregarPng, organigramaSvg, type CartaoSvg } from './svg';
import { CARTAO, medidorTexto, medirCartao, type CartaoMedido, type TextoAjustado } from './texto';
import { MIME_MEMBRO, type Destino, type PrevisaoDestino } from './moverMembro';

/** Membro com os dados dos cartões (contactos e cargo só chegam a quem pode vê-los — ver EquipaController@organigrama). */
export type MembroCartao = Membro & { telefone?: string | null; email?: string | null; cargo?: string | null };
export type PosicaoQuadro = Posicao & { disposicao?: string | null; tarefas?: number[] | null; membros: MembroCartao[] };

const CHAVE_ORIENTACAO = 'proj_org_orientacao';
const lerOrientacao = (): Orientacao => {
  try { return localStorage.getItem(CHAVE_ORIENTACAO) === 'horizontal' ? 'horizontal' : 'vertical'; } catch { return 'vertical'; }
};
const ZOOM_MIN = 0.2;
const ZOOM_MAX = 2;

/** Ocupantes ordenados: o responsável primeiro, depois pelo nome. */
export function ocupantes(p: Pick<Posicao, 'membros' | 'membro_responsavel_id'>): MembroCartao[] {
  return [...(p.membros as MembroCartao[])].sort((a, b) => Number(b.id === p.membro_responsavel_id) - Number(a.id === p.membro_responsavel_id) || a.nome.localeCompare(b.nome, 'pt'));
}

/** Texto das vagas como no legado («2/3 vaga(s) · 1 por preencher», «excedido», ou «N membro(s)» sem vagas). */
export function textoVagas(ocupadas: number, vagas: number | null | undefined): { texto: string; excesso: boolean; falta: boolean } {
  const v = Number(vagas ?? 0);
  if (!v) return { texto: `${ocupadas} membro(s)`, excesso: false, falta: false };
  return { texto: `${ocupadas}/${v} vaga(s)`, excesso: ocupadas > v, falta: ocupadas < v };
}

/**
 * Ronda 4 — arrastar membros entre posições (legado: ligarArrasto, projectos_organigrama.js:354-367). O estado do arrasto vive
 * no separador (também se larga na «Equipa sem posição»); o quadro só liga os cartões e mostra a pré-visualização do destino.
 */
export interface ArrastoMembros {
  activo: { membroId: number; de: Destino } | null;
  iniciar: (membroId: number, de: Destino) => void;
  terminar: () => void;
  largar: (para: Destino) => void;
  previsao: (para: Destino) => PrevisaoDestino | null;
}

/** O arrasto em curso é de um membro (e não de um ficheiro ou texto)? */
export const eArrastoMembro = (e: { dataTransfer: DataTransfer | null }): boolean => !!e.dataTransfer && Array.from(e.dataTransfer.types ?? []).includes(MIME_MEMBRO);

interface Props {
  posicoes: PosicaoQuadro[];
  titulo: string;
  /** Abrir o detalhe da posição (clique / Enter). */
  aoAbrir: (p: PosicaoQuadro) => void;
  /** Acções de gestão da posição (menu «⋯» do cartão); sem elas o cartão é só de consulta. */
  accoes?: (p: PosicaoQuadro) => MenuProps['items'];
  aoAccao?: (p: PosicaoQuadro, chave: string) => void;
  /** Arrastar membros entre posições (só para quem gere o projecto). */
  arrasto?: ArrastoMembros;
}

/**
 * Organigrama em esquema (projectos_organigrama.js:236-360): caixas e conectores, vertical ou horizontal (escolha
 * lembrada no navegador, como `proj_org_orientacao` do legado), ramos que se recolhem, zoom (botões, Ctrl+roda, teclas
 * + − 0), arrastar para mover, ajustar ao ecrã, realce do caminho até ao topo ao passar o rato ou com o foco,
 * impressão/PDF e PNG. Navegação por teclado nos cartões: ↑ superior, ↓ primeira subposição, ← recolher, → expandir,
 * Enter detalhe.
 */
export function QuadroOrganigrama({ posicoes, titulo, aoAbrir, accoes, aoAccao, arrasto }: Props) {
  const { token } = theme.useToken();
  const [orientacao, setOrientacao] = useState<Orientacao>(lerOrientacao);
  const [recolhidos, setRecolhidos] = useState<Set<number>>(new Set());
  const [zoom, setZoom] = useState(1);
  const [pan, setPan] = useState({ x: 0, y: 0 });
  const [realce, setRealce] = useState<number | null>(null);
  const [cheio, setCheio] = useState(false);
  /** Posição sobre a qual se está a arrastar um membro (pré-visualização do destino). */
  const [alvo, setAlvo] = useState<number | null>(null);
  useEffect(() => { if (!arrasto?.activo) setAlvo(null); }, [arrasto?.activo]);
  const area = useRef<HTMLDivElement>(null);
  const moldura = useRef<HTMLDivElement>(null);
  const arrastoFundo = useRef<{ x: number; y: number; px: number; py: number; moveu: boolean } | null>(null);

  const entradas = useMemo<NoEntrada[]>(() => posicoes.map((p) => ({ id: p.id, pai: p.no_pai_id, ordem: p.ordem, apoio: p.apoio, coluna: p.disposicao === 'COLUNA' })), [posicoes]);
  const filhos = useMemo(() => filhosPorPai(entradas), [entradas]);
  const pais = useMemo(() => new Map(entradas.map((n) => [n.id, n.pai !== null && posicoes.some((p) => p.id === n.pai) ? n.pai : null])), [entradas, posicoes]);
  // texto completo nos cartões (sem «…»): quebra em linhas e a altura de cada cartão entra na disposição
  const medidas = useMemo(() => {
    const medir = medidorTexto(token.fontFamily);
    return new Map<number, CartaoMedido>(posicoes.map((p) => [p.id, medirCartao({
      titulo: p.titulo, area: [p.area || (p.apoio ? '' : 'Posição'), p.apoio ? 'apoio' : ''].filter(Boolean).join(' · '),
      pessoas: ocupantes(p).map((m) => ({ nome: m.nome, cargo: m.cargo || m.papel })),
    }, medir)]));
  }, [posicoes, token.fontFamily]);
  const alturas = useMemo(() => new Map([...medidas].map(([id, m]) => [id, m.altura])), [medidas]);
  const disp = useMemo(() => disporArvore(entradas, orientacao, recolhidos, MEDIDAS, alturas), [entradas, orientacao, recolhidos, alturas]);
  const porId = useMemo(() => new Map(posicoes.map((p) => [p.id, p])), [posicoes]);
  const caminho = useMemo(() => (realce === null ? new Set<number>() : new Set([realce, ...antepassados(realce, pais)])), [realce, pais]);

  const ajustar = useCallback(() => {
    const el = area.current;
    if (!el || !disp.largura) return;
    const k = escalaAjustada(disp.largura, disp.altura, el.clientWidth, el.clientHeight);
    setZoom(k);
    setPan({ x: Math.max(0, (el.clientWidth - disp.largura * k) / 2), y: Math.max(0, Math.min(16, (el.clientHeight - disp.altura * k) / 2)) });
  }, [disp.largura, disp.altura]);
  // ajusta ao abrir, quando muda a orientação e quando entram/saem posições (recolher não reajusta)
  useLayoutEffect(() => { ajustar(); }, [orientacao, posicoes.length]); // eslint-disable-line react-hooks/exhaustive-deps

  const mudarZoom = useCallback((novo: number, cx?: number, cy?: number) => {
    const el = area.current;
    const k = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, Math.round(novo * 100) / 100));
    const ox = cx ?? (el ? el.clientWidth / 2 : 0);
    const oy = cy ?? (el ? el.clientHeight / 2 : 0);
    setPan((p) => ({ x: ox - ((ox - p.x) * k) / zoom, y: oy - ((oy - p.y) * k) / zoom }));
    setZoom(k);
  }, [zoom]);

  // Ctrl/⌘ + roda = zoom à volta do cursor (listener não passivo para impedir o zoom da página)
  useEffect(() => {
    const el = area.current;
    if (!el) return undefined;
    const roda = (e: WheelEvent) => {
      if (!e.ctrlKey && !e.metaKey) return;
      e.preventDefault();
      const r = el.getBoundingClientRect();
      mudarZoom(zoom * (e.deltaY < 0 ? 1.1 : 1 / 1.1), e.clientX - r.left, e.clientY - r.top);
    };
    el.addEventListener('wheel', roda, { passive: false });
    return () => el.removeEventListener('wheel', roda);
  }, [mudarZoom, zoom]);

  useEffect(() => {
    const sair = () => setCheio(document.fullscreenElement === moldura.current && !!moldura.current);
    document.addEventListener('fullscreenchange', sair);
    return () => document.removeEventListener('fullscreenchange', sair);
  }, []);
  useEffect(() => { const t = setTimeout(ajustar, 50); return () => clearTimeout(t); }, [cheio]); // eslint-disable-line react-hooks/exhaustive-deps

  const mudarOrientacao = (o: Orientacao) => {
    setOrientacao(o);
    try { localStorage.setItem(CHAVE_ORIENTACAO, o); } catch { /* sem armazenamento: fica só nesta sessão */ }
  };
  const alternar = (id: number, abrir?: boolean) => setRecolhidos((r) => {
    const n = new Set(r);
    if (abrir ?? n.has(id)) n.delete(id); else n.add(id);
    return n;
  });

  // arrastar o fundo para mover
  const premir = (e: PE<HTMLDivElement>) => {
    if (e.button !== 0 || (e.target as HTMLElement).closest('[data-org-no], button, a')) return;
    arrastoFundo.current = { x: e.clientX, y: e.clientY, px: pan.x, py: pan.y, moveu: false };
    e.currentTarget.setPointerCapture(e.pointerId);
  };
  const mover = (e: PE<HTMLDivElement>) => {
    const a = arrastoFundo.current;
    if (!a) return;
    a.moveu = true;
    setPan({ x: a.px + e.clientX - a.x, y: a.py + e.clientY - a.y });
  };
  const largar = (e: PE<HTMLDivElement>) => {
    arrastoFundo.current = null;
    if (e.currentTarget.hasPointerCapture(e.pointerId)) e.currentTarget.releasePointerCapture(e.pointerId);
  };
  const teclaArea = (e: KE<HTMLDivElement>) => {
    if ((e.target as HTMLElement).closest('[data-org-no]')) return;
    const passo = 48;
    const mapa: Record<string, () => void> = {
      '+': () => mudarZoom(zoom * 1.2), '=': () => mudarZoom(zoom * 1.2), '-': () => mudarZoom(zoom / 1.2), '0': ajustar,
      ArrowLeft: () => setPan((p) => ({ ...p, x: p.x + passo })), ArrowRight: () => setPan((p) => ({ ...p, x: p.x - passo })),
      ArrowUp: () => setPan((p) => ({ ...p, y: p.y + passo })), ArrowDown: () => setPan((p) => ({ ...p, y: p.y - passo })),
    };
    if (mapa[e.key]) { e.preventDefault(); mapa[e.key](); }
  };
  /** Traz o cartão para dentro da área visível (navegação por teclado). */
  const mostrar = (id: number) => {
    const n = disp.nos.get(id);
    const el = area.current;
    if (!n || !el) return;
    const x = n.x * zoom + pan.x;
    const y = n.y * zoom + pan.y;
    const w = MEDIDAS.largura * zoom;
    const h = n.altura * zoom;
    let dx = 0;
    let dy = 0;
    if (x < 8) dx = 8 - x; else if (x + w > el.clientWidth - 8) dx = el.clientWidth - 8 - x - w;
    if (y < 8) dy = 8 - y; else if (y + h > el.clientHeight - 8) dy = el.clientHeight - 8 - y - h;
    if (dx || dy) setPan((p) => ({ x: p.x + dx, y: p.y + dy }));
  };
  const focar = (id: number | null | undefined) => {
    if (id === null || id === undefined) return;
    (area.current?.querySelector(`[data-org-no="${id}"]`) as HTMLElement | null)?.focus();
  };
  const teclaNo = (e: KE<HTMLDivElement>, p: PosicaoQuadro) => {
    const n = disp.nos.get(p.id);
    const temFilhos = (filhos.get(p.id)?.length ?? 0) > 0;
    if (e.key === 'Enter') { e.preventDefault(); aoAbrir(p); } else if (e.key === 'ArrowLeft' && temFilhos && !recolhidos.has(p.id)) { e.preventDefault(); alternar(p.id, false); } else if (e.key === 'ArrowRight' && temFilhos && recolhidos.has(p.id)) { e.preventDefault(); alternar(p.id, true); } else if (e.key === 'ArrowUp') { e.preventDefault(); focar(n?.pai); } else if (e.key === 'ArrowDown') { e.preventDefault(); focar(n?.filhos[0]); }
  };

  const cartoesSvg = (): Map<number, CartaoSvg> => new Map(posicoes.map((p) => {
    const os = ocupantes(p);
    const contacto = os.find((m) => m.telefone || m.email);
    return [p.id, { id: p.id, titulo: p.titulo, area: p.area, cor: p.cor, apoio: !!p.apoio, vagas: textoVagas(os.length, p.vagas).texto,
      pessoas: os.map((m) => ({ nome: m.nome, cargo: m.cargo || m.papel, responsavel: m.id === p.membro_responsavel_id })),
      contacto: contacto ? [contacto.telefone, contacto.email].filter(Boolean).join(' · ') : null }];
  }));
  const svg = () => organigramaSvg(disp, cartoesSvg());
  const nomeFicheiro = `${titulo.replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_|_$/g, '')}.png`;

  const borda = token.colorBorder;
  const realceCor = token.colorPrimary;
  return (
    <div ref={moldura} style={{ background: cheio ? token.colorBgLayout : undefined, padding: cheio ? 12 : 0 }}>
      <Flex gap={8} wrap align="center" justify="space-between" style={{ marginBottom: 8 }} className="imp-nao-imprimir">
        <Space wrap size={6}>
          <Segmented<Orientacao> value={orientacao} onChange={mudarOrientacao} aria-label="Orientação do organigrama"
            options={[{ value: 'vertical', label: 'Vertical', icon: <NodeExpandOutlined rotate={90} /> }, { value: 'horizontal', label: 'Horizontal', icon: <NodeExpandOutlined /> }]} />
          <Button size="small" icon={<NodeExpandOutlined />} onClick={() => setRecolhidos(new Set())}>Expandir tudo</Button>
          <Button size="small" icon={<NodeCollapseOutlined />} onClick={() => setRecolhidos(new Set(entradas.filter((n) => pais.get(n.id) !== null && (filhos.get(n.id)?.length ?? 0) > 0).map((n) => n.id)))}>Recolher tudo</Button>
        </Space>
        <Space wrap size={6}>
          <Space.Compact>
            <Tooltip title="Reduzir (−)"><Button size="small" icon={<MinusOutlined />} aria-label="Reduzir" onClick={() => mudarZoom(zoom / 1.2)} disabled={zoom <= ZOOM_MIN} /></Tooltip>
            <Tooltip title="Tamanho real (100%)"><Button size="small" onClick={() => mudarZoom(1)} aria-label={`Zoom ${Math.round(zoom * 100)}%, repor 100%`} style={{ minWidth: 56 }}>{Math.round(zoom * 100)}%</Button></Tooltip>
            <Tooltip title="Ampliar (+)"><Button size="small" icon={<PlusOutlined />} aria-label="Ampliar" onClick={() => mudarZoom(zoom * 1.2)} disabled={zoom >= ZOOM_MAX} /></Tooltip>
          </Space.Compact>
          <Tooltip title="Ajustar ao ecrã (0)"><Button size="small" icon={<CompressOutlined />} onClick={ajustar}>Ajustar</Button></Tooltip>
          <Tooltip title={cheio ? 'Sair do ecrã inteiro' : 'Ecrã inteiro'}>
            <Button size="small" icon={cheio ? <FullscreenExitOutlined /> : <FullscreenOutlined />} aria-label={cheio ? 'Sair do ecrã inteiro' : 'Ecrã inteiro'}
              onClick={() => { if (document.fullscreenElement) void document.exitFullscreen(); else void moldura.current?.requestFullscreen?.().catch(() => message.info('O navegador não permitiu o ecrã inteiro.')); }} />
          </Tooltip>
          <BotoesExportar tamanho="small" excel={false} chave="projectos.organigrama" obterPedido={() => ({
            titulo, orientacao: disp.largura >= disp.altura ? 'paisagem' : 'retrato',
            conteudo: `<div style="text-align:center">${svg().replace('<svg ', '<svg style="max-width:100%;height:auto" ')}</div>`,
          })} />
          <Button size="small" icon={<FileImageOutlined />} onClick={() => void descarregarPng(svg(), nomeFicheiro).catch((e: Error) => message.error(e.message))}>PNG</Button>
        </Space>
      </Flex>
      <div ref={area} role="tree" aria-label={`${titulo}. Arraste o fundo para mover; Ctrl + roda ou + e − para o zoom; 0 ajusta ao ecrã.`} tabIndex={0}
        onKeyDown={teclaArea} onPointerDown={premir} onPointerMove={mover} onPointerUp={largar} onPointerCancel={largar}
        style={{ position: 'relative', overflow: 'hidden', height: cheio ? 'calc(100vh - 80px)' : 'min(72vh, 760px)', minHeight: 320, borderRadius: token.borderRadiusLG,
          border: `1px solid ${token.colorBorderSecondary}`, cursor: arrastoFundo.current ? 'grabbing' : 'grab', touchAction: 'none', outlineOffset: 2,
          backgroundColor: token.colorFillQuaternary, backgroundImage: `radial-gradient(${token.colorBorderSecondary} 1px, transparent 1px)`, backgroundSize: `${20 * zoom}px ${20 * zoom}px`,
          backgroundPosition: `${pan.x}px ${pan.y}px` }}>
        <div style={{ position: 'absolute', left: 0, top: 0, width: disp.largura, height: disp.altura, transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`, transformOrigin: '0 0' }}>
          <svg width={disp.largura} height={disp.altura} style={{ position: 'absolute', inset: 0, overflow: 'visible' }} aria-hidden="true">
            {disp.ligacoes.map((l) => {
              const activo = caminho.has(l.para) && caminho.has(l.de);
              return <path key={`${l.de}-${l.para}`} d={l.d} fill="none" stroke={activo ? realceCor : borda} strokeWidth={activo ? 2.5 : 1.4} strokeLinejoin="round" style={{ transition: 'stroke 120ms' }} />;
            })}
          </svg>
          {[...disp.nos.values()].map((n) => {
            const p = porId.get(n.id);
            if (!p) return null;
            const os = ocupantes(p);
            const v = textoVagas(os.length, p.vagas);
            const cor = CORES_HEX[p.cor ?? 'azul'] ?? CORES_HEX.azul;
            const nFilhos = filhos.get(p.id)?.length ?? 0;
            const escondidos = n.recolhido ? descendentes(p.id, filhos).length : 0;
            const activo = caminho.has(p.id);
            const contacto = os.find((m) => m.telefone || m.email);
            const menu = accoes?.(p);
            const med = medidas.get(p.id);
            const previsao = arrasto?.activo && alvo === p.id ? arrasto.previsao(p.id) : null;
            const corAlvo = previsao ? (previsao.mesmo ? token.colorTextQuaternary : previsao.excede ? token.colorError : token.colorPrimary) : null;
            return (
              <div key={n.id} data-org-no={n.id} role="treeitem" tabIndex={0} aria-level={n.nivel + 1} aria-expanded={nFilhos ? !n.recolhido : undefined}
                aria-label={`${p.titulo}${p.area ? `, ${p.area}` : ''}, ${v.texto}${os.length ? `, ${os.map((m) => m.nome).join(', ')}` : ', sem ocupante'}`}
                onClick={(e) => { if (!(e.target as HTMLElement).closest('button, a')) aoAbrir(p); }} onKeyDown={(e) => teclaNo(e, p)}
                onMouseEnter={() => setRealce(p.id)} onMouseLeave={() => setRealce((r) => (r === p.id ? null : r))}
                onFocus={() => { setRealce(p.id); mostrar(p.id); }} onBlur={() => setRealce((r) => (r === p.id ? null : r))}
                onDragOver={arrasto ? (e) => {
                  if (!arrasto.activo || !eArrastoMembro(e)) return;
                  e.preventDefault();
                  e.dataTransfer.dropEffect = arrasto.previsao(p.id)?.mesmo ? 'none' : 'move';
                  if (alvo !== p.id) setAlvo(p.id);
                } : undefined}
                onDragLeave={arrasto ? (e) => { if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setAlvo((a) => (a === p.id ? null : a)); } : undefined}
                onDrop={arrasto ? (e) => {
                  if (!arrasto.activo || !eArrastoMembro(e)) return;
                  e.preventDefault();
                  setAlvo(null);
                  arrasto.largar(p.id);
                } : undefined}
                data-org-alvo={previsao ? (previsao.excede ? 'excede' : previsao.mesmo ? 'mesmo' : 'ok') : undefined}
                style={{
                  position: 'absolute', left: n.x, top: n.y, width: MEDIDAS.largura, height: n.altura, boxSizing: 'border-box', cursor: 'pointer',
                  background: p.apoio ? token.colorFillAlter : token.colorBgContainer, color: token.colorText, borderRadius: 8,
                  border: `1px ${p.apoio ? 'dashed' : 'solid'} ${activo ? realceCor : token.colorBorder}`, borderTop: `4px solid ${cor}`,
                  boxShadow: corAlvo ? `0 0 0 3px ${corAlvo}, ${token.boxShadowSecondary}` : activo ? `0 0 0 2px ${token.colorPrimaryBorder}, ${token.boxShadowSecondary}` : token.boxShadowTertiary,
                  padding: `${CARTAO.padTopo - 2}px ${CARTAO.padH}px ${CARTAO.padBase}px`, display: 'flex', flexDirection: 'column', gap: 0, transition: 'box-shadow 120ms, border-color 120ms', outline: 'none',
                }}>
                <Flex justify="space-between" align="start" gap={4}>
                  <Linhas texto={med?.titulo} completo={p.titulo} forte />
                  {menu && menu.length > 0 && (
                    <Dropdown trigger={['click']} menu={{ items: menu, onClick: ({ key, domEvent }) => { domEvent.stopPropagation(); aoAccao?.(p, String(key)); } }}>
                      <Button size="small" type="text" icon={<MoreOutlined />} aria-label={`Acções de ${p.titulo}`} onClick={(e) => e.stopPropagation()} style={{ flex: 'none', marginRight: -6 }} />
                    </Dropdown>
                  )}
                </Flex>
                <Flex justify="space-between" align="start" gap={4} style={{ minHeight: med?.linhaArea ?? CARTAO.linhaArea }}>
                  <Typography.Text type="secondary" style={{ fontSize: 11, minWidth: 0 }}>
                    <Linhas texto={med?.area ?? null} completo={p.area ?? ''} semApoio={!!p.apoio} />
                    {p.apoio && <Tag style={{ marginLeft: 0, fontSize: 10, lineHeight: '16px' }} color="purple">apoio</Tag>}
                  </Typography.Text>
                  <Typography.Text style={{ fontSize: 11, whiteSpace: 'nowrap', flex: 'none' }} type={v.excesso ? 'danger' : v.falta ? 'warning' : 'secondary'}>{v.texto}</Typography.Text>
                </Flex>
                <div style={{ flex: 1, minHeight: 0 }}>
                  {os.length ? os.slice(0, 2).map((m, i) => (
                    <Flex key={m.id} align="start" gap={6} style={{ marginTop: i ? CARTAO.entrePessoas : 2, cursor: arrasto ? 'grab' : undefined, opacity: arrasto?.activo?.membroId === m.id ? 0.4 : 1 }}
                      draggable={!!arrasto} data-org-membro={m.id} title={arrasto ? `Arraste «${m.nome}» para outra posição` : m.nome}
                      onDragStart={arrasto ? (e) => {
                        e.stopPropagation();
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData(MIME_MEMBRO, String(m.id));
                        e.dataTransfer.setData('text/plain', m.nome);
                        arrasto.iniciar(m.id, p.id);
                      } : undefined}
                      onDragEnd={arrasto ? () => arrasto.terminar() : undefined}>
                      <Avatar size={CARTAO.avatar} style={{ background: cor, flex: 'none', fontSize: 10 }}>{iniciais(m.nome)}</Avatar>
                      <div style={{ minWidth: 0 }}>
                        <Linhas texto={med?.pessoas[i]?.nome} completo={m.nome} peso={m.id === p.membro_responsavel_id ? 600 : 400} />
                        {(m.cargo || m.papel) && <Typography.Text type="secondary"><Linhas texto={med?.pessoas[i]?.cargo ?? null} completo={m.cargo || m.papel || ''} /></Typography.Text>}
                      </div>
                    </Flex>
                  )) : <Typography.Text type="secondary" italic style={{ fontSize: 11, display: 'block', marginTop: 2 }}>Sem ocupante</Typography.Text>}
                </div>
                <Flex justify="space-between" align="center" gap={4} style={{ minHeight: 18 }}>
                  <Space size={4}>
                    {contacto?.telefone && <Tooltip title={contacto.telefone}><a href={`tel:${contacto.telefone.replace(/[^\d+]/g, '')}`} aria-label={`Telefonar a ${contacto.nome}`} onClick={(e) => e.stopPropagation()}><PhoneOutlined /></a></Tooltip>}
                    {contacto?.email && <Tooltip title={contacto.email}><a href={`mailto:${contacto.email}`} aria-label={`Enviar e-mail a ${contacto.nome}`} onClick={(e) => e.stopPropagation()}><MailOutlined /></a></Tooltip>}
                    {os.length > 2 && <Typography.Text type="secondary" style={{ fontSize: 10 }}>+{os.length - 2}</Typography.Text>}
                  </Space>
                  {nFilhos > 0 && (
                    <Button size="small" type={n.recolhido ? 'primary' : 'default'} shape="round" style={{ height: 18, padding: '0 6px', fontSize: 10 }}
                      icon={n.recolhido ? <ExpandOutlined /> : <CompressOutlined />} aria-label={n.recolhido ? `Expandir ${p.titulo} (${escondidos} posição(ões))` : `Recolher ${p.titulo}`}
                      onClick={(e) => { e.stopPropagation(); alternar(p.id); }}>
                      {n.recolhido ? escondidos : nFilhos}
                    </Button>
                  )}
                </Flex>
                {previsao && (
                  <div data-testid="previsao-destino" style={{ position: 'absolute', left: 4, right: 4, bottom: -12, zIndex: 2, pointerEvents: 'none', textAlign: 'center' }}>
                    <Tag color={previsao.mesmo ? 'default' : previsao.excede ? 'error' : 'processing'} style={{ margin: 0, maxWidth: '100%', whiteSpace: 'normal', lineHeight: 1.3, fontSize: 10 }}>
                      {previsao.texto}
                    </Tag>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>
      <div aria-live="polite" className="imp-nao-imprimir" style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0 0 0 0)' }}>
        {arrasto?.activo && alvo !== null ? arrasto.previsao(alvo)?.texto : ''}
      </div>
      <Typography.Text type="secondary" style={{ fontSize: 11 }} className="imp-nao-imprimir">
        Arraste o fundo para mover · Ctrl + roda (ou + / −) para o zoom · 0 ajusta ao ecrã · nos cartões: ↑ ↓ para navegar, ← → para recolher/expandir, Enter para o detalhe.
        {arrasto && ' · Arraste um membro para outra posição (ou use «Mover membro para…» no menu ⋯ da posição).'}
      </Typography.Text>
    </div>
  );
}

/**
 * Texto já quebrado em linhas (texto.ts): cada linha numa linha própria, no tamanho ajustado — nunca com «…». O texto
 * completo fica no `title`. Sem medida (não devia acontecer) mostra o texto a quebrar normalmente.
 */
function Linhas({ texto, completo, forte, peso, semApoio }: { texto: TextoAjustado | null | undefined; completo: string; forte?: boolean; peso?: number; semApoio?: boolean }) {
  if (!completo && !texto) return null;
  const linhas = texto ? (semApoio ? semSufixoApoio(texto.linhas) : texto.linhas) : [completo];
  const tamanho = texto?.tamanho ?? 12;
  return (
    <span title={completo || undefined} style={{ display: 'block', minWidth: 0, fontSize: tamanho, lineHeight: `${Math.ceil(tamanho * CARTAO.alturaLinha)}px`,
      fontWeight: forte ? 600 : peso, overflowWrap: 'anywhere' }}>
      {linhas.map((l, i) => <span key={i} style={{ display: 'block', whiteSpace: texto ? 'nowrap' : 'normal' }}>{l}</span>)}
    </span>
  );
}

/** A área foi medida com « · apoio» (a etiqueta ocupa esse espaço); no ecrã a etiqueta mostra-se à parte. */
export function semSufixoApoio(linhas: string[]): string[] {
  const l = [...linhas];
  const u = l.length - 1;
  if (u >= 0) l[u] = l[u].replace(/\s*·?\s*apoio$/, '');
  return l.filter((x) => x !== '');
}
