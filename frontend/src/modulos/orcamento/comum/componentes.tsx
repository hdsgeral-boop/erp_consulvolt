import { Select, Tag, Typography, type SelectProps } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Orcamento, Rubrica, TipoOrcamento } from './tipos';

/** Componentes e consultas partilhados do Orçamento. Chaves de cache: ['orcamento', ...]. */

const ESTADOS: Record<string, { rotulo: string; cor: string }> = {
  RASCUNHO: { rotulo: 'Rascunho', cor: 'default' },
  SUBMETIDO: { rotulo: 'Submetido', cor: 'gold' },
  APROVADO: { rotulo: 'Aprovado', cor: 'green' },
  SUBSTITUIDO: { rotulo: 'Substituído', cor: 'default' },
  PUBLICADA: { rotulo: 'Publicada', cor: 'green' },
  PENDENTE: { rotulo: 'Pendente', cor: 'orange' },
  REJEITADO: { rotulo: 'Rejeitado', cor: 'red' },
  UTILIZADO: { rotulo: 'Utilizado', cor: 'blue' },
  EXPLORACAO: { rotulo: 'Exploração', cor: 'geekblue' },
  TESOURARIA: { rotulo: 'Tesouraria', cor: 'cyan' },
  PROVEITO: { rotulo: 'Proveito', cor: 'green' },
  CUSTO: { rotulo: 'Custo', cor: 'volcano' },
  RECEBIMENTO: { rotulo: 'Recebimento', cor: 'green' },
  PAGAMENTO: { rotulo: 'Pagamento', cor: 'volcano' },
  OK: { rotulo: 'OK', cor: 'green' },
  AVISO: { rotulo: 'Aviso', cor: 'orange' },
  EXCEDIDO: { rotulo: 'Excedido', cor: 'red' },
  SEM_DOTACAO: { rotulo: 'Sem dotação', cor: 'default' },
  BLOQUEIO: { rotulo: 'Bloqueio', cor: 'red' },
  APROVACAO: { rotulo: 'Aprovação', cor: 'gold' },
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
