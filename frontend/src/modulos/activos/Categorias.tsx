import { Button, Card, Col, Form, Input, InputNumber, Modal, Popconfirm, Row, Space } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { NavActivos } from './comum/NavActivos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { pedidoTabela } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { useCategorias } from './comum/componentes';
import type { CategoriaActivo } from './comum/tipos';
import { larguraModal } from '@/componentes/responsivo';

import { TabelaComModos } from '@/componentes/vistas';
/** Activos › Categorias e taxas (ecrã activos_categorias): taxa anual, vida útil e contas de gasto, acumulada, venda, perda e activo. */
export default function Categorias() {
  const { pode } = useSessao();
  const q = useCategorias();
  const [editar, setEditar] = useState<CategoriaActivo | null | undefined>(undefined);
  const eliminar = useAccao({ invalidar: [['activos']] });
  const gerir = pode('activos_cat_gerir');

  const colunas: ColunaApi<CategoriaActivo>[] = [
    { title: 'Nome', dataIndex: 'nome', render: (v) => <strong>{v}</strong> },
    { title: 'Taxa anual', dataIndex: 'taxa_anual', align: 'right', render: (v) => (v !== null ? `${Number(v).toLocaleString('pt-PT')}%` : '—') },
    { title: 'Vida útil (meses)', dataIndex: 'vida_util_padrao', align: 'right', render: (v) => v ?? '—' },
    { title: 'Gasto', dataIndex: 'conta_gasto', render: (v) => v ?? <span style={{ color: '#cf1322' }}>em falta</span> },
    { title: 'Amort. acumulada', dataIndex: 'conta_amortizacao_acumulada', render: (v) => v ?? <span style={{ color: '#cf1322' }}>em falta</span> },
    { title: 'Venda (ganho)', dataIndex: 'conta_venda', render: (v) => v ?? '—' },
    { title: 'Perda', dataIndex: 'conta_perda', render: (v) => v ?? '—' },
    { title: 'Activo', dataIndex: 'conta_ativo', render: (v) => v ?? '—' },
    { title: 'Activos', dataIndex: 'ativos', align: 'right' },
    {
      title: '', key: 'acc', align: 'right',
      render: (_, c) => gerir && (
        <Space>
          <Button size="small" icon={<EditOutlined />} onClick={() => setEditar(c)} />
          <Popconfirm title={`Eliminar a categoria «${c.nome}»?`} disabled={!!c.ativos} okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
            onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/ativos/categorias/${c.id}` })}>
            <Button size="small" danger icon={<DeleteOutlined />} disabled={!!c.ativos} title={c.ativos ? 'Categoria com activos' : undefined} />
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Categorias e taxas"
        subtitulo="Sem as contas de gasto (73) e de amortização acumulada (18) não é possível integrar amortizações"
        accoes={gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar(null)}>Nova categoria</Button>}
        impressaoDesactivada={!q.data?.length}
        impressao={() => pedidoTabela({ titulo: 'Categorias de activos e taxas de amortização', colunas, linhas: q.data ?? [] })}
      />
      <NavActivos actual="activos_categorias" />
      <Card>
        <TabelaComModos<CategoriaActivo>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={q.data}
          scroll={{ x: 'max-content' }}
          pagination={false}
          columns={colunas}
        />
      </Card>
      <ModalCategoria categoria={editar} aoFechar={() => setEditar(undefined)} />
    </>
  );
}

function ModalCategoria({ categoria, aoFechar }: { categoria: CategoriaActivo | null | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<Partial<CategoriaActivo> & { taxa_anual?: number }>();
  const aberto = categoria !== undefined;
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    if (categoria) form.setFieldsValue({ ...categoria, taxa_anual: categoria.taxa_anual !== null ? Number(categoria.taxa_anual) : undefined } as never);
  }, [aberto, categoria, form]);

  const conta = (nome: keyof CategoriaActivo, rotulo: string, prefixo: string) => (
    <Col xs={24} md={12}>
      <Form.Item name={nome} label={rotulo}>
        <SeletorConta prefixo={prefixo} placeholder={`Conta ${prefixo}…`} />
      </Form.Item>
    </Col>
  );

  return (
    <Modal title={categoria ? `Editar categoria «${categoria.nome}»` : 'Nova categoria'} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(720)} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const dados = Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x === undefined || x === '' ? null : x]));
          accao.mutate(categoria ? { metodo: 'put', url: `/ativos/categorias/${categoria.id}`, dados } : { url: '/ativos/categorias', dados });
        }}
      >
        <Row gutter={12}>
          <Col xs={24} md={12}>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}>
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item name="taxa_anual" label="Taxa anual (%)">
              <InputNumber min={0} max={100} precision={4} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item name="vida_util_padrao" label="Vida útil (meses)">
              <InputNumber min={0} precision={0} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          {conta('conta_gasto', 'Conta de gasto (73)', '73')}
          {conta('conta_amortizacao_acumulada', 'Conta de amortização acumulada (18)', '18')}
          {conta('conta_venda', 'Conta de ganho na venda (6)', '6')}
          {conta('conta_perda', 'Conta de perda (7)', '7')}
          {conta('conta_ativo', 'Conta do activo (11–14)', '1')}
        </Row>
      </Form>
    </Modal>
  );
}
