import { Alert, DatePicker, Descriptions, Drawer, Flex, Table, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { NomeArmazem } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem } from '@/modulos/compras/comum/Seletores';
import { TIPOS_MOVIMENTO, type Extracto } from './tipos';

/** Extracto do artigo (GET /logistica/produtos/{id}/extracto): saldo inicial, movimentos com saldo corrido e saldo final. */
export function ExtractoArtigo({ produtoId, armazemInicial, aoFechar }: { produtoId: number | null; armazemInicial?: number | null; aoFechar: () => void }) {
  const [periodo, setPeriodo] = useState<[Dayjs, Dayjs]>([dayjs().startOf('year'), dayjs()]);
  const [armazem, setArmazem] = useState<number | undefined>(armazemInicial ?? undefined);
  const consulta = useQuery({
    queryKey: ['logistica', 'extracto', produtoId, armazem, dataApi(periodo[0]), dataApi(periodo[1])],
    queryFn: () => obter<Extracto>(`/logistica/produtos/${produtoId}/extracto`, { armazem_id: armazem, de: dataApi(periodo[0]), ate: dataApi(periodo[1]) }),
    enabled: produtoId !== null,
  });
  const e = consulta.data;

  return (
    <Drawer title={e ? `Extracto — ${e.produto.codigo ? `${e.produto.codigo} — ` : ''}${e.produto.nome}` : 'Extracto do artigo'} open={produtoId !== null} onClose={aoFechar} width={980} loading={consulta.isLoading}>
      <Flex gap={8} wrap style={{ marginBottom: 16 }}>
        <DatePicker.RangePicker format="DD/MM/YYYY" allowClear={false} value={periodo} onChange={(v) => v?.[0] && v?.[1] && setPeriodo([v[0], v[1]])} />
        <SeletorArmazem allowClear placeholder="Todos os armazéns" style={{ width: 240 }} value={armazem} onChange={setArmazem} />
      </Flex>
      {consulta.error && <Alert type="error" showIcon message="Não foi possível obter o extracto." />}
      {e && (
        <>
          <Descriptions size="small" bordered column={{ xs: 1, md: 2 }} style={{ marginBottom: 16 }}>
            <Descriptions.Item label="Saldo inicial">{formatarNumero(e.saldo_inicial.quantidade)} · {formatarKz(e.saldo_inicial.valor)} Kz</Descriptions.Item>
            <Descriptions.Item label="Saldo final">{formatarNumero(e.saldo_final.quantidade)} · {formatarKz(e.saldo_final.valor)} Kz</Descriptions.Item>
            <Descriptions.Item label="Custo médio actual">{formatarKz(e.produto.custo_medio)} Kz</Descriptions.Item>
            <Descriptions.Item label="Stock actual (todos os armazéns)">{formatarNumero(e.produto.quantidade_stock)}</Descriptions.Item>
          </Descriptions>
          <Table
            rowKey="id"
            size="small"
            scroll={{ x: 'max-content' }}
            pagination={{ defaultPageSize: 50, showSizeChanger: true }}
            dataSource={e.movimentos}
            columns={[
              { title: 'Data', dataIndex: 'data', render: formatarData },
              { title: 'Tipo', dataIndex: 'tipo', render: (t: string) => <Tag>{TIPOS_MOVIMENTO[t] ?? t}</Tag> },
              { title: 'Armazém', dataIndex: 'armazem_id', render: (v: number) => <NomeArmazem id={v} /> },
              { title: 'Referência', dataIndex: 'referencia', ellipsis: true },
              { title: 'Entrada', dataIndex: 'entrada', align: 'right', render: (v: string | null) => (v ? formatarNumero(v) : '') },
              { title: 'Saída', dataIndex: 'saida', align: 'right', render: (v: string | null) => (v ? formatarNumero(v) : '') },
              { title: 'Custo unit.', dataIndex: 'custo_unitario', align: 'right', render: (v: string | null) => formatarKz(v) },
              { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string | null) => formatarKz(v) },
              { title: 'Saldo qtd.', dataIndex: 'saldo_quantidade', align: 'right', render: formatarNumero },
              { title: 'Saldo valor', dataIndex: 'saldo_valor', align: 'right', render: (v: string) => formatarKz(v) },
            ]}
          />
        </>
      )}
    </Drawer>
  );
}
