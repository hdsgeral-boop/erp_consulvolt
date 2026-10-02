import { Card, Table, Tooltip, Typography } from 'antd';
import { ArrowDownOutlined, ArrowUpOutlined, InfoCircleOutlined, MinusOutlined } from '@ant-design/icons';
import { useCallback, type ReactNode } from 'react';
import { tabelaHtml } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';

/** Formatos usados pelos painéis, relatórios de gestão e fluxos. */
export function formatarPorFormato(valor: unknown, formato?: string | null, casas?: number | null): string {
  if (valor === null || valor === undefined || valor === '') return '—';
  switch (formato) {
    case 'kz':
    case 'moeda':
    case 'valor':
      return formatarKz(valor as string);
    case 'pct':
      return `${new Intl.NumberFormat('pt-PT', { maximumFractionDigits: casas ?? 2 }).format(Number(valor))}%`;
    case 'dias':
      return `${formatarNumero(valor as string)} dias`;
    case 'meses':
      return `${formatarNumero(valor as string)} meses`;
    case 'horas':
      return `${formatarNumero(valor as string)} h`;
    case 'data':
      return typeof valor === 'string' && /^\d{4}-\d{2}-\d{2}/.test(valor) ? formatarData(valor) : String(valor);
    case 'num':
    case 'nota':
      return casas !== null && casas !== undefined
        ? new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 0, maximumFractionDigits: casas }).format(Number(valor))
        : formatarNumero(valor as string);
    default:
      return typeof valor === 'boolean' ? (valor ? 'Sim' : 'Não') : String(valor);
  }
}

export function eNumerico(formato?: string | null): boolean {
  return !!formato && ['kz', 'moeda', 'valor', 'pct', 'dias', 'meses', 'horas', 'num', 'nota'].includes(formato);
}

/** Procura no menu do utilizador o ecrã que corresponde a uma vista do legado (atalhos e acções dos painéis e fluxos). */
export function useRotaDaVista() {
  const { menu } = useSessao();
  return useCallback(
    (vista?: string | null): string | null => {
      if (!vista) return null;
      for (const m of menu) {
        const e = m.ecras.find((x) => x.id === vista || x.vistas.includes(vista));
        if (e) return `/m/${m.id}/${e.id}`;
      }
      return null;
    },
    [menu],
  );
}

/** Cartão de indicador (KPI): rótulo, valor formatado, subtítulo e, opcionalmente, variação. */
export function CartaoKpi({
  rotulo,
  valor,
  formato,
  subtitulo,
  ajuda,
  variacaoPct,
  leitura,
  alerta,
  aoClicar,
  casas,
}: {
  rotulo: string;
  valor: unknown;
  formato?: string | null;
  subtitulo?: string | null;
  ajuda?: string | null;
  variacaoPct?: number | null;
  leitura?: 'boa' | 'ma' | 'neutra' | string;
  alerta?: boolean;
  aoClicar?: () => void;
  casas?: number | null;
}) {
  const cor = leitura === 'boa' ? '#389e0d' : leitura === 'ma' ? '#cf1322' : 'rgba(0,0,0,0.55)';
  const Icone = variacaoPct === null || variacaoPct === undefined || variacaoPct === 0 ? MinusOutlined : variacaoPct > 0 ? ArrowUpOutlined : ArrowDownOutlined;
  return (
    <Card
      size="small"
      hoverable={!!aoClicar}
      onClick={aoClicar}
      style={{ height: '100%', borderLeft: alerta ? '3px solid #fa8c16' : undefined }}
      styles={{ body: { padding: '12px 16px' } }}
    >
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>
        {rotulo}
        {ajuda && (
          <Tooltip title={ajuda}>
            <InfoCircleOutlined style={{ marginLeft: 6 }} aria-label="Ajuda" />
          </Tooltip>
        )}
      </Typography.Text>
      <div style={{ fontSize: 20, fontWeight: 600, margin: '4px 0 2px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
        {formatarPorFormato(valor, formato, casas)}
        {formato === 'kz' && valor !== null && valor !== undefined && <span style={{ fontSize: 12, fontWeight: 400, marginLeft: 4 }}>Kz</span>}
      </div>
      {variacaoPct !== undefined && (
        <Typography.Text style={{ fontSize: 12, color: cor }}>
          <Icone /> {variacaoPct === null ? 'sem base de comparação' : `${variacaoPct > 0 ? '+' : ''}${formatarNumero(variacaoPct)}%`}
        </Typography.Text>
      )}
      {subtitulo && (
        <div>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            {subtitulo}
          </Typography.Text>
        </div>
      )}
    </Card>
  );
}

export interface ColunaApi {
  id: string;
  rotulo: string;
  formato?: string | null;
}

export interface TabelaApiGestao {
  id: string;
  titulo: string;
  colunas: ColunaApi[];
  linhas: Record<string, unknown>[];
}

/**
 * Tabela genérica vinda da API (Indicadores::tabela): colunas {id, rotulo, formato} e linhas.
 * O formato «valor» lê o formato da própria linha (quadro de comparação da holding).
 */
export function TabelaGestao({ tabela, porPagina = 10, extra }: { tabela: TabelaApiGestao; porPagina?: number; extra?: ReactNode }) {
  return (
    <Card size="small" title={tabela.titulo} extra={extra}>
      <Table<Record<string, unknown>>
        size="small"
        rowKey={(l) => String(l.id ?? l.chave ?? JSON.stringify(l))}
        dataSource={tabela.linhas}
        pagination={tabela.linhas.length > porPagina ? { pageSize: porPagina, size: 'small' } : false}
        scroll={scrollTabela()}
        locale={{ emptyText: 'Sem registos.' }}
        columns={tabela.colunas.map((c) => ({
          title: c.rotulo,
          dataIndex: c.id,
          align: eNumerico(c.formato) || c.formato === 'valor' ? ('right' as const) : undefined,
          render: (v: unknown, l: Record<string, unknown>) => {
            const formato = c.formato === 'valor' ? (l.formato as string) : c.formato;
            const texto = formatarPorFormato(v, formato);
            const negativo = eNumerico(formato) && Number(v) < 0;
            return <span style={{ whiteSpace: 'nowrap', color: negativo ? '#cf1322' : undefined }}>{texto}</span>;
          },
        }))}
      />
    </Card>
  );
}

/** Tabela da API de gestão (todas as linhas, sem paginação) em HTML de impressão. */
export function tabelaGestaoHtml(tabela: TabelaApiGestao): string {
  return tabelaHtml<Record<string, unknown>>({
    legenda: tabela.titulo,
    linhas: tabela.linhas,
    colunas: tabela.colunas.map((c) => ({
      titulo: c.rotulo,
      alinhamento: eNumerico(c.formato) || c.formato === 'valor' ? ('direita' as const) : ('esquerda' as const),
      valor: (l) => formatarPorFormato(l[c.id], c.formato === 'valor' ? (l.formato as string) : c.formato),
    })),
  });
}
