import { Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';
import { obter } from '@/api/cliente';

/**
 * Dados de referência partilhados (terceiros, produtos, armazéns). Várias respostas da API trazem só o id
 * (ex.: fornecedor_id, produto_id, armazem_id): estes componentes resolvem o nome com cache longa.
 * Se o utilizador não tiver permissão para a ficha, mostra-se o id (o pedido falha em silêncio).
 */

const DEZ_MINUTOS = 600_000;

export interface Armazem {
  id: number;
  nome: string;
  codigo: string | null;
  localizacao: string | null;
  predefinido: boolean | null;
}

export interface ProdutoCatalogo {
  id: number;
  codigo: string | null;
  nome: string;
  preco_unitario: string | null;
  taxa_imposto: string | null;
  movimenta_stock: boolean | null;
}

export interface FichaTerceiro {
  id: number;
  nome: string;
  nif: string | null;
  tipo?: string | null;
  e_cliente?: boolean;
  e_fornecedor?: boolean;
  endereco?: string | null;
  email?: string | null;
  telefone?: string | null;
  codigo_conta: string | null;
  conta_compra_transitoria?: string | null;
  codigo_moeda?: string | null;
  fe_pais?: string | null;
}

export function useArmazens() {
  return useQuery({ queryKey: ['logistica', 'armazens'], queryFn: () => obter<Armazem[]>('/logistica/armazens'), staleTime: DEZ_MINUTOS, retry: false });
}

export function useCatalogo() {
  return useQuery({ queryKey: ['logistica', 'catalogo'], queryFn: () => obter<ProdutoCatalogo[]>('/logistica/produtos/catalogo'), staleTime: 300_000, retry: false });
}

/** Mapa id → produto do catálogo (produtos activos). */
export function useMapaProdutos(): Map<number, ProdutoCatalogo> {
  const { data } = useCatalogo();
  return useMemo(() => new Map((data ?? []).map((p) => [p.id, p])), [data]);
}

export function rotuloProduto(p: { codigo?: string | null; nome: string } | undefined | null): string {
  if (!p) return '';
  return p.codigo ? `${p.codigo} — ${p.nome}` : p.nome;
}

export function NomeTerceiro({ id }: { id: number | null | undefined }) {
  const consulta = useQuery({
    queryKey: ['terceiros', 'ficha', id],
    queryFn: () => obter<FichaTerceiro>(`/terceiros/${id}`),
    enabled: !!id,
    staleTime: DEZ_MINUTOS,
    retry: false,
  });
  if (!id) return <>—</>;
  return <>{consulta.data?.nome?.trim() ?? `#${id}`}</>;
}

/** Nome do produto: primeiro o catálogo (em cache); se não estiver lá (ex.: bloqueado), a ficha. */
export function NomeProduto({ id, descricao }: { id: number | null | undefined; descricao?: string | null }) {
  const mapa = useMapaProdutos();
  const doCatalogo = id ? mapa.get(id) : undefined;
  const ficha = useQuery({
    queryKey: ['logistica', 'produto', id],
    queryFn: () => obter<ProdutoCatalogo>(`/logistica/produtos/${id}`),
    enabled: !!id && mapa.size > 0 && !doCatalogo,
    staleTime: DEZ_MINUTOS,
    retry: false,
  });
  if (!id) return <>{descricao || '—'}</>;
  const p = doCatalogo ?? ficha.data;
  return (
    <>
      {p ? rotuloProduto(p) : `Produto #${id}`}
      {descricao && descricao !== p?.nome && (
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
          {descricao}
        </Typography.Text>
      )}
    </>
  );
}

export function NomeArmazem({ id }: { id: number | null | undefined }) {
  const { data } = useArmazens();
  if (!id) return <>—</>;
  return <>{data?.find((a) => a.id === id)?.nome ?? `#${id}`}</>;
}
