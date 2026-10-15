import { Alert, Table, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';

/** Linha plana de uma demonstração (secção / linha / subtotal / total). */
export interface LinhaDemonstracaoUN {
  chave: string;
  tipo: 'seccao' | 'linha' | 'subtotal' | 'total';
  descricao: string;
  nota?: string | null;
  atual?: string;
}

export interface MapaPorUnidade<T> {
  colunas: { unidade_negocio_id: number | null; rotulo: string; mapa: T }[];
  total: T;
}

interface Linha {
  chave: string;
  tipo: LinhaDemonstracaoUN['tipo'];
  descricao: string;
  nota?: string | null;
  valores: (string | undefined)[];
  total?: string;
}

/**
 * Junta as colunas de um mapa por unidade de negócio numa tabela: a estrutura (secções e linhas) vem do Total e cada
 * coluna é o mesmo mapa calculado só com os lançamentos dessa unidade (legado novo: colunasPorUN, ui_reports.js:387).
 */
export function juntarColunas<T>(dados: MapaPorUnidade<T>, linhas: (m: T) => LinhaDemonstracaoUN[]): Linha[] {
  const porColuna = dados.colunas.map((c) => new Map(linhas(c.mapa).map((l) => [l.chave, l.atual])));
  return linhas(dados.total).map((l) => ({ chave: l.chave, tipo: l.tipo, descricao: l.descricao, nota: l.nota, total: l.atual, valores: porColuna.map((m) => m.get(l.chave)) }));
}

/** Balanço/DR com uma coluna por unidade de negócio, «Sem unidade» e o Total (botão «Por unidade de negócio»). */
export function TabelaPorUnidade<T>({ dados, linhas, titulo, periodo }: { dados: MapaPorUnidade<T>; linhas: (m: T) => LinhaDemonstracaoUN[]; titulo: string; periodo?: string }) {
  if (!dados.colunas.length) {
    return <Alert type="info" showIcon message="Não há lançamentos com unidade de negócio no período: o mapa por unidade não tem colunas." />;
  }
  const filas = juntarColunas(dados, linhas);
  const negrito = (l: Linha) => l.tipo !== 'linha';
  const columns: ColumnsType<Linha> = [
    { title: 'Rubrica', dataIndex: 'descricao', fixed: 'left', width: 320,
      render: (d: string, l) => (l.tipo === 'seccao' ? <Typography.Text strong>{d}</Typography.Text> : <span style={{ fontWeight: negrito(l) ? 600 : 400, paddingLeft: l.tipo === 'linha' ? 12 : 0 }}>{d}</span>) },
    { title: 'Nota', dataIndex: 'nota', width: 60, responsive: ['md'] },
    ...dados.colunas.map((c, i) => ({
      title: c.rotulo, key: `un-${c.unidade_negocio_id ?? 'sem'}`, align: 'right' as const, width: 140,
      render: (_: unknown, l: Linha) => (l.tipo === 'seccao' ? null : <span style={{ fontWeight: negrito(l) ? 600 : 400 }}>{formatarKz(l.valores[i])}</span>),
    })),
    { title: 'Total', key: 'total', align: 'right', width: 150, render: (_, l) => (l.tipo === 'seccao' ? null : <strong>{formatarKz(l.total)}</strong>) },
  ];
  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
        <BotoesExportar obterPedido={() => ({
          titulo: `${titulo} por unidade de negócio`, periodo,
          conteudo: tabelaHtml({ linhas: filas.filter((l) => l.tipo !== 'seccao'), colunas: [
            { titulo: 'Rubrica', valor: (l: Linha) => l.descricao },
            ...dados.colunas.map((c, i) => ({ titulo: c.rotulo, valor: (l: Linha) => formatarKz(l.valores[i]) })),
            { titulo: 'Total', valor: (l: Linha) => formatarKz(l.total) },
          ] }),
        })} />
      </div>
      <Table<Linha> size="small" rowKey="chave" pagination={false} dataSource={filas} columns={columns} scroll={scrollTabela()} />
    </>
  );
}
