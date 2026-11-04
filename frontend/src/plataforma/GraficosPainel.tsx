import { Card, Col, Empty, Row } from 'antd';
import { GraficoBarras, GraficoDonut, GraficoLinhas } from '@/componentes/graficos/Graficos';

/**
 * Séries do painel da plataforma (GET /plataforma/painel › indicadores), acrescentadas ao contrato existente. Todas
 * opcionais: um servidor que ainda não as devolva simplesmente não mostra os gráficos.
 */
export interface SeriesPainel {
  moeda?: string;
  /** últimos 12 meses (rótulos) */
  meses?: string[];
  clientes_novos_mes?: number[];
  receita_mes?: number[];
  /** últimos 30 dias (rótulos) */
  dias?: string[];
  acessos_dia?: number[];
  por_plano?: { plano: string; clientes: number }[];
}

export function temSeries(s: SeriesPainel | undefined): s is SeriesPainel {
  return !!s && (!!s.clientes_novos_mes || !!s.receita_mes || !!s.acessos_dia || !!s.por_plano);
}

/** Gráficos do painel: clientes novos, receita recebida, clientes por plano e acessos por dia. */
export function GraficosPainel({ series, carregar = false }: { series: SeriesPainel | undefined; carregar?: boolean }) {
  if (!temSeries(series)) return null;
  const moeda = series.moeda ?? 'AOA';
  const meses = series.meses ?? [];
  return (
    <>
      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        {series.clientes_novos_mes && (
          <Col xs={24} xl={12}>
            <Card loading={carregar} title="Clientes novos" extra="últimos 12 meses" style={{ height: '100%' }}>
              <GraficoBarras rotulos={meses} series={[{ rotulo: 'Clientes novos', valores: series.clientes_novos_mes, cor: '#3b82f6' }]} altura={220} />
            </Card>
          </Col>
        )}
        {series.receita_mes && (
          <Col xs={24} xl={12}>
            <Card loading={carregar} title="Receita recebida" extra={`por mês (${moeda})`} style={{ height: '100%' }}>
              <GraficoBarras rotulos={meses} series={[{ rotulo: `Receita (${moeda})`, valores: series.receita_mes, cor: '#14b8a6' }]} monetario altura={220} />
            </Card>
          </Col>
        )}
      </Row>
      <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
        {series.por_plano && (
          <Col xs={24} md={12}>
            <Card loading={carregar} title="Clientes por plano" style={{ height: '100%' }}>
              {series.por_plano.length > 0 ? (
                <GraficoDonut rotulos={series.por_plano.map((p) => p.plano)} series={[{ rotulo: 'Clientes', valores: series.por_plano.map((p) => p.clientes) }]} altura={220} />
              ) : (
                <Empty description="Sem clientes" image={Empty.PRESENTED_IMAGE_SIMPLE} />
              )}
            </Card>
          </Col>
        )}
        {series.acessos_dia && (
          <Col xs={24} md={12}>
            <Card loading={carregar} title="Acessos" extra="utilizadores por dia, 30 dias" style={{ height: '100%' }}>
              <GraficoLinhas rotulos={series.dias ?? []} series={[{ rotulo: 'Utilizadores', valores: series.acessos_dia, cor: '#0891b2' }]} preenchimento altura={220} />
            </Card>
          </Col>
        )}
      </Row>
    </>
  );
}
