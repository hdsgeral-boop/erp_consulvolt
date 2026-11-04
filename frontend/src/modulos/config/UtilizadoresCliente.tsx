import { Button, Form, Input, Modal, Popconfirm, Select, Space, Switch, Table, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';

interface UtilizadorCliente { id: number; nome_utilizador: string; nome_completo: string | null; email: string | null; papel: string; ativo: boolean }

const PAPEIS = [
  { value: 'SUPER_ADMINISTRADOR', label: 'Super-administrador' },
  { value: 'ADMINISTRADOR', label: 'Administrador' },
  { value: 'UTILIZADOR', label: 'Utilizador' },
];

/**
 * Utilizadores de um cliente, na consola da plataforma: editar nome, e-mail, papel e estado (PUT …/utilizadores/{id}) e
 * repor a palavra-passe. A nova palavra-passe é sempre gerada pelo servidor e enviada por e-mail (nunca escolhida aqui);
 * o utilizador é obrigado a trocá-la no próximo acesso.
 */
export function UtilizadoresCliente({ clienteId }: { clienteId: number }) {
  const qc = useQueryClient();
  const chave = ['plataforma', 'clientes', clienteId, 'utilizadores'];
  const lista = useQuery({ queryKey: chave, queryFn: () => obter<UtilizadorCliente[]>(`/plataforma/clientes/${clienteId}/utilizadores`) });
  const [editar, setEditar] = useState<UtilizadorCliente | null>(null);
  const [form] = Form.useForm();
  const refrescar = () => qc.invalidateQueries({ queryKey: chave });
  const gravar = useMutation({
    mutationFn: (p: { id: number; v: Record<string, unknown> }) => enviar('put', `/plataforma/clientes/${clienteId}/utilizadores/${p.id}`, p.v),
    onSuccess: () => { message.success('Utilizador actualizado.'); setEditar(null); void refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const repor = useMutation({
    mutationFn: (id: number) => enviar('post', `/plataforma/clientes/${clienteId}/utilizadores/${id}/redefinir`, {}),
    onSuccess: (r) => message.success(r.mensagem || 'Palavra-passe reposta. As sessões do utilizador terminaram.'),
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
          {
            title: 'Utilizador',
            render: (_, u) => (
              <>
                <div style={{ fontWeight: 600 }}>{u.nome_completo || u.nome_utilizador}</div>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>@{u.nome_utilizador}{u.email ? ` · ${u.email}` : ''}</Typography.Text>
              </>
            ),
          },
          { title: 'Papel', render: (_, u) => <Tag color={u.papel === 'SUPER_ADMINISTRADOR' ? 'purple' : u.papel === 'ADMINISTRADOR' ? 'blue' : 'default'}>{PAPEIS.find((p) => p.value === u.papel)?.label ?? u.papel}</Tag> },
          { title: 'Activo', render: (_, u) => <Switch size="small" aria-label={`Activo: ${u.nome_utilizador}`} checked={u.ativo} loading={gravar.isPending && gravar.variables?.id === u.id} onChange={(v) => gravar.mutate({ id: u.id, v: { ativo: v } })} /> },
          {
            title: '',
            render: (_, u) => (
              <Space>
                <Button size="small" onClick={() => { form.setFieldsValue({ nome_completo: u.nome_completo, email: u.email, papel: u.papel }); setEditar(u); }}>Editar</Button>
                <Popconfirm
                  title="Repor a palavra-passe?"
                  description={u.email ? `É gerada uma palavra-passe nova e enviada para ${u.email}. As sessões abertas terminam e a troca é obrigatória no próximo acesso.` : 'Este utilizador não tem e-mail: a palavra-passe nova não lhe pode ser enviada. Indique primeiro um e-mail (Editar).'}
                  okText="Repor"
                  cancelText="Cancelar"
                  okButtonProps={{ disabled: !u.email }}
                  onConfirm={() => repor.mutateAsync(u.id)}
                >
                  <Button size="small">Repor palavra-passe</Button>
                </Popconfirm>
              </Space>
            ),
          },
        ]}
      />
      <Modal open={editar !== null} title={`Editar ${editar?.nome_utilizador ?? ''}`} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onCancel={() => setEditar(null)} destroyOnHidden
        onOk={() => form.validateFields().then((v) => editar && gravar.mutate({ id: editar.id, v })).catch(() => undefined)}>
        <Form form={form} layout="vertical">
          <Form.Item name="nome_completo" label="Nome completo"><Input maxLength={200} /></Form.Item>
          <Form.Item name="email" label="E-mail" rules={[{ type: 'email', message: 'E-mail inválido.' }]}><Input maxLength={150} /></Form.Item>
          <Form.Item name="papel" label="Papel"><Select options={PAPEIS} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}
