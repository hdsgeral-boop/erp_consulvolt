import { Button, Card, Flex, Form, Input, InputNumber, Modal, Popconfirm, Radio, Space, Table, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined, UsergroupAddOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { SeletorTerceiro } from '@/modulos/contab/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { formatarNumero } from '@/utilitarios/formatacao';
import { EtiquetaProjectos, SeletorColaborador, useEquipa } from '../comum/componentes';
import type { Membro } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';

/** Equipa do projecto: internos (colaboradores), terceiros e nomes livres, com papel e horas/dia alocadas (1 a 8). */
export function SeparadorEquipa({ projecto, acc }: PropsSeparador) {
  const e = useEquipa(projecto.id);
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const [membro, setMembro] = useState<Partial<Membro> | null>(null);
  const [massa, setMassa] = useState(false);
  const [alterar, setAlterar] = useState(false);
  const remover = useAccao({ invalidar: [['projectos']], aoSucesso: () => setSeleccao([]) });
  const membros = e.data?.membros ?? [];
  const horas = membros.reduce((t, m) => t + Number(m.horas_alocadas ?? 0), 0);
  const posicao = (p: Membro['posicao']) => (!p ? '—' : typeof p === 'string' ? p : p.titulo);

  return (
    <Card title={e.data?.equipa?.nome ?? 'Equipa'} extra={<Typography.Text type="secondary">{membros.length} membro(s) · {formatarNumero(horas)} h/dia alocadas</Typography.Text>}>
      {acc.gerir && (
        <Flex gap={8} wrap style={{ marginBottom: 12 }}>
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setMembro({ tipo: 'INTERNO' })}>Adicionar membro</Button>
          <Button icon={<UsergroupAddOutlined />} onClick={() => setMassa(true)}>Adicionar vários colaboradores</Button>
          {seleccao.length > 0 && <Button icon={<EditOutlined />} onClick={() => setAlterar(true)}>Alterar {seleccao.length}</Button>}
          {seleccao.length > 0 && (
            <Popconfirm title={`Remover ${seleccao.length} membro(s) da equipa?`} okText="Remover" cancelText="Cancelar" okButtonProps={{ danger: true }}
              onConfirm={() => remover.mutate({ url: `/projetos/${projecto.id}/equipa/membros/remover`, dados: { ids: seleccao } })}>
              <Button danger icon={<DeleteOutlined />} loading={remover.isPending}>Remover</Button>
            </Popconfirm>
          )}
        </Flex>
      )}
      <Table<Membro>
        rowKey="id"
        size="middle"
        loading={e.isFetching}
        dataSource={membros}
        pagination={false}
        rowSelection={acc.gerir ? { selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as number[]) } : undefined}
        columns={[
          { title: 'Nome', dataIndex: 'nome', render: (v, m) => <>{v}{m.nif && <Typography.Text type="secondary"> · NIF {m.nif}</Typography.Text>}</> },
          { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaProjectos valor={v} /> },
          { title: 'Papel', dataIndex: 'papel', render: (v) => v ?? '—' },
          { title: 'Horas/dia', dataIndex: 'horas_alocadas', align: 'right', render: (v) => formatarNumero(v) },
          { title: 'Posição no organigrama', dataIndex: 'posicao', render: posicao },
          { title: '', key: 'acc', align: 'right', render: (_, m) => acc.gerir && <Button size="small" icon={<EditOutlined />} onClick={() => setMembro(m)} /> },
        ]}
      />
      <ModalMembro projectoId={projecto.id} membro={membro} aoFechar={() => setMembro(null)} />
      <ModalMassa projectoId={projecto.id} aberto={massa} aoFechar={() => setMassa(false)} />
      <ModalAlterar projectoId={projecto.id} ids={seleccao} aberto={alterar} aoFechar={() => { setAlterar(false); setSeleccao([]); }} />
    </Card>
  );
}

function ModalMembro({ projectoId, membro, aoFechar }: { projectoId: number; membro: Partial<Membro> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const tipo = Form.useWatch('tipo', form);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!membro) return;
    form.resetFields();
    form.setFieldsValue({ ...membro, horas_alocadas: membro.horas_alocadas ? Number(membro.horas_alocadas) : 8 });
  }, [membro, form]);
  return (
    <Modal title={membro?.id ? `Editar ${membro.nome}` : 'Adicionar membro'} open={!!membro} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = { tipo: v.tipo, colaborador_id: v.tipo === 'INTERNO' ? v.colaborador_id : null, terceiro_id: v.tipo === 'TERCEIRO' ? v.terceiro_id : null,
          nome_externo: v.tipo === 'LIVRE' ? v.nome_externo : null, papel: v.papel || null, horas_alocadas: v.horas_alocadas ?? null };
        accao.mutate(membro?.id ? { metodo: 'put', url: `/projetos/${projectoId}/equipa/membros/${membro.id}`, dados } : { url: `/projetos/${projectoId}/equipa/membros`, dados });
      }}>
        <Form.Item name="tipo" label="Tipo">
          <Radio.Group optionType="button" disabled={!!membro?.id} options={[{ value: 'INTERNO', label: 'Colaborador' }, { value: 'TERCEIRO', label: 'Terceiro' }, { value: 'LIVRE', label: 'Nome livre' }]} />
        </Form.Item>
        {tipo === 'INTERNO' && <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}><SeletorColaborador disabled={!!membro?.id} /></Form.Item>}
        {tipo === 'TERCEIRO' && <Form.Item name="terceiro_id" label="Terceiro (subempreiteiro)" rules={[{ required: true, message: 'Escolha o terceiro.' }]}><SeletorTerceiro style={{ width: '100%' }} disabled={!!membro?.id} /></Form.Item>}
        {tipo === 'LIVRE' && <Form.Item name="nome_externo" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>}
        <Space>
          <Form.Item name="papel" label="Papel"><Input maxLength={100} style={{ width: 260 }} /></Form.Item>
          <Form.Item name="horas_alocadas" label="Horas/dia"><InputNumber min={1} max={8} step={0.5} style={{ width: 120 }} /></Form.Item>
        </Space>
      </Form>
    </Modal>
  );
}

function ModalMassa({ projectoId, aberto, aoFechar }: { projectoId: number; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ ids: number[]; papel?: string; horas_alocadas?: number }>();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => { if (aberto) form.setFieldsValue({ ids: [], papel: undefined, horas_alocadas: 8 }); }, [aberto, form]);
  return (
    <Modal title="Adicionar vários colaboradores" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Adicionar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/equipa/membros/massa`, dados: { tipo: 'INTERNO', ids: v.ids, papel: v.papel || null, horas_alocadas: v.horas_alocadas ?? null } })}>
        <Form.Item name="ids" label="Colaboradores" rules={[{ required: true, message: 'Escolha pelo menos um.' }]}><SeletorColaborador mode="multiple" /></Form.Item>
        <Space>
          <Form.Item name="papel" label="Papel"><Input maxLength={100} style={{ width: 260 }} /></Form.Item>
          <Form.Item name="horas_alocadas" label="Horas/dia"><InputNumber min={1} max={8} step={0.5} style={{ width: 120 }} /></Form.Item>
        </Space>
      </Form>
    </Modal>
  );
}

function ModalAlterar({ projectoId, ids, aberto, aoFechar }: { projectoId: number; ids: number[]; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ papel?: string; horas_alocadas?: number }>();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => { if (aberto) form.resetFields(); }, [aberto, form]);
  return (
    <Modal title={`Alterar ${ids.length} membro(s)`} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Aplicar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Typography.Paragraph type="secondary">Campos vazios ficam como estão.</Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/equipa/membros/alterar`, dados: { ids, papel: v.papel || null, horas_alocadas: v.horas_alocadas ?? null } })}>
        <Space>
          <Form.Item name="papel" label="Papel"><Input maxLength={100} style={{ width: 260 }} /></Form.Item>
          <Form.Item name="horas_alocadas" label="Horas/dia"><InputNumber min={1} max={8} step={0.5} style={{ width: 120 }} /></Form.Item>
        </Space>
      </Form>
    </Modal>
  );
}
