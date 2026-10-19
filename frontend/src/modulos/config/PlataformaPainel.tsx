import { Card, Col, Row, Statistic, Table } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { formatarDataHora } from '@/utilitarios/formatacao';

interface Painel {
  clientes: { total: number; ativos: number; suspensos: number };
  empresas: { total: number; ativas: number; em_teste: number; licenca_expirada: number };
  utilizadores: number;
  ultimos_clientes: { id: number; nome: string; slug: string; criado_em: string }[];
}

/** Plataforma SaaS — painel (plataforma_painel): totais de clientes, empresas, licenças e utilizadores. */
export default function PlataformaPainel() {
  const { data, isLoading } = useQuery({ queryKey: ['plataforma', 'painel'], queryFn: () => obter<Painel>('/plataforma/painel') });
  const n = (titulo: string, valor?: number) => (
    <Col xs={12} md={6}><Card loading={isLoading}><Statistic title={titulo} value={valor ?? 0} /></Card></Col>
  );
  return (
    <>
      <CabecalhoPagina titulo="Painel da plataforma" subtitulo="Visão geral dos clientes SaaS, empresas e licenças" />
      <Row gutter={[12, 12]}>
        {n('Clientes', data?.clientes.total)}
        {n('Clientes suspensos', data?.clientes.suspensos)}
        {n('Empresas', data?.empresas.total)}
        {n('Utilizadores', data?.utilizadores)}
        {n('Empresas em teste', data?.empresas.em_teste)}
        {n('Licenças expiradas', data?.empresas.licenca_expirada)}
      </Row>
      <Card title="Últimos clientes" style={{ marginTop: 12 }}>
        <Table rowKey="id" size="small" pagination={false} loading={isLoading} dataSource={data?.ultimos_clientes ?? []}
          columns={[{ title: 'Cliente', dataIndex: 'nome' }, { title: 'Endereço', dataIndex: 'slug' }, { title: 'Criado em', render: (_, c) => formatarDataHora(c.criado_em) }]} />
      </Card>
    </>
  );
}