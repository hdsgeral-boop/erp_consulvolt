import { Button } from 'antd';
import type { CSSProperties, KeyboardEvent, ReactNode } from 'react';
import { IconeFa } from './IconeFa';
import { ESTADOS_FLUXO, ORDEM_ESTADOS, estadoDe, ligacaoActiva, type EstadoEtapa } from './estados';
import './fluxos.css';

/**
 * Peças visuais do Fluxo de Processos, iguais às do sistema anterior (js/fluxo_processos.js, fluxo_tabela.js e
 * fluxo_narrativa.js): pastilha de estado, mini-progresso, diagrama de etapas com nós e linhas de ligação, detalhe da
 * etapa (factos, pendências, acções), narrativa, funil por etapa actual e legenda. Devem ficar dentro de `<RaizFluxo>`
 * (variáveis de cor e impressão com cores).
 */

export interface EtapaDiagrama {
  id: string;
  nome: string;
  icone?: string | null;
}

export function RaizFluxo({ children, className, style }: { children: ReactNode; className?: string; style?: CSSProperties }) {
  return (
    <div className={['fluxo-raiz', className].filter(Boolean).join(' ')} style={style}>
      {children}
    </div>
  );
}

/** Pastilha de estado com o ícone do legado (pill, fluxo_processos.js:358). */
export function PillEstado({ estado, texto }: { estado: string | null | undefined; texto?: ReactNode }) {
  const d = ESTADOS_FLUXO[estadoDe(estado)];
  return (
    <span className={`fluxo-pill ${d.cls}`}>
      <IconeFa nome={d.icone} />
      {texto ?? d.rotulo}
    </span>
  );
}

/** Um segmento por etapa, colorido pelo estado (fluxo-mini). */
export function MiniProgresso({ estados, rotulo }: { estados: (string | null | undefined)[]; rotulo?: string }) {
  return (
    <span className="fluxo-mini" style={{ '--n': Math.max(1, estados.length) } as CSSProperties} role={rotulo ? 'img' : undefined} aria-label={rotulo} aria-hidden={rotulo ? undefined : true}>
      {estados.map((e, i) => (
        <i key={i} className={ESTADOS_FLUXO[estadoDe(e)].cls} />
      ))}
    </span>
  );
}

/** Marcas de pendências: erros (círculo com cruz) e avisos (triângulo), como na tabela do legado. */
export function MarcasPendencias({ erros, avisos }: { erros: number; avisos: number }) {
  if (!erros && !avisos) return <span style={{ color: '#94a3b8' }}>—</span>;
  return (
    <span>
      {erros > 0 && (
        <span className="ft-marca erro" title={`${erros} erro(s)`}>
          <IconeFa nome="times-circle" />
          {erros}
        </span>
      )}
      {avisos > 0 && (
        <span className="ft-marca aviso" title={`${avisos} aviso(s)`}>
          <IconeFa nome="exclamation-triangle" />
          {avisos}
        </span>
      )}
    </span>
  );
}

/**
 * Diagrama das etapas: nó circular com o ícone da etapa (cor do estado), nome, pastilha e resumo; linha de ligação antes de
 * cada etapa (verde quando a etapa já começou). Horizontal, ou vertical quando o espaço é estreito (consulta de contentor).
 * Setas ← → (e Home/End) mudam de etapa.
 */
export function DiagramaFluxo({
  etapas,
  estados,
  seleccionada,
  aoSeleccionar,
  titulo,
  estadoGlobal,
  concluidas,
}: {
  etapas: EtapaDiagrama[];
  estados: Record<string, { estado: string; resumo?: string | null } | undefined>;
  seleccionada?: string | null;
  aoSeleccionar?: (id: string) => void;
  titulo?: ReactNode;
  estadoGlobal?: { estado: EstadoEtapa; rotulo: string };
  /** mostra a barra de progresso «n/N … %» */
  concluidas?: number;
}) {
  const teclas = (ev: KeyboardEvent<HTMLOListElement>) => {
    if (!aoSeleccionar || !seleccionada) return;
    const i = etapas.findIndex((e) => e.id === seleccionada);
    const alvo = ev.key === 'ArrowRight' || ev.key === 'ArrowDown' ? i + 1 : ev.key === 'ArrowLeft' || ev.key === 'ArrowUp' ? i - 1 : ev.key === 'Home' ? 0 : ev.key === 'End' ? etapas.length - 1 : null;
    if (alvo === null || alvo < 0 || alvo >= etapas.length || alvo === i) return;
    ev.preventDefault();
    aoSeleccionar(etapas[alvo].id);
    (ev.currentTarget.querySelectorAll('button')[alvo] as HTMLButtonElement | undefined)?.focus();
  };
  const pct = concluidas !== undefined ? Math.round((concluidas / Math.max(1, etapas.length)) * 100) : null;
  return (
    <section className="fluxo-diagrama imp-sem-quebra" aria-label={typeof titulo === 'string' ? `Fluxo ${titulo}` : 'Fluxo do processo'}>
      {(titulo || estadoGlobal) && (
        <div className="fluxo-diagrama-topo">
          <strong>{titulo}</strong>
          {estadoGlobal && <PillEstado estado={estadoGlobal.estado} texto={estadoGlobal.rotulo} />}
        </div>
      )}
      <ol className="fluxo-etapas" style={{ '--n': etapas.length } as CSSProperties} aria-label="Etapas do processo" onKeyDown={teclas}>
        {etapas.map((e, i) => {
          const x = estados[e.id];
          const est = estadoDe(x?.estado);
          const d = ESTADOS_FLUXO[est];
          const seguinte = etapas[i + 1] ? estados[etapas[i + 1].id]?.estado : null;
          return (
            <li
              key={e.id}
              className={`fluxo-etapa ${d.cls}`}
              style={{ '--fx-ligacao-seguinte': ligacaoActiva(seguinte) ? 'var(--fx-ok-linha)' : 'var(--fx-borda)' } as CSSProperties}
            >
              <button
                type="button"
                aria-pressed={aoSeleccionar ? e.id === seleccionada : undefined}
                tabIndex={!aoSeleccionar || !seleccionada || e.id === seleccionada ? 0 : -1}
                onClick={() => aoSeleccionar?.(e.id)}
                title={`${e.nome}: ${d.rotulo}`}
                style={aoSeleccionar ? undefined : { cursor: 'default' }}
              >
                <span className="fluxo-no">
                  <IconeFa nome={e.icone} />
                </span>
                <span className="fluxo-etapa-texto">
                  <span className="fluxo-etapa-nome">{e.nome}</span>
                  <PillEstado estado={est} />
                  {x?.resumo && <span className="fluxo-etapa-resumo">{x.resumo}</span>}
                </span>
              </button>
            </li>
          );
        })}
      </ol>
      {pct !== null && (
        <div className="fluxo-progresso">
          <span>
            {concluidas}/{etapas.length}
          </span>
          <span className="fluxo-progresso-barra" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} aria-label="Etapas concluídas">
            <span style={{ width: `${pct}%` }} />
          </span>
          <span>{pct}%</span>
        </div>
      )}
    </section>
  );
}

export interface AccaoEtapa {
  rotulo: string;
  primaria?: boolean;
  aoClicar?: () => void;
  desactivada?: boolean;
  titulo?: string;
}

/** Detalhe da etapa seleccionada (fluxo-detalhe): factos, pendências (ou «Sem pendências»), acções com seta. */
export function DetalheEtapa({
  etapa,
  estado,
  factos,
  problemas,
  accoes,
}: {
  etapa: EtapaDiagrama;
  estado: string;
  factos: { rotulo: string; texto: string }[];
  problemas: { nivel: string; texto: string }[];
  accoes: AccaoEtapa[];
}) {
  return (
    <section className="fluxo-detalhe imp-sem-quebra" aria-live="polite">
      <div className="fluxo-detalhe-topo">
        <h3>
          <IconeFa nome={etapa.icone} /> {etapa.nome}
        </h3>
        <PillEstado estado={estado} />
      </div>
      {factos.length > 0 && (
        <div className="fluxo-factos">
          {factos.map((f, i) => (
            <div key={i} className="fluxo-facto">
              <span>{f.rotulo}</span>
              <strong>{f.texto}</strong>
            </div>
          ))}
        </div>
      )}
      {problemas.length > 0 ? (
        <ul className="fluxo-problemas">
          {problemas.map((p, i) => (
            <li key={i} className={p.nivel === 'erro' ? 'erro' : 'aviso'}>
              <IconeFa nome={p.nivel === 'erro' ? 'times-circle' : 'exclamation-triangle'} />
              <span>{p.texto}</span>
            </li>
          ))}
        </ul>
      ) : estadoDe(estado) !== 'fazer' ? (
        <div className="fluxo-sem-problemas">
          <IconeFa nome="check-circle" /> Sem pendências nesta etapa.
        </div>
      ) : (
        <div className="fluxo-nota">Esta etapa fica disponível quando a anterior estiver concluída.</div>
      )}
      {accoes.length > 0 && (
        <div className="fluxo-accoes imp-nao-imprimir">
          {accoes.map((a, i) => (
            <Button key={i} type={a.primaria ? 'primary' : 'default'} disabled={a.desactivada} title={a.titulo} onClick={a.aoClicar} iconPosition="end" icon={<IconeFa nome="arrow-right" />}>
              {a.rotulo}
            </Button>
          ))}
        </div>
      )}
    </section>
  );
}

export interface NarrativaDaEtapa {
  nome: string;
  quem: string;
  descricao: string;
  controlos: string;
  resultado: string;
}

/** Narrativa da etapa seleccionada (fluxo-narrativa): descrição, quem executa, controlos e resultado. */
export function NarrativaEtapa({ indice, narrativa }: { indice: number; narrativa: NarrativaDaEtapa }) {
  return (
    <section className="fluxo-narrativa imp-sem-quebra" aria-label="Narrativa da etapa">
      <h4>
        <IconeFa nome="book-open" /> {indice + 1}. {narrativa.nome} — narrativa
      </h4>
      <p>{narrativa.descricao}</p>
      <dl>
        <div>
          <dt>Quem executa</dt>
          <dd>{narrativa.quem}</dd>
        </div>
        <div>
          <dt>Controlos</dt>
          <dd>{narrativa.controlos}</dd>
        </div>
        <div>
          <dt>Resultado</dt>
          <dd>{narrativa.resultado}</dd>
        </div>
      </dl>
    </section>
  );
}

/** Legenda dos estados (as quatro pastilhas) e a nota sobre os botões. */
export function LegendaFluxo() {
  return (
    <div className="fluxo-legenda">
      {ORDEM_ESTADOS.map((e) => (
        <PillEstado key={e} estado={e} />
      ))}
      <span>· Os botões abrem o ecrã onde a etapa é executada.</span>
    </div>
  );
}

/** Indicadores do topo (fluxo-kpis): rótulo em maiúsculas e número; a vermelho quando em alerta. */
export function KpisFluxo({ kpis }: { kpis: { chave: string; rotulo: string; texto: string; alerta?: boolean }[] }) {
  return (
    <div className="fluxo-kpis">
      {kpis.map((k) => (
        <div key={k.chave} className={`fluxo-kpi${k.alerta ? ' bloq' : ''}`}>
          <span>{k.rotulo}</span>
          <strong>{k.texto}</strong>
        </div>
      ))}
    </div>
  );
}

export interface ColunaFunil {
  id: string;
  rotulo: string;
  numero: number;
  valor?: string | null;
  comPendencias?: number;
}

/** Funil «Por etapa actual» (ft-funil): uma coluna por etapa, divisas «›» entre colunas; clicar filtra. */
export function FunilFluxo({ colunas, activa, aoEscolher }: { colunas: ColunaFunil[]; activa?: string | null; aoEscolher: (id: string | null) => void }) {
  return (
    <div className="ft-funil" style={{ '--n': colunas.length } as CSSProperties} role="group" aria-label="Processos por etapa actual">
      {colunas.map((c) => (
        <button key={c.id} type="button" className="ft-coluna" aria-pressed={activa === c.id} onClick={() => aoEscolher(activa === c.id ? null : c.id)}>
          <span className="ft-coluna-nome">{c.rotulo}</span>
          <span className="ft-coluna-num">{c.numero}</span>
          <span className="ft-coluna-info">
            {c.valor && <span>{c.valor}</span>}
            {!!c.comPendencias && <span className="alerta">{c.comPendencias} com pendências</span>}
          </span>
        </button>
      ))}
    </div>
  );
}
