import { Descriptions, Flex, Table, Tag, Tooltip, Typography } from 'antd';
import { WarningOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { DetalhePeriodo, ResultadoSalarial } from '../api';
import { useColaboradores } from './consultas';
import { totaisResultados } from './regras';

/** Resultados do período por colaborador (fotografia ou cálculo ao vivo), com rubricas e avisos no detalhe da linha. */
export function TabelaResultados({ periodo, carregando }: { periodo: DetalhePeriodo | undefined; carregando?: boolean }) {
  const colaboradores = useColaboradores();
  const resultados = periodo?.resultados ?? [];
  const totais = periodo?.totais ?? totaisResultados(resultados);
  const nome = (r: ResultadoSalarial) => r.nome ?? colaboradores.nome(r.colaborador_id);

  const colunas: ColumnsType<ResultadoSalarial> = [
    {
      title: 'Colaborador',
      fixed: 'left',
      sorter: (a, b) => nome(a).localeCompare(nome(b), 'pt'),
      render: (_, r) => (
        <Flex gap={6} align="center">
          <strong>{nome(r)}</strong>
          {r.avencado && <Tag color="purple">Avençado</Tag>}
          {r.reformado && <Tag>Reformado</Tag>}
          {r.avisos?.length > 0 && <Tooltip title={r.avisos.join(' ')}><WarningOutlined style={{ color: '#faad14' }} /></Tooltip>}
        </Flex>
      ),
    },
    { title: 'NIF', dataIndex: 'nif', render: (v: string | null | undefined) => v ?? '—' },
    { title: 'Dias', align: 'center', render: (_, r) => `${formatarNumero(r.dias_trabalhados)}/${formatarNumero(r.dias_contrato)}` },
    { title: 'Bruto', dataIndex: 'bruto', align: 'right', render: (v: string) => formatarKz(v), sorter: (a, b) => Number(a.bruto) - Number(b.bruto) },
    { title: 'INSS (trab.)', dataIndex: 'inss_trabalhador', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'INSS (empresa)', dataIndex: 'inss_patronal', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'IRT', dataIndex: 'irt', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'Descontos', dataIndex: 'descontos', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'Líquido', dataIndex: 'liquido', align: 'right', render: (v: string) => <strong>{formatarKz(v)}</strong>, sorter: (a, b) => Number(a.liquido) - Number(b.liquido) },
  ];

  return (
    <Table<ResultadoSalarial>
      rowKey="colaborador_id"
      size="small"
      loading={carregando}
      columns={colunas}
      dataSource={resultados}
      scroll={{ x: 'max-content' }}
      pagination={{ pageSize: 50, showSizeChanger: true, showTotal: (t) => `${t} colaborador(es)` }}
      expandable={{
        expandedRowRender: (r) => (
          <Flex gap={24} wrap>
            <Table
              size="small"
              rowKey={(x, i) => `${x.infotipo_id}-${i}`}
              pagination={false}
              style={{ minWidth: 420 }}
              dataSource={r.rubricas ?? []}
              columns={[
                { title: 'Rubrica', dataIndex: 'nome' },
                { title: 'Tipo', dataIndex: 'tipo', render: (t: string, x) => (x.informativa || t === 'OUTROS' ? <Tag>Informativa</Tag> : <Tag color={t === 'VENCIMENTO' ? 'green' : 'red'}>{t === 'VENCIMENTO' ? 'Vencimento' : 'Desconto'}</Tag>) },
                { title: 'Horas', dataIndex: 'horas', align: 'right', render: (v?: string) => (v ? formatarNumero(v) : '') },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v) },
              ]}
            />
            <Descriptions size="small" column={1} bordered style={{ minWidth: 300 }}>
              <Descriptions.Item label="Base INSS">{formatarKz(r.base_inss)}</Descriptions.Item>
              <Descriptions.Item label="Isenções">{formatarKz(r.isencoes)}</Descriptions.Item>
              <Descriptions.Item label="Matéria colectável IRT">{formatarKz(r.base_irt)}</Descriptions.Item>
              <Descriptions.Item label="Modo de cálculo">{r.modo_calculo === 'LEGADO' ? 'Legado (período migrado)' : 'Actual'}</Descriptions.Item>
            </Descriptions>
            {r.avisos?.length > 0 && (
              <div style={{ maxWidth: 420 }}>
                <Typography.Text type="warning" strong>Avisos</Typography.Text>
                <ul style={{ paddingLeft: 18, margin: 0 }}>{r.avisos.map((a, i) => <li key={i}>{a}</li>)}</ul>
              </div>
            )}
          </Flex>
        ),
      }}
      summary={() =>
        resultados.length > 0 ? (
          <Table.Summary fixed>
            <Table.Summary.Row style={{ fontWeight: 600 }}>
              <Table.Summary.Cell index={0} colSpan={3}>Totais</Table.Summary.Cell>
              <Table.Summary.Cell index={3} align="right">{formatarKz(totais.bruto)}</Table.Summary.Cell>
              <Table.Summary.Cell index={4} align="right">{formatarKz(totais.inss_trabalhador)}</Table.Summary.Cell>
              <Table.Summary.Cell index={5} align="right">{formatarKz(totais.inss_patronal)}</Table.Summary.Cell>
              <Table.Summary.Cell index={6} align="right">{formatarKz(totais.irt)}</Table.Summary.Cell>
              <Table.Summary.Cell index={7} align="right">{formatarKz(totais.descontos)}</Table.Summary.Cell>
              <Table.Summary.Cell index={8} align="right">{formatarKz(totais.liquido)}</Table.Summary.Cell>
            </Table.Summary.Row>
          </Table.Summary>
        ) : null
      }
    />
  );
}

/** Totais do período em cartões (bruto, encargos e líquido). */
export function ResumoTotais({ periodo }: { periodo: DetalhePeriodo | undefined }) {
  const t = periodo?.totais;
  if (!t) return null;
  const itens: [string, string][] = [
    ['Bruto', t.bruto], ['INSS trabalhador', t.inss_trabalhador], ['INSS empresa', t.inss_patronal], ['IRT', t.irt], ['Outros descontos', t.descontos], ['Líquido a pagar', t.liquido],
  ];
  return (
    <Descriptions size="small" bordered column={{ xs: 1, sm: 2, md: 3, xl: 6 }} style={{ marginBottom: 16 }}>
      {itens.map(([r, v]) => <Descriptions.Item key={r} label={r}>{formatarKz(v)}</Descriptions.Item>)}
    </Descriptions>
  );
}
