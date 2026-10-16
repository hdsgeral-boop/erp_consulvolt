import { Input, Select, type TableProps } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';
import { obter, obterPagina } from '@/api/cliente';
import { TabelaApi, type ColunaApi, type ImpressaoTabelaApi } from '@/componentes/TabelaApi';
import { useEcraPequeno } from '@/componentes/responsivo';
import type { PropsPadroesLista } from '@/componentes/vistas';
import type { ReactNode } from 'react';
import { usePlanoContas } from '../contab/comum/dados';
import type { DocumentoTesouraria, MeioPagamento } from './api';

export function useMeiosPagamento() {
  return useQuery({ queryKey: ['teso', 'meios'], queryFn: () => obter<MeioPagamento[]>('/tesouraria/meios-pagamento'), staleTime: 300_000, retry: false });
}

/**
 * Selector da conta de banco/caixa (43/45): meios de pagamento activos e, se o utilizador tiver acesso ao plano,
 * as restantes contas de movimento 43/45. Sem nenhuma das fontes, texto livre (o servidor valida).
 * `estrito`: os meios de pagamento também são filtrados pelos prefixos e o rótulo é a descrição do plano (ex.: pagamentos/
 * recebimentos só por bancos, Fase A).
 */
export function SeletorContaFinanceira({ value, onChange, allowClear, placeholder = 'Conta de banco/caixa', style, prefixos = ['43', '45'], estrito = false }: { value?: string; onChange?: (v: string | undefined) => void; allowClear?: boolean; placeholder?: string; style?: React.CSSProperties; prefixos?: string[]; estrito?: boolean }) {
  const meios = useMeiosPagamento();
  const plano = usePlanoContas();
  const opcoes = useMemo(() => {
    const vistos = new Set<string>();
    const lista: { value: string; label: string }[] = [];
    const doMeios = () => {
      for (const m of meios.data ?? []) {
        if (!m.ativo || vistos.has(m.codigo_conta) || (estrito && !prefixos.some((p) => m.codigo_conta.startsWith(p)))) continue;
        vistos.add(m.codigo_conta);
        lista.push({ value: m.codigo_conta, label: `${m.codigo_conta} - ${m.nome}${m.codigo_moeda && m.codigo_moeda !== 'AOA' ? ` (${m.codigo_moeda})` : ''}` });
      }
    };
    const doPlano = () => {
      for (const c of plano.data ?? []) {
        if (c.tipo !== 'M' || vistos.has(c.codigo) || !prefixos.some((p) => c.codigo.startsWith(p))) continue;
        vistos.add(c.codigo);
        lista.push({ value: c.codigo, label: `${c.codigo} - ${c.descricao ?? ''}` });
      }
    };
    // estrito (pagamentos/recebimentos só por bancos): como filterContasFinanceiras do legado, as contas do plano com a
    // descrição do plano; os meios de pagamento só acrescentam contas que o utilizador não vê no plano
    if (estrito) {
      doPlano();
      doMeios();
    } else {
      doMeios();
      doPlano();
    }
    return lista;
  }, [meios.data, plano.data, prefixos, estrito]);

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
/** Total de documentos de tesouraria com os filtros dados (etiqueta «Total: N» dos cartões do legado). */
export function useTotalDocumentos(filtros: Record<string, unknown>): number | undefined {
  const q = useQuery({
    queryKey: ['teso', 'documentos', 'total', filtros],
    queryFn: () => obterPagina<DocumentoTesouraria>('/tesouraria/documentos', { ...filtros, pagina: 1, por_pagina: 1 }),
    retry: false,
  });
  return q.data?.paginacao.total;
}

export function TabelaDocumentos({
  filtros,
  ...props
}: { filtros: Record<string, unknown>; impressao?: ImpressaoTabelaApi; columns?: ColunaApi<DocumentoTesouraria>[]; barra?: ReactNode }
  & Partial<Pick<PropsPadroesLista<DocumentoTesouraria>, 'accoes' | 'seleccao' | 'vazio' | 'tituloAccoes'>>
  & Omit<TableProps<DocumentoTesouraria>, 'dataSource' | 'pagination' | 'loading' | 'columns'>) {
  const pequeno = useEcraPequeno();
  return <TabelaApi<DocumentoTesouraria> url="/tesouraria/documentos" chaveConsulta={['teso', 'documentos']} filtros={filtros} rowKey="id" size={pequeno ? 'small' : 'middle'} {...props} />;
}
