import { Button, Card, Form, Input, Modal, Popconfirm, Space, Switch, Table, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';

interface Admin { id: number; nome_utilizador: string; nome_completo: string | null; ativo: boolean; ultimo_acesso_em: string | null }
const CHAVE = ['plataforma', 'administradores'];

/** Plataforma SaaS — administradores (plataforma_administradores): super-administradores do tenant da plataforma. */
export default function PlataformaAdministradores() {
  const qc = useQueryClient();
  const [aberto, setAberto] = useState(false);
  const [form] = Form.useForm();
  const lista = useQuery({ queryKey: CHAVE, queryFn: () => obter<Admin[]>('/plataforma/administradores') });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });
  const criar = useMutation({ mutationFn: (v: Record<string, unknown>) => enviar('post', '/plataforma/administradores', v), onSuccess: () => { message.success('Administrador criado.'); setAberto(false); refrescar(); }, onError: (e) => notificarErro(e) });
  const estado = useMutation({ mutationFn: (p: { id: number; ativo: boolean }) => enviar('put', `/plataforma/administradores/${p.id}/estado`, { ativo: p.ativo }), onSuccess: refrescar, onError: (e) => notificarErro(e) });
  const apagar = useMutation({ mutationFn: (id: number) => enviar('delete', `/plataforma/administradores/${id}`), onSuccess: () => { message.success('Administrador eliminado.'); refrescar(); }, onError: (e) => notificarErro(e) });
  return (
    <>
      <CabecalhoPagina titulo="Administradores" subtitulo="Quem administra a plataforma (acesso total a todos os clientes)" />
      <Card extra={<Button type="primary" onClick={() => { form.resetFields(); setAberto(true); }}>Novo administrador</Button>}>
        <Table<Admin> rowKey="id" size="small" loading={lista.isLoading} dataSource={lista.data ?? []} pagination={false}
          columns={[
            { title: 'Utilizador', dataIndex: 'nome_utilizador' },
            { title: 'Nome', dataIndex: 'nome_completo' },
            { title: 'Último acesso', render: (_, a) => (a.ultimo_acesso_em ? formatarDataHora(a.ultimo_acesso_em) : '—') },
            { title: 'Activo', render: (_, a) => <Switch checked={a.ativo} onChange={(v) => estado.mutate({ id: a.id, ativo: v })} /> },
            { title: '', render: (_, a) => <Space><Popconfirm title="Eliminar este administrador?" onConfirm={() => apagar.mutate(a.id)}><Button size="small" danger>Eliminar</Button></Popconfirm></Space> },
          ]} />
      </Card>
      <Modal open={aberto} title="Novo administrador" okText="Criar" confirmLoading={criar.isPending} onCancel={() => setAberto(false)} destroyOnClose onOk={() => form.validateFields().then((v) => criar.mutate(v))}>
        <Form form={form} layout="vertical">
          <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true }]}><Input maxLength={100} /></Form.Item>
          <Form.Item name="nome_completo" label="Nome completo"><Input maxLength={200} /></Form.Item>
          <Form.Item name="palavra_passe" label="Palavra-passe (mín. 10)" rules={[{ required: true, min: 10 }]}><Input.Password maxLength={255} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}