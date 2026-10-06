import { Alert, Button, Card, Col, DatePicker, Empty, Form, List, Row, Select, Skeleton, Space, Table, Tabs, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useMemo, useRef, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { scrollTabela } from '@/componentes/responsivo';
import { dataApi, formatarNumero } from '@/utilitarios/formatacao';
import { GraficoBarras } from '@/componentes/graficos/Graficos';
import { CartaoKpi, eNumerico, formatarPorFormato } from './comum/componentes';

interface Opcao {
  id: string;
  nome: string;
}

interface Catalogo {
  modulos: (Opcao & { descricao: string })[];
  presets_a: Opcao[];
  comparacoes: Opcao[];
}

interface Periodo {
  inicio: string;
  fim: string;
  nome: string;
  dias: number;
  rotulo: string;
}

interface Periodos {
  a: Periodo;
  b: Periodo | null;
  duracao_diferente: boolean;
}

interface KpiGestao {
  chave: string;
  rotulo: string;
  formato: string;
  sentido: 'sobe' | 'desce' | 'neutro';
  ajuda?: string;
  actual: boolean;
  casas: number | null;
  a: unknown;
  b: unknown;
  variacao: { abs: unknown; pct: number | null };
  leitura: 'boa' | 'ma' | 'neutra';
}

interface TabelaRel {
  id: string;
  titulo: string;
  chave: string;
  colunas: { id: string; rotulo: string; formato: string | null }[];
  linhas: Record<string, unknown>[];
  coluna_principal: string | null;
}

/** Cores do período A (escuras) e do B (claras), como nos relatórios de gestão do legado (relatorios_gestao.js:809). */
const CORES_A = ['#1d4ed8', '#b45309', '#15803d'];
const CORES_B = ['#93c5fd', '#fcd34d', '#86efac'];

interface GraficoRel {
  id: string;
  titulo: string;
  tipo: string;
  rotulos_a: string[];
  rotulos_b: string[];
  series: { rotulo: string; a: (string | number | null)[]; b: (string | number | null)[] }[];
}

interface RelatorioModulo {
  modulo: Opcao & { descricao: string };
  periodos: Periodos;
  notas: string[];
  kpis: KpiGestao[];
  tabelas: TabelaRel[];
  graficos: GraficoRel[];
}

interface Resumo {
  periodos: Periodos;
  blocos: { modulo: Opcao; kpis: KpiGestao[] }[];
  destaques: (KpiGestao & { modulo: string; modulo_id: string })[];
}

interface ParamsPeriodo {
  preset_a?: string;
  a_inicio?: string;
  a_fim?: string;
  comparacao: string;
  b_inicio?: string;
  b_fim?: string;
}

/** Geral › Relatórios de gestão (relatorios_gestao): período A × B, resumo executivo e 9 módulos (GET /gestao/relatorios/*). */
export default function RelatoriosGestao() {
  const catalogo = useQuery({ queryKey: ['gestao', 'relatorios', 'catalogo'], queryFn: () => obter<Catalogo>('/gestao/relatorios'), staleTime: 3_600_000 });
  const [preset, setPreset] = useState('mes');
  const [datasA, setDatasA] = useState<[Dayjs, Dayjs] | null>(null);
  const [comparacao, setComparacao] = useState('homologo');
  const [datasB, setDatasB] = useState<[Dayjs, Dayjs] | null>(null);
  const [separador, setSeparador] = useState('resumo');
  const areaSeparadores = useRef<HTMLDivElement>(null);

  const params = useMemo<ParamsPeriodo | null>(() => {
    const p: ParamsPeriodo = { comparacao };
    if (preset === 'livre') {
      if (!datasA) return null;
      p.a_inicio = dataApi(datasA[0]);
      p.a_fim = dataApi(datasA[1]);
    } else p.preset_a = preset;
    if (comparacao === 'livre') {
      if (!datasB) return null;
      p.b_inicio = dataApi(datasB[0]);
      p.b_fim = dataApi(datasB[1]);
    }
    return p;
  }, [preset, datasA, comparacao, datasB]);

  const periodos = useQuery({
    queryKey: ['gestao', 'relatorios', 'periodos', params],
    queryFn: () => obter<Periodos>('/gestao/relatorios/periodos', params as unknown as Record<string, unknown>),
    enabled: !!params,
  });

  if (catalogo.isLoading) return <Skeleton active />;
  const cat = catalogo.data;
  if (!cat) return <Alert type="error" showIcon message="Não foi possível carregar os relatórios de gestão." />;

  return (
    <>
      <CabecalhoPagina
        titulo="Relatórios de gestão"
        subtitulo="Indicadores de todos os módulos com comparação de períodos"
        impressaoDesactivada={!params}
        impressao={() => {
          // Imprime o separador activo (resumo executivo ou módulo), com os indicadores, gráficos e tabelas.
          const painel = areaSeparadores.current?.querySelector('.ant-tabs-tabpane-active');
          if (!painel) return null;
          const nome = separador === 'resumo' ? 'Resumo executivo' : cat?.modulos.find((m) => m.id === separador)?.nome ?? separador;
          const p = periodos.data;
          return {
            titulo: `Relatório de gestão — ${nome}`,
            periodo: p ? `A: ${p.a.nome} (${p.a.rotulo})${p.b ? ` · B: ${p.b.nome} (${p.b.rotulo})` : ''}` : undefined,
            filtros: p?.duracao_diferente ? 'Períodos com duração diferente' : undefined,
            conteudo: painel,
          };
        }}
      />
      <Card size="small" style={{ marginBottom: 16 }}>
        <Form layout="inline" style={{ rowGap: 8 }}>
          <Form.Item label="Período A">
            <Select style={{ width: 190 }} value={preset} onChange={setPreset} options={cat.presets_a.map((p) => ({ value: p.id, label: p.nome }))} />
          </Form.Item>
          {preset === 'livre' && (
            <Form.Item>
              <DatePicker.RangePicker format="DD/MM/YYYY" value={datasA} onChange={(v) => setDatasA(v && v[0] && v[1] ? [v[0], v[1]] : null)} />
            </Form.Item>
          )}
          <Form.Item label="Comparar com">
            <Select style={{ width: 230 }} value={comparacao} onChange={setComparacao} options={cat.comparacoes.map((p) => ({ value: p.id, label: p.nome }))} />
          </Form.Item>
          {comparacao === 'livre' && (
            <Form.Item>
              <DatePicker.RangePicker format="DD/MM/YYYY" value={datasB} onChange={(v) => setDatasB(v && v[0] && v[1] ? [v[0], v[1]] : null)} />
            </Form.Item>
          )}
        </Form>
        {periodos.data && (
          <Space wrap style={{ marginTop: 8 }}>
            <Tag color="blue">A: {periodos.data.a.nome} · {periodos.data.a.rotulo}</Tag>
            {periodos.data.b && <Tag>B: {periodos.data.b.nome} · {periodos.data.b.rotulo}</Tag>}
            {periodos.data.duracao_diferente && <Tag color="orange">Períodos com duração diferente — compare com cuidado</Tag>}
          </Space>
        )}
      </Card>
      {!params ? (
        <Alert type="info" showIcon message="Escolha as datas do período." />
      ) : (
        <div ref={areaSeparadores}>
        <Tabs
          activeKey={separador}
          onChange={setSeparador}
          destroyOnHidden
          items={[
            { key: 'resumo', label: 'Resumo executivo', children: <ResumoExecutivo params={params} aoAbrir={setSeparador} /> },
            ...cat.modulos.map((m) => ({ key: m.id, label: m.nome, children: <RelatorioDoModulo modulo={m.id} params={params} /> })),
          ]}
        />
        </div>
      )}
    </>
  );
}

function KpiComparado({ k, temB }: { k: KpiGestao; temB: boolean }) {
  return (
    <CartaoKpi
      rotulo={k.rotulo}
      valor={k.a}
      formato={k.formato}
      casas={k.casas}
      ajuda={k.ajuda || null}
      subtitulo={temB && !k.actual ? `B: ${formatarPorFormato(k.b, k.formato, k.casas)}` : k.actual ? 'Situação actual' : null}
      variacaoPct={temB && !k.actual ? k.variacao.pct : undefined}
      leitura={k.leitura}
    />
  );
}

function ResumoExecutivo({ params, aoAbrir }: { params: ParamsPeriodo; aoAbrir: (modulo: string) => void }) {
  const q = useQuery({ queryKey: ['gestao', 'relatorios', 'resumo', params], queryFn: () => obter<Resumo>('/gestao/relatorios/resumo', params as unknown as Record<string, unknown>) });
  if (q.isLoading) return <Skeleton active />;
  if (q.error || !q.data) return <Alert type="error" showIcon message={(q.error as Error | null)?.message ?? 'Erro ao carregar o resumo.'} />;
  const temB = !!q.data.periodos.b;
  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      {q.data.destaques.length > 0 && (
        <Card size="small" title="Destaques (variações de 20% ou mais)">
          <List
            size="small"
            dataSource={q.data.destaques}
            renderItem={(d) => (
              <List.Item actions={[<Button key="abrir" type="link" size="small" onClick={() => aoAbrir(d.modulo_id)}>Ver módulo</Button>]}>
                <Space wrap>
                  <Tag>{d.modulo}</Tag>
                  <Typography.Text>{d.rotulo}</Typography.Text>
                  <Typography.Text strong style={{ color: d.leitura === 'boa' ? '#389e0d' : d.leitura === 'ma' ? '#cf1322' : undefined }}>
                    {d.variacao.pct !== null && d.variacao.pct > 0 ? '+' : ''}
                    {formatarNumero(d.variacao.pct)}%
                  </Typography.Text>
                  <Typography.Text type="secondary">
                    ({formatarPorFormato(d.b, d.formato, d.casas)} → {formatarPorFormato(d.a, d.formato, d.casas)})
                  </Typography.Text>
                </Space>
              </List.Item>
            )}
          />
        </Card>
      )}
      {q.data.blocos.map((b) => (
        <Card key={b.modulo.id} size="small" title={b.modulo.nome} extra={<Button type="link" size="small" onClick={() => aoAbrir(b.modulo.id)}>Detalhe</Button>}>
          {b.kpis.length ? (
            <Row gutter={[12, 12]}>
              {b.kpis.map((k) => (
                <Col key={k.chave} xs={24} sm={12} lg={8} xl={6}>
                  <KpiComparado k={k} temB={temB} />
                </Col>
              ))}
            </Row>
          ) : (
            <Typography.Text type="secondary">Sem indicadores no resumo.</Typography.Text>
          )}
        </Card>
      ))}
    </Space>
  );
}

function RelatorioDoModulo({ modulo, params }: { modulo: string; params: ParamsPeriodo }) {
  const q = useQuery({ queryKey: ['gestao', 'relatorios', modulo, params], queryFn: () => obter<RelatorioModulo>(`/gestao/relatorios/${modulo}`, params as unknown as Record<string, unknown>) });
  if (q.isLoading) return <Skeleton active />;
  if (q.error || !q.data) return <Alert type="error" showIcon message={(q.error as Error | null)?.message ?? 'Erro ao carregar o relatório.'} />;
  const r = q.data;
  const temB = !!r.periodos.b;
  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
        {r.modulo.descricao}
      </Typography.Paragraph>
      {r.notas.map((n, i) => (
        <Alert key={i} type="info" showIcon message={n} />
      ))}
      <Row gutter={[12, 12]}>
        {r.kpis.map((k) => (
          <Col key={k.chave} xs={24} sm={12} lg={8} xl={6}>
            <KpiComparado k={k} temB={temB} />
          </Col>
        ))}
      </Row>
      {r.graficos.map((g) => (
        <Card key={g.id} size="small">
          <GraficoBarras
            titulo={g.titulo}
            rotulos={g.rotulos_a}
            monetario
            series={g.series.flatMap((s, i) => [
              { rotulo: `${s.rotulo} (A)`, valores: s.a, cor: CORES_A[i % 3] },
              ...(temB && s.b.length ? [{ rotulo: `${s.rotulo} (B)`, valores: s.b, cor: CORES_B[i % 3] }] : []),
            ])}
          />
          {temB && g.rotulos_b.length > 0 && (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
              B corresponde a {g.rotulos_b[0]} … {g.rotulos_b[g.rotulos_b.length - 1]}.
            </Typography.Text>
          )}
        </Card>
      ))}
      <Row gutter={[16, 16]}>
        {r.tabelas.map((t) => (
          <Col key={t.id} xs={24} xl={r.tabelas.length > 1 ? 12 : 24}>
            <Card size="small" title={t.titulo}>
              <Table<Record<string, unknown>>
                size="small"
                rowKey={(l) => String(l[t.chave])}
                dataSource={t.linhas}
                pagination={false}
                scroll={scrollTabela()}
                locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem registos." /> }}
                columns={[
                  ...t.colunas.map((c) => ({
                    title: c.rotulo,
                    dataIndex: c.id,
                    align: eNumerico(c.formato) ? ('right' as const) : undefined,
                    render: (v: unknown) => <span style={{ whiteSpace: 'nowrap' }}>{formatarPorFormato(v, c.formato)}</span>,
                  })),
                  ...(temB && t.coluna_principal
                    ? [
                        { title: 'B', dataIndex: 'b', align: 'right' as const, render: (v: unknown) => formatarPorFormato(v, t.colunas.find((c) => c.id === t.coluna_principal)?.formato) },
                        {
                          title: 'Var. %',
                          dataIndex: 'variacao_pct',
                          align: 'right' as const,
                          render: (v: number | null) => (v === null || v === undefined ? '—' : <span style={{ color: v < 0 ? '#cf1322' : '#389e0d' }}>{v > 0 ? '+' : ''}{formatarNumero(v)}%</span>),
                        },
                      ]
                    : []),
                ]}
              />
            </Card>
          </Col>
        ))}
      </Row>
    </Space>
  );
}
