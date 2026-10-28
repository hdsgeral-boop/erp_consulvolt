import { Button, Card, Form, Input, Modal, Popconfirm, Select, Space, Table, Tag, Tooltip, message } from 'antd';
import { ClockCircleOutlined, CommentOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { ConversaSuporte } from './comum/ConversaSuporte';

interface Ticket {
  id: number; tenant_id: number; cliente: string | null; assunto: string; descricao: string | null; estado: string; prioridade: string; categoria: string;
  criado_por: string | null; criado_em: string; agente_id: number | null; agente_nome: string | null; prazo_sla: string | null; em_atraso: boolean;
}
interface Cliente { id: number; nome: string }
interface Agente { id: number; nome: string }

const ESTADOS = ['ABERTO', 'EM_CURSO', 'RESOLVIDO', 'FECHADO'];
const PRIORIDADES = ['BAIXA', 'NORMAL', 'ALTA', 'URGENTE'];
/** Categorias (ADR-077): decidem qual especialização do backoffice de suporte vê o ticket — ver TicketSuporte::CHAVE_POR_CATEGORIA. */
const CATEGORIAS = ['GERAL', 'PLANO', 'FACTURACAO', 'MARKETING'];
const CHAVE = ['plataforma', 'suporte'];
const opcoes = (l: string[]) => l.map((v) => ({ value: v, label: v }));

/** Plataforma SaaS — suporte (plataforma_suporte): tickets dos clientes. */
export default function PlataformaSuporte() {
  const qc = useQueryClient();
  const [estado, setEstado] = useState<string>();
  const [pesquisa, setPesquisa] = useState('');
  const [aberto, setAberto] = useState(false);
  const [conversa, setConversa] = useState<Ticket | null>(null);
  const [form] = Form.useForm();
  const lista = useQuery({ queryKey: [...CHAVE, estado, pesquisa], queryFn: () => obter<Ticket[]>('/plataforma/suporte', { estado, pesquisa: pesquisa || undefined }) });
  const clientes = useQuery({ queryKey: ['plataforma', 'clientes'], queryFn: () => obter<Cliente[]>('/plataforma/clientes'), enabled: aberto });
  const agentes = useQuery({ queryKey: ['plataforma', 'suporte', 'agentes'], queryFn: () => obter<Agente[]>('/plataforma/suporte/agentes') });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });
  const criar = useMutation({ mutationFn: (v: Record<string, unknown>) => enviar('post', '/plataforma/suporte', v), onSuccess: () => { message.success('Ticket criado.'); setAberto(false); refrescar(); }, onError: (e) => notificarErro(e) });
  const mudar = useMutation({ mutationFn: (p: { id: number; dados: Record<string, unknown> }) => enviar('put', `/plataforma/suporte/${p.id}`, p.dados), onSuccess: refrescar, onError: (e) => notificarErro(e) });
  const apagar = useMutation({ mutationFn: (id: number) => enviar('delete', `/plataforma/suporte/${id}`), onSuccess: () => { message.success('Ticket eliminado.'); refrescar(); }, onError: (e) => notificarErro(e) });
  return (
    <>
      <CabecalhoPagina titulo="Suporte" subtitulo="Pedidos de suporte dos clientes" />
      <Card extra={
        <Space wrap>
          <Input.Search allowClear placeholder="Pesquisar" style={{ width: 200 }} onSearch={setPesquisa} />
          <Select allowClear placeholder="Estado" style={{ width: 160 }} options={opcoes(ESTADOS)} value={estado} onChange={setEstado} />
          <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); setAberto(true); }}>Novo ticket</Button>
        </Space>
      }>
        <Table<Ticket> rowKey="id" size="middle" loading={lista.isLoading} dataSource={lista.data ?? []} scroll={{ x: 'max-content' }}
          expandable={{ expandedRowRender: (t) => t.descricao ?? '—' }}
          columns={[
            { title: 'Cliente', dataIndex: 'cliente', render: (v: string | null) => v || <Tag>—</Tag> },
            {
              title: 'Assunto', dataIndex: 'assunto', render: (v: string, t) => (
                <Space size={6}>
                  {t.em_atraso && <Tooltip title={`Prazo do SLA vencido${t.prazo_sla ? ` (${formatarDataHora(t.prazo_sla)})` : ''}`}><ClockCircleOutlined style={{ color: '#dc2626' }} /></Tooltip>}
                  <strong>{v}</strong>
                </Space>
              ),
            },
            { title: 'Categoria', dataIndex: 'categoria', render: (v: string) => <Tag>{v}</Tag> },
            { title: 'Prioridade', render: (_, t) => <Tag color={t.prioridade === 'URGENTE' ? 'red' : t.prioridade === 'ALTA' ? 'orange' : t.prioridade === 'BAIXA' ? 'default' : 'blue'}>{t.prioridade}</Tag> },
            { title: 'Agente', render: (_, t) => (
              <Select allowClear size="small" style={{ width: 150 }} placeholder="Por atribuir" value={t.agente_id ?? undefined}
                options={(agentes.data ?? []).map((a) => ({ value: a.id, label: a.nome }))}
                onChange={(v) => mudar.mutate({ id: t.id, dados: { agente_id: v ?? null } })} />
            ) },
            { title: 'Estado', render: (_, t) => <Select size="small" style={{ width: 140 }} value={t.estado} options={opcoes(ESTADOS)} onChange={(e) => mudar.mutate({ id: t.id, dados: { estado: e } })} /> },
            { title: 'Criado', render: (_, t) => formatarDataHora(t.criado_em) },
            {
              title: '',
              align: 'right',
              render: (_, t) => (
                <Space>
                  <Button size="small" icon={<CommentOutlined />} onClick={() => setConversa(t)}>Conversa</Button>
                  <Popconfirm title="Eliminar o ticket?" onConfirm={() => apagar.mutate(t.id)}>
                    <Button size="small" danger icon={<DeleteOutlined />}>Eliminar</Button>
                  </Popconfirm>
                </Space>
              ),
            },
          ]} />
      </Card>
      <ConversaSuporte
        base="/plataforma/suporte"
        anexosBase="/plataforma/suporte/anexos"
        respostasRapidasBase="/plataforma/suporte/respostas-rapidas"
        agente
        ticketId={conversa?.id ?? null}
        titulo={conversa ? `#${conversa.id} — ${conversa.assunto}` : undefined}
        aoFechar={() => setConversa(null)}
      />
      <Modal open={aberto} title="Novo ticket" okText="Criar" confirmLoading={criar.isPending} onCancel={() => setAberto(false)} destroyOnClose onOk={() => form.validateFields().then((v) => criar.mutate(v))}>
        <Form form={form} layout="vertical" initialValues={{ prioridade: 'NORMAL', categoria: 'GERAL' }}>
          <Form.Item name="tenant_id" label="Cliente" rules={[{ required: true }]}><Select options={(clientes.data ?? []).map((c) => ({ value: c.id, label: c.nome }))} /></Form.Item>
          <Form.Item name="assunto" label="Assunto" rules={[{ required: true }]}><Input maxLength={200} /></Form.Item>
          <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
          <Form.Item name="prioridade" label="Prioridade"><Select options={opcoes(PRIORIDADES)} /></Form.Item>
          <Form.Item name="categoria" label="Categoria"><Select options={opcoes(CATEGORIAS)} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}