import { Alert, Button, Card, Progress, Skeleton, Table, Tag, Typography } from 'antd';
import { ArrowLeftOutlined, TrophyTwoTone } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { EstadoTag } from '../comum/estados';
import { NomeProduto } from '../comum/referencias';
import { numeroOuId } from '../comum/tipos';

export interface PropostaComparada {
  id: number;
  numero_proposta: string | null;
  referencia: string;
  fornecedor: string | null;
  estado: string;
  montante_total: string | null;
  total_com_imposto: string | null;
  codigo_moeda: string;
  data_entrega: string | null;
  pontuacao: { preco: number; prazo: number; total: number };
}

export interface Comparacao {
  propostas: PropostaComparada[];
  matriz: { item_pedido_id: number; produto_id: number; descricao: string | null; quantidade: string; precos: Record<string, string | null> }[];
}

/** Por artigo, a proposta com o menor preço unitário cotado (ignora não cotados). */
export function melhorPrecoPorLinha(precos: Record<string, string | null>): string | null {
  let melhor: string | null = null;
  let valor = Infinity;
  for (const [id, p] of Object.entries(precos)) {
    if (p === null || p === undefined) continue;
    const n = Number(p);
    if (n > 0 && n < valor) {
      valor = n;
      melhor = id;
    }
  }
  return melhor;
}

/** Quadro de avaliação das propostas de um pedido (GET /compras/pedidos/{id}/comparacao): pontuação preço (70) + prazo (30). */
export function QuadroComparativo() {
  const { pedidoId } = useParams();
  const navegar = useNavigate();
  const consulta = useQuery({ queryKey: ['compras', 'comparacao', pedidoId], queryFn: () => obter<Comparacao>(`/compras/pedidos/${pedidoId}/comparacao`) });

  if (consulta.isLoading) return <Skeleton active />;
  const d = consulta.data;
  if (!d) return <Alert type="error" message="Não foi possível obter a comparação." />;
  const melhor = d.propostas[0]?.id;

  return (
    <>
      <CabecalhoPagina titulo={`Quadro comparativo — pedido #${pedidoId}`} subtitulo="Pontuação: preço (até 70) + prazo de entrega (até 30)" accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
      {d.propostas.length === 0 ? (
        <Alert type="info" showIcon message="Ainda não há propostas para este pedido." />
      ) : (
        <>
          <Card title="Classificação" style={{ marginBottom: 16 }}>
            <Table<PropostaComparada>
              rowKey="id"
              size="small"
              pagination={false}
              scroll={{ x: 'max-content' }}
              dataSource={d.propostas}
              onRow={(r) => ({ onClick: () => navegar(`../${r.id}`), style: { cursor: 'pointer' } })}
              columns={[
                { title: '', key: 'melhor', width: 32, render: (_, r) => (r.id === melhor ? <TrophyTwoTone twoToneColor="#faad14" /> : null) },
                { title: 'Proposta', render: (_, r) => <strong>{numeroOuId(r.numero_proposta, r.id)}</strong> },
                { title: 'Referência', dataIndex: 'referencia' },
                { title: 'Fornecedor', dataIndex: 'fornecedor', render: (v) => v?.trim() || '—' },
                { title: 'Total (Kz)', dataIndex: 'montante_total', align: 'right', render: (v: string | null) => formatarKz(v) },
                { title: 'Moeda', dataIndex: 'codigo_moeda' },
                { title: 'Entrega', dataIndex: 'data_entrega', render: formatarData },
                { title: 'Preço', key: 'preco', align: 'right', render: (_, r) => formatarNumero(r.pontuacao.preco) },
                { title: 'Prazo', key: 'prazo', align: 'right', render: (_, r) => formatarNumero(r.pontuacao.prazo) },
                { title: 'Pontuação', key: 'total', width: 180, render: (_, r) => <Progress percent={r.pontuacao.total} size="small" format={(p) => formatarNumero(p ?? 0)} /> },
                { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
              ]}
            />
          </Card>
          <Card title="Preços unitários por artigo (Kz)">
            <Table
              rowKey="item_pedido_id"
              size="small"
              pagination={false}
              scroll={{ x: 'max-content' }}
              dataSource={d.matriz}
              columns={[
                { title: 'Artigo', fixed: 'left', render: (_, l) => <NomeProduto id={l.produto_id} descricao={l.descricao} /> },
                { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
                ...d.propostas.map((p) => ({
                  title: p.referencia,
                  key: String(p.id),
                  align: 'right' as const,
                  render: (_: unknown, l: Comparacao['matriz'][number]) => {
                    const v = l.precos[String(p.id)];
                    if (v === null || v === undefined) return <Typography.Text type="secondary">não cotado</Typography.Text>;
                    return melhorPrecoPorLinha(l.precos) === String(p.id) ? <Tag color="green">{formatarKz(v)}</Tag> : formatarKz(v);
                  },
                })),
              ]}
            />
          </Card>
        </>
      )}
    </>
  );
}
