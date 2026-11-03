import { Button, Card, Col, Row, Statistic, Table, Tag } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';

interface Metricas {
  por_estado: Record<string, number>;
  tempo_medio_primeira_resposta_horas: number | null;
  em_atraso: number;
  por_agente: { agente_id: number; nome: string; total: number }[];
}

// Fase 6 da extensão SaaS (segurança.txt, secção 13.3): os 11 estados — ver TicketSuporte::ESTADOS.
const ESTADOS_TERMINAIS = ['RESOLVIDO', 'FECHADO', 'CANCELADO'];
const ORDEM_ESTADOS = [
  'ABERTO', 'EM_TRIAGEM', 'EM_ANALISE', 'ATRIBUIDO', 'EM_CURSO', 'A_AGUARDAR_CLIENTE', 'A_AGUARDAR_TERCEIRO',
  'RESOLVIDO', 'FECHADO', 'REABERTO', 'CANCELADO',
];

/** Dashboard de métricas do backoffice de suporte: tickets por estado, tempo médio de resposta, atrasos e por agente. */
export default function DashboardSuporte() {
  const navegar = useNavigate();
  const m = useQuery({ queryKey: ['suporte_agente', 'metricas'], queryFn: () => obter<Metricas>('/suporte/metricas') });
  const d = m.data;

  return (
    <>
      <CabecalhoPagina
        titulo="Dashboard de suporte"
        subtitulo="Métricas dos tickets de suporte"
        accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>}
      />
      <Row gutter={[16, 16]}>
        <Col xs={24} sm={12} lg={6}><Card loading={m.isLoading}><Statistic title="Abertos" value={d?.por_estado.ABERTO ?? 0} /></Card></Col>
        <Col xs={24} sm={12} lg={6}><Card loading={m.isLoading}><Statistic title="Em curso" value={d?.por_estado.EM_CURSO ?? 0} /></Card></Col>
        <Col xs={24} sm={12} lg={6}><Card loading={m.isLoading}><Statistic title="Resolvidos" value={d?.por_estado.RESOLVIDO ?? 0} /></Card></Col>
        <Col xs={24} sm={12} lg={6}><Card loading={m.isLoading}><Statistic title="Fechados" value={d?.por_estado.FECHADO ?? 0} /></Card></Col>
        <Col xs={24} sm={12} lg={8}>
          <Card loading={m.isLoading}>
            <Statistic title="Tempo médio até à 1.ª resposta" value={d?.tempo_medio_primeira_resposta_horas ?? '—'} suffix={d?.tempo_medio_primeira_resposta_horas !== null && d?.tempo_medio_primeira_resposta_horas !== undefined ? 'h' : ''} />
          </Card>
        </Col>
        <Col xs={24} sm={12} lg={8}>
          <Card loading={m.isLoading}><Statistic title="Tickets em atraso (SLA vencido)" value={d?.em_atraso ?? 0} styles={{ content: d && d.em_atraso > 0 ? { color: '#dc2626' } : undefined }} /></Card>
        </Col>
      </Row>
      <Card title="Tickets por estado" style={{ marginTop: 16 }}>
        <Table rowKey="estado" size="small" loading={m.isLoading} pagination={false}
          dataSource={ORDEM_ESTADOS.map((estado) => ({ estado, total: d?.por_estado[estado] ?? 0 }))}
          columns={[
            { title: 'Estado', dataIndex: 'estado', render: (v: string) => <Tag color={ESTADOS_TERMINAIS.includes(v) ? 'default' : 'blue'}>{v}</Tag> },
            { title: 'Tickets', dataIndex: 'total', align: 'right' },
          ]} />
      </Card>
      <Card title="Tickets por agente" style={{ marginTop: 16 }}>
        <Table rowKey="agente_id" size="small" loading={m.isLoading} pagination={false} dataSource={d?.por_agente ?? []}
          columns={[{ title: 'Agente', dataIndex: 'nome' }, { title: 'Tickets atribuídos', dataIndex: 'total', align: 'right' }]} />
      </Card>
    </>
  );
}
