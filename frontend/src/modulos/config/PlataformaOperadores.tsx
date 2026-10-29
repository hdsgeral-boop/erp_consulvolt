import { useState } from 'react';
import { Alert, Avatar, Button, Card, Form, Input, Modal, Popconfirm, Select, Space, Switch, Table, Tag, Typography, message } from 'antd';
import { DeleteOutlined, EditOutlined, KeyOutlined, PlusOutlined, UserSwitchOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';

interface Operador {
  id: number;
  nome_utilizador: string;
  nome_completo: string | null;
  email: string | null;
  ativo: boolean;
  ultimo_acesso_em: string | null;
  perfil: { id: number; nome: string } | null;
}

/** Perfis restritos à plataforma (Fase 4, ADR-077 alargado): GET /plataforma/perfis-plataforma. */
function usePerfisPlataforma() {
  return useQuery({ queryKey: ['plataforma', 'perfis_plataforma'], queryFn: () => obter<{ id: number; nome: string }[]>('/plataforma/perfis-plataforma'), staleTime: 300_000 });
}

const CHAVE = ['plataforma', 'operadores'];

/**
 * Plataforma SaaS — operadores do back-office (Fase 4 da extensão SaaS): contas com acesso granular a áreas da
 * plataforma (clientes, planos, pagamentos, auditoria, …), cada uma restrita às chaves do seu perfil — nunca
 * acesso irrestrito como um super-administrador. Geridos aqui, pelos super-administradores.
 */
export default function PlataformaOperadores() {
  const qc = useQueryClient();
  const [aberto, setAberto] = useState<Operador | 'novo' | null>(null);
  const [form] = Form.useForm();
  const lista = useQuery({ queryKey: CHAVE, queryFn: () => obter<Operador[]>('/plataforma/operadores-plataforma') });
  const perfis = usePerfisPlataforma();
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });

  const criar = useMutation({
    mutationFn: (v: Record<string, unknown>) => enviar('post', '/plataforma/operadores-plataforma', v),
    onSuccess: () => { message.success('Operador criado.'); setAberto(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const atualizar = useMutation({
    mutationFn: (p: { id: number; v: Record<string, unknown> }) => enviar('put', `/plataforma/operadores-plataforma/${p.id}`, p.v),
    onSuccess: () => { message.success('Operador actualizado.'); setAberto(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const estado = useMutation({
    mutationFn: (p: { id: number; ativo: boolean }) => enviar('put', `/plataforma/operadores-plataforma/${p.id}/estado`, { ativo: p.ativo }),
    onSuccess: () => { message.success('Estado actualizado.'); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const apagar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/plataforma/operadores-plataforma/${id}`),
    onSuccess: () => { message.success('Operador eliminado.'); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const reporPassword = useMutation({
    mutationFn: (id: number) => enviar('post', `/plataforma/operadores-plataforma/${id}/palavra-passe`, {}),
    onSuccess: () => message.success('Nova palavra-passe gerada e enviada por email.'),
    onError: (e) => notificarErro(e),
  });

  const abrirEdicao = (o: Operador) => {
    form.resetFields();
    form.setFieldsValue({ nome_completo: o.nome_completo, email: o.email, perfil_utilizador_id: o.perfil?.id });
    setAberto(o);
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
      <CabecalhoPagina titulo="Operadores da plataforma" subtitulo="Contas de back-office com acesso granular — cada uma só às áreas que o seu perfil concede" />
      <Card extra={<Button type="primary" icon={<PlusOutlined />} onClick={abrirCriacao}>Novo operador</Button>}>
        <Table<Operador>
          rowKey="id"
          size="middle"
          loading={lista.isLoading}
          dataSource={lista.data ?? []}
          scroll={{ x: 'max-content' }}
          columns={[
            {
              title: 'Operador',
              render: (_, o) => (
                <Space>
                  <Avatar icon={<UserSwitchOutlined />} style={{ background: '#1947b3' }}>{iniciais(o.nome_completo || o.nome_utilizador)}</Avatar>
                  <div>
                    <div style={{ fontWeight: 600 }}>{o.nome_completo || o.nome_utilizador}</div>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>@{o.nome_utilizador}{o.email ? ` · ${o.email}` : ''}</Typography.Text>
                  </div>
                </Space>
              ),
            },
            {
              title: 'Perfil',
              render: (_, o) => (o.perfil ? <Tag color="purple">{o.perfil.nome}</Tag> : <Typography.Text type="danger">Por definir</Typography.Text>),
            },
            {
              title: 'Estado',
              render: (_, o) => (
                <Switch checked={o.ativo} checkedChildren="Activo" unCheckedChildren="Inactivo" onChange={(v) => estado.mutate({ id: o.id, ativo: v })} />
              ),
            },
            { title: 'Último acesso', render: (_, o) => (o.ultimo_acesso_em ? formatarDataHora(o.ultimo_acesso_em) : <Typography.Text type="secondary">Nunca entrou</Typography.Text>) },
            {
              title: '',
              align: 'right',
              render: (_, o) => (
                <Space>
                  <Button size="small" icon={<EditOutlined />} onClick={() => abrirEdicao(o)}>Editar</Button>
                  <Popconfirm title="Repor a palavra-passe?" description="Gera uma nova palavra-passe aleatória e termina as sessões abertas." onConfirm={() => reporPassword.mutate(o.id)}>
                    <Button size="small" icon={<KeyOutlined />}>Repor password</Button>
                  </Popconfirm>
                  <Popconfirm title="Eliminar este operador?" description="A acção não pode ser revertida." onConfirm={() => apagar.mutate(o.id)}>
                    <Button size="small" danger icon={<DeleteOutlined />}>Eliminar</Button>
                  </Popconfirm>
                </Space>
              ),
            },
          ]}
        />
      </Card>

      <Modal
        open={aberto !== null}
        title={aberto === 'novo' ? 'Novo operador da plataforma' : 'Editar operador'}
        okText={aberto === 'novo' ? 'Criar' : 'Guardar'}
        confirmLoading={criar.isPending || atualizar.isPending}
        onCancel={() => setAberto(null)}
        onOk={confirmar}
        destroyOnClose
      >
        {aberto === 'novo' && (
          <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Acesso granular, nunca total" description="Este utilizador entra na área da plataforma, mas cada operação sensível (clientes, planos, pagamentos, auditoria, …) exige ainda a chave do seu perfil — nunca o acesso irrestrito de um super-administrador." />
        )}
        <Form form={form} layout="vertical">
          {aberto === 'novo' && (
            <Form.Item name="nome_utilizador" label="Nome de utilizador" rules={[{ required: true, message: 'Indique o nome de utilizador.' }]}>
              <Input maxLength={100} autoComplete="off" />
            </Form.Item>
          )}
          <Form.Item name="nome_completo" label="Nome completo">
            <Input maxLength={200} />
          </Form.Item>
          <Form.Item name="email" label="Email (recomendado — é por aqui que recebe a palavra-passe gerada pelo sistema)" rules={[{ type: 'email', message: 'Email inválido.' }]}>
            <Input maxLength={150} autoComplete="off" />
          </Form.Item>
          <Form.Item name="perfil_utilizador_id" label="Perfil de permissões" tooltip="Decide a que áreas da plataforma este operador tem acesso" rules={[{ required: true, message: 'Escolha o perfil.' }]}>
            <Select showSearch optionFilterProp="label" loading={perfis.isLoading} options={(perfis.data ?? []).map((p) => ({ value: p.id, label: p.nome }))} placeholder="Escolha o perfil" />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
