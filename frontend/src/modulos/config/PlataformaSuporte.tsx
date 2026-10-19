import { Button, Card, Form, Input, Modal, Popconfirm, Select, Space, Table, Tag, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { ConversaSuporte } from './comum/ConversaSuporte';

interface Ticket { id: number; tenant_id: number; cliente: string | null; assunto: string; descricao: string | null; estado: string; prioridade: string; categoria: string; criado_por: string | null; criado_em: string }
interface Cliente { id: number; nome: string }

const ESTADOS = ['ABERTO', 'EM_CURSO', 'RESOLVIDO', 'FECHADO'];
const PRIORIDADES = ['BAIXA', 'NORMAL', 'ALTA', 'URGENTE'];
const CHAVE = ['plataforma', 'suporte'];
const opcoes = (l: string[]) => l.map((v) => ({ value: v, label: v }));

/** Plataforma SaaS — suporte (plataforma_suporte): tickets dos clientes. */
export default function PlataformaSuporte() {
  const qc = useQueryClient();
  const [estado, setEstado] = useState<string>();
  const [aberto, setAberto] = useState(false);
  const [conversa, setConversa] = useState<Ticket | null>(null);
  const [form] = Form.useForm();
  const lista = useQuery({ queryKey: [...CHAVE, estado], queryFn: () => obter<Ticket[]>(`/plataforma/suporte${estado ? `?estado=${estado}` : ''}`) });
  const clientes = useQuery({ queryKey: ['plataforma', 'clientes'], queryFn: () => obter<Cliente[]>('/plataforma/clientes'), enabled: aberto });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });
  const criar = useMutation({ mutationFn: (v: Record<string, unknown>) => enviar('post', '/plataforma/suporte', v), onSuccess: () => { message.success('Ticket criado.'); setAberto(false); refrescar(); }, onError: (e) => notificarErro(e) });
  const mudar = useMutation({ mutationFn: (p: { id: number; estado: string }) => enviar('put', `/plataforma/suporte/${p.id}`, { estado: p.estado }), onSuccess: refrescar, onError: (e) => notificarErro(e) });
  const apagar = useMutation({ mutationFn: (id: number) => enviar('delete', `/plataforma/suporte/${id}`), onSuccess: () => { message.success('Ticket eliminado.'); refrescar(); }, onError: (e) => notificarErro(e) });
  return (
    <>
      <CabecalhoPagina titulo="Suporte" subtitulo="Pedidos de suporte dos clientes" />
      <Card extra={<Space><Select allowClear placeholder="Estado" style={{ width: 160 }} options={opcoes(ESTADOS)} value={estado} onChange={setEstado} /><Button type="primary" onClick={() => { form.resetFields(); setAberto(true); }}>Novo ticket</Button></Space>}>
        <Table<Ticket> rowKey="id" size="small" loading={lista.isLoading} dataSource={lista.data ?? []} scroll={{ x: 'max-content' }}
          expandable={{ expandedRowRender: (t) => t.descricao ?? '—' }}
          columns={[
            { title: 'Cliente', dataIndex: 'cliente' },
            { title: 'Assunto', dataIndex: 'assunto' },
            { title: 'Prioridade', render: (_, t) => <Tag color={t.prioridade === 'URGENTE' ? 'red' : t.prioridade === 'ALTA' ? 'orange' : 'default'}>{t.prioridade}</Tag> },
            { title: 'Estado', render: (_, t) => <Select size="small" style={{ width: 130 }} value={t.estado} options={opcoes(ESTADOS)} onChange={(e) => mudar.mutate({ id: t.id, estado: e })} /> },
            { title: 'Criado', render: (_, t) => formatarDataHora(t.criado_em) },
            { title: '', render: (_, t) => <Space><Button size="small" onClick={() => setConversa(t)}>Conversa</Button><Popconfirm title="Eliminar o ticket?" onConfirm={() => apagar.mutate(t.id)}><Button size="small" danger>Eliminar</Button></Popconfirm></Space> },
          ]} />
      </Card>
      <ConversaSuporte base="/plataforma/suporte" ticketId={conversa?.id ?? null} titulo={conversa ? `: ` : undefined} aoFechar={() => setConversa(null)} />
      <Modal open={aberto} title="Novo ticket" okText="Criar" confirmLoading={criar.isPending} onCancel={() => setAberto(false)} destroyOnClose onOk={() => form.validateFields().then((v) => criar.mutate(v))}>
        <Form form={form} layout="vertical" initialValues={{ prioridade: 'NORMAL', categoria: 'GERAL' }}>
          <Form.Item name="tenant_id" label="Cliente" rules={[{ required: true }]}><Select options={(clientes.data ?? []).map((c) => ({ value: c.id, label: c.nome }))} /></Form.Item>
          <Form.Item name="assunto" label="Assunto" rules={[{ required: true }]}><Input maxLength={200} /></Form.Item>
          <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
          <Form.Item name="prioridade" label="Prioridade"><Select options={opcoes(PRIORIDADES)} /></Form.Item>
          <Form.Item name="categoria" label="Categoria"><Select options={opcoes(['GERAL', 'PLANO', 'FACTURACAO'])} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}