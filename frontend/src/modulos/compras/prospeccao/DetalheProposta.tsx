import { Alert, Button, Card, DatePicker, Descriptions, Form, Modal, Skeleton, Table } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { ModalIva } from '../comum/ModalIva';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { COLUNAS_DESCRICOES, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { pedidoDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { dadosPropostaCompra } from '../comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { comOpcoesOrcamentoPedido, useExcessoOrcamental } from '@/componentes/orcamento';
import { EstadoTag } from '../comum/estados';
import { NomeProduto, NomeTerceiro } from '../comum/referencias';
import { accoesProposta } from '../comum/regras';
import { numeroOuId, type EncomendaCompra, type ItemCompra, type PropostaCompra } from '../comum/tipos';
import { ValorMoeda } from '../comum/Valores';

export function DetalheProposta() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [adjudicar, setAdjudicar] = useState(false);
  const [iva, setIva] = useState(false);
  const [form] = Form.useForm<{ data: Dayjs }>();
  const consulta = useQuery({ queryKey: ['compras', 'proposta', id], queryFn: () => obter<PropostaCompra>(`/compras/propostas/${id}`) });
  const excesso = useExcessoOrcamental();
  const accao: ReturnType<typeof useAccao<PropostaCompra | EncomendaCompra>> = useAccao<PropostaCompra | EncomendaCompra>({
    invalidar: [['compras']],
    aoSucesso: (dados, pedido) => {
      setAdjudicar(false);
      if (pedido.url.endsWith('/adjudicar') && dados?.id && pode('compras_encomendas_view')) navegar(`/m/compras/compras_encomendas/${dados.id}`);
    },
    // a adjudicação cria a encomenda e passa pelo controlo orçamental (A-02)
    aoErro: (e, pedido) => {
      if (!pedido.url.endsWith('/adjudicar')) return false;
      const tratado = excesso.tratar(e, { repetir: (o) => accao.mutate(comOpcoesOrcamentoPedido(pedido, o)) });
      if (tratado) setAdjudicar(false);
      return tratado;
    },
  });

  if (consulta.isLoading) return <Skeleton active />;
  const c = consulta.data;
  if (!c) return <Alert type="error" message="Proposta não encontrada." />;
  const a = accoesProposta(c, pode);
  const moeda = c.codigo_moeda || 'AOA';
  const nome = numeroOuId(c.numero_proposta, c.id);

  return (
    <>
      <CabecalhoPagina
        titulo={`Proposta ${nome}`}
        subtitulo={<>Referência {c.referencia} · <NomeTerceiro id={c.fornecedor_id} terceiro={c.fornecedor} /></>}
        impressao={() => pedidoDocumentoComercial(dadosPropostaCompra(c))}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            <Button onClick={() => navegar(`../comparacao/${c.pedido_compra_id}`)}>Quadro comparativo</Button>
            {['PROPOSTA', 'PROPOSTA_ADJUDICACAO'].includes(c.estado) && pode('compras_new_proposal') && <Button onClick={() => setIva(true)}>Editar IVA</Button>}
            {a.propor && (
              <Button type="primary" loading={accao.isPending} onClick={() => accao.mutate({ url: `/compras/propostas/${c.id}/propor` })}>
                Propor adjudicação
              </Button>
            )}
            {a.adjudicar && (
              <Button type="primary" onClick={() => { form.setFieldsValue({ data: dayjs() }); setAdjudicar(true); }}>
                Adjudicar
              </Button>
            )}
            {a.cancelarProposta && (
              <Button loading={accao.isPending} onClick={() => accao.mutate({ url: `/compras/propostas/${c.id}/cancelar-proposta` })}>
                Cancelar proposta de adjudicação
              </Button>
            )}
            {a.anular && (
              <Button
                danger
                onClick={() =>
                  Modal.confirm({
                    title: `Anular a proposta ${nome}?`,
                    okText: 'Anular',
                    okButtonProps: { danger: true },
                    cancelText: 'Cancelar',
                    onOk: () => accao.mutateAsync({ url: `/compras/propostas/${c.id}/anular` }),
                  })
                }
              >
                Anular
              </Button>
            )}
          </>
        }
      />
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={COLUNAS_DESCRICOES} size="small">
          <Descriptions.Item label="Pedido">
            {pode('compras_pedidos_view') ? <a onClick={() => navegar(`/m/compras/compras_pedidos/${c.pedido_compra_id}`)}>#{c.pedido_compra_id}</a> : `#${c.pedido_compra_id}`}
          </Descriptions.Item>
          <Descriptions.Item label="Data">{formatarData(c.data)}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={c.estado} /></Descriptions.Item>
          <Descriptions.Item label="Prazo de entrega">{formatarData(c.data_entrega)}</Descriptions.Item>
          <Descriptions.Item label="Moeda">{moeda}{c.taxa_cambio && moeda !== 'AOA' ? ` (câmbio ${formatarNumero(c.taxa_cambio)})` : ''}</Descriptions.Item>
          <Descriptions.Item label="Total (Kz)"><ValorMoeda kz={c.montante_total} moeda={moeda} valorMoeda={c.montante_total_moeda} /></Descriptions.Item>
          {c.total_imposto && <Descriptions.Item label="IVA (Kz)">{formatarKz(c.total_imposto)}</Descriptions.Item>}
          {c.total_com_imposto && <Descriptions.Item label="Total c/ IVA (Kz)">{formatarKz(c.total_com_imposto)}</Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card title="Artigos">
        <Table<ItemCompra>
          rowKey="id"
          size="small"
          pagination={false}
          scroll={scrollTabela()}
          dataSource={c.linhas ?? []}
          columns={[
            { title: 'Produto', render: (_, l) => <NomeProduto id={l.produto_id} produto={l.produto} descricao={l.descricao} /> },
            { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
            ...(moeda !== 'AOA' ? [{ title: `Preço (${moeda})`, dataIndex: 'preco_unitario_moeda', align: 'right' as const, render: (v: string | null) => formatarKz(v) }] : []),
            { title: 'Preço (Kz)', dataIndex: 'preco_unitario', align: 'right', render: (v: string | null) => formatarKz(v) },
            { title: 'IVA %', dataIndex: 'taxa_imposto', align: 'right', render: formatarNumero },
            { title: 'Total (Kz)', key: 'total', align: 'right', render: (_, l) => formatarKz(l.total_kz ?? l.total) },
          ]}
        />
      </Card>
      <Modal
        title={`Adjudicar a proposta ${nome}`}
        open={adjudicar}
        width={larguraModal(520)}
        onCancel={() => setAdjudicar(false)}
        okText="Adjudicar e gerar encomenda"
        cancelText="Cancelar"
        confirmLoading={accao.isPending}
        onOk={() => form.submit()}
      >
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/compras/propostas/${c.id}/adjudicar`, dados: { data: dataApi(v.data) } })}>
          <Alert
            type="info"
            showIcon
            style={{ marginBottom: 16 }}
            message="A adjudicação gera a encomenda ao fornecedor e recusa as restantes propostas do pedido. Se o valor exigir níveis de aprovação ainda não obtidos, o pedido volta à deliberação."
          />
          <Form.Item name="data" label="Data da encomenda">
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>
      {excesso.dialogo}
      <ModalIva aberto={iva} titulo={`IVA da proposta ${nome}`} linhas={c.linhas ?? []} url={`/compras/propostas/${c.id}/iva`} aoFechar={() => setIva(false)} />
    </>
  );
}
