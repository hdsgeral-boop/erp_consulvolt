import { Skeleton, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type KeyboardEvent, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import {
  DetalheEtapa,
  DiagramaFluxo,
  IconeFa,
  MiniProgresso,
  NarrativaEtapa,
  PillEstado,
  estadoProcesso,
  type EtapaDiagrama,
  type NarrativaDaEtapa,
} from '@/componentes/fluxos';
import { formatarKz } from '@/utilitarios/formatacao';
import { formatarPorFormato, useRotaDaVista } from '../comum/componentes';
import { dataCurta, etapaInicial, tituloDiagrama, type LinhaProcesso } from './logica';
import './fluxo.css';

/**
 * Peças do ecrã Fluxo de Processos com a disposição do legado (js/fluxo_processos.js:383-443, 639-659): cabeçalho com os
 * botões por baixo do subtítulo, separadores sublinhados, cartões dos períodos/processos e o painel do processo
 * seleccionado (diagrama + barra de progresso + detalhe da etapa) na própria página.
 */

export interface FluxoSeparador {
  id: string;
  nome: string;
  icone?: string | null;
}

/** Título e subtítulo; os botões ficam por baixo, à esquerda, pequenos e com contorno (page-head/page-actions do legado). */
export function CabecalhoFluxo({ subtitulo, accoes }: { subtitulo?: ReactNode; accoes?: ReactNode }) {
  return (
    <div className="fluxo-cabecalho cabecalho-pagina">
      <div className="fluxo-cabecalho-textos">
        <Typography.Title level={3} className="erp-titulo-pagina" style={{ margin: 0, overflowWrap: 'anywhere' }}>
          Fluxo de Processos
        </Typography.Title>
        {subtitulo && <p className="fluxo-sub">{subtitulo}</p>}
      </div>
      {accoes && <div className="fluxo-cabecalho-accoes no-print">{accoes}</div>}
    </div>
  );
}

/** Separadores dos fluxos: sublinhado azul no activo, ícone à esquerda, quebram em várias linhas (fluxo-tabs). */
export function SeparadoresFluxo({ fluxos, activo, aoEscolher }: { fluxos: FluxoSeparador[]; activo: string; aoEscolher: (id: string) => void }) {
  const teclas = (ev: KeyboardEvent<HTMLDivElement>) => {
    const i = fluxos.findIndex((f) => f.id === activo);
    const alvo = ev.key === 'ArrowRight' ? i + 1 : ev.key === 'ArrowLeft' ? i - 1 : ev.key === 'Home' ? 0 : ev.key === 'End' ? fluxos.length - 1 : null;
    if (alvo === null || alvo < 0 || alvo >= fluxos.length) return;
    ev.preventDefault();
    aoEscolher(fluxos[alvo].id);
    (ev.currentTarget.querySelectorAll('button')[alvo] as HTMLButtonElement | undefined)?.focus();
  };
  return (
    <div className="fluxo-tabs" role="tablist" aria-label="Processos" onKeyDown={teclas}>
      {fluxos.map((f) => (
        <button key={f.id} type="button" role="tab" className="fluxo-tab" aria-selected={f.id === activo} tabIndex={f.id === activo ? 0 : -1} onClick={() => aoEscolher(f.id)}>
          <IconeFa nome={f.icone} /> {f.nome}
        </button>
      ))}
    </div>
  );
}

const kz = (v: string | null | undefined) => (v && Number(v) !== 0 ? `${formatarKz(v)} Kz` : null);

/**
 * Cartões do topo: RH — rótulo do período grande e a pastilha do estado na mesma linha; restantes — título, pastilha e
 * «data · valor». Por baixo, um segmento por etapa e «n de N etapas concluídas».
 */
export function CartoesProcessos({
  fluxo,
  etapas,
  lista,
  seleccionado,
  aoSeleccionar,
}: {
  fluxo: string;
  etapas: EtapaDiagrama[];
  lista: LinhaProcesso[];
  seleccionado: string | null;
  aoSeleccionar: (chave: string) => void;
}) {
  return (
    <div className="fluxo-periodos">
      {lista.map((p) => {
        const est = estadoProcesso(p);
        const segmentos = <MiniProgresso estados={etapas.map((e) => p.progresso?.[e.id])} />;
        return (
          <button key={p.chave} type="button" className="fluxo-periodo" aria-pressed={p.chave === seleccionado} onClick={() => aoSeleccionar(p.chave)}>
            {fluxo === 'rh' ? (
              <>
                <span className="fluxo-periodo-topo">
                  <span className="fluxo-periodo-mes">{p.titulo}</span>
                  <PillEstado estado={est.estado} texto={est.rotulo} />
                </span>
                {segmentos}
                <span className="fluxo-periodo-info">
                  {p.concluidas} de {p.total_etapas} etapas concluídas
                </span>
              </>
            ) : (
              <>
                <span className="fluxo-periodo-mes pequeno">{p.titulo}</span>
                <span className="fluxo-periodo-topo quebra">
                  <PillEstado estado={est.estado} texto={est.rotulo} />
                  <span className="fluxo-periodo-sub">{p.linha_data ?? [dataCurta(p.data), kz(p.valor)].filter(Boolean).join(' · ')}</span>
                </span>
                {segmentos}
                <span className="fluxo-periodo-info">
                  {p.concluidas} de {p.total_etapas} etapas{p.subtitulo ? ` · ${p.subtitulo}` : ''}
                </span>
              </>
            )}
          </button>
        );
      })}
    </div>
  );
}

export interface EtapaProcesso {
  estado: string;
  resumo: string | null;
  factos: { rotulo: string; valor: unknown; formato: string }[];
  problemas: { nivel: 'erro' | 'aviso' | string; texto: string; ambito?: string }[];
  accoes: { rotulo: string; acao: string; primaria: boolean }[];
  narrativa: NarrativaDaEtapa | null;
}

export interface DetalheProcesso {
  chave: string;
  titulo: string;
  subtitulo: string | null;
  data: string | null;
  valor: string | null;
  valor_pendente: string | null;
  total_etapas: number;
  concluidas: number;
  bloqueado: boolean;
  etapas: Record<string, EtapaProcesso>;
  documentos: { tipo: string; id: number; tipo_documento?: string; numero?: string }[];
}

export const factosTexto = (f: EtapaProcesso['factos']) => f.map((x) => ({ rotulo: x.rotulo, texto: formatarPorFormato(x.valor, x.formato) }));

/** O que o painel expõe à página (impressão do workflow e setas ← → no ecrã inteiro). */
export interface EstadoPainel {
  detalhe: DetalheProcesso;
  etapa: string | null;
  mudarEtapa: (delta: number) => void;
}

/**
 * Painel do processo seleccionado, na página (como no legado): diagrama com o título «Processamento salarial · 09/2026» e a
 * pastilha do estado, barra «4/6 … 67 %», narrativa (opcional) e detalhe da etapa com os botões que abrem o ecrã certo.
 */
export function PainelProcesso({
  fluxo,
  nomeFluxo,
  etapas,
  chave,
  narrativa,
  narrativaFluxo,
  aoMudar,
}: {
  fluxo: string;
  nomeFluxo: string;
  etapas: EtapaDiagrama[];
  chave: string;
  narrativa: boolean;
  narrativaFluxo?: { etapas: NarrativaDaEtapa[] } | null;
  aoMudar?: (estado: EstadoPainel | null) => void;
}) {
  const navegar = useNavigate();
  const rotaDaVista = useRotaDaVista();
  const q = useQuery({
    queryKey: ['gestao', 'fluxos', fluxo, 'processo', chave],
    queryFn: () => obter<DetalheProcesso>(`/gestao/fluxos/${fluxo}/processos/${encodeURIComponent(chave)}`),
  });
  const d = q.data;
  const [etapaSel, setEtapaSel] = useState<string | null>(null);
  useEffect(() => {
    setEtapaSel(d ? etapaInicial(etapas, d.etapas) : null);
  }, [d, etapas]);

  useEffect(() => {
    if (!aoMudar) return;
    if (!d) {
      aoMudar(null);
      return;
    }
    aoMudar({
      detalhe: d,
      etapa: etapaSel,
      mudarEtapa: (delta) =>
        setEtapaSel((actual) => {
          const i = etapas.findIndex((e) => e.id === actual);
          return etapas[Math.min(etapas.length - 1, Math.max(0, i + delta))]?.id ?? actual;
        }),
    });
  }, [d, etapaSel, etapas, aoMudar]);
  useEffect(() => () => aoMudar?.(null), [aoMudar]);

  const estados = useMemo(() => (d ? Object.fromEntries(Object.entries(d.etapas).map(([k, v]) => [k, { estado: v.estado, resumo: v.resumo }])) : {}), [d]);
  if (q.isLoading) return <Skeleton active />;
  if (q.error || !d) return <div className="fluxo-vazio">Não foi possível carregar o processo.</div>;
  const indice = etapaSel ? etapas.findIndex((e) => e.id === etapaSel) : -1;
  const et = etapaSel ? d.etapas[etapaSel] : undefined;
  const etapaDef = indice >= 0 ? etapas[indice] : undefined;
  const narrEtapa = et?.narrativa ?? (indice >= 0 ? narrativaFluxo?.etapas[indice] : undefined) ?? null;

  return (
    <>
      <DiagramaFluxo
        titulo={tituloDiagrama(fluxo, nomeFluxo, d)}
        estadoGlobal={estadoProcesso(d)}
        etapas={etapas}
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
    </>
  );
}
