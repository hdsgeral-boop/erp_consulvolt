import { Button, Card, Col, Row, Statistic, Table } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';

interface Metricas {
  por_estado: { ABERTO: number; EM_CURSO: number; RESOLVIDO: number; FECHADO: number };
  tempo_medio_primeira_resposta_horas: number | null;
  em_atraso: number;
  por_agente: { agente_id: number; nome: string; total: number }[];
}

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
          <Card loading={m.isLoading}><Statistic title="Tickets em atraso (SLA vencido)" value={d?.em_atraso ?? 0} valueStyle={d && d.em_atraso > 0 ? { color: '#dc2626' } : undefined} /></Card>
        </Col>
      </Row>
      <Card title="Tickets por agente" style={{ marginTop: 16 }}>
        <Table rowKey="agente_id" size="small" loading={m.isLoading} pagination={false} dataSource={d?.por_agente ?? []}
          columns={[{ title: 'Agente', dataIndex: 'nome' }, { title: 'Tickets atribuídos', dataIndex: 'total', align: 'right' }]} />
      </Card>
    </>
  );
}
