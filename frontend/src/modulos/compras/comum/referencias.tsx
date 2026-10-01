import { Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';
import { obter } from '@/api/cliente';

/**
 * Dados de referência partilhados (terceiros, produtos, armazéns). Desde a afinação (ADR-064) as respostas de Compras,
 * Tesouraria e Armazém trazem `fornecedor`/`terceiro` e `produto` — passe-os a NomeTerceiro/NomeProduto para não haver
 * pedidos por linha. Sem eles (ex.: armazem_id), estes componentes resolvem o nome com cache longa.
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

/** Referências que a API já devolve nas respostas (ADR-064): fornecedor/terceiro {id,nome,nif} e produto {id,codigo,nome}. */
export interface RefTerceiro {
  id: number;
  nome: string;
  nif?: string | null;
}

export interface RefProduto {
  id: number;
  codigo: string | null;
  nome: string;
}

/**
 * Nome do terceiro. Com `terceiro` (vindo da resposta) mostra-o logo, sem pedidos; sem ele (respostas antigas),
 * resolve pela ficha com cache longa.
 */
export function NomeTerceiro({ id, terceiro }: { id: number | null | undefined; terceiro?: RefTerceiro | null }) {
  if (!id) return <>—</>;
  if (terceiro?.nome) return <>{terceiro.nome.trim()}</>;
  return <NomeTerceiroFicha id={id} />;
}

function NomeTerceiroFicha({ id }: { id: number }) {
  const consulta = useQuery({
    queryKey: ['terceiros', 'ficha', id],
    queryFn: () => obter<FichaTerceiro>(`/terceiros/${id}`),
    staleTime: DEZ_MINUTOS,
    retry: false,
  });
  return <>{consulta.data?.nome?.trim() ?? `#${id}`}</>;
}

/** Nome do produto: o que vem na resposta (`produto`); senão o catálogo (em cache); se não estiver lá (ex.: bloqueado), a ficha. */
export function NomeProduto({ id, descricao, produto }: { id: number | null | undefined; descricao?: string | null; produto?: RefProduto | null }) {
  if (id && produto?.nome) return <RotuloProduto p={produto} descricao={descricao} />;
  return <NomeProdutoResolvido id={id} descricao={descricao} />;
}

function RotuloProduto({ p, descricao }: { p: { codigo?: string | null; nome: string }; descricao?: string | null }) {
  return (
    <>
      {rotuloProduto(p)}
      {descricao && descricao !== p.nome && (
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
          {descricao}
        </Typography.Text>
      )}
    </>
  );
}

function NomeProdutoResolvido({ id, descricao }: { id: number | null | undefined; descricao?: string | null }) {
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
