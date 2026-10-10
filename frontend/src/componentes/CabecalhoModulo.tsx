import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { IconeFa } from './fluxos/IconeFa';
import './CabecalhoModulo.css';

/** Ícone: nome Font Awesome 5 do legado (desenhado em SVG por IconeFa) ou um nó React já pronto. */
export type IconeModulo = string | ReactNode;

/** Indicador à direita do título (legado: «● Pendentes: 8», «📈 Mensal: 0,00 Kz», «● Integrados: 286»). */
export interface IndicadorModulo {
  chave: string;
  rotulo: ReactNode;
  valor: ReactNode;
  /** nome FA (ex.: «clock», «chart-bar», «check-circle») ou nó; por omissão um ponto «●» */
  icone?: IconeModulo;
  /** cor do ícone (legado: laranja #f59e0b para pendentes, verde #10b981 para totais/integrados) */
  cor?: string;
  /** dica (title) */
  dica?: string;
}

/** Separador em forma de botão que navega para uma rota /m/... */
export interface SeparadorModulo {
  chave: string;
  rotulo: ReactNode;
  /** rota de destino (ex.: «/m/compras/compras_pedidos»); pode ter query string sem dados pessoais */
  destino: string;
  icone?: IconeModulo;
  /** contador opcional num distintivo (ex.: pendentes); 0/nulo não mostra */
  contador?: number | null;
  /** permissões (basta uma) para o separador aparecer — ex.: «compras_pedidos_view» */
  permissao?: string | string[];
  /** força o estado activo (por omissão: o caminho actual começa pelo caminho do destino) */
  activo?: boolean;
}

export interface PropsCabecalhoModulo {
  /** título grande do módulo (ex.: «Gestão de Vendas») */
  titulo: ReactNode;
  /** ícone do título (ex.: «shopping-cart») */
  icone?: IconeModulo;
  subtitulo?: ReactNode;
  indicadores?: IndicadorModulo[];
  /** botão «⛶ Ecrã Cheio» (Fullscreen API). `true` = área de conteúdo (.erp-conteudo); função = elemento a mostrar */
  ecraCheio?: boolean | (() => HTMLElement | null);
  /** acções extra à direita do cabeçalho (depois dos indicadores) */
  accoes?: ReactNode;
  /** barra de acções rápidas por baixo do título (legado: .compras-toolbar) */
  accoesRapidas?: ReactNode;
  /** separadores-botão (legado: .tab-btn); só aparecem os permitidos e só quando há pelo menos `minimoSeparadores` */
  separadores?: SeparadorModulo[];
  /** chave do separador activo (sobrepõe a detecção pelo caminho) */
  activo?: string;
  /** mínimo de separadores visíveis para mostrar a barra (por omissão 2) */
  minimoSeparadores?: number;
  /** conteúdo opcional por baixo dos separadores (ex.: sub-separadores) */
  children?: ReactNode;
  /** conteúdo na mesma linha dos separadores, encostado à direita (ex.: sub-separadores da Faturação no legado) */
  separadoresDireita?: ReactNode;
}

const desenharIcone = (icone: IconeModulo | undefined, cor?: string) =>
  icone == null ? null : typeof icone === 'string' ? <IconeFa nome={icone} style={cor ? { color: cor } : undefined} /> : icone;

/** O caminho actual corresponde ao destino (mesmo prefixo de caminho e, se o destino tiver query, os mesmos parâmetros). */
export function separadorActivo(destino: string, caminho: string, pesquisa = ''): boolean {
  const [rota, query] = destino.split('?');
  const dentro = caminho === rota || caminho.startsWith(`${rota}/`);
  if (!dentro || !query) return dentro;
  const actual = new URLSearchParams(pesquisa);
  return [...new URLSearchParams(query)].every(([k, v]) => actual.get(k) === v);
}

/** Estado do ecrã cheio (Fullscreen API) e alternância. */
function useEcraCheio(alvo: PropsCabecalhoModulo['ecraCheio']) {
  const [cheio, setCheio] = useState(() => typeof document !== 'undefined' && !!document.fullscreenElement);
  useEffect(() => {
    const mudar = () => setCheio(!!document.fullscreenElement);
    document.addEventListener('fullscreenchange', mudar);
    return () => document.removeEventListener('fullscreenchange', mudar);
  }, []);
  const alternar = useCallback(() => {
    if (document.fullscreenElement) {
      void document.exitFullscreen?.().catch(() => undefined);
      return;
    }
    const el = (typeof alvo === 'function' ? alvo() : null) ?? document.querySelector<HTMLElement>('.erp-conteudo') ?? document.documentElement;
    void el.requestFullscreen?.().catch(() => undefined);
  }, [alvo]);
  return { cheio, alternar, suportado: typeof document !== 'undefined' && !!document.fullscreenEnabled };
}

/**
 * Cabeçalho de MÓDULO AGREGADOR, como no sistema anterior (G.5 do relatório de verificação visual): título grande com o
 * ícone do módulo (h2.view-title), subtítulo, indicadores à direita, botão «Ecrã Cheio» opcional, barra de acções rápidas
 * opcional e os separadores do módulo em forma de BOTÃO (activo azul cheio, restantes brancos com contorno, ícone à
 * esquerda, contador opcional, quebra de linha). Os separadores navegam entre rotas /m/... (cada separador é um ecrã).
 * Documentação: src/componentes/README_CABECALHO_MODULO.md.
 */
export function CabecalhoModulo({
  titulo, icone, subtitulo, indicadores, ecraCheio, accoes, accoesRapidas, separadores, activo, minimoSeparadores = 2, children, separadoresDireita,
}: PropsCabecalhoModulo) {
  const navegar = useNavigate();
  const { pathname, search } = useLocation();
  const { pode } = useSessao();
  const cheio = useEcraCheio(ecraCheio);
  const visiveis = (separadores ?? []).filter((s) => !s.permissao || pode(...(Array.isArray(s.permissao) ? s.permissao : [s.permissao])));
  const temDireita = !!indicadores?.length || !!accoes || (!!ecraCheio && cheio.suportado);

  return (
    <div className="erp-cab-modulo imp-nao-imprimir">
      <div className="erp-cab-modulo-topo">
        <div className="erp-cab-modulo-textos">
          <h2 className="erp-cab-modulo-titulo">
            {icone != null && <span className="erp-cab-modulo-icone">{desenharIcone(icone)}</span>}
            <span>{titulo}</span>
          </h2>
          {subtitulo && <p className="erp-cab-modulo-subtitulo">{subtitulo}</p>}
        </div>
        {temDireita && (
          <div className="erp-cab-modulo-direita">
            {!!indicadores?.length && (
              <div className="erp-cab-modulo-indicadores" role="status">
                {indicadores.map((i) => (
                  <span key={i.chave} className="erp-cab-modulo-indicador" title={i.dica}>
                    <span className="erp-cab-modulo-indicador-icone" style={{ color: i.cor ?? '#f59e0b' }} aria-hidden>
                      {i.icone == null ? '●' : desenharIcone(i.icone)}
                    </span>
                    {i.rotulo}: <strong>{i.valor}</strong>
                  </span>
                ))}
              </div>
            )}
            {accoes}
            {!!ecraCheio && cheio.suportado && (
              <button type="button" className="erp-cab-modulo-ecra-cheio" onClick={cheio.alternar} aria-pressed={cheio.cheio}>
                <IconeFa nome={cheio.cheio ? 'compress' : 'expand'} /> {cheio.cheio ? 'Sair do Ecrã Cheio' : 'Ecrã Cheio'}
              </button>
            )}
          </div>
        )}
      </div>

      {accoesRapidas && <div className="erp-cab-modulo-rapidas">{accoesRapidas}</div>}

      {visiveis.length >= minimoSeparadores && (
        <nav className="erp-cab-modulo-separadores" aria-label="Separadores do módulo">
          {visiveis.map((s) => {
            const eActivo = activo ? activo === s.chave : s.activo ?? separadorActivo(s.destino, pathname, search);
            return (
              <a
                key={s.chave}
                href={s.destino}
                className={eActivo ? 'erp-separador-botao activo' : 'erp-separador-botao'}
                aria-current={eActivo ? 'page' : undefined}
                onClick={(ev) => {
                  if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button !== 0) return; // abrir noutro separador do navegador
                  ev.preventDefault();
                  navegar(s.destino);
                }}
              >
                {s.icone != null && <span className="erp-separador-icone">{desenharIcone(s.icone)}</span>}
                <span>{s.rotulo}</span>
                {!!s.contador && <span className="erp-separador-contador">{s.contador}</span>}
              </a>
            );
          })}
          {separadoresDireita && <div style={{ marginLeft: 'auto', display: 'flex', flexWrap: 'wrap', gap: 'inherit' }}>{separadoresDireita}</div>}
        </nav>
      )}
      {children}
    </div>
  );
}
