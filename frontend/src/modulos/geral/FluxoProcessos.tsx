import { Alert, Button, Descriptions, Drawer, Empty, Flex, Input, Skeleton, Space, Tabs, Tag, Typography } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar } from '@/componentes/impressao';
import { larguraGaveta } from '@/componentes/responsivo';
import { TabelaApi } from '@/componentes/TabelaApi';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { GraficoBarras } from '@/componentes/graficos/Graficos';
import {
  DetalheEtapa,
  DiagramaFluxo,
  FunilFluxo,
  IconeFa,
  KpisFluxo,
  LegendaFluxo,
  MarcasPendencias,
  MiniProgresso,
  NarrativaEtapa,
  PillEstado,
  RaizFluxo,
  CSS_WORKFLOW,
  ESTADOS_FLUXO,
  ORDEM_ESTADOS,
  estadoDe,
  estadoProcesso,
  workflowHtml,
  type EtapaDiagrama,
  type NarrativaDaEtapa,
} from '@/componentes/fluxos';
import { formatarPorFormato, useRotaDaVista } from './comum/componentes';

interface Etapa extends EtapaDiagrama {
  icone: string | null;
}

interface FluxoResumoLista {
  id: string;
  nome: string;
  sub: string;
  icone?: string | null;
  tem_actividade: boolean;
  etapas: Etapa[];
}

interface Narrativa {
  titulo: string;
  objectivo: string;
  intervenientes: string[];
  etapas: NarrativaDaEtapa[];
}

interface Kpi {
  chave: string;
  rotulo: string;
  valor: unknown;
  formato: string;
  alerta: boolean;
}

interface ResumoFluxo {
  fluxo: FluxoResumoLista & { narrativa?: Narrativa | null };
  tem_actividade: boolean;
  total: number;
  kpis: Kpi[];
  funil: {
    etapa: string;
    nome: string;
    parados: number;
    bloqueados: number;
    com_pendencias: number;
    valor: string | null;
    valor_pendente: string | null;
    estados: Record<'concluida' | 'curso' | 'fazer' | 'bloqueada', number> | null;
  }[];
  extra?: { periodos_em_falta?: { meses: string[]; primeiro: string | null; ultimo: string | null } } | null;
}

interface ProcessoLista {
  chave: string;
  titulo: string;
  subtitulo: string | null;
  data: string | null;
  valor: string | null;
  valor_pendente: string | null;
  total_etapas: number;
  concluidas: number;
  etapa_actual: string | null;
  etapa_actual_nome: string | null;
  etapa_actual_estado: string | null;
  etapa_actual_resumo: string | null;
  bloqueado: boolean;
  n_erros: number;
  n_avisos: number;
  progresso: Record<string, string>;
}

interface EtapaProcesso {
  estado: string;
  resumo: string | null;
  factos: { rotulo: string; valor: unknown; formato: string }[];
  problemas: { nivel: 'erro' | 'aviso' | string; texto: string }[];
  accoes: { rotulo: string; acao: string; primaria: boolean }[];
  narrativa: NarrativaDaEtapa | null;
}

interface DetalheProcesso extends Omit<ProcessoLista, 'progresso' | 'etapa_actual_nome' | 'etapa_actual_estado' | 'etapa_actual_resumo'> {
  etapas: Record<string, EtapaProcesso>;
  documentos: { tipo: string; id: number; tipo_documento?: string; numero?: string }[];
  fluxo: { id: string; nome: string };
}

/** Filtros rápidos (ft-chips do legado) → filtro «estado» da API. */
const FILTROS: { id: string | undefined; rotulo: string }[] = [
  { id: undefined, rotulo: 'Todos' },
  { id: 'em_curso', rotulo: 'Em curso' },
  { id: 'com_pendencias', rotulo: 'Com pendências' },
  { id: 'bloqueado', rotulo: 'Bloqueados' },
  { id: 'concluido', rotulo: 'Concluídos' },
];

const CHAVE_NARRATIVA = 'fluxo.narrativa';
const lerNarrativa = () => {
  try {
    return localStorage.getItem(CHAVE_NARRATIVA) === '1';
  } catch {
    return false;
  }
};

const kz = (v: string | null | undefined) => (v && Number(v) !== 0 ? `${formatarKz(v)} Kz` : null);
const factosTexto = (f: EtapaProcesso['factos']) => f.map((x) => ({ rotulo: x.rotulo, texto: formatarPorFormato(x.valor, x.formato) }));
const kpisTexto = (k: Kpi[]) => k.map((x) => ({ chave: x.chave, rotulo: x.rotulo, texto: `${formatarPorFormato(x.valor, x.formato)}${x.formato === 'kz' && x.valor !== null && x.valor !== undefined ? ' Kz' : ''}`, alerta: x.alerta }));

/** Etapa a mostrar ao abrir um processo (fluxo_processos.js:373): a bloqueada, senão a em curso, a por fazer ou a última. */
function etapaInicial(etapas: Etapa[], d: DetalheProcesso): string {
  const com = (e: string) => etapas.find((x) => estadoDe(d.etapas[x.id]?.estado) === e);
  return (com('bloqueada') ?? com('curso') ?? com('fazer') ?? etapas[etapas.length - 1]).id;
}

/**
 * Geral › Fluxo de Processos (js/fluxo_processos.js, fluxo_tabela.js, fluxo_narrativa.js): separadores com os fluxos com
 * actividade, indicadores, funil por etapa actual, lista de processos e, por processo, o diagrama das etapas com o detalhe,
 * a narrativa, a impressão do workflow e a apresentação em ecrã inteiro. Mesma linguagem visual do legado
 * (componentes/fluxos). Dados: GET /gestao/fluxos/*.
 */
export default function FluxoProcessos() {
  const cliente = useQueryClient();
  const lista = useQuery({ queryKey: ['gestao', 'fluxos'], queryFn: () => obter<FluxoResumoLista[]>('/gestao/fluxos'), staleTime: 300_000 });
  const [activo, setActivo] = useState<string | undefined>();
  const [narrativa, setNarrativaEstado] = useState(lerNarrativa);
  const [apresentar, setApresentar] = useState<'nativo' | 'janela' | null>(null);
  const refApres = useRef<HTMLDivElement>(null);
  const navegarEtapa = useRef<((delta: number) => void) | null>(null);
  const resumoActivo = useRef<ResumoFluxo | null>(null);

  const setNarrativa = useCallback((v: boolean) => {
    setNarrativaEstado(v);
    try {
      localStorage.setItem(CHAVE_NARRATIVA, v ? '1' : '0');
    } catch {
      /* sem armazenamento */
    }
  }, []);

  // Ecrã inteiro: nativo, ou sobreposto à janela se o navegador o recusar (fluxo_narrativa.js:263-276)
  const alternarEcraInteiro = useCallback(async () => {
    const alvo = refApres.current;
    if (!alvo) return;
    if (document.fullscreenElement) {
      await document.exitFullscreen().catch(() => undefined);
      return;
    }
    if (apresentar === 'janela') {
      setApresentar(null);
      return;
    }
    let nativo = false;
    if (alvo.requestFullscreen) {
      try {
        await Promise.race([alvo.requestFullscreen(), new Promise((r) => setTimeout(r, 1000))]);
        nativo = document.fullscreenElement === alvo;
      } catch {
        nativo = false;
      }
    }
    setApresentar(nativo ? 'nativo' : 'janela');
    setNarrativa(true);
  }, [apresentar, setNarrativa]);

  useEffect(() => {
    const aoMudar = () => setApresentar((a) => (document.fullscreenElement ? 'nativo' : a === 'nativo' ? null : a));
    document.addEventListener('fullscreenchange', aoMudar);
    return () => document.removeEventListener('fullscreenchange', aoMudar);
  }, []);

  useEffect(() => {
    if (!apresentar) return;
    document.body.style.overflow = apresentar === 'janela' ? 'hidden' : '';
    const teclas = (ev: KeyboardEvent) => {
      if (ev.key === 'Escape' && apresentar === 'janela') {
        setApresentar(null);
        return;
      }
      if (/INPUT|SELECT|TEXTAREA/.test((ev.target as HTMLElement | null)?.tagName ?? '')) return;
      if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
        if (navegarEtapa.current) {
          ev.preventDefault();
          navegarEtapa.current(ev.key === 'ArrowRight' ? 1 : -1);
        }
      } else if (ev.key === 'n' || ev.key === 'N') setNarrativa(!lerNarrativa());
    };
    document.addEventListener('keydown', teclas);
    return () => {
      document.removeEventListener('keydown', teclas);
      document.body.style.overflow = '';
    };
  }, [apresentar, setNarrativa]);

  if (lista.isLoading) return <Skeleton active />;
  const fluxos = (lista.data ?? []).filter((f) => f.tem_actividade);
  const todos = lista.data ?? [];
  const actual = fluxos.find((f) => f.id === activo) ?? fluxos[0];

  const imprimirWorkflowGeral = () => {
    const r = resumoActivo.current;
    if (!actual) return null;
    return {
      titulo: `Workflow — ${r?.fluxo.narrativa?.titulo ?? actual.nome}`,
      subtitulo: 'Workflow com narrativa',
      conteudo: workflowHtml({ narrativa: r?.fluxo.narrativa ?? null, etapas: actual.etapas, kpis: r ? kpisTexto(r.kpis) : [] }),
      cssExtra: CSS_WORKFLOW,
      orientacao: 'paisagem' as const,
    };
  };

  return (
    <>
      <CabecalhoPagina
        titulo="Fluxo de Processos"
        subtitulo={actual?.sub ?? 'Onde está cada processo, o que falta fazer e quem o faz'}
        accoes={
          actual && (
            <>
              <Button icon={<IconeFa nome="sync-alt" />} onClick={() => void cliente.invalidateQueries({ queryKey: ['gestao', 'fluxos'] })}>
                Actualizar
              </Button>
              <Button icon={<IconeFa nome="book-open" />} aria-pressed={narrativa} onClick={() => setNarrativa(!narrativa)}>
                {narrativa ? 'Ocultar narrativa' : 'Mostrar narrativa'}
              </Button>
              <BotoesExportar excel={false} seletorPagina={false} textoImprimir="Imprimir workflow" obterPedido={imprimirWorkflowGeral} />
              <Button icon={<IconeFa nome="expand" />} onClick={() => void alternarEcraInteiro()}>
                Ecrã inteiro
              </Button>
            </>
          )
        }
      />
      {todos.length === 0 ? (
        <Empty description="Sem acesso a nenhum fluxo. Peça acesso aos módulos cujos processos quer acompanhar." />
      ) : !actual ? (
        <RaizFluxo>
          <Empty
            image={<IconeFa nome="project-diagram" style={{ fontSize: 28, color: '#94a3b8' }} />}
            imageStyle={{ height: 36 }}
            description={
              <>
                <strong>Ainda não há processos para acompanhar</strong>
                <br />
                Cada fluxo aparece quando a empresa registar a primeira transacção no módulo onde o processo começa (por exemplo, o primeiro período de salários, pedido de compra ou sessão de caixa).
              </>
            }
          />
        </RaizFluxo>
      ) : (
        <RaizFluxo>
          <div ref={refApres} className={`fluxo-apresentacao${apresentar === 'janela' ? ' fluxo-apres-janela' : ''}`} style={{ position: 'relative' }}>
            <div className="fluxo-apres-topo">
              <div>
                <strong>Fluxo de Processos · {actual.nome}</strong>
                <br />
                <span>{new Date().toLocaleDateString('pt-PT')} · ← → mudar de etapa · N narrativa · Esc sair</span>
              </div>
              <Button icon={<IconeFa nome="compress" />} onClick={() => void alternarEcraInteiro()}>
                Sair do ecrã inteiro
              </Button>
            </div>
            <Tabs
              activeKey={actual.id}
              onChange={setActivo}
              destroyOnHidden
              items={fluxos.map((f) => ({
                key: f.id,
                label: (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <IconeFa nome={f.icone} /> {f.nome}
                  </span>
                ),
                children: (
                  <Fluxo
                    fluxo={f}
                    narrativa={narrativa}
                    contentor={apresentar ? () => refApres.current ?? document.body : undefined}
                    registarNavegacao={(fn) => (navegarEtapa.current = fn)}
                    aoCarregar={(r) => (resumoActivo.current = r)}
                  />
                ),
              }))}
            />
          </div>
        </RaizFluxo>
      )}
    </>
  );
}

function Fluxo({
  fluxo,
  narrativa,
  contentor,
  registarNavegacao,
  aoCarregar,
}: {
  fluxo: FluxoResumoLista;
  narrativa: boolean;
  contentor?: () => HTMLElement;
  registarNavegacao: (fn: ((delta: number) => void) | null) => void;
  aoCarregar: (r: ResumoFluxo) => void;
}) {
  const navegar = useNavigate();
  const rotaDaVista = useRotaDaVista();
  const resumo = useQuery({ queryKey: ['gestao', 'fluxos', fluxo.id], queryFn: () => obter<ResumoFluxo>(`/gestao/fluxos/${fluxo.id}`) });
  const [etapa, setEtapa] = useState<string | undefined>();
  const [estado, setEstado] = useState<string | undefined>();
  const [pesquisa, setPesquisa] = useState('');
  const [aberto, setAberto] = useState<string | null>(null);

  useEffect(() => {
    if (resumo.data) aoCarregar(resumo.data);
  }, [resumo.data, aoCarregar]);

  if (resumo.isLoading) return <Skeleton active />;
  if (resumo.error || !resumo.data) return <Alert type="error" showIcon message={(resumo.error as Error | null)?.message ?? 'Não foi possível calcular o estado dos processos.'} />;
  const r = resumo.data;
  const nomeEtapa = (id: string | null) => fluxo.etapas.find((e) => e.id === id)?.nome ?? r.funil.find((f) => f.etapa === id)?.nome ?? id ?? '—';
  const comEstados = r.funil.filter((f) => f.estados);
  const concluidos = r.funil.find((f) => f.etapa === 'concluidos');
  const porConcluir = r.funil.filter((f) => f.etapa !== 'concluidos');
  const contagem: Record<string, number> = {
    todos: r.total,
    em_curso: porConcluir.reduce((s, f) => s + f.parados - f.bloqueados, 0),
    com_pendencias: r.funil.reduce((s, f) => s + f.com_pendencias, 0),
    bloqueado: porConcluir.reduce((s, f) => s + f.bloqueados, 0),
    concluido: concluidos?.parados ?? 0,
  };
  const buracos = r.extra?.periodos_em_falta;
  const rotaCalcular = rotaDaVista('calcular');

  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      {!r.tem_actividade && <Alert type="info" showIcon message="Ainda não há processos neste fluxo na empresa activa." />}
      <KpisFluxo kpis={kpisTexto(r.kpis)} />

      {buracos && buracos.meses.length > 0 && (
        <section className="fluxo-buracos" aria-label="Períodos em falta na sequência">
          <div className="fluxo-buracos-topo">
            <IconeFa nome="exclamation-triangle" />
            <div>
              <strong>
                {buracos.meses.length} período(s) em falta entre {buracos.primeiro} e {buracos.ultimo}
              </strong>
              <span>Estes meses não têm processamento salarial. Clique num mês para o abrir em Calcular.</span>
            </div>
          </div>
          <div className="fluxo-buracos-lista">
            {buracos.meses.map((m) => (
              <button key={m} type="button" className="fluxo-buraco" title={`Abrir ${m} em Calcular`} disabled={!rotaCalcular} onClick={() => rotaCalcular && navegar(rotaCalcular)}>
                <IconeFa nome="calendar-times-regular" /> {m}
              </button>
            ))}
          </div>
        </section>
      )}

      <div>
        <p className="fluxo-titulo-seccao">Por etapa actual</p>
        <FunilFluxo
          activa={etapa}
          aoEscolher={(id) => {
            setEtapa(id ?? undefined);
            if (id === 'concluidos' && estado && estado !== 'concluido') setEstado(undefined);
          }}
          colunas={r.funil.map((f) => ({ id: f.etapa, rotulo: f.nome, numero: f.parados, valor: kz(f.valor), comPendencias: f.com_pendencias }))}
        />
      </div>

      <div>
        <Space wrap size={8} style={{ width: '100%', justifyContent: 'space-between', marginBottom: 8 }}>
          <div className="ft-chips" role="group" aria-label="Filtros">
            {FILTROS.map((f) => (
              <button key={f.rotulo} type="button" className="ft-chip" aria-pressed={estado === f.id} onClick={() => setEstado(f.id)}>
                {f.rotulo}
                <b>{contagem[f.id ?? 'todos']}</b>
              </button>
            ))}
            {etapa && (
              <span className="ft-etapa-activa">
                Etapa: {nomeEtapa(etapa)}
                <button type="button" aria-label="Limpar a etapa" onClick={() => setEtapa(undefined)}>
                  ×
                </button>
              </span>
            )}
          </div>
          <Input.Search placeholder="Pesquisar n.º, entidade…" aria-label="Pesquisar processos" allowClear style={{ width: 260, maxWidth: '100%' }} onSearch={setPesquisa} />
        </Space>
        <TabelaApi<ProcessoLista>
          url={`/gestao/fluxos/${fluxo.id}/processos`}
          chaveConsulta={['gestao', 'fluxos', fluxo.id, 'processos']}
          filtros={{ etapa, estado, pesquisa }}
          impressao={{
            titulo: `Processos · ${fluxo.nome}`,
            filtros: [etapa && `Etapa: ${nomeEtapa(etapa)}`, estado && `Estado: ${FILTROS.find((f) => f.id === estado)?.rotulo ?? estado}`, pesquisa && `Pesquisa: ${pesquisa}`],
          }}
          rowKey="chave"
          size="small"
          onRow={(p) => ({ onClick: () => setAberto(p.chave), style: { cursor: 'pointer' } })}
          columns={[
            {
              title: 'Processo',
              dataIndex: 'titulo',
              render: (v: string, p) => (
                <>
                  <span className="ft-titulo">{v}</span>
                  {p.subtitulo && <span className="ft-resumo">{p.subtitulo}</span>}
                </>
              ),
            },
            { title: 'Data', dataIndex: 'data', render: formatarData, width: 110, responsive: ['md'] },
            { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string | null) => formatarKz(v) },
            { title: 'Pendente', dataIndex: 'valor_pendente', align: 'right', responsive: ['md'], render: (v: string | null) => (v && Number(v) ? <Typography.Text type="warning">{formatarKz(v)}</Typography.Text> : '—') },
            {
              title: 'Etapa actual',
              dataIndex: 'etapa_actual_nome',
              valorImpressao: (p) => (p.etapa_actual_nome ? `${p.etapa_actual_nome} (${ESTADOS_FLUXO[estadoDe(p.etapa_actual_estado)].rotulo})` : 'Concluído'),
              render: (v: string | null, p) =>
                v ? (
                  <>
                    <PillEstado estado={p.etapa_actual_estado} texto={v} />
                    {p.etapa_actual_resumo && <span className="ft-resumo">{p.etapa_actual_resumo}</span>}
                  </>
                ) : (
                  <PillEstado estado="concluida" texto="Concluído" />
                ),
            },
            {
              title: 'Progresso',
              key: 'p',
              width: 110,
              responsive: ['lg'],
              valorImpressao: (p) => `${p.concluidas}/${p.total_etapas}${p.bloqueado ? ' (bloqueado)' : ''}`,
              render: (_, p) => <MiniProgresso estados={fluxo.etapas.map((e) => p.progresso?.[e.id])} rotulo={`${p.concluidas} de ${p.total_etapas} etapas`} />,
            },
            {
              title: 'Pendências',
              key: 'a',
              valorImpressao: (p) => [p.n_erros ? `${p.n_erros} erro(s)` : '', p.n_avisos ? `${p.n_avisos} aviso(s)` : ''].filter(Boolean).join(' · '),
              render: (_, p) => <MarcasPendencias erros={p.n_erros} avisos={p.n_avisos} />,
            },
          ]}
        />
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          Clique numa linha para ver o fluxo do processo.
        </Typography.Text>
      </div>

      {comEstados.length > 0 && (
        <GraficoBarras
          titulo="Estado de cada etapa nos processos"
          horizontal
          empilhado
          rotulos={comEstados.map((f) => f.nome)}
          series={ORDEM_ESTADOS.map((e) => ({ rotulo: ESTADOS_FLUXO[e].rotulo, cor: ESTADOS_FLUXO[e].traco, valores: comEstados.map((f) => f.estados?.[e] ?? 0) }))}
        />
      )}

      <LegendaFluxo />
      <DetalheDoProcesso
        fluxo={fluxo}
        narrativaFluxo={r.fluxo.narrativa ?? null}
        kpis={r.kpis}
        chave={aberto}
        aoFechar={() => setAberto(null)}
        narrativa={narrativa}
        contentor={contentor}
        registarNavegacao={registarNavegacao}
      />
    </Space>
  );
}

function DetalheDoProcesso({
  fluxo,
  narrativaFluxo,
  kpis,
  chave,
  aoFechar,
  narrativa,
  contentor,
  registarNavegacao,
}: {
  fluxo: FluxoResumoLista;
  narrativaFluxo: Narrativa | null;
  kpis: Kpi[];
  chave: string | null;
  aoFechar: () => void;
  narrativa: boolean;
  contentor?: () => HTMLElement;
  registarNavegacao: (fn: ((delta: number) => void) | null) => void;
}) {
  const navegar = useNavigate();
  const rotaDaVista = useRotaDaVista();
  const q = useQuery({
    queryKey: ['gestao', 'fluxos', fluxo.id, 'processo', chave],
    queryFn: () => obter<DetalheProcesso>(`/gestao/fluxos/${fluxo.id}/processos/${encodeURIComponent(chave!)}`),
    enabled: !!chave,
  });
  const d = q.data;
  const [etapaSel, setEtapaSel] = useState<string | null>(null);
  useEffect(() => {
    setEtapaSel(d ? etapaInicial(fluxo.etapas, d) : null);
  }, [d, fluxo.etapas]);

  const indice = etapaSel ? fluxo.etapas.findIndex((e) => e.id === etapaSel) : -1;
  useEffect(() => {
    if (!chave || !d) {
      registarNavegacao(null);
      return;
    }
    registarNavegacao((delta) => {
      setEtapaSel((actual) => {
        const i = fluxo.etapas.findIndex((e) => e.id === actual);
        const alvo = Math.min(fluxo.etapas.length - 1, Math.max(0, i + delta));
        return fluxo.etapas[alvo]?.id ?? actual;
      });
    });
    return () => registarNavegacao(null);
  }, [chave, d, fluxo.etapas, registarNavegacao]);

  const estados = useMemo(() => (d ? Object.fromEntries(Object.entries(d.etapas).map(([k, v]) => [k, { estado: v.estado, resumo: v.resumo }])) : {}), [d]);
  const et = d && etapaSel ? d.etapas[etapaSel] : undefined;
  const etapaDef = indice >= 0 ? fluxo.etapas[indice] : undefined;
  const narrEtapa = et?.narrativa ?? (indice >= 0 ? narrativaFluxo?.etapas[indice] : undefined) ?? null;

  return (
    <Drawer
      open={!!chave}
      onClose={aoFechar}
      width={larguraGaveta(860)}
      getContainer={contentor ?? undefined}
      title={d ? `${d.titulo} — ${fluxo.nome}` : 'Processo'}
      destroyOnHidden
    >
      {q.isLoading || !d ? (
        <Skeleton active />
      ) : (
        <RaizFluxo>
          <Space direction="vertical" size={16} style={{ width: '100%' }}>
            <Flex justify="flex-end">
                <BotoesExportar
                  tamanho="small"
                  excel={false}
                  seletorPagina={false}
                  textoImprimir="Imprimir workflow"
                  obterPedido={() => ({
                    titulo: `Workflow — ${narrativaFluxo?.titulo ?? fluxo.nome}`,
                    subtitulo: `${d.titulo}${d.subtitulo ? ` · ${d.subtitulo}` : ''}`,
                    conteudo: workflowHtml({
                      narrativa: narrativaFluxo,
                      etapas: fluxo.etapas,
                      estados,
                      processo: d.titulo,
                      etapaSeleccionada: etapaSel,
                      kpis: kpisTexto(kpis),
                      factos: et ? factosTexto(et.factos) : [],
                      pendencias: et?.problemas ?? [],
                    }),
                    cssExtra: CSS_WORKFLOW,
                    orientacao: 'paisagem',
                  })}
                />
            </Flex>
            <Descriptions size="small" column={{ xs: 1, sm: 2 }} bordered>
              <Descriptions.Item label="Descrição" span={2}>
                {d.subtitulo ?? '—'}
              </Descriptions.Item>
              <Descriptions.Item label="Data">{formatarData(d.data)}</Descriptions.Item>
              <Descriptions.Item label="Valor">{d.valor !== null ? `${formatarKz(d.valor)} Kz` : '—'}</Descriptions.Item>
              <Descriptions.Item label="Progresso">
                {d.concluidas}/{d.total_etapas} etapas
              </Descriptions.Item>
              <Descriptions.Item label="Pendente">{d.valor_pendente && Number(d.valor_pendente) !== 0 ? `${formatarKz(d.valor_pendente)} Kz` : '—'}</Descriptions.Item>
              {d.documentos.length > 0 && (
                <Descriptions.Item label="Documentos" span={2}>
                  <Space wrap>
                    {d.documentos.map((x) => (
                      <Tag key={`${x.tipo}-${x.id}-${x.numero ?? ''}`}>{x.numero ?? `${x.tipo} ${x.id}`}</Tag>
                    ))}
                  </Space>
                </Descriptions.Item>
              )}
            </Descriptions>
            <DiagramaFluxo
              titulo={`${fluxo.nome} · ${d.titulo}`}
              estadoGlobal={estadoProcesso(d)}
              etapas={fluxo.etapas}
              estados={estados}
              seleccionada={etapaSel}
              aoSeleccionar={setEtapaSel}
              concluidas={d.concluidas}
            />
            {narrativa && narrEtapa && indice >= 0 && <NarrativaEtapa indice={indice} narrativa={narrEtapa} />}
            {et && etapaDef && (
              <DetalheEtapa
                etapa={etapaDef}
                estado={et.estado}
                factos={factosTexto(et.factos)}
                problemas={et.problemas}
                accoes={et.accoes.map((a) => {
                  const rota = rotaDaVista(a.acao);
                  return { rotulo: a.rotulo, primaria: a.primaria, desactivada: !rota, titulo: rota ? undefined : 'Sem acesso a este ecrã', aoClicar: () => rota && navegar(rota) };
                })}
              />
            )}
            <LegendaFluxo />
          </Space>
        </RaizFluxo>
      )}
    </Drawer>
  );
}
