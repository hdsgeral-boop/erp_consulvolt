import { Button, Card, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, message } from 'antd';
import { EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import dayjs from 'dayjs';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';

interface Cupom {
  id: number; codigo: string; descricao: string | null; tipo_desconto: 'PERCENTAGEM' | 'VALOR_FIXO'; valor_desconto: number;
  moeda: string; data_inicio: string; data_fim: string; limite_usos: number | null; usos_atuais: number; limite_por_cliente: number;
  planos_aplicaveis: number[] | null; ciclos_aplicaveis: string[] | null; ativo: boolean; usos_count: number; receita_descontada: number;
}
interface PlanoOpcao { id: number; codigo: string; nome: string }

const CHAVE = ['plataforma', 'cupoes'];
const CICLOS = [
  { value: 'MENSAL', label: 'Mensal' }, { value: 'TRIMESTRAL', label: 'Trimestral' },
  { value: 'SEMESTRAL', label: 'Semestral' }, { value: 'ANUAL', label: 'Anual' },
];

/** Plataforma SaaS — cupões e campanhas promocionais da subscrição (plataforma_cupoes, motor de precificação Módulo B). */
export default function PlataformaCupoes() {
  const qc = useQueryClient();
  const [edit, setEdit] = useState<Cupom | 'novo' | null>(null);
  const [form] = Form.useForm();

  const { data: cupoes, isLoading } = useQuery({ queryKey: CHAVE, queryFn: () => obter<Cupom[]>('/plataforma/cupoes') });
  const { data: planos } = useQuery({ queryKey: ['licenca', 'planos-todos'], queryFn: () => obter<PlanoOpcao[]>('/licenca/planos', { todos: 1 }) });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });

  const gravar = useMutation({
    mutationFn: (v: Record<string, unknown>) => (edit !== 'novo' && edit ? enviar('put', `/plataforma/cupoes/${edit.id}`, v) : enviar('post', '/plataforma/cupoes', v)),
    onSuccess: () => { message.success('Cupão guardado.'); setEdit(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const alternarEstado = useMutation({
    mutationFn: ({ id, ativo }: { id: number; ativo: boolean }) => enviar('put', `/plataforma/cupoes/${id}/estado`, { ativo }),
    onSuccess: () => refrescar(), onError: (e) => notificarErro(e),
  });

  const abrir = (c: Cupom | 'novo') => {
    setEdit(c);
    if (c === 'novo') {
      form.resetFields();
      form.setFieldsValue({ tipo_desconto: 'PERCENTAGEM', limite_por_cliente: 1, ativo: true, moeda: 'AOA' });
    } else {
      form.setFieldsValue({ ...c, periodo: [dayjs(c.data_inicio), dayjs(c.data_fim)] });
    }
  };

  const planosOpcoes = (planos ?? []).map((p) => ({ value: p.id, label: `${p.nome} (${p.codigo})` }));

  return (
    <>
      <CabecalhoPagina titulo="Cupões de desconto" subtitulo="Campanhas promocionais da subscrição SaaS — válido até 15% de desconto percentual"
        accoes={<Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Novo cupão</Button>} />
      <Card loading={isLoading}>
        <Table<Cupom> rowKey="id" dataSource={cupoes ?? []} pagination={{ pageSize: 20 }} columns={[
          { title: 'Código', dataIndex: 'codigo', render: (v) => <Tag color="geekblue">{v}</Tag> },
          { title: 'Desconto', render: (_, c) => c.tipo_desconto === 'PERCENTAGEM' ? `${formatarNumero(c.valor_desconto)}%` : `${formatarKz(c.valor_desconto)} ${c.moeda}` },
          { title: 'Período', render: (_, c) => `${formatarData(c.data_inicio)} – ${formatarData(c.data_fim)}` },
          { title: 'Usos', render: (_, c) => `${c.usos_atuais}${c.limite_usos ? ` / ${c.limite_usos}` : ''} (lim. ${c.limite_por_cliente}/cliente)` },
          { title: 'Receita descontada', align: 'right', render: (_, c) => `${formatarKz(c.receita_descontada)} ${c.moeda}` },
          { title: 'Activo', render: (_, c) => <Switch checked={c.ativo} loading={alternarEstado.isPending} onChange={(ativo) => alternarEstado.mutate({ id: c.id, ativo })} /> },
          { title: '', align: 'right', render: (_, c) => <Button size="small" icon={<EditOutlined />} onClick={() => abrir(c)}>Editar</Button> },
        ]} />
      </Card>

      <Modal open={edit !== null} title={edit === 'novo' ? 'Novo cupão' : `Cupão ${edit?.codigo ?? ''}`}
        okText="Guardar" confirmLoading={gravar.isPending} onCancel={() => setEdit(null)} destroyOnClose width={640}
        onOk={() => form.validateFields().then((v) => gravar.mutate({
          ...v, data_inicio: dataApi(v.periodo?.[0]), data_fim: dataApi(v.periodo?.[1]), periodo: undefined,
        }))}>
        <Form form={form} layout="vertical">
          <Space.Compact block>
            <Form.Item name="codigo" label="Código" rules={[{ required: true }, { pattern: /^[A-Za-z0-9_-]+$/, message: 'Só letras, números, _ e -' }]} style={{ width: '60%' }}>
              <Input placeholder="PROMO2026" style={{ textTransform: 'uppercase' }} />
            </Form.Item>
            <Form.Item name="moeda" label="Moeda" style={{ width: '40%' }}><Input maxLength={3} /></Form.Item>
          </Space.Compact>
          <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
          <Space.Compact block>
            <Form.Item name="tipo_desconto" label="Tipo de desconto" rules={[{ required: true }]} style={{ width: '50%' }}>
              <Select options={[{ value: 'PERCENTAGEM', label: 'Percentagem' }, { value: 'VALOR_FIXO', label: 'Valor fixo' }]} />
            </Form.Item>
            <Form.Item name="valor_desconto" label="Valor" rules={[{ required: true }]} style={{ width: '50%' }}>
              <InputNumber min={0.01} max={999999} precision={2} style={{ width: '100%' }} />
            </Form.Item>
          </Space.Compact>
          <Form.Item name="periodo" label="Período de validade" rules={[{ required: true }]}>
            <DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
          <Space.Compact block>
            <Form.Item name="limite_usos" label="Limite total de usos (vazio = ilimitado)" style={{ width: '50%' }}>
              <InputNumber min={1} style={{ width: '100%' }} />
            </Form.Item>
            <Form.Item name="limite_por_cliente" label="Limite por cliente" rules={[{ required: true }]} style={{ width: '50%' }}>
              <InputNumber min={1} style={{ width: '100%' }} />
            </Form.Item>
          </Space.Compact>
          <Form.Item name="planos_aplicaveis" label="Planos aplicáveis (vazio = todos)"><Select mode="multiple" options={planosOpcoes} allowClear /></Form.Item>
          <Form.Item name="ciclos_aplicaveis" label="Ciclos aplicáveis (vazio = todos)"><Select mode="multiple" options={CICLOS} allowClear /></Form.Item>
          <Form.Item name="ativo" label="Activo" valuePropName="checked"><Switch /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}
