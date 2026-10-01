import { Alert, Button, Card, Col, DatePicker, Empty, Row, Space, Statistic, Table, Typography } from 'antd';
import { BarChartOutlined, FileSearchOutlined, FundOutlined, LineChartOutlined, ProfileOutlined, TableOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { BotaoCsv, ValorKz } from './comum/Componentes';
import { eZero } from '@/utilitarios/decimal';
import { MovimentosSemNota } from './comum/Demonstracao';
import { useAbrirLancamento } from './comum/useMapa';

interface Desequilibrio {
  diario: string;
  diario_id: number;
  lancamento: string;
  sem_numero_lan: boolean;
  data_documento: string;
  linhas: number;
  debito: string;
  credito: string;
  diferenca: string;
  primeira_linha_id: number;
}

interface RelatorioDesequilibrios {
  resumo: { debito: string; credito: string; diferenca: string; lancamentos_desequilibrados: number; soma_das_diferencas: string };
  lancamentos: Desequilibrio[];
}

const ICONES: Record<string, ReactNode> = {
  contab_mapa_extrato: <FileSearchOutlined />,
  contab_mapa_balancete: <TableOutlined />,
  contab_mapa_balanco: <ProfileOutlined />,
  contab_mapa_dr: <BarChartOutlined />,
  contab_mapa_fluxo: <FundOutlined />,
  contab_mapa_evolucao: <LineChartOutlined />,
};

/**
 * Contabilidade › Mapas e relatórios (ecrã relatorios_contabeis): acesso aos mapas que o utilizador pode ver,
 * controlo de desequilíbrios (D ≠ C por lançamento) e movimentos sem nota DEMO.
 */
export default function RelatoriosContabeis() {
  const { menu } = useSessao();
  const navegar = useNavigate();
  const abrir = useAbrirLancamento();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [semNota, setSemNota] = useState(false);
  const mapas = menu.find((m) => m.id === 'contab')?.ecras.filter((e) => e.pai === 'relatorios_contabeis') ?? [];

  const deseq = useQuery({
    queryKey: ['contab', 'desequilibrios', dataApi(periodo?.[0]), dataApi(periodo?.[1])],
    queryFn: () => obter<RelatorioDesequilibrios>('/contabilidade/relatorios/desequilibrios', { data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }),
  });
  const r = deseq.data?.resumo;

  return (
    <>
      <CabecalhoPagina titulo="Mapas e relatórios" subtitulo="Mapas contabilísticos e controlo de qualidade dos lançamentos" />
      <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
        {mapas.length === 0 && (
          <Col span={24}>
            <Empty description="Não tem acesso a nenhum mapa contabilístico nesta empresa." />
          </Col>
        )}
        {mapas.map((m) => (
          <Col key={m.id} xs={24} sm={12} lg={8}>
            <Card hoverable onClick={() => navegar(`/m/contab/${m.id}`)}>
              <Space>
                <span style={{ fontSize: 22 }}>{ICONES[m.id] ?? <TableOutlined />}</span>
                <Typography.Text strong>{m.nome}</Typography.Text>
              </Space>
            </Card>
          </Col>
        ))}
      </Row>

      <Card
        title="Controlo de desequilíbrios"
        extra={
          <Space wrap>
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} allowEmpty={[true, true]} />
            <Button onClick={() => setSemNota(true)}>Movimentos sem nota DEMO</Button>
          </Space>
        }
      >
        {r && (
          <>
            <Space size={32} wrap style={{ marginBottom: 12 }}>
              <Statistic title="Total a débito" value={formatarKz(r.debito)} />
              <Statistic title="Total a crédito" value={formatarKz(r.credito)} />
              <Statistic title="Diferença" value={formatarKz(r.diferenca)} valueStyle={{ color: eZero(r.diferenca) ? undefined : '#cf1322' }} />
              <Statistic title="Lançamentos desequilibrados" value={r.lancamentos_desequilibrados} />
            </Space>
            {r.lancamentos_desequilibrados === 0 ? (
              <Alert type="success" showIcon message="Todos os lançamentos do período estão equilibrados (débito = crédito)." />
            ) : (
              <>
                <div style={{ marginBottom: 8 }}>
                  <BotaoCsv<Desequilibrio>
                    nome="desequilibrios"
                    linhas={deseq.data?.lancamentos}
                    colunas={[
                      { titulo: 'Diário', valor: (l) => l.diario },
                      { titulo: 'Lançamento', valor: (l) => l.lancamento },
                      { titulo: 'Data', valor: (l) => l.data_documento },
                      { titulo: 'Linhas', valor: (l) => l.linhas },
                      { titulo: 'Débito', valor: (l) => l.debito, numerico: true },
                      { titulo: 'Crédito', valor: (l) => l.credito, numerico: true },
                      { titulo: 'Diferença', valor: (l) => l.diferenca, numerico: true },
                    ]}
                  />
                </div>
                <Table<Desequilibrio>
                  rowKey={(l) => `${l.diario_id}|${l.lancamento}`}
                  size="small"
                  loading={deseq.isFetching}
                  dataSource={deseq.data?.lancamentos}
                  pagination={{ pageSize: 25 }}
                  scroll={{ x: 'max-content' }}
                  columns={[
                    { title: 'Diário', dataIndex: 'diario' },
                    {
                      title: 'Lançamento',
                      dataIndex: 'lancamento',
                      render: (v: string, l) => (abrir ? <Typography.Link onClick={() => abrir(l.primeira_linha_id)}>{v}</Typography.Link> : v),
                    },
                    { title: 'Data', dataIndex: 'data_documento', render: formatarData },
                    { title: 'Linhas', dataIndex: 'linhas', align: 'right' },
                    { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                    { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                    { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (v: string) => <ValorKz valor={v} forte /> },
                  ]}
                />
              </>
            )}
          </>
        )}
      </Card>
      {semNota && <MovimentosSemNota parametros={{ data_fim: dataApi(periodo?.[1]) ?? new Date().toISOString().slice(0, 10), data_inicio: dataApi(periodo?.[0]) }} aoFechar={() => setSemNota(false)} />}
    </>
  );
}
