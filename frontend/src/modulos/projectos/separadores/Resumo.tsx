import { Alert, Card, Col, Descriptions, Empty, List, Progress, Row, Space, Statistic, Table, Typography, theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { BarraExecucao } from '../comum/componentes';
import { rotuloRubrica } from '../comum/regras';
import type { ResumoProjecto } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';

/** Resumo e indicadores do projecto (GET /projetos/{id}/resumo). */
export function SeparadorResumo({ projecto, aoIr }: PropsSeparador & { aoIr: (separador: string) => void }) {
  const q = useQuery({ queryKey: ['projectos', 'resumo', projecto.id], queryFn: () => obter<ResumoProjecto>(`/projetos/${projecto.id}/resumo`) });
  if (q.isLoading || !q.data) return <Card loading />;
  const r = q.data;
  const i = r.indicadores;
  const nome = (x: string | { nome?: string; numero_documento?: string } | null) => (!x ? '—' : typeof x === 'string' ? x : x.nome ?? x.numero_documento ?? '—');

  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      {r.alertas.length > 0 && (
        <Card size="small" title="Alertas">
          <List
            size="small"
            dataSource={r.alertas}
            renderItem={(a) => (
              <List.Item style={{ cursor: a.separador ? 'pointer' : undefined }} onClick={() => a.separador && aoIr(a.separador === 'planeamento' ? 'planeamento' : a.separador)}>
                <Alert style={{ width: '100%' }} type={a.nivel === 'ERRO' ? 'error' : a.nivel === 'AVISO' ? 'warning' : 'info'} showIcon message={a.titulo} description={a.texto || undefined} />
              </List.Item>
            )}
          />
        </Card>
      )}
      <Row gutter={[16, 16]}>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Execução física" value={i.execucao} suffix="%" />{i.execucao_ponderada !== null && <Typography.Text type="secondary">Ponderada: {i.execucao_ponderada}%</Typography.Text>}</Card></Col>
        <Col xs={12} md={6}>
          <Card size="small">
            <Statistic title="Prazo decorrido" value={i.prazo.tempo_pct ?? 0} suffix="%" />
            <Typography.Text type="secondary">{formatarData(i.prazo.inicio)} → {formatarData(i.prazo.fim)} · faltam {i.prazo.restantes_dias ?? '—'} dia(s)</Typography.Text>
          </Card>
        </Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Orçamento (Kz)" value={formatarKz(i.orcamento)} /><Typography.Text type="secondary">Compromissos: {formatarKz(i.compromissos)}</Typography.Text></Card></Col>
        <Col xs={12} md={6}>
          <Card size="small">
            <Statistic title="Custo realizado (Kz)" value={formatarKz(i.custo)} valueStyle={{ color: Number(i.disponivel) < 0 ? '#cf1322' : undefined }} />
            <Typography.Text type="secondary">Disponível: {formatarKz(i.disponivel)}{i.consumo_pct !== null ? ` · consumo ${i.consumo_pct}%` : ''}</Typography.Text>
          </Card>
        </Col>
      </Row>
      <Row gutter={[16, 16]}>
        <Col xs={24} lg={14}>
          <Card size="small" title="Curva S (acumulado)">
            <CurvaS meses={r.curva_s.meses} series={[
              { nome: 'Previsto', valores: r.curva_s.previsto, cor: '#94a3b8' },
              { nome: 'Realizado', valores: r.curva_s.realizado, cor: '#ef4444' },
              ...(r.curva_s.faturado ? [{ nome: 'Facturado', valores: r.curva_s.faturado, cor: '#10b981' }] : []),
            ]} />
          </Card>
        </Col>
        <Col xs={24} lg={10}>
          <Card size="small" title="Orçado vs realizado por rubrica">
            <Table
              size="small"
              rowKey="rubrica"
              pagination={false}
              dataSource={r.orcado_realizado}
              columns={[
                { title: 'Rubrica', dataIndex: 'rubrica', render: rotuloRubrica },
                { title: 'Orçado', dataIndex: 'orcado', align: 'right', render: (v) => <ValorKz valor={v} /> },
                { title: 'Realizado', dataIndex: 'realizado', align: 'right', render: (v, l) => <Typography.Text type={Number(v) > Number(l.orcado) ? 'danger' : undefined}>{formatarKz(v)}</Typography.Text> },
              ]}
            />
          </Card>
        </Col>
      </Row>
      <Row gutter={[16, 16]}>
        <Col xs={24} md={8}>
          <Card size="small" title="Execução por milestone">
            {r.execucao_por_marco.length ? r.execucao_por_marco.map((m) => (
              <div key={m.marco} style={{ marginBottom: 8 }}>
                <Typography.Text>{m.marco} <Typography.Text type="secondary">({m.tarefas} tarefa(s))</Typography.Text></Typography.Text>
                <BarraExecucao valor={m.execucao} largura={260} />
              </div>
            )) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />}
          </Card>
        </Col>
        <Col xs={24} md={8}>
          <Card size="small" title="Tarefas por estado">
            {Object.entries(i.estados).map(([e, n]) => (
              <div key={e} style={{ display: 'flex', justifyContent: 'space-between' }}><span>{e.replace('_', ' ').toLowerCase()}</span><strong>{n}</strong></div>
            ))}
            <Progress percent={i.tarefas ? Math.round(((i.estados.CONCLUIDA ?? 0) / i.tarefas) * 100) : 0} size="small" format={(p) => `${p}% concluídas`} />
          </Card>
        </Col>
        <Col xs={24} md={8}>
          <Card size="small" title="Origem dos custos">
            {Object.keys(r.origem_custos).length ? Object.entries(r.origem_custos).map(([k, v]) => (
              <div key={k} style={{ display: 'flex', justifyContent: 'space-between' }}><span>{rotuloRubrica(k)}</span><ValorKz valor={v} /></div>
            )) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />}
            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8 }}>
              Contabilidade: proveitos {formatarKz(r.contabilidade.proveitos)} · custos {formatarKz(r.contabilidade.custos)} ({r.contabilidade.linhas} linha(s))
            </Typography.Text>
          </Card>
        </Col>
      </Row>
      <Card size="small" title="Ficha">
        <Descriptions size="small" column={{ xs: 1, md: 3 }}>
          <Descriptions.Item label="Cliente">{nome(r.ficha.cliente)}</Descriptions.Item>
          <Descriptions.Item label="Encomenda">{nome(r.ficha.encomenda)}</Descriptions.Item>
          <Descriptions.Item label="Unidade de negócio">{r.ficha.unidade_negocio ? `${r.ficha.unidade_negocio.codigo ?? ''} ${r.ficha.unidade_negocio.nome}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Centro de custo">{r.ficha.centro_custo ? `${r.ficha.centro_custo.codigo} — ${r.ficha.centro_custo.descricao ?? ''}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Equipa">{r.ficha.membros} membro(s), {r.ficha.internos} interno(s)</Descriptions.Item>
          <Descriptions.Item label="Horas">{r.ficha.horas_dia_alocadas} h/dia alocadas · {r.ficha.horas_lancadas} h lançadas</Descriptions.Item>
          <Descriptions.Item label="Milestones">{r.ficha.marcos}</Descriptions.Item>
          <Descriptions.Item label="Autos de medição">{r.ficha.revisoes}</Descriptions.Item>
          <Descriptions.Item label="Posições no organigrama">{r.ficha.posicoes_organigrama}</Descriptions.Item>
        </Descriptions>
      </Card>
    </Space>
  );
}

/** Gráfico de linhas simples em SVG (sem dependências) para a curva S. */
function CurvaS({ meses, series }: { meses: string[]; series: { nome: string; valores: number[]; cor: string }[] }) {
  const { token } = theme.useToken();
  if (!meses.length) return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />;
  const L = 600, A = 220, m = { e: 70, d: 10, t: 10, b: 30 };
  const max = Math.max(1, ...series.flatMap((s) => s.valores));
  const x = (i: number) => m.e + (meses.length === 1 ? (L - m.e - m.d) / 2 : (i * (L - m.e - m.d)) / (meses.length - 1));
  const y = (v: number) => A - m.b - (v / max) * (A - m.t - m.b);
  const curto = new Intl.NumberFormat('pt-PT', { notation: 'compact', maximumFractionDigits: 1 });
  return (
    <div>
      <svg viewBox={`0 0 ${L} ${A}`} style={{ width: '100%', height: 'auto' }} role="img" aria-label="Curva S">
        {[0, 0.25, 0.5, 0.75, 1].map((f) => (
          <g key={f}>
            <line x1={m.e} x2={L - m.d} y1={y(max * f)} y2={y(max * f)} stroke={token.colorSplit} />
            <text x={m.e - 6} y={y(max * f) + 4} fontSize={11} textAnchor="end" fill={token.colorTextSecondary}>{curto.format(max * f)}</text>
          </g>
        ))}
        {meses.map((mes, i) => (
          <text key={mes + i} x={x(i)} y={A - 10} fontSize={11} textAnchor="middle" fill={token.colorTextSecondary}>{mes}</text>
        ))}
        {series.map((s) => (
          <g key={s.nome}>
            <polyline fill="none" stroke={s.cor} strokeWidth={2} points={s.valores.map((v, i) => `${x(i)},${y(v)}`).join(' ')} />
            {s.valores.map((v, i) => <circle key={i} cx={x(i)} cy={y(v)} r={3} fill={s.cor}><title>{`${s.nome} ${meses[i]}: ${formatarKz(v)} Kz`}</title></circle>)}
          </g>
        ))}
      </svg>
      <Space size="large">
        {series.map((s) => <span key={s.nome}><span style={{ display: 'inline-block', width: 12, height: 3, background: s.cor, marginRight: 6, verticalAlign: 'middle' }} />{s.nome}</span>)}
      </Space>
    </div>
  );
}
