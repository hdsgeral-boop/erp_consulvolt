import { Button, Card, DatePicker, Flex, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Table } from 'antd';
import { CheckOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { EtiquetaActivos, SeletorActivo } from './comum/componentes';
import type { Manutencao as RegistoManutencao } from './comum/tipos';

/** Activos › Manutenções (ecrã activos_manutencao): registo de manutenções preventivas e correctivas e respectiva conclusão. */
export default function Manutencao() {
  const { pode } = useSessao();
  const [activo, setActivo] = useState<number>();
  const [estado, setEstado] = useState<string>();
  const [nova, setNova] = useState(false);
  const [concluir, setConcluir] = useState<RegistoManutencao | null>(null);
  const q = useQuery({
    queryKey: ['activos', 'manutencoes', activo, estado],
    queryFn: () => obter<RegistoManutencao[]>('/ativos/manutencoes', { ativo_imobilizado_id: activo, estado }),
  });
  const eliminar = useAccao({ invalidar: [['activos']] });
  const gerir = pode('activos_manut');

  return (
    <>
      <CabecalhoPagina
        titulo="Manutenções"
        subtitulo="Intervenções preventivas e correctivas nos activos"
        accoes={gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNova(true)}>Registar manutenção</Button>}
      />
      <Card>
        <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
          <Flex gap={8} wrap>
            <SeletorActivo allowClear value={activo} onChange={setActivo} style={{ width: 320 }} />
            <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 160 }}
              options={[{ value: 'PLANEADA', label: 'Planeada' }, { value: 'CONCLUIDA', label: 'Concluída' }]} />
          </Flex>
          <BotaoCsv nome="manutencoes" linhas={q.data} colunas={[
            { titulo: 'Data', valor: (l) => formatarData(l.data) }, { titulo: 'Activo', valor: (l) => l.ativo_imobilizado?.codigo },
            { titulo: 'Tipo', valor: (l) => l.tipo }, { titulo: 'Descrição', valor: (l) => l.descricao }, { titulo: 'Custo', valor: (l) => l.custo, numerico: true },
            { titulo: 'Estado', valor: (l) => l.estado }, { titulo: 'Resolução', valor: (l) => l.resolucao },
          ]} />
        </Flex>
        <Table<RegistoManutencao>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={q.data}
          scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Activo', key: 'a', render: (_, r) => (r.ativo_imobilizado ? `${r.ativo_imobilizado.codigo} — ${r.ativo_imobilizado.descricao}` : r.ativo_imobilizado_id) },
            { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaActivos valor={v} /> },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
            { title: 'Custo (Kz)', dataIndex: 'custo', align: 'right', render: (v) => <ValorKz valor={v} /> },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaActivos valor={v} /> },
            { title: 'Execução', dataIndex: 'data_execucao', render: formatarData },
            { title: 'Resolução', dataIndex: 'resolucao', ellipsis: true, width: 220, render: (v) => v ?? '—' },
            {
              title: '', key: 'acc', align: 'right', fixed: 'right',
              render: (_, r) => gerir && (
                <Space>
                  {r.estado !== 'CONCLUIDA' && <Button size="small" icon={<CheckOutlined />} onClick={() => setConcluir(r)}>Concluir</Button>}
                  <Popconfirm title="Remover esta manutenção?" okText="Remover" cancelText="Cancelar" okButtonProps={{ danger: true }}
                    onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/ativos/manutencoes/${r.id}` })}>
                    <Button size="small" danger icon={<DeleteOutlined />} />
                  </Popconfirm>
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <ModalManutencao aberto={nova} aoFechar={() => setNova(false)} />
      <ModalConcluir registo={concluir} aoFechar={() => setConcluir(null)} />
    </>
  );
}

/** Registo de uma manutenção. Com custo > 0 o servidor grava-a logo como concluída. */
export function ModalManutencao({ aberto, activoId, aoFechar }: { aberto: boolean; activoId?: number; aoFechar: () => void }) {
  const [form] = Form.useForm<{ ativo_imobilizado_id: number; tipo: string; data: dayjs.Dayjs; descricao?: string; custo?: number }>();
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (aberto) {
      form.resetFields();
      form.setFieldsValue({ ativo_imobilizado_id: activoId, tipo: 'PREVENTIVA', data: dayjs() });
    }
  }, [aberto, activoId, form]);
  return (
    <Modal title="Registar manutenção" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: '/ativos/manutencoes', dados: { ...v, data: dataApi(v.data), custo: v.custo ?? 0 } })}>
        <Form.Item name="ativo_imobilizado_id" label="Activo" rules={[{ required: true, message: 'Escolha o activo.' }]}>
          <SeletorActivo disabled={!!activoId} />
        </Form.Item>
        <Space wrap>
          <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
            <Select style={{ width: 160 }} options={[{ value: 'PREVENTIVA', label: 'Preventiva' }, { value: 'CORRECTIVA', label: 'Correctiva' }]} />
          </Form.Item>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}>
            <DatePicker format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item name="custo" label="Custo (Kz)" tooltip="Com custo, a manutenção fica concluída">
            <InputNumber min={0} precision={2} style={{ width: 160 }} />
          </Form.Item>
        </Space>
        <Form.Item name="descricao" label="Descrição">
          <Input.TextArea rows={3} maxLength={5000} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

function ModalConcluir({ registo, aoFechar }: { registo: RegistoManutencao | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ resolucao: string; custo?: number }>();
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (registo) form.setFieldsValue({ resolucao: '', custo: registo.custo ? Number(registo.custo) : undefined });
  }, [registo, form]);
  return (
    <Modal title="Concluir manutenção" open={!!registo} onCancel={aoFechar} onOk={() => form.submit()} okText="Concluir" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/ativos/manutencoes/${registo?.id}/executar`, dados: { ...v, custo: v.custo ?? 0 } })}>
        <Form.Item name="resolucao" label="Resolução" rules={[{ required: true, message: 'Descreva a resolução.' }]}>
          <Input.TextArea rows={3} maxLength={5000} />
        </Form.Item>
        <Form.Item name="custo" label="Custo (Kz)">
          <InputNumber min={0} precision={2} style={{ width: 200 }} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
