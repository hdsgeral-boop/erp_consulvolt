import { Flex, Table, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { scrollTabela } from '@/componentes/responsivo';
import { formatarData } from '@/utilitarios/formatacao';
import type { MovimentoExtrato } from '../api';
import { ValorKz } from '../comum/Componentes';
import { extratoPorConta, type ContaDoExtrato } from './arvoreContas';
import { BotoesArvore } from './DetalheConta';

/**
 * Extracto arrumado por conta (vista «Por conta» do Extrato de Conta Corrente): uma linha por conta com o n.º de
 * movimentos, débitos, créditos e o movimento líquido do período, que abre os movimentos dessa conta (cada um liga ao
 * lançamento). Os valores são os do extracto calculado no servidor; aqui só se agrupam.
 */
export function ExtratoPorConta({ movimentos, abrir }: { movimentos: MovimentoExtrato[]; abrir?: (id: number) => void }) {
  const contas = useMemo(() => extratoPorConta(movimentos), [movimentos]);
  const [abertas, setAbertas] = useState<string[]>([]);
  return (
    <>
      <Flex justify="end" style={{ marginBottom: 8 }}>
        <BotoesArvore aoExpandir={() => setAbertas(contas.map((c) => c.chave))} aoRecolher={() => setAbertas([])} desactivado={!contas.length} />
      </Flex>
      <Table<ContaDoExtrato<MovimentoExtrato>>
        rowKey="chave"
        size="small"
        className="erp-mapa-contab"
        dataSource={contas}
        scroll={scrollTabela()}
        pagination={{ pageSize: 50, showTotal: (n) => `${n} conta(s)` }}
        columns={[
          { title: 'Conta', dataIndex: 'codigo_conta', render: (v: string) => <strong>{v}</strong> },
          { title: 'Nome Conta', dataIndex: 'descricao_conta', ellipsis: true, render: (v: string | null) => v ?? '-' },
          { title: 'Movimentos', dataIndex: 'movimentos', align: 'right' },
          { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
          { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
          { title: 'Movimento (D − C)', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} forte /> },
        ]}
        expandable={{
          expandedRowKeys: abertas,
          onExpandedRowsChange: (k) => setAbertas(k.map(String)),
          expandedRowRender: (c) => (
            <Table<MovimentoExtrato>
              rowKey="id"
              size="small"
              pagination={false}
              dataSource={c.filhos}
              scroll={scrollTabela()}
              columns={[
                { title: 'Data', dataIndex: 'data_documento', render: formatarData, width: 100 },
                { title: 'Diário', dataIndex: 'diario', responsive: ['md'] },
                {
                  title: 'Nº Lanç.',
                  dataIndex: 'numero_lan',
                  render: (v: string, r) => (abrir ? <Typography.Link onClick={() => abrir(r.id)} title="Abrir o lançamento">{v || r.numero_documento || r.id}</Typography.Link> : v),
                },
                { title: 'Terceiro', dataIndex: 'terceiro', ellipsis: true, responsive: ['lg'], render: (v: string | null) => v?.trim() ?? '' },
                { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
                { title: 'Débito', key: 'd', align: 'right', render: (_, r) => (r.tipo_dc === 'D' ? <ValorKz valor={r.valor} /> : null) },
                { title: 'Crédito', key: 'c', align: 'right', render: (_, r) => (r.tipo_dc === 'C' ? <ValorKz valor={r.valor} /> : null) },
              ]}
            />
          ),
        }}
      />
    </>
  );
}
