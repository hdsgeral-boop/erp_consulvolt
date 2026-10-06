import { AutoavaliacoesRH, AvaliacaoChefiasRH } from './comum/AvaliacoesPortalRH';
import { Alert, Button, Card, Checkbox, Col, Descriptions, Flex, Form, Input, Modal, Popconfirm, Row, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar } from '@/componentes/impressao';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData } from '@/utilitarios/formatacao';
import { PARENTESCOS, TIPOS_PEDIDO, type ModeloDocumento, type PedidoPortal, type PropostaDocumento, type TipoPedido, type UtilizadorEmpresa } from './api';
import { AreaImpressao, BotaoImprimir, EstadoTag, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores } from './comum/consultas';
import { DocumentoImpresso, EtapasPedido, resumoPedido } from './comum/Pedidos';
import { accoesPedidoRh } from './comum/regras';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { pedidoTabela } from './comum/impressao';

import { TabelaComModos } from '@/componentes/vistas';
const ESTADOS_PEDIDO = ['PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO', 'EMITIDO', 'RECUSADO', 'CANCELADO'];

/** RH › Pedidos do Portal (ecrã rh_portal_gestao): decidir pedidos, emitir documentos, modelos e ligações utilizador ↔ colaborador. */
export default function PortalGestao() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  return (
    <>
      <CabecalhoPagina titulo="Pedidos do Portal do Colaborador" subtitulo="Decisões do RH sobre férias, documentos e agregado familiar; autoavaliações submetidas; avaliação anónima das chefias; ligação dos utilizadores às fichas."
        accoes={pode('rh_portal_usar') && <Button icon={<UserOutlined />} onClick={() => navegar('/m/rh/rh_portal')}>Abrir o meu portal</Button>} />
      <Tabs items={[
        { key: 'pedidos', label: 'Pedidos', children: <Pedidos /> },
        { key: 'modelos', label: 'Modelos de documentos', children: <Modelos /> },
        ...(pode('rh_portal_aprovar') || pode('rh_avaliacao_view') ? [{ key: 'autoavaliacoes', label: 'Autoavaliações', children: <AutoavaliacoesRH /> }] : []),
        ...(pode('rh_portal_aprovar') ? [{ key: 'chefias', label: 'Avaliação das chefias', children: <AvaliacaoChefiasRH /> }] : []),
        ...(pode('rh_portal_aprovar') || pode('rh_portal_gestao_view') ? [{ key: 'ligacoes', label: 'Utilizadores e colaboradores', children: <Ligacoes editar={pode('rh_portal_aprovar')} /> }] : []),
      ]} />
    </>
  );
}

function Pedidos() {
  const { pode, empresa } = useSessao();
  const colaboradores = useColaboradores();
  const [estado, setEstado] = useState<string | undefined>('PENDENTE_RH');
  const [tipo, setTipo] = useState<TipoPedido>();
  const [colaborador, setColaborador] = useState<number>();
  const [decidir, setDecidir] = useState<PedidoPortal | null>(null);
  const [emitir, setEmitir] = useState<PedidoPortal | null>(null);
  const [ver, setVer] = useState<PedidoPortal | null>(null);
  const [formD] = Form.useForm<{ decisao: 'APROVADO' | 'RECUSADO'; nota?: string; remunerada?: 'SIM' | 'NAO' }>();
  const [formE] = Form.useForm<{ titulo: string; texto: string; assinante: string; cargo_assinante?: string; local?: string }>();
  const decisao = Form.useWatch('decisao', formD);
  const q = useQuery({ queryKey: ['rh', 'portal', 'pedidos', { estado, tipo, colaborador }], queryFn: () => obter<PedidoPortal[]>('/rh/portal/pedidos', { estado, tipo, colaborador_id: colaborador }) });
  useAvisarErro(q.error);
  const proposta = useQuery({ queryKey: ['rh', 'portal', 'proposta', emitir?.id], queryFn: () => obter<PropostaDocumento>(`/rh/portal/pedidos/${emitir?.id}/proposta`), enabled: emitir !== null, gcTime: 0 });
  const accao = useAccaoRh(() => { setDecidir(null); setEmitir(null); });

  const abrirEmissao = (p: PedidoPortal) => {
    formE.resetFields();
    setEmitir(p);
  };
  const prop = proposta.data;
  useEffect(() => {
    if (prop) formE.setFieldsValue({ titulo: prop.titulo, texto: prop.texto, assinante: prop.assinante ?? '', cargo_assinante: prop.cargo_assinante ?? '', local: prop.local ?? '' });
  }, [prop, formE]);

  const colunas: ColunaApi<PedidoPortal>[] = [
    { title: 'N.º', dataIndex: 'id', render: (v: number) => `#${v}` },
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => <strong>{colaboradores.nome(v)}</strong> },
    { title: 'Tipo', dataIndex: 'tipo', render: (t: TipoPedido) => TIPOS_PEDIDO[t] ?? t },
    { title: 'Resumo', render: (_, p) => resumoPedido(p) },
    { title: 'Pedido em', dataIndex: 'criado_em', responsive: ['md'], render: formatarData },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    {
      title: '',
      key: 'accoes',
      render: (_, p) => {
        const ac = accoesPedidoRh(p, pode);
        return (
          <Space size={4} wrap>
            {ac.decidir && <Button size="small" type="primary" onClick={() => { formD.resetFields(); formD.setFieldsValue({ decisao: 'APROVADO' }); setDecidir(p); }}>Decidir</Button>}
            {ac.emitir && <Button size="small" type="primary" onClick={() => abrirEmissao(p)}>Emitir documento</Button>}
            {ac.emitir && <Button size="small" danger onClick={() => { formD.resetFields(); formD.setFieldsValue({ decisao: 'RECUSADO' }); setDecidir(p); }}>Recusar</Button>}
            {p.documento && <Button size="small" onClick={() => setVer(p)}>Ver documento</Button>}
          </Space>
        );
      },
    },
  ];

  const dadosD = (decidir?.dados ?? {}) as Record<string, unknown>;
  return (
    <Card>
      <BarraFiltros style={{ marginBottom: 12 }} accoes={
        <BotoesExportar desactivado={!q.data?.length} obterPedido={() => pedidoTabela({
          titulo: 'Pedidos do Portal do Colaborador',
          filtros: [estado ? `Estado: ${estado}` : null, tipo ? `Tipo: ${TIPOS_PEDIDO[tipo]}` : null, colaborador ? `Colaborador: ${colaboradores.nome(colaborador)}` : null],
          colunas,
          linhas: q.data ?? [],
        })} />
      }>
        <Select placeholder="Estado" allowClear style={{ width: 200 }} value={estado} onChange={setEstado} options={ESTADOS_PEDIDO.map((e) => ({ value: e, label: <EstadoTag estado={e} /> }))} />
        <Select placeholder="Tipo" allowClear style={{ width: 260 }} value={tipo} onChange={setTipo} options={Object.entries(TIPOS_PEDIDO).map(([v, l]) => ({ value: v, label: l }))} />
        <SeletorColaborador value={colaborador} onChange={setColaborador} />
      </BarraFiltros>
      <TabelaComModos<PedidoPortal> idVista="pedidos" rowKey="id" size="small" loading={q.isFetching} columns={colunas} dataSource={q.data ?? []} pagination={{ pageSize: 25 }} scroll={scrollTabela()}
        expandable={{ expandedRowRender: (p) => <DetalhePedido pedido={p} /> }} />

      <Modal title={`Decidir pedido #${decidir?.id ?? ''}`} open={decidir !== null} onCancel={() => setDecidir(null)} okText="Confirmar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formD.submit()} destroyOnHidden>
        {decidir && <Typography.Paragraph>{colaboradores.nome(decidir.colaborador_id)} — {TIPOS_PEDIDO[decidir.tipo]}: {resumoPedido(decidir)}</Typography.Paragraph>}
        {decidir?.tipo === 'AGREGADO' && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="A aprovação é recusada se o agregado mudou desde o pedido." />}
        <Form form={formD} layout="vertical" onFinish={(v) => decidir && accao.mutate({ metodo: 'post', url: `/rh/portal/pedidos/${decidir.id}/decidir`, dados: v })}>
          <Form.Item name="decisao" label="Decisão"><Select disabled={decidir?.tipo === 'DOCUMENTO'} options={[{ value: 'APROVADO', label: 'Aprovar' }, { value: 'RECUSADO', label: 'Recusar' }]} /></Form.Item>
          {decisao === 'APROVADO' && decidir?.tipo === 'AUSENCIA' && (
            <Form.Item name="remunerada" label="Remunerada? (tipos a critério do empregador)" extra={dadosD.tipo ? `Tipo: ${String(dadosD.tipo)}` : undefined}>
              <Select allowClear options={[{ value: 'SIM', label: 'Sim' }, { value: 'NAO', label: 'Não (descontada)' }]} />
            </Form.Item>
          )}
          <Form.Item name="nota" label="Nota" rules={[{ required: decisao === 'RECUSADO', min: 3, message: 'Indique o motivo da recusa.' }]}><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </Modal>

      <Modal title={`Emitir documento — pedido #${emitir?.id ?? ''}`} open={emitir !== null} width={larguraModal(860)} onCancel={() => setEmitir(null)} okText="Emitir" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formE.submit()} destroyOnHidden>
        {proposta.isLoading ? <Card loading variant="borderless" /> : (
          <>
            {(proposta.data?.faltas ?? []).length > 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`Variáveis sem valor (aparecem como [Rótulo]): ${proposta.data?.faltas.join(', ')}. Complete o texto antes de emitir.`} />}
            <Form form={formE} layout="vertical" onFinish={(v) => emitir && accao.mutate({ metodo: 'post', url: `/rh/portal/pedidos/${emitir.id}/emitir`, dados: v })}>
              <Form.Item name="titulo" label="Título" rules={[{ required: true }]}><Input maxLength={255} /></Form.Item>
              <Form.Item name="texto" label="Texto" rules={[{ required: true, min: 20 }]}><Input.TextArea rows={10} maxLength={20000} /></Form.Item>
              <Row gutter={12}>
                <Col xs={24} sm={12} md={8}><Form.Item name="assinante" label="Assinante" rules={[{ required: true, message: 'Indique quem assina.' }]}><Input maxLength={255} /></Form.Item></Col>
                <Col xs={24} sm={12} md={8}><Form.Item name="cargo_assinante" label="Cargo do assinante"><Input maxLength={255} /></Form.Item></Col>
                <Col xs={24} sm={12} md={8}><Form.Item name="local" label="Local"><Input maxLength={255} /></Form.Item></Col>
              </Row>
            </Form>
          </>
        )}
      </Modal>

      <Modal title="Documento emitido" open={ver !== null} width={larguraModal(820)} onCancel={() => setVer(null)} footer={<Space wrap><BotaoImprimir /><Button onClick={() => setVer(null)}>Fechar</Button></Space>}>
        {ver?.documento && <AreaImpressao><DocumentoImpresso documento={ver.documento} empresa={empresa?.nome} /></AreaImpressao>}
      </Modal>
    </Card>
  );
}

function DetalhePedido({ pedido }: { pedido: PedidoPortal }) {
  const d = (pedido.dados ?? {}) as Record<string, unknown>;
  const lista = (v: unknown) => (Array.isArray(v) ? (v as Record<string, unknown>[]) : []);
  return (
    <Space direction="vertical" style={{ width: '100%' }}>
      <EtapasPedido pedido={pedido} />
      {pedido.tipo === 'AGREGADO' && (
        <Row gutter={16}>
          {(['antes', 'depois'] as const).map((k) => (
            <Col xs={24} md={12} key={k}>
              <Typography.Text strong>{k === 'antes' ? 'Agregado actual' : 'Agregado proposto'}</Typography.Text>
              <Table size="small" pagination={false} scroll={scrollTabela()} rowKey={(x, i) => `${String(x.nome)}-${i}`} dataSource={lista(d[k])} columns={[
                { title: 'Nome', dataIndex: 'nome' },
                { title: 'Parentesco', dataIndex: 'parentesco', render: (v: string | null) => PARENTESCOS.find((p) => p.value === v)?.label ?? v ?? '—' },
                { title: 'Nascimento', dataIndex: 'data_nascimento', render: (v: string | null) => formatarData(v) },
              ]} />
            </Col>
          ))}
        </Row>
      )}
      {Boolean(d.observacoes || d.motivo) && <Descriptions size="small" column={1}><Descriptions.Item label="Observações / motivo">{String(d.observacoes || d.motivo)}</Descriptions.Item></Descriptions>}
      {Array.isArray(d.avisos) && d.avisos.length > 0 && <Alert type="warning" showIcon message={(d.avisos as string[]).join(' ')} />}
    </Space>
  );
}

function Modelos() {
  const { pode } = useSessao();
  const editar = pode('rh_portal_modelos');
  const q = useQuery({ queryKey: ['rh', 'portal', 'modelos'], queryFn: () => obter<{ modelos: ModeloDocumento[]; variaveis: Record<string, string> }>('/rh/portal/modelos') });
  useAvisarErro(q.error);
  const [edicao, setEdicao] = useState<ModeloDocumento | 'novo' | null>(null);
  const [form] = Form.useForm();
  const accao = useAccaoRh(() => setEdicao(null));
  return (
    <Card>
      {editar && <Flex justify="end" style={{ marginBottom: 12 }}><Button type="primary" onClick={() => { form.resetFields(); form.setFieldsValue({ ativo: true, auto_emitir: false }); setEdicao('novo'); }}>Novo modelo</Button></Flex>}
      <TabelaComModos<ModeloDocumento> idVista="modelos" rowKey="codigo" size="small" loading={q.isFetching} dataSource={q.data?.modelos ?? []} pagination={false} scroll={scrollTabela()} columns={[
        { title: 'Código', dataIndex: 'codigo', render: (v: string) => <code>{v}</code> },
        { title: 'Nome', dataIndex: 'nome' },
        { title: 'Origem', render: (_, m) => (m.padrao ? (m.personalizado ? <Tag color="blue">Padrão personalizado</Tag> : <Tag>Padrão</Tag>) : <Tag color="purple">Próprio</Tag>) },
        { title: 'Emissão automática', dataIndex: 'auto_emitir', responsive: ['md'], render: (v: boolean) => (v ? 'Sim' : 'Não') },
        { title: 'Assinante', dataIndex: 'assinante', responsive: ['md'], render: (v: string | null) => v ?? '—' },
        { title: 'Activo', dataIndex: 'ativo', render: (v: boolean) => (v ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>) },
        {
          title: '',
          key: 'accoes',
          render: (_, m) => editar && (
            <Space size={4}>
              <Button size="small" onClick={() => { form.setFieldsValue(m); setEdicao(m); }}>Editar</Button>
              {(m.personalizado || !m.padrao) && (
                <Popconfirm title={m.padrao ? 'Repor o texto padrão?' : 'Eliminar o modelo?'} okText="Confirmar" cancelText="Cancelar" onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/portal/modelos/${m.codigo}` })}>
                  <Button size="small" danger>{m.padrao ? 'Repor' : 'Eliminar'}</Button>
                </Popconfirm>
              )}
            </Space>
          ),
        },
      ]} />
      <Modal title={edicao === 'novo' ? 'Novo modelo' : 'Editar modelo'} open={edicao !== null} width={larguraModal(860)} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'put', url: '/rh/portal/modelos', dados: v })}>
          <Row gutter={12}>
            <Col xs={24} sm={12} md={8}><Form.Item name="codigo" label="Código" rules={[{ pattern: /^[A-Z0-9_]+$/, message: 'Maiúsculas, números e _.' }]} extra="Vazio = gerado."><Input maxLength={50} disabled={edicao !== 'novo'} /></Form.Item></Col>
            <Col xs={24} md={16}><Form.Item name="nome" label="Nome" rules={[{ required: true }]}><Input maxLength={255} /></Form.Item></Col>
            <Col xs={24}><Form.Item name="titulo" label="Título" rules={[{ required: true }]}><Input maxLength={255} /></Form.Item></Col>
            <Col xs={24}><Form.Item name="texto" label="Texto" rules={[{ required: true, min: 30 }]}><Input.TextArea rows={8} maxLength={20000} /></Form.Item></Col>
            <Col xs={24} sm={12} md={8}><Form.Item name="assinante" label="Assinante"><Input maxLength={255} /></Form.Item></Col>
            <Col xs={24} sm={12} md={8}><Form.Item name="cargo_assinante" label="Cargo"><Input maxLength={255} /></Form.Item></Col>
            <Col xs={24} sm={12} md={8}><Form.Item name="local" label="Local"><Input maxLength={255} /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="ativo" valuePropName="checked"><Checkbox>Activo</Checkbox></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="auto_emitir" valuePropName="checked"><Checkbox>Emissão automática (sem variáveis em falta e com assinante)</Checkbox></Form.Item></Col>
          </Row>
        </Form>
        <Typography.Text strong>Variáveis disponíveis</Typography.Text>
        <div style={{ marginTop: 4 }}>{Object.entries(q.data?.variaveis ?? {}).map(([k, r]) => <Tag key={k} title={r} style={{ marginBottom: 4 }}>{`{{${k}}}`}</Tag>)}</div>
      </Modal>
    </Card>
  );
}

/** Utilizadores da empresa e o colaborador a que cada um está ligado (GET /rh/portal/utilizadores); ligar exige rh_portal_aprovar. */
function Ligacoes({ editar }: { editar: boolean }) {
  const q = useQuery({ queryKey: ['rh', 'portal', 'utilizadores'], queryFn: () => obter<UtilizadorEmpresa[]>('/rh/portal/utilizadores') });
  useAvisarErro(q.error);
  const [pesquisa, setPesquisa] = useState('');
  const [soSem, setSoSem] = useState(false);
  const [ligar, setLigar] = useState<UtilizadorEmpresa | null>(null);
  const [form] = Form.useForm<{ colaborador_id?: number | null }>();
  const accao = useAccaoRh(() => setLigar(null));
  const termo = pesquisa.trim().toLowerCase();
  const linhas = (q.data ?? []).filter((u) => (!soSem || !u.colaborador_id)
    && (!termo || [u.nome_utilizador, u.nome_completo, u.colaborador_nome].some((t) => (t ?? '').toLowerCase().includes(termo))));
  const colunas: ColunaApi<UtilizadorEmpresa>[] = [
    { title: 'Utilizador', dataIndex: 'nome_utilizador', render: (v: string, u) => <Space size={4}><strong>{v}</strong>{!u.ativo && <Tag>Inactivo</Tag>}</Space>, sorter: (a, b) => a.nome_utilizador.localeCompare(b.nome_utilizador, 'pt') },
    { title: 'Nome', dataIndex: 'nome_completo', render: (v: string | null) => v ?? '—' },
    { title: 'Colaborador ligado', dataIndex: 'colaborador_nome', render: (v: string | null, u) => (u.colaborador_id ? v ?? `#${u.colaborador_id}` : <Typography.Text type="secondary">sem ligação</Typography.Text>) },
    ...(editar ? [{
      title: '', key: 'acc', align: 'right' as const,
      render: (_: unknown, u: UtilizadorEmpresa) => (
        <Space size={4}>
          <Button size="small" onClick={() => { form.setFieldsValue({ colaborador_id: u.colaborador_id }); setLigar(u); }}>{u.colaborador_id ? 'Alterar' : 'Ligar'}</Button>
          {u.colaborador_id && (
            <Popconfirm title="Retirar a ligação ao colaborador?" okText="Retirar" cancelText="Cancelar" okButtonProps={{ danger: true }}
              onConfirm={() => accao.mutateAsync({ metodo: 'post', url: '/rh/portal/ligacoes', dados: { utilizador_id: u.id, colaborador_id: null } })}>
              <Button size="small" danger>Retirar</Button>
            </Popconfirm>
          )}
        </Space>
      ),
    }] : []),
  ];
  return (
    <Card>
      <Typography.Paragraph type="secondary">Cada utilizador desta empresa pode estar ligado a um colaborador (acesso automático ao Portal); um colaborador só tem um utilizador. As alterações ficam auditadas.</Typography.Paragraph>
      <BarraFiltros style={{ marginBottom: 12 }} accoes={
        <BotoesExportar desactivado={!linhas.length} obterPedido={() => pedidoTabela({ titulo: 'Ligações utilizador ↔ colaborador', filtros: [soSem ? 'Só sem ligação' : null, pesquisa ? `Pesquisa: ${pesquisa}` : null], colunas, linhas })} />
      }>
        <Input.Search placeholder="Utilizador, nome ou colaborador" allowClear onSearch={setPesquisa} onChange={(e) => !e.target.value && setPesquisa('')} style={{ width: 300 }} />
        <Checkbox checked={soSem} onChange={(e) => setSoSem(e.target.checked)}>Só sem ligação</Checkbox>
      </BarraFiltros>
      <TabelaComModos<UtilizadorEmpresa> idVista="utilizadores" rowKey="id" size="small" loading={q.isFetching} columns={colunas} dataSource={linhas} pagination={{ pageSize: 20 }} scroll={scrollTabela()} />
      <Modal title={`Ligar ${ligar?.nome_utilizador ?? ''} a um colaborador`} open={ligar !== null} onCancel={() => setLigar(null)} okText="Gravar ligação" cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => ligar && accao.mutate({ metodo: 'post', url: '/rh/portal/ligacoes', dados: { utilizador_id: ligar.id, colaborador_id: v.colaborador_id ?? null } })}>
          <Form.Item name="colaborador_id" label="Colaborador" extra="Sem colaborador, a ligação é retirada."><SeletorColaborador style={{ width: '100%' }} /></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
