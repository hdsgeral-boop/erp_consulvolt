import { Button, Descriptions, Flex, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { CalculatorOutlined, FileTextOutlined, WarningOutlined } from '@ant-design/icons';
import { useState } from 'react';
import type { ColumnsType } from 'antd/es/table';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { DetalhePeriodo, ResultadoSalarial } from '../api';
import { useCargos, useColaboradores } from './consultas';
import { ModalReciboColaborador, ModalSimulacaoColaborador } from './SimulacaoColaborador';
import { totaisResultados } from './regras';

/**
 * Resultados do período por colaborador (fotografia ou cálculo ao vivo), com rubricas e avisos no detalhe da linha.
 * Por linha: «Simular» (simulação salarial do colaborador, calculada no servidor — nada é gravado) e, nos períodos
 * validados, «Recibo» (recibo individual em 2 vias na mesma folha).
 */
export function TabelaResultados({ periodo, carregando, recibos }: { periodo: DetalhePeriodo | undefined; carregando?: boolean; recibos?: boolean }) {
  const colaboradores = useColaboradores();
  const cargos = useCargos();
  const resultados = periodo?.resultados ?? [];
  const totais = periodo?.totais ?? totaisResultados(resultados);
  const nome = (r: ResultadoSalarial) => r.nome ?? colaboradores.nome(r.colaborador_id);
  const [simular, setSimular] = useState<ResultadoSalarial | null>(null);
  const [recibo, setRecibo] = useState<ResultadoSalarial | null>(null);
  const comRecibo = recibos && periodo?.estado === 'VALIDADO';
  const dadosRecibo = (r: ResultadoSalarial) => {
    const c = colaboradores.mapa.get(r.colaborador_id);
    return { nome: nome(r), nif: r.nif ?? c?.nif ?? null, numero_inss: r.numero_inss ?? c?.numero_inss ?? null, funcao: c?.cargo_funcao_id ? cargos.nome(c.cargo_funcao_id) : r.funcao ?? null };
  };

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
    {
      title: '',
      key: 'accoes',
      align: 'right',
      render: (_, r) => (
        <Space size={4} wrap={false}>
          <Button size="small" icon={<CalculatorOutlined />} onClick={() => setSimular(r)} aria-label={`Simular ${nome(r)}`}>{periodo?.estado === 'VALIDADO' ? 'Detalhe' : 'Simular'}</Button>
          {comRecibo && <Button size="small" icon={<FileTextOutlined />} onClick={() => setRecibo(r)} aria-label={`Recibo de ${nome(r)}`}>Recibo</Button>}
        </Space>
      ),
    },
  ];

  return (
    <>
    <ModalSimulacaoColaborador resultado={simular} mesAno={periodo?.mes_ano ?? ''} nome={simular ? nome(simular) : ''} simulacao={periodo?.estado !== 'VALIDADO'} aoFechar={() => setSimular(null)} />
    <ModalReciboColaborador resultado={recibo} mesAno={periodo?.mes_ano ?? ''} colaborador={recibo ? dadosRecibo(recibo) : { nome: '' }} aoFechar={() => setRecibo(null)} />
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
              style={{ flex: '1 1 320px', minWidth: 0 }}
              scroll={{ x: 'max-content' }}
              dataSource={r.rubricas ?? []}
              columns={[
                { title: 'Rubrica', dataIndex: 'nome' },
                { title: 'Tipo', dataIndex: 'tipo', render: (t: string, x) => (x.informativa || t === 'OUTROS' ? <Tag>Informativa</Tag> : <Tag color={t === 'VENCIMENTO' ? 'green' : 'red'}>{t === 'VENCIMENTO' ? 'Vencimento' : 'Desconto'}</Tag>) },
                { title: 'Horas', dataIndex: 'horas', align: 'right', render: (v?: string) => (v ? formatarNumero(v) : '') },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v) },
              ]}
            />
            <Descriptions size="small" column={1} bordered style={{ flex: '1 1 260px', minWidth: 0 }}>
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
    </>
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
    <Descriptions size="small" bordered column={{ xs: 1, sm: 2, lg: 3, xxl: 6 }} style={{ marginBottom: 16 }} labelStyle={{ whiteSpace: 'nowrap' }}
      contentStyle={{ whiteSpace: 'nowrap', textAlign: 'right', fontWeight: 600 }}>
      {itens.map(([r, v]) => <Descriptions.Item key={r} label={r}>{formatarKz(v)}</Descriptions.Item>)}
    </Descriptions>
  );
}
