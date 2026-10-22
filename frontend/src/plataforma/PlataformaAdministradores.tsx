import { Button, Card, Form, Input, Modal, Popconfirm, Space, Switch, Table, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { CampoPalavraPasse } from './CampoPalavraPasse';

interface Admin { id: number; nome_utilizador: string; nome_completo: string | null; email: string | null; ativo: boolean; ultimo_acesso_em: string | null }
const CHAVE = ['plataforma', 'administradores'];

/** Plataforma SaaS — administradores (plataforma_administradores): super-administradores do tenant da plataforma. */
export default function PlataformaAdministradores() {
  const qc = useQueryClient();
  const [aberto, setAberto] = useState<Admin | 'novo' | null>(null);
  const [form] = Form.useForm();
  const lista = useQuery({ queryKey: CHAVE, queryFn: () => obter<Admin[]>('/plataforma/administradores') });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });
  const gravar = useMutation({
    mutationFn: (v: Record<string, unknown>) =>
      aberto === 'novo' ? enviar('post', '/plataforma/administradores', v) : enviar('put', `/plataforma/administradores/${(aberto as Admin).id}`, v),
    onSuccess: () => { message.success(aberto === 'novo' ? 'Administrador criado.' : 'Administrador actualizado.'); setAberto(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const estado = useMutation({ mutationFn: (p: { id: number; ativo: boolean }) => enviar('put', `/plataforma/administradores/${p.id}/estado`, { ativo: p.ativo }), onSuccess: refrescar, onError: (e) => notificarErro(e) });
  const apagar = useMutation({ mutationFn: (id: number) => enviar('delete', `/plataforma/administradores/${id}`), onSuccess: () => { message.success('Administrador eliminado.'); refrescar(); }, onError: (e) => notificarErro(e) });
  const abrir = (a: Admin | 'novo') => {
    form.resetFields();
    if (a !== 'novo') form.setFieldsValue({ nome_completo: a.nome_completo, email: a.email });
    setAberto(a);
  };
  const novo = aberto === 'novo';

  return (
    <>
      <CabecalhoPagina titulo="Administradores" subtitulo="Quem administra a plataforma (acesso total a todos os clientes)" />
      <Card extra={<Button type="primary" onClick={() => abrir('novo')}>Novo administrador</Button>}>
        <Table<Admin> rowKey="id" size="small" loading={lista.isLoading} dataSource={lista.data ?? []} pagination={false} scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Utilizador', dataIndex: 'nome_utilizador' },
            { title: 'Nome', dataIndex: 'nome_completo' },
            { title: 'E-mail', dataIndex: 'email', render: (v: string | null) => v ?? '—' },
            { title: 'Último acesso', render: (_, a) => (a.ultimo_acesso_em ? formatarDataHora(a.ultimo_acesso_em) : '—') },
            { title: 'Activo', render: (_, a) => <Switch checked={a.ativo} onChange={(v) => estado.mutate({ id: a.id, ativo: v })} /> },
            {
              title: '',
              render: (_, a) => (
                <Space>
                  <Button size="small" onClick={() => abrir(a)}>Editar</Button>
                  <Popconfirm title="Eliminar este administrador?" onConfirm={() => apagar.mutate(a.id)}><Button size="small" danger>Eliminar</Button></Popconfirm>
                </Space>
              ),
            },
          ]} />
      </Card>
      <Modal open={aberto !== null} title={novo ? 'Novo administrador' : `Editar ${(aberto as Admin | null)?.nome_utilizador ?? ''}`} okText={novo ? 'Criar' : 'Gravar'}
        confirmLoading={gravar.isPending} onCancel={() => setAberto(null)} destroyOnHidden
        onOk={() => form.validateFields().then((v) => gravar.mutate(Object.fromEntries(Object.entries(v).filter(([k, x]) => k !== 'palavra_passe' || x))))}>
        <Form form={form} layout="vertical">
          <Form.Item name="nome_completo" label="Nome completo"><Input maxLength={200} /></Form.Item>
          {novo && <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true, message: 'Indique o utilizador.' }]}><Input maxLength={100} /></Form.Item>}
          <Form.Item name="email" label="E-mail" rules={[{ type: 'email', message: 'E-mail inválido.' }]}><Input maxLength={150} /></Form.Item>
          <CampoPalavraPasse form={form} obrigatorio={novo} rotulo={novo ? 'Palavra-passe' : 'Nova palavra-passe (em branco mantém)'} minimo={10} />
        </Form>
      </Modal>
    </>
  );
}
