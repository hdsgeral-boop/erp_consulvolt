import { AutoComplete, Select, type SelectProps } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter, obterPagina } from '@/api/cliente';
import { rotuloProduto, useArmazens, useCatalogo, type FichaTerceiro } from './referencias';

type PropsBase<V> = Omit<SelectProps<V>, 'options' | 'showSearch' | 'filterOption' | 'onSearch' | 'loading'>;

/** Pesquisa de clientes ou fornecedores (GET /terceiros?papel=…&pesquisa=…). */
export function SeletorTerceiro({ papel, ...props }: PropsBase<number> & { papel: 'CLIENTE' | 'FORNECEDOR' }) {
  const [pesquisa, setPesquisa] = useState('');
  const consulta = useQuery({
    queryKey: ['terceiros', 'pesquisa', papel, pesquisa],
    queryFn: () => obterPagina<FichaTerceiro>('/terceiros', { papel, pesquisa, por_pagina: 30 }),
    retry: false,
  });
  return (
    <Select<number>
      showSearch
      allowClear
      filterOption={false}
      onSearch={setPesquisa}
      loading={consulta.isFetching}
      placeholder={papel === 'CLIENTE' ? 'Pesquisar cliente (nome ou NIF)' : 'Pesquisar fornecedor (nome ou NIF)'}
      options={(consulta.data?.itens ?? []).map((t) => ({ value: t.id, label: `${t.nome.trim()}${t.nif ? ` (NIF ${t.nif})` : ''}` }))}
      {...props}
    />
  );
}

/** Produto do catálogo (produtos activos), com pesquisa por código ou nome. */
export function SeletorProduto({ apenasStock, ...props }: PropsBase<number> & { apenasStock?: boolean }) {
  const { data, isLoading } = useCatalogo();
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={isLoading}
      placeholder="Produto ou serviço"
      options={(data ?? []).filter((p) => !apenasStock || p.movimenta_stock).map((p) => ({ value: p.id, label: rotuloProduto(p) }))}
      {...props}
    />
  );
}

export function SeletorArmazem(props: PropsBase<number>) {
  const { data, isLoading } = useArmazens();
  return (
    <Select<number>
      showSearch
      optionFilterProp="label"
      loading={isLoading}
      placeholder="Armazém"
      options={(data ?? []).map((a) => ({ value: a.id, label: `${a.nome}${a.predefinido ? ' (predefinido)' : ''}` }))}
      {...props}
    />
  );
}

interface PropsConta {
  value?: string;
  onChange?: (v: string) => void;
  /** Prefixo das contas sugeridas (ex.: '31' clientes, '32' fornecedores, '4' disponibilidades). */
  prefixo?: string;
  placeholder?: string;
  disabled?: boolean;
}

/**
 * Conta do plano (só contas de movimento). Sugere as contas do plano quando o utilizador as pode consultar;
 * caso contrário, aceita o código escrito (o servidor valida a conta).
 */
export function SeletorConta({ value, onChange, prefixo, placeholder = 'Código da conta', disabled }: PropsConta) {
  const consulta = useQuery({
    queryKey: ['plano', 'movimento', prefixo ?? ''],
    queryFn: () => obter<{ codigo: string; descricao: string }[]>('/contabilidade/plano-contas', { prefixo, tipo: 'M' }),
    staleTime: 600_000,
    retry: false,
  });
  return (
    <AutoComplete
      value={value}
      onChange={(v: string) => onChange?.(v)}
      disabled={disabled}
      placeholder={placeholder}
      allowClear
      options={(consulta.data ?? []).slice(0, 2000).map((c) => ({ value: c.codigo, label: `${c.codigo} — ${c.descricao}` }))}
      filterOption={(i, o) => String(o?.label ?? '').toLowerCase().includes(i.toLowerCase())}
    />
  );
}
