import { Card, Col, Form, Input, InputNumber, Modal, Row } from 'antd';
import { useEffect, useState } from 'react';
import { NavActivos } from './comum/NavActivos';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { BotaoCartao } from './inventario/BotaoCartao';
import { ouTracos, percentagem } from './inventario/regrasInventario';
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

  // Colunas e rótulos curtos do legado (renderAssetCategories); as contas em falta ficam a vermelho porque sem 73/18 não há integração.
  const emFalta = <span style={{ color: '#cf1322' }} title="Sem esta conta não é possível integrar amortizações">---</span>;
  const colunas: ColunaApi<CategoriaActivo>[] = [
    { title: 'Nome', dataIndex: 'nome', ocultavel: false, render: (v) => <strong>{v}</strong> },
    { title: 'Taxa (%)', dataIndex: 'taxa_anual', align: 'center', render: (v) => percentagem(v), valorImpressao: (c) => percentagem(c.taxa_anual) },
    { title: 'Amort. Exerc.', dataIndex: 'conta_gasto', render: (v) => v ?? emFalta, valorImpressao: (c) => ouTracos(c.conta_gasto) },
    { title: 'Amort. Acum.', dataIndex: 'conta_amortizacao_acumulada', render: (v) => v ?? emFalta, valorImpressao: (c) => ouTracos(c.conta_amortizacao_acumulada) },
    { title: 'C. Venda', dataIndex: 'conta_venda', render: (v) => ouTracos(v) },
    { title: 'C. Perda', dataIndex: 'conta_perda', render: (v) => ouTracos(v) },
    // extras do sistema novo: escondidos por omissão («Colunas ▾»)
    { title: 'Vida útil (meses)', dataIndex: 'vida_util_padrao', align: 'right', ocultaPorOmissao: true, render: (v) => ouTracos(v) },
    { title: 'C. Activo', dataIndex: 'conta_ativo', ocultaPorOmissao: true, render: (v) => ouTracos(v) },
    { title: 'Activos', dataIndex: 'ativos', align: 'right', ocultaPorOmissao: true },
  ];

  return (
    <>
      <NavActivos actual="activos_categorias" />
      <Card
        title="Categorias de Imobilizado"
        extra={gerir && <BotaoCartao icone="plus" variante="primario" onClick={() => setEditar(null)}>Nova Categoria</BotaoCartao>}
      >
        <TabelaComModos<CategoriaActivo>
          idVista="activos_categorias"
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={q.data}
          scroll={{ x: 'max-content' }}
          columns={colunas}
          impressao={{ titulo: 'Categorias de Imobilizado' }}
          accoes={(c) => gerir ? [
            { icone: 'edit', titulo: 'Editar', onClick: () => setEditar(c) },
            {
              icone: 'trash', titulo: 'Excluir', variante: 'perigo',
              desactivado: c.ativos ? 'Categoria com activos: não pode ser excluída' : false,
              confirmar: { titulo: `Deseja realmente apagar a categoria "${c.nome}"?`, textoOk: 'Excluir', perigo: true },
              onClick: () => eliminar.mutateAsync({ metodo: 'delete', url: `/ativos/categorias/${c.id}` }).catch(() => undefined),
            },
          ] : []}
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
