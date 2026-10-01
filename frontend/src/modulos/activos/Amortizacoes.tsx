import { Alert, Button, Card, Col, DatePicker, Descriptions, Empty, Flex, Input, InputNumber, List, Modal, Row, Space, Statistic, Table, Tabs, Tag, Typography } from 'antd';
import { CalculatorOutlined, CloudUploadOutlined, EyeOutlined, RollbackOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, IndicadorEquilibrio, ValorKz } from '@/modulos/contab/comum/Componentes';
import { paraCentimos } from '@/utilitarios/decimal';
import { useAccao } from '@/componentes/Accoes';
import { formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaActivos } from './comum/componentes';
import { accoesPeriodo, codigoPeriodo, ordenarPeriodos, quotaEditavel, rotuloPeriodo } from './comum/regras';
import type { LinhaPeriodo, MesPendente, PeriodoAmortizacoes, PreVisualizacao, Verificacao } from './comum/tipos';

/** Activos › Amortizações (ecrã activos_amortizacoes): cálculo, quotas manuais, pré-visualização, integração, reabertura e verificação. */
export default function Amortizacoes() {
  return (
    <>
      <CabecalhoPagina titulo="Amortizações" subtitulo="Quotas constantes mensais; integração no diário AM (D 73 / C 18) por conta, unidade de negócio e centro de custo" />
      <Tabs
        destroyInactiveTabPane
        items={[
          { key: 'periodo', label: 'Período', children: <Periodo /> },
          { key: 'pendentes', label: 'Meses em falta', children: <MesesEmFalta /> },
          { key: 'verificacao', label: 'Verificação', children: <VerificacaoAmortizacoes /> },
        ]}
      />
    </>
  );
}

function Periodo() {
  const { pode } = useSessao();
  const [mes, setMes] = useState(dayjs().subtract(1, 'month'));
  const [filtro, setFiltro] = useState('');
  const [quotas, setQuotas] = useState<Record<number, number | null>>({});
  const [previsao, setPrevisao] = useState(false);
  const [reabrir, setReabrir] = useState(false);
  const periodo = codigoPeriodo(mes.year(), mes.month() + 1);
  const q = useQuery({ queryKey: ['activos', 'amortizacoes', 'periodo', periodo], queryFn: () => obter<PeriodoAmortizacoes>('/ativos/amortizacoes', { periodo }) });
  const calcular = useAccao({ invalidar: [['activos']] });
  const quota = useAccao({ invalidar: [['activos', 'amortizacoes']] });
  const p = q.data;
  const acc = accoesPeriodo(p?.estado ?? 'ABERTO', pode);
  const t = filtro.trim().toLowerCase();
  const linhas = (p?.linhas ?? []).filter((l) => !t || `${l.codigo} ${l.descricao} ${l.categoria}`.toLowerCase().includes(t));

  const gravarQuota = (l: LinhaPeriodo) => {
    const v = quotas[l.ativo_imobilizado_id];
    quota.mutate(
      { metodo: 'put', url: '/ativos/amortizacoes/quota', dados: { ativo_imobilizado_id: l.ativo_imobilizado_id, periodo, valor: v ?? 0 } },
      { onSuccess: () => setQuotas(({ [l.ativo_imobilizado_id]: _, ...resto }) => resto) },
    );
  };

  return (
    <Card>
      <Flex gap={12} wrap justify="space-between" align="center" style={{ marginBottom: 16 }}>
        <Space wrap>
          <DatePicker picker="month" format="MM/YYYY" value={mes} onChange={(d) => d && setMes(d)} allowClear={false} />
          {p && <EtiquetaActivos valor={p.estado} />}
          <Input.Search placeholder="Filtrar activos" allowClear onSearch={setFiltro} style={{ width: 220 }} />
        </Space>
        <Space wrap>
          {acc.calcular && (
            <Button icon={<CalculatorOutlined />} loading={calcular.isPending} onClick={() => calcular.mutate({ url: '/ativos/amortizacoes/calcular', dados: { periodos: [periodo] } })}>
              Calcular {rotuloPeriodo(periodo)}
            </Button>
          )}
          {(p?.contagens.RASCUNHO ?? 0) > 0 && <Button icon={<EyeOutlined />} onClick={() => setPrevisao(true)}>Pré-visualizar e integrar</Button>}
          {acc.reabrir && <Button danger icon={<RollbackOutlined />} onClick={() => setReabrir(true)}>Reabrir período</Button>}
        </Space>
      </Flex>
      {p && (
        <Row gutter={16} style={{ marginBottom: 16 }}>
          <Col xs={12} md={6}><Statistic title="Integrado" value={formatarKz(p.totais.integrado)} /></Col>
          <Col xs={12} md={6}><Statistic title="Em rascunho" value={formatarKz(p.totais.rascunho)} /></Col>
          <Col xs={12} md={6}><Statistic title="Por calcular" value={formatarKz(p.totais.por_calcular)} /></Col>
          <Col xs={12} md={6}>
            <Statistic title="Activos" value={p.linhas.length} suffix={<Typography.Text type="secondary" style={{ fontSize: 13 }}>
              {Object.entries(p.contagens).map(([k, n]) => `${n} ${k.replace('_', ' ').toLowerCase()}`).join(' · ')}
            </Typography.Text>} />
          </Col>
        </Row>
      )}
      <Table<LinhaPeriodo>
        rowKey="ativo_imobilizado_id"
        size="small"
        loading={q.isFetching}
        dataSource={linhas}
        scroll={{ x: 'max-content' }}
        pagination={{ defaultPageSize: 50, showSizeChanger: true, showTotal: (n) => `${n} activo(s)` }}
        columns={[
          { title: 'Código', dataIndex: 'codigo', fixed: 'left' },
          { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
          { title: 'Categoria', dataIndex: 'categoria' },
          { title: 'CC', dataIndex: 'centro_custo', render: (v) => v ?? '—' },
          { title: 'Aquisição', dataIndex: 'valor_aquisicao', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Acumulado anterior', dataIndex: 'acumulado_anterior', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Calculada', dataIndex: 'quota_calculada', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
          {
            title: 'Quota', key: 'quota', align: 'right',
            render: (_, l) => {
              const editada = l.ativo_imobilizado_id in quotas;
              if (!acc.quotaManual || !quotaEditavel(l)) return <ValorKz valor={l.quota} forte />;
              return (
                <Space.Compact>
                  <InputNumber
                    size="small"
                    min={0}
                    precision={2}
                    style={{ width: 130 }}
                    value={editada ? quotas[l.ativo_imobilizado_id] : Number(l.quota)}
                    onChange={(v) => setQuotas((s) => ({ ...s, [l.ativo_imobilizado_id]: v }))}
                  />
                  {editada && <Button size="small" type="primary" icon={<SaveOutlined />} loading={quota.isPending} onClick={() => gravarQuota(l)} title="Gravar quota manual" />}
                </Space.Compact>
              );
            },
          },
          {
            title: 'Diferença', key: 'dif', align: 'right',
            render: (_, l) => (paraCentimos(l.quota) !== paraCentimos(l.quota_calculada) ? <Tag color="gold">manual</Tag> : null),
          },
          { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaActivos valor={v} /> },
        ]}
      />
      <ModalPrevisualizar periodo={previsao ? periodo : null} podeIntegrar={pode('activos_amort_integrar')} aoFechar={() => setPrevisao(false)} />
      <ModalReabrir periodos={reabrir ? [periodo] : []} aoFechar={() => setReabrir(false)} />
    </Card>
  );
}

/** Pré-visualização do lançamento de integração (movimentos por activo e linhas agrupadas) e botão para integrar. */
function ModalPrevisualizar({ periodo, podeIntegrar, aoFechar }: { periodo: string | null; podeIntegrar: boolean; aoFechar: () => void }) {
  const q = useQuery({
    queryKey: ['activos', 'amortizacoes', 'previsao', periodo],
    queryFn: () => obter<PreVisualizacao>('/ativos/amortizacoes/pre-visualizacao', { periodo }),
    enabled: !!periodo,
    retry: false,
  });
  const integrar = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  const p = q.data;
  return (
    <Modal
      title={`Integração de ${periodo ? rotuloPeriodo(periodo) : ''}`}
      open={!!periodo}
      onCancel={aoFechar}
      width={960}
      destroyOnClose
      footer={[
        <Button key="c" onClick={aoFechar}>Fechar</Button>,
        podeIntegrar && (
          <Button key="i" type="primary" icon={<CloudUploadOutlined />} disabled={!p} loading={integrar.isPending}
            onClick={() => integrar.mutate({ url: '/ativos/amortizacoes/integrar', dados: { periodos: [periodo] } })}>
            Integrar na contabilidade
          </Button>
        ),
      ]}
    >
      {q.error && <Alert type="warning" showIcon message={(q.error as Error).message} />}
      {p && (
        <>
          <Descriptions size="small" column={3} style={{ marginBottom: 12 }}>
            <Descriptions.Item label="Documento">{p.numero_documento}</Descriptions.Item>
            <Descriptions.Item label="Activos">{p.movimentos.length}</Descriptions.Item>
            <Descriptions.Item label="Total"><ValorKz valor={p.total} forte /></Descriptions.Item>
          </Descriptions>
          <Typography.Title level={5}>Linhas do lançamento</Typography.Title>
          <Table
            size="small"
            rowKey={(_, i) => String(i)}
            pagination={false}
            dataSource={p.linhas}
            columns={[
              { title: 'Conta', dataIndex: 'codigo_conta' },
              { title: 'UN', dataIndex: 'unidade_negocio_id', render: (v, l) => l.unidade_negocio_codigo ?? (v ? `#${v}` : '—') },
              { title: 'CC', dataIndex: 'centro_custo_id', render: (v, l) => l.centro_custo_codigo ?? (v ? `#${v}` : '—') },
              { title: 'Débito', key: 'd', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : '') },
              { title: 'Crédito', key: 'c', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : '') },
            ]}
          />
          <div style={{ margin: '12px 0' }}><IndicadorEquilibrio linhas={p.linhas} /></div>
          <Typography.Title level={5}>Movimentos por activo</Typography.Title>
          <Table
            size="small"
            rowKey="ativo_imobilizado_id"
            pagination={{ pageSize: 10 }}
            dataSource={p.movimentos}
            columns={[
              { title: 'Activo', key: 'a', render: (_, m) => `${m.codigo} — ${m.descricao}` },
              { title: 'Débito', dataIndex: 'conta_debito' },
              { title: 'Crédito', dataIndex: 'conta_credito' },
              { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
            ]}
          />
        </>
      )}
    </Modal>
  );
}

function ModalReabrir({ periodos, aoFechar }: { periodos: string[]; aoFechar: () => void }) {
  const [motivo, setMotivo] = useState('');
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => { setMotivo(''); aoFechar(); } });
  return (
    <Modal
      title={`Reabrir ${periodos.map(rotuloPeriodo).join(', ')}`}
      open={periodos.length > 0}
      onCancel={aoFechar}
      okText="Reabrir"
      cancelText="Cancelar"
      okButtonProps={{ danger: true }}
      confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ url: '/ativos/amortizacoes/reabrir', dados: { periodos, motivo: motivo.trim() || null } })}
    >
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Os lançamentos do período são estornados e os cálculos anulados (incluindo os migrados)." />
      <Input.TextArea rows={3} maxLength={500} placeholder="Motivo (opcional)" value={motivo} onChange={(e) => setMotivo(e.target.value)} />
    </Modal>
  );
}

function MesesEmFalta() {
  const { pode } = useSessao();
  const [ano, setAno] = useState(dayjs().year());
  const pend = useQuery({ queryKey: ['activos', 'amortizacoes', 'pendentes'], queryFn: () => obter<{ por_calcular: MesPendente[]; por_integrar: MesPendente[] }>('/ativos/amortizacoes/pendentes') });
  const noAno = useQuery({
    queryKey: ['activos', 'amortizacoes', 'por-integrar-ano', ano],
    queryFn: () => obter<{ ativo_imobilizado_id: number; codigo: string | null; periodos: string[] }[]>('/ativos/amortizacoes/por-integrar-no-ano', { ano }),
  });
  const calcular = useAccao({ invalidar: [['activos']] });
  const integrar = useAccao({ invalidar: [['activos']] });
  const porCalcular = ordenarPeriodos((pend.data?.por_calcular ?? []).map((m) => m.periodo));
  const porIntegrar = ordenarPeriodos((pend.data?.por_integrar ?? []).map((m) => m.periodo));

  const lista = (titulo: string, itens: MesPendente[] | undefined, accao: React.ReactNode) => (
    <Card title={titulo} extra={accao} size="small">
      {itens?.length ? (
        <List size="small" dataSource={itens} renderItem={(m) => (
          <List.Item extra={<ValorKz valor={m.valor} />}>{rotuloPeriodo(m.periodo)} · {m.ativos} activo(s)</List.Item>
        )} />
      ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nada pendente" />}
    </Card>
  );

  return (
    <>
      <Row gutter={16} style={{ marginBottom: 16 }}>
        <Col xs={24} md={12}>
          {lista('Meses por calcular', pend.data?.por_calcular, pode('activos_amort_calcular') && porCalcular.length > 0 && (
            <Button size="small" icon={<CalculatorOutlined />} loading={calcular.isPending} onClick={() => calcular.mutate({ url: '/ativos/amortizacoes/calcular', dados: { periodos: porCalcular } })}>
              Calcular todos
            </Button>
          ))}
        </Col>
        <Col xs={24} md={12}>
          {lista('Meses calculados por integrar', pend.data?.por_integrar, pode('activos_amort_integrar') && porIntegrar.length > 0 && (
            <Button size="small" type="primary" icon={<CloudUploadOutlined />} loading={integrar.isPending}
              onClick={() => Modal.confirm({ title: `Integrar ${porIntegrar.length} período(s)?`, content: porIntegrar.map(rotuloPeriodo).join(', '), okText: 'Integrar', cancelText: 'Cancelar',
                onOk: () => integrar.mutateAsync({ url: '/ativos/amortizacoes/integrar', dados: { periodos: porIntegrar } }) })}>
              Integrar todos
            </Button>
          ))}
        </Col>
      </Row>
      <Card size="small" title="Activos com quotas do ano por integrar" extra={<InputNumber min={1900} max={2100} value={ano} onChange={(v) => v && setAno(v)} />}>
        <Table
          size="small"
          rowKey="ativo_imobilizado_id"
          loading={noAno.isFetching}
          dataSource={noAno.data}
          pagination={{ pageSize: 20 }}
          columns={[
            { title: 'Activo', dataIndex: 'codigo' },
            { title: 'Períodos', dataIndex: 'periodos', render: (ps: string[]) => ordenarPeriodos(ps).map((x) => <Tag key={x}>{rotuloPeriodo(x)}</Tag>) },
          ]}
        />
      </Card>
    </>
  );
}

function VerificacaoAmortizacoes() {
  const q = useQuery({ queryKey: ['activos', 'amortizacoes', 'verificacao'], queryFn: () => obter<Verificacao>('/ativos/amortizacoes/verificacao') });
  const v = q.data;
  return (
    <Card loading={q.isLoading}>
      {v && (
        <>
          <Alert
            style={{ marginBottom: 16 }}
            type={v.quotas_divergentes.length || v.acumulados_divergentes.length ? 'warning' : 'success'}
            showIcon
            message={`${v.registos} quota(s) verificada(s): ${v.quotas_divergentes.length} divergente(s) do cálculo, ${v.acumulados_divergentes.length} ficha(s) com acumulado diferente do integrado.`}
            description="Diferenças de 1 cêntimo nos registos migrados resultam do arredondamento em vírgula flutuante do sistema antigo (ADR-051); os registos migrados não são alterados."
          />
          <Flex justify="end" style={{ marginBottom: 8 }}>
            <BotaoCsv nome="verificacao-amortizacoes" linhas={v.quotas_divergentes} colunas={[
              { titulo: 'Activo', valor: (l) => l.codigo }, { titulo: 'Período', valor: (l) => l.periodo }, { titulo: 'Registado', valor: (l) => l.registado, numerico: true },
              { titulo: 'Esperado', valor: (l) => l.esperado, numerico: true }, { titulo: 'Diferença', valor: (l) => l.diferenca, numerico: true }, { titulo: 'Integrado', valor: (l) => l.contabilizado },
            ]} />
          </Flex>
          <Table
            size="small"
            rowKey={(l) => `${l.ativo_imobilizado_id}-${l.periodo}`}
            dataSource={v.quotas_divergentes}
            pagination={{ pageSize: 15 }}
            columns={[
              { title: 'Activo', dataIndex: 'codigo' },
              { title: 'Período', dataIndex: 'periodo', render: rotuloPeriodo },
              { title: 'Registado', dataIndex: 'registado', align: 'right', render: (x) => <ValorKz valor={x} /> },
              { title: 'Esperado', dataIndex: 'esperado', align: 'right', render: (x) => <ValorKz valor={x} /> },
              { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (x) => <ValorKz valor={x} forte /> },
              { title: 'Estado', dataIndex: 'contabilizado', render: (c) => <EtiquetaActivos valor={c ? 'INTEGRADO' : 'RASCUNHO'} /> },
            ]}
          />
          {v.acumulados_divergentes.length > 0 && (
            <Table
              style={{ marginTop: 16 }}
              size="small"
              rowKey="ativo_imobilizado_id"
              dataSource={v.acumulados_divergentes}
              pagination={false}
              title={() => <strong>Acumulado da ficha ≠ integrado</strong>}
              columns={[
                { title: 'Activo', dataIndex: 'codigo' },
                { title: 'Na ficha', dataIndex: 'ficha', align: 'right', render: (x) => <ValorKz valor={x} /> },
                { title: 'Integrado', dataIndex: 'derivado', align: 'right', render: (x) => <ValorKz valor={x} /> },
              ]}
            />
          )}
        </>
      )}
    </Card>
  );
}
