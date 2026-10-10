import { Alert, Button, Empty, Skeleton, Space } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { BotaoImprimir } from '@/componentes/impressao';
import {
  IconeFa,
  KpisFluxo,
  LegendaFluxo,
  RaizFluxo,
  CSS_WORKFLOW,
  workflowHtml,
  type EtapaDiagrama,
  type NarrativaDaEtapa,
} from '@/componentes/fluxos';
import { formatarPorFormato, useRotaDaVista } from './comum/componentes';
import { CabecalhoFluxo, CartoesProcessos, PainelProcesso, SeparadoresFluxo, factosTexto, type EstadoPainel } from './fluxo/componentes';
import { cartaoInicial, cartoesDoFluxo, temCartoes, tituloCartoes, type LinhaProcesso } from './fluxo/logica';
import { VistaVolume, type VistaVolumeConfig } from './fluxo/VistaVolume';

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
  extra?: {
    periodos_em_falta?: { meses: string[]; primeiro: string | null; ultimo: string | null };
    meses_por_calcular?: { periodo: string; ativos: number; valor: string }[];
  } | null;
  /** Vista de volume (funil, filtros e colunas do legado) — só nos fluxos sem cartões. */
  vista?: VistaVolumeConfig | null;
}

const CHAVE_NARRATIVA = 'fluxo.narrativa';
const lerNarrativa = () => {
  try {
    return localStorage.getItem(CHAVE_NARRATIVA) === '1';
  } catch {
    return false;
  }
};

const kpisTexto = (k: Kpi[]) => k.map((x) => ({ chave: x.chave, rotulo: x.rotulo, texto: `${formatarPorFormato(x.valor, x.formato)}${x.formato === 'kz' && x.valor !== null && x.valor !== undefined ? ' Kz' : ''}`, alerta: x.alerta }));

/**
 * Geral › Fluxo de Processos (js/fluxo_processos.js, fluxo_tabela.js, fluxo_narrativa.js), com a disposição do legado:
 * cabeçalho com os botões pequenos por baixo do subtítulo, separadores sublinhados (quebram linha), indicadores, aviso dos
 * períodos em falta e, nos fluxos em cartões, os cartões dos últimos períodos/processos → diagrama do seleccionado → barra
 * de progresso → detalhe da etapa, tudo na página. Por baixo, o funil por etapa actual e a tabela de todos os processos
 * (clicar numa linha mostra o processo no diagrama). Dados: GET /gestao/fluxos/*.
 */
export default function FluxoProcessos() {
  const cliente = useQueryClient();
  const lista = useQuery({ queryKey: ['gestao', 'fluxos'], queryFn: () => obter<FluxoResumoLista[]>('/gestao/fluxos'), staleTime: 300_000 });
  const [activo, setActivo] = useState<string | undefined>();
  const [narrativa, setNarrativaEstado] = useState(lerNarrativa);
  const [apresentar, setApresentar] = useState<'nativo' | 'janela' | null>(null);
  const refApres = useRef<HTMLDivElement>(null);
  const painel = useRef<EstadoPainel | null>(null);
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
        if (painel.current) {
          ev.preventDefault();
          painel.current.mudarEtapa(ev.key === 'ArrowRight' ? 1 : -1);
        }
      } else if (ev.key === 'n' || ev.key === 'N') setNarrativa(!lerNarrativa());
    };
    document.addEventListener('keydown', teclas);
    return () => {
      document.removeEventListener('keydown', teclas);
      document.body.style.overflow = '';
    };
  }, [apresentar, setNarrativa]);

  const aoMudarPainel = useCallback((e: EstadoPainel | null) => {
    painel.current = e;
  }, []);
  const aoCarregar = useCallback((r: ResumoFluxo) => {
    resumoActivo.current = r;
  }, []);

  if (lista.isLoading) return <Skeleton active />;
  const fluxos = (lista.data ?? []).filter((f) => f.tem_actividade);
  const todos = lista.data ?? [];
  const actual = fluxos.find((f) => f.id === activo) ?? fluxos[0];

  // Imprimir workflow (FluxoNarrativa.imprimir): o processo seleccionado, se houver, senão o workflow do fluxo
  const imprimirWorkflow = () => {
    if (!actual) return null;
    const r = resumoActivo.current?.fluxo.id === actual.id ? resumoActivo.current : null;
    const p = painel.current;
    const et = p?.etapa ? p.detalhe.etapas[p.etapa] : undefined;
    const narr = r?.fluxo.narrativa ?? null;
    return {
      titulo: `Workflow — ${narr?.titulo ?? actual.nome}`,
      subtitulo: p ? `${p.detalhe.titulo}${p.detalhe.subtitulo ? ` · ${p.detalhe.subtitulo}` : ''}` : 'Workflow com narrativa',
      conteudo: workflowHtml({
        narrativa: narr,
        etapas: actual.etapas,
        kpis: r ? kpisTexto(r.kpis) : [],
        ...(p
          ? {
              estados: Object.fromEntries(Object.entries(p.detalhe.etapas).map(([k, v]) => [k, { estado: v.estado, resumo: v.resumo }])),
              processo: p.detalhe.titulo,
              etapaSeleccionada: p.etapa,
              factos: et ? factosTexto(et.factos) : [],
              pendencias: et?.problemas ?? [],
            }
          : {}),
      }),
      cssExtra: CSS_WORKFLOW,
      orientacao: 'paisagem' as const,
    };
  };

  return (
    <>
      <CabecalhoFluxo
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
              <BotaoImprimir texto="Imprimir workflow" obterPedido={imprimirWorkflow} />
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
            <SeparadoresFluxo fluxos={fluxos} activo={actual.id} aoEscolher={setActivo} />
            <div role="tabpanel" aria-label={actual.nome}>
              <Fluxo key={actual.id} fluxo={actual} narrativa={narrativa} aoMudarPainel={aoMudarPainel} aoCarregar={aoCarregar} />
            </div>
          </div>
        </RaizFluxo>
      )}
    </>
  );
}

function Fluxo({
  fluxo,
  narrativa,
  aoMudarPainel,
  aoCarregar,
}: {
  fluxo: FluxoResumoLista;
  narrativa: boolean;
  aoMudarPainel: (e: EstadoPainel | null) => void;
  aoCarregar: (r: ResumoFluxo) => void;
}) {
  const navegar = useNavigate();
  const rotaDaVista = useRotaDaVista();
  const comCartoes = temCartoes(fluxo.id);
  const resumo = useQuery({ queryKey: ['gestao', 'fluxos', fluxo.id], queryFn: () => obter<ResumoFluxo>(`/gestao/fluxos/${fluxo.id}`) });
  // Cartões do topo: os processos mais recentes (montarLista do legado)
  const recentes = useQuery({
    queryKey: ['gestao', 'fluxos', fluxo.id, 'recentes'],
    queryFn: () => obter<LinhaProcesso[]>(`/gestao/fluxos/${fluxo.id}/processos`, { ordem: 'recentes', por_pagina: 200 }),
    enabled: comCartoes,
  });
  const [seleccionado, setSeleccionado] = useState<string | null>(null);
  const refPainel = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (resumo.data) aoCarregar(resumo.data);
  }, [resumo.data, aoCarregar]);

  const cartoes = comCartoes ? cartoesDoFluxo(fluxo.id, recentes.data ?? []) : [];
  const inicial = comCartoes ? cartaoInicial(cartoes) : null;
  const chave = seleccionado ?? inicial;

  if (resumo.isLoading) return <Skeleton active />;
  if (resumo.error || !resumo.data) return <Alert type="error" showIcon message={(resumo.error as Error | null)?.message ?? 'Não foi possível calcular o estado dos processos.'} />;
  const r = resumo.data;
  const buracos = r.extra?.periodos_em_falta;
  const rotaCalcular = rotaDaVista('calcular');
  // Imobilizado (fluxo_imobilizado.js:260): meses fechados sem amortização calculada; «06-2026» → «06/2026»
  const mesesAmort = fluxo.id === 'imobilizado' ? (r.extra?.meses_por_calcular ?? []) : [];
  const rotaAmortizacoes = rotaDaVista('activos_amortizacoes');
  const mesTxt = (p: string) => p.replace('-', '/');

  const painelProcesso = chave && (
    <div ref={refPainel} style={{ display: 'grid', gap: 16, scrollMarginTop: 16 }}>
      <PainelProcesso
        key={chave}
        fluxo={fluxo.id}
        nomeFluxo={fluxo.nome}
        etapas={fluxo.etapas}
        chave={chave}
        narrativa={narrativa}
        narrativaFluxo={r.fluxo.narrativa}
        aoMudar={aoMudarPainel}
      />
      <LegendaFluxo />
    </div>
  );

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

      {mesesAmort.length > 0 && (
        <section className="fluxo-buracos" aria-label="Meses de amortização por calcular">
          <div className="fluxo-buracos-topo">
            <IconeFa nome="exclamation-triangle" />
            <div>
              <strong>
                {mesesAmort.length} mês(es) de amortização por calcular entre {mesTxt(mesesAmort[0].periodo)} e {mesTxt(mesesAmort[mesesAmort.length - 1].periodo)}
              </strong>
              <span>Meses fechados sem amortização calculada para pelo menos um activo em uso. Clique num mês para o abrir em Amortizações.</span>
            </div>
          </div>
          <div className="fluxo-buracos-lista">
            {mesesAmort.map((m) => (
              <button
                key={m.periodo}
                type="button"
                className="fluxo-buraco"
                title={`Abrir ${mesTxt(m.periodo)} em Amortizações`}
                disabled={!rotaAmortizacoes}
                onClick={() => rotaAmortizacoes && navegar(rotaAmortizacoes)}
              >
                <IconeFa nome="calendar-times-regular" /> {mesTxt(m.periodo)} · {m.ativos} activo(s)
              </button>
            ))}
          </div>
        </section>
      )}

      {comCartoes &&
        (recentes.isLoading ? (
          <Skeleton active />
        ) : cartoes.length === 0 ? (
          <div className="fluxo-vazio">
            <IconeFa nome={fluxo.icone} style={{ fontSize: '1.6rem', color: '#94a3b8' }} />
            <strong>Ainda não há processos neste fluxo</strong>
          </div>
        ) : (
          <>
            <div>
              <p className="fluxo-titulo-seccao">{tituloCartoes(fluxo.id, cartoes.length)}</p>
              <CartoesProcessos fluxo={fluxo.id} etapas={fluxo.etapas} lista={cartoes} seleccionado={chave} aoSeleccionar={setSeleccionado} />
            </div>
            {painelProcesso}
          </>
        ))}

      {/* Legado: o funil «Por etapa actual» e a tabela de processos só existem nos fluxos sem cartões (vista de volume,
          fluxo_tabela.js: Lavandaria, Projectos, Bancos, Folha de Caixa), com o diagrama a abrir por baixo da linha escolhida;
          nos fluxos com cartões a página termina no detalhe e na legenda. */}
      {!comCartoes && r.vista && (
        <VistaVolume
          fluxo={fluxo.id}
          nomeFluxo={fluxo.nome}
          etapas={fluxo.etapas}
          vista={r.vista}
          total={r.total}
          narrativa={narrativa}
          narrativaFluxo={r.fluxo.narrativa}
          aoMudarPainel={aoMudarPainel}
        />
      )}

      {comCartoes && !chave && <LegendaFluxo />}
    </Space>
  );
}
