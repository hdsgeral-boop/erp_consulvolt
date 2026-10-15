import { Select, Tag, type SelectProps } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { ESTADOS_ORDEM } from './regras';
import type { Artigo, EstadoOrdem } from './tipos';

/** Artigos para as necessidades, por código ou nome (GET /ativos/manutencao/artigos). Serviços: só artigos sem stock. */
export function SeletorArtigo({ tipo, rotuloInicial, ...props }: Omit<SelectProps<number>, 'options' | 'showSearch'> & { tipo?: 'MATERIAL' | 'SERVICO'; rotuloInicial?: string }) {
  const [texto, setTexto] = useState('');
  const q = useQuery({ queryKey: ['activos', 'manutencao', 'artigos', texto, tipo], queryFn: () => obter<Artigo[]>('/ativos/manutencao/artigos', { texto, tipo }), staleTime: 60_000 });
  const opcoes: NonNullable<SelectProps<number>['options']> = (q.data ?? []).map((a) => ({ value: a.id, label: `${a.codigo ? `${a.codigo} — ` : ''}${a.nome}` }));
  if (props.value && rotuloInicial && !opcoes.some((o) => o.value === props.value)) opcoes.unshift({ value: props.value, label: rotuloInicial });
  return <Select<number> showSearch filterOption={false} onSearch={setTexto} loading={q.isFetching} placeholder="— escolher —" popupMatchSelectWidth={false} options={opcoes} {...props} />;
}

export function useArmazens() {
  return useQuery({ queryKey: ['activos', 'manutencao', 'armazens'], queryFn: () => obter<{ id: number; codigo: string | null; nome: string; predefinido: boolean | null }[]>('/ativos/manutencao/armazens'), staleTime: 300_000 });
}

export function EtiquetaOrdem({ estado }: { estado: EstadoOrdem | string }) {
  const e = ESTADOS_ORDEM[estado as EstadoOrdem];
  return <Tag color={e?.cor ?? 'default'}>{e?.rotulo ?? estado}</Tag>;
}
