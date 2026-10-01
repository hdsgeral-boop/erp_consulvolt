import { Select, Tag, type SelectProps } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter, obterPagina } from '@/api/cliente';
import type { Activo, CategoriaActivo } from './tipos';

/** Componentes e consultas partilhados do módulo Activos. Chaves de cache: ['activos', ...]. */

const CINCO_MIN = 300_000;

const ESTADOS: Record<string, { rotulo: string; cor: string }> = {
  ACTIVO: { rotulo: 'Activo', cor: 'green' },
  INACTIVO: { rotulo: 'Inactivo', cor: 'default' },
  ABATIDO: { rotulo: 'Abatido', cor: 'red' },
  POR_CALCULAR: { rotulo: 'Por calcular', cor: 'orange' },
  RASCUNHO: { rotulo: 'Rascunho', cor: 'gold' },
  INTEGRADO: { rotulo: 'Integrado', cor: 'green' },
  ABERTO: { rotulo: 'Aberto', cor: 'blue' },
  CALCULADO: { rotulo: 'Calculado', cor: 'gold' },
  PARCIAL: { rotulo: 'Parcial', cor: 'orange' },
  PENDENTE: { rotulo: 'Pendente', cor: 'orange' },
  CONCLUIDA: { rotulo: 'Concluída', cor: 'green' },
  PLANEADA: { rotulo: 'Planeada', cor: 'blue' },
  PREVENTIVA: { rotulo: 'Preventiva', cor: 'blue' },
  CORRECTIVA: { rotulo: 'Correctiva', cor: 'volcano' },
  SINISTRO: { rotulo: 'Sinistro', cor: 'volcano' },
  VENDA: { rotulo: 'Venda', cor: 'blue' },
  FIM_VIDA: { rotulo: 'Fim de vida', cor: 'default' },
};

export function rotuloActivos(valor: string | null | undefined): string {
  if (!valor) return '—';
  return ESTADOS[valor]?.rotulo ?? valor.charAt(0) + valor.slice(1).toLowerCase().replace(/_/g, ' ');
}

/** Etiqueta para estados de activos, quotas, períodos, manutenções e tipos de abate. */
export function EtiquetaActivos({ valor }: { valor: string | null | undefined }) {
  if (!valor) return <>—</>;
  return <Tag color={ESTADOS[valor]?.cor ?? 'default'}>{rotuloActivos(valor)}</Tag>;
}

export function useCategorias() {
  return useQuery({ queryKey: ['activos', 'categorias'], queryFn: () => obter<CategoriaActivo[]>('/ativos/categorias'), staleTime: CINCO_MIN });
}

type PropsSelect<V> = Omit<SelectProps<V>, 'options' | 'loading' | 'showSearch'>;

export function SeletorCategoria(props: PropsSelect<number>) {
  const c = useCategorias();
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={c.isLoading}
      placeholder="Categoria"
      popupMatchSelectWidth={false}
      options={(c.data ?? []).map((x) => ({ value: x.id, label: x.nome }))}
      {...props}
    />
  );
}

/** Activo por pesquisa remota (código ou descrição). `apenasActivos` filtra o estado ACTIVO. */
export function SeletorActivo({ apenasActivos, rotuloInicial, ...props }: PropsSelect<number> & { apenasActivos?: boolean; rotuloInicial?: string }) {
  const [texto, setTexto] = useState('');
  const q = useQuery({
    queryKey: ['activos', 'pesquisa', texto, apenasActivos],
    queryFn: () => obterPagina<Activo>('/ativos/bens', { texto, estado: apenasActivos ? 'ACTIVO' : undefined, por_pagina: 30 }),
    staleTime: 60_000,
  });
  const opcoes: SelectProps['options'] = (q.data?.itens ?? []).map((a) => ({ value: a.id, label: `${a.codigo ?? '—'} — ${a.descricao}` }));
  if (props.value && rotuloInicial && !opcoes.some((o) => o.value === props.value)) opcoes.unshift({ value: props.value, label: rotuloInicial });
  return (
    <Select<number>
      showSearch
      filterOption={false}
      onSearch={setTexto}
      loading={q.isFetching}
      placeholder="Activo (código ou descrição)"
      popupMatchSelectWidth={false}
      options={opcoes}
      {...props}
    />
  );
}

export interface ProjectoResumo {
  id: number;
  codigo: string | null;
  nome: string;
  tipo?: string | null;
  estado?: string | null;
}

/** Projectos activos (GET /projetos/ativos), para afectações e transferências. */
export function SeletorProjecto(props: PropsSelect<number>) {
  const q = useQuery({ queryKey: ['projectos', 'activos-resumo'], queryFn: () => obter<ProjectoResumo[]>('/projetos/ativos'), staleTime: CINCO_MIN, retry: false });
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={q.isLoading}
      placeholder="Projecto"
      popupMatchSelectWidth={false}
      options={(q.data ?? []).map((p) => ({ value: p.id, label: `${p.codigo ?? ''} — ${p.nome}` }))}
      {...props}
    />
  );
}
