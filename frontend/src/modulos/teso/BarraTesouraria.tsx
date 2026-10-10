import { useQuery } from '@tanstack/react-query';
import { useLocation } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoModulo, type IndicadorModulo, type SeparadorModulo } from '@/componentes/CabecalhoModulo';
import type { ResumoTesouraria } from './api';

const BASE = '/m/teso';

export type GrupoTesouraria = 'operacoes' | 'contabilidade' | 'gestao';

interface DefinicaoSeparador {
  chave: string;
  rotulo: string;
  icone: string;
  destino: string;
  permissao: string;
}

interface DefinicaoGrupo {
  titulo: string;
  icone: string;
  subtitulo: string;
  separadores: DefinicaoSeparador[];
}

/**
 * Os três agregadores da Tesouraria do sistema anterior (renderTesourariaOperacoes / renderTesourariaContabil /
 * renderTesourariaGestao, js/ui_tesouraria.js:98-273): título, ícone, subtítulo e separadores pela ordem, rótulos e
 * ícones do legado. «Pagamentos» e «Recebimentos» são a mesma lista filtrada por ?tipo= (permissão do ecrã
 * teso_gestao_pagamentos, que no catálogo cobre as duas vistas). A «Emissão de Mapas de Tesouraria» do relatório é o
 * título do formulário dentro de «Emissão de Extratos» (renderMapasTesouraria), não um quarto separador.
 */
export const GRUPOS_TESOURARIA: Record<GrupoTesouraria, DefinicaoGrupo> = {
  operacoes: {
    titulo: 'Tesouraria - Operações',
    icone: 'money-bill-wave',
    subtitulo: 'Movimentos Diários de caixa, pagamentos e recebimentos.',
    separadores: [
      { chave: 'pagamentos', rotulo: 'Pagamentos', icone: 'money-bill-wave', destino: `${BASE}/teso_gestao_pagamentos?tipo=PAGAMENTO`, permissao: 'teso_gestao_pagamentos_view' },
      { chave: 'recebimentos', rotulo: 'Recebimentos', icone: 'hand-holding-usd', destino: `${BASE}/teso_gestao_pagamentos?tipo=RECEBIMENTO`, permissao: 'teso_gestao_pagamentos_view' },
      { chave: 'todos', rotulo: 'Todos os Registos', icone: 'list-alt', destino: `${BASE}/teso_gestao_pagamentos`, permissao: 'teso_gestao_pagamentos_view' },
      { chave: 'folha_caixa', rotulo: 'Folha de Caixa', icone: 'cash-register', destino: `${BASE}/teso_folha_caixa`, permissao: 'teso_folha_caixa_view' },
    ],
  },
  contabilidade: {
    titulo: 'Tesouraria - Contabilidade',
    icone: 'file-invoice-dollar',
    subtitulo: 'Operações contabilísticas e integração de tesouraria no Diário geral.',
    separadores: [
      { chave: 'integracao', rotulo: 'Integração no Diário', icone: 'check-double', destino: `${BASE}/teso_contab_integracao`, permissao: 'teso_contab_integracao_view' },
      { chave: 'descontab', rotulo: 'Histórico / Anulação', icone: 'history', destino: `${BASE}/teso_contab_historico`, permissao: 'teso_contab_historico_view' },
    ],
  },
  gestao: {
    titulo: 'Tesouraria - Gestão',
    icone: 'chart-pie',
    subtitulo: 'Visualização de extratos e reconciliação de contas.',
    separadores: [
      { chave: 'mapas', rotulo: 'Emissão de Extratos', icone: 'file-invoice', destino: `${BASE}/teso_gestao_mapas`, permissao: 'teso_gestao_mapas_view' },
      { chave: 'conciliacao', rotulo: 'Conciliação Bancária', icone: 'balance-scale', destino: `${BASE}/teso_gestao_conciliacao`, permissao: 'teso_gestao_conciliacao_view' },
      { chave: 'conferencia', rotulo: 'Conferência de Caixa', icone: 'cash-register', destino: `${BASE}/teso_gestao_conferencia`, permissao: 'teso_gestao_conferencia_view' },
    ],
  },
};

/** Agregador de cada ecrã da Tesouraria (Meios de pagamento fica fora: no legado é uma página própria). */
export const GRUPO_DO_ECRA: Record<string, GrupoTesouraria> = {
  teso_gestao_pagamentos: 'operacoes',
  teso_folha_caixa: 'operacoes',
  teso_contab_integracao: 'contabilidade',
  teso_contab_historico: 'contabilidade',
  teso_gestao_mapas: 'gestao',
  teso_gestao_conciliacao: 'gestao',
  teso_gestao_conferencia: 'gestao',
};

/**
 * Separador activo a partir do caminho e da query. Explícito porque «Todos os Registos» (sem ?tipo) é prefixo dos outros
 * dois e a detecção automática do CabecalhoModulo marcava-o também em «Pagamentos»/«Recebimentos».
 */
export function separadorActivoTesouraria(grupo: GrupoTesouraria, caminho: string, pesquisa = ''): string | undefined {
  const ecra = /^\/m\/teso\/([a-z_]+)/.exec(caminho)?.[1];
  if (!ecra) return undefined;
  if (grupo === 'operacoes' && ecra === 'teso_gestao_pagamentos') {
    const tipo = new URLSearchParams(pesquisa).get('tipo');
    return tipo === 'PAGAMENTO' ? 'pagamentos' : tipo === 'RECEBIMENTO' ? 'recebimentos' : 'todos';
  }
  return GRUPOS_TESOURARIA[grupo].separadores.find((s) => s.destino.split('?')[0] === `${BASE}/${ecra}`)?.chave;
}

/** Indicadores do legado (updateGlobalTesoSummary): «🕒 Pendentes: N» laranja e «✔ Integrados: N» verde. */
export function indicadoresTesouraria(resumo: ResumoTesouraria | undefined): IndicadorModulo[] {
  const n = (v: number | undefined) => (v === undefined ? '…' : v.toLocaleString('pt-PT'));
  return [
    { chave: 'pendentes', rotulo: 'Pendentes', valor: n(resumo?.pendentes), icone: 'clock', cor: '#f59e0b', dica: 'Documentos de tesouraria por integrar no Diário' },
    { chave: 'integrados', rotulo: 'Integrados', valor: n(resumo?.integrados), icone: 'check-circle', cor: '#10b981', dica: 'Documentos de tesouraria integrados no Diário' },
  ];
}

export function useResumoTesouraria() {
  return useQuery({ queryKey: ['teso', 'resumo'], queryFn: () => obter<ResumoTesouraria>('/tesouraria/resumo'), staleTime: 30_000, retry: false });
}

/** Cabeçalho agregador da Tesouraria (CabecalhoModulo, G.5): título, indicadores, «Ecrã Cheio» e separadores-botão. */
export function BarraTesouraria({ grupo }: { grupo: GrupoTesouraria }) {
  const { pathname, search } = useLocation();
  const resumo = useResumoTesouraria();
  const g = GRUPOS_TESOURARIA[grupo];
  const separadores: SeparadorModulo[] = g.separadores.map((s) => ({ chave: s.chave, rotulo: s.rotulo, icone: s.icone, destino: s.destino, permissao: s.permissao }));
  return (
    <CabecalhoModulo
      titulo={g.titulo}
      icone={g.icone}
      subtitulo={g.subtitulo}
      indicadores={resumo.isError ? undefined : indicadoresTesouraria(resumo.data)}
      ecraCheio
      separadores={separadores}
      activo={separadorActivoTesouraria(grupo, pathname, search)}
      minimoSeparadores={1}
    />
  );
}
