import { Table, type TableProps } from 'antd';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obterPagina } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';

interface Props<T> extends Omit<TableProps<T>, 'dataSource' | 'pagination' | 'loading'> {
  /** Caminho da API paginada (RespostaApi::paginado). */
  url: string;
  /** Filtros enviados como parâmetros; mudam a consulta e voltam à 1.ª página. */
  filtros?: Record<string, unknown>;
  chaveConsulta: unknown[];
  porPagina?: number;
}

/** Tabela ligada a uma listagem paginada do servidor (pagina/por_pagina), com a paginação da API. */
export function TabelaApi<T extends object>({ url, filtros = {}, chaveConsulta, porPagina = 25, ...props }: Props<T>) {
  const [pagina, setPagina] = useState(1);
  const [tamanho, setTamanho] = useState(porPagina);
  const consulta = useQuery({
    queryKey: [...chaveConsulta, filtros, pagina, tamanho],
    queryFn: () => obterPagina<T>(url, { ...filtros, pagina, por_pagina: tamanho }),
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
