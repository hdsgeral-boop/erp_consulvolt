import { Alert, Skeleton, Space } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router';
import { obter } from '@/api/cliente';
import { IconeFa, KpisFluxo, LegendaFluxo, type EtapaDiagrama, type NarrativaDaEtapa } from '@/componentes/fluxos';
import { formatarPorFormato, useRotaDaVista } from '../comum/componentes';
import { PainelProcesso, type EstadoPainel } from './componentes';
import { cartaoInicial, cartoesDoFluxo, temCartoes, tituloCartoes, type LinhaProcesso } from './logica';
import { CartoesProcessos } from './componentes';
import { VistaVolume, type VistaVolumeConfig } from './VistaVolume';

export interface Etapa extends EtapaDiagrama {
  icone: string | null;
}

export interface FluxoResumoLista {
  id: string;
  nome: string;
  sub: string;
  icone?: string | null;
  tem_actividade: boolean;
  etapas: Etapa[];
}

export interface Narrativa {
  titulo: string;
  objectivo: string;
  intervenientes: string[];
  etapas: NarrativaDaEtapa[];
}

export interface Kpi {
  chave: string;
  rotulo: string;
  valor: unknown;
  formato: string;
  alerta: boolean;
}

export interface ResumoFluxo {
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

export const kpisTexto = (k: Kpi[]) => k.map((x) => ({ chave: x.chave, rotulo: x.rotulo, texto: `${formatarPorFormato(x.valor, x.formato)}${x.formato === 'kz' && x.valor !== null && x.valor !== undefined ? ' Kz' : ''}`, alerta: x.alerta }));

/** Conteúdo de um fluxo (indicadores, períodos em falta, cartões, diagrama e vista de volume). Partilhado pelo ecrã Fluxo de Processos e pelo Dashboard. */
export function Fluxo({
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
  if (resumo.error || !resumo.data) return <Alert type="error" showIcon title={(resumo.error as Error | null)?.message ?? 'Não foi possível calcular o estado dos processos.'} />;
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
    <Space orientation="vertical" size={16} style={{ width: '100%' }}>
      {!r.tem_actividade && <Alert type="info" showIcon title="Ainda não há processos neste fluxo na empresa activa." />}
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
