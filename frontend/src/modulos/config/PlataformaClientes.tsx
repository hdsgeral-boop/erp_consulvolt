import { Avatar, Button, Card, Descriptions, Drawer, Empty, Form, Input, Modal, Popconfirm, Select, Space, Table, Tag, Typography, message } from 'antd';
import {
  BankOutlined,
  EyeOutlined,
  InboxOutlined,
  PauseCircleOutlined,
  PlayCircleOutlined,
  PlusOutlined,
  TeamOutlined,
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData } from '@/utilitarios/formatacao';

interface Cliente {
  id: number;
  nome: string;
  slug: string;
  estado: 'ATIVO' | 'SUSPENSO' | 'ARQUIVADO';
  empresas: number;
  utilizadores: number;
  criado_em: string;
}

interface Detalhe {
  nome: string;
  plano: string | null;
  utilizadores: number;
  empresas: EmpresaCliente[];
}

interface EmpresaCliente {
  id: number;
  nome: string;
  nif: string;
  estado: string;
  licenca_origem: string;
  licenca_fim: string | null;
}

const COR = { ATIVO: 'green', SUSPENSO: 'orange', ARQUIVADO: 'default' } as const;
const CHAVE = ['plataforma', 'clientes'];

/** Plataforma SaaS — clientes (plataforma_clientes): criar cliente com administrador e empresa, suspender, arquivar. */
export default function PlataformaClientes() {
  const qc = useQueryClient();
  const [criar, setCriar] = useState(false);
  const [ver, setVer] = useState<number | null>(null);
  const [form] = Form.useForm();
  const lista = useQuery({ queryKey: CHAVE, queryFn: () => obter<Cliente[]>('/plataforma/clientes') });
  const detalhe = useQuery({
    queryKey: [...CHAVE, ver],
    queryFn: () => obter<Detalhe>(`/plataforma/clientes/${ver}`),
    enabled: ver !== null,
  });
  const planos = useQuery({ queryKey: ['plataforma', 'planos'], queryFn: () => obter<{ planos: { codigo: string; nome: string }[] }>('/plataforma/planos'), enabled: ver !== null });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });
  const plano = useMutation({ mutationFn: (p: { id: number; plano: string | null }) => enviar('put', `/plataforma/clientes/${p.id}/plano`, { plano: p.plano }), onSuccess: () => { message.success('Plano actualizado.'); refrescar(); }, onError: (e) => notificarErro(e) });
  const novo = useMutation({
    mutationFn: (v: Record<string, unknown>) => enviar('post', '/plataforma/clientes', v),
    onSuccess: () => { message.success('Cliente criado.'); setCriar(false); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const estado = useMutation({
    mutationFn: (p: { id: number; estado: string }) => enviar('put', `/plataforma/clientes/${p.id}/estado`, { estado: p.estado }),
    onSuccess: () => { message.success('Estado actualizado.'); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const arquivar = useMutation({
    mutationFn: (id: number) => enviar('post', `/plataforma/clientes/${id}/arquivar`, {}),
    onSuccess: () => { message.success('Cliente arquivado.'); refrescar(); },
    onError: (e) => notificarErro(e),
  });

  return (
    <>
      <CabecalhoPagina titulo="Clientes" subtitulo="Contas SaaS: cada cliente tem os seus utilizadores e empresas isolados" />
      <Card extra={<Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); setCriar(true); }}>Novo cliente</Button>}>
        <Table<Cliente>
          rowKey="id"
          size="middle"
          loading={lista.isLoading}
          dataSource={lista.data ?? []}
          scroll={{ x: 'max-content' }}
          columns={[
            {
              title: 'Cliente',
              dataIndex: 'nome',
              render: (nome: string) => (
                <Space>
                  <Avatar style={{ background: '#1947b3', flex: 'none' }}>{nome.slice(0, 2).toUpperCase()}</Avatar>
                  <strong>{nome}</strong>
                </Space>
              ),
            },
            { title: 'Endereço', dataIndex: 'slug', render: (v: string) => <Typography.Text code>{v}</Typography.Text> },
            { title: 'Estado', render: (_, c) => <Tag color={COR[c.estado]}>{c.estado}</Tag> },
            { title: 'Empresas', dataIndex: 'empresas', align: 'right' },
            { title: 'Utilizadores', dataIndex: 'utilizadores', align: 'right' },
            { title: 'Criado em', render: (_, c) => formatarData(c.criado_em) },
            {
              title: '',
              align: 'right',
              render: (_, c) => (
                <Space>
                  <Button size="small" icon={<EyeOutlined />} onClick={() => setVer(c.id)}>Detalhe</Button>
                  {c.estado === 'ATIVO' && c.id !== 1 && (
                    <Popconfirm title="Suspender o cliente? Os utilizadores deixam de entrar." onConfirm={() => estado.mutate({ id: c.id, estado: 'SUSPENSO' })}>
                      <Button size="small" icon={<PauseCircleOutlined />}>Suspender</Button>
                    </Popconfirm>
                  )}
                  {c.estado === 'SUSPENSO' && (
                    <Button size="small" icon={<PlayCircleOutlined />} onClick={() => estado.mutate({ id: c.id, estado: 'ATIVO' })}>Activar</Button>
                  )}
                  {c.estado !== 'ARQUIVADO' && c.id !== 1 && (
                    <Popconfirm title="Arquivar? Os dados ficam guardados." onConfirm={() => arquivar.mutate(c.id)}>
                      <Button size="small" danger icon={<InboxOutlined />}>Arquivar</Button>
                    </Popconfirm>
                  )}
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <Drawer open={ver !== null} onClose={() => setVer(null)} width={580} title={detalhe.data?.nome ?? 'Cliente'} destroyOnClose>
        <Descriptions column={1} size="small" bordered style={{ marginBottom: 20 }}>
          <Descriptions.Item label={<Space size={6}><TeamOutlined />Utilizadores</Space>}>{detalhe.data?.utilizadores ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Plano comercial">
            <Select
              allowClear
              style={{ width: 260 }}
              placeholder="Sem plano"
              value={detalhe.data?.plano ?? undefined}
              options={(planos.data?.planos ?? []).map((p) => ({ value: p.codigo, label: p.nome }))}
              onChange={(v) => ver !== null && plano.mutate({ id: ver, plano: v ?? null })}
            />
          </Descriptions.Item>
        </Descriptions>
        <Typography.Title level={5} style={{ marginBottom: 12 }}>
          <BankOutlined style={{ marginRight: 8 }} />Empresas
        </Typography.Title>
        <Table<EmpresaCliente>
          rowKey="id"
          size="small"
          loading={detalhe.isLoading}
          pagination={false}
          dataSource={detalhe.data?.empresas ?? []}
          locale={{ emptyText: <Empty description="Este cliente ainda não tem empresas." image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
          columns={[
            { title: 'Empresa', dataIndex: 'nome' },
            { title: 'NIF', dataIndex: 'nif' },
            { title: 'Estado', render: (_, e) => <Tag color={e.estado === 'ATIVO' ? 'green' : 'default'}>{e.estado}</Tag> },
            {
              title: 'Licença',
              render: (_, e) => (
                <Tag color={e.licenca_origem === 'TESTE' ? 'blue' : e.licenca_origem === 'SEM_LIMITE' ? 'gold' : 'purple'}>
                  {e.licenca_origem}{e.licenca_fim ? ` até ${formatarData(e.licenca_fim)}` : ''}
                </Tag>
              ),
            },
          ]}
        />
      </Drawer>
      <Modal open={criar} title="Novo cliente" okText="Criar" confirmLoading={novo.isPending} onCancel={() => setCriar(false)} destroyOnClose
        onOk={() => form.validateFields().then((v) => novo.mutate(v.empresa?.nome ? v : { ...v, empresa: undefined }))}>
        <Form form={form} layout="vertical">
          <Form.Item name="nome" label="Nome do cliente" rules={[{ required: true }]}><Input maxLength={255} /></Form.Item>
          <Form.Item name="slug" label="Endereço (slug)" rules={[{ required: true, min: 3, pattern: /^[a-z][a-z0-9-]*[a-z0-9]$/, message: 'Minúsculas, números e hífenes.' }]}><Input maxLength={40} /></Form.Item>
          <Form.Item name={['administrador', 'nome_utilizador']} label="Utilizador administrador" rules={[{ required: true }]}><Input maxLength={100} /></Form.Item>
          <Form.Item name={['administrador', 'palavra_passe']} label="Palavra-passe inicial (mín. 10)" rules={[{ required: true, min: 10 }]}><Input.Password maxLength={255} /></Form.Item>
          <Form.Item name={['empresa', 'nome']} label="Primeira empresa (opcional)"><Input maxLength={255} /></Form.Item>
          <Form.Item noStyle shouldUpdate>{() => form.getFieldValue(['empresa', 'nome']) && (
            <Form.Item name={['empresa', 'nif']} label="NIF da empresa" rules={[{ required: true }]}><Input maxLength={30} /></Form.Item>
          )}</Form.Item>
        </Form>
      </Modal>
    </>
  );
}