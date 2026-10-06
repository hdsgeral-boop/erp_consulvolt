import { Alert, Card, Col, Descriptions, Empty, List, Progress, Row, Space, Statistic, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useRef } from 'react';
import { BotoesExportar } from '@/componentes/impressao';
import { GraficoLinhas } from '@/componentes/graficos/Graficos';
import { COLUNAS_DESCRICOES } from '@/componentes/responsivo';
import { obter } from '@/api/cliente';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { BarraExecucao, rotuloProjectos as rotuloTipo } from '../comum/componentes';
import { rotuloRubrica } from '../comum/regras';
import type { ResumoProjecto } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { scrollTabela } from '@/componentes/responsivo';

/** Resumo e indicadores do projecto (GET /projetos/{id}/resumo). */
export function SeparadorResumo({ projecto, aoIr }: PropsSeparador & { aoIr: (separador: string) => void }) {
  const q = useQuery({ queryKey: ['projectos', 'resumo', projecto.id], queryFn: () => obter<ResumoProjecto>(`/projetos/${projecto.id}/resumo`) });
  const ref = useRef<HTMLDivElement>(null);
  if (q.isLoading || !q.data) return <Card loading />;
  const r = q.data;
  const i = r.indicadores;
  const nome = (x: string | { nome?: string; numero_documento?: string } | null) => (!x ? '—' : typeof x === 'string' ? x : x.nome ?? x.numero_documento ?? '—');

  return (
    <div ref={ref}>
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <div className="imp-nao-imprimir" style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <BotoesExportar
          textoImprimir="Imprimir ficha"
          obterPedido={() =>
            ref.current
              ? {
                  titulo: `Ficha do projecto ${projecto.codigo ?? ''} — ${projecto.nome}`,
                  subtitulo: `${rotuloTipo(projecto.tipo)} · ${rotuloTipo(projecto.estado)}${projecto.cliente ? ` · Cliente: ${projecto.cliente.nome}` : ''}`,
                  periodo: i.prazo.inicio || i.prazo.fim ? `${formatarData(i.prazo.inicio)} a ${formatarData(i.prazo.fim)}` : undefined,
                  conteudo: ref.current,
                }
              : null
          }
        />
      </div>
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
            <Table scroll={scrollTabela()}
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
        <Descriptions size="small" column={COLUNAS_DESCRICOES}>
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
    </div>
  );
}

/** Curva S (acumulado) com o gráfico de linhas comum (eixos, dica, alternância gráfico/tabela e impressão). */
function CurvaS({ meses, series }: { meses: string[]; series: { nome: string; valores: number[]; cor: string }[] }) {
  return <GraficoLinhas rotulos={meses} series={series.map((s) => ({ rotulo: s.nome, valores: s.valores, cor: s.cor }))} monetario />;
}
