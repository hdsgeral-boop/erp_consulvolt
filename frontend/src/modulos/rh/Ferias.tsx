import { Button, Card, DatePicker, Flex, Form, Input, InputNumber, Modal, Popconfirm, Progress, Select, Space, Table, Tabs, Tag, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { ESTADOS_FERIAS_RH, ROTULOS_ESTADO, type PeriodoFerias, type ResumoFerias } from './api';
import { contem, EstadoTag, PesquisaLocal, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores } from './comum/consultas';

interface Plano {
  resumo: ResumoFerias[];
  periodos: PeriodoFerias[];
}

interface ValoresFerias {
  colaborador_id: number;
  periodo: [Dayjs, Dayjs];
  estado: string;
  direito?: number | null;
  observacoes?: string;
}

/** RH › Programa de férias (ecrã rh_ferias): direito, marcação, aprovação e gozo das férias. */
export default function Ferias() {
  const { pode } = useSessao();
  const editar = pode('rh_ferias_edit');
  const cliente = useQueryClient();
  const colaboradores = useColaboradores();
  const [ano, setAno] = useState(dayjs().year());
  const [colaborador, setColaborador] = useState<number>();
  const [termo, setTermo] = useState('');
  const [edicao, setEdicao] = useState<PeriodoFerias | 'novo' | null>(null);
  const [form] = Form.useForm<ValoresFerias>();
  const plano = useQuery({ queryKey: ['rh', 'ferias', ano, colaborador], queryFn: () => obter<Plano>('/rh/ferias', { ano, colaborador_id: colaborador }) });
  useAvisarErro(plano.error);
  const accao = useAccaoRh();

  const gravar = useMutation({
    mutationFn: ({ dados, id }: { dados: Record<string, unknown>; id?: number }) => enviar(id ? 'put' : 'post', id ? `/rh/ferias/${id}` : '/rh/ferias', dados),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      void cliente.invalidateQueries({ queryKey: ['rh'] });
    },
    onError: (e, vars) => {
      if (e instanceof ErroApi && e.codigo === 'SALDO_EXCEDIDO' && !vars.dados.confirmar_excesso) {
        Modal.confirm({ title: 'Exceder o direito a férias?', content: e.message, okText: 'Gravar mesmo assim', cancelText: 'Cancelar', onOk: () => gravar.mutateAsync({ ...vars, dados: { ...vars.dados, confirmar_excesso: true } }) });
      } else notificarErro(e);
    },
  });

  const abrir = (p: PeriodoFerias | 'novo') => {
    form.resetFields();
    if (p === 'novo') form.setFieldsValue({ colaborador_id: colaborador, estado: 'PLANEADO' });
    else form.setFieldsValue({ colaborador_id: p.colaborador_id, periodo: [dayjs(p.data_inicio), dayjs(p.data_fim)], estado: p.estado, direito: p.direito, observacoes: p.observacoes ?? undefined });
    setEdicao(p);
  };

  const resumo = (plano.data?.resumo ?? []).filter((r) => contem(r.nome, termo));
  const periodos = (plano.data?.periodos ?? []).filter((p) => contem(colaboradores.nome(p.colaborador_id), termo));

  const colResumo: ColumnsType<ResumoFerias> = [
    { title: 'Colaborador', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong>, sorter: (a, b) => a.nome.localeCompare(b.nome, 'pt') },
    { title: 'Direito', dataIndex: 'direito', align: 'right' },
    { title: 'Marcados', dataIndex: 'marcados', align: 'right' },
    { title: 'Aprovados', dataIndex: 'aprovados', align: 'right' },
    { title: 'Gozados', dataIndex: 'gozados', align: 'right' },
    { title: 'Pedidos (portal)', dataIndex: 'pedidos', align: 'right' },
    { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (s: number) => <Tag color={s < 0 ? 'red' : s === 0 ? 'default' : 'green'}>{s}</Tag>, sorter: (a, b) => a.saldo - b.saldo },
    { title: 'Utilização', width: 160, render: (_, r) => <Progress size="small" percent={r.direito ? Math.round((r.marcados / r.direito) * 100) : 0} status={r.saldo < 0 ? 'exception' : 'normal'} /> },
  ];

  const colPeriodos: ColumnsType<PeriodoFerias> = [
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => colaboradores.nome(v) },
    { title: 'Início', dataIndex: 'data_inicio', render: formatarData },
    { title: 'Fim', dataIndex: 'data_fim', render: formatarData },
    { title: 'Dias úteis', dataIndex: 'dias', align: 'right' },
    {
      title: 'Estado',
      dataIndex: 'estado',
      render: (e: string, p) => editar && e !== 'PEDIDO' ? (
        <Select size="small" value={e} style={{ width: 140 }} onChange={(estado) => accao.mutate({ metodo: 'post', url: `/rh/ferias/${p.id}/estado`, dados: { estado } })}
          options={ESTADOS_FERIAS_RH.map((x) => ({ value: x, label: ROTULOS_ESTADO[x] }))} />
      ) : <EstadoTag estado={e} />,
    },
    { title: 'Origem', render: (_, p) => (p.pedido_portal_colaborador_id ? <Tag>Portal #{p.pedido_portal_colaborador_id}</Tag> : 'RH') },
    { title: 'Observações', dataIndex: 'observacoes', render: (v: string | null) => v ?? '' },
    {
      title: '',
      key: 'accoes',
      render: (_, p) => editar && (
        <Space size={4}>
          {p.estado !== 'PEDIDO' && <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => abrir(p)} />}
          {!p.pedido_portal_colaborador_id && (
            <Popconfirm title="Eliminar o período de férias?" okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar"
              onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/ferias/${p.id}` })}>
              <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Programa de férias"
        subtitulo="Dias úteis pelo calendário da empresa (feriados da configuração da Efectividade); os pedidos do portal decidem-se em «Pedidos do Portal»"
        accoes={editar && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Marcar férias</Button>}
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <InputNumber value={ano} min={2000} max={2100} onChange={(v) => v && setAno(v)} addonBefore="Ano" style={{ width: 150 }} />
          <SeletorColaborador value={colaborador} onChange={setColaborador} />
          <PesquisaLocal aoMudar={setTermo} placeholder="Nome" />
        </Flex>
        <Tabs items={[
          { key: 'resumo', label: 'Resumo por colaborador', children: <Table<ResumoFerias> rowKey="colaborador_id" size="small" loading={plano.isFetching} columns={colResumo} dataSource={resumo} pagination={{ pageSize: 50 }} scroll={{ x: 'max-content' }} /> },
          { key: 'periodos', label: `Períodos (${periodos.length})`, children: <Table<PeriodoFerias> rowKey="id" size="small" loading={plano.isFetching} columns={colPeriodos} dataSource={periodos} pagination={{ pageSize: 50 }} scroll={{ x: 'max-content' }} /> },
        ]} />
      </Card>
      <Modal title={edicao === 'novo' ? 'Marcar férias' : 'Editar férias'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar"
        confirmLoading={gravar.isPending} onOk={() => form.submit()} destroyOnClose>
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate({
          id: edicao && edicao !== 'novo' ? edicao.id : undefined,
          dados: { colaborador_id: v.colaborador_id, data_inicio: dataApi(v.periodo[0]), data_fim: dataApi(v.periodo[1]), estado: v.estado, direito: v.direito ?? null, observacoes: v.observacoes ?? null },
        })}>
          <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true }]}><SeletorColaborador apenasActivos style={{ width: '100%' }} disabled={edicao !== 'novo'} /></Form.Item>
          <Form.Item name="periodo" label="Período" rules={[{ required: true, message: 'Indique as datas.' }]}><DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="estado" label="Estado"><Select options={ESTADOS_FERIAS_RH.map((x) => ({ value: x, label: ROTULOS_ESTADO[x] }))} /></Form.Item>
          <Form.Item name="direito" label="Direito anual (dias úteis)" extra="Vazio = direito em vigor (22 por omissão). Gravar aplica-o a todos os períodos do ano."><InputNumber min={0} max={60} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}
