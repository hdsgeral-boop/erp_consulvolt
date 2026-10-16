import { Tooltip } from 'antd';
import type { CSSProperties, ReactNode } from 'react';
import { IconeLegado } from './AccoesLinha';
import './padroesLegado.css';

/**
 * Cartão KPI do sistema anterior (.stat-card): barra de cor (por baixo — Dashboard de Vendas, Relatórios POS,
 * Lavandaria — ou à esquerda — Dashboard Global), quadrado com ícone colorido, rótulo em MAIÚSCULAS, valor grande a
 * negrito e linha secundária opcional. Com `aoClicar` passa a botão (filtra a lista, abre o detalhe…).
 */
export type PosicaoBarraKpi = 'baixo' | 'esquerda' | 'nenhuma';

/** Cores usadas pelo antigo nos cartões (azul, verde, verde-água, laranja, vermelho, roxo, cinzento). */
export const CORES_KPI = {
  azul: '#3b82f6',
  primario: '#2563eb',
  verde: '#10b981',
  sucesso: '#059669',
  verdeAgua: '#14b8a6',
  laranja: '#f59e0b',
  vermelho: '#ef4444',
  roxo: '#7c3aed',
  cinzento: '#64748b',
} as const;

export interface PropsCartaoKpi {
  rotulo: ReactNode;
  valor: ReactNode;
  /** Unidade pequena depois do valor (ex.: «Kz»). */
  unidade?: ReactNode;
  /** Linha secundária (ex.: «Faturação activa», «12 documentos»). */
  secundaria?: ReactNode;
  /** Ícone Font Awesome do antigo (ex.: «shopping-cart», «fa-coins»). */
  icone?: string;
  /** Cor da barra e do ícone (por omissão o azul do antigo). Aceita as chaves de `CORES_KPI`. */
  cor?: keyof typeof CORES_KPI | string;
  /** Onde fica a barra de cor (por omissão: por baixo). */
  barra?: PosicaoBarraKpi;
  /** Cor do valor (ex.: vermelho para negativos). */
  corValor?: string;
  /** Dica (ícone de ajuda não incluído: o cartão inteiro mostra a dica). */
  ajuda?: ReactNode;
  aoClicar?: () => void;
  /** Realce de «seleccionado» (cartões que filtram a lista). */
  activo?: boolean;
  className?: string;
  style?: CSSProperties;
}

function corDe(cor: PropsCartaoKpi['cor']): string {
  if (!cor) return CORES_KPI.azul;
  return (CORES_KPI as Record<string, string>)[cor] ?? cor;
}

/** Fundo translúcido (10 %) a partir de uma cor #rrggbb; outras cores → cinzento muito claro. */
export function fundoTranslucido(cor: string): string {
  const m = /^#([0-9a-f]{6})$/i.exec(cor.trim());
  if (!m) return 'rgba(100, 116, 139, 0.1)';
  const n = parseInt(m[1], 16);
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, 0.1)`;
}

export function CartaoKpi({ rotulo, valor, unidade, secundaria, icone, cor, barra = 'baixo', corValor, ajuda, aoClicar, activo, className, style }: PropsCartaoKpi) {
  const c = corDe(cor);
  // o valor fica numa só linha com reticências (legado): o texto completo aparece ao passar o rato
  const tituloValor = typeof valor === 'string' || typeof valor === 'number' ? `${valor}${typeof unidade === 'string' ? ` ${unidade}` : ''}` : undefined;
  const classes = [
    'erp-kpi',
    barra === 'baixo' ? 'erp-kpi-barra-baixo' : barra === 'esquerda' ? 'erp-kpi-barra-esquerda' : '',
    aoClicar ? 'erp-kpi-clicavel' : '',
    activo ? 'erp-kpi-activo' : '',
    className ?? '',
  ]
    .filter(Boolean)
    .join(' ');
  const estilo = { '--erp-kpi-cor': c, '--erp-kpi-fundo': fundoTranslucido(c), ...style } as CSSProperties;
  const conteudo = (
    <>
      <div className="erp-kpi-textos">
        <div className="erp-kpi-rotulo">{rotulo}</div>
        <div className="erp-kpi-valor" style={corValor ? { color: corValor } : undefined} title={tituloValor}>
          {valor}
          {unidade !== undefined && unidade !== null && <span className="erp-kpi-unidade">{unidade}</span>}
        </div>
        {secundaria !== undefined && secundaria !== null && secundaria !== '' && <span className="erp-kpi-secundaria">{secundaria}</span>}
      </div>
      {icone && (
        <div className="erp-kpi-icone" aria-hidden>
          <IconeLegado icone={icone} />
        </div>
      )}
    </>
  );
  const cartao = aoClicar ? (
    <button type="button" className={classes} style={estilo} onClick={aoClicar} aria-pressed={activo === undefined ? undefined : activo}>
      {conteudo}
    </button>
  ) : (
    <div className={classes} style={estilo}>
      {conteudo}
    </div>
  );
  return ajuda ? <Tooltip title={ajuda}>{cartao}</Tooltip> : cartao;
}

/** Grelha responsiva de cartões KPI (auto-fit; uma coluna no telemóvel). */
export function GrelhaKpi({ children, larguraMinima = 220, style, className }: { children: ReactNode; larguraMinima?: number; style?: CSSProperties; className?: string }) {
  return (
    <div className={['erp-grelha-kpi', className].filter(Boolean).join(' ')} style={{ '--erp-kpi-min': `${larguraMinima}px`, ...style } as CSSProperties}>
      {children}
    </div>
  );
}
