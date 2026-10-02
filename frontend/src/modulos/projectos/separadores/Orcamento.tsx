import { Button, Card, Col, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Statistic, Table, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState, useRef } from 'react';
import { obter } from '@/api/cliente';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaProjectos, ModalEliminar, SeletorTarefa, useWbs } from '../comum/componentes';
import { achatarWbs, consumo, RUBRICAS, rotuloRubrica, totaisPorRubrica } from '../comum/regras';
import type { Aditamento, CustosTarefa, LinhaOrcamento } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { ImpressaoSeparador } from '../comum/ImpressaoSeparador';
import { scrollTabela } from '@/componentes/responsivo';

/** Orçamento base por tarefa e rubrica, orçado vs executado por tarefa, e aditamentos (trabalhos a mais/menos). */
export function SeparadorOrcamento({ projecto, acc }: PropsSeparador) {
  const orc = useQuery({ queryKey: ['projectos', 'orcamento', projecto.id], queryFn: () => obter<{ total: string; linhas: LinhaOrcamento[] }>(`/projetos/${projecto.id}/orcamento`) });
  const adit = useQuery({ queryKey: ['projectos', 'aditamentos', projecto.id], queryFn: () => obter<{ total_aprovado: string; aditamentos: Aditamento[] }>(`/projetos/${projecto.id}/aditamentos`) });
  const custos = useQuery({ queryKey: ['projectos', 'custos-tarefas', projecto.id], queryFn: () => obter<Record<string, CustosTarefa>>(`/projetos/${projecto.id}/custos-tarefas`) });
  const w = useWbs(projecto.id);
  const [linha, setLinha] = useState<Partial<LinhaOrcamento> | null>(null);
  const [aditamento, setAditamento] = useState<Partial<Aditamento> | null>(null);
  const [eliminarAdit, setEliminarAdit] = useState<Aditamento | null>(null);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => setEliminarAdit(null) });
  const tarefas = useMemo(() => new Map(achatarWbs(w.data).map((t) => [t.id, t])), [w.data]);
  const porRubrica = totaisPorRubrica(orc.data?.linhas ?? []);
  const totalComAdit = (Number(orc.data?.total ?? 0) + Number(adit.data?.total_aprovado ?? 0)).toFixed(2);
  const linhasCustos = Object.entries(custos.data ?? {}).map(([id, c]) => ({ id: Number(id), ...c }));
  const refSeparador = useRef<HTMLDivElement>(null);

  return (
    <div ref={refSeparador}>
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <div className="imp-nao-imprimir" style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <ImpressaoSeparador alvo={refSeparador} titulo="Orçamento e aditamentos" projecto={projecto} />
      </div>
      <Row gutter={[16, 16]}>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Orçamento base (Kz)" value={formatarKz(orc.data?.total)} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Aditamentos aprovados (Kz)" value={formatarKz(adit.data?.total_aprovado)} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Orçamento revisto (Kz)" value={formatarKz(totalComAdit)} /></Card></Col>
        <Col xs={12} md={6}>
          <Card size="small">
            {Object.entries(porRubrica).map(([r, v]) => <div key={r} style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13 }}><span>{rotuloRubrica(r)}</span><span>{formatarKz(v)}</span></div>)}
            {!Object.keys(porRubrica).length && <Typography.Text type="secondary">Sem rubricas</Typography.Text>}
          </Card>
        </Col>
      </Row>
      <Card size="small" title="Orçamento base" extra={acc.gerir && <Button type="primary" size="small" icon={<PlusOutlined />} onClick={() => setLinha({})}>Rubrica</Button>}>
        <Table<LinhaOrcamento> scroll={scrollTabela()}
          rowKey="id"
          size="small"
          loading={orc.isFetching}
          dataSource={orc.data?.linhas}
          pagination={false}
          columns={[
            { title: 'Tarefa', dataIndex: 'tarefa_projeto_id', render: (id, l) => (id ? l.tarefa_nome ?? tarefas.get(id)?.nome ?? '#' + id : 'Projecto (geral)') },
            { title: 'Responsável', key: 'resp', render: (_, l) => l.membro_nome ?? l.posicao_titulo ?? '—' },
            { title: 'Rubrica', dataIndex: 'rubrica', render: rotuloRubrica },
            { title: 'Conta', dataIndex: 'numero_conta', render: (v) => v ?? '—' },
            { title: 'Montante (Kz)', dataIndex: 'montante', align: 'right', render: (v) => <ValorKz valor={v} /> },
            {
              title: '', key: 'acc', align: 'right',
              render: (_, l) => acc.gerir && (
                <Space wrap>
                  <Button size="small" icon={<EditOutlined />} onClick={() => setLinha(l)} />
                  <Popconfirm title="Remover esta rubrica do orçamento?" okText="Remover" cancelText="Cancelar" okButtonProps={{ danger: true }}
                    onConfirm={() => accao.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/orcamento/${l.id}` })}>
                    <Button size="small" danger icon={<DeleteOutlined />} />
                  </Popconfirm>
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <Card size="small" title="Orçado vs executado por tarefa">
        <Table scroll={scrollTabela()}
          rowKey="id"
          size="small"
          loading={custos.isFetching}
          dataSource={linhasCustos}
          pagination={false}
          columns={[
            { title: 'Tarefa', dataIndex: 'id', render: (id) => tarefas.get(id)?.nome ?? `#${id}` },
            { title: 'Orçado', dataIndex: 'orcamento', align: 'right', render: (v) => <ValorKz valor={v} /> },
            { title: 'Executado', dataIndex: 'executado', align: 'right', render: (v, l) => <Typography.Text type={Number(v) > Number(l.orcamento) && Number(l.orcamento) > 0 ? 'danger' : undefined}>{formatarKz(v)}</Typography.Text> },
            { title: 'Consumo', key: 'c', align: 'right', render: (_, l) => { const c = consumo(l.executado, l.orcamento); return c === null ? '—' : `${c.toLocaleString('pt-PT')}%`; } },
            { title: 'Horas', dataIndex: 'horas', align: 'right' },
            { title: 'Requisições', dataIndex: 'requisicoes', align: 'right' },
          ]}
        />
      </Card>
      <Card size="small" title="Aditamentos e trabalhos a mais" extra={acc.gerir && <Button size="small" icon={<PlusOutlined />} onClick={() => setAditamento({ estado: 'PENDENTE' })}>Aditamento</Button>}>
        <Table<Aditamento> scroll={scrollTabela()}
          rowKey="id"
          size="small"
          loading={adit.isFetching}
          dataSource={adit.data?.aditamentos}
          pagination={false}
          columns={[
            { title: 'Data', key: 'd', render: (_, a) => formatarData(a.data ?? a.criado_em ?? null) },
            { title: 'Descrição', dataIndex: 'descricao' },
            { title: 'Montante (Kz)', dataIndex: 'montante', align: 'right', render: (v) => <ValorKz valor={v} /> },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaProjectos valor={v} /> },
            {
              title: '', key: 'acc', align: 'right',
              render: (_, a) => (
                <Space wrap>
                  {acc.gerir && <Button size="small" icon={<EditOutlined />} onClick={() => setAditamento(a)} />}
                  {acc.eliminar && <Button size="small" danger icon={<DeleteOutlined />} onClick={() => setEliminarAdit(a)} />}
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <ModalLinha projectoId={projecto.id} linha={linha} aoFechar={() => setLinha(null)} />
      <ModalAditamento projectoId={projecto.id} aditamento={aditamento} aoFechar={() => setAditamento(null)} />
      <ModalEliminar aberto={!!eliminarAdit} titulo="Eliminar o aditamento" aviso={eliminarAdit?.descricao} carregando={accao.isPending} aoFechar={() => setEliminarAdit(null)}
        aoConfirmar={(confirmacao) => eliminarAdit && accao.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/aditamentos/${eliminarAdit.id}`, dados: { confirmacao } })} />
    </Space>
    </div>
  );
}

function ModalLinha({ projectoId, linha, aoFechar }: { projectoId: number; linha: Partial<LinhaOrcamento> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const rubrica = Form.useWatch('rubrica', form);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (linha) { form.resetFields(); form.setFieldsValue({ ...linha, montante: linha.montante ? Number(linha.montante) : undefined }); }
  }, [linha, form]);
  return (
    <Modal title={linha?.id ? 'Editar rubrica' : 'Nova rubrica do orçamento'} open={!!linha} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = { tarefa_projeto_id: v.tarefa_projeto_id ?? null, rubrica: v.rubrica, numero_conta: v.rubrica === 'MAO_DE_OBRA' ? null : v.numero_conta || null, montante: v.montante };
        accao.mutate(linha?.id ? { metodo: 'put', url: `/projetos/${projectoId}/orcamento/${linha.id}`, dados } : { url: `/projetos/${projectoId}/orcamento`, dados });
      }}>
        <Form.Item name="tarefa_projeto_id" label="Tarefa"><SeletorTarefa projectoId={projectoId} allowClear placeholder="Projecto (geral)" /></Form.Item>
        <Form.Item name="rubrica" label="Rubrica" rules={[{ required: true, message: 'Escolha a rubrica.' }]}>
          <Select options={Object.entries(RUBRICAS).map(([value, label]) => ({ value, label }))} />
        </Form.Item>
        {rubrica && rubrica !== 'MAO_DE_OBRA' && <Form.Item name="numero_conta" label="Conta (opcional)"><SeletorConta /></Form.Item>}
        <Form.Item name="montante" label="Montante (Kz)" rules={[{ required: true, message: 'Indique o montante.' }]}><InputNumber precision={2} style={{ width: 220, maxWidth: '100%' }} /></Form.Item>
      </Form>
    </Modal>
  );
}

function ModalAditamento({ projectoId, aditamento, aoFechar }: { projectoId: number; aditamento: Partial<Aditamento> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (aditamento) { form.resetFields(); form.setFieldsValue({ ...aditamento, montante: aditamento.montante ? Number(aditamento.montante) : undefined }); }
  }, [aditamento, form]);
  return (
    <Modal title={aditamento?.id ? 'Editar aditamento' : 'Novo aditamento'} open={!!aditamento} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = { descricao: v.descricao, montante: v.montante ?? null, estado: v.estado };
        accao.mutate(aditamento?.id ? { metodo: 'put', url: `/projetos/${projectoId}/aditamentos/${aditamento.id}`, dados } : { url: `/projetos/${projectoId}/aditamentos`, dados });
      }}>
        <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Descreva o aditamento.' }]}><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
        <Space wrap>
          <Form.Item name="montante" label="Montante (Kz)" tooltip="Negativo = trabalhos a menos"><InputNumber precision={2} style={{ width: 200, maxWidth: '100%' }} /></Form.Item>
          <Form.Item name="estado" label="Estado">
            <Select style={{ width: 160 }} options={[{ value: 'PENDENTE', label: 'Pendente' }, { value: 'APROVADO', label: 'Aprovado' }, { value: 'REJEITADO', label: 'Rejeitado' }]} />
          </Form.Item>
        </Space>
      </Form>
    </Modal>
  );
}
