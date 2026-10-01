import { Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import type { Definicoes } from './tipos';

/** Componentes e consultas partilhados de Acréscimos e Diferimentos. Chaves de cache: ['acrescimos', ...]. */

const CORES: Record<string, string> = {
  ACTIVO: 'green', A_REGULARIZAR: 'gold', REGULARIZADO: 'blue', A_TERMINAR: 'orange', CONCLUIDO: 'default', ANULADO: 'red',
  ACRESCIMO: 'purple', DIFERIMENTO: 'cyan', CUSTO: 'volcano', PROVEITO: 'green',
  INICIAL: 'geekblue', RECONHECIMENTO: 'blue', REGULARIZACAO: 'gold', ANULACAO: 'red', TERMINO: 'orange', CONTABILIZADO: 'green',
  COMPRA: 'magenta', VENDA: 'green', DIARIO: 'blue', TESOURARIA: 'cyan', MANUAL: 'default',
};

const ROTULOS: Record<string, string> = {
  ACTIVO: 'Activo', A_REGULARIZAR: 'Regularização por contabilizar', REGULARIZADO: 'Regularizado', A_TERMINAR: 'Término por contabilizar', CONCLUIDO: 'Concluído', ANULADO: 'Anulado',
  ACRESCIMO: 'Acréscimo', DIFERIMENTO: 'Diferimento', CUSTO: 'Gasto', PROVEITO: 'Rendimento',
  INICIAL: 'Diferimento inicial', RECONHECIMENTO: 'Reconhecimento', REGULARIZACAO: 'Regularização', ANULACAO: 'Anulação', TERMINO: 'Término antecipado', CONTABILIZADO: 'Contabilizado',
  COMPRA: 'Compras', VENDA: 'Vendas', DIARIO: 'Diário', TESOURARIA: 'Tesouraria', MANUAL: 'Manual',
};

export function rotuloAD(v: string | null | undefined): string {
  return v ? ROTULOS[v] ?? v : '—';
}

export function EtiquetaAD({ valor }: { valor: string | null | undefined }) {
  if (!valor) return <>—</>;
  return <Tag color={CORES[valor] ?? 'default'}>{rotuloAD(valor)}</Tag>;
}

export function useDefinicoes() {
  return useQuery({ queryKey: ['acrescimos', 'definicoes'], queryFn: () => obter<Definicoes>('/acrescimos/definicoes'), staleTime: 300_000 });
}
