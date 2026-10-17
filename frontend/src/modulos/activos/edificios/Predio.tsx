import { Badge, theme, Tooltip } from 'antd';
import type { CSSProperties, ReactNode } from 'react';
import { eCave, formaEdificio, nomeAndar, ordenarParaDesenho, rotuloPorta } from './regras';
import type { PisoDesenho, PortaDesenho } from './tipos';

/**
 * Desenho do edifício (r3_activos): casa (telhado de duas águas) ou prédio (terraço), com os andares empilhados — o último
 * em cima, as caves abaixo do solo — e as portas/divisões de cada andar como portas clicáveis (mostram os bens daquela
 * porta). Feito com HTML/CSS (portas são botões, acessíveis por teclado) e SVG só no telhado; cores do tema (tela nocturna).
 * Usado nos cartões dos edifícios, na planta e na pré-visualização ao criar andares e portas.
 */
export interface PropsPredio {
  pisos: PisoDesenho[];
  /** Nome do edifício (rótulo do grupo para leitores de ecrã). */
  nome: string;
  /** Clique numa porta (sem isto as portas não são botões). */
  aoAbrirPorta?: (porta: PortaDesenho, piso: PisoDesenho) => void;
  /** Máximo de portas desenhadas por andar (o resto aparece como «+N»). */
  maxPortas?: number;
  /** Altura máxima do desenho (com deslocação interna) — os botões do cartão ficam sempre à vista. */
  alturaMaxima?: number;
  /** Selo no telhado (ex.: «Arrendado»). */
  selo?: ReactNode;
  /** Tamanho compacto (cartões). */
  compacto?: boolean;
  /** Texto quando não há andares. */
  vazio?: ReactNode;
}

export function Predio({ pisos, nome, aoAbrirPorta, maxPortas, alturaMaxima, selo, compacto, vazio }: PropsPredio) {
  const { token } = theme.useToken();
  const desenho = ordenarParaDesenho(pisos);
  const forma = formaEdificio(pisos);
  const acima = desenho.filter((p) => !eCave(p));
  const caves = desenho.filter((p) => eCave(p));
  const parede = token.colorFillTertiary;
  const contorno = token.colorBorder;
  const alturaPorta = compacto ? 30 : 40;
  const larguraPorta = compacto ? 34 : 46;

  const porta = (p: PortaDesenho, f: PisoDesenho) => {
    const ocupada = p.n_bens > 0;
    const estilo: CSSProperties = {
      position: 'relative', width: larguraPorta, height: alturaPorta, borderRadius: `${Math.round(larguraPorta / 2)}px ${Math.round(larguraPorta / 2)}px 3px 3px`,
      border: `1.5px ${p.nova ? 'dashed' : 'solid'} ${p.nova ? token.colorSuccess : ocupada ? token.colorPrimaryBorder : contorno}`,
      background: p.nova ? token.colorSuccessBg : ocupada ? token.colorPrimaryBg : token.colorBgContainer,
      color: ocupada ? token.colorPrimaryText : token.colorTextSecondary, fontSize: compacto ? 10 : 11, fontWeight: 700, lineHeight: 1.1,
      display: 'inline-flex', alignItems: 'flex-end', justifyContent: 'center', paddingBottom: 3, overflow: 'hidden', whiteSpace: 'nowrap',
      cursor: aoAbrirPorta && !p.nova ? 'pointer' : 'default', transition: 'transform .12s ease, box-shadow .12s ease', flex: '0 0 auto',
    };
    const conteudo = (
      <>
        <span aria-hidden style={{ position: 'absolute', right: 5, top: '52%', width: 3, height: 3, borderRadius: 2, background: 'currentColor', opacity: 0.6 }} />
        <span style={{ maxWidth: larguraPorta - 4, overflow: 'hidden', textOverflow: 'ellipsis' }}>{p.numero}</span>
        {ocupada && (
          <Badge count={p.n_bens} size="small" overflowCount={99} color={token.colorPrimary}
            style={{ position: 'absolute', top: -2, right: -2, boxShadow: 'none' }} />
        )}
      </>
    );
    const rotulo = rotuloPorta(p, f);
    if (!aoAbrirPorta || p.nova) {
      return <span key={p.id} role="img" aria-label={rotulo} title={rotulo} className="r3-porta" style={estilo}>{conteudo}</span>;
    }
    return (
      <Tooltip key={p.id} title={<>{rotulo}{p.tipo ? ` · ${p.tipo}` : ''}</>} mouseEnterDelay={0.4}>
        <button type="button" aria-label={rotulo} className="r3-porta r3-porta-botao" style={estilo} onClick={() => aoAbrirPorta(p, f)}>{conteudo}</button>
      </Tooltip>
    );
  };

  const andar = (f: PisoDesenho, cave = false) => {
    const visiveis = maxPortas ? f.portas.slice(0, maxPortas) : f.portas;
    const resto = f.portas.length - visiveis.length;
    const bens = f.portas.reduce((s, p) => s + p.n_bens, 0);
    return (
      <div key={f.id} role="listitem" aria-label={`${nomeAndar(f)}: ${f.portas.length} porta(s), ${bens} bem(ns)`}
        style={{ display: 'flex', alignItems: 'stretch', borderTop: `1px solid ${contorno}`,
          background: f.destaque ? (f.novo ? token.colorSuccessBg : token.colorWarningBg) : cave ? token.colorFillSecondary : parede,
          outline: f.destaque ? `2px dashed ${f.novo ? token.colorSuccess : token.colorWarning}` : undefined, outlineOffset: -2 }}>
        <div style={{ flex: `0 0 ${compacto ? 62 : 92}px`, padding: compacto ? '4px 6px' : '6px 8px', borderRight: `1px solid ${contorno}`, background: token.colorBgContainer,
          display: 'flex', flexDirection: 'column', justifyContent: 'center', minWidth: 0 }}>
          <span style={{ fontSize: compacto ? 10.5 : 12, fontWeight: 700, color: token.colorText, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={nomeAndar(f)}>
            {nomeAndar(f)}
          </span>
          <span style={{ fontSize: 10, color: token.colorTextSecondary }}>{f.portas.length} p. · {bens} b.</span>
        </div>
        <div style={{ flex: 1, display: 'flex', flexWrap: 'wrap', alignItems: 'flex-end', gap: compacto ? 5 : 8, padding: compacto ? '6px 8px 0' : '8px 10px 0', minHeight: alturaPorta + 10 }}>
          {visiveis.map((p) => porta(p, f))}
          {!f.portas.length && (
            <span style={{ fontSize: 11, color: token.colorTextTertiary, alignSelf: 'center', paddingBottom: 6 }}>{f.novo ? 'Andar novo' : 'Sem portas'}</span>
          )}
          {resto > 0 && <span style={{ fontSize: 11, color: token.colorTextSecondary, alignSelf: 'center', paddingBottom: 6 }}>+{resto} porta(s)</span>}
        </div>
      </div>
    );
  };

  return (
    <div role="group" aria-label={`Desenho do edifício ${nome}`} style={{ display: 'flex', flexDirection: 'column', alignItems: 'stretch' }}>
      <style>{`.r3-porta-botao:hover,.r3-porta-botao:focus-visible{transform:translateY(-2px);box-shadow:0 3px 8px ${token.colorPrimaryBorder};outline:none}
        .r3-porta-botao:focus-visible{outline:2px solid ${token.colorPrimary};outline-offset:2px}
        @media (prefers-reduced-motion: reduce){.r3-porta-botao{transition:none!important}.r3-porta-botao:hover{transform:none}}`}</style>
      {/* telhado: duas águas (casa) ou terraço com platibanda (prédio) */}
      <div style={{ position: 'relative', margin: '0 auto', width: '100%' }} aria-hidden>
        {forma === 'CASA' ? (
          <svg viewBox="0 0 200 40" preserveAspectRatio="none" style={{ display: 'block', width: '100%', height: compacto ? 30 : 40 }}>
            <polygon points="0,40 100,2 200,40" fill={token.colorTextTertiary} />
            <rect x="148" y="10" width="14" height="20" fill={token.colorTextQuaternary} />
          </svg>
        ) : (
          <div style={{ padding: '0 6px' }}>
            <div style={{ height: 6, width: 2, marginLeft: '78%', background: token.colorTextTertiary }} />
            <div style={{ height: compacto ? 8 : 10, background: token.colorTextTertiary, borderRadius: '3px 3px 0 0' }} />
          </div>
        )}
        {selo && <div style={{ position: 'absolute', top: forma === 'CASA' ? 4 : -2, left: 6 }}>{selo}</div>}
      </div>
      <div style={{ border: `2px solid ${contorno}`, borderTop: 'none', background: parede, maxHeight: alturaMaxima, overflowY: alturaMaxima ? 'auto' : undefined }}>
        <div role="list" aria-label="Andares acima do solo">
          {acima.map((f) => andar(f))}
        </div>
        {!pisos.length && (
          <div style={{ padding: compacto ? 10 : 16, textAlign: 'center', fontSize: 12, color: token.colorTextSecondary }}>{vazio ?? 'Ainda sem andares.'}</div>
        )}
      </div>
      {/* solo: entrada e linha do terreno; as caves ficam por baixo */}
      <div aria-hidden style={{ height: compacto ? 5 : 7, background: token.colorTextSecondary, borderRadius: 2, margin: '0 -6px' }} />
      {caves.length > 0 && (
        <div role="list" aria-label="Caves" style={{ border: `2px dashed ${contorno}`, borderTop: 'none' }}>
          {caves.map((f) => andar(f, true))}
        </div>
      )}
    </div>
  );
}
