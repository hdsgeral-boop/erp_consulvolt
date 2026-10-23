import { Button, Card, Drawer, Form, Input, Modal, Popconfirm, Select, Space, Table, Tabs, Tag, message } from 'antd';
import { PlusOutlined, SearchOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData } from '@/utilitarios/formatacao';
import { UtilizadoresCliente } from './UtilizadoresCliente';

interface Cliente {
  id: number;
  nome: string;
  slug: string;
  estado: 'ATIVO' | 'SUSPENSO' | 'ARQUIVADO';
  plano?: string | null;
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
const NOME = { ATIVO: 'Activo', SUSPENSO: 'Suspenso', ARQUIVADO: 'Arquivado' } as const;
const CHAVE = ['plataforma', 'clientes'];

/** Plataforma SaaS — clientes (plataforma_clientes): criar cliente com administrador e empresa, suspender, arquivar. */
export default function PlataformaClientes() {
  const qc = useQueryClient();
  const [criar, setCriar] = useState(false);
  const [ver, setVer] = useState<number | null>(null);
  const [form] = Form.useForm();
  const [params, setParams] = useSearchParams();
  const [pesquisa, setPesquisa] = useState(params.get('q') ?? '');
  const [filtroEstado, setFiltroEstado] = useState<string | undefined>();
  // «Novo cliente» vindo do painel (?novo=1) abre o formulário; a pesquisa do cabeçalho vem em ?q=
  useEffect(() => {
    if (params.get('novo') === '1') {
      form.resetFields();
      setCriar(true);
      setParams((p) => { p.delete('novo'); return p; }, { replace: true });
    }
    setPesquisa(params.get('q') ?? '');
  }, [params, form, setParams]);
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

  const filtrados = useMemo(() => {
    const t = pesquisa.trim().toLowerCase();
    return (lista.data ?? []).filter((c) => (!filtroEstado || c.estado === filtroEstado) && (!t || c.nome.toLowerCase().includes(t) || c.slug.toLowerCase().includes(t)));
  }, [lista.data, pesquisa, filtroEstado]);

  return (
    <>
      <CabecalhoPagina titulo="Clientes" subtitulo="Contas SaaS: cada cliente tem os seus utilizadores e empresas isolados" />
      <Card>
        <Space wrap style={{ width: '100%', marginBottom: 12, justifyContent: 'space-between' }}>
          <Space wrap>
            <Input allowClear prefix={<SearchOutlined />} placeholder="Procurar por nome ou endereço" value={pesquisa} onChange={(e) => setPesquisa(e.target.value)} style={{ width: 300 }} />
            <Select allowClear placeholder="Todos os estados" value={filtroEstado} onChange={setFiltroEstado} style={{ width: 190 }}
              options={Object.entries(NOME).map(([value, label]) => ({ value, label }))} />
          </Space>
          <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); setCriar(true); }}>Novo cliente</Button>
        </Space>
        <Table<Cliente>
          rowKey="id"
          size="small"
          loading={lista.isLoading}
          dataSource={filtrados}
          scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Cliente', dataIndex: 'nome' },
            { title: 'Endereço', dataIndex: 'slug' },
            { title: 'Plano', render: (_, c) => c.plano ?? '—' },
            { title: 'Estado', render: (_, c) => <Tag color={COR[c.estado]}>{NOME[c.estado]}</Tag> },
            { title: 'Empresas', dataIndex: 'empresas', align: 'right' },
            { title: 'Utilizadores', dataIndex: 'utilizadores', align: 'right' },
            { title: 'Criado em', render: (_, c) => formatarData(c.criado_em) },
            {
              title: '',
              render: (_, c) => (
                <Space>
                  <Button size="small" onClick={() => setVer(c.id)}>Detalhe</Button>
                  {c.estado === 'ATIVO' && c.id !== 1 && <Popconfirm title="Suspender o cliente? Os utilizadores deixam de entrar." onConfirm={() => estado.mutate({ id: c.id, estado: 'SUSPENSO' })}><Button size="small">Suspender</Button></Popconfirm>}
                  {c.estado === 'SUSPENSO' && <Button size="small" onClick={() => estado.mutate({ id: c.id, estado: 'ATIVO' })}>Activar</Button>}
                  {c.estado !== 'ARQUIVADO' && c.id !== 1 && <Popconfirm title="Arquivar? Os dados ficam guardados." onConfirm={() => arquivar.mutate(c.id)}><Button size="small" danger>Arquivar</Button></Popconfirm>}
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <Drawer open={ver !== null} onClose={() => setVer(null)} width={760} title={detalhe.data?.nome ?? 'Cliente'}>
        <p>Plano: <Select allowClear style={{ width: 240 }} placeholder="Sem plano" value={detalhe.data?.plano ?? undefined} options={(planos.data?.planos ?? []).map((p) => ({ value: p.codigo, label: p.nome }))} onChange={(v) => ver !== null && plano.mutate({ id: ver, plano: v ?? null })} /></p>
        <Tabs
          items={[
            { key: 'empresas', label: `Empresas (${detalhe.data?.empresas.length ?? 0})`, children: (
        <Table<EmpresaCliente>
          rowKey="id"
          size="small"
          loading={detalhe.isLoading}
          pagination={false}
          dataSource={detalhe.data?.empresas ?? []}
          columns={[
            { title: 'Empresa', dataIndex: 'nome' },
            { title: 'NIF', dataIndex: 'nif' },
            { title: 'Estado', dataIndex: 'estado' },
            { title: 'Licença', render: (_, e) => `${e.licenca_origem}${e.licenca_fim ? ` até ${formatarData(e.licenca_fim)}` : ''}` },
          ]}
        />
            ) },
            { key: 'utilizadores', label: `Utilizadores (${detalhe.data?.utilizadores ?? 0})`, children: ver !== null && <UtilizadoresCliente clienteId={ver} /> },
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