import { Table, type TableProps } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo } from 'react';
import { obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { scrollTabela } from '@/componentes/responsivo';

/*
 * As listagens paginadas no servidor (/api/compras/*, /api/compras/contratos, /api/logistica/guias-saida e inventarios,
 * /api/tesouraria/documentos) usam o formato comum desde a afinação (ADR-064): mostram-se com o TabelaApi de
 * src/componentes. Aqui fica só a tabela para endpoints que devolvem a lista completa.
 */

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
      scroll={scrollTabela()}
      pagination={{ defaultPageSize: 25, showSizeChanger: true, pageSizeOptions: [10, 25, 50, 100], showTotal: (t) => `${t} registo(s)` }}
      {...props}
      loading={consulta.isFetching}
      dataSource={linhas}
    />
  );
}
