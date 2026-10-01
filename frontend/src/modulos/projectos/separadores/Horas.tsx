import { Button, Card, DatePicker, Form, InputNumber, Modal, Popconfirm, Space, Table, Typography } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { useAccao } from '@/componentes/Accoes';
import { SeletorActivo } from '@/modulos/activos/comum/componentes';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { EtiquetaProjectos, SeletorMembro, SeletorTarefa } from '../comum/componentes';
import type { EquipamentosProjecto, FolhaHoras, UsoEquipamento } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';

/** Folhas de horas (só colaboradores internos da equipa) e imputação do uso de equipamentos (activos) às tarefas. */
export function SeparadorHoras({ projecto, acc }: PropsSeparador) {
  const horas = useQuery({ queryKey: ['projectos', 'horas', projecto.id], queryFn: () => obter<FolhaHoras[]>(`/projetos/${projecto.id}/horas`) });
  const equip = useQuery({ queryKey: ['projectos', 'equipamentos', projecto.id], queryFn: () => obter<EquipamentosProjecto>(`/projetos/${projecto.id}/equipamentos`) });
  const [novaHora, setNovaHora] = useState(false);
  const [novoEquip, setNovoEquip] = useState(false);
  const accao = useAccao({ invalidar: [['projectos']] });
  const usos = equip.data?.usos ?? [];
  const totalHoras = (horas.data ?? []).reduce((t, h) => t + Number(h.horas), 0);
  const tarefa = (codigo: string | null | undefined, nome: string | null | undefined, id: number | null) => (nome ? [codigo, nome].filter(Boolean).join(' — ') : id ? `#${id}` : '—');

  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <Card size="small" title={`Folhas de horas (${formatarNumero(totalHoras)} h)`} extra={acc.execucao && <Button type="primary" size="small" icon={<PlusOutlined />} onClick={() => setNovaHora(true)}>Registar horas</Button>}>
        <Table<FolhaHoras>
          rowKey="id"
          size="small"
          loading={horas.isFetching}
          dataSource={horas.data}
          pagination={{ pageSize: 20 }}
          columns={[
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Colaborador', dataIndex: 'colaborador_nome', render: (v: string | null | undefined, h) => v ?? `#${h.colaborador_id}` },
            { title: 'Tarefa', key: 't', render: (_, h) => tarefa(h.tarefa_codigo, h.tarefa_nome, h.tarefa_projeto_id) },
            { title: 'Horas', dataIndex: 'horas', align: 'right', render: formatarNumero },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaProjectos valor={v} /> },
            {
              title: '', key: 'acc', align: 'right',
              render: (_, h) => acc.eliminar && h.estado === 'REGISTADO' && (
                <Popconfirm title="Eliminar este registo de horas?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                  onConfirm={() => accao.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/horas/${h.id}` })}>
                  <Button size="small" danger icon={<DeleteOutlined />} />
                </Popconfirm>
              ),
            },
          ]}
        />
      </Card>
      <Card size="small" title={`Uso de equipamentos (${formatarKz(equip.data?.total ?? '0.00')} Kz)`} extra={acc.execucao && <Button size="small" icon={<PlusOutlined />} onClick={() => setNovoEquip(true)}>Imputar equipamento</Button>}>
        <Table<UsoEquipamento>
          rowKey="id"
          size="small"
          loading={equip.isFetching}
          dataSource={usos}
          pagination={{ pageSize: 20 }}
          columns={[
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Equipamento', key: 'e', render: (_, m) => (m.ativo_codigo ? `${m.ativo_codigo}${m.ativo_descricao ? ` — ${m.ativo_descricao}` : ''}` : '—') },
            { title: 'Tarefa', key: 't', render: (_, m) => tarefa(m.tarefa_codigo, m.tarefa_nome, m.tarefa_projeto_id) },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
            { title: 'Custo (Kz)', dataIndex: 'montante', align: 'right', render: (v) => <ValorKz valor={v} /> },
            {
              title: '', key: 'acc', align: 'right',
              render: (_, m) => acc.eliminar && (
                <Popconfirm title="Eliminar este uso de equipamento?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                  onConfirm={() => accao.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/equipamentos/${m.id}` })}>
                  <Button size="small" danger icon={<DeleteOutlined />} />
                </Popconfirm>
              ),
            },
          ]}
        />
        {(equip.data?.afetacoes ?? []).length > 0 && (
          <>
            <Typography.Text strong style={{ display: 'block', marginTop: 12 }}>Activos afectos ao projecto</Typography.Text>
            <Table size="small" rowKey="id" pagination={false} dataSource={equip.data?.afetacoes ?? []} style={{ marginTop: 8 }}
              columns={[
                { title: 'Activo', key: 'a', render: (_, f) => `${f.ativo_codigo ?? `#${f.ativo_imobilizado_id}`}${f.ativo_descricao ? ` — ${f.ativo_descricao}` : ''}` },
                { title: 'De', dataIndex: 'data_inicio', render: formatarData },
                { title: 'Até', dataIndex: 'data_fim', render: (v: string | null) => (v ? formatarData(v) : 'em curso') },
              ]} />
          </>
        )}
      </Card>
      <ModalHoras projectoId={projecto.id} aberto={novaHora} aoFechar={() => setNovaHora(false)} />
      <ModalEquipamento projectoId={projecto.id} aberto={novoEquip} aoFechar={() => setNovoEquip(false)} />
    </Space>
  );
}

function ModalHoras({ projectoId, aberto, aoFechar }: { projectoId: number; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ data: dayjs(), horas: 8 }); } }, [aberto, form]);
  return (
    <Modal title="Registar horas" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/horas`, dados: { ...v, data: dataApi(v.data) } })}>
        <Form.Item name="colaborador_id" label="Colaborador (interno da equipa)" rules={[{ required: true, message: 'Escolha o colaborador.' }]}>
          <SeletorMembro projectoId={projectoId} apenasInternos valorColaborador />
        </Form.Item>
        <Form.Item name="tarefa_projeto_id" label="Tarefa" rules={[{ required: true, message: 'Escolha a tarefa.' }]}><SeletorTarefa projectoId={projectoId} /></Form.Item>
        <Space>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="horas" label="Horas" rules={[{ required: true }]}><InputNumber min={0.25} max={24} step={0.5} style={{ width: 120 }} /></Form.Item>
        </Space>
      </Form>
    </Modal>
  );
}

function ModalEquipamento({ projectoId, aberto, aoFechar }: { projectoId: number; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  const h = Form.useWatch('horas', form);
  const c = Form.useWatch('custo_hora', form);
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ data: dayjs(), horas: 8 }); } }, [aberto, form]);
  return (
    <Modal title="Imputar uso de equipamento" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Imputar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/equipamentos`, dados: { ...v, data: dataApi(v.data) } })}>
        <Form.Item name="ativo_imobilizado_id" label="Equipamento (activo)" rules={[{ required: true, message: 'Escolha o equipamento.' }]}><SeletorActivo apenasActivos /></Form.Item>
        <Form.Item name="tarefa_projeto_id" label="Tarefa" rules={[{ required: true, message: 'Escolha a tarefa.' }]}><SeletorTarefa projectoId={projectoId} /></Form.Item>
        <Space wrap>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="horas" label="Horas" rules={[{ required: true }]}><InputNumber min={0.25} step={0.5} style={{ width: 110 }} /></Form.Item>
          <Form.Item name="custo_hora" label="Custo/hora (Kz)" rules={[{ required: true, message: 'Indique o custo.' }]}><InputNumber min={0} precision={2} style={{ width: 150 }} /></Form.Item>
        </Space>
        <Typography.Text type="secondary">Custo a imputar: {formatarKz((Number(h ?? 0) * Number(c ?? 0)).toFixed(2), true)}</Typography.Text>
      </Form>
    </Modal>
  );
}
