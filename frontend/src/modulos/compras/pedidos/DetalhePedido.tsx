import { Alert, Button, Card, Descriptions, Form, Input, Modal, Radio, Skeleton, Steps, Table, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '../comum/accoes';
import { numero } from '../comum/calculos';
import { EstadoTag, rotuloEstado } from '../comum/estados';
import { obterLista } from '../comum/lista';
import { NomeProduto, NomeTerceiro } from '../comum/referencias';
import { accoesPedido, etapaPendente } from '../comum/regras';
import { numeroOuId, type EtapaDeliberacao, type ItemCompra, type PedidoCompra, type PropostaCompra } from '../comum/tipos';

function estadoPasso(e: EtapaDeliberacao): 'finish' | 'process' | 'error' | 'wait' {
  if (e.estado === 'APROVADO') return 'finish';
  if (e.estado === 'PENDENTE') return 'process';
  if (e.estado === 'RECUSADO') return 'error';
  return 'wait';
}

/** Detalhe do pedido interno: deliberação, linhas, propostas recebidas e acções (decidir, anular, registar proposta). */
export function DetalhePedido() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [decisao, setDecisao] = useState(false);
  const [anular, setAnular] = useState(false);
  const [form] = Form.useForm<{ decisao: 'APROVAR' | 'RECUSAR'; nota?: string }>();
  const tipoDecisao = Form.useWatch('decisao', form);

  const consulta = useQuery({ queryKey: ['compras', 'pedido', id], queryFn: () => obter<PedidoCompra>(`/compras/pedidos/${id}`) });
  const verPropostas = pode('compras_prospeccao_view');
  const propostas = useQuery({
    queryKey: ['compras', 'propostas', 'do-pedido', id],
    queryFn: () => obterLista<PropostaCompra>('/compras/propostas', { pedido_compra_id: id, por_pagina: 100 }),
    enabled: verPropostas,
  });
  const accao = useAccao<PedidoCompra>({
    invalidar: [['compras']],
    aoSucesso: () => {
      setDecisao(false);
      setAnular(false);
    },
  });

  if (consulta.isLoading) return <Skeleton active />;
  const p = consulta.data;
  if (!p) return <Alert type="error" message="Pedido não encontrado." />;

  const a = accoesPedido(p, pode);
  const etapa = etapaPendente(p);
  const etapas = p.deliberacao?.etapas ?? [];
  const linhas = p.linhas ?? [];

  return (
    <>
      <CabecalhoPagina
        titulo={`Pedido ${numeroOuId(p.numero_pedido, p.id)}`}
        subtitulo={p.nome_requerente}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.registarProposta && (
              <Button onClick={() => navegar(`/m/compras/compras_prospeccao/novo?pedido=${p.id}`)}>Registar proposta</Button>
            )}
            {verPropostas && ['APROVADO', 'ADJUDICADO'].includes(p.estado) && (
              <Button onClick={() => navegar(`/m/compras/compras_prospeccao/comparacao/${p.id}`)}>Quadro comparativo</Button>
            )}
            {a.decidir && (
              <Button type="primary" onClick={() => { form.setFieldsValue({ decisao: 'APROVAR', nota: undefined }); setDecisao(true); }}>
                Decidir
              </Button>
            )}
            {a.anular && <Button danger onClick={() => setAnular(true)}>Anular</Button>}
          </>
        }
      />
      {p.estado === 'ANULADO' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Pedido anulado${p.motivo_anulacao ? `: ${p.motivo_anulacao}` : '.'}`} />}

      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={{ xs: 1, md: 3 }} size="small">
          <Descriptions.Item label="Data">{formatarData(p.data)}</Descriptions.Item>
          <Descriptions.Item label="Entrega pretendida">{formatarData(p.data_entrega)}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={p.estado} /></Descriptions.Item>
          <Descriptions.Item label="Valor estimado">
            {formatarKz(p.valor_estimado?.valor)} Kz
            {(p.valor_estimado?.sem_preco ?? 0) > 0 && <Typography.Text type="warning"> ({p.valor_estimado?.sem_preco} linha(s) sem preço)</Typography.Text>}
          </Descriptions.Item>
          {p.criado_por && <Descriptions.Item label="Criado por">{p.criado_por}</Descriptions.Item>}
          {p.descricao && <Descriptions.Item label="Descrição" span={3}>{p.descricao}</Descriptions.Item>}
          {p.observacoes && <Descriptions.Item label="Observações" span={3}>{p.observacoes}</Descriptions.Item>}
        </Descriptions>
      </Card>

      {etapas.length > 0 && (
        <Card title="Deliberação" style={{ marginBottom: 16 }}>
          <Steps
            size="small"
            items={etapas.map((e) => ({
              title: e.nome,
              status: estadoPasso(e),
              description: (
                <>
                  <div>{rotuloEstado(e.estado)}</div>
                  {e.por && <div>{e.por}{e.em ? ` · ${formatarDataHora(e.em)}` : ''}</div>}
                  {e.estado === 'PENDENTE' && e.nome_aprovador && <div>{e.nome_aprovador}</div>}
                  {e.nota && <div><em>{e.nota}</em></div>}
                </>
              ),
            }))}
          />
        </Card>
      )}

      <Card title="Artigos" style={{ marginBottom: 16 }}>
        <Table<ItemCompra>
          rowKey="id"
          size="small"
          pagination={false}
          scroll={{ x: 'max-content' }}
          dataSource={linhas}
          columns={[
            { title: 'Produto', render: (_, l) => <NomeProduto id={l.produto_id} descricao={l.descricao} /> },
            { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
            { title: 'Preço estimado', dataIndex: 'preco_unitario', align: 'right', render: (v: string | null) => (v ? formatarKz(v) : <Typography.Text type="warning">sem preço</Typography.Text>) },
            { title: 'Total estimado', key: 'total', align: 'right', render: (_, l) => (l.preco_unitario ? formatarKz(numero(l.quantidade) * numero(l.preco_unitario)) : '—') },
          ]}
        />
      </Card>

      {verPropostas && (propostas.data?.itens.length ?? 0) > 0 && (
        <Card title="Propostas de fornecedores" style={{ marginBottom: 16 }}>
          <Table<PropostaCompra>
            rowKey="id"
            size="small"
            pagination={false}
            dataSource={propostas.data?.itens}
            onRow={(r) => ({ onClick: () => navegar(`/m/compras/compras_prospeccao/${r.id}`), style: { cursor: 'pointer' } })}
            columns={[
              { title: 'Proposta', render: (_, r) => numeroOuId(r.numero_proposta, r.id) },
              { title: 'Referência', dataIndex: 'referencia' },
              { title: 'Fornecedor', render: (_, r) => <NomeTerceiro id={r.fornecedor_id} /> },
              { title: 'Total (Kz)', dataIndex: 'montante_total', align: 'right', render: (v: string | null) => formatarKz(v) },
              { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
            ]}
          />
        </Card>
      )}

      <Modal
        title={`Decisão — ${etapa?.nome ?? 'aprovação do pedido'}`}
        open={decisao}
        onCancel={() => setDecisao(false)}
        okText="Confirmar decisão"
        cancelText="Cancelar"
        okButtonProps={{ danger: tipoDecisao === 'RECUSAR' }}
        confirmLoading={accao.isPending}
        onOk={() => form.submit()}
      >
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/compras/pedidos/${p.id}/decidir`, dados: { decisao: v.decisao, nota: v.nota?.trim() || undefined } })}>
          <Form.Item name="decisao">
            <Radio.Group optionType="button" buttonStyle="solid" options={[{ value: 'APROVAR', label: 'Aprovar' }, { value: 'RECUSAR', label: 'Recusar' }]} />
          </Form.Item>
          <Form.Item
            name="nota"
            label={tipoDecisao === 'RECUSAR' ? 'Motivo da recusa' : 'Nota (opcional)'}
            rules={tipoDecisao === 'RECUSAR' ? [{ required: true, min: 3, message: 'Indique o motivo da recusa.' }] : []}
          >
            <Input.TextArea rows={3} maxLength={1000} />
          </Form.Item>
        </Form>
      </Modal>
      <ModalMotivo
        aberto={anular}
        titulo={`Anular o pedido ${numeroOuId(p.numero_pedido, p.id)}`}
        textoOk="Anular"
        carregando={accao.isPending}
        aoFechar={() => setAnular(false)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/compras/pedidos/${p.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
