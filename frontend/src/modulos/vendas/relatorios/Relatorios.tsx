import { Alert, Button, Card, Col, DatePicker, Empty, Flex, Progress, Row, Skeleton, Statistic, Table, Typography, message } from 'antd';
import { DownloadOutlined, ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
import { descarregar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { barrasMensais, type ResumoVendas, type ValidacaoSaft } from './kpis';

/** Vendas › Relatórios de vendas (ecrã vendas_relatorios): indicadores do período (calculados no servidor) e exportação SAF-T(AO). */
export default function Relatorios() {
  const { pode } = useSessao();
  const [periodo, setPeriodo] = useState<[Dayjs, Dayjs]>([dayjs().startOf('year'), dayjs()]);
  const [aExportar, setAExportar] = useState(false);
  const inicio = dataApi(periodo[0]) as string;
  const fim = dataApi(periodo[1]) as string;
  const podeResumo = pode('vendas_relatorios_view');

  const consulta = useQuery({
    queryKey: ['vendas', 'relatorio', 'resumo', inicio, fim],
    queryFn: () => obter<ResumoVendas>('/vendas/relatorios/resumo', { inicio, fim }),
    enabled: podeResumo,
    staleTime: 120_000,
  });
  const resumo = consulta.data;
  const barras = useMemo(() => barrasMensais(resumo?.por_mes ?? []), [resumo]);

  /** Valida primeiro (JSON: erros e avisos legíveis) e só depois descarrega o ficheiro. */
  const exportarSaft = async () => {
    setAExportar(true);
    try {
      const v = await obter<ValidacaoSaft>('/vendas/saft/validar', { inicio, fim });
      const d = await descarregar('/vendas/saft', { inicio, fim }, v.nome || `SAFT_AO_${inicio}_${fim}.xml`);
      message.success(`SAF-T gerado: ${d.cabecalhos['x-saft-documentos'] ?? v.documentos} documento(s), ${d.cabecalhos['x-saft-recibos'] ?? v.recibos} recibo(s).`);
      const avisos = d.cabecalhos['x-saft-avisos'] ? decodeURIComponent(d.cabecalhos['x-saft-avisos']) : v.avisos.join(' | ');
      if (avisos) message.warning(`Avisos: ${avisos}`, 10);
    } catch (e) {
      notificarErro(e, 'Não foi possível gerar o SAF-T');
    } finally {
      setAExportar(false);
    }
  };

  return (
    <>
      <CabecalhoPagina
        titulo="Relatórios de vendas"
        subtitulo="Indicadores de facturação do período (facturas e facturas-recibo menos notas de crédito)"
        accoes={
          <>
            <DatePicker.RangePicker format="DD/MM/YYYY" allowClear={false} value={periodo} onChange={(v) => v?.[0] && v?.[1] && setPeriodo([v[0], v[1]])} />
            {podeResumo && <Button icon={<ReloadOutlined />} loading={consulta.isFetching} onClick={() => void consulta.refetch()}>Actualizar</Button>}
            {pode('vendas_relatorios_view', 'vendas_fe_config') && (
              <Button type="primary" icon={<DownloadOutlined />} loading={aExportar} onClick={() => void exportarSaft()}>
                Exportar SAF-T (AO)
              </Button>
            )}
          </>
        }
      />
      {!podeResumo ? (
        <Alert type="info" showIcon message="Os indicadores exigem a consulta dos relatórios de vendas. A exportação SAF-T está disponível." />
      ) : consulta.isLoading ? (
        <Skeleton active />
      ) : consulta.error || !resumo ? (
        <Alert type="error" showIcon message="Não foi possível calcular os indicadores." description={consulta.error?.message} />
      ) : (
        <>
          <Row gutter={16} style={{ marginBottom: 16 }}>
            <Col xs={12} md={6}><Card><Statistic title="Vendas líquidas (Kz)" value={formatarKz(resumo.liquido)} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="IVA liquidado (Kz)" value={formatarKz(resumo.imposto)} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="A receber (Kz)" value={formatarKz(resumo.a_receber)} valueStyle={{ color: '#d48806' }} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="Notas de crédito (Kz)" value={formatarKz(resumo.notas_credito)} valueStyle={{ color: '#cf1322' }} /></Card></Col>
          </Row>
          <Row gutter={16}>
            <Col xs={24} lg={12}>
              <Card title="Facturação mensal (com IVA)" style={{ marginBottom: 16 }}>
                {barras.length === 0 ? (
                  <Empty description="Sem documentos no período." />
                ) : (
                  barras.map((m) => (
                    <Flex key={m.mes} align="center" gap={12} style={{ marginBottom: 6 }}>
                      <span style={{ width: 70 }}>{dayjs(`${m.mes}-01`).format('MM/YYYY')}</span>
                      <Progress percent={m.percentagem} showInfo={false} status={m.negativo ? 'exception' : 'normal'} style={{ flex: 1, margin: 0 }} />
                      <span style={{ width: 150, textAlign: 'right' }}>{formatarKz(m.bruto)}</span>
                    </Flex>
                  ))
                )}
                <Typography.Text type="secondary">{resumo.documentos} documento(s) · total {formatarKz(resumo.bruto, true)}</Typography.Text>
              </Card>
            </Col>
            <Col xs={24} lg={12}>
              <Card title="Maiores clientes" style={{ marginBottom: 16 }}>
                <Table
                  rowKey={(c) => String(c.cliente_id ?? 'cf')}
                  size="small"
                  pagination={false}
                  dataSource={resumo.maiores_clientes}
                  columns={[
                    { title: 'Cliente', dataIndex: 'nome' },
                    { title: 'Docs.', dataIndex: 'documentos', align: 'right' },
                    { title: 'Facturado (Kz)', dataIndex: 'bruto', align: 'right', render: (v: string) => formatarKz(v) },
                  ]}
                />
              </Card>
            </Col>
          </Row>
          <Card title="Facturas pendentes mais recentes">
            <Table
              rowKey="id"
              size="small"
              pagination={false}
              dataSource={resumo.pendentes}
              columns={[
                { title: 'Documento', dataIndex: 'numero_documento' },
                { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
                { title: 'Cliente', key: 'cliente', render: (_, d) => d.cliente?.nome ?? (d.cliente ? `#${d.cliente.id}` : '—') },
                { title: 'Vencimento', dataIndex: 'data_vencimento', render: formatarData },
                { title: 'Pendente (Kz)', dataIndex: 'valor_pendente', align: 'right', render: (v: string) => formatarKz(v) },
              ]}
            />
          </Card>
        </>
      )}
    </>
  );
}
