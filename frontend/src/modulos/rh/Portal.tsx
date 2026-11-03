import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Popconfirm, Result, Row, Select, Space, Table, Tabs, Typography } from 'antd';
import { MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
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
import { IndicadoresPortal } from './portal/IndicadoresPortal';
import { PortalContrato, PortalEmprestimos, PortalEscala } from './portal/PortalFaseB';
import { Aprovacoes } from './portal/Aprovacoes';
import { FeriasPortal } from './portal/FeriasPortal';
import { PortalEntregaveis } from './portal/PortalEntregaveis';
import { PortalDelegacoes } from './portal/Delegacoes';
import { PortalProjectos } from './portal/PortalProjectos';

/** Aprovações por secções (portal/Aprovacoes.tsx) — também usadas pelo portal autónomo de quem aprova sem colaborador ligado. */
export { Aprovacoes };
import { pedidoPendente } from './comum/regras';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';

/** Portal do Colaborador (ecrã rh_portal): os próprios dados, recibos, pedidos e aprovações da equipa. */
export default function Portal() {
  const resumo = useQuery({ queryKey: ['rh', 'portal', 'resumo'], queryFn: () => obter<ResumoPortal>('/rh/portal/resumo'), retry: false });
  const [separador, setSeparador] = useSeparadorUrl(['pedidos', 'ferias', 'recibos', 'faltas', 'escala', 'contrato', 'emprestimos', 'avaliacao', 'equipa', 'projectos', 'entregaveis', 'aprovacoes', 'delegacoes', 'dados']);
  const [pedirFerias, setPedirFerias] = useState(false);
  if (resumo.isLoading) return <Card loading />;
  if (resumo.error || !resumo.data) {
    const msg = resumo.error instanceof ErroApi ? resumo.error.message : 'Não foi possível abrir o portal.';
    return <Result status="info" title="Portal do Colaborador" subTitle={msg} />;
  }
  const r = resumo.data;
  return (
    <>
      <CabecalhoPagina titulo={`Olá, ${r.colaborador.nome_completo.split(' ')[0]}`} subtitulo="Portal do Colaborador" />
      <IndicadoresPortal resumo={r} aoAbrirFaltas={() => setSeparador('faltas')} />
      <Tabs activeKey={separador} onChange={setSeparador} items={[
        { key: 'pedidos', label: 'Os meus pedidos', children: <MeusPedidos resumo={r} abrirFerias={pedirFerias} aoAbrirFerias={() => setPedirFerias(false)} /> },
        // Fase J (portal_ui.js:116-125): Férias com «A minha equipa» para a chefia
        { key: 'ferias', label: 'Férias', children: <FeriasPortal resumo={r} aoPedir={() => { setPedirFerias(true); setSeparador('pedidos'); }} /> },
        { key: 'recibos', label: 'Recibos', children: <Recibos resumo={r} /> },
        { key: 'faltas', label: r.faltas_por_justificar ? `Faltas (${r.faltas_por_justificar})` : 'Faltas', children: <MinhasFaltas resumo={r} /> },
        // Versão Actual (portal_ui.js:398-620): escala, contrato e empréstimos
        { key: 'escala', label: 'Escala', children: <PortalEscala /> },
        { key: 'contrato', label: 'Contrato', children: <PortalContrato /> },
        { key: 'emprestimos', label: 'Empréstimos', children: <PortalEmprestimos /> },
        { key: 'avaliacao', label: 'A minha avaliação', children: <PortalAvaliacao /> },
        { key: 'equipa', label: 'A minha equipa', children: <AMinhaEquipa /> },
        // Fase J: Projectos só para quem está em equipas de projecto; Entregáveis só a quem tenham sido atribuídos ou responda por eles
        ...(r.tem_projetos ? [{ key: 'projectos', label: 'Projectos', children: <PortalProjectos /> }] : []),
        ...(r.tem_entregaveis ? [{ key: 'entregaveis', label: r.entregaveis_pendentes ? `Entregáveis (${r.entregaveis_pendentes})` : 'Entregáveis', children: <PortalEntregaveis /> }] : []),
        { key: 'aprovacoes', label: `Aprovações (${r.aprovacoes_para_mim})`, children: <Aprovacoes /> },
        // Fase K (K5): delegação da chefia (sem permissão especial: cada um só mexe nas suas)
        { key: 'delegacoes', label: r.delegacoes_recebidas_ativas ? `Delegações (${r.delegacoes_recebidas_ativas})` : 'Delegações', children: <PortalDelegacoes /> },
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

function MeusPedidos({ resumo, abrirFerias, aoAbrirFerias }: { resumo: ResumoPortal; abrirFerias?: boolean; aoAbrirFerias?: () => void }) {
  const { empresa } = useSessao();
  const [tipo, setTipo] = useState<TipoPedido | null>(null);
  const [documento, setDocumento] = useState<PedidoPortal | null>(null);
  const [form] = Form.useForm();
  // «Pedir férias» no separador Férias abre aqui o pedido de férias
  useEffect(() => {
    if (abrirFerias) { form.resetFields(); setTipo('FERIAS'); aoAbrirFerias?.(); }
  }, [abrirFerias, aoAbrirFerias, form]);
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
        {(Object.keys(TIPOS_PEDIDO) as TipoPedido[]).filter((t) => t !== 'EMPRESTIMO').map((t) => <Button key={t} icon={<PlusOutlined />} onClick={() => { form.resetFields(); setTipo(t); }}>{TIPOS_PEDIDO[t]}</Button>)}
      </Space>
      <Table<PedidoPortal> rowKey="id" size="small" loading={q.isFetching} columns={colunas} dataSource={q.data ?? []} pagination={{ pageSize: 20 }} scroll={scrollTabela()}
        expandable={{ expandedRowRender: (p) => <EtapasPedido pedido={p} /> }} />

      <Modal title={tipo ? `Pedido — ${TIPOS_PEDIDO[tipo]}` : ''} open={tipo !== null} width={larguraModal(640)} onCancel={() => setTipo(null)} okText="Enviar pedido" cancelText="Cancelar"
        confirmLoading={accao.isPending} okButtonProps={{ disabled: tipo === 'FERIAS' && !resumo.ferias.periodo }} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={enviarPedido}>
          {tipo === 'FERIAS' && (
            resumo.ferias.periodo ? (
              <>
                <Alert type="info" showIcon style={{ marginBottom: 12 }}
                  title={`Saldo disponível: ${resumo.ferias.saldo} dias úteis. Período de férias de ${resumo.ano}: ${formatarData(resumo.ferias.periodo.data_inicio)} a ${formatarData(resumo.ferias.periodo.data_fim)}.`} />
                <Form.Item name="periodo" label="Período" rules={[{ required: true, message: 'Indique as datas.' }]}>
                  <DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }}
                    disabledDate={(d) => d.isBefore(dayjs(), 'day') || d.isBefore(dayjs(resumo.ferias.periodo!.data_inicio), 'day') || d.isAfter(dayjs(resumo.ferias.periodo!.data_fim), 'day')} />
                </Form.Item>
                <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
              </>
            ) : (
              <Alert type="warning" showIcon title={`Ainda não foi definido o período de férias de ${resumo.ano}. Peça ao administrador para o configurar antes de pedir férias.`} />
            )
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
              <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Indique o agregado completo (substitui o registado depois da aprovação do RH)." />
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

/** As minhas faltas (GET /rh/portal/ausencias): justificar uma falta por justificar cria um pedido «Ausência» ligado à falta. */
function MinhasFaltas({ resumo }: { resumo?: ResumoPortal }) {
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
      {/* art. 230.º da Lei 12/23 (assiduidade e portal do legado novo): texto informativo com o acumulado do colaborador */}
      <Alert type={(resumo?.faltas_injustificadas_mes ?? 0) > 3 || (resumo?.faltas_injustificadas_ano ?? 0) > 12 ? 'warning' : 'info'} showIcon style={{ marginBottom: 12 }}
        message={resumo?.faltas_injustificadas_ano !== undefined
          ? `Faltas injustificadas: ${resumo.faltas_injustificadas_mes ?? 0} dia(s) este mês · ${resumo.faltas_injustificadas_ano} dia(s) em ${resumo.ano}`
          : 'Faltas detectadas na efectividade'}
        description="Pode justificar as faltas enquanto o processamento salarial do mês estiver aberto: a justificação vai para aprovação da chefia e do RH e só depois de aprovada a falta fica justificada, sem desconto (salvo as ausências não remuneradas por lei). Sem justificação aprovada, contam como faltas injustificadas: perda de remuneração e, acima de 3 num mês ou 12 no ano, infracção disciplinar (art.º 230.º da Lei 12/23)." />
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
