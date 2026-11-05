import { Alert, Button, Card, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { InfoCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { GraficoLinhas } from '@/componentes/graficos/Graficos';
import { scrollTabela } from '@/componentes/responsivo';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { PropsSeparador } from '../DetalheProjecto';

export interface IndicadoresEvm {
  bac: number; pv: number | null; ev: number | null; ac: number; cv: number | null; sv: number | null; cpi: number | null; spi: number | null;
  eac: number | null; etc: number | null; vac: number | null; tcpi: number | null;
}

export interface PrestacaoFacturacao {
  id: number; descricao: string | null; montante: string; data_prevista: string | null; retencao_pct: string; retencao: number; estado: 'PLANEADO' | 'FACTURADO' | 'CANCELADO';
  marco: string | null; documento: string | null; valor_facturado: number | null; recebido: number | null; marco_projeto_id: number | null;
}

interface PainelFinanceiro {
  indicadores: Record<string, number | null>;
  evm: IndicadoresEvm;
  receita: { reconhecida: number; sobre_facturacao: number } | null;
  curva_s: { meses: string[]; previsto: number[]; realizado: number[]; faturado: number[] | null } | null;
  custos_por_categoria: { rubrica: string; orcado: string; realizado: string }[];
  plano: PrestacaoFacturacao[];
  facturas_por_associar: { venda_id: number; documento: string; data: string | null; valor: string }[];
  fluxo_previsto: { mes: string; entradas: number; saidas: number; saldo: number }[] | null;
  alertas: { codigo: string; nivel: 'erro' | 'aviso'; texto: string }[];
  ocultos: string[];
}

/** Fórmulas mostradas nas dicas de cada indicador (docs/PROJECTOS_FINANCEIRO.md). */
export const FORMULAS: Record<string, string> = {
  PV: 'PV (valor planeado) = orçamento × % planeado até hoje (curva S prevista).',
  EV: 'EV (valor acrescentado) = orçamento × % de execução das tarefas (progresso ponderado).',
  AC: 'AC (custo real) = custos já registados no projecto (compras, horas, autos, equipamentos).',
  CPI: 'CPI = EV ÷ AC. Abaixo de 1 gasta-se mais do que o trabalho realizado vale.',
  SPI: 'SPI = EV ÷ PV. Abaixo de 1 o projecto está atrasado face ao plano.',
  CV: 'CV = EV − AC (variação de custo). Negativa: derrapagem.',
  SV: 'SV = EV − PV (variação de prazo, em valor). Negativa: atraso.',
  EAC: 'EAC = orçamento ÷ CPI (estimativa do custo no termo, mantendo o rendimento actual).',
  ETC: 'ETC = EAC − AC (quanto falta gastar).',
  VAC: 'VAC = orçamento − EAC (variação prevista no termo). Negativa: excesso previsto.',
  TCPI: 'TCPI = (orçamento − EV) ÷ (orçamento − AC): rendimento exigido ao que falta para cumprir o orçamento.',
};

/** Cor do semáforo de um índice de desempenho (CPI/SPI). */
export function corIndice(v: number | null | undefined): string {
  if (v === null || v === undefined) return '#64748b';
  return v >= 1 ? '#16a34a' : v >= 0.9 ? '#d97706' : '#dc2626';
}

const kz = (v: number | null | undefined) => (v === null || v === undefined ? '—' : formatarKz(v));
const idx = (v: number | null | undefined) => (v === null || v === undefined ? '—' : v.toFixed(2).replace('.', ','));

interface ValoresPrestacao { descricao: string; montante: number; data_prevista?: Dayjs; retencao_pct?: number }

/**
 * Financeiro do projecto: indicadores (orçamento, custo real, facturado, recebido, margem, CPI/SPI), valor acrescentado (EVM),
 * curva S, custos por rubrica, plano de facturação ligado às facturas de venda do projecto e previsão de fluxo de caixa.
 * Indicadores sem dados de origem ficam escondidos, com a explicação no fim.
 */
export function SeparadorFinanceiro({ projecto }: PropsSeparador) {
  const { pode } = useSessao();
  const gerir = pode('proj_gerir', 'proj_revisao');
  const base = `/projetos/${projecto.id}/financeiro`;
  const q = useQuery({ queryKey: ['projectos', 'financeiro', projecto.id], queryFn: () => obter<PainelFinanceiro>(base) });
  const accao = useAccao({ invalidar: [['projectos', 'financeiro'], ['projectos']] });
  const [edicao, setEdicao] = useState<{ linha?: PrestacaoFacturacao } | null>(null);
  const [associar, setAssociar] = useState<PrestacaoFacturacao | null>(null);
  const [vendaId, setVendaId] = useState<number>();
  const [form] = Form.useForm<ValoresPrestacao>();
  const d = q.data;
  const i = d?.indicadores ?? {};
  const e = d?.evm;
  const externo = i.facturado !== null && i.facturado !== undefined;

  const dica = (k: string, texto: string) => <Tooltip title={FORMULAS[k]}><span>{texto} <InfoCircleOutlined style={{ opacity: 0.6 }} /></span></Tooltip>;

  return (
    <Space orientation="vertical" size={16} style={{ width: '100%' }}>
      {(d?.alertas ?? []).map((a) => <Alert key={a.codigo} type={a.nivel === 'erro' ? 'error' : 'warning'} showIcon title={a.texto} />)}
      <GrelhaKpi larguraMinima={220}>
        <CartaoKpi barra="esquerda" cor="#ea580c" rotulo="Orçamento (BAC)" valor={kz(i.orcamento)} unidade="Kz" secundaria={<>Disponível após custos e compromissos: <strong>{kz(i.orcamento_disponivel)}</strong></>} />
        <CartaoKpi barra="esquerda" cor="#dc2626" rotulo="Custo real (AC)" valor={kz(i.custo_real)} unidade="Kz" secundaria={<>{i.consumo_pct ?? 0}% do orçamento · compromissos {kz(i.compromissos)}</>} />
        {externo && <CartaoKpi barra="esquerda" cor="#7c3aed" rotulo="Facturado (sem IVA)" valor={kz(i.facturado)} unidade="Kz" secundaria={<>Venda global {kz(i.venda_global)} · a facturar <strong>{kz(i.saldo_a_facturar)}</strong></>} />}
        {externo && <CartaoKpi barra="esquerda" cor="#0891b2" rotulo="Recebido" valor={kz(i.recebido)} unidade="Kz" secundaria={<>Por receber {kz(i.por_receber)} · retenção {kz(i.retencao)}</>} />}
        {externo && <CartaoKpi barra="esquerda" cor="#16a34a" rotulo="Margem bruta" valor={kz(i.margem_bruta)} unidade="Kz" secundaria={<>{i.margem_bruta_pct !== null && i.margem_bruta_pct !== undefined ? `${i.margem_bruta_pct}% do facturado` : 'Sem facturação ainda'}{i.margem_prevista_pct !== null && i.margem_prevista_pct !== undefined ? ` · prevista ${i.margem_prevista_pct}%` : ''}</>} />}
        {e?.cpi !== null && e?.cpi !== undefined && <CartaoKpi barra="esquerda" cor={corIndice(e.cpi)} rotulo={dica('CPI', 'CPI (custo)')} valor={idx(e.cpi)} secundaria={e.cpi >= 1 ? 'Dentro do orçamento' : 'Gasta-se mais do que o rendimento'} />}
        {e?.spi !== null && e?.spi !== undefined && <CartaoKpi barra="esquerda" cor={corIndice(e.spi)} rotulo={dica('SPI', 'SPI (prazo)')} valor={idx(e.spi)} secundaria={e.spi >= 1 ? 'Dentro do plano' : 'Atrasado face ao plano'} />}
      </GrelhaKpi>

      {e && (
        <Card size="small" title="Valor acrescentado (EVM)" loading={q.isLoading}>
          <Descriptions size="small" bordered column={{ xs: 1, sm: 2, lg: 4 }}>
            <Descriptions.Item label={dica('PV', 'PV')}>{kz(e.pv)}</Descriptions.Item>
            <Descriptions.Item label={dica('EV', 'EV')}>{kz(e.ev)}</Descriptions.Item>
            <Descriptions.Item label={dica('AC', 'AC')}>{kz(e.ac)}</Descriptions.Item>
            <Descriptions.Item label={dica('CV', 'CV')}>{kz(e.cv)}</Descriptions.Item>
            <Descriptions.Item label={dica('SV', 'SV')}>{kz(e.sv)}</Descriptions.Item>
            <Descriptions.Item label={dica('EAC', 'EAC')}>{kz(e.eac)}</Descriptions.Item>
            <Descriptions.Item label={dica('ETC', 'ETC')}>{kz(e.etc)}</Descriptions.Item>
            <Descriptions.Item label={dica('VAC', 'VAC')}>{kz(e.vac)}</Descriptions.Item>
            <Descriptions.Item label={dica('TCPI', 'TCPI')}>{idx(e.tcpi)}</Descriptions.Item>
            {d?.receita && <Descriptions.Item label={<Tooltip title="Receita reconhecida = venda global × % de execução. Sobre-facturação = facturado − reconhecida (positivo: facturou-se mais do que o executado).">Receita reconhecida <InfoCircleOutlined style={{ opacity: 0.6 }} /></Tooltip>}>{kz(d.receita.reconhecida)} ({d.receita.sobre_facturacao >= 0 ? 'sobre' : 'sub'}-facturação {kz(Math.abs(d.receita.sobre_facturacao))})</Descriptions.Item>}
          </Descriptions>
        </Card>
      )}

      {d?.curva_s && (
        <Card size="small" title="Curva S — planeado × real (acumulados)">
          <GraficoLinhas rotulos={d.curva_s.meses} monetario series={[
            { rotulo: 'Planeado (orçamento)', valores: d.curva_s.previsto, cor: '#ea580c' },
            { rotulo: 'Custo real', valores: d.curva_s.realizado, cor: '#dc2626' },
            ...(d.curva_s.faturado ? [{ rotulo: 'Facturação (sem IVA)', valores: d.curva_s.faturado, cor: '#16a34a' }] : []),
          ]} />
        </Card>
      )}

      <Row gutter={[16, 16]}>
        <Col xs={24} xl={10}>
          <Card size="small" title="Custos por rubrica">
            <Table size="small" pagination={false} rowKey="rubrica" scroll={scrollTabela()} dataSource={d?.custos_por_categoria ?? []} columns={[
              { title: 'Rubrica', dataIndex: 'rubrica' },
              { title: 'Orçado', dataIndex: 'orcado', align: 'right', render: (v: string) => formatarKz(v) },
              { title: 'Realizado', dataIndex: 'realizado', align: 'right', render: (v: string) => formatarKz(v) },
              { title: 'Desvio', key: 'dv', align: 'right', render: (_, r) => { const x = Number(r.orcado) - Number(r.realizado); return <Typography.Text type={x < 0 ? 'danger' : undefined}>{formatarKz(x)}</Typography.Text>; } },
            ]} />
          </Card>
        </Col>
        <Col xs={24} xl={14}>
          <Card size="small" title="Plano de facturação" extra={gerir && externo && <Button size="small" type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); setEdicao({}); }}>Nova prestação</Button>}>
            {!externo ? <Typography.Text type="secondary">Projecto interno: não tem facturação a cliente.</Typography.Text> : (
              <Table<PrestacaoFacturacao> size="small" pagination={false} rowKey="id" scroll={scrollTabela()} dataSource={(d?.plano ?? []).filter((l) => l.estado !== 'CANCELADO')} locale={{ emptyText: 'Sem prestações planeadas.' }} columns={[
                { title: 'Prestação', dataIndex: 'descricao', render: (v: string | null, l) => <>{v}{l.marco ? <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>Marco: {l.marco}</Typography.Text> : null}</> },
                { title: 'Prevista', dataIndex: 'data_prevista', responsive: ['md'], render: (v: string | null) => (v ? formatarData(v) : '—') },
                { title: 'Valor', dataIndex: 'montante', align: 'right', render: (v: string) => formatarKz(v) },
                { title: 'Estado', dataIndex: 'estado', render: (s: string, l) => (s === 'FACTURADO' ? <Tag color="green">{l.documento}</Tag> : <Tag>Planeada</Tag>) },
                { title: 'Recebido', dataIndex: 'recebido', align: 'right', responsive: ['lg'], render: (v: number | null) => (v === null ? '—' : formatarKz(v)) },
                {
                  title: '', key: 'a', render: (_, l) => gerir && (
                    <Space size={4} wrap>
                      {l.estado === 'PLANEADO' && <Button size="small" onClick={() => { setVendaId(undefined); setAssociar(l); }}>Associar factura</Button>}
                      {l.estado === 'PLANEADO' && <Button size="small" onClick={() => { form.setFieldsValue({ descricao: l.descricao ?? '', montante: Number(l.montante), data_prevista: l.data_prevista ? dayjs(l.data_prevista) : undefined, retencao_pct: Number(l.retencao_pct) }); setEdicao({ linha: l }); }}>Editar</Button>}
                      {l.estado === 'PLANEADO' && <Popconfirm title="Cancelar a prestação?" okText="Cancelar prestação" cancelText="Voltar" onConfirm={() => accao.mutateAsync({ url: `${base}/plano/${l.id}/cancelar` })}><Button size="small" danger>Cancelar</Button></Popconfirm>}
                      {l.estado === 'FACTURADO' && <Popconfirm title="Desassociar a factura?" okText="Desassociar" cancelText="Voltar" onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `${base}/plano/${l.id}/factura` })}><Button size="small">Desassociar</Button></Popconfirm>}
                    </Space>
                  ),
                },
              ]} />
            )}
            <Typography.Paragraph type="secondary" style={{ margin: '8px 0 0', fontSize: 12 }}>A factura emite-se na Faturação (escolhendo este projecto), com a série e a validação fiscal habituais; aqui associa-se à prestação.</Typography.Paragraph>
          </Card>
        </Col>
      </Row>

      {d?.fluxo_previsto && (
        <Card size="small" title="Previsão de fluxo de caixa do projecto">
          <Table size="small" pagination={false} rowKey="mes" scroll={scrollTabela()} dataSource={d.fluxo_previsto} columns={[
            { title: 'Mês', dataIndex: 'mes' },
            { title: 'Entradas previstas (líq. de retenção)', dataIndex: 'entradas', align: 'right', render: (v: number) => formatarKz(v) },
            { title: 'Saídas previstas (custo orçado)', dataIndex: 'saidas', align: 'right', render: (v: number) => formatarKz(v) },
            { title: 'Saldo acumulado', dataIndex: 'saldo', align: 'right', render: (v: number) => <Typography.Text type={v < 0 ? 'danger' : undefined}>{formatarKz(v)}</Typography.Text> },
          ]} />
        </Card>
      )}

      {(d?.ocultos ?? []).length > 0 && <Alert type="info" showIcon title="Indicadores não apresentados" description={<ul style={{ margin: 0, paddingLeft: 18 }}>{d?.ocultos.map((o) => <li key={o}>{o}</li>)}</ul>} />}

      <Modal open={edicao !== null} title={edicao?.linha ? 'Editar prestação' : 'Nova prestação de facturação'} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onCancel={() => setEdicao(null)} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" initialValues={{ retencao_pct: 0 }} onFinish={(v) => accao.mutateAsync({ metodo: edicao?.linha ? 'put' : 'post', url: edicao?.linha ? `${base}/plano/${edicao.linha.id}` : `${base}/plano`, dados: { ...v, data_prevista: v.data_prevista ? dataApi(v.data_prevista) : null } }).then(() => setEdicao(null))}>
          <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Indique a descrição.' }]}><Input maxLength={200} placeholder="Ex.: Sinal 30 %, Auto n.º 2, Conclusão" /></Form.Item>
          <Form.Item name="montante" label="Valor previsto (sem IVA)" rules={[{ required: true, message: 'Indique o valor.' }]}><InputNumber min={0.01} precision={2} style={{ width: '100%' }} /></Form.Item>
          <Row gutter={12}>
            <Col xs={24} sm={12}><Form.Item name="data_prevista" label="Data prevista"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="retencao_pct" label="Retenção (%)"><InputNumber min={0} max={100} step={0.5} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
        </Form>
      </Modal>

      <Modal open={associar !== null} title={`Associar factura a «${associar?.descricao ?? ''}»`} okText="Associar" cancelText="Cancelar" okButtonProps={{ disabled: !vendaId }} confirmLoading={accao.isPending} onCancel={() => setAssociar(null)}
        onOk={() => associar && vendaId && accao.mutateAsync({ url: `${base}/plano/${associar.id}/factura`, dados: { venda_id: vendaId } }).then(() => setAssociar(null))}>
        {(d?.facturas_por_associar ?? []).length === 0 ? <Alert type="info" showIcon title="Não há facturas do projecto por associar. Emita a factura na Faturação escolhendo este projecto." />
          : <Select style={{ width: '100%' }} placeholder="Escolha a factura" value={vendaId} onChange={setVendaId} options={d?.facturas_por_associar.map((f) => ({ value: f.venda_id, label: `${f.documento} · ${f.data ? formatarData(f.data) : ''} · ${formatarKz(f.valor)} Kz` }))} />}
      </Modal>
    </Space>
  );
}
