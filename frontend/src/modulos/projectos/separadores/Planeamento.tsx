import { Button, Card, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Row, Segmented, Select, Slider, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, FlagOutlined, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import dayjs from 'dayjs';
import { useEffect, useMemo, useRef, useState } from 'react';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { rotuloProjectos } from '../comum/componentes';
import { useAccao } from '@/componentes/Accoes';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { BarraExecucao, EtiquetaProjectos, ModalEliminar, SeletorMembro, SeletorTarefa, useEquipa, useWbs } from '../comum/componentes';
import { Gantt } from '../comum/Gantt';
import { linhasGanttWbs, tarefaAtrasada } from '../comum/regras';
import type { Marco, TarefaWbs } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';

type LinhaArvore = (TarefaWbs & { chave: string; children?: LinhaArvore[] }) | { chave: string; grupo: true; marco: Marco | null; execucao: number; children?: LinhaArvore[] };

function paraArvore(t: TarefaWbs): LinhaArvore {
  return { ...t, chave: `t-${t.id}`, children: t.subtarefas?.length ? t.subtarefas.map(paraArvore) : undefined };
}

/** WBS: milestones, tarefas e subtarefas (árvore), execução, e vista Gantt do projecto. */
export function SeparadorPlaneamento({ projecto, acc }: PropsSeparador) {
  const w = useWbs(projecto.id);
  const equipa = useEquipa(projecto.id);
  const [vista, setVista] = useState<'wbs' | 'gantt'>('wbs');
  const [tarefa, setTarefa] = useState<Partial<TarefaWbs> | null>(null);
  const [marco, setMarco] = useState<Partial<Marco> | null>(null);
  const [execucao, setExecucao] = useState<TarefaWbs | null>(null);
  const [eliminar, setEliminar] = useState<{ tipo: 'tarefa' | 'marco'; id: number; nome: string } | null>(null);
  const accEliminar = useAccao({ invalidar: [['projectos']], aoSucesso: () => setEliminar(null) });
  const nomeMembro = useMemo(() => new Map((equipa.data?.membros ?? []).map((m) => [m.id, m.nome])), [equipa.data]);
  const refGantt = useRef<HTMLDivElement>(null);

  const arvore: LinhaArvore[] = (w.data?.grupos ?? [])
    .filter((g) => g.marco || g.tarefas.length)
    .map((g) => ({ chave: `m-${g.marco?.id ?? 'sem'}`, grupo: true as const, marco: g.marco, execucao: g.execucao, children: g.tarefas.length ? g.tarefas.map(paraArvore) : undefined }));

  /** WBS impressa: milestones como grupos e tarefas/subtarefas indentadas; o Gantt imprime-se a partir do ecrã. */
  const pedidoImpressao = () => {
    if (vista === 'gantt') {
      return refGantt.current
        ? { titulo: `Gantt do projecto ${projecto.codigo ?? ''} — ${projecto.nome}`, filtros: ['Barras tracejadas = sem data de fim; linha vermelha = hoje'], conteudo: refGantt.current, orientacao: 'paisagem' as const }
        : null;
    }
    type Plana = { grupo: string; nivel: number; t: TarefaWbs };
    const planas: Plana[] = [];
    const juntar = (t: TarefaWbs, grupo: string, nivel: number) => {
      planas.push({ grupo, nivel, t });
      (t.subtarefas ?? []).forEach((s) => juntar(s, grupo, nivel + 1));
    };
    (w.data?.grupos ?? []).forEach((g) => g.tarefas.forEach((t) => juntar(t, `${g.marco?.nome ?? 'Sem milestone'}${g.marco?.data ? ` · ${formatarData(g.marco.data)}` : ''} · execução ${Math.round(g.execucao)} %`, 0)));
    return {
      titulo: `Planeamento (WBS) — ${projecto.codigo ?? ''} ${projecto.nome}`,
      filtros: [`Execução global: ${Math.round(w.data?.execucao_global ?? 0)} %`],
      conteudo: tabelaHtml({
        colunas: [
          { titulo: 'Tarefa', valor: (p: Plana) => `${' '.repeat(p.nivel)}${p.t.codigo ? `${p.t.codigo} ` : ''}${p.t.nome}${tarefaAtrasada(p.t) ? ' (atrasada)' : ''}`, quebrar: true },
          { titulo: 'Responsável', valor: (p) => (p.t.atribuido_a_id ? nomeMembro.get(p.t.atribuido_a_id) ?? `#${p.t.atribuido_a_id}` : '') },
          { titulo: 'Início', valor: (p) => p.t.data_inicio, formato: 'data' },
          { titulo: 'Fim', valor: (p) => p.t.data_fim, formato: 'data' },
          { titulo: 'Estado', valor: (p) => rotuloProjectos(p.t.estado) },
          { titulo: 'Execução', valor: (p) => p.t.execucao, formato: 'percentagem' },
          { titulo: 'Horas', valor: (p) => p.t.horas, formato: 'numero' },
          { titulo: 'Valor contrato (Kz)', valor: (p) => p.t.valor_contrato, formato: 'moeda' },
        ],
        linhas: planas,
        agrupar: { chave: (p) => p.grupo },
      }),
    };
  };

  const colunas: ColumnsType<LinhaArvore> = [
    {
      title: 'Tarefa', key: 'nome', width: 320,
      render: (_, l) => 'grupo' in l ? (
        <Space wrap><FlagOutlined style={{ color: '#8b5cf6' }} /><strong>{l.marco?.nome ?? 'Sem milestone'}</strong>{l.marco?.data && <Typography.Text type="secondary">{formatarData(l.marco.data)}</Typography.Text>}</Space>
      ) : (
        <Space wrap>
          {l.codigo && <Typography.Text type="secondary">{l.codigo}</Typography.Text>}
          {l.nome}
          {tarefaAtrasada(l) && <Tag color="red">Atrasada</Tag>}
        </Space>
      ),
    },
    { title: 'Responsável', key: 'resp', render: (_, l) => ('grupo' in l ? null : l.atribuido_a_id ? nomeMembro.get(l.atribuido_a_id) ?? `#${l.atribuido_a_id}` : '—') },
    { title: 'Início', key: 'ini', render: (_, l) => ('grupo' in l ? null : formatarData(l.data_inicio)) },
    { title: 'Fim', key: 'fim', render: (_, l) => ('grupo' in l ? null : formatarData(l.data_fim)) },
    { title: 'Estado', key: 'est', render: (_, l) => ('grupo' in l ? l.marco ? <EtiquetaProjectos valor={l.marco.estado} /> : null : <EtiquetaProjectos valor={l.estado} />) },
    { title: 'Execução', key: 'exe', render: (_, l) => <BarraExecucao valor={l.execucao} /> },
    { title: 'Horas', key: 'h', align: 'right', render: (_, l) => ('grupo' in l ? null : l.horas || '—') },
    { title: 'Valor contrato', key: 'vc', align: 'right', render: (_, l) => ('grupo' in l ? null : <ValorKz valor={l.valor_contrato} />) },
    {
      title: '', key: 'acc', align: 'right', fixed: 'right',
      render: (_, l) => {
        if ('grupo' in l) {
          if (!l.marco) return null;
          const m = l.marco;
          return (
            <Space wrap>
              {acc.gerir && <Tooltip title="Nova tarefa neste milestone"><Button size="small" icon={<PlusOutlined />} onClick={() => setTarefa({ marco_projeto_id: m.id })} /></Tooltip>}
              {acc.gerir && <Button size="small" icon={<EditOutlined />} onClick={() => setMarco(m)} />}
              {acc.eliminar && <Button size="small" danger icon={<DeleteOutlined />} onClick={() => setEliminar({ tipo: 'marco', id: m.id, nome: m.nome })} />}
            </Space>
          );
        }
        return (
          <Space wrap>
            {acc.execucao && !l.subtarefas?.length && <Button size="small" onClick={() => setExecucao(l)}>%</Button>}
            {acc.gerir && <Tooltip title="Nova subtarefa"><Button size="small" icon={<PlusOutlined />} onClick={() => setTarefa({ tarefa_pai_id: l.id, marco_projeto_id: l.marco_projeto_id })} /></Tooltip>}
            {acc.gerir && <Button size="small" icon={<EditOutlined />} onClick={() => setTarefa(l)} />}
            {acc.eliminar && <Button size="small" danger icon={<DeleteOutlined />} onClick={() => setEliminar({ tipo: 'tarefa', id: l.id, nome: l.nome })} />}
          </Space>
        );
      },
    },
  ];

  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
        <Space wrap>
          <Segmented value={vista} onChange={(v) => setVista(v as 'wbs' | 'gantt')} options={[{ value: 'wbs', label: 'WBS' }, { value: 'gantt', label: 'Gantt' }]} />
          <Typography.Text>Execução global: </Typography.Text><BarraExecucao valor={w.data?.execucao_global} largura={180} />
        </Space>
        <Space wrap>
          {acc.gerir && <Button icon={<FlagOutlined />} onClick={() => setMarco({})}>Novo milestone</Button>}
          {acc.gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setTarefa({})}>Nova tarefa</Button>}
          <BotoesExportar desactivado={!w.data} obterPedido={pedidoImpressao} />
        </Space>
      </Flex>
      {vista === 'wbs' ? (
        <Table<LinhaArvore>
          rowKey="chave"
          size="small"
          loading={w.isFetching}
          dataSource={arvore}
          columns={colunas}
          pagination={false}
          scroll={scrollTabela()}
          expandable={{ defaultExpandAllRows: true }}
          key={arvore.length}
        />
      ) : (
        <Gantt ref={refGantt} linhas={linhasGanttWbs(w.data)} />
      )}
      <ModalTarefa projectoId={projecto.id} tarefa={tarefa} aoFechar={() => setTarefa(null)} />
      <ModalMarco projectoId={projecto.id} marco={marco} aoFechar={() => setMarco(null)} />
      <ModalExecucao projectoId={projecto.id} tarefa={execucao} aoFechar={() => setExecucao(null)} />
      <ModalEliminar
        aberto={!!eliminar}
        titulo={`Eliminar ${eliminar?.tipo === 'marco' ? 'o milestone' : 'a tarefa'} «${eliminar?.nome ?? ''}»`}
        aviso={eliminar?.tipo === 'marco' ? 'As tarefas do milestone passam a «sem milestone».' : 'As subtarefas também são eliminadas. Tarefas com registos (horas, custos, autos) não podem ser eliminadas.'}
        carregando={accEliminar.isPending}
        aoFechar={() => setEliminar(null)}
        aoConfirmar={(confirmacao) => eliminar && accEliminar.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/${eliminar.tipo === 'marco' ? 'marcos' : 'tarefas'}/${eliminar.id}`, dados: { confirmacao } })}
      />
    </Card>
  );
}

function ModalTarefa({ projectoId, tarefa, aoFechar }: { projectoId: number; tarefa: Partial<TarefaWbs> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const w = useWbs(projectoId);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  const editar = !!tarefa?.id;
  useEffect(() => {
    if (!tarefa) return;
    form.resetFields();
    form.setFieldsValue({
      ...tarefa,
      data_inicio: tarefa.data_inicio ? dayjs(tarefa.data_inicio) : undefined,
      data_fim: tarefa.data_fim ? dayjs(tarefa.data_fim) : undefined,
      valor_contrato: tarefa.valor_contrato ? Number(tarefa.valor_contrato) : undefined,
      estado: tarefa.estado ?? 'PENDENTE',
    });
  }, [tarefa, form]);
  const marcos = (w.data?.grupos ?? []).filter((g) => g.marco).map((g) => ({ value: g.marco!.id, label: g.marco!.nome }));

  return (
    <Modal title={editar ? `Editar tarefa «${tarefa?.nome}»` : tarefa?.tarefa_pai_id ? 'Nova subtarefa' : 'Nova tarefa'} open={!!tarefa} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(760)} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const dados = { ...v, data_inicio: dataApi(v.data_inicio) ?? null, data_fim: dataApi(v.data_fim) ?? null, codigo: v.codigo || null };
          for (const k of ['tarefa_pai_id', 'marco_projeto_id', 'atribuido_a_id', 'valor_contrato', 'percentagem_execucao']) if (dados[k] === undefined) dados[k] = null;
          accao.mutate(editar ? { metodo: 'put', url: `/projetos/${projectoId}/tarefas/${tarefa?.id}`, dados } : { url: `/projetos/${projectoId}/tarefas`, dados });
        }}
      >
        <Row gutter={12}>
          <Col xs={24} md={6}><Form.Item name="codigo" label="Código"><Input maxLength={50} /></Form.Item></Col>
          <Col xs={24} md={18}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="marco_projeto_id" label="Milestone"><Select allowClear options={marcos} placeholder="Sem milestone" /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="tarefa_pai_id" label="Tarefa-mãe"><SeletorTarefa projectoId={projectoId} allowClear placeholder="Nenhuma (tarefa principal)" /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="atribuido_a_id" label="Responsável"><SeletorMembro projectoId={projectoId} allowClear /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="data_inicio" label="Início"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={6}>
            <Form.Item name="data_fim" label="Fim" dependencies={['data_inicio']} rules={[({ getFieldValue }) => ({
              validator: (_, v) => (!v || !getFieldValue('data_inicio') || !v.isBefore(getFieldValue('data_inicio'), 'day') ? Promise.resolve() : Promise.reject(new Error('O fim é anterior ao início.'))),
            })]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="estado" label="Estado">
              <Select options={[{ value: 'PENDENTE', label: 'Pendente' }, { value: 'EM_CURSO', label: 'Em curso' }, { value: 'CONCLUIDA', label: 'Concluída' }, { value: 'BLOQUEADA', label: 'Bloqueada' }]} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}><Form.Item name="percentagem_execucao" label="% executada"><InputNumber min={0} max={100} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="valor_contrato" label="Valor de contrato (Kz)" tooltip="Subempreitada: base dos autos de medição"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
      </Form>
    </Modal>
  );
}

function ModalMarco({ projectoId, marco, aoFechar }: { projectoId: number; marco: Partial<Marco> | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ nome: string; data?: dayjs.Dayjs }>();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (marco) form.setFieldsValue({ nome: marco.nome ?? '', data: marco.data ? dayjs(marco.data) : undefined });
  }, [marco, form]);
  return (
    <Modal title={marco?.id ? 'Editar milestone' : 'Novo milestone'} open={!!marco} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = { nome: v.nome, data: dataApi(v.data) ?? null };
        accao.mutate(marco?.id ? { metodo: 'put', url: `/projetos/${projectoId}/marcos/${marco.id}`, dados } : { url: `/projetos/${projectoId}/marcos`, dados });
      }}>
        <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
        <Form.Item name="data" label="Data"><DatePicker format="DD/MM/YYYY" /></Form.Item>
      </Form>
    </Modal>
  );
}

function ModalExecucao({ projectoId, tarefa, aoFechar }: { projectoId: number; tarefa: TarefaWbs | null; aoFechar: () => void }) {
  const [valor, setValor] = useState(0);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => setValor(tarefa?.percentagem_execucao ?? tarefa?.execucao ?? 0), [tarefa]);
  return (
    <Modal title={`Execução de «${tarefa?.nome ?? ''}»`} open={!!tarefa} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ url: `/projetos/${projectoId}/tarefas/${tarefa?.id}/execucao`, dados: { percentagem_execucao: valor } })}>
      <Flex gap={16} align="center" wrap>
        <Slider style={{ flex: 1, minWidth: 160 }} min={0} max={100} step={5} value={valor} onChange={setValor} />
        <InputNumber min={0} max={100} precision={0} value={valor} onChange={(v) => setValor(v ?? 0)} suffix="%" style={{ width: 110 }} />
      </Flex>
    </Modal>
  );
}
