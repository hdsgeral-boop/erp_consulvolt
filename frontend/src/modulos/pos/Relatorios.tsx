import { Button, Card, Col, DatePicker, Empty, Flex, Row, Skeleton, Statistic, Table, Tabs } from 'antd';
import { DownloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { descarregarCsv, gerarCsv } from '@/modulos/contab/comum/csv';
import { DetalheSessao, ValorDesvio } from './comum/DetalheSessao';
import { EstadoPOS } from './comum/estados';
import { SeletorTerminal } from './comum/Filtros';
import type { PainelRelatorios } from './comum/tipos';

type Z = PainelRelatorios['zs'][number];

/**
 * POS › Relatórios (ecrã pos_relatorios): KPIs, totais por meio, fechos Z, diferenças de TPA e vendas por produto,
 * terminal e operador, com filtros por período e terminal (GET /pos/relatorios).
 */
export default function Relatorios() {
  const [periodo, setPeriodo] = useState<[Dayjs, Dayjs]>([dayjs().startOf('month'), dayjs()]);
  const [terminal, setTerminal] = useState<number>();
  const [detalhe, setDetalhe] = useState<number | null>(null);
  const filtros = { data_inicio: dataApi(periodo[0]), data_fim: dataApi(periodo[1]), terminal_pos_id: terminal };
  const consulta = useQuery({ queryKey: ['pos', 'relatorios', filtros], queryFn: () => obter<PainelRelatorios>('/pos/relatorios', filtros) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar os relatórios do POS');
  }, [consulta.error]);
  const r = consulta.data;

  const exportarZ = () =>
    r &&
    descarregarCsv(
      `pos_fechos_z_${filtros.data_inicio}_${filtros.data_fim}`,
      gerarCsv<Z>(
        [
          { titulo: 'Z', valor: (z) => z.numero_z },
          { titulo: 'Terminal', valor: (z) => z.codigo_terminal },
          { titulo: 'Operador', valor: (z) => z.nome_operador },
          { titulo: 'Abertura', valor: (z) => formatarDataHora(z.aberto_em) },
          { titulo: 'Fecho', valor: (z) => formatarDataHora(z.fechado_em) },
          { titulo: 'Vendas', valor: (z) => z.numero_vendas, numerico: true },
          { titulo: 'Total', valor: (z) => z.total_vendas, numerico: true },
          { titulo: 'Esperado', valor: (z) => z.numerario_esperado, numerico: true },
          { titulo: 'Contado', valor: (z) => z.numerario_contado, numerico: true },
          { titulo: 'Desvio', valor: (z) => z.desvio, numerico: true },
          { titulo: 'Estado do desvio', valor: (z) => z.estado_desvio },
          { titulo: 'Integração', valor: (z) => z.estado_contabilizacao },
          { titulo: 'Prestação', valor: (z) => z.estado_liquidacao },
        ],
        r.zs,
      ),
    );

  const exportarProdutos = () =>
    r &&
    descarregarCsv(
      `pos_vendas_produto_${filtros.data_inicio}_${filtros.data_fim}`,
      gerarCsv(
        [
          { titulo: 'Código', valor: (p: PainelRelatorios['por_produto'][number]) => p.codigo },
          { titulo: 'Produto', valor: (p) => p.nome },
          { titulo: 'Quantidade', valor: (p) => p.quantidade, numerico: true },
          { titulo: 'Base', valor: (p) => p.total_liquido, numerico: true },
          { titulo: 'Total', valor: (p) => p.total_bruto, numerico: true },
        ],
        r.por_produto,
      ),
    );

  return (
    <>
      <CabecalhoPagina
        titulo="Relatórios POS"
        accoes={
          <>
            <DatePicker.RangePicker format="DD/MM/YYYY" allowClear={false} value={periodo} onChange={(v) => v?.[0] && v[1] && setPeriodo([v[0], v[1]])} />
            <SeletorTerminal value={terminal} onChange={setTerminal} />
          </>
        }
      />
      {!r ? (
        <Skeleton active />
      ) : (
        <>
          <Row gutter={[12, 12]} style={{ marginBottom: 16 }}>
            <Kpi titulo="Facturação" valor={`${formatarKz(r.kpis.facturacao)} Kz`} />
            <Kpi titulo="Documentos" valor={r.kpis.documentos} />
            <Kpi titulo="Ticket médio" valor={`${formatarKz(r.kpis.ticket_medio)} Kz`} />
            <Kpi titulo="Desvios (soma)" valor={`${formatarKz(r.kpis.desvios)} Kz`} cor={Number(r.kpis.desvios) < 0 ? '#cf1322' : undefined} />
            <Kpi titulo="Sessões abertas" valor={r.kpis.sessoes_abertas} />
            <Kpi titulo="Desvios por deliberar" valor={r.kpis.desvios_por_deliberar} cor={r.kpis.desvios_por_deliberar ? '#d46b08' : undefined} />
            <Kpi titulo="Sessões por integrar" valor={r.kpis.sessoes_por_integrar} cor={r.kpis.sessoes_por_integrar ? '#d46b08' : undefined} />
            <Kpi titulo="Sessões por prestar" valor={r.kpis.sessoes_por_prestar} cor={r.kpis.sessoes_por_prestar ? '#d46b08' : undefined} />
          </Row>
          <Tabs
            items={[
              {
                key: 'z',
                label: `Fechos Z (${r.zs.length})`,
                children: (
                  <>
                    <Flex justify="end" style={{ marginBottom: 8 }}>
                      <Button icon={<DownloadOutlined />} disabled={!r.zs.length} onClick={exportarZ}>
                        CSV
                      </Button>
                    </Flex>
                    <Table<Z>
                      size="small"
                      rowKey="id"
                      dataSource={r.zs}
                      scroll={{ x: 'max-content' }}
                      onRow={(z) => ({ onClick: () => setDetalhe(z.id), style: { cursor: 'pointer' } })}
                      columns={[
                        { title: 'Z', dataIndex: 'numero_z' },
                        { title: 'Terminal', dataIndex: 'codigo_terminal' },
                        { title: 'Operador', dataIndex: 'nome_operador' },
                        { title: 'Fecho', dataIndex: 'fechado_em', render: (v) => formatarDataHora(v) },
                        { title: 'Vendas', dataIndex: 'numero_vendas', align: 'right' },
                        { title: 'Total', dataIndex: 'total_vendas', align: 'right', render: (v) => formatarKz(v) },
                        { title: 'Esperado', dataIndex: 'numerario_esperado', align: 'right', render: (v) => formatarKz(v) },
                        { title: 'Contado', dataIndex: 'numerario_contado', align: 'right', render: (v) => formatarKz(v) },
                        { title: 'Desvio', dataIndex: 'desvio', align: 'right', render: (v) => <ValorDesvio valor={v} /> },
                        { title: 'Desvio', dataIndex: 'estado_desvio', render: (v) => <EstadoPOS estado={v} /> },
                        { title: 'Integração', dataIndex: 'estado_contabilizacao', render: (v) => <EstadoPOS estado={v} /> },
                        { title: 'Prestação', dataIndex: 'estado_liquidacao', render: (v) => <EstadoPOS estado={v} /> },
                      ]}
                    />
                  </>
                ),
              },
              {
                key: 'meios',
                label: 'Por meio de pagamento',
                children: (
                  <Table
                    size="small"
                    pagination={false}
                    rowKey={(m) => `${m.tipo}-${m.nome}`}
                    dataSource={r.por_meio}
                    columns={[
                      { title: 'Meio', dataIndex: 'nome' },
                      { title: 'Natureza', dataIndex: 'tipo', render: (v) => (v ? <EstadoPOS estado={v} /> : 'Sem detalhe') },
                      { title: 'Operações', dataIndex: 'quantidade', align: 'right' },
                      { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
                    ]}
                  />
                ),
              },
              {
                key: 'produtos',
                label: 'Por produto',
                children: (
                  <>
                    <Flex justify="end" style={{ marginBottom: 8 }}>
                      <Button icon={<DownloadOutlined />} disabled={!r.por_produto.length} onClick={exportarProdutos}>
                        CSV
                      </Button>
                    </Flex>
                    <Table
                      size="small"
                      rowKey="produto_id"
                      dataSource={r.por_produto}
                      columns={[
                        { title: 'Código', dataIndex: 'codigo' },
                        { title: 'Produto', dataIndex: 'nome' },
                        { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (v) => formatarNumero(v) },
                        { title: 'Base', dataIndex: 'total_liquido', align: 'right', render: (v) => formatarKz(v) },
                        { title: 'Total', dataIndex: 'total_bruto', align: 'right', render: (v) => formatarKz(v) },
                      ]}
                    />
                  </>
                ),
              },
              {
                key: 'terminais',
                label: 'Por terminal e operador',
                children: (
                  <Row gutter={16}>
                    <Col xs={24} lg={12}>
                      <Card size="small" title="Por terminal">
                        <Table
                          size="small"
                          pagination={false}
                          rowKey="terminal_pos_id"
                          dataSource={r.por_terminal}
                          columns={[
                            { title: 'Terminal', render: (_, t) => `${t.codigo_terminal} — ${t.nome_terminal}` },
                            { title: 'Documentos', dataIndex: 'documentos', align: 'right' },
                            { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) },
                          ]}
                        />
                      </Card>
                    </Col>
                    <Col xs={24} lg={12}>
                      <Card size="small" title="Por operador">
                        <Table
                          size="small"
                          pagination={false}
                          rowKey={(o) => o.operador ?? '—'}
                          dataSource={r.por_operador}
                          columns={[
                            { title: 'Operador', dataIndex: 'operador', render: (v) => v ?? '—' },
                            { title: 'Documentos', dataIndex: 'documentos', align: 'right' },
                            { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) },
                          ]}
                        />
                      </Card>
                    </Col>
                  </Row>
                ),
              },
              {
                key: 'tpa',
                label: `Diferenças TPA (${r.diferencas_tpa.length})`,
                children: r.diferencas_tpa.length ? (
                  <Table
                    size="small"
                    pagination={false}
                    rowKey={(d) => `${d.sessao_pos_id}-${d.meio_id}`}
                    dataSource={r.diferencas_tpa}
                    columns={[
                      { title: 'Z', dataIndex: 'numero_z' },
                      { title: 'TPA', render: (_, d) => `${d.nome ?? '—'}${d.codigo_tpa ? ` (${d.codigo_tpa})` : ''}` },
                      { title: 'Sistema', dataIndex: 'valor_sistema', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'Talão', dataIndex: 'valor_talao', align: 'right', render: (v) => formatarKz(v) },
                      { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (v) => <ValorDesvio valor={v} /> },
                      { title: 'Lote', dataIndex: 'referencia_lote', render: (v) => v ?? '—' },
                    ]}
                  />
                ) : (
                  <Empty description="Sem diferenças entre os talões TPA e o sistema." />
                ),
              },
            ]}
          />
        </>
      )}
      <DetalheSessao id={detalhe} aoFechar={() => setDetalhe(null)} />
    </>
  );
}

function Kpi({ titulo, valor, cor }: { titulo: string; valor: string | number; cor?: string }) {
  return (
    <Col xs={12} md={6} xl={3}>
      <Card size="small">
        <Statistic title={titulo} value={valor} valueStyle={{ fontSize: 18, color: cor }} />
      </Card>
    </Col>
  );
}
