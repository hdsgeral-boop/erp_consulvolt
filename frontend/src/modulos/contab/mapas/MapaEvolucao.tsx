import { Card, Checkbox, Flex, Form, InputNumber, Table } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { NOMES_MESES, type Evolucao } from '../api';
import { BotaoCsv, ValorKz } from '../comum/Componentes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { filtrosDosParametros, periodoDosParametros } from '../comum/impressao';
import { useMapa } from '../comum/useMapa';

type LinhaEvolucao = Evolucao['contas'][number];

/** Mapas › Evolução mensal (ecrã contab_mapa_evolucao): movimento líquido por mês e saldo, por conta (e terceiro). */
export default function MapaEvolucao() {
  const mapa = useMapa<Evolucao>('evolucao', '/contabilidade/relatorios/evolucao');
  const d = mapa.data;
  const meses = d?.meses_ativos?.length ? d.meses_ativos : Array.from({ length: 12 }, (_, i) => i + 1);
  const pequeno = useEcraPequeno();

  const colunas: ColumnsType<LinhaEvolucao> = [
    { title: 'Conta', dataIndex: 'codigo_conta', fixed: pequeno ? undefined : 'left' },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 220 },
    ...meses.map((m) => ({
      title: NOMES_MESES[m - 1],
      key: `m${m}`,
      align: 'right' as const,
      render: (_: unknown, r: LinhaEvolucao) => <ValorKz valor={r.meses[String(m)] ?? '0.00'} discretoSeZero />,
    })),
    { title: 'Saldo', dataIndex: 'saldo', align: 'right', fixed: pequeno ? undefined : 'right', render: (v: string) => <ValorKz valor={v} forte /> },
  ];

  return (
    <>
      <CabecalhoPagina titulo="Evolução mensal" subtitulo="Balancete de evolução por mês" />
      <Card style={{ marginBottom: 16 }}>
        <FiltrosMapa
          modo="ano"
          aCalcular={mapa.isFetching}
          aoCalcular={mapa.calcular}
          valoresIniciais={{ nivel: 2 }}
          extra={
            <>
              <Form.Item name="nivel" label="Nível" style={{ marginBottom: 8 }}>
                <InputNumber min={1} max={20} style={{ width: 90 }} />
              </Form.Item>
              <Form.Item name="por_terceiro" valuePropName="checked" style={{ marginBottom: 8 }}>
                <Checkbox>Por terceiro</Checkbox>
              </Form.Item>
            </>
          }
        />
      </Card>
      {d && (
        <Card
          extra={
            <Flex gap={8} wrap>
            <BotoesExportar
              obterPedido={() => ({
                titulo: `Evolução mensal ${d.ano}`,
                periodo: periodoDosParametros(mapa.parametros),
                filtros: filtrosDosParametros(mapa.parametros),
                orientacao: 'paisagem',
                conteudo: documentoEvolucao(d, meses),
              })}
            />
            <BotaoCsv<LinhaEvolucao>
              nome={`evolucao_${d.ano}`}
              linhas={d.contas}
              colunas={[
                { titulo: 'Conta', valor: (l) => l.codigo_conta },
                { titulo: 'Descrição', valor: (l) => l.descricao },
                ...meses.map((m) => ({ titulo: NOMES_MESES[m - 1], valor: (l: LinhaEvolucao) => l.meses[String(m)] ?? '0.00', numerico: true })),
                { titulo: 'Saldo', valor: (l) => l.saldo, numerico: true },
              ]}
            />
            </Flex>
          }
        >
          <Table<LinhaEvolucao>
            rowKey="codigo_conta"
            size="small"
            columns={colunas}
            dataSource={d.contas}
            pagination={false}
            scroll={scrollTabela()}
            expandable={{
              rowExpandable: (r) => !!r.terceiros?.length,
              expandedRowRender: (r) => (
                <Table
                  scroll={scrollTabela()}
                  rowKey="terceiro_id"
                  size="small"
                  pagination={false}
                  dataSource={r.terceiros}
                  columns={[
                    { title: 'Terceiro', dataIndex: 'terceiro', render: (v: string | null) => v?.trim() ?? '—' },
                    ...meses.map((m) => ({
                      title: NOMES_MESES[m - 1],
                      key: `t${m}`,
                      align: 'right' as const,
                      render: (_: unknown, t: { meses: Record<string, string> }) => <ValorKz valor={t.meses[String(m)] ?? '0.00'} discretoSeZero />,
                    })),
                    { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                  ]}
                />
              ),
            }}
            summary={() => (
              <Table.Summary fixed>
                <Table.Summary.Row>
                  <Table.Summary.Cell index={0} colSpan={3}>
                    <strong>Totais</strong>
                  </Table.Summary.Cell>
                  {meses.map((m, i) => (
                    <Table.Summary.Cell key={m} index={i + 3} align="right">
                      <ValorKz valor={d.totais[String(m)] ?? '0.00'} forte />
                    </Table.Summary.Cell>
                  ))}
                  <Table.Summary.Cell index={meses.length + 3} align="right">
                    <ValorKz valor={d.saldo_total} forte />
                  </Table.Summary.Cell>
                </Table.Summary.Row>
              </Table.Summary>
            )}
          />
        </Card>
      )}
    </>
  );
}

/** Evolução mensal para impressão (contas e, se pedido, os terceiros de cada conta), com os totais por mês. */
export function documentoEvolucao(d: Evolucao, meses: number[]): string {
  type Linha = { conta: string; descricao: string; meses: Record<string, string>; saldo: string; terceiro?: boolean };
  const linhas: Linha[] = d.contas.flatMap((c) => [
    { conta: c.codigo_conta, descricao: c.descricao ?? '', meses: c.meses, saldo: c.saldo },
    ...(c.terceiros ?? []).map((t) => ({ conta: '', descricao: `   · ${t.terceiro?.trim() ?? '—'}`, meses: t.meses, saldo: t.saldo, terceiro: true })),
  ]);
  return tabelaHtml<Linha>({
    linhas,
    totais: 'Totais',
    colunas: [
      { titulo: 'Conta', valor: (l) => l.conta, total: 'Totais' },
      { titulo: 'Descrição', valor: (l) => l.descricao, total: '' },
      ...meses.map((m) => ({ titulo: NOMES_MESES[m - 1], valor: (l: Linha) => l.meses[String(m)] ?? '0.00', formato: 'moeda' as const, total: formatarKz(d.totais[String(m)] ?? '0.00') })),
      { titulo: 'Saldo', valor: (l) => l.saldo, formato: 'moeda', total: formatarKz(d.saldo_total) },
    ],
  });
}
