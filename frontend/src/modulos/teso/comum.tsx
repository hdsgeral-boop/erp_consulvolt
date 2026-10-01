import { Input, Select, Table, type TableProps } from 'antd';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { usePlanoContas } from '../contab/comum/dados';
import type { DocumentoTesouraria, MeioPagamento, PaginaDocumentos } from './api';

export function useMeiosPagamento() {
  return useQuery({ queryKey: ['teso', 'meios'], queryFn: () => obter<MeioPagamento[]>('/tesouraria/meios-pagamento'), staleTime: 300_000, retry: false });
}

/**
 * Selector da conta de banco/caixa (43/45): meios de pagamento activos e, se o utilizador tiver acesso ao plano,
 * as restantes contas de movimento 43/45. Sem nenhuma das fontes, texto livre (o servidor valida).
 */
export function SeletorContaFinanceira({ value, onChange, allowClear, placeholder = 'Conta de banco/caixa', style, prefixos = ['43', '45'] }: { value?: string; onChange?: (v: string | undefined) => void; allowClear?: boolean; placeholder?: string; style?: React.CSSProperties; prefixos?: string[] }) {
  const meios = useMeiosPagamento();
  const plano = usePlanoContas();
  const opcoes = useMemo(() => {
    const vistos = new Set<string>();
    const lista: { value: string; label: string }[] = [];
    for (const m of meios.data ?? []) {
      if (!m.ativo || vistos.has(m.codigo_conta)) continue;
      vistos.add(m.codigo_conta);
      lista.push({ value: m.codigo_conta, label: `${m.codigo_conta} — ${m.nome}${m.codigo_moeda && m.codigo_moeda !== 'AOA' ? ` (${m.codigo_moeda})` : ''}` });
    }
    for (const c of plano.data ?? []) {
      if (c.tipo !== 'M' || vistos.has(c.codigo) || !prefixos.some((p) => c.codigo.startsWith(p))) continue;
      vistos.add(c.codigo);
      lista.push({ value: c.codigo, label: `${c.codigo} — ${c.descricao ?? ''}` });
    }
    return lista;
  }, [meios.data, plano.data, prefixos]);

  if (!opcoes.length && (meios.isError || !meios.data?.length) && plano.isError) {
    return <Input value={value} onChange={(e) => onChange?.(e.target.value || undefined)} placeholder={placeholder} maxLength={20} style={style} />;
  }
  return (
    <Select
      showSearch
      value={value}
      onChange={onChange}
      allowClear={allowClear}
      placeholder={placeholder}
      loading={meios.isLoading || plano.isLoading}
      optionFilterProp="label"
      options={opcoes}
      style={{ minWidth: 240, ...style }}
      popupMatchSelectWidth={false}
    />
  );
}

/**
 * Tabela dos documentos de tesouraria. O GET /tesouraria/documentos devolve {itens, total, pagina, por_pagina} dentro de `dados`
 * (não usa metadados.paginacao), por isso não serve o TabelaApi comum.
 */
export function TabelaDocumentos({ filtros, ...props }: { filtros: Record<string, unknown> } & Omit<TableProps<DocumentoTesouraria>, 'dataSource' | 'pagination' | 'loading'>) {
  const [pagina, setPagina] = useState(1);
  const [tamanho, setTamanho] = useState(25);
  const chave = JSON.stringify(filtros);
  useEffect(() => setPagina(1), [chave]);
  const consulta = useQuery({
    queryKey: ['teso', 'documentos', filtros, pagina, tamanho],
    queryFn: () => obter<PaginaDocumentos>('/tesouraria/documentos', { ...filtros, pagina, por_pagina: tamanho }),
    placeholderData: keepPreviousData,
  });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar os documentos');
  }, [consulta.error]);
  return (
    <Table<DocumentoTesouraria>
      rowKey="id"
      size="middle"
      scroll={{ x: 'max-content' }}
      {...props}
      loading={consulta.isFetching}
      dataSource={consulta.data?.itens}
      pagination={{
        current: pagina,
        pageSize: tamanho,
        total: consulta.data?.total ?? 0,
        showSizeChanger: true,
        pageSizeOptions: [10, 25, 50, 100],
        showTotal: (t) => `${t} documento(s)`,
        onChange: (p, s) => {
          setPagina(s !== tamanho ? 1 : p);
          setTamanho(s);
        },
      }}
    />
  );
}
