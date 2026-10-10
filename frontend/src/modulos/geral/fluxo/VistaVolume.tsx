import { Button, Skeleton } from 'antd';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Fragment, useEffect, useRef, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { obterPagina } from '@/api/cliente';
import { FunilFluxo, IconeFa, LegendaFluxo, MiniProgresso, PillEstado, type EtapaDiagrama, type NarrativaDaEtapa } from '@/componentes/fluxos';
import { formatarKz } from '@/utilitarios/formatacao';
import { useRotaDaVista } from '../comum/componentes';
import { PainelProcesso, type EstadoPainel } from './componentes';
import { dataCurta, type LinhaProcesso } from './logica';
import './fluxo.css';

/**
 * Vista de volume do legado (js/fluxo_tabela.js), usada pela Lavandaria, Projectos e Obras, Bancos (43) e Folha de Caixa:
 * «Por etapa actual» (funil com as colunas do fluxo; clicar filtra), filtros rápidos com contagens, pesquisa, tabela
 * paginada com as colunas do fluxo e o diagrama do processo a abrir por baixo da linha escolhida. A configuração (funil,
 * filtros, colunas) e as regras vêm do servidor (GET /gestao/fluxos/{id} → vista); a filtragem é feita no servidor.
 */

export interface ColunaVolume {
  tipo: 'titulo' | 'texto' | 'conta' | 'data' | 'kz' | 'moeda' | 'pct' | 'etapa' | 'progresso' | 'pendencias' | 'documentos' | 'por_conciliar';
  rotulo: string;
  campo?: string;
  /** kz: sempre | opcional («—» se zero) | destaque (negrito, «—» se zero); moeda: sempre | diferenca */
  modo?: string;
  /** data: campo booleano que pinta a data a vermelho (prazo das ordens atrasadas) */
  alerta?: string;
  /** pendências: marca extra quando o campo é verdadeiro («Urgente», «Desvio») */
  marca?: { campo: string; texto: string };
}

export interface VistaVolumeConfig {
  funil: { id: string; rotulo: string; parados: number; com_pendencias: number; valor: string | null }[];
  filtros: { id: string; rotulo: string; total: number }[];
  filtro_inicial: string | null;
  colunas: ColunaVolume[];
  placeholder: string;
  nome_itens: string;
  por_pagina: number;
  vazio?: { icone: string; titulo: string; texto: string; botao: string; acao: string } | null;
}

export type LinhaVolume = LinhaProcesso & Record<string, unknown>;

const NUMERICAS = new Set(['data', 'kz', 'moeda', 'pct', 'documentos', 'por_conciliar']);
const vazio = <span style={{ color: '#94a3b8' }}>—</span>;
const num = (v: unknown) => Number(v ?? 0) || 0;
const kz = (v: unknown) => formatarKz(v as string, true);
const moedaTxt = (v: unknown, moeda: unknown) => `${formatarKz(v as string)} ${!moeda || moeda === 'AOA' ? 'Kz' : String(moeda)}`;

/** Conteúdo de uma célula conforme o tipo da coluna (celula, fluxo_tabela.js:149-160, e os td de cada fluxo). */
function celula(c: ColunaVolume, p: LinhaVolume, etapas: EtapaDiagrama[]): ReactNode {
  const v = c.campo ? p[c.campo] : undefined;
  switch (c.tipo) {
    case 'texto':
      return (
        <div className="ft-trunc" title={String(v ?? '')}>
          {v ? String(v) : '—'}
        </div>
      );
    case 'conta':
      return (
        <div className="ft-trunc" title={String(p.conta_nome ?? '')}>
          <strong>{String(p.conta ?? '')}</strong> {String(p.conta_nome ?? '')}
        </div>
      );
    case 'data':
      return <span style={c.alerta && p[c.alerta] ? { color: '#b91c1c', fontWeight: 600 } : undefined}>{dataCurta(v as string | null)}</span>;
    case 'pct':
      return `${num(v)}%`;
    case 'kz':
      if (c.modo === 'destaque') return num(v) > 0.01 ? <strong>{kz(v)}</strong> : vazio;
      if (c.modo === 'opcional') return num(v) ? kz(v) : vazio;
      return kz(v);
    case 'moeda':
      if (c.modo === 'diferenca')
        return Math.abs(num(v)) > 0.01 ? <strong style={{ color: num(v) < 0 ? '#b91c1c' : '#b45309' }}>{moedaTxt(v, p.moeda)}</strong> : vazio;
      return moedaTxt(v, p.moeda);
    case 'documentos': {
      const n = num(p.n_docs);
      const pi = num(p.por_integrar);
      return n ? (
        <>
          {n}
          {pi > 0 && <span className="ft-marca aviso"> ({pi} por integrar)</span>}
        </>
      ) : (
        vazio
      );
    }
    case 'por_conciliar': {
      const r = num(p.pendentes_razao);
      const x = num(p.pendentes_extracto);
      return r + x ? `${r} contab. · ${x} banco` : vazio;
    }
    case 'etapa':
      return p.etapa_actual ? (
        <>
          <PillEstado estado={p.etapa_actual_estado} texto={p.etapa_actual_nome ?? p.etapa_actual} />
          {p.etapa_actual_resumo && <span className="ft-resumo">{p.etapa_actual_resumo}</span>}
        </>
      ) : (
        <PillEstado estado="concluida" texto="Concluído" />
      );
    case 'progresso':
      return <MiniProgresso estados={etapas.map((e) => p.progresso?.[e.id])} rotulo={`${p.concluidas} de ${etapas.length} etapas`} />;
    case 'pendencias': {
      const extra = c.marca && p[c.marca.campo] ? <span className="ft-marca erro">{c.marca.texto}</span> : null;
      if (!p.n_erros && !p.n_avisos && !extra) return vazio;
      return (
        <>
          {p.n_erros > 0 && (
            <span className="ft-marca erro" title={`${p.n_erros} erro(s)`}>
              <IconeFa nome="times-circle" />
              {p.n_erros}
            </span>
          )}
          {p.n_avisos > 0 && (
            <span className="ft-marca aviso" title={`${p.n_avisos} aviso(s)`}>
              <IconeFa nome="exclamation-triangle" />
              {p.n_avisos}
            </span>
          )}
          {extra}
        </>
      );
    }
    default:
      return null;
  }
}

export function VistaVolume({
  fluxo,
  nomeFluxo,
  etapas,
  vista,
  total,
  narrativa,
  narrativaFluxo,
  aoMudarPainel,
}: {
  fluxo: string;
  nomeFluxo: string;
  etapas: EtapaDiagrama[];
  vista: VistaVolumeConfig;
  total: number;
  narrativa: boolean;
  narrativaFluxo?: { etapas: NarrativaDaEtapa[] } | null;
  aoMudarPainel?: (e: EstadoPainel | null) => void;
}) {
  const navegar = useNavigate();
  const rotaDaVista = useRotaDaVista();
  const [filtro, setFiltro] = useState<string | null>(vista.filtro_inicial);
  const [etapa, setEtapa] = useState<string | null>(null);
  const [texto, setTexto] = useState('');
  const [pesquisa, setPesquisa] = useState('');
  const [pagina, setPagina] = useState(1);
  const [sel, setSel] = useState<string | null>(null);
  const temporizador = useRef<ReturnType<typeof setTimeout>>();
  const porPagina = vista.por_pagina || 25;

  // pesquisa ao escrever, 200 ms depois da última tecla (fluxo_tabela.js:208-211)
  useEffect(() => {
    clearTimeout(temporizador.current);
    temporizador.current = setTimeout(() => {
      setPesquisa(texto.trim());
      setPagina(1);
    }, 200);
    return () => clearTimeout(temporizador.current);
  }, [texto]);

  const q = useQuery({
    queryKey: ['gestao', 'fluxos', fluxo, 'processos', { etapa, filtro, pesquisa, pagina, porPagina }],
    queryFn: () => obterPagina<LinhaVolume>(`/gestao/fluxos/${fluxo}/processos`, { etapa, filtro, pesquisa, pagina, por_pagina: porPagina }),
    placeholderData: keepPreviousData,
    enabled: total > 0,
  });

  if (total === 0 && vista.vazio) {
    const rota = rotaDaVista(vista.vazio.acao);
    return (
      <div className="fluxo-vazio">
        <IconeFa nome={vista.vazio.icone} style={{ fontSize: '1.6rem', color: '#94a3b8' }} />
        <strong>{vista.vazio.titulo}</strong>
        <span>{vista.vazio.texto}</span>
        <Button type="primary" disabled={!rota} onClick={() => rota && navegar(rota)}>
          {vista.vazio.botao}
        </Button>
      </div>
    );
  }

  const colEtapa = vista.funil.find((c) => c.id === etapa);
  const escolherEtapa = (id: string | null) => {
    setEtapa(id);
    // Concluídos não aparecem nos filtros de processos por concluir (fluxo_tabela.js:222)
    if (id === 'concluidos' && vista.filtros.some((f) => f.id === 'todas')) setFiltro('todas');
    setPagina(1);
  };
  const linhas = q.data?.itens ?? [];
  const pag = q.data?.paginacao;
  const nTotal = pag?.total ?? 0;
  const paginas = Math.max(1, pag?.ultima_pagina ?? 1);
  const ini = ((pag?.pagina_atual ?? pagina) - 1) * porPagina;
  const n = vista.colunas.length;

  return (
    <>
      <div>
        <p className="fluxo-titulo-seccao">Por etapa actual</p>
        <FunilFluxo
          activa={etapa}
          aoEscolher={escolherEtapa}
          colunas={vista.funil.map((c) => ({ id: c.id, rotulo: c.rotulo, numero: c.parados, valor: num(c.valor) > 0.004 ? kz(c.valor) : null, comPendencias: c.com_pendencias }))}
        />
      </div>

      <div>
        <div className="ft-barra">
          <div className="ft-chips" role="group" aria-label="Filtros">
            {vista.filtros.map((f) => (
              <button
                key={f.id}
                type="button"
                className="ft-chip"
                aria-pressed={filtro === f.id}
                onClick={() => {
                  setFiltro(f.id);
                  setPagina(1);
                }}
              >
                {f.rotulo}
                <b>{f.total}</b>
              </button>
            ))}
            {colEtapa && (
              <span className="ft-etapa-activa">
                Etapa: {colEtapa.rotulo}
                <button type="button" aria-label="Limpar a etapa" onClick={() => escolherEtapa(null)}>
                  ×
                </button>
              </span>
            )}
          </div>
          <input type="search" className="ft-pesquisa" placeholder={vista.placeholder} aria-label={vista.placeholder} value={texto} onChange={(e) => setTexto(e.target.value)} />
        </div>

        {q.isLoading ? (
          <Skeleton active />
        ) : !linhas.length ? (
          <div className="ft-tabela-wrap">
            <div className="ft-sem-resultados">Nenhum resultado para os filtros escolhidos.</div>
          </div>
        ) : (
          <>
            <div className="ft-tabela-wrap">
              <table className="ft-tabela">
                <thead>
                  <tr>
                    {vista.colunas.map((c, i) => (
                      <th key={i} className={NUMERICAS.has(c.tipo) ? 'num' : undefined}>
                        {c.rotulo}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {linhas.map((p) => {
                    const aberta = p.chave === sel;
                    const alternar = () => setSel(aberta ? null : p.chave);
                    return (
                      <Fragment key={p.chave}>
                        <tr className={`ft-linha${aberta ? ' sel' : ''}`} onClick={alternar}>
                          {vista.colunas.map((c, i) => (
                            <td key={i} className={NUMERICAS.has(c.tipo) ? 'num' : undefined}>
                              {c.tipo === 'titulo' ? (
                                <button
                                  type="button"
                                  className="ft-linha-btn"
                                  aria-expanded={aberta}
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    alternar();
                                  }}
                                >
                                  {p.titulo}
                                </button>
                              ) : (
                                celula(c, p, etapas)
                              )}
                            </td>
                          ))}
                        </tr>
                        {aberta && (
                          <tr className="ft-detalhe-linha">
                            <td colSpan={n}>
                              <div className="ft-detalhe">
                                <PainelProcesso
                                  fluxo={fluxo}
                                  nomeFluxo={nomeFluxo}
                                  etapas={etapas}
                                  chave={p.chave}
                                  narrativa={narrativa}
                                  narrativaFluxo={narrativaFluxo}
                                  aoMudar={aoMudarPainel}
                                />
                              </div>
                            </td>
                          </tr>
                        )}
                      </Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <div className="ft-rodape">
              <span>
                {ini + 1}–{ini + linhas.length} de {nTotal} {vista.nome_itens} · clique numa linha para ver o fluxo
              </span>
              {paginas > 1 && (
                <div className="ft-paginas">
                  <button type="button" disabled={pagina <= 1} onClick={() => setPagina(pagina - 1)} aria-label="Página anterior">
                    ‹
                  </button>
                  <span style={{ padding: '0 .3rem' }}>
                    Página {pagina} de {paginas}
                  </span>
                  <button type="button" disabled={pagina >= paginas} onClick={() => setPagina(pagina + 1)} aria-label="Página seguinte">
                    ›
                  </button>
                </div>
              )}
            </div>
          </>
        )}
      </div>
      <LegendaFluxo />
    </>
  );
}
