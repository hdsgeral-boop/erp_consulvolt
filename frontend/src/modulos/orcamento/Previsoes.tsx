import { Alert, Button, Card, Col, DatePicker, Empty, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Table, Tag, Typography, theme } from 'antd';
import { BranchesOutlined, CloudUploadOutlined, DeleteOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { Route, Routes, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { SeccaoFormulario } from '@/componentes/formularios';
import { SeletorProjecto } from '@/modulos/activos/comum/componentes';
import { formatarKz } from '@/utilitarios/formatacao';
import { Kz, rotuloOrc, useRubricas } from './comum/componentes';
import { BotaoImprimir } from '@/componentes/impressao';
import { BarraFiltros } from '@/componentes/responsivo';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { GraficoLinhas } from '@/componentes/graficos/Graficos';
import { totalAnual } from './comum/regras';
import type { Previsao, ResumoPrevisao } from './comum/tipos';
import { larguraModal, useEcra } from '@/componentes/responsivo';
import { pedidoTabela } from './comum/impressao';

const METODOS = [
  { value: 'ORCAMENTO', label: 'Orçamento aprovado do mês' },
  { value: 'TENDENCIA', label: 'Tendência (média dos 3 últimos meses reais)' },
  { value: 'ANO_ANTERIOR', label: 'Mês homólogo + crescimento' },
  { value: 'BRANCO', label: 'Em branco' },
];

/** Orçamento › Previsões deslizantes (ecrã orc_previsoes): 12 meses a partir do último mês fechado, com revisões e publicação. */
export default function Previsoes() {
  return (
    <Routes>
      <Route index element={<EcraPrevisoes />} />
      <Route path=":id" element={<EcraPrevisoes />} />
    </Routes>
  );
}

/** Série de previsões do legado (`chaveDims`): mesmo tipo e mesmas dimensões (UN, CC, projecto). */
export const chaveSerie = (p: Pick<Previsao, 'tipo' | 'unidade_negocio_id' | 'centro_custo_id' | 'projeto_id'>) =>
  `${p.tipo}|${p.unidade_negocio_id ?? ''}|${p.centro_custo_id ?? ''}|${p.projeto_id ?? ''}`;

const dimensoes = (p: Pick<Previsao, 'unidade_negocio_id' | 'centro_custo_id' | 'projeto_id'>) =>
  [p.unidade_negocio_id && `UN ${p.unidade_negocio_id}`, p.centro_custo_id && `CC ${p.centro_custo_id}`, p.projeto_id && `Proj. ${p.projeto_id}`].filter(Boolean).join(' · ') || 'Toda a empresa';

/** Séries (uma por chave, pela ordem da lista) e revisões de uma série (a mais recente primeiro), como no legado. */
export function seriesERevisoes(lista: Previsao[], serie?: string): { series: { chave: string; rotulo: string }[]; revisoes: Previsao[]; serie?: string } {
  const mapa = new Map<string, Previsao>();
  for (const p of lista) if (!mapa.has(chaveSerie(p))) mapa.set(chaveSerie(p), p);
  const series = [...mapa.entries()].map(([chave, p]) => ({ chave, rotulo: `${rotuloOrc(p.tipo)} · ${dimensoes(p)} — ${p.nome ?? 'Previsão'}` }));
  const escolhida = serie && mapa.has(serie) ? serie : series[0]?.chave;
  const revisoes = lista.filter((p) => chaveSerie(p) === escolhida).sort((a, b) => b.revisao - a.revisao);
  return { series, revisoes, serie: escolhida };
}

export const rotuloRevisao = (r: Pick<Previsao, 'revisao' | 'mes_referencia' | 'estado'>) =>
  `Rev. ${r.revisao} · real até ${dayjs(`${r.mes_referencia}-01`).format('MMM YYYY')} · ${r.estado === 'PUBLICADA' ? 'publicada' : 'rascunho'}`;

/**
 * Um só ecrã como no legado (orcamento_planeamento_ui.js:264-379): filtros «Série» e «Revisão» e, por baixo, a revisão
 * escolhida (indicadores, grelha, pressupostos e gráfico). A rota «:id» abre logo essa revisão.
 */
function EcraPrevisoes() {
  const { id: idRota } = useParams();
  const { pode } = useSessao();
  const [nova, setNova] = useState(false);
  const [serie, setSerie] = useState<string>();
  const [id, setId] = useState<number | undefined>(idRota ? Number(idRota) : undefined);
  const q = useQuery({ queryKey: ['orcamento', 'previsoes'], queryFn: () => obter<Previsao[]>('/orcamento/previsoes') });
  const lista = useMemo(() => q.data ?? [], [q.data]);
  // a revisão pedida na rota escolhe a sua série
  const serieDoId = lista.find((p) => p.id === id);
  const { series, revisoes, serie: serieActual } = seriesERevisoes(lista, serie ?? (serieDoId ? chaveSerie(serieDoId) : undefined));
  const idActual = id && revisoes.some((r) => r.id === id) ? id : revisoes[0]?.id;

  return (
    <>
      <CabecalhoPagina titulo="Previsões (rolling forecast)" subtitulo="Previsão deslizante de 12 meses: os meses fechados passam a real e cada revisão mensal fica guardada; estimativa do fecho do ano face ao orçamento aprovado."
        accoes={pode('orc_previsoes_edit') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNova(true)}>Nova série de previsões</Button>} />
      {!q.isLoading && !lista.length ? (
        <Card><Empty description="Ainda não há previsões. Crie uma série (exploração ou tesouraria, da empresa ou por UN/CC/projecto)." /></Card>
      ) : (
        <Card style={{ marginBottom: 12 }}>
          <BarraFiltros>
            <label style={{ flex: 1, minWidth: 280 }}>
              <Typography.Text strong style={{ display: 'block', fontSize: 12 }}>Série</Typography.Text>
              <Select style={{ width: '100%' }} aria-label="Série" loading={q.isLoading} value={serieActual} onChange={(v) => { setSerie(v); setId(undefined); }}
                options={series.map((x) => ({ value: x.chave, label: x.rotulo }))} />
            </label>
            <label style={{ minWidth: 260 }}>
              <Typography.Text strong style={{ display: 'block', fontSize: 12 }}>Revisão</Typography.Text>
              <Select style={{ width: '100%' }} aria-label="Revisão" value={idActual} onChange={setId} options={revisoes.map((r) => ({ value: r.id, label: rotuloRevisao(r) }))} />
            </label>
          </BarraFiltros>
        </Card>
      )}
      {idActual && <Detalhe key={idActual} id={idActual} aoAbrir={(n) => { setId(n ?? undefined); if (n === null) setSerie(undefined); }} />}
      <ModalNova aberto={nova} aoFechar={() => setNova(false)} aoGravar={(p) => { setSerie(chaveSerie(p)); setId(p.id); }} />
    </>
  );
}

function ModalNova({ aberto, aoFechar, aoGravar }: { aberto: boolean; aoFechar: () => void; aoGravar: (p: Previsao) => void }) {
  const [form] = Form.useForm();
  const metodo = Form.useWatch('metodo', form);
  const accao = useAccao<Previsao>({ invalidar: [['orcamento']], aoSucesso: (p) => { aoGravar(p); aoFechar(); } });
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ tipo: 'EXPLORACAO', metodo: 'TENDENCIA', mes_referencia: dayjs().subtract(1, 'month') }); } }, [aberto, form]);
  return (
    <Modal title="Nova previsão" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Criar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(720)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados: Record<string, unknown> = { ...v, mes_referencia: v.mes_referencia.format('YYYY-MM') };
        for (const k of Object.keys(dados)) if (dados[k] === undefined || dados[k] === '') dados[k] = null;
        accao.mutate({ url: '/orcamento/previsoes', dados });
      }}>
        <Row gutter={12}>
          <Col xs={24} md={8}><Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select options={[{ value: 'EXPLORACAO', label: 'Exploração' }, { value: 'TESOURARIA', label: 'Tesouraria' }]} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="mes_referencia" label="Último mês real fechado" rules={[{ required: true }]}><DatePicker picker="month" format="MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="nome" label="Nome"><Input maxLength={255} /></Form.Item></Col>
        </Row>
        {/* Grade com secções: cada série abre logo a sua ficha, por isso não há criação em Linhas */}
        <SeccaoFormulario titulo="Semente dos 12 meses">
          <Row gutter={12}>
            <Col xs={24} md={16}><Form.Item name="metodo" label="Semear com"><Select options={METODOS} /></Form.Item></Col>
            {metodo === 'ANO_ANTERIOR' && <Col xs={24} md={8}><Form.Item name="crescimento_pct" label="Crescimento (%)"><InputNumber min={-100} max={1000} style={{ width: '100%' }} /></Form.Item></Col>}
          </Row>
        </SeccaoFormulario>
        <SeccaoFormulario titulo="Dimensões" recolhida>
          <Row gutter={12}>
            <Col xs={24} md={8}><Form.Item name="unidade_negocio_id" label="Unidade de negócio"><SeletorUnidade style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={8}><Form.Item name="centro_custo_id" label="Centro de custo"><SeletorAux tabela="centros-custo" placeholder="Centro de custo" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={8}><Form.Item name="projeto_id" label="Projecto"><SeletorProjecto allowClear /></Form.Item></Col>
          </Row>
        </SeccaoFormulario>
      </Form>
    </Modal>
  );
}

type Linha = ResumoPrevisao['linhas'][number];

function Detalhe({ id, aoAbrir }: { id: number; aoAbrir: (id: number | null) => void }) {
  const { pode } = useSessao();
  const q = useQuery({ queryKey: ['orcamento', 'previsao', id], queryFn: () => obter<ResumoPrevisao>(`/orcamento/previsoes/${id}`) });
  const [valores, setValores] = useState<Record<number, Record<string, number>>>({});
  const [notas, setNotas] = useState('');
  const [alterado, setAlterado] = useState(false);
  const { telemovel } = useEcra();
  const { token } = theme.useToken();
  const rubricas = useRubricas(q.data?.previsao.tipo);
  const gravar = useAccao({ invalidar: [['orcamento']], aoSucesso: () => setAlterado(false) });
  const accao = useAccao({ invalidar: [['orcamento']] });
  const revisao = useAccao<Previsao>({ invalidar: [['orcamento']], aoSucesso: (p) => aoAbrir(p.id) });
  const eliminar = useAccao({ invalidar: [['orcamento']], aoSucesso: () => aoAbrir(null) });

  useEffect(() => {
    if (!q.data) return;
    setValores(Object.fromEntries(q.data.linhas.map((l) => [l.rubrica_id, Object.fromEntries(q.data.meses.map((m) => [m, Number(l.previsao[m] ?? 0)]))])));
    setNotas(q.data.previsao.notas ?? '');
    setAlterado(false);
  }, [q.data]);

  if (!q.data) return <Card loading={q.isLoading} />;
  const r = q.data;
  const p = r.previsao;
  const editavel = p.estado === 'RASCUNHO' && pode('orc_previsoes_edit');
  const total = (rid: number) => totalAnual(Object.values(valores[rid] ?? {}));
  // fecho estimado com os valores no ecrã: real do ano até ao mês de referência + meses previstos do ano de fecho
  const fecho = (l: Linha) => Number(l.real_ano ?? 0) + Object.entries(valores[l.rubrica_id] ?? {}).filter(([m]) => m.startsWith(`${r.ano_fecho}-`)).reduce((a, [, v]) => a + Number(v ?? 0), 0);
  const tes = p.tipo === 'TESOURARIA';
  const natureza = new Map((rubricas.data ?? []).map((x) => [x.id, x.natureza]));
  const entrada = (rid: number) => ['PROVEITO', 'RECEBIMENTO'].includes(natureza.get(rid) ?? '');
  const desvio = (l: Linha) => (l.orcado === null ? null : (fecho(l) - Number(l.orcado)) * (entrada(l.rubrica_id) ? 1 : -1));
  // indicadores do legado (orcamento_planeamento_ui.js:361-365)
  const somar = (f: (l: Linha) => number, ent: boolean) => r.linhas.filter((l) => entrada(l.rubrica_id) === ent).reduce((a, l) => a + f(l), 0);
  const t12A = somar((l) => total(l.rubrica_id), true);
  const t12B = somar((l) => total(l.rubrica_id), false);
  const fechoRes = somar(fecho, true) - somar(fecho, false);
  const orcRes = somar((l) => Number(l.orcado ?? 0), true) - somar((l) => Number(l.orcado ?? 0), false);
  const rotRes = tes ? 'Fluxo' : 'Resultado';
  const sinal = (rid: number) => (entrada(rid) ? 1 : -1);
  const realRes = r.meses_reais.map((_, i) => r.linhas.reduce((a, l) => a + sinal(l.rubrica_id) * Number(l.real_recente[i] ?? 0), 0));
  const prevRes = r.meses.map((m) => r.linhas.reduce((a, l) => a + sinal(l.rubrica_id) * Number(valores[l.rubrica_id]?.[m] ?? 0), 0));

  const colunas: ColunaApi<Linha>[] = [
    { title: 'Rubrica', key: 'r', fixed: telemovel ? undefined : 'left', width: telemovel ? 170 : 240, render: (_, l) => <><strong>{l.codigo}</strong> {l.nome}</>, valorImpressao: (l) => `${l.codigo} ${l.nome}` },
    ...r.meses_reais.map((m, i) => ({ title: <Typography.Text type="secondary">{dayjs(`${m}-01`).format('MMM YY')} (real)</Typography.Text>, key: `real${i}`, align: 'right' as const, render: (_: unknown, l: Linha) => <Typography.Text type="secondary">{Number(l.real_recente[i] ?? 0).toLocaleString('pt-PT', { maximumFractionDigits: 0 })}</Typography.Text>, valorImpressao: (l: Linha) => Number(l.real_recente[i] ?? 0).toLocaleString('pt-PT', { maximumFractionDigits: 0 }) })),
    ...r.meses.map((m) => ({
      title: dayjs(`${m}-01`).format('MMM YY'), key: m, align: 'right' as const,
      valorImpressao: (l: Linha) => formatarKz(valores[l.rubrica_id]?.[m] ?? 0),
      render: (_: unknown, l: Linha) => editavel
        ? <InputNumber size="small" controls={false} precision={2} style={{ width: 108 }} value={valores[l.rubrica_id]?.[m] ?? 0} onChange={(v) => { setValores((s) => ({ ...s, [l.rubrica_id]: { ...s[l.rubrica_id], [m]: v ?? 0 } })); setAlterado(true); }} />
        : <Kz valor={valores[l.rubrica_id]?.[m] ?? 0} />,
    })),
    { title: 'Próx. 12 meses', key: 't12', align: 'right', render: (_, l) => <Kz valor={total(l.rubrica_id)} forte />, valorImpressao: (l) => formatarKz(total(l.rubrica_id)) },
    { title: `Real ${r.ano_fecho}`, dataIndex: 'real_ano', align: 'right', render: (v) => <Kz valor={v} /> },
    { title: `Fecho estimado ${r.ano_fecho}`, key: 'fecho', align: 'right', render: (_, l) => <Kz valor={fecho(l)} forte />, valorImpressao: (l) => formatarKz(fecho(l)) },
    { title: `Orçado ${r.ano_fecho}`, dataIndex: 'orcado', align: 'right', render: (v) => (v === null ? '—' : <Kz valor={v} />) },
    {
      title: 'Desvio esperado', key: 'desvio', align: 'right', valorImpressao: (l) => { const d = desvio(l); return d === null ? '—' : formatarKz(d); },
      render: (_, l) => { const d = desvio(l); return d === null ? '—' : <span style={{ color: d < 0 ? token.colorError : token.colorSuccess, whiteSpace: 'nowrap' }}>{formatarKz(d)}</span>; },
    },
  ];

  return (
    <>
      {/* cabeçalho da revisão do legado (orcamento_planeamento_ui.js:310-311): nome, «rev. N», estado e, a cinzento, tipo e mês real */}
      <Flex wrap gap={8} justify="space-between" align="center" style={{ marginBottom: 12 }}>
        <Space wrap size={6}>
          <strong style={{ fontSize: 16.8 }}>{p.nome ?? 'Previsão'}</strong>
          <Tag color="blue">rev. {p.revisao}</Tag>
          <Tag color={p.estado === 'PUBLICADA' ? 'green' : 'gold'}>{p.estado === 'PUBLICADA' ? 'Publicada' : 'Rascunho'}</Tag>
          <Typography.Text type="secondary" style={{ fontSize: 13 }}>· {rotuloOrc(p.tipo)} · {dimensoes(p)} · real até {dayjs(`${p.mes_referencia}-01`).format('MMM YYYY')}{p.metodo ? ` · semente: ${METODOS.find((m) => m.value === p.metodo)?.label ?? p.metodo}` : ''}</Typography.Text>
          {alterado && <Typography.Text style={{ color: '#b45309', fontSize: 13 }}>● alterações por gravar</Typography.Text>}
        </Space>
        <Space wrap>
        <BotaoImprimir obterPedido={() => pedidoTabela({
          titulo: 'Previsão deslizante (12 meses)',
          subtitulo: `${p.nome ?? 'Previsão'} · revisão R${p.revisao} · ${p.estado}${alterado ? ' (com alterações por gravar)' : ''}`,
          periodo: `Mês de referência ${dayjs(`${p.mes_referencia}-01`).format('MM/YYYY')} · fecho estimado de ${r.ano_fecho}`,
          filtros: notas ? [`Notas: ${notas}`] : undefined,
          colunas,
          linhas: r.linhas,
        })} />
            {editavel && <Button type="primary" icon={<SaveOutlined />} disabled={!alterado} loading={gravar.isPending}
              onClick={() => gravar.mutate({ metodo: 'put', url: `/orcamento/previsoes/${p.id}`, dados: { linhas: Object.entries(valores).map(([rid, v]) => ({ rubrica_orcamental_id: Number(rid), valores: v })), notas: notas || null } })}>Gravar</Button>}
            {editavel && (
              <Popconfirm title="Publicar a previsão?" description="Uma revisão publicada não se altera nem se elimina." okText="Publicar" cancelText="Cancelar" disabled={alterado} onConfirm={() => accao.mutate({ url: `/orcamento/previsoes/${p.id}/publicar` })}>
                <Button icon={<CloudUploadOutlined />} disabled={alterado}>Publicar</Button>
              </Popconfirm>
            )}
            {p.estado === 'PUBLICADA' && pode('orc_previsoes_edit') && <NovaRevisao aoCriar={(mes) => revisao.mutate({ url: `/orcamento/previsoes/${p.id}/revisao`, dados: { mes_referencia: mes } })} carregando={revisao.isPending} mesActual={p.mes_referencia} />}
            {editavel && (
              <Popconfirm title="Eliminar esta revisão?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/orcamento/previsoes/${p.id}` })}>
                <Button danger icon={<DeleteOutlined />} aria-label="Eliminar revisão" />
              </Popconfirm>
            )}
        </Space>
      </Flex>
      {!r.orcamento_id && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`Não há orçamento aprovado de ${r.ano_fecho} para comparar o fecho estimado.`} />}
      <div style={{ marginBottom: 16 }}>
        <GrelhaKpi larguraMinima={200}>
          <CartaoKpi barra="nenhuma" rotulo={`${rotRes} estimado no fecho de ${r.ano_fecho}`} valor={formatarKz(fechoRes)} corValor={fechoRes < 0 ? token.colorError : undefined}
            secundaria={r.orcamento_id ? `orçado ${formatarKz(orcRes)} · desvio ${formatarKz(fechoRes - orcRes)}` : 'sem orçamento aprovado'} />
          <CartaoKpi barra="nenhuma" rotulo={`${tes ? 'Recebimentos' : 'Proveitos'} · próximos 12 meses`} valor={formatarKz(t12A)} />
          <CartaoKpi barra="nenhuma" rotulo={`${tes ? 'Pagamentos' : 'Custos'} · próximos 12 meses`} valor={formatarKz(t12B)} />
          <CartaoKpi barra="nenhuma" rotulo={`${rotRes} · próximos 12 meses`} valor={formatarKz(t12A - t12B)} corValor={t12A - t12B < 0 ? token.colorError : undefined} />
        </GrelhaKpi>
      </div>
      <Card size="small">
        <Table<Linha> rowKey="rubrica_id" size="small" pagination={false} dataSource={r.linhas} columns={colunas} scroll={{ x: 'max-content', y: 620 }} />
        <Flex vertical gap={4} style={{ marginTop: 12 }}>
          <Typography.Text strong>Pressupostos desta revisão</Typography.Text>
          <Input.TextArea rows={2} maxLength={5000} aria-label="Pressupostos desta revisão" placeholder="Notas da revisão" value={notas} disabled={!editavel} onChange={(e) => { setNotas(e.target.value); setAlterado(true); }} />
        </Flex>
      </Card>
      {/* gráfico do legado (orcamento_planeamento_ui.js:368-377): real recente (contínuo) e previsão dos 12 meses */}
      <Card size="small" style={{ marginTop: 16 }} title={`${tes ? 'Fluxo de caixa' : 'Resultado'}: real recente e previsão 12 meses`}>
        <GraficoLinhas monetario altura={250} preenchimento
          rotulos={[...r.meses_reais, ...r.meses].map((m) => dayjs(`${m}-01`).format('MMM YY'))}
          series={[
            { rotulo: 'Real', cor: '#0f172a', valores: [...realRes, ...r.meses.map(() => null)] },
            { rotulo: 'Previsão', cor: '#1d4ed8', valores: [...realRes.map((v, i) => (i === realRes.length - 1 ? v : null)), ...prevRes] },
          ]} />
      </Card>
    </>
  );
}

function NovaRevisao({ aoCriar, carregando, mesActual }: { aoCriar: (mes: string) => void; carregando: boolean; mesActual: string }) {
  const [aberto, setAberto] = useState(false);
  const [mes, setMes] = useState(dayjs(`${mesActual}-01`).add(1, 'month'));
  return (
    <>
      <Button icon={<BranchesOutlined />} onClick={() => setAberto(true)} loading={carregando}>Nova revisão</Button>
      <Modal title="Nova revisão da previsão" open={aberto} onCancel={() => setAberto(false)} okText="Criar" cancelText="Cancelar" onOk={() => { aoCriar(mes.format('YYYY-MM')); setAberto(false); }}>
        <Typography.Paragraph type="secondary">Copia os meses em comum com a revisão actual e semeia só os meses novos.</Typography.Paragraph>
        <DatePicker picker="month" format="MM/YYYY" value={mes} allowClear={false} onChange={(d) => d && setMes(d)} />
      </Modal>
    </>
  );
}
