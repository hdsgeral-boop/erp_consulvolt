import { Select, Tag, Typography, type SelectProps } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { formatarKz } from '@/utilitarios/formatacao';
import type { ListaParidade, Orcamento, OrcamentoLista, Rubrica, TipoOrcamento } from './tipos';

/** Componentes e consultas partilhados do Orçamento. Chaves de cache: ['orcamento', ...]. */

/** Cores das pílulas do legado (.orc-nat, .orc-estado-pill — orcamento_ui.js:45-47, orcamento_planeamento_ui.js:38-41). */
const ESTADOS: Record<string, { rotulo: string; cor: string }> = {
  RASCUNHO: { rotulo: 'Rascunho', cor: 'default' },
  SUBMETIDO: { rotulo: 'Submetido', cor: 'gold' },
  APROVADO: { rotulo: 'Aprovado', cor: 'green' },
  SUBSTITUIDO: { rotulo: 'Substituído', cor: 'default' },
  PUBLICADA: { rotulo: 'Publicada', cor: 'green' },
  PENDENTE: { rotulo: 'Pendente', cor: 'gold' },
  REJEITADO: { rotulo: 'Recusado', cor: 'red' },
  UTILIZADO: { rotulo: 'Aprovado e utilizado', cor: 'default' },
  EXPLORACAO: { rotulo: 'Exploração', cor: 'geekblue' },
  TESOURARIA: { rotulo: 'Tesouraria', cor: 'cyan' },
  PROVEITO: { rotulo: 'Proveito', cor: 'green' },
  CUSTO: { rotulo: 'Custo', cor: 'red' },
  RECEBIMENTO: { rotulo: 'Recebimento', cor: 'green' },
  PAGAMENTO: { rotulo: 'Pagamento', cor: 'red' },
  OK: { rotulo: 'Dentro', cor: 'green' },
  AVISO: { rotulo: 'Aviso', cor: 'gold' },
  EXCEDIDO: { rotulo: 'Excedido', cor: 'red' },
  SEM_DOTACAO: { rotulo: 'Sem dotação', cor: 'default' },
  BLOQUEIO: { rotulo: 'Bloqueio', cor: 'red' },
  APROVACAO: { rotulo: 'Aprovação', cor: 'purple' },
  NENHUM: { rotulo: 'Sem controlo', cor: 'default' },
  AVISAR: { rotulo: 'Avisar', cor: 'orange' },
  BLOQUEAR: { rotulo: 'Bloquear', cor: 'red' },
  TEMPORAL: { rotulo: 'Temporal', cor: 'blue' },
  PONTUAL: { rotulo: 'Pontual', cor: 'gold' },
  ESTRUTURAL: { rotulo: 'Estrutural', cor: 'red' },
  MISTO: { rotulo: 'Misto', cor: 'purple' },
  SEM_DESVIO: { rotulo: 'Sem desvio', cor: 'green' },
};

export function rotuloOrc(v: string | null | undefined): string {
  if (!v) return '—';
  return ESTADOS[v]?.rotulo ?? v.charAt(0) + v.slice(1).toLowerCase().replace(/_/g, ' ');
}

export function EtiquetaOrc({ valor }: { valor: string | null | undefined }) {
  if (!valor) return <>—</>;
  return <Tag color={ESTADOS[valor]?.cor ?? 'default'}>{rotuloOrc(valor)}</Tag>;
}

/** Valor em Kz (texto decimal desde a ADR-064; aceita também números); negativos a vermelho. */
export function Kz({ valor, forte }: { valor: number | string | null | undefined; forte?: boolean }) {
  const n = Number(valor ?? 0);
  return <Typography.Text strong={forte} type={n < 0 ? 'danger' : undefined} style={{ whiteSpace: 'nowrap' }}>{formatarKz(valor === null || valor === undefined ? null : n)}</Typography.Text>;
}

export function useRubricas(tipo?: TipoOrcamento) {
  return useQuery({ queryKey: ['orcamento', 'rubricas', tipo ?? 'todas'], queryFn: () => obter<Rubrica[]>('/orcamento/rubricas', { tipo }), staleTime: 120_000 });
}

export function useOrcamentos(filtros: { ano?: number; tipo?: TipoOrcamento } = {}) {
  // a lista é paginada no servidor (ADR-064): os seletores e a lista pedem a página máxima (500)
  return useQuery({ queryKey: ['orcamento', 'orcamentos', filtros], queryFn: () => obter<Orcamento[]>('/orcamento/orcamentos', { ...filtros, por_pagina: 500 }) });
}

/** Lista de paridade (ordem, dimensões, total das rubricas, última acção e orçamento por omissão do sistema anterior). */
export function useOrcamentosLegado(filtros: { ano?: number; tipo?: TipoOrcamento } = {}) {
  return useQuery({ queryKey: ['orcamento', 'paridade', 'orcamentos', filtros], queryFn: () => obter<ListaParidade>('/orcamento/paridade/orcamentos', filtros) });
}

/** Seletor «Orçamento» com os rótulos do legado («2026 · Exploração · Toda a empresa · v1 (Rascunho)»). */
export function SeletorOrcamentoLegado({ filtrar, ...props }: Omit<SelectProps<number>, 'options' | 'loading'> & { filtrar?: (o: OrcamentoLista) => boolean }) {
  const q = useOrcamentosLegado();
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={q.isLoading}
      placeholder="Orçamento"
      popupMatchSelectWidth={false}
      options={(q.data?.orcamentos ?? []).filter((o) => !filtrar || filtrar(o)).map((o) => ({ value: o.id, label: o.rotulo }))}
      {...props}
    />
  );
}

export function rotuloOrcamento(o: Pick<Orcamento, 'ano' | 'nome' | 'versao' | 'tipo' | 'estado'>): string {
  return `${o.ano} · ${o.nome ?? rotuloOrc(o.tipo)} · v${o.versao} (${rotuloOrc(o.estado)})`;
}

/** Orçamento (lista completa, opcionalmente filtrada por ano/tipo/estado). */
export function SeletorOrcamento({ ano, tipo, estados, ...props }: Omit<SelectProps<number>, 'options' | 'loading'> & { ano?: number; tipo?: TipoOrcamento; estados?: string[] }) {
  const q = useOrcamentos({ ano, tipo });
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={q.isLoading}
      placeholder="Orçamento"
      popupMatchSelectWidth={false}
      options={(q.data ?? []).filter((o) => !estados || estados.includes(o.estado)).map((o) => ({ value: o.id, label: rotuloOrcamento(o) }))}
      {...props}
    />
  );
}
