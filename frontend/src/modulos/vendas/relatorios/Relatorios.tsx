import { Alert, Button, Card, Col, DatePicker, Empty, Flex, Progress, Row, Skeleton, Statistic, Table, Typography, message } from 'antd';
import { DownloadOutlined, ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
import { http, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { DocumentoVenda } from '../api';
import { calcularKpis } from './kpis';

const MAX_PAGINAS = 20;

/** Todos os FT/FR/NC do período (páginas de 500; limite de 10 000 documentos por tipo). */
async function documentosDoPeriodo(inicio: string, fim: string): Promise<{ documentos: DocumentoVenda[]; truncado: boolean }> {
  const documentos: DocumentoVenda[] = [];
  let truncado = false;
  for (const tipo of ['FT', 'FR', 'NC']) {
    for (let pagina = 1; pagina <= MAX_PAGINAS; pagina++) {
      const r = await obterPagina<DocumentoVenda>('/vendas/documentos', { tipo_documento: tipo, data_inicio: inicio, data_fim: fim, por_pagina: 500, pagina });
      documentos.push(...r.itens);
      if (pagina >= r.paginacao.ultima_pagina) break;
      if (pagina === MAX_PAGINAS) truncado = true;
    }
  }
  return { documentos, truncado };
}

/** Nome do ficheiro a partir do Content-Disposition. */
export function nomeFicheiro(cabecalho: string | undefined, alternativa: string): string {
  const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cabecalho ?? '');
  return m ? decodeURIComponent(m[1]) : alternativa;
}

/** Vendas › Relatórios de vendas (ecrã vendas_relatorios): indicadores do período e exportação SAF-T(AO). */
export default function Relatorios() {
  const { pode } = useSessao();
  const [periodo, setPeriodo] = useState<[Dayjs, Dayjs]>([dayjs().startOf('year'), dayjs()]);
  const [aExportar, setAExportar] = useState(false);
  const inicio = dataApi(periodo[0]) as string;
  const fim = dataApi(periodo[1]) as string;
  const podeDocumentos = pode('vendas_faturacao_view');

  const consulta = useQuery({ queryKey: ['vendas', 'relatorio', inicio, fim], queryFn: () => documentosDoPeriodo(inicio, fim), enabled: podeDocumentos, staleTime: 120_000 });
  const kpis = useMemo(() => calcularKpis(consulta.data?.documentos ?? []), [consulta.data]);
  const maxMes = Math.max(1, ...kpis.porMes.map((m) => Math.abs(m.bruto)));

  const exportarSaft = async () => {
    setAExportar(true);
    try {
      const r = await http.get<Blob>('/vendas/saft', { params: { inicio, fim }, responseType: 'blob' });
      const url = URL.createObjectURL(r.data);
      const a = document.createElement('a');
      a.href = url;
      a.download = nomeFicheiro(r.headers['content-disposition'] as string | undefined, `SAFT_AO_${inicio}_${fim}.xml`);
      a.click();
      URL.revokeObjectURL(url);
      const avisos = decodeURIComponent(String(r.headers['x-saft-avisos'] ?? ''));
      message.success(`SAF-T gerado: ${r.headers['x-saft-documentos'] ?? '?'} documento(s), ${r.headers['x-saft-recibos'] ?? '?'} recibo(s).`);
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
            {podeDocumentos && <Button icon={<ReloadOutlined />} loading={consulta.isFetching} onClick={() => void consulta.refetch()}>Actualizar</Button>}
            {pode('vendas_relatorios_view', 'vendas_fe_config') && (
              <Button type="primary" icon={<DownloadOutlined />} loading={aExportar} onClick={() => void exportarSaft()}>
                Exportar SAF-T (AO)
              </Button>
            )}
          </>
        }
      />
      {!podeDocumentos ? (
        <Alert type="info" showIcon message="Os indicadores usam a listagem de documentos de venda, que exige a consulta da Facturação. A exportação SAF-T está disponível." />
      ) : consulta.isLoading ? (
        <Skeleton active />
      ) : consulta.error ? (
        <Alert type="error" showIcon message="Não foi possível calcular os indicadores." />
      ) : (
        <>
          {consulta.data?.truncado && <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="O período tem demasiados documentos: os indicadores estão incompletos. Escolha um período mais curto." />}
          <Row gutter={16} style={{ marginBottom: 16 }}>
            <Col xs={12} md={6}><Card><Statistic title="Vendas líquidas (Kz)" value={formatarKz(kpis.liquido)} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="IVA liquidado (Kz)" value={formatarKz(kpis.imposto)} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="A receber (Kz)" value={formatarKz(kpis.aReceber)} valueStyle={{ color: '#d48806' }} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="Notas de crédito (Kz)" value={formatarKz(kpis.notasCredito)} valueStyle={{ color: '#cf1322' }} /></Card></Col>
          </Row>
          <Row gutter={16}>
            <Col xs={24} lg={12}>
              <Card title="Facturação mensal (com IVA)" style={{ marginBottom: 16 }}>
                {kpis.porMes.length === 0 ? (
                  <Empty description="Sem documentos no período." />
                ) : (
                  kpis.porMes.map((m) => (
                    <Flex key={m.mes} align="center" gap={12} style={{ marginBottom: 6 }}>
                      <span style={{ width: 70 }}>{dayjs(`${m.mes}-01`).format('MM/YYYY')}</span>
                      <Progress percent={Math.round((Math.abs(m.bruto) / maxMes) * 100)} showInfo={false} status={m.bruto < 0 ? 'exception' : 'normal'} style={{ flex: 1, margin: 0 }} />
                      <span style={{ width: 150, textAlign: 'right' }}>{formatarKz(m.bruto)}</span>
                    </Flex>
                  ))
                )}
                <Typography.Text type="secondary">{kpis.documentos} documento(s) · total {formatarKz(kpis.bruto, true)}</Typography.Text>
              </Card>
            </Col>
            <Col xs={24} lg={12}>
              <Card title="Maiores clientes" style={{ marginBottom: 16 }}>
                <Table
                  rowKey="cliente_id"
                  size="small"
                  pagination={false}
                  dataSource={kpis.topClientes}
                  columns={[
                    { title: 'Cliente', dataIndex: 'nome' },
                    { title: 'Docs.', dataIndex: 'documentos', align: 'right' },
                    { title: 'Facturado (Kz)', dataIndex: 'bruto', align: 'right', render: (v: number) => formatarKz(v) },
                  ]}
                />
              </Card>
            </Col>
          </Row>
          <Card title="Facturas pendentes mais recentes">
            <Table<DocumentoVenda>
              rowKey="id"
              size="small"
              pagination={false}
              dataSource={kpis.pendentes}
              columns={[
                { title: 'Documento', dataIndex: 'numero_documento' },
                { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
                { title: 'Cliente', render: (_, d) => d.cliente?.nome ?? `#${d.cliente_id}` },
                { title: 'Vencimento', dataIndex: 'data_vencimento', render: formatarData },
                { title: 'Pendente (Kz)', dataIndex: 'valor_pendente', align: 'right', render: (v: string | null) => formatarKz(v) },
              ]}
            />
          </Card>
        </>
      )}
    </>
  );
}
