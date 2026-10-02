import { Input, Select, type TableProps } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';
import { obter } from '@/api/cliente';
import { TabelaApi, type ColunaApi, type ImpressaoTabelaApi } from '@/componentes/TabelaApi';
import { useEcraPequeno } from '@/componentes/responsivo';
import { usePlanoContas } from '../contab/comum/dados';
import type { DocumentoTesouraria, MeioPagamento } from './api';

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
 * Tabela dos documentos de tesouraria: GET /tesouraria/documentos no formato paginado comum (metadados.paginacao, ADR-064),
 * mostrado pelo TabelaApi de src/componentes.
 */
export function TabelaDocumentos({
  filtros,
  ...props
}: { filtros: Record<string, unknown>; impressao?: ImpressaoTabelaApi; columns?: ColunaApi<DocumentoTesouraria>[] } & Omit<TableProps<DocumentoTesouraria>, 'dataSource' | 'pagination' | 'loading' | 'columns'>) {
  const pequeno = useEcraPequeno();
  return <TabelaApi<DocumentoTesouraria> url="/tesouraria/documentos" chaveConsulta={['teso', 'documentos']} filtros={filtros} rowKey="id" size={pequeno ? 'small' : 'middle'} {...props} />;
}
