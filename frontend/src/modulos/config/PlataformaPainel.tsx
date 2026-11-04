import { Card, Col, Row, Statistic, Table } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';
import { GraficosPainel, type SeriesPainel } from '@/plataforma/GraficosPainel';

interface Painel {
  clientes: { total: number; ativos: number; suspensos: number };
  empresas: { total: number; ativas: number; em_teste: number; licenca_expirada: number };
  utilizadores: number;
  ultimos_clientes: { id: number; nome: string; slug: string; criado_em: string }[];
  indicadores: SeriesPainel & {
    mrr: number; arr: number; receita_recebida_mes: number;
    pagamentos: { pendentes: number; confirmados_mes: number };
    taxa_cancelamento_mes: number; taxa_conversao_trial_mes: number;
    tickets: { abertos: number; criticos: number; vencidos: number; tempo_medio_primeira_resposta_horas: number | null };
    disponibilidade: boolean;
  };
}

/** Plataforma SaaS — painel (plataforma_painel): totais de clientes, empresas, licenças, utilizadores e indicadores (Fase 5). */
export default function PlataformaPainel() {
  const { data, isLoading } = useQuery({ queryKey: ['plataforma', 'painel'], queryFn: () => obter<Painel>('/plataforma/painel') });
  const n = (titulo: string, valor?: number | string) => (
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
      <Row gutter={[12, 12]} style={{ marginTop: 12 }}>
        {n('MRR (AOA)', data ? formatarNumero(data.indicadores.mrr) : undefined)}
        {n('ARR (AOA)', data ? formatarNumero(data.indicadores.arr) : undefined)}
        {n('Receita recebida no mês (AOA)', data ? formatarNumero(data.indicadores.receita_recebida_mes) : undefined)}
        {n('Pagamentos pendentes', data?.indicadores.pagamentos.pendentes)}
        {n('Cancelamento no mês (%)', data?.indicadores.taxa_cancelamento_mes)}
        {n('Conversão de trial no mês (%)', data?.indicadores.taxa_conversao_trial_mes)}
        {n('Tickets abertos', data?.indicadores.tickets.abertos)}
        {n('Tickets vencidos (SLA)', data?.indicadores.tickets.vencidos)}
      </Row>
      <GraficosPainel series={data?.indicadores} carregar={isLoading} />
      <Card title="Últimos clientes" style={{ marginTop: 12 }}>
        <Table rowKey="id" size="small" pagination={false} loading={isLoading} dataSource={data?.ultimos_clientes ?? []}
          columns={[{ title: 'Cliente', dataIndex: 'nome' }, { title: 'Endereço', dataIndex: 'slug' }, { title: 'Criado em', render: (_, c) => formatarDataHora(c.criado_em) }]} />
      </Card>
    </>
  );
}