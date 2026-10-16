import { Alert, Button, DatePicker, Empty, Select, Skeleton, Table, Typography, message } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import { BotaoImprimir, esc, tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { GraficoBarras } from '@/componentes/graficos/Graficos';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarNumero } from '@/utilitarios/formatacao';
import { eNumerico, formatarPorFormato } from './comum/componentes';
import { dicaLeitura, dicaSentido, formatarRg, formatarVariacao, iconeModulo, legendaPeriodos, rotuloPeriodo, setaVariacao } from './relatorios/regras';
import './relatorios/relatorios.css';

interface Opcao {
  id: string;
  nome: string;
}

interface Catalogo {
  modulos: (Opcao & { descricao: string })[];
  presets_a: Opcao[];
  comparacoes: Opcao[];
}

interface Periodo {
  inicio: string;
  fim: string;
  nome: string;
  dias: number;
  rotulo: string;
}

interface Periodos {
  a: Periodo;
  b: Periodo | null;
  duracao_diferente: boolean;
}

export interface KpiGestao {
  chave: string;
  rotulo: string;
  formato: string;
  sentido: 'sobe' | 'desce' | 'neutro';
  ajuda?: string;
  actual: boolean;
  casas: number | null;
  a: unknown;
  b: unknown;
  variacao: { abs: unknown; pct: number | null };
  leitura: 'boa' | 'ma' | 'neutra';
}

interface TabelaRel {
  id: string;
  titulo: string;
  chave: string;
  colunas: { id: string; rotulo: string; formato: string | null }[];
  linhas: Record<string, unknown>[];
  coluna_principal: string | null;
}

/** Cores do período A (escuras) e do B (claras), como nos relatórios de gestão do legado (relatorios_gestao.js:825 — `coresA`/`coresB`; ficam fora de CORES_SERIES). */
const CORES_A = ['#1d4ed8', '#b45309', '#15803d'];
const CORES_B = ['#93c5fd', '#fcd34d', '#86efac'];

interface GraficoRel {
  id: string;
  titulo: string;
  tipo: string;
  rotulos_a: string[];
  rotulos_b: string[];
  series: { rotulo: string; a: (string | number | null)[]; b: (string | number | null)[] }[];
}

interface RelatorioModulo {
  modulo: Opcao & { descricao: string };
  periodos?: Periodos;
  notas: string[];
  kpis: KpiGestao[];
  tabelas: TabelaRel[];
  graficos: GraficoRel[];
}

interface Resumo {
  periodos: Periodos;
  blocos: { modulo: Opcao; kpis: KpiGestao[] }[];
  destaques: (KpiGestao & { modulo: string; modulo_id: string })[];
}

export type TodosGestao = Resumo & { modulos: RelatorioModulo[] };

interface ParamsPeriodo {
  preset_a?: string;
  a_inicio?: string;
  a_fim?: string;
  comparacao: string;
  b_inicio?: string;
  b_fim?: string;
}

interface Datas {
  inicio: string;
  fim: string;
}

/**
 * Geral › Relatórios de Gestão (renderRelatoriosGestao, modules/gestao/relatorios_gestao.js:664-760): período em análise
 * (A) e de comparação (B), cada um com o selector e as datas De/Até editáveis, «Actualizar» e a linha «A … (N dias) · B …»;
 * separadores sublinhados com ícone; resumo executivo com as variações relevantes (≥ 20 %) e os módulos em grelha de 2
 * colunas; «Imprimir» (separador activo), «Imprimir todos» e «Excel» (resumo e todos os módulos).
 * Endpoints: GET /gestao/relatorios, /periodos, /resumo, /todos, /{modulo}.
 */
export default function RelatoriosGestao() {
  const { empresa } = useSessao();
  const cliente = useQueryClient();
  const catalogo = useQuery({ queryKey: ['gestao', 'relatorios', 'catalogo'], queryFn: () => obter<Catalogo>('/gestao/relatorios'), staleTime: 3_600_000 });
  const [presetA, setPresetA] = useState('mes');
  const [livreA, setLivreA] = useState<Datas | null>(null);
  const [presetB, setPresetB] = useState('homologo');
  const [livreB, setLivreB] = useState<Datas | null>(null);
  const [separador, setSeparador] = useState('resumo');
  const area = useRef<HTMLDivElement>(null);

  const params = useMemo<ParamsPeriodo | null>(() => {
    const p: ParamsPeriodo = { comparacao: presetB };
    if (presetA === 'livre') {
      if (!livreA) return null;
      p.a_inicio = livreA.inicio;
      p.a_fim = livreA.fim;
    } else p.preset_a = presetA;
    if (presetB === 'livre') {
      if (!livreB) return null;
      p.b_inicio = livreB.inicio;
      p.b_fim = livreB.fim;
    }
    return p;
  }, [presetA, livreA, presetB, livreB]);

  const periodos = useQuery({
    queryKey: ['gestao', 'relatorios', 'periodos', params],
    queryFn: () => obter<Periodos>('/gestao/relatorios/periodos', params as unknown as Record<string, unknown>),
    enabled: !!params,
    placeholderData: (anterior) => anterior,
  });

  if (catalogo.isLoading) return <Skeleton active />;
  const cat = catalogo.data;
  if (!cat) return <Alert type="error" showIcon message="Não foi possível carregar os relatórios de gestão." />;

  const p = periodos.data;
  const datasA: Datas | null = presetA === 'livre' ? livreA : p ? { inicio: p.a.inicio, fim: p.a.fim } : null;
  const datasB: Datas | null = presetB === 'livre' ? livreB : presetB === 'nenhum' ? null : p?.b ? { inicio: p.b.inicio, fim: p.b.fim } : null;
  const separadores = [{ id: 'resumo', nome: 'Resumo executivo' }, ...cat.modulos];
  const nomeSeparador = separadores.find((s) => s.id === separador)?.nome ?? separador;
  const textoPeriodo = p ? `A: ${rotuloPeriodo(p.a)}${p.b ? ` · B: ${rotuloPeriodo(p.b)}` : ''}` : undefined;

  // As datas editadas de A passam o selector para «Datas à escolha» (como no legado); as de B só com «Datas à escolha».
  const mudarA = (campo: keyof Datas, d: Dayjs | null) => {
    if (!d || !datasA) return;
    const novo = { ...datasA, [campo]: dataApi(d) as string };
    if (novo.inicio > novo.fim) {
      message.warning('Indique datas válidas para o período A (início ≤ fim).');
      return;
    }
    setLivreA(novo);
    setPresetA('livre');
  };
  const mudarB = (campo: keyof Datas, d: Dayjs | null) => {
    if (!d || !datasB) return;
    const novo = { ...datasB, [campo]: dataApi(d) as string };
    if (novo.inicio > novo.fim) {
      message.warning('Indique datas válidas para o período B (início ≤ fim).');
      return;
    }
    setLivreB(novo);
  };
  const escolherPresetA = (v: string) => {
    if (v === 'livre' && !livreA && datasA) setLivreA(datasA);
    setPresetA(v);
  };
  const escolherPresetB = (v: string) => {
    if (v === 'livre' && !livreB) setLivreB(datasB ?? datasA);
    setPresetB(v);
  };
  const actualizar = () => void cliente.invalidateQueries({ predicate: (q) => q.queryKey[0] === 'gestao' && q.queryKey[1] === 'relatorios' && q.queryKey[2] !== 'catalogo' });

  const pedidoTodos = async (): Promise<PedidoImpressao | null> => {
    if (!params) return null;
    const t = await obter<TodosGestao>('/gestao/relatorios/todos', params as unknown as Record<string, unknown>);
    return {
      titulo: 'Relatório de Gestão',
      subtitulo: 'Resumo executivo e todos os módulos',
      periodo: textoPeriodo,
      filtros: p?.duracao_diferente ? 'Períodos com duração diferente' : undefined,
      conteudo: htmlTodos(t, !!t.periodos.b),
    };
  };

  const teclado = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    const i = separadores.findIndex((s) => s.id === separador);
    const n = separadores[(i + (e.key === 'ArrowRight' ? 1 : -1) + separadores.length) % separadores.length];
    setSeparador(n.id);
    (e.currentTarget.querySelector(`[data-mod="${n.id}"]`) as HTMLButtonElement | null)?.focus();
  };

  const legenda = p ? legendaPeriodos(p.a, p.b) : null;

  // Imprime o separador activo (resumo executivo ou módulo), com os indicadores, gráficos e tabelas.
  const pedidoActivo = () => {
    const painel = area.current;
    if (!painel) return null;
    return {
      titulo: `Relatório de Gestão — ${nomeSeparador}`,
      periodo: textoPeriodo,
      filtros: p?.duracao_diferente ? 'Períodos com duração diferente' : undefined,
      conteudo: painel,
    };
  };

  return (
    <div className="rg">
      <CabecalhoPagina
        titulo="Relatórios de Gestão"
        subtitulo={`${empresa?.nome ? `${empresa.nome} · ` : ''}Indicadores por módulo, com comparação entre períodos`}
        accoes={
          // ordem do legado (relatorios_gestao.js): «Imprimir» (separador activo), «Imprimir todos», «Excel»; o PDF do novo fica no fim
          <>
            <BotaoImprimir obterPedido={pedidoActivo} texto="Imprimir" desactivado={!params} chave="relatorios_gestao_activo" />
            <span title="Resumo e todos os módulos">
              <BotaoImprimir obterPedido={pedidoTodos} texto="Imprimir todos" desactivado={!params} chave="relatorios_gestao_todos" />
            </span>
            <BotaoImprimir obterPedido={pedidoTodos} modo="excel" texto="Excel" desactivado={!params} chave="relatorios_gestao_todos" />
            <BotaoImprimir obterPedido={pedidoActivo} modo="pdf" desactivado={!params} chave="relatorios_gestao_activo" />
          </>
        }
      />
      <div className="rg-barra">
        <div className="rg-periodo a">
          <div className="rg-campo">
            <label htmlFor="rg-preset-a">Período em análise (A)</label>
            <Select id="rg-preset-a" style={{ width: 180 }} value={presetA} onChange={escolherPresetA} options={cat.presets_a.map((o) => ({ value: o.id, label: o.nome }))} />
          </div>
          <div className="rg-campo">
            <label htmlFor="rg-a-ini">De</label>
            <DatePicker id="rg-a-ini" format="DD/MM/YYYY" allowClear={false} style={{ width: 140 }} value={datasA ? dayjs(datasA.inicio) : null} onChange={(d) => mudarA('inicio', d)} />
          </div>
          <div className="rg-campo">
            <label htmlFor="rg-a-fim">Até</label>
            <DatePicker id="rg-a-fim" format="DD/MM/YYYY" allowClear={false} style={{ width: 140 }} value={datasA ? dayjs(datasA.fim) : null} onChange={(d) => mudarA('fim', d)} />
          </div>
        </div>
        <div className="rg-periodo b">
          <div className="rg-campo">
            <label htmlFor="rg-preset-b">Comparar com (B)</label>
            <Select id="rg-preset-b" style={{ width: 200 }} value={presetB} onChange={escolherPresetB} options={cat.comparacoes.map((o) => ({ value: o.id, label: o.nome }))} />
          </div>
          <div className="rg-campo">
            <label htmlFor="rg-b-ini">De</label>
            <DatePicker id="rg-b-ini" format="DD/MM/YYYY" allowClear={false} style={{ width: 140 }} disabled={presetB !== 'livre'} value={datasB ? dayjs(datasB.inicio) : null} onChange={(d) => mudarB('inicio', d)} />
          </div>
          <div className="rg-campo">
            <label htmlFor="rg-b-fim">Até</label>
            <DatePicker id="rg-b-fim" format="DD/MM/YYYY" allowClear={false} style={{ width: 140 }} disabled={presetB !== 'livre'} value={datasB ? dayjs(datasB.fim) : null} onChange={(d) => mudarB('fim', d)} />
          </div>
        </div>
        <Button type="primary" icon={<IconeFa nome="sync-alt" />} onClick={actualizar} loading={periodos.isFetching} disabled={!params}>
          Actualizar
        </Button>
        {legenda && (
          <div className="rg-legenda" aria-live="polite">
            <b style={{ color: '#1d4ed8' }}>A</b> {legenda.a}
            {legenda.b && (
              <>
                {' · '}
                <b style={{ color: '#64748b' }}>B</b> {legenda.b}
              </>
            )}
            {p?.duracao_diferente && (
              <span style={{ color: '#b45309' }}>
                {' · '}
                <IconeFa nome="exclamation-triangle" /> períodos com duração diferente: compare indicadores médios e percentagens
              </span>
            )}
          </div>
        )}
      </div>
      <div className="rg-tabs" role="tablist" aria-label="Módulos" onKeyDown={teclado}>
        {separadores.map((s) => (
          <button
            key={s.id}
            type="button"
            className="rg-tab"
            role="tab"
            data-mod={s.id}
            aria-selected={s.id === separador}
            tabIndex={s.id === separador ? 0 : -1}
            onClick={() => setSeparador(s.id)}
          >
            <IconeFa nome={iconeModulo(s.id)} /> {s.nome}
          </button>
        ))}
      </div>
      <div ref={area} role="tabpanel" aria-label={nomeSeparador}>
        {!params ? (
          <Alert type="info" showIcon message="Escolha as datas do período." />
        ) : separador === 'resumo' ? (
          <ResumoExecutivo params={params} aoAbrir={setSeparador} />
        ) : (
          <RelatorioDoModulo key={separador} modulo={separador} params={params} />
        )}
      </div>
    </div>
  );
}

/** Mini-cartão do legado (cartaoKpi, relatorios_gestao.js:766-774): borda superior pela leitura, «B: …» e variação ▲/▼. */
export function CartaoRg({ k, temB }: { k: KpiGestao; temB: boolean }) {
  const leitura = temB ? k.leitura : 'neutra';
  return (
    <div className={`rg-kpi ${leitura}`}>
      <div className="rg-kpi-rot">
        <span>
          {k.rotulo}{' '}
          {k.actual && (
            <span className="rg-tag" title="Situação actual, sem histórico">
              actual
            </span>
          )}
        </span>
        {k.ajuda && (
          <span className="rg-ajuda" title={dicaSentido(k.ajuda, k.sentido)} aria-label={dicaSentido(k.ajuda, k.sentido)}>
            <IconeFa nome="info-circle" />
          </span>
        )}
      </div>
      <div className="rg-kpi-a">{formatarRg(k.a, k.formato, k.casas)}</div>
      {temB && (
        <>
          <div className="rg-kpi-b">B: {formatarRg(k.b, k.formato, k.casas)}</div>
          <div className={`rg-var ${leitura}`} title={dicaLeitura(leitura)}>
            {setaVariacao(k.variacao.abs)} {formatarVariacao(k.variacao.abs, k.variacao.pct, k.formato)}
          </div>
        </>
      )}
    </div>
  );
}

function ResumoExecutivo({ params, aoAbrir }: { params: ParamsPeriodo; aoAbrir: (modulo: string) => void }) {
  const q = useQuery({ queryKey: ['gestao', 'relatorios', 'resumo', params], queryFn: () => obter<Resumo>('/gestao/relatorios/resumo', params as unknown as Record<string, unknown>) });
  if (q.isLoading) return <Skeleton active />;
  if (q.error || !q.data) return <Alert type="error" showIcon message={(q.error as Error | null)?.message ?? 'Erro ao carregar o resumo.'} />;
  const temB = !!q.data.periodos.b;
  return (
    <>
      {temB && (
        <div className="rg-cartao" style={{ marginBottom: 12 }}>
          <h4>
            <IconeFa nome="bullseye" /> Variações relevantes (≥ 20 %)
          </h4>
          {q.data.destaques.length ? (
            <div style={{ overflowX: 'auto' }}>
              <table className="rg-tabela">
                <thead>
                  <tr>
                    <th>Módulo</th>
                    <th>Indicador</th>
                    <th className="n">A</th>
                    <th className="n">Variação</th>
                    <th>Leitura</th>
                  </tr>
                </thead>
                <tbody>
                  {q.data.destaques.map((d) => (
                    <tr key={`${d.modulo_id}-${d.chave}`}>
                      <td>{d.modulo}</td>
                      <td>{d.rotulo}</td>
                      <td className="n">{formatarRg(d.a, d.formato, d.casas)}</td>
                      <td className={`n rg-var ${d.leitura}`}>{formatarVariacao(d.variacao.abs, d.variacao.pct, d.formato)}</td>
                      <td className={`rg-var ${d.leitura}`}>{d.leitura === 'boa' ? 'Favorável' : 'Desfavorável'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p style={{ margin: 0, color: '#64748b', fontSize: '0.85rem' }}>Nenhum indicador variou 20 % ou mais entre os períodos.</p>
          )}
        </div>
      )}
      <div className="rg-grid2">
        {q.data.blocos.map((b) => (
          <div key={b.modulo.id} className="rg-cartao">
            <h4>
              <IconeFa nome={iconeModulo(b.modulo.id)} /> {b.modulo.nome}
              <Button className="rg-ver imp-nao-imprimir" size="small" onClick={() => aoAbrir(b.modulo.id)}>
                Ver módulo
              </Button>
            </h4>
            {b.kpis.length ? (
              <div className="rg-kpis compacto">
                {b.kpis.map((k) => (
                  <CartaoRg key={k.chave} k={k} temB={temB} />
                ))}
              </div>
            ) : (
              <Typography.Text type="secondary">Sem indicadores no resumo.</Typography.Text>
            )}
          </div>
        ))}
      </div>
    </>
  );
}

function RelatorioDoModulo({ modulo, params }: { modulo: string; params: ParamsPeriodo }) {
  const q = useQuery({ queryKey: ['gestao', 'relatorios', modulo, params], queryFn: () => obter<RelatorioModulo>(`/gestao/relatorios/${modulo}`, params as unknown as Record<string, unknown>) });
  if (q.isLoading) return <Skeleton active />;
  if (q.error || !q.data) return <Alert type="error" showIcon message={(q.error as Error | null)?.message ?? 'Erro ao carregar o relatório.'} />;
  const r = q.data;
  const temB = !!r.periodos?.b;
  return (
    <>
      <p style={{ margin: '0 0 10px', color: '#475569', fontSize: '0.85rem' }}>{r.modulo.descricao}</p>
      {r.notas.map((n, i) => (
        <div key={i} className="rg-nota">
          <IconeFa nome="info-circle" /> {n}
        </div>
      ))}
      <div className="rg-kpis">
        {r.kpis.map((k) => (
          <CartaoRg key={k.chave} k={k} temB={temB} />
        ))}
      </div>
      {r.graficos.length > 0 && (
        <div className="rg-grid2" style={{ marginBottom: 12 }}>
          {r.graficos.map((g) => (
            <div key={g.id} className="rg-cartao">
              <GraficoBarras
                titulo={g.titulo}
                rotulos={g.rotulos_a.map((x, i) => (temB && g.rotulos_b[i] ? `${x} / ${g.rotulos_b[i]}` : x))}
                monetario
                series={g.series.flatMap((s, i) => [
                  { rotulo: `${s.rotulo} (A)`, valores: s.a, cor: CORES_A[i % 3] },
                  ...(temB && s.b.length ? [{ rotulo: `${s.rotulo} (B)`, valores: s.b, cor: CORES_B[i % 3] }] : []),
                ])}
              />
            </div>
          ))}
        </div>
      )}
      <div className="rg-grid2">
        {r.tabelas.map((t) => (
          <div key={t.id} className="rg-cartao">
            <h4>{t.titulo}</h4>
            <Table<Record<string, unknown>>
              size="small"
              rowKey={(l) => String(l[t.chave])}
              dataSource={t.linhas}
              pagination={false}
              scroll={scrollTabela()}
              locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem dados no período A." /> }}
              columns={[
                ...t.colunas.map((c) => ({
                  title: `${c.rotulo}${c.formato && temB ? ' (A)' : ''}`,
                  dataIndex: c.id,
                  align: eNumerico(c.formato) ? ('right' as const) : undefined,
                  render: (v: unknown) => <span style={{ whiteSpace: 'nowrap' }}>{formatarPorFormato(v, c.formato)}</span>,
                })),
                ...(temB && t.coluna_principal
                  ? [
                      {
                        title: `${t.colunas.find((c) => c.id === t.coluna_principal)?.rotulo ?? ''} (B)`,
                        dataIndex: 'b',
                        align: 'right' as const,
                        render: (v: unknown) => formatarPorFormato(v, t.colunas.find((c) => c.id === t.coluna_principal)?.formato),
                      },
                      {
                        title: 'Δ %',
                        dataIndex: 'variacao_pct',
                        align: 'right' as const,
                        render: (v: number | null) => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${formatarNumero(v)} %`),
                      },
                    ]
                  : []),
              ]}
            />
          </div>
        ))}
      </div>
    </>
  );
}

/** HTML de «Imprimir todos» / «Excel» (imprimir, relatorios_gestao.js:853-876): resumo e cada módulo, um por página. */
export function htmlTodos(t: TodosGestao, temB: boolean): string {
  const kpisHtml = (kpis: KpiGestao[], legenda?: string) =>
    tabelaHtml<KpiGestao>({
      legenda,
      linhas: kpis,
      colunas: [
        { titulo: 'Indicador', valor: (k) => `${k.rotulo}${k.actual ? ' (actual)' : ''}`, quebrar: true },
        { titulo: 'A', valor: (k) => formatarRg(k.a, k.formato, k.casas), alinhamento: 'direita' },
        ...(temB
          ? [
              { titulo: 'B', valor: (k: KpiGestao) => formatarRg(k.b, k.formato, k.casas), alinhamento: 'direita' as const },
              { titulo: 'Variação', valor: (k: KpiGestao) => formatarVariacao(k.variacao.abs, k.variacao.pct, k.formato), alinhamento: 'direita' as const },
              { titulo: 'Leitura', valor: (k: KpiGestao) => (k.leitura === 'boa' ? 'Favorável' : k.leitura === 'ma' ? 'Desfavorável' : '') },
            ]
          : []),
      ],
    });
  const tabelaRel = (tb: TabelaRel) => {
    const principal = tb.colunas.find((c) => c.id === tb.coluna_principal);
    return tabelaHtml<Record<string, unknown>>({
      legenda: tb.titulo,
      linhas: tb.linhas,
      vazio: 'Sem dados no período A.',
      colunas: [
        ...tb.colunas.map((c) => ({
          titulo: `${c.rotulo}${c.formato && temB ? ' (A)' : ''}`,
          valor: (l: Record<string, unknown>) => formatarPorFormato(l[c.id], c.formato),
          alinhamento: eNumerico(c.formato) ? ('direita' as const) : ('esquerda' as const),
        })),
        ...(temB && principal
          ? [
              { titulo: `${principal.rotulo} (B)`, valor: (l: Record<string, unknown>) => formatarPorFormato(l.b, principal.formato), alinhamento: 'direita' as const },
              {
                titulo: 'Δ %',
                valor: (l: Record<string, unknown>) => (l.variacao_pct === null || l.variacao_pct === undefined ? '—' : `${Number(l.variacao_pct) > 0 ? '+' : ''}${formatarNumero(l.variacao_pct as number)} %`),
                alinhamento: 'direita' as const,
              },
            ]
          : []),
      ],
    });
  };
  const seccao = (titulo: string, corpo: string, primeira: boolean) =>
    `<section style="${primeira ? '' : 'break-before:page;'}"><h3 style="margin:0 0 6px; font-size:12pt; border-bottom:1px solid #0f172a;">${esc(titulo)}</h3>${corpo}</section>`;
  const destaques = temB
    ? t.destaques.length
      ? tabelaHtml<Resumo['destaques'][number]>({
          legenda: 'Variações relevantes (≥ 20 %)',
          linhas: t.destaques,
          colunas: [
            { titulo: 'Módulo', valor: (d) => d.modulo },
            { titulo: 'Indicador', valor: (d) => d.rotulo, quebrar: true },
            { titulo: 'A', valor: (d) => formatarRg(d.a, d.formato, d.casas), alinhamento: 'direita' },
            { titulo: 'Variação', valor: (d) => formatarVariacao(d.variacao.abs, d.variacao.pct, d.formato), alinhamento: 'direita' },
            { titulo: 'Leitura', valor: (d) => (d.leitura === 'boa' ? 'Favorável' : 'Desfavorável') },
          ],
        })
      : '<p>Nenhum indicador variou 20 % ou mais entre os períodos.</p>'
    : '';
  const resumo = destaques + t.blocos.filter((b) => b.kpis.length).map((b) => kpisHtml(b.kpis, b.modulo.nome)).join('');
  return [
    seccao('Resumo executivo', resumo, true),
    ...t.modulos.map((m) =>
      seccao(m.modulo.nome, `${m.notas.map((n) => `<p style="font-size:9pt; color:#92400e">${esc(n)}</p>`).join('')}${kpisHtml(m.kpis, 'Indicadores')}${m.tabelas.map(tabelaRel).join('')}`, false),
    ),
  ].join('');
}
