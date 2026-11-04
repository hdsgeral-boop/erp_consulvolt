import { Button, Empty, Skeleton } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';
import { obter } from '@/api/cliente';
import { BotaoImprimir } from '@/componentes/impressao';
import { IconeFa, RaizFluxo, CSS_WORKFLOW, workflowHtml } from '@/componentes/fluxos';
import { CabecalhoFluxo, SeparadoresFluxo, factosTexto, type EstadoPainel } from './fluxo/componentes';
import { Fluxo, kpisTexto, type FluxoResumoLista, type ResumoFluxo } from './fluxo/FluxoDetalhe';

const CHAVE_NARRATIVA = 'fluxo.narrativa';
const lerNarrativa = () => {
  try {
    return localStorage.getItem(CHAVE_NARRATIVA) === '1';
  } catch {
    return false;
  }
};


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
            styles={{ image: { height: 36 } }}
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

