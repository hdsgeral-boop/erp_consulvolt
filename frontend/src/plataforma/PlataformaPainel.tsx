import { Button, Card, Col, Empty, Row, Space, Statistic, Table, Tag, Typography } from 'antd';
import { ApartmentOutlined, CustomerServiceOutlined, DownloadOutlined, DollarOutlined, ReloadOutlined, TeamOutlined, UserOutlined, WarningOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { descarregar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { GraficoBarras, GraficoDonut, GraficoLinhas } from '@/componentes/graficos/Graficos';
import { formatarData, formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';

interface Painel {
  clientes: { total: number; ativos: number; suspensos: number };
  empresas: { total: number; ativas: number; em_teste: number; licenca_expirada: number };
  utilizadores: number;
  ultimos_clientes: { id: number; nome: string; slug: string; criado_em: string }[];
  indicadores?: {
    mrr: number;
    moeda: string;
    utilizadores_ativos_30d: number;
    tickets_abertos: number;
    meses: string[];
    clientes_novos_mes: number[];
    receita_mes: number[];
    dias: string[];
    acessos_dia: number[];
    por_plano: { plano: string; clientes: number }[];
    tickets: Record<string, number>;
    licencas_a_expirar: { empresa: string; cliente: string | null; licenca_fim: string; origem: string }[];
  };
}

function Indicador({ titulo, valor, icone, cor, sufixo, rodape, aoClicar, carregar }: { titulo: string; valor: number | string; icone: ReactNode; cor: string; sufixo?: string; rodape?: ReactNode; aoClicar?: () => void; carregar: boolean }) {
  return (
    <Card loading={carregar} hoverable={!!aoClicar} onClick={aoClicar} styles={{ body: { padding: 16 } }}>
      <Space align="start" style={{ width: '100%', justifyContent: 'space-between' }}>
        <Statistic title={titulo} value={valor} suffix={sufixo} valueStyle={{ fontWeight: 700 }} />
        <span style={{ fontSize: 22, color: cor, background: `${cor}1a`, borderRadius: 10, padding: '6px 10px' }}>{icone}</span>
      </Space>
      {rodape && <div style={{ marginTop: 6, fontSize: 12, color: '#64748b' }}>{rodape}</div>}
    </Card>
  );
}

/**
 * Plataforma SaaS — painel (plataforma_painel): indicadores do negócio (receita recorrente estimada, clientes, empresas,
 * utilizadores activos, tickets), séries de 12 meses e 30 dias, distribuição por plano, licenças a expirar e atalhos.
 */
export default function PlataformaPainel() {
  const navegar = useNavigate();
  const { data, isLoading, refetch, isFetching } = useQuery({ queryKey: ['plataforma', 'painel'], queryFn: () => obter<Painel>('/plataforma/painel'), staleTime: 60_000 });
  const ind = data?.indicadores;

  return (
    <>
      <CabecalhoPagina
        titulo="Painel da plataforma"
        subtitulo="Visão geral do negócio: clientes, receita, utilização e suporte"
        accoes={
          <Space wrap>
            <Button icon={<DownloadOutlined />} onClick={() => void descarregar('/plataforma/relatorios/clientes.csv', undefined, 'clientes.csv')}>Clientes (CSV)</Button>
            <Button icon={<DownloadOutlined />} onClick={() => void descarregar('/plataforma/relatorios/empresas.csv', undefined, 'empresas.csv')}>Empresas (CSV)</Button>
            <Button icon={<ReloadOutlined />} loading={isFetching} onClick={() => void refetch()}>Actualizar</Button>
          </Space>
        }
      />
      <Row gutter={[12, 12]}>
        <Col xs={12} lg={6}>
          <Indicador carregar={isLoading} titulo="Receita recorrente (mês)" valor={formatarNumero(ind?.mrr ?? 0)} sufixo={ind?.moeda} icone={<DollarOutlined />} cor="#16a34a" rodape="Estimada pelos planos dos clientes activos" />
        </Col>
        <Col xs={12} lg={6}>
          <Indicador carregar={isLoading} titulo="Clientes activos" valor={data?.clientes.ativos ?? 0} icone={<TeamOutlined />} cor="#2563eb"
            rodape={`${data?.clientes.total ?? 0} no total · ${data?.clientes.suspensos ?? 0} suspenso(s)`} aoClicar={() => navegar('/plataforma/clientes')} />
        </Col>
        <Col xs={12} lg={6}>
          <Indicador carregar={isLoading} titulo="Empresas" valor={data?.empresas.total ?? 0} icone={<ApartmentOutlined />} cor="#7c3aed"
            rodape={`${data?.empresas.em_teste ?? 0} em teste · ${data?.empresas.licenca_expirada ?? 0} com licença expirada`} />
        </Col>
        <Col xs={12} lg={6}>
          <Indicador carregar={isLoading} titulo="Utilizadores activos (30 dias)" valor={ind?.utilizadores_ativos_30d ?? 0} icone={<UserOutlined />} cor="#0891b2"
            rodape={`${data?.utilizadores ?? 0} utilizadores no total`} />
        </Col>
      </Row>

      <Row gutter={[12, 12]} style={{ marginTop: 12 }}>
        <Col xs={24} xl={16}>
          <Card title="Receita recebida por mês" loading={isLoading}>
            {ind && <GraficoBarras rotulos={ind.meses} series={[{ rotulo: `Receita (${ind.moeda})`, valores: ind.receita_mes, cor: '#16a34a' }]} monetario altura={240} />}
          </Card>
        </Col>
        <Col xs={24} xl={8}>
          <Card title="Clientes por plano" loading={isLoading}>
            {ind && ind.por_plano.length > 0 ? (
              <GraficoDonut rotulos={ind.por_plano.map((p) => p.plano)} series={[{ rotulo: 'Clientes', valores: ind.por_plano.map((p) => p.clientes) }]} altura={240} />
            ) : (
              <Empty description="Sem clientes" />
            )}
          </Card>
        </Col>
        <Col xs={24} xl={12}>
          <Card title="Acessos por dia (últimos 30 dias)" loading={isLoading}>
            {ind && <GraficoLinhas rotulos={ind.dias} series={[{ rotulo: 'Utilizadores', valores: ind.acessos_dia, cor: '#0891b2' }]} preenchimento altura={220} />}
          </Card>
        </Col>
        <Col xs={24} xl={12}>
          <Card title="Clientes novos por mês" loading={isLoading}>
            {ind && <GraficoBarras rotulos={ind.meses} series={[{ rotulo: 'Clientes novos', valores: ind.clientes_novos_mes, cor: '#2563eb' }]} altura={220} />}
          </Card>
        </Col>
      </Row>

      <Row gutter={[12, 12]} style={{ marginTop: 12 }}>
        <Col xs={24} xl={12}>
          <Card title={<Space><WarningOutlined style={{ color: '#d97706' }} />Licenças a expirar (30 dias)</Space>} loading={isLoading}>
            <Table rowKey={(l) => `${l.empresa}-${l.licenca_fim}`} size="small" pagination={false} dataSource={ind?.licencas_a_expirar ?? []}
              locale={{ emptyText: 'Nenhuma licença a expirar nos próximos 30 dias.' }}
              columns={[
                { title: 'Empresa', dataIndex: 'empresa' },
                { title: 'Cliente', dataIndex: 'cliente' },
                { title: 'Fim', render: (_, l) => formatarData(l.licenca_fim) },
                { title: 'Tipo', render: (_, l) => <Tag color={l.origem === 'TESTE' ? 'orange' : 'blue'}>{l.origem}</Tag> },
              ]} />
          </Card>
        </Col>
        <Col xs={24} xl={6}>
          <Card title={<Space><CustomerServiceOutlined />Suporte</Space>} loading={isLoading} extra={<Typography.Link onClick={() => navegar('/plataforma/suporte')}>Abrir</Typography.Link>}>
            <Statistic title="Tickets por resolver" value={ind?.tickets_abertos ?? 0} />
            <Space wrap style={{ marginTop: 12 }}>
              {Object.entries(ind?.tickets ?? {}).map(([estado, n]) => <Tag key={estado}>{estado.replace('_', ' ')}: {n}</Tag>)}
            </Space>
          </Card>
        </Col>
        <Col xs={24} xl={6}>
          <Card title="Últimos clientes" loading={isLoading} extra={<Typography.Link onClick={() => navegar('/plataforma/clientes')}>Ver todos</Typography.Link>}>
            {(data?.ultimos_clientes ?? []).map((c) => (
              <div key={c.id} style={{ padding: '6px 0', borderBottom: '1px solid #f1f5f9' }}>
                <div style={{ fontWeight: 600 }}>{c.nome}</div>
                <div style={{ fontSize: 12, color: '#64748b' }}>{c.slug} · {formatarDataHora(c.criado_em)}</div>
              </div>
            ))}
          </Card>
        </Col>
      </Row>
    </>
  );
}
