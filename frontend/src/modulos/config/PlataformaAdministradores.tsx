import { useState } from 'react';
import { Avatar, Button, Card, Form, Input, Modal, Popconfirm, Space, Switch, Table, Tag, Typography, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined, UserOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';

interface Administrador {
  id: number;
  nome_utilizador: string;
  nome_completo: string | null;
  email: string | null;
  ativo: boolean;
  ultimo_acesso_em: string | null;
}

const CHAVE = ['plataforma', 'administradores'];

/** Plataforma SaaS — administradores (plataforma_administradores): super-administradores da própria Consulvolt. */
export default function PlataformaAdministradores() {
  const qc = useQueryClient();
  const { utilizador } = useSessao();
  const [aberto, setAberto] = useState<Administrador | 'novo' | null>(null);
  const [form] = Form.useForm();
  const lista = useQuery({ queryKey: CHAVE, queryFn: () => obter<Administrador[]>('/plataforma/administradores') });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });

  const criar = useMutation({
    mutationFn: (v: Record<string, unknown>) => enviar('post', '/plataforma/administradores', v),
    onSuccess: () => { message.success('Administrador criado.'); setAberto(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const atualizar = useMutation({
    mutationFn: (p: { id: number; v: Record<string, unknown> }) => enviar('put', `/plataforma/administradores/${p.id}`, p.v),
    onSuccess: () => { message.success('Administrador actualizado.'); setAberto(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const estado = useMutation({
    mutationFn: (p: { id: number; ativo: boolean }) => enviar('put', `/plataforma/administradores/${p.id}/estado`, { ativo: p.ativo }),
    onSuccess: () => { message.success('Estado actualizado.'); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const apagar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/plataforma/administradores/${id}`),
    onSuccess: () => { message.success('Administrador eliminado.'); refrescar(); },
    onError: (e) => notificarErro(e),
  });

  const abrirEdicao = (a: Administrador) => {
    form.resetFields();
    form.setFieldsValue({ nome_completo: a.nome_completo });
    setAberto(a);
  };
  const abrirCriacao = () => {
    form.resetFields();
    setAberto('novo');
  };
  const confirmar = () =>
    form.validateFields().then((v) => (aberto === 'novo' ? criar.mutate(v) : aberto && atualizar.mutate({ id: aberto.id, v })));

  const iniciais = (nome: string) =>
    nome.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('') || '?';

  return (
    <>
      <CabecalhoPagina titulo="Administradores" subtitulo="Super-administradores com acesso à plataforma (gestão de todos os clientes, planos e definições)" />
      <Card extra={<Button type="primary" icon={<PlusOutlined />} onClick={abrirCriacao}>Novo administrador</Button>}>
        <Table<Administrador>
          rowKey="id"
          size="middle"
          loading={lista.isLoading}
          dataSource={lista.data ?? []}
          scroll={{ x: 'max-content' }}
          columns={[
            {
              title: 'Administrador',
              render: (_, a) => (
                <Space>
                  <Avatar icon={<UserOutlined />} style={{ background: '#1947b3' }}>{iniciais(a.nome_completo || a.nome_utilizador)}</Avatar>
                  <div>
                    <div style={{ fontWeight: 600 }}>{a.nome_completo || a.nome_utilizador}</div>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>@{a.nome_utilizador}{a.email ? ` · ${a.email}` : ''}</Typography.Text>
                  </div>
                  {a.id === utilizador?.id && <Tag color="blue">Você</Tag>}
                </Space>
              ),
            },
            {
              title: 'Estado',
              render: (_, a) => (
                <Switch
                  checked={a.ativo}
                  checkedChildren="Activo"
                  unCheckedChildren="Inactivo"
                  disabled={a.id === utilizador?.id}
                  onChange={(v) => estado.mutate({ id: a.id, ativo: v })}
                />
              ),
            },
            { title: 'Último acesso', render: (_, a) => (a.ultimo_acesso_em ? formatarDataHora(a.ultimo_acesso_em) : <Typography.Text type="secondary">Nunca entrou</Typography.Text>) },
            {
              title: '',
              align: 'right',
              render: (_, a) => (
                <Space>
                  <Button size="small" icon={<EditOutlined />} onClick={() => abrirEdicao(a)}>Editar</Button>
                  {a.id !== utilizador?.id && (
                    <Popconfirm title="Eliminar este administrador?" description="A acção não pode ser revertida." onConfirm={() => apagar.mutate(a.id)}>
                      <Button size="small" danger icon={<DeleteOutlined />}>Eliminar</Button>
                    </Popconfirm>
                  )}
                </Space>
              ),
            },
          ]}
        />
      </Card>

      <Modal
        open={aberto !== null}
        title={aberto === 'novo' ? 'Novo administrador' : 'Editar administrador'}
        okText={aberto === 'novo' ? 'Criar' : 'Guardar'}
        confirmLoading={criar.isPending || atualizar.isPending}
        onCancel={() => setAberto(null)}
        onOk={confirmar}
        destroyOnClose
      >
        <Form form={form} layout="vertical">
          {aberto === 'novo' && (
            <Form.Item name="nome_utilizador" label="Nome de utilizador" rules={[{ required: true, message: 'Indique o nome de utilizador.' }]}>
              <Input maxLength={100} autoComplete="off" />
            </Form.Item>
          )}
          <Form.Item name="nome_completo" label="Nome completo">
            <Input maxLength={200} />
          </Form.Item>
          {aberto === 'novo' && (
            <Form.Item name="email" label="Email (opcional — envia as credenciais de acesso)" rules={[{ type: 'email', message: 'Email inválido.' }]}>
              <Input maxLength={150} autoComplete="off" />
            </Form.Item>
          )}
          <Form.Item
            name="palavra_passe"
            label={aberto === 'novo' ? 'Palavra-passe (mín. 10 caracteres)' : 'Nova palavra-passe (em branco = mantém)'}
            rules={aberto === 'novo' ? [{ required: true, min: 10, message: 'Mínimo de 10 caracteres.' }] : [{ min: 10, message: 'Mínimo de 10 caracteres.' }]}
          >
            <Input.Password maxLength={255} autoComplete="new-password" />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
