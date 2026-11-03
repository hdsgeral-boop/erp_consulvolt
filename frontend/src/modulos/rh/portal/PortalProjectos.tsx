import { Button, Card, Col, DatePicker, Empty, Form, Input, InputNumber, Modal, Progress, Row, Select, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { IconeFa } from '@/componentes/fluxos';
import { scrollTabela } from '@/componentes/responsivo';
import { dataApi, formatarData, formatarNumero } from '@/utilitarios/formatacao';
import { useMutacao } from '@/modulos/estrutura/tarefas/comum';
import { useAvisarErro } from '../comum/consultas';
import { horasProjecto, registarHoras, registarMedicao, tarefasProjecto, type TarefaProjectoPortal } from './api';

const CHAVE = ['rh', 'portal', 'projetos'];
const rotuloTarefa = (t: TarefaProjectoPortal) => `${t.projeto_codigo ?? t.projeto_nome} · ${t.codigo ? `${t.codigo} ` : ''}${t.nome}`;

/**
 * Portal › Projectos (portal_ui.js desenharProjectos, 792-900): as duas coisas que o colaborador faz todos os dias e que
 * obrigavam a entrar nos ecrãs de gestão do projecto — lançar as HORAS e actualizar a FOLHA DE MEDIÇÃO das tarefas pelas
 * quais responde (atribuídas a si ou à posição que ocupa no organigrama do projecto). Rotas `rh/portal/projetos/*`.
 */
export function PortalProjectos() {
  const [mes, setMes] = useState<Dayjs>(dayjs().startOf('month'));
  const tarefas = useQuery({ queryKey: [...CHAVE, 'tarefas'], queryFn: tarefasProjecto, retry: false });
  const horas = useQuery({ queryKey: [...CHAVE, 'horas', mes.format('YYYY-MM')], queryFn: () => horasProjecto(mes.format('YYYY-MM')), retry: false });
  useAvisarErro(tarefas.error, 'Não foi possível carregar as tarefas de projecto');
  const [formH] = Form.useForm<{ tarefa_projeto_id: number; data: Dayjs; horas: number }>();
  const [medir, setMedir] = useState<TarefaProjectoPortal | null>(null);
  const [formM] = Form.useForm<{ quantidade: number; data?: Dayjs | null; nota?: string }>();
  const lancar = useMutacao((d: { tarefa_projeto_id: number; data: string; horas: number }) => registarHoras(d), { invalidar: [CHAVE], aoSucesso: () => formH.resetFields(['horas']) });
  const medicao = useMutacao((p: { id: number; d: { quantidade: number; data?: string | null; nota?: string | null } }) => registarMedicao(p.id, p.d), { invalidar: [CHAVE, ['projectos']], aoSucesso: () => setMedir(null) });
  const lista = Array.isArray(tarefas.data) ? tarefas.data : [];
  const comMeta = lista.filter((t) => t.tem_meta);
  const lancamentos = Array.isArray(horas.data) ? horas.data : [];
  const totalMes = lancamentos.reduce((s, h) => s + Number(h.horas || 0), 0);
  const projectos = new Set(lista.map((t) => t.projeto_id)).size;

  return (
    <>
      <Card title={<Space><IconeFa nome="clock" />Registar horas</Space>}>
        <Typography.Paragraph type="secondary">Só aparecem as tarefas pelas quais responde — atribuídas a si ou da posição que ocupa no organigrama do projecto. Está em {projectos} projecto(s), com {lista.length} tarefa(s) à sua responsabilidade.</Typography.Paragraph>
        {lista.length ? (
          <Form form={formH} layout="vertical" initialValues={{ data: dayjs() }} onFinish={(v) => lancar.mutate({ tarefa_projeto_id: v.tarefa_projeto_id, data: dataApi(v.data)!, horas: v.horas })}>
            <Row gutter={12} align="bottom">
              <Col xs={24} md={10}><Form.Item name="tarefa_projeto_id" label="Tarefa" rules={[{ required: true, message: 'Escolha a tarefa.' }]}><Select showSearch optionFilterProp="label" options={lista.map((t) => ({ value: t.id, label: `${rotuloTarefa(t)}${t.por_posicao ? ' — pela posição' : ''}` }))} /></Form.Item></Col>
              <Col xs={12} md={5}><Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isAfter(dayjs(), 'day')} /></Form.Item></Col>
              <Col xs={12} md={4}><Form.Item name="horas" label="Horas" rules={[{ required: true, message: 'Indique as horas.' }]}><InputNumber min={0.5} max={24} step={0.5} style={{ width: '100%' }} placeholder="8" /></Form.Item></Col>
              <Col xs={24} md={5}><Form.Item><Button type="primary" htmlType="submit" icon={<IconeFa nome="plus" />} loading={lancar.isPending}>Lançar</Button></Form.Item></Col>
            </Row>
          </Form>
        ) : !tarefas.isLoading && <Empty description="Não tem tarefas à sua responsabilidade em projectos abertos." />}
        <Space wrap align="center" style={{ margin: '8px 0' }}>
          <Typography.Text strong>Horas lançadas — {formatarNumero(totalMes)} h em {lancamentos.length} lançamento(s)</Typography.Text>
          <DatePicker picker="month" format="MM/YYYY" allowClear={false} value={mes} onChange={(v) => v && setMes(v)} aria-label="Mês" />
        </Space>
        <Table rowKey="id" size="small" loading={horas.isFetching} dataSource={lancamentos} pagination={{ pageSize: 20, hideOnSinglePage: true }} scroll={scrollTabela()}
          locale={{ emptyText: 'Ainda não lançou horas neste mês.' }}
          columns={[
            { title: 'Data', dataIndex: 'data', render: (v: string | null) => formatarData(v) },
            { title: 'Tarefa', key: 't', render: (_, h) => `${h.tarefa_codigo ? `${h.tarefa_codigo} ` : ''}${h.tarefa_nome ?? `Tarefa #${h.tarefa_projeto_id}`}` },
            { title: 'Horas', dataIndex: 'horas', align: 'right', render: (v: number) => <b>{formatarNumero(v)}</b> },
            { title: 'Estado', dataIndex: 'estado', render: (v: string | null) => (v ? <Tag>{v}</Tag> : '—') },
          ]} />
      </Card>

      <Card title={<Space><IconeFa nome="ruler-combined" />Folhas de medição</Space>} style={{ marginTop: 16 }}>
        <Typography.Paragraph type="secondary">As tarefas à sua responsabilidade que têm meta por quantidade. Registar aqui actualiza a execução da tarefa, como na folha de medição do projecto.</Typography.Paragraph>
        <Table<TarefaProjectoPortal> rowKey="id" size="small" loading={tarefas.isFetching} dataSource={comMeta} pagination={false} scroll={scrollTabela()}
          locale={{ emptyText: 'Nenhuma das tarefas à sua responsabilidade tem meta por quantidade definida.' }}
          columns={[
            { title: 'Projecto', key: 'p', render: (_, t) => <span style={{ whiteSpace: 'nowrap' }}>{t.projeto_codigo ?? t.projeto_nome}</span> },
            { title: 'Tarefa', key: 't', render: (_, t) => <>{t.codigo ? `${t.codigo} ` : ''}{t.nome}{t.por_posicao && <small style={{ color: '#3730a3', marginLeft: 6 }} title="Pela posição que ocupa no organigrama do projecto"><IconeFa nome="sitemap" /> posição</small>}</> },
            { title: 'Meta', key: 'm', align: 'right', render: (_, t) => <span style={{ whiteSpace: 'nowrap' }}>{formatarNumero(t.meta_quantidade)} {t.meta_unidade ?? 'un'}</span> },
            { title: 'Realizado', dataIndex: 'meta_realizado', align: 'right', render: (v: number) => <b>{formatarNumero(v)}</b> },
            { title: 'Falta', key: 'f', align: 'right', render: (_, t) => { const falta = Math.max(0, Math.round((t.meta_quantidade - t.meta_realizado) * 100) / 100); return <span style={{ color: falta > 0 ? '#b45309' : '#166534' }}>{formatarNumero(falta)}</span>; } },
            { title: 'Execução', key: 'e', width: 140, render: (_, t) => <Progress size="small" percent={Math.min(100, Math.round(t.pct_meta ?? (t.meta_quantidade > 0 ? (t.meta_realizado / t.meta_quantidade) * 100 : 0)))} /> },
            { title: '', key: 'a', render: (_, t) => <Button size="small" type="primary" icon={<IconeFa nome="ruler-combined" />} onClick={() => { formM.resetFields(); formM.setFieldsValue({ data: dayjs() }); setMedir(t); }}>Medir</Button> },
          ]} />
      </Card>

      <Modal open={medir !== null} title={medir ? `Medição — ${rotuloTarefa(medir)}` : ''} onCancel={() => setMedir(null)} okText="Registar" cancelText="Cancelar" confirmLoading={medicao.isPending} onOk={() => formM.submit()} destroyOnHidden>
        {medir && <Typography.Paragraph type="secondary">Meta {formatarNumero(medir.meta_quantidade)} {medir.meta_unidade ?? 'un'} · realizado {formatarNumero(medir.meta_realizado)}.</Typography.Paragraph>}
        <Form form={formM} layout="vertical" onFinish={(v) => medir && medicao.mutate({ id: medir.id, d: { quantidade: v.quantidade, data: dataApi(v.data ?? null) ?? null, nota: v.nota?.trim() || null } })}>
          <Form.Item name="quantidade" label={`Quantidade (${medir?.meta_unidade ?? 'un'})`} rules={[{ required: true, message: 'Indique a quantidade.' }]}><InputNumber min={0.0001} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="data" label="Data"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isAfter(dayjs(), 'day')} /></Form.Item>
          <Form.Item name="nota" label="Nota"><Input maxLength={500} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}
