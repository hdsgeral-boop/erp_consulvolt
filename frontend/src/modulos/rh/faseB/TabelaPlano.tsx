import { Table } from 'antd';
import { formatarKz } from '@/utilitarios/formatacao';
import { EstadoTag } from '../comum/componentes';
import type { PrestacaoPlano } from './tipos';

/** Plano de amortização de um empréstimo (RH › Empréstimos e Portal › Empréstimos). */
export function TabelaPlano({ plano, comJuro }: { plano: PrestacaoPlano[]; comJuro: boolean }) {
  return (
    <Table<PrestacaoPlano> size="small" rowKey="numero" pagination={false} dataSource={plano} scroll={{ x: true }} columns={[
      { title: '#', dataIndex: 'numero', width: 50 },
      { title: 'Mês', dataIndex: 'mes_ano', render: (v: string) => `${v.slice(5)}/${v.slice(0, 4)}` },
      { title: 'Capital', dataIndex: 'capital', align: 'right', render: (v: string) => formatarKz(v) },
      ...(comJuro ? [{ title: 'Juro', dataIndex: 'juro', align: 'right' as const, render: (v: string) => formatarKz(v) }] : []),
      { title: 'Prestação', dataIndex: 'total', align: 'right', render: (v: string) => <strong>{formatarKz(v)}</strong> },
      { title: 'Em dívida', dataIndex: 'saldo', align: 'right', render: (v: string) => formatarKz(v) },
      { title: 'Estado', dataIndex: 'estado', render: (v?: string) => (v ? <EstadoTag estado={v} /> : '—') },
    ]} />
  );
}
