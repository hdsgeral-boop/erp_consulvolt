import { Button, Card, DatePicker, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Table } from 'antd';
import { CheckOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { useAccao } from '@/componentes/Accoes';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { somar } from '@/utilitarios/decimal';
import { pedidoTodasPaginas } from './comum/impressao';
import { EtiquetaActivos, SeletorActivo } from './comum/componentes';
import { filtroPeriodo, useListaPaginada } from './comum/paginacao';
import type { Manutencao as RegistoManutencao } from './comum/tipos';

/** Activos › Manutenções (ecrã activos_manutencao): registo de manutenções preventivas e correctivas e respectiva conclusão. */
export default function Manutencao() {
  const { pode } = useSessao();
  const [activo, setActivo] = useState<number>();
  const [estado, setEstado] = useState<string>();
  const [nova, setNova] = useState(false);
  const [concluir, setConcluir] = useState<RegistoManutencao | null>(null);
  const [tipo, setTipo] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  // paginado e filtrado no servidor (ADR-064)
  const q = useListaPaginada<RegistoManutencao>(['activos', 'manutencoes'], '/ativos/manutencoes', { ativo_imobilizado_id: activo, estado, tipo, ...filtroPeriodo(periodo) });
  const eliminar = useAccao({ invalidar: [['activos']] });
  const gerir = pode('activos_manut');

  const colunas: ColunaApi<RegistoManutencao>[] = [
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Activo', key: 'a', render: (_, r) => (r.ativo_imobilizado ? `${r.ativo_imobilizado.codigo} — ${r.ativo_imobilizado.descricao}` : r.ativo_imobilizado_id) },
    { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaActivos valor={v} /> },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
    { title: 'Custo (Kz)', dataIndex: 'custo', align: 'right', render: (v) => <ValorKz valor={v} />, totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.custo))) },
    { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaActivos valor={v} /> },
    { title: 'Execução', dataIndex: 'data_execucao', responsive: ['md'], render: formatarData },
    { title: 'Resolução', dataIndex: 'resolucao', ellipsis: true, width: 220, responsive: ['lg'], render: (v) => v ?? '—' },
    {
      title: '', key: 'acc', align: 'right',
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
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Manutenções"
        subtitulo="Intervenções preventivas e correctivas nos activos"
        accoes={gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNova(true)}>Registar manutenção</Button>}
      />
      <Card>
        <BarraFiltros accoes={<>
          <BotoesExportar desactivado={!q.itens.length} obterPedido={() => pedidoTodasPaginas('/ativos/manutencoes', { ativo_imobilizado_id: activo, estado, tipo, ...filtroPeriodo(periodo) }, {
            titulo: 'Manutenções de activos',
            periodo: periodo?.[0] || periodo?.[1] ? `${periodo?.[0]?.format('DD/MM/YYYY') ?? '…'} a ${periodo?.[1]?.format('DD/MM/YYYY') ?? '…'}` : undefined,
            filtros: [estado ? `Estado: ${estado}` : null, tipo ? `Tipo: ${tipo}` : null, activo ? `Activo: #${activo}` : null],
            colunas,
          })} />
          <BotaoCsv nome="manutencoes_pagina" linhas={q.itens} colunas={[
            { titulo: 'Data', valor: (l) => formatarData(l.data) }, { titulo: 'Activo', valor: (l) => l.ativo_imobilizado?.codigo },
            { titulo: 'Tipo', valor: (l) => l.tipo }, { titulo: 'Descrição', valor: (l) => l.descricao }, { titulo: 'Custo', valor: (l) => l.custo, numerico: true },
            { titulo: 'Estado', valor: (l) => l.estado }, { titulo: 'Resolução', valor: (l) => l.resolucao },
          ]} />
        </>}>
            <SeletorActivo allowClear value={activo} onChange={setActivo} style={{ width: 320 }} />
            <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 160 }}
              options={[{ value: 'PLANEADA', label: 'Planeada' }, { value: 'CONCLUIDA', label: 'Concluída' }]} />
            <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 150 }}
              options={[{ value: 'PREVENTIVA', label: 'Preventiva' }, { value: 'CORRECTIVA', label: 'Correctiva' }]} />
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} allowEmpty={[true, true]} placeholder={['Desde', 'Até']} />
        </BarraFiltros>
        <Table<RegistoManutencao>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={q.itens}
          pagination={q.paginacao}
          scroll={{ x: 'max-content' }}
          columns={colunas}
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
    <Modal title="Registar manutenção" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
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
    <Modal title="Concluir manutenção" open={!!registo} onCancel={aoFechar} onOk={() => form.submit()} okText="Concluir" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
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
