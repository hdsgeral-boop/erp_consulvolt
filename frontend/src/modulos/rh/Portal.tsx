import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Popconfirm, Result, Row, Select, Space, Statistic, Table, Tabs, Typography } from 'antd';
import { MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import {
  PARENTESCOS, TIPOS_PEDIDO, type Dependente, type MinhaAusencia, type ModeloDocumento, type PedidoPortal, type ResultadoSalarial, type ResumoPortal, type TipoAusencia, type TipoPedido,
} from './api';
import { PortalAvaliacao } from './comum/PortalAvaliacao';
import { AreaImpressao, BotaoImprimir, EstadoTag } from './comum/componentes';
import { useAccaoRh, useAvisarErro } from './comum/consultas';
import { DocumentoImpresso, EtapasPedido, resumoPedido } from './comum/Pedidos';
import { AMinhaEquipa } from './comum/AMinhaEquipa';
import { ReciboSalario } from './comum/ReciboSalario';
import { pedidoPendente } from './comum/regras';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';

/** Portal do Colaborador (ecrã rh_portal): os próprios dados, recibos, pedidos e aprovações da equipa. */
export default function Portal() {
  const resumo = useQuery({ queryKey: ['rh', 'portal', 'resumo'], queryFn: () => obter<ResumoPortal>('/rh/portal/resumo'), retry: false });
  if (resumo.isLoading) return <Card loading />;
  if (resumo.error || !resumo.data) {
    const msg = resumo.error instanceof ErroApi ? resumo.error.message : 'Não foi possível abrir o portal.';
    return <Result status="info" title="Portal do Colaborador" subTitle={msg} />;
  }
  const r = resumo.data;
  return (
    <>
      <CabecalhoPagina titulo={`Olá, ${r.colaborador.nome_completo.split(' ')[0]}`} subtitulo="Portal do Colaborador" />
      <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
        <Col xs={12} md={6}><Card><Statistic title={`Saldo de férias ${r.ano}`} value={r.ferias.saldo} suffix={`/ ${r.ferias.direito} dias`} /></Card></Col>
        <Col xs={12} md={6}><Card><Statistic title="Faltas por justificar" value={r.faltas_por_justificar} valueStyle={{ color: r.faltas_por_justificar ? '#cf1322' : undefined }} /></Card></Col>
        <Col xs={12} md={6}><Card><Statistic title="Pedidos pendentes" value={r.pedidos_pendentes} /></Card></Col>
        <Col xs={12} md={6}><Card><Statistic title="Aprovações para mim" value={r.aprovacoes_para_mim} valueStyle={{ color: r.aprovacoes_para_mim ? '#d48806' : undefined }} /></Card></Col>
      </Row>
      <Tabs items={[
        { key: 'pedidos', label: 'Os meus pedidos', children: <MeusPedidos resumo={r} /> },
        { key: 'recibos', label: 'Recibos', children: <Recibos resumo={r} /> },
        { key: 'faltas', label: r.faltas_por_justificar ? `Faltas (${r.faltas_por_justificar})` : 'Faltas', children: <MinhasFaltas /> },
        { key: 'avaliacao', label: 'A minha avaliação', children: <PortalAvaliacao /> },
        { key: 'equipa', label: 'A minha equipa', children: <AMinhaEquipa /> },
        { key: 'aprovacoes', label: `Aprovações (${r.aprovacoes_para_mim})`, children: <Aprovacoes /> },
        {
          key: 'dados',
          label: 'Os meus dados',
          children: (
            <Card>
              <Descriptions column={{ xs: 1, md: 2 }} size="small">
                <Descriptions.Item label="Nome">{r.colaborador.nome_completo}</Descriptions.Item>
                <Descriptions.Item label="NIF">{r.colaborador.nif}</Descriptions.Item>
                <Descriptions.Item label="Estado"><EstadoTag estado={r.colaborador.estado} /></Descriptions.Item>
                <Descriptions.Item label="Admissão">{formatarData(r.colaborador.data_admissao)}</Descriptions.Item>
                <Descriptions.Item label="Férias marcadas">{r.ferias.marcados} dias</Descriptions.Item>
              </Descriptions>
              <MeusDependentes />
              <Typography.Paragraph type="secondary" style={{ marginTop: 12 }}>Para alterar o agregado familiar faça um pedido «Alteração do agregado familiar»; outras alterações pedem-se ao RH.</Typography.Paragraph>
            </Card>
          ),
        },
      ]} />
    </>
  );
}

function MeusPedidos({ resumo }: { resumo: ResumoPortal }) {
  const { empresa } = useSessao();
  const [tipo, setTipo] = useState<TipoPedido | null>(null);
  const [documento, setDocumento] = useState<PedidoPortal | null>(null);
  const [form] = Form.useForm();
  const q = useQuery({ queryKey: ['rh', 'portal', 'meus-pedidos'], queryFn: () => obter<PedidoPortal[]>('/rh/portal/meus-pedidos') });
  useAvisarErro(q.error);
  const catalogo = useQuery({ queryKey: ['rh', 'assiduidade', 'tipos-ausencia'], queryFn: () => obter<Record<string, TipoAusencia>>('/rh/assiduidade/tipos-ausencia'), staleTime: Infinity, enabled: tipo === 'AUSENCIA' });
  const modelos = useQuery({ queryKey: ['rh', 'portal', 'modelos'], queryFn: () => obter<{ modelos: ModeloDocumento[] }>('/rh/portal/modelos'), enabled: tipo === 'DOCUMENTO' });
  const accao = useAccaoRh(() => setTipo(null));

  const enviarPedido = (v: Record<string, unknown>) => {
    const p = v.periodo as [Dayjs, Dayjs] | undefined;
    const dados: Record<string, unknown> = { ...v, tipo, periodo: undefined };
    if (p) { dados.data_inicio = dataApi(p[0]); dados.data_fim = dataApi(p[1]); }
    if (tipo === 'AGREGADO') dados.dependentes = ((v.dependentes as Record<string, unknown>[] | undefined) ?? []).map((d) => ({ ...d, data_nascimento: dataApi((d.data_nascimento as Dayjs | null) ?? null) ?? null }));
    accao.mutate({ metodo: 'post', url: '/rh/portal/pedidos', dados });
  };

  const colunas: ColumnsType<PedidoPortal> = [
    { title: 'N.º', dataIndex: 'id', render: (v: number) => `#${v}` },
    { title: 'Tipo', dataIndex: 'tipo', render: (t: TipoPedido) => TIPOS_PEDIDO[t] ?? t },
    { title: 'Resumo', render: (_, p) => resumoPedido(p) },
    { title: 'Pedido em', dataIndex: 'criado_em', responsive: ['md'], render: formatarData },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    {
      title: '',
      key: 'accoes',
      render: (_, p) => (
        <Space size={4} wrap>
          {p.documento && <Button size="small" onClick={() => setDocumento(p)}>Ver documento</Button>}
          {pedidoPendente(p) && (
            <Popconfirm title="Cancelar o pedido?" okText="Cancelar pedido" cancelText="Voltar" onConfirm={() => accao.mutateAsync({ metodo: 'post', url: `/rh/portal/pedidos/${p.id}/cancelar` })}>
              <Button size="small" danger>Cancelar</Button>
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  return (
    <Card>
      <Space wrap style={{ marginBottom: 12 }}>
        {(Object.keys(TIPOS_PEDIDO) as TipoPedido[]).map((t) => <Button key={t} icon={<PlusOutlined />} onClick={() => { form.resetFields(); setTipo(t); }}>{TIPOS_PEDIDO[t]}</Button>)}
      </Space>
      <Table<PedidoPortal> rowKey="id" size="small" loading={q.isFetching} columns={colunas} dataSource={q.data ?? []} pagination={{ pageSize: 20 }} scroll={scrollTabela()}
        expandable={{ expandedRowRender: (p) => <EtapasPedido pedido={p} /> }} />

      <Modal title={tipo ? `Pedido — ${TIPOS_PEDIDO[tipo]}` : ''} open={tipo !== null} width={larguraModal(640)} onCancel={() => setTipo(null)} okText="Enviar pedido" cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={enviarPedido}>
          {tipo === 'FERIAS' && (
            <>
              <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`Saldo disponível: ${resumo.ferias.saldo} dias úteis. As férias não podem começar no passado.`} />
              <Form.Item name="periodo" label="Período" rules={[{ required: true, message: 'Indique as datas.' }]}><DatePicker.RangePicker format="DD/MM/YYYY" disabledDate={(d) => d.isBefore(dayjs(), 'day')} style={{ width: '100%' }} /></Form.Item>
              <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
            </>
          )}
          {tipo === 'AUSENCIA' && (
            <>
              <Form.Item name="ausencia_tipo" label="Tipo (Lei 12/23)" rules={[{ required: true }]}>
                <Select showSearch optionFilterProp="label" loading={catalogo.isLoading} options={Object.entries(catalogo.data ?? {}).map(([k, t]) => ({ value: k, label: t.nome }))} />
              </Form.Item>
              <Form.Item name="periodo" label="Período" rules={[{ required: true }]}><DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
              <Form.Item name="horas" label="Horas (só nos tipos em horas)"><InputNumber min={0.5} step={0.5} style={{ width: '100%' }} /></Form.Item>
              <Form.Item name="motivo" label="Motivo" rules={[{ required: true }]}><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
              <Form.Item name="documento_url" label="Documento de prova (ligação)"><Input maxLength={1000} /></Form.Item>
            </>
          )}
          {tipo === 'DOCUMENTO' && (
            <>
              <Form.Item name="documento" label="Documento" rules={[{ required: true }]}>
                <Select loading={modelos.isLoading} options={(modelos.data?.modelos ?? []).filter((m) => m.ativo).map((m) => ({ value: m.codigo, label: m.nome }))} />
              </Form.Item>
              <Form.Item name="finalidade" label="Finalidade"><Input maxLength={500} /></Form.Item>
              <Form.Item name="destinatario" label="Entidade destinatária"><Input maxLength={255} /></Form.Item>
              <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
            </>
          )}
          {tipo === 'AGREGADO' && (
            <>
              <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Indique o agregado completo (substitui o registado depois da aprovação do RH)." />
              <Form.List name="dependentes">
                {(campos, { add, remove }) => (
                  <>
                    {campos.map(({ key, name }) => (
                      <Row gutter={8} key={key} align="middle">
                        <Col xs={24} sm={12} md={8}><Form.Item name={[name, 'nome']} rules={[{ required: true, message: 'Nome.' }]}><Input placeholder="Nome" /></Form.Item></Col>
                        <Col xs={24} sm={12} md={5}><Form.Item name={[name, 'parentesco']}><Select placeholder="Parentesco" options={PARENTESCOS} /></Form.Item></Col>
                        <Col xs={24} sm={12} md={6}><Form.Item name={[name, 'data_nascimento']}><DatePicker format="DD/MM/YYYY" placeholder="Nascimento" style={{ width: '100%' }} /></Form.Item></Col>
                        <Col xs={24} sm={12} md={4}><Form.Item name={[name, 'dependente_fiscal']} valuePropName="checked"><Checkbox>Fiscal</Checkbox></Form.Item></Col>
                        <Col xs={24} sm={12} md={1}><Form.Item><MinusCircleOutlined onClick={() => remove(name)} aria-label="Retirar" /></Form.Item></Col>
                      </Row>
                    ))}
                    <Button type="dashed" icon={<PlusOutlined />} onClick={() => add()}>Acrescentar</Button>
                  </>
                )}
              </Form.List>
              <Form.Item name="observacoes" label="Observações" style={{ marginTop: 12 }}><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
            </>
          )}
        </Form>
      </Modal>

      <Modal title="Documento emitido" open={documento !== null} width={larguraModal(820)} onCancel={() => setDocumento(null)} footer={<Space wrap><BotaoImprimir /><Button onClick={() => setDocumento(null)}>Fechar</Button></Space>}>
        {documento?.documento && <AreaImpressao><DocumentoImpresso documento={documento.documento} empresa={empresa?.nome} /></AreaImpressao>}
      </Modal>
    </Card>
  );
}

function Recibos({ resumo }: { resumo: ResumoPortal }) {
  const { empresa } = useSessao();
  const q = useQuery({ queryKey: ['rh', 'portal', 'recibos'], queryFn: () => obter<ResultadoSalarial[]>('/rh/portal/recibos') });
  useAvisarErro(q.error);
  const [ver, setVer] = useState<ResultadoSalarial | null>(null);
  return (
    <Card>
      <Table<ResultadoSalarial> rowKey={(r) => r.numero_recibo ?? String(r.periodo_processamento_salarial_id)} size="small" loading={q.isFetching} dataSource={q.data ?? []} pagination={{ pageSize: 12 }} scroll={scrollTabela()}
        onRow={(r) => ({ onClick: () => setVer(r), style: { cursor: 'pointer' } })}
        columns={[
          { title: 'Mês', dataIndex: 'mes_ano', render: (v: string) => <strong>{v}</strong> },
          { title: 'N.º do recibo', dataIndex: 'numero_recibo', responsive: ['md'] },
          { title: 'Bruto', dataIndex: 'bruto', align: 'right', render: (v: string) => formatarKz(v) },
          { title: 'Descontos', align: 'right', render: (_, r) => formatarKz(Number(r.inss_trabalhador) + Number(r.irt) + Number(r.descontos)) },
          { title: 'Líquido', dataIndex: 'liquido', align: 'right', render: (v: string) => <strong>{formatarKz(v)}</strong> },
        ]} />
      <Modal title={`Recibo ${ver?.mes_ano ?? ''}`} open={ver !== null} width={larguraModal(820)} onCancel={() => setVer(null)} footer={<Space wrap><BotaoImprimir /><Button onClick={() => setVer(null)}>Fechar</Button></Space>}>
        {ver && (
          <AreaImpressao>
            <ReciboSalario vias={1} resultado={ver} mesAno={ver.mes_ano ?? ''} empresa={{ nome: empresa?.nome, nif: empresa?.nif ?? null }} colaborador={{ nome: resumo.colaborador.nome_completo, nif: resumo.colaborador.nif }} />
          </AreaImpressao>
        )}
      </Modal>
    </Card>
  );
}

/** Pedidos à espera do utilizador (chefia directa; o RH decide em «Pedidos do Portal»). */
function Aprovacoes() {
  const q = useQuery({ queryKey: ['rh', 'portal', 'aprovacoes'], queryFn: () => obter<PedidoPortal[]>('/rh/portal/aprovacoes') });
  useAvisarErro(q.error);
  const [decidir, setDecidir] = useState<PedidoPortal | null>(null);
  const [form] = Form.useForm<{ decisao: 'APROVADO' | 'RECUSADO'; nota?: string }>();
  const decisao = Form.useWatch('decisao', form);
  const accao = useAccaoRh(() => setDecidir(null));
  return (
    <Card>
      <Table<PedidoPortal> rowKey="id" size="small" loading={q.isFetching} dataSource={q.data ?? []} pagination={false} scroll={scrollTabela()}
        columns={[
          { title: 'N.º', dataIndex: 'id', render: (v: number) => `#${v}` },
          { title: 'Pedido por', dataIndex: 'criado_por' },
          { title: 'Tipo', dataIndex: 'tipo', render: (t: TipoPedido) => TIPOS_PEDIDO[t] },
          { title: 'Resumo', render: (_, p) => resumoPedido(p) },
          { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
          { title: '', key: 'a', render: (_, p) => p.tipo !== 'DOCUMENTO' && <Button size="small" type="primary" onClick={() => { form.resetFields(); form.setFieldsValue({ decisao: 'APROVADO' }); setDecidir(p); }}>Decidir</Button> },
        ]}
        expandable={{ expandedRowRender: (p) => <EtapasPedido pedido={p} /> }} />
      <Modal title={`Decidir pedido #${decidir?.id ?? ''}`} open={decidir !== null} onCancel={() => setDecidir(null)} okText="Confirmar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        {decidir && <Typography.Paragraph>{TIPOS_PEDIDO[decidir.tipo]} — {resumoPedido(decidir)}</Typography.Paragraph>}
        <Form form={form} layout="vertical" onFinish={(v) => decidir && accao.mutate({ metodo: 'post', url: `/rh/portal/pedidos/${decidir.id}/decidir`, dados: v })}>
          <Form.Item name="decisao" label="Decisão"><Select options={[{ value: 'APROVADO', label: 'Aprovar' }, { value: 'RECUSADO', label: 'Recusar' }]} /></Form.Item>
          <Form.Item name="nota" label="Nota" rules={[{ required: decisao === 'RECUSADO', min: 3, message: 'Indique o motivo da recusa.' }]}><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}


/** As minhas faltas (GET /rh/portal/ausencias): justificar uma falta por justificar cria um pedido «Ausência» ligado à falta. */
function MinhasFaltas() {
  const [historico, setHistorico] = useState(false);
  const q = useQuery({ queryKey: ['rh', 'portal', 'ausencias', historico], queryFn: () => obter<MinhaAusencia[]>('/rh/portal/ausencias', historico ? { estado: 'TODOS' } : undefined) });
  useAvisarErro(q.error);
  const catalogo = useQuery({ queryKey: ['rh', 'assiduidade', 'tipos-ausencia'], queryFn: () => obter<Record<string, TipoAusencia>>('/rh/assiduidade/tipos-ausencia'), staleTime: Infinity });
  const [justificar, setJustificar] = useState<MinhaAusencia | null>(null);
  const [form] = Form.useForm();
  const accao = useAccaoRh(() => setJustificar(null));
  const cat = catalogo.data ?? {};
  const colunas: ColumnsType<MinhaAusencia> = [
    { title: 'Data', render: (_, a) => (a.data_inicio === a.data_fim ? formatarData(a.data_inicio) : `${formatarData(a.data_inicio)} a ${formatarData(a.data_fim)}`) },
    { title: 'Ocorrência', render: (_, a) => (a.tipo ? cat[a.tipo]?.nome ?? a.tipo : a.ocorrencia ?? (a.detectada ? 'Falta detectada na assiduidade' : '—')) },
    { title: 'Duração', render: (_, a) => (a.horas_falta ? `${a.horas_falta} h` : a.horas ? `${a.horas} h` : `${a.dias_uteis ?? a.dias ?? 1} dia(s)`) },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Motivo / decisão', render: (_, a) => a.nota_decisao ?? a.motivo ?? '' },
    { title: '', key: 'acc', align: 'right', render: (_, a) => a.pode_justificar && <Button size="small" type="primary" onClick={() => { form.resetFields(); setJustificar(a); }}>Justificar</Button> },
  ];
  return (
    <Card>
      <Space style={{ marginBottom: 12 }}>
        <Checkbox checked={historico} onChange={(e) => setHistorico(e.target.checked)}>Ver o histórico (todas as ausências)</Checkbox>
      </Space>
      <Table<MinhaAusencia> rowKey="id" size="small" loading={q.isFetching} columns={colunas} dataSource={q.data ?? []} pagination={{ pageSize: 20 }} scroll={scrollTabela()}
        locale={{ emptyText: historico ? 'Sem ausências registadas.' : 'Não tem faltas por justificar.' }} />
      <Modal title="Justificar falta" open={justificar !== null} onCancel={() => setJustificar(null)} okText="Enviar justificação" cancelText="Cancelar" confirmLoading={accao.isPending}
        onOk={() => form.submit()} destroyOnHidden>
        {justificar && <Typography.Paragraph>Falta de {formatarData(justificar.data_inicio)}{justificar.data_fim !== justificar.data_inicio ? ` a ${formatarData(justificar.data_fim)}` : ''}.</Typography.Paragraph>}
        <Form form={form} layout="vertical" onFinish={(v) => justificar && accao.mutate({ metodo: 'post', url: '/rh/portal/pedidos', dados: { tipo: 'AUSENCIA', ausencia_id: justificar.id, ...v } })}>
          <Form.Item name="ausencia_tipo" label="Tipo (Lei 12/23)" rules={[{ required: true, message: 'Escolha o tipo.' }]}>
            <Select showSearch optionFilterProp="label" loading={catalogo.isLoading} options={Object.entries(cat).map(([k, t]) => ({ value: k, label: t.nome }))} />
          </Form.Item>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Indique o motivo.' }]}><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
          <Form.Item name="documento_url" label="Documento de prova (ligação)"><Input maxLength={1000} /></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}

/** O agregado familiar registado (GET /rh/portal/dependentes). */
function MeusDependentes() {
  const q = useQuery({ queryKey: ['rh', 'portal', 'dependentes'], queryFn: () => obter<(Dependente & { id: number })[]>('/rh/portal/dependentes') });
  useAvisarErro(q.error);
  const rotulo = (p: string | null) => PARENTESCOS.find((x) => x.value === p)?.label ?? p ?? '—';
  return (
    <div style={{ marginTop: 16 }}>
      <Typography.Text strong>Agregado familiar</Typography.Text>
      <Table<Dependente & { id: number }> rowKey="id" size="small" loading={q.isFetching} dataSource={q.data ?? []} pagination={false} scroll={scrollTabela()} style={{ marginTop: 8 }}
        locale={{ emptyText: 'Sem dependentes registados.' }}
        columns={[
          { title: 'Nome', dataIndex: 'nome' },
          { title: 'Parentesco', dataIndex: 'parentesco', render: rotulo },
          { title: 'Nascimento', dataIndex: 'data_nascimento', render: formatarData },
          { title: 'Dependente fiscal', dataIndex: 'dependente_fiscal', render: (v: boolean | null) => (v ? 'Sim' : 'Não') },
        ]} />
    </div>
  );
}
