import { Button, Form, Input, Modal, Select, Space, Switch, Table, Tag, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { CampoPalavraPasse } from './CampoPalavraPasse';

interface UtilizadorCliente { id: number; nome_utilizador: string; nome_completo: string | null; email: string | null; papel: string; ativo: boolean }

const PAPEIS = [
  { value: 'SUPER_ADMINISTRADOR', label: 'Super administrador' },
  { value: 'ADMINISTRADOR', label: 'Administrador' },
  { value: 'UTILIZADOR', label: 'Utilizador' },
];

/**
 * Utilizadores de um cliente, na consola da plataforma: editar nome, e-mail, papel e estado, e repor a palavra-passe
 * (provisória: o utilizador é convidado a alterá-la no próximo acesso).
 */
export function UtilizadoresCliente({ clienteId }: { clienteId: number }) {
  const qc = useQueryClient();
  const chave = ['plataforma', 'clientes', clienteId, 'utilizadores'];
  const lista = useQuery({ queryKey: chave, queryFn: () => obter<UtilizadorCliente[]>(`/plataforma/clientes/${clienteId}/utilizadores`) });
  const [editar, setEditar] = useState<UtilizadorCliente | null>(null);
  const [repor, setRepor] = useState<UtilizadorCliente | null>(null);
  const [form] = Form.useForm();
  const [formSenha] = Form.useForm();
  const refrescar = () => qc.invalidateQueries({ queryKey: chave });
  const gravar = useMutation({
    mutationFn: (p: { id: number; v: Record<string, unknown> }) => enviar('put', `/plataforma/clientes/${clienteId}/utilizadores/${p.id}`, p.v),
    onSuccess: () => { message.success('Utilizador actualizado.'); setEditar(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const senha = useMutation({
    mutationFn: (p: { id: number; palavra_passe: string }) => enviar('post', `/plataforma/clientes/${clienteId}/utilizadores/${p.id}/redefinir`, { palavra_passe: p.palavra_passe }),
    onSuccess: () => { message.success('Palavra-passe reposta. As sessões do utilizador terminaram.'); setRepor(null); },
    onError: (e) => notificarErro(e),
  });

  return (
    <>
      <Table<UtilizadorCliente>
        rowKey="id"
        size="small"
        loading={lista.isLoading}
        dataSource={lista.data ?? []}
        pagination={{ pageSize: 10, hideOnSinglePage: true }}
        scroll={{ x: 'max-content' }}
        columns={[
          { title: 'Utilizador', render: (_, u) => <><div style={{ fontWeight: 600 }}>{u.nome_completo || u.nome_utilizador}</div><div style={{ fontSize: 12, color: '#64748b' }}>@{u.nome_utilizador}{u.email ? ` · ${u.email}` : ''}</div></> },
          { title: 'Papel', render: (_, u) => <Tag color={u.papel === 'SUPER_ADMINISTRADOR' ? 'purple' : u.papel === 'ADMINISTRADOR' ? 'blue' : 'default'}>{PAPEIS.find((p) => p.value === u.papel)?.label ?? u.papel}</Tag> },
          { title: 'Activo', render: (_, u) => <Switch size="small" checked={u.ativo} onChange={(v) => gravar.mutate({ id: u.id, v: { ativo: v } })} /> },
          {
            title: '',
            render: (_, u) => (
              <Space>
                <Button size="small" onClick={() => { form.setFieldsValue({ nome_completo: u.nome_completo, email: u.email, papel: u.papel }); setEditar(u); }}>Editar</Button>
                <Button size="small" onClick={() => { formSenha.resetFields(); setRepor(u); }}>Repor palavra-passe</Button>
              </Space>
            ),
          },
        ]}
      />
      <Modal open={editar !== null} title={`Editar ${editar?.nome_utilizador ?? ''}`} okText="Gravar" confirmLoading={gravar.isPending} onCancel={() => setEditar(null)} destroyOnHidden
        onOk={() => form.validateFields().then((v) => editar && gravar.mutate({ id: editar.id, v }))}>
        <Form form={form} layout="vertical">
          <Form.Item name="nome_completo" label="Nome completo"><Input maxLength={200} /></Form.Item>
          <Form.Item name="email" label="E-mail" rules={[{ type: 'email', message: 'E-mail inválido.' }]}><Input maxLength={150} /></Form.Item>
          <Form.Item name="papel" label="Papel"><Select options={PAPEIS} /></Form.Item>
        </Form>
      </Modal>
      <Modal open={repor !== null} title={`Repor palavra-passe — ${repor?.nome_utilizador ?? ''}`} okText="Repor" confirmLoading={senha.isPending} onCancel={() => setRepor(null)} destroyOnHidden
        onOk={() => formSenha.validateFields().then((v) => repor && senha.mutate({ id: repor.id, palavra_passe: v.palavra_passe }))}>
        <Form form={formSenha} layout="vertical">
          <CampoPalavraPasse form={formSenha} obrigatorio rotulo="Nova palavra-passe (provisória)" minimo={10} />
        </Form>
      </Modal>
    </>
  );
}
