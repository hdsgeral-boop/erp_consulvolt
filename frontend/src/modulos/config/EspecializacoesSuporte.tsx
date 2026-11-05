import { useState } from 'react';
import { Alert, Button, Checkbox, Form, Input, Modal, Popconfirm, Space, Switch, Table, Tag, Typography, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';

export interface GrupoCategorias { id: string; rotulo: string; categorias: string[] }
export interface Especializacao {
  id: number; nome: string; descricao: string | null; ativo: boolean; grupos: string[]; categorias: string[];
  grupos_rotulos: string[]; agentes: number; base: boolean;
}
interface Resposta { especializacoes: Especializacao[]; grupos: GrupoCategorias[] }

export const CHAVE_ESPECIALIZACOES = ['plataforma', 'especializacoes_suporte'];

export const TEXTO_ESPECIALIZACAO =
  'Define quais as categorias de tickets (Geral, Financeiro, Marketing/Comunicação, Técnico) que o agente vê e responde; o agente nunca acede a clientes, planos nem definições.';

/** Gestão das especializações do backoffice de suporte (ADR-077): nome, descrição e categorias de tickets que abrange. */
export function EspecializacoesSuporte({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const qc = useQueryClient();
  const [editar, setEditar] = useState<Especializacao | 'nova' | null>(null);
  const [form] = Form.useForm<{ nome: string; descricao?: string; grupos: string[] }>();
  const dados = useQuery({ queryKey: CHAVE_ESPECIALIZACOES, queryFn: () => obter<Resposta>('/plataforma/especializacoes-suporte'), enabled: aberto });
  const refrescar = () => {
    qc.invalidateQueries({ queryKey: CHAVE_ESPECIALIZACOES });
    qc.invalidateQueries({ queryKey: ['plataforma', 'perfis_suporte'] });
    qc.invalidateQueries({ queryKey: ['plataforma', 'agentes_suporte'] });
  };
  const gravar = useMutation({
    mutationFn: (p: { id?: number; v: Record<string, unknown> }) => (p.id ? enviar('put', `/plataforma/especializacoes-suporte/${p.id}`, p.v) : enviar('post', '/plataforma/especializacoes-suporte', p.v)),
    onSuccess: () => { message.success('Especialização guardada.'); setEditar(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const apagar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/plataforma/especializacoes-suporte/${id}`),
    onSuccess: () => { message.success('Especialização eliminada.'); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const grupos = dados.data?.grupos ?? [];

  const abrir = (e: Especializacao | 'nova') => {
    form.resetFields();
    if (e !== 'nova') form.setFieldsValue({ nome: e.nome, descricao: e.descricao ?? '', grupos: e.grupos });
    setEditar(e);
  };

  return (
    <>
      <Modal open={aberto} title="Especializações de suporte" width={860} footer={<Button onClick={aoFechar}>Fechar</Button>} onCancel={aoFechar} destroyOnHidden>
        <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Para que serve" description={TEXTO_ESPECIALIZACAO} />
        <div style={{ textAlign: 'right', marginBottom: 8 }}>
          <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('nova')}>Nova especialização</Button>
        </div>
        <Table<Especializacao>
          rowKey="id" size="small" loading={dados.isLoading} dataSource={dados.data?.especializacoes ?? []} pagination={false} scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Especialização', render: (_, e) => (<div><strong>{e.nome}</strong>{e.base && <Tag style={{ marginLeft: 6 }}>base</Tag>}<div><Typography.Text type="secondary" style={{ fontSize: 12 }}>{e.descricao}</Typography.Text></div></div>) },
            { title: 'Categorias', render: (_, e) => e.grupos_rotulos.map((r) => <Tag key={r} color="purple">{r.split(' (')[0]}</Tag>) },
            { title: 'Agentes', dataIndex: 'agentes', align: 'right' },
            {
              title: 'Activa',
              render: (_, e) => <Switch size="small" checked={e.ativo} onChange={(v) => gravar.mutate({ id: e.id, v: { ativo: v } })} />,
            },
            {
              title: '', align: 'right',
              render: (_, e) => (
                <Space>
                  <Button size="small" icon={<EditOutlined />} onClick={() => abrir(e)}>Editar</Button>
                  <Popconfirm title="Eliminar esta especialização?" description={e.agentes ? 'Tem agentes associados: não pode ser eliminada.' : 'A acção não pode ser revertida.'} onConfirm={() => apagar.mutate(e.id)}>
                    <Button size="small" danger icon={<DeleteOutlined />}>Eliminar</Button>
                  </Popconfirm>
                </Space>
              ),
            },
          ]}
        />
      </Modal>

      <Modal
        open={editar !== null} title={editar === 'nova' ? 'Nova especialização' : 'Editar especialização'} okText="Guardar" confirmLoading={gravar.isPending}
        onCancel={() => setEditar(null)} destroyOnHidden
        onOk={() => form.validateFields().then((v) => gravar.mutate({ id: editar && editar !== 'nova' ? editar.id : undefined, v }))}
      >
        <Form form={form} layout="vertical">
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={100} /></Form.Item>
          <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={500} /></Form.Item>
          <Form.Item name="grupos" label="Categorias de tickets que abrange" rules={[{ required: true, type: 'array', min: 1, message: 'Escolha pelo menos uma categoria de tickets.' }]}
            extra="As permissões do agente são geradas a partir das categorias escolhidas; nunca dão acesso a clientes, planos nem definições.">
            <Checkbox.Group style={{ display: 'grid', gap: 8 }}>
              {grupos.map((g) => (
                <Checkbox key={g.id} value={g.id}>
                  <strong>{g.rotulo}</strong>
                  <div><Typography.Text type="secondary" style={{ fontSize: 12 }}>{g.categorias.join(', ')}</Typography.Text></div>
                </Checkbox>
              ))}
            </Checkbox.Group>
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
