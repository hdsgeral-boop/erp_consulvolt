import { Table, type TableProps } from 'antd';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import type { Pagina } from '@/api/tipos';
import { notificarErro } from '@/utilitarios/erros';
import { obterLista } from './lista';

interface PropsServidor<T> extends Omit<TableProps<T>, 'dataSource' | 'pagination' | 'loading'> {
  url: string;
  filtros?: Record<string, unknown>;
  chaveConsulta: unknown[];
  porPagina?: number;
  /** Como obter a página (por omissão: formato das listagens de /api/compras). */
  carregar?: (url: string, params: Record<string, unknown>) => Promise<Pagina<T>>;
}

/**
 * Tabela com paginação do servidor para as listagens que NÃO usam `metadados.paginacao` (ex.: /api/compras/*).
 * Mesmo comportamento do TabelaApi: os filtros mudam a consulta e voltam à 1.ª página.
 */
export function TabelaServidor<T extends object>({ url, filtros = {}, chaveConsulta, porPagina = 25, carregar = obterLista, ...props }: PropsServidor<T>) {
  const [pagina, setPagina] = useState(1);
  const [tamanho, setTamanho] = useState(porPagina);
  const consulta = useQuery({
    queryKey: [...chaveConsulta, filtros, pagina, tamanho],
    queryFn: () => carregar(url, { ...filtros, pagina, por_pagina: tamanho }),
    placeholderData: keepPreviousData,
  });
  const chaveFiltros = JSON.stringify(filtros);
  useEffect(() => setPagina(1), [chaveFiltros]);
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar a listagem');
  }, [consulta.error]);

  return (
    <Table<T>
      rowKey={(r) => String((r as { id?: number | string }).id ?? JSON.stringify(r))}
      size="middle"
      scroll={{ x: 'max-content' }}
      {...props}
      loading={consulta.isFetching}
      dataSource={consulta.data?.itens}
      pagination={{
        current: consulta.data?.paginacao.pagina_atual ?? pagina,
        pageSize: tamanho,
        total: consulta.data?.paginacao.total ?? 0,
        showSizeChanger: true,
        pageSizeOptions: [10, 25, 50, 100],
        showTotal: (t) => `${t} registo(s)`,
        onChange: (p, s) => {
          setPagina(s !== tamanho ? 1 : p);
          setTamanho(s);
        },
      }}
    />
  );
}

interface PropsLocal<T> extends Omit<TableProps<T>, 'dataSource' | 'loading'> {
  url: string;
  params?: Record<string, unknown>;
  chaveConsulta: unknown[];
  /** Filtro aplicado no cliente (as listagens sem paginação devolvem tudo). */
  filtrar?: (linha: T) => boolean;
}

/** Tabela para endpoints que devolvem a lista completa (sem paginação no servidor); pagina e filtra no cliente. */
export function TabelaLocal<T extends object>({ url, params, chaveConsulta, filtrar, ...props }: PropsLocal<T>) {
  const consulta = useQuery({ queryKey: [...chaveConsulta, params ?? {}], queryFn: () => obter<T[]>(url, params) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar a listagem');
  }, [consulta.error]);
  const linhas = useMemo(() => (filtrar ? (consulta.data ?? []).filter(filtrar) : consulta.data ?? []), [consulta.data, filtrar]);

  return (
    <Table<T>
      rowKey={(r) => String((r as { id?: number | string }).id ?? JSON.stringify(r))}
      size="middle"
      scroll={{ x: 'max-content' }}
      pagination={{ defaultPageSize: 25, showSizeChanger: true, pageSizeOptions: [10, 25, 50, 100], showTotal: (t) => `${t} registo(s)` }}
      {...props}
      loading={consulta.isFetching}
      dataSource={linhas}
    />
  );
}
