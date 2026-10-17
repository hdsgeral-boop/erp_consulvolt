import { Alert, Button, Card, Col, DatePicker, Empty, Row, Skeleton, Table, Tag, Typography, message, theme } from 'antd';
import { DownloadOutlined, ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { descarregar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { pares, tabelaHtml } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { GraficoDonut, GraficoLinhas } from '@/componentes/graficos/Graficos';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { type ResumoVendas, type ValidacaoSaft } from './kpis';
import { EcraSaft } from './EcraSaft';

/** Cores das fatias do donut «Maiores Clientes» no legado (ui_sales.js:4283), em ciclo como no Chart.js. */
const CORES_CLIENTES = (_r: string, i: number) => ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'][i % 5];

/** Os 2 cartões lado a lado (evolução mensal e maiores clientes) ocupam a altura da linha, como a grelha do legado. */
const ESTILO_CARTAO_PAR = { marginBottom: 24, height: 'calc(100% - 24px)' } as const;

/** N.º de clientes no donut do legado (topCustomers.slice(0, 5), ui_sales.js:4154). */
const TOP_CLIENTES = 5;

/** Vendas › Dashboard (ecrã vendas_relatorios) e o separador «Exportar SAFT-AO» (vendas_relatorios/saft). */
export default function Relatorios() {
  return (
    <Routes>
      <Route index element={<DashboardVendas />} />
      <Route path="saft" element={<EcraSaft />} />
    </Routes>
  );
}

/**
 * «Dashboard de Vendas» do legado (renderRelatoriosVendasTab, js/ui_sales.js:4060): cartões com barra de cor, evolução
 * mensal em linha com área, maiores clientes em donut, alertas de stock baixo e facturas pendentes. Valores do servidor.
 */
function DashboardVendas() {
  const navegar = useNavigate();
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
  const top5 = [...(resumo?.maiores_clientes ?? [])].sort((a, b) => Number(b.bruto) - Number(a.bruto)).slice(0, TOP_CLIENTES);

  /** Mapa impresso: indicadores, facturação mensal, maiores clientes e pendentes (A4 retrato, salvo se não couber). */
  const pedidoImpressao = () => {
    if (!resumo) return null;
    return {
      titulo: 'Dashboard de Vendas',
      periodo: `${formatarData(inicio)} a ${formatarData(fim)}`,
      filtros: ['Facturas e facturas-recibo menos notas de crédito; anulados excluídos'],
      conteudo: [
        pares(
          [
            ['Vendas totais (com IVA)', `${formatarKz(resumo.bruto)} Kz`],
            ['Vendas líquidas (sem IVA)', `${formatarKz(resumo.liquido)} Kz`],
            ['IVA liquidado', `${formatarKz(resumo.imposto)} Kz`],
            ['A receber (saldo)', `${formatarKz(resumo.a_receber)} Kz`],
            ['Notas de crédito', `${formatarKz(resumo.notas_credito)} Kz`],
            ['Documentos', resumo.documentos],
          ],
          3,
        ),
        tabelaHtml({
          legenda: 'Facturação mensal',
          colunas: [
            { titulo: 'Mês', valor: (m: ResumoVendas['por_mes'][number]) => dayjs(`${m.mes}-01`).format('MM/YYYY') },
            { titulo: 'Documentos', valor: (m) => m.documentos, formato: 'inteiro', somar: true },
            { titulo: 'Líquido (Kz)', valor: (m) => m.liquido, formato: 'moeda', somar: true },
            { titulo: 'Com IVA (Kz)', valor: (m) => m.bruto, formato: 'moeda', somar: true },
          ],
          linhas: resumo.por_mes,
          totais: true,
        }),
        tabelaHtml({
          legenda: 'Maiores clientes',
          colunas: [
            { titulo: 'Cliente', valor: (c: ResumoVendas['maiores_clientes'][number]) => c.nome, quebrar: true },
            { titulo: 'NIF', valor: (c) => c.nif ?? '' },
            { titulo: 'Docs.', valor: (c) => c.documentos, formato: 'inteiro' },
            { titulo: 'Facturado (Kz)', valor: (c) => c.bruto, formato: 'moeda' },
          ],
          linhas: resumo.maiores_clientes,
        }),
        tabelaHtml({
          legenda: 'Facturas pendentes mais recentes',
          colunas: [
            { titulo: 'Documento', valor: (d: ResumoVendas['pendentes'][number]) => d.numero_documento ?? `#${d.id}` },
            { titulo: 'Data', valor: (d) => d.data_emissao, formato: 'data' },
            { titulo: 'Cliente', valor: (d) => d.cliente?.nome ?? '', quebrar: true },
            { titulo: 'Vencimento', valor: (d) => d.data_vencimento, formato: 'data' },
            { titulo: 'Total (Kz)', valor: (d) => d.total_bruto, formato: 'moeda' },
            { titulo: 'Pendente (Kz)', valor: (d) => d.valor_pendente, formato: 'moeda', somar: true },
          ],
          linhas: resumo.pendentes,
          totais: true,
        }),
      ].join(''),
    };
  };

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
        titulo="Dashboard de Vendas"
        subtitulo="Indicadores de facturação do período (facturas e facturas-recibo menos notas de crédito)"
        impressao={podeResumo ? pedidoImpressao : undefined}
        impressaoDesactivada={!resumo}
        accoes={
          <>
            <DatePicker.RangePicker format="DD/MM/YYYY" allowClear={false} value={periodo} onChange={(v) => v?.[0] && v?.[1] && setPeriodo([v[0], v[1]])} />
            {podeResumo && <Button icon={<ReloadOutlined />} loading={consulta.isFetching} onClick={() => void consulta.refetch()}>Actualizar</Button>}
            {pode('vendas_faturacao_view') && <Button icon={<IconeFa nome="list" />} onClick={() => navegar('/m/vendas/vendas_faturacao')}>Ver Todas Faturas</Button>}
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
          {/* 4.3.2/4.3.5: cartões do legado (barra de cor por baixo, valor grande a negrito), rótulos explícitos com/sem IVA */}
          <div style={{ marginBottom: 24 }}>
            <GrelhaKpi larguraMinima={200}>
              <CartaoKpi rotulo="Vendas totais (com IVA)" valor={formatarKz(resumo.bruto)} unidade="Kz" cor="verde"
                ajuda="Total facturado com IVA (o sistema anterior chamava-lhe «Vendas Totais (Líquido)»)" />
              <CartaoKpi rotulo="Vendas líquidas (sem IVA)" valor={formatarKz(resumo.liquido)} unidade="Kz" cor="verdeAgua" />
              <CartaoKpi rotulo="IVA liquidado" valor={formatarKz(resumo.imposto)} unidade="Kz" cor="primario" />
              <CartaoKpi rotulo="A receber (saldo)" valor={formatarKz(resumo.a_receber)} unidade="Kz" cor="laranja"
                ajuda="Saldo por receber das facturas do período (já descontados recibos parciais e notas de crédito)" />
              <CartaoKpi rotulo="Notas de crédito" valor={formatarKz(resumo.notas_credito)} unidade="Kz" cor="vermelho" />
            </GrelhaKpi>
          </div>
          <Row gutter={[24, 0]}>
            <Col xs={24} lg={14}>
              <Card title="Evolução de Faturação Mensal" style={ESTILO_CARTAO_PAR}>
                {resumo.por_mes.length === 0 ? (
                  <Empty description="Sem documentos no período." />
                ) : (
                  <GraficoLinhas
                    rotulos={resumo.por_mes.map((m) => dayjs(`${m.mes}-01`).format('MM/YYYY'))}
                    series={[{ rotulo: 'Faturação (com IVA)', valores: resumo.por_mes.map((m) => m.bruto), cor: '#2563eb' }]}
                    monetario
                    preenchimento
                    altura={300}
                  />
                )}
                <Typography.Text type="secondary">{resumo.documentos} documento(s) · total {formatarKz(resumo.bruto, true)}</Typography.Text>
              </Card>
            </Col>
            <Col xs={24} lg={10}>
              {/* Legado (ui_sales.js:4151, 4280): top 5 clientes, 2 cartões de 300px lado a lado, legenda curta por baixo — sem tabela */}
              <Card title="Maiores Clientes (Faturação)" style={ESTILO_CARTAO_PAR}>
                {top5.length === 0 ? (
                  <Empty description="Sem clientes no período." />
                ) : (
                  <GraficoDonut coresCategorias={CORES_CLIENTES} rotulos={top5.map((c) => c.nome)} series={[{ rotulo: 'Facturado', valores: top5.map((c) => c.bruto) }]} monetario altura={200} />
                )}
              </Card>
            </Col>
          </Row>
          <Row gutter={[24, 0]}>
            <Col xs={24} lg={12}>
              <AlertasStock />
            </Col>
            <Col xs={24} lg={12}>
              <Card title="Faturas Pendentes (Mais Recentes)" style={{ marginBottom: 24 }}>
                <Table
                  rowKey="id"
                  size="small"
                  pagination={false}
                  dataSource={resumo.pendentes}
                  scroll={scrollTabela()}
                  columns={[
                    { title: 'Documento', dataIndex: 'numero_documento' },
                    { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
                    { title: 'Cliente', key: 'cliente', render: (_, d) => d.cliente?.nome ?? (d.cliente ? `#${d.cliente.id}` : '—') },
                    { title: 'Vencimento', dataIndex: 'data_vencimento', responsive: ['md'], render: formatarData },
                    { title: 'Pendente (Kz)', dataIndex: 'valor_pendente', align: 'right', render: (v: string) => formatarKz(v) },
                  ]}
                />
              </Card>
            </Col>
          </Row>
        </>
      )}
    </>
  );
}

/** Ronda 3: estado pelas regras dos níveis de stock (CRITICO, REPOSICAO) ou a regra do legado (< 5 un.) sem níveis. */
interface AlertaStock { id: number; codigo: string | null; nome: string | null; stock: string; minimo: string; estado?: string; motivo?: string | null; quantidade_sugerida?: string | null }

/** 4.3.7 — «Alertas de Stock Baixo» do Dashboard de Vendas do legado (produto, código e «N un»). */
function AlertasStock() {
  const { token } = theme.useToken();
  const alertas = useQuery({
    queryKey: ['vendas', 'relatorio', 'alertas-stock'],
    queryFn: () => obter<AlertaStock[]>('/vendas/relatorios/alertas-stock'),
    staleTime: 120_000,
    retry: false,
  });
  return (
    <Card title="Alertas de Stock Baixo" style={{ marginBottom: 24 }} styles={{ body: { padding: 0 } }} loading={alertas.isLoading}>
      {alertas.error ? (
        <Alert type="error" showIcon style={{ margin: 16 }} message="Não foi possível ler os alertas de stock." />
      ) : !alertas.data?.length ? (
        <div style={{ padding: 32, textAlign: 'center', color: token.colorTextSecondary }}>Nenhum alerta de stock.</div>
      ) : (
        <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
          {alertas.data.map((p) => {
            const q = Number(p.stock);
            return (
              <li key={p.id} style={{ padding: 16, borderBottom: `1px solid ${token.colorSplit}`, display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12 }}>
                <div style={{ minWidth: 0 }}>
                  <span style={{ display: 'block', fontWeight: 600, fontSize: 14 }}>{p.nome}</span>
                  <small style={{ color: token.colorTextSecondary }}>
                    Código: {p.codigo ?? '—'}
                    {p.motivo && p.estado !== 'STOCK_BAIXO' ? ` · ${p.motivo}` : ''}
                    {p.estado === 'REPOSICAO' || (p.estado === 'CRITICO' && Number(p.quantidade_sugerida) > 0) ? ` · repor ${formatarNumero(p.quantidade_sugerida ?? 0)}` : ''}
                  </small>
                </div>
                <Tag color={q <= 0 || p.estado === 'CRITICO' ? 'red' : 'orange'} style={{ margin: 0 }}>{formatarNumero(q)} un</Tag>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}
