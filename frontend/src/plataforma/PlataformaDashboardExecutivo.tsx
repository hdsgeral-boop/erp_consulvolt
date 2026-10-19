import { Alert, Card, Col, Row, Spin, Statistic } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
type Painel = { clientes: { total: number; ativos: number; suspensos: number }; empresas: { total: number; em_teste: number; licenca_expirada: number }; utilizadores: number };
export function PlataformaDashboardExecutivo() {
  const painel = useQuery({ queryKey: ['plataforma-painel'], queryFn: () => obter<Painel>('/plataforma/painel') });
  if (painel.isLoading) return <Spin />; if (painel.isError || !painel.data) return <Alert type="error" message="Não foi possível carregar o painel da plataforma." />;
  const p = painel.data; return <><h1>Plataforma SaaS</h1><p>Visão executiva da operação Consulvolt.</p><Row gutter={[16, 16]}><Col xs={24} sm={12} lg={6}><Card><Statistic title="Tenants" value={p.clientes.total} /></Card></Col><Col xs={24} sm={12} lg={6}><Card><Statistic title="Empresas" value={p.empresas.total} /></Card></Col><Col xs={24} sm={12} lg={6}><Card><Statistic title="Em teste" value={p.empresas.em_teste} /></Card></Col><Col xs={24} sm={12} lg={6}><Card><Statistic title="Utilizadores" value={p.utilizadores} /></Card></Col></Row></>;
}
