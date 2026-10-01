import { Button, Card, DatePicker, Form, Input, InputNumber, Modal, Space, Table } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { dataApi, formatarData, formatarNumero } from '@/utilitarios/formatacao';
import { EtiquetaProjectos, SeletorTarefa, useWbs } from '../comum/componentes';
import { achatarWbs, rotuloRubrica } from '../comum/regras';
import type { Requisicao } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';

/** Requisições de material do projecto: cada requisição gera um pedido de compra (módulo Compras). */
export function SeparadorRequisicoes({ projecto, acc }: PropsSeparador) {
  const q = useQuery({ queryKey: ['projectos', 'requisicoes', projecto.id], queryFn: () => obter<Requisicao[]>(`/projetos/${projecto.id}/requisicoes`) });
  const w = useWbs(projecto.id);
  const tarefas = useMemo(() => new Map(achatarWbs(w.data).map((t) => [t.id, t.nome])), [w.data]);
  const [nova, setNova] = useState(false);
  return (
    <Card size="small" extra={acc.requisitar && <Button type="primary" size="small" icon={<PlusOutlined />} onClick={() => setNova(true)}>Nova requisição</Button>}>
      <Table<Requisicao>
        rowKey="id"
        size="small"
        loading={q.isFetching}
        dataSource={q.data}
        pagination={{ pageSize: 20 }}
        expandable={{
          expandedRowRender: (r) => (
            <Table
              rowKey="id"
              size="small"
              pagination={false}
              dataSource={r.linhas}
              columns={[
                { title: 'Descrição', dataIndex: 'descricao' },
                { title: 'Tarefa', dataIndex: 'tarefa_projeto_id', render: (id) => (id ? tarefas.get(id) ?? `#${id}` : '—') },
                { title: 'Rubrica', dataIndex: 'rubrica', render: rotuloRubrica },
                { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
                { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaProjectos valor={v} /> },
              ]}
            />
          ),
        }}
        columns={[
          { title: 'N.º', dataIndex: 'id' },
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Requerente', dataIndex: 'nome_requerente' },
          { title: 'Data prevista', dataIndex: 'data_prevista', render: formatarData },
          { title: 'Linhas', key: 'n', align: 'right', render: (_, r) => r.linhas.length },
          { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaProjectos valor={v} /> },
        ]}
      />
      <ModalRequisicao projectoId={projecto.id} aberto={nova} aoFechar={() => setNova(false)} />
    </Card>
  );
}

function ModalRequisicao({ projectoId, aberto, aoFechar }: { projectoId: number; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const { utilizador } = useSessao();
  const accao = useAccao<{ pedido_compra_id?: number | null }>({ invalidar: [['projectos'], ['compras']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (aberto) { form.resetFields(); form.setFieldsValue({ nome_requerente: utilizador?.nome_completo ?? utilizador?.nome_utilizador, data: dayjs(), linhas: [{ quantidade: 1 }] }); }
  }, [aberto, form, utilizador]);
  return (
    <Modal title="Nova requisição de material" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Submeter" cancelText="Cancelar" confirmLoading={accao.isPending} width={860} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({
        url: `/projetos/${projectoId}/requisicoes`,
        dados: { nome_requerente: v.nome_requerente, data: dataApi(v.data), data_prevista: dataApi(v.data_prevista) ?? null,
          linhas: v.linhas.map((l: { produto_id: number; quantidade: number; tarefa_projeto_id?: number }) => ({ ...l, tarefa_projeto_id: l.tarefa_projeto_id ?? null })) },
      })}>
        <Space wrap>
          <Form.Item name="nome_requerente" label="Requerente" rules={[{ required: true, message: 'Indique o requerente.' }]}><Input maxLength={200} style={{ width: 300 }} /></Form.Item>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="data_prevista" label="Data prevista"><DatePicker format="DD/MM/YYYY" /></Form.Item>
        </Space>
        <Form.List name="linhas" rules={[{ validator: (_, v) => (v?.length ? Promise.resolve() : Promise.reject(new Error('Acrescente pelo menos uma linha.'))) }]}>
          {(campos, { add, remove }, { errors }) => (
            <>
              {campos.map((c) => (
                <Space key={c.key} align="baseline" wrap>
                  <Form.Item name={[c.name, 'produto_id']} rules={[{ required: true, message: 'Produto' }]}><SeletorProduto style={{ width: 320 }} /></Form.Item>
                  <Form.Item name={[c.name, 'quantidade']} rules={[{ required: true, message: 'Qtd.' }]}><InputNumber min={0.001} placeholder="Qtd." style={{ width: 110 }} /></Form.Item>
                  <Form.Item name={[c.name, 'tarefa_projeto_id']}><SeletorTarefa projectoId={projectoId} allowClear style={{ width: 240 }} /></Form.Item>
                  <Button danger icon={<DeleteOutlined />} onClick={() => remove(c.name)} disabled={campos.length === 1} />
                </Space>
              ))}
              <Form.ErrorList errors={errors} />
              <Button icon={<PlusOutlined />} onClick={() => add({ quantidade: 1 })}>Linha</Button>
            </>
          )}
        </Form.List>
      </Form>
    </Modal>
  );
}
