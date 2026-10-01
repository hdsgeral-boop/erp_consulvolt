import { keepPreviousData, useQuery } from '@tanstack/react-query';
import type { TablePaginationConfig } from 'antd';
import type { Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obterPagina } from '@/api/cliente';
import { dataApi } from '@/utilitarios/formatacao';

/**
 * Lista paginada no servidor (metadados.paginacao, ADR-064) com filtros: muda de página sem perder os filtros e volta à
 * primeira quando estes mudam. A chave da consulta começa por `chave` (para a invalidação do módulo).
 */
export function useListaPaginada<T>(chave: unknown[], url: string, filtros: Record<string, unknown>, porPagina = 50) {
  const [pagina, setPagina] = useState(1);
  const [tamanho, setTamanho] = useState(porPagina);
  const chaveFiltros = JSON.stringify(filtros);
  useEffect(() => setPagina(1), [chaveFiltros]);
  const q = useQuery({
    queryKey: [...chave, filtros, pagina, tamanho],
    queryFn: () => obterPagina<T>(url, { ...filtros, pagina, por_pagina: tamanho }),
    placeholderData: keepPreviousData,
  });
  const paginacao: TablePaginationConfig = {
    current: pagina,
    pageSize: tamanho,
    total: q.data?.paginacao.total ?? 0,
    showSizeChanger: true,
    pageSizeOptions: [25, 50, 100, 200],
    showTotal: (t) => `${t} registo(s)`,
    onChange: (p, s) => { setPagina(s !== tamanho ? 1 : p); setTamanho(s); },
  };
  return { ...q, itens: q.data?.itens ?? [], paginacao };
}

/** Período (data de/até) para os filtros das listas. */
export function filtroPeriodo(periodo: [Dayjs | null, Dayjs | null] | null | undefined): { data_de?: string; data_ate?: string } {
  return { data_de: dataApi(periodo?.[0] ?? null) ?? undefined, data_ate: dataApi(periodo?.[1] ?? null) ?? undefined };
}
