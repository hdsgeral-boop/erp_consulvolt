import { Avatar, Button, Card, Col, Dropdown, Empty, Row, Space, Tag, Typography } from 'antd';
import {
  ApartmentOutlined,
  BellOutlined,
  CheckCircleOutlined,
  ClockCircleOutlined,
  CustomerServiceOutlined,
  DesktopOutlined,
  DollarOutlined,
  DownloadOutlined,
  ExclamationCircleOutlined,
  PlusOutlined,
  ReloadOutlined,
  RightOutlined,
  ThunderboltOutlined,
} from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { descarregar, obter } from '@/api/cliente';
import { GraficoBarras, GraficoDonut, GraficoLinhas } from '@/componentes/graficos/Graficos';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';

interface Painel {
  clientes: { total: number; ativos: number; suspensos: number };
  empresas: { total: number; ativas: number };
  /** A licença é do cliente: contagens de clientes. */
  licencas?: { total: number; ativas: number; em_teste: number; expiradas: number; sem_limite: number };
  utilizadores: number;
  sessoes_ativas?: number;
  ultimos_clientes: { id: number; nome: string; slug: string; estado?: string; plano?: string | null; criado_em: string }[];
  actividade_recente?: { acao: string; utilizador: string | null; cliente: string | null; ocorrido_em: string }[];
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
    licencas_a_expirar: { cliente: string; licenca_fim: string; origem: string }[];
  };
}

const COR_ESTADO: Record<string, string> = { ATIVO: 'green', SUSPENSO: 'orange', ARQUIVADO: 'default' };
const NOME_ESTADO: Record<string, string> = { ATIVO: 'Activo', SUSPENSO: 'Suspenso', ARQUIVADO: 'Arquivado' };

const saudacao = () => {
  const h = new Date().getHours();
  return h < 12 ? 'Bom dia' : h < 19 ? 'Boa tarde' : 'Boa noite';
};

/** Cartão de indicador: ícone colorido, rótulo, valor grande e uma linha de tendência/estado (como no Central Admin). */
function Indicador({ rotulo, valor, icone, cor, rodape, aoClicar, carregar }: { rotulo: string; valor: ReactNode; icone: ReactNode; cor: string; rodape: ReactNode; aoClicar?: () => void; carregar: boolean }) {
  return (
    <Card loading={carregar} hoverable={!!aoClicar} onClick={aoClicar} styles={{ body: { padding: 18 } }} style={{ borderRadius: 14 }}>
      <span style={{ fontSize: 18, color: cor, background: `${cor}1a`, borderRadius: 10, width: 38, height: 38, display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}>{icone}</span>
      <div style={{ marginTop: 12, fontSize: 13, color: '#64748b', fontWeight: 500 }}>{rotulo}</div>
      <div style={{ fontSize: 26, fontWeight: 800, color: '#0f172a', lineHeight: 1.25 }}>{valor}</div>
      <div style={{ marginTop: 4, fontSize: 12, color: '#64748b' }}>{rodape}</div>
    </Card>
  );
}

function Cabecalho({ titulo, subtitulo, accao }: { titulo: string; subtitulo?: string; accao?: ReactNode }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
      <div>
        <div style={{ fontSize: 16, fontWeight: 700, color: '#0f172a' }}>{titulo}</div>
        {subtitulo && <div style={{ fontSize: 12, color: '#64748b' }}>{subtitulo}</div>}
      </div>
      {accao}
    </div>
  );
}

/**
 * Plataforma SaaS — painel (plataforma_painel), no desenho do Central Admin: saudação e «Novo cliente», quatro indicadores,
 * evolução e licenças, receita e utilização, clientes recentes, alertas operacionais e actividade recente.
 * A própria plataforma (o tenant dos administradores) nunca conta como cliente.
 */
export default function PlataformaPainel() {
  const navegar = useNavigate();
  const { utilizador } = useSessao();
  const { data, isLoading, refetch, isFetching } = useQuery({ queryKey: ['plataforma', 'painel'], queryFn: () => obter<Painel>('/plataforma/painel'), staleTime: 30_000, refetchInterval: 60_000 });
  const ind = data?.indicadores;
  const primeiro = (utilizador?.nome_completo || utilizador?.nome_utilizador || '').split(' ')[0];
  const moeda = ind?.moeda ?? 'AOA';

  const alertas: { icone: ReactNode; titulo: string; detalhe: string; destino: string }[] = [];
  if (data?.licencas?.expiradas) alertas.push({ icone: <ExclamationCircleOutlined style={{ color: '#ef4444' }} />, titulo: `${data.licencas.expiradas} cliente(s) com a licença expirada`, detalhe: 'Estão em somente leitura; contactar para renovar', destino: '/plataforma/saas' });
  if (ind?.tickets_abertos) alertas.push({ icone: <ClockCircleOutlined style={{ color: '#f59e0b' }} />, titulo: `${ind.tickets_abertos} ticket(s) por resolver`, detalhe: 'Priorizar o suporte ao cliente', destino: '/plataforma/suporte' });
  if (ind?.licencas_a_expirar.length) alertas.push({ icone: <ClockCircleOutlined style={{ color: '#3b82f6' }} />, titulo: `${ind.licencas_a_expirar.length} licença(s) terminam nos próximos 30 dias`, detalhe: ind.licencas_a_expirar.slice(0, 2).map((l) => `${l.cliente} (${formatarData(l.licenca_fim)})`).join(' · '), destino: '/plataforma/saas' });

  return (
    <>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <div>
          <div style={{ fontSize: 12, fontWeight: 700, letterSpacing: '.08em', color: '#3b82f6', textTransform: 'uppercase' }}>Visão geral</div>
          <Typography.Title level={2} style={{ margin: '2px 0 0', fontWeight: 800 }}>{saudacao()}{primeiro ? `, ${primeiro}` : ''}</Typography.Title>
          <Typography.Text type="secondary">Acompanhe a operação da sua plataforma num só lugar.</Typography.Text>
        </div>
        <Space wrap>
          <Button icon={<ReloadOutlined />} loading={isFetching} onClick={() => void refetch()}>Actualizar</Button>
          <Dropdown
            menu={{
              items: [
                { key: 'c', label: 'Clientes (CSV)', onClick: () => void descarregar('/plataforma/relatorios/clientes.csv', undefined, 'clientes.csv') },
                { key: 'e', label: 'Empresas (CSV)', onClick: () => void descarregar('/plataforma/relatorios/empresas.csv', undefined, 'empresas.csv') },
              ],
            }}
          >
            <Button icon={<DownloadOutlined />}>Relatórios</Button>
          </Dropdown>
          <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('/plataforma/clientes?novo=1')}>Novo cliente</Button>
        </Space>
      </div>

      <Row gutter={[16, 16]}>
        <Col xs={12} lg={6}>
          <Indicador carregar={isLoading} rotulo="Clientes activos" valor={data?.clientes.ativos ?? 0} icone={<ApartmentOutlined />} cor="#3b82f6" aoClicar={() => navegar('/plataforma/clientes')}
            rodape={`${data?.clientes.total ?? 0} no total · ${data?.empresas.total ?? 0} empresa(s)`} />
        </Col>
        <Col xs={12} lg={6}>
          <Indicador carregar={isLoading} rotulo="Receita mensal" valor={`${formatarNumero(ind?.mrr ?? 0)} ${moeda}`} icone={<DollarOutlined />} cor="#8b5cf6" rodape="Estimada pelos planos dos clientes activos" />
        </Col>
        <Col xs={12} lg={6}>
          <Indicador carregar={isLoading} rotulo="Tickets abertos" valor={ind?.tickets_abertos ?? 0} icone={<CustomerServiceOutlined />} cor="#f59e0b" aoClicar={() => navegar('/plataforma/suporte')}
            rodape={ind?.tickets_abertos ? 'Por resolver' : <><CheckCircleOutlined /> Sem pendentes</>} />
        </Col>
        <Col xs={12} lg={6}>
          <Indicador carregar={isLoading} rotulo="Sessões activas" valor={data?.sessoes_ativas ?? 0} icone={<DesktopOutlined />} cor="#10b981" aoClicar={() => navegar('/plataforma/sessoes')}
            rodape={`${ind?.utilizadores_ativos_30d ?? 0} utilizadores activos em 30 dias`} />
        </Col>
      </Row>

      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} xl={16}>
          <Card loading={isLoading} style={{ borderRadius: 14, height: '100%' }}>
            <Cabecalho titulo="Clientes novos" subtitulo="Evolução da base de clientes · últimos 12 meses" accao={<Tag bordered={false}>{data?.clientes.total ?? 0} cliente(s)</Tag>} />
            {ind && <GraficoBarras rotulos={ind.meses} series={[{ rotulo: 'Clientes novos', valores: ind.clientes_novos_mes, cor: '#3b82f6' }]} altura={220} />}
          </Card>
        </Col>
        <Col xs={24} xl={8}>
          <Card loading={isLoading} style={{ borderRadius: 14, height: '100%' }}>
            <Cabecalho titulo="Licenças" subtitulo="Estado da licença de cada cliente" accao={<Typography.Link onClick={() => navegar('/plataforma/saas')}>Gerir</Typography.Link>} />
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 10, margin: '14px 0 10px' }}>
              <span style={{ fontSize: 34, fontWeight: 800, color: '#0f172a' }}>{data?.licencas?.total ?? 0}</span>
              <span style={{ color: '#64748b', fontSize: 12 }}>clientes<br />com licença</span>
            </div>
            {[
              ['#10b981', 'Válidas', data?.licencas?.ativas ?? 0],
              ['#8b5cf6', 'Em teste', data?.licencas?.em_teste ?? 0],
              ['#f59e0b', 'Expiradas', data?.licencas?.expiradas ?? 0],
            ].map(([cor, rotulo, n]) => (
              <div key={String(rotulo)} style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderTop: '1px solid #f1f5f9', fontSize: 13 }}>
                <span><i style={{ display: 'inline-block', width: 8, height: 8, borderRadius: '50%', background: String(cor), marginRight: 8 }} />{rotulo}</span>
                <b>{n}</b>
              </div>
            ))}
          </Card>
        </Col>
      </Row>

      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} xl={12}>
          <Card loading={isLoading} style={{ borderRadius: 14 }}>
            <Cabecalho titulo="Receita recebida" subtitulo={`Por mês · últimos 12 meses (${moeda})`} />
            {ind && <GraficoBarras rotulos={ind.meses} series={[{ rotulo: `Receita (${moeda})`, valores: ind.receita_mes, cor: '#14b8a6' }]} monetario altura={220} />}
          </Card>
        </Col>
        <Col xs={24} md={12} xl={6}>
          <Card loading={isLoading} style={{ borderRadius: 14, height: '100%' }}>
            <Cabecalho titulo="Clientes por plano" />
            {ind && ind.por_plano.length > 0 ? (
              <GraficoDonut rotulos={ind.por_plano.map((p) => p.plano)} series={[{ rotulo: 'Clientes', valores: ind.por_plano.map((p) => p.clientes) }]} altura={220} />
            ) : (
              <Empty description="Sem clientes" image={Empty.PRESENTED_IMAGE_SIMPLE} />
            )}
          </Card>
        </Col>
        <Col xs={24} md={12} xl={6}>
          <Card loading={isLoading} style={{ borderRadius: 14, height: '100%' }}>
            <Cabecalho titulo="Acessos" subtitulo="Utilizadores por dia · 30 dias" />
            {ind && <GraficoLinhas rotulos={ind.dias} series={[{ rotulo: 'Utilizadores', valores: ind.acessos_dia, cor: '#0891b2' }]} preenchimento altura={220} />}
          </Card>
        </Col>
      </Row>

      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        <Col xs={24} xl={14}>
          <Card loading={isLoading} style={{ borderRadius: 14, height: '100%' }}>
            <Cabecalho titulo="Clientes recentes" subtitulo="Últimos clientes adicionados" accao={<Typography.Link onClick={() => navegar('/plataforma/clientes')}>Ver todos</Typography.Link>} />
            {(data?.ultimos_clientes ?? []).length === 0 ? (
              <Empty description="Ainda não há clientes. Crie o primeiro." image={Empty.PRESENTED_IMAGE_SIMPLE} style={{ marginTop: 16 }} />
            ) : (
              (data?.ultimos_clientes ?? []).map((c) => (
                <div key={c.id} onClick={() => navegar(`/plataforma/clientes?q=${encodeURIComponent(c.slug)}`)} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 0', borderBottom: '1px solid #f1f5f9', cursor: 'pointer' }}>
                  <Avatar style={{ background: '#eff6ff', color: '#2563eb', fontWeight: 700 }}>{c.nome.slice(0, 1).toUpperCase()}</Avatar>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 600 }}>{c.nome}</div>
                    <div style={{ fontSize: 12, color: '#64748b' }}>{c.slug}{c.plano ? ` · ${c.plano}` : ''}</div>
                  </div>
                  {c.estado && <Tag color={COR_ESTADO[c.estado] ?? 'default'} bordered={false}>{NOME_ESTADO[c.estado] ?? c.estado}</Tag>}
                  <span style={{ fontSize: 12, color: '#64748b', whiteSpace: 'nowrap' }}>{formatarData(c.criado_em)}</span>
                </div>
              ))
            )}
          </Card>
        </Col>
        <Col xs={24} xl={10}>
          <Card loading={isLoading} style={{ borderRadius: 14, height: '100%' }}>
            <Cabecalho titulo="Alertas operacionais" subtitulo="Itens que podem exigir atenção" accao={<BellOutlined style={{ color: '#94a3b8' }} />} />
            {alertas.length === 0 ? (
              <div style={{ marginTop: 16, padding: 14, borderRadius: 10, background: '#ecfdf5', color: '#047857', fontWeight: 600 }}><CheckCircleOutlined /> Tudo sob controlo</div>
            ) : (
              alertas.map((a) => (
                <div key={a.titulo} onClick={() => navegar(a.destino)} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 0', borderBottom: '1px solid #f1f5f9', cursor: 'pointer' }}>
                  <span style={{ fontSize: 18 }}>{a.icone}</span>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 600 }}>{a.titulo}</div>
                    <div style={{ fontSize: 12, color: '#64748b' }}>{a.detalhe}</div>
                  </div>
                  <RightOutlined style={{ color: '#94a3b8', fontSize: 11 }} />
                </div>
              ))
            )}
          </Card>
        </Col>
      </Row>

      <Card loading={isLoading} style={{ borderRadius: 14, marginTop: 16 }}>
        <Cabecalho titulo="Actividade recente" subtitulo="Últimas acções registadas na plataforma" accao={<Typography.Link onClick={() => navegar('/plataforma/auditoria')}>Ver auditoria</Typography.Link>} />
        {(data?.actividade_recente ?? []).length === 0 ? (
          <Empty description="Sem actividade." image={Empty.PRESENTED_IMAGE_SIMPLE} style={{ marginTop: 16 }} />
        ) : (
          (data?.actividade_recente ?? []).map((a, i) => (
            <div key={`${a.ocorrido_em}-${i}`} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 0', borderBottom: i < (data?.actividade_recente?.length ?? 0) - 1 ? '1px solid #f1f5f9' : undefined }}>
              <span style={{ width: 32, height: 32, borderRadius: 10, background: '#eff6ff', color: '#3b82f6', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}><ThunderboltOutlined /></span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontWeight: 600 }}>{a.acao}</div>
                <div style={{ fontSize: 12, color: '#64748b' }}>{a.utilizador ?? 'Sistema'}{a.cliente ? ` · ${a.cliente}` : ''}</div>
              </div>
              <time style={{ fontSize: 12, color: '#64748b', whiteSpace: 'nowrap' }}>{formatarDataHora(a.ocorrido_em)}</time>
            </div>
          ))
        )}
      </Card>
    </>
  );
}
