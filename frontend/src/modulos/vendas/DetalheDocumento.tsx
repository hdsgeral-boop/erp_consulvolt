import { Alert, Button, Card, Checkbox, DatePicker, Descriptions, Flex, Form, Input, Modal, Select, Skeleton, Space, Table, Tag, message } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { CONTABILIZAVEIS, CONVERSOES, CORES_ESTADO, FISCAIS, TIPOS_DOCUMENTO, type DocumentoVenda, type LinhaVenda } from './api';

/** Detalhe de um documento de venda, com converter, anular e contabilizar/descontabilizar (mesmas regras do servidor). */
export function DetalheDocumento() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [conversao, setConversao] = useState(false);
  const [descontab, setDescontab] = useState(false);
  const [formConv] = Form.useForm<{ tipo_destino: string; data_emissao: Dayjs; motivo_nota_credito?: string; devolucao_mercadoria?: boolean }>();
  const [formMotivo] = Form.useForm<{ motivo: string }>();
  const tipoDestino = Form.useWatch('tipo_destino', formConv);

  const consulta = useQuery({ queryKey: ['vendas', 'documento', id], queryFn: () => obter<DocumentoVenda>(`/vendas/documentos/${id}`) });
  const accao = useMutation({
    mutationFn: ({ caminho, dados }: { caminho: string; dados?: unknown }) => enviar<DocumentoVenda>('post', `/vendas/documentos/${id}/${caminho}`, dados),
    onSuccess: ({ dados, mensagem }, { caminho }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['vendas'] });
      setConversao(false);
      setDescontab(false);
      if (caminho === 'converter' && dados?.id) navegar(`../${dados.id}`);
    },
    onError: (e) => notificarErro(e),
  });

  if (consulta.isLoading) return <Skeleton active />;
  const d = consulta.data;
  if (!d) return <Alert type="error" message="Documento não encontrado." />;

  const anulado = d.estado === 'ANULADO';
  const destinos = CONVERSOES[d.tipo_documento] ?? [];
  const podeConverter = !anulado && destinos.length > 0 && pode('vendas_fat_emitir');
  const podeAnular = !anulado && !FISCAIS.includes(d.tipo_documento) && !d.contabilizado && pode('vendas_fat_del');
  const podeContabilizar = !anulado && !d.contabilizado && CONTABILIZAVEIS.includes(d.tipo_documento) && pode('vendas_fat_contabilizar');
  const podeDescontabilizar = d.contabilizado && pode('vendas_fat_descontab');

  const colunas = [
    { title: 'Descrição', dataIndex: 'descricao' },
    { title: 'Qtd.', dataIndex: 'quantidade', align: 'right' as const, render: formatarNumero },
    { title: 'Preço unit.', dataIndex: 'preco_unitario', align: 'right' as const, render: (v: string) => formatarKz(v) },
    { title: 'IVA %', dataIndex: 'taxa_imposto', align: 'right' as const, render: formatarNumero },
    { title: 'Valor', dataIndex: 'valor', align: 'right' as const, render: (v: string | null) => formatarKz(v) },
    { title: 'Total', dataIndex: 'total', align: 'right' as const, render: (v: string | null) => formatarKz(v) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={d.numero_documento}
        subtitulo={TIPOS_DOCUMENTO[d.tipo_documento] ?? d.tipo_documento}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {podeConverter && <Button onClick={() => { formConv.setFieldsValue({ tipo_destino: destinos[0], data_emissao: dayjs() }); setConversao(true); }}>Converter</Button>}
            {podeContabilizar && <Button type="primary" loading={accao.isPending} onClick={() => accao.mutate({ caminho: 'contabilizar' })}>Contabilizar</Button>}
            {podeDescontabilizar && <Button danger onClick={() => setDescontab(true)}>Descontabilizar</Button>}
            {podeAnular && (
              <Button danger onClick={() => Modal.confirm({ title: `Anular ${d.numero_documento}?`, okText: 'Anular', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ caminho: 'anular' }) })}>
                Anular
              </Button>
            )}
          </>
        }
      />
      {anulado && <Alert type="error" showIcon message="Documento anulado." style={{ marginBottom: 16 }} />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={{ xs: 1, md: 3 }} size="small">
          <Descriptions.Item label="Cliente">{d.cliente?.nome ?? `#${d.cliente_id}`}{d.cliente?.nif ? ` (NIF ${d.cliente.nif})` : ''}</Descriptions.Item>
          <Descriptions.Item label="Data">{formatarData(d.data_emissao)}</Descriptions.Item>
          <Descriptions.Item label="Estado">{d.estado ? <Tag color={CORES_ESTADO[d.estado]}>{d.estado}</Tag> : '—'}</Descriptions.Item>
          <Descriptions.Item label="Vencimento">{formatarData(d.data_vencimento)}</Descriptions.Item>
          <Descriptions.Item label="Contabilização">{d.contabilizado ? `Contabilizado (${d.numero_lan_contabilizacao ?? '—'})` : 'Por contabilizar'}</Descriptions.Item>
          <Descriptions.Item label="AGT">{d.faturacao_eletronica?.estado ?? '—'}{d.faturacao_eletronica?.hash ? ` · hash ${d.faturacao_eletronica.hash}` : ''}</Descriptions.Item>
          {d.motivo_nota_credito && <Descriptions.Item label="Motivo da NC" span={3}>{d.motivo_nota_credito}</Descriptions.Item>}
          {d.observacoes && <Descriptions.Item label="Observações" span={3}>{d.observacoes}</Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card title="Linhas" style={{ marginBottom: 16 }}>
        <Table<LinhaVenda> rowKey="id" size="small" pagination={false} columns={colunas} dataSource={d.linhas ?? []} scroll={{ x: 'max-content' }} />
        <Flex justify="end" style={{ marginTop: 16 }}>
          <Descriptions column={1} size="small" style={{ width: 320 }} bordered>
            <Descriptions.Item label="Líquido">{formatarKz(d.total_liquido)}</Descriptions.Item>
            <Descriptions.Item label="IVA">{formatarKz(d.total_imposto)}</Descriptions.Item>
            <Descriptions.Item label="Total">{formatarKz(d.total_bruto, true)}</Descriptions.Item>
            <Descriptions.Item label="Pago">{formatarKz(d.valor_pago)}</Descriptions.Item>
            <Descriptions.Item label="Pendente">{formatarKz(d.valor_pendente)}</Descriptions.Item>
          </Descriptions>
        </Flex>
      </Card>

      <Modal title="Converter documento" open={conversao} onCancel={() => setConversao(false)} okText="Converter" confirmLoading={accao.isPending} onOk={() => formConv.submit()}>
        <Form form={formConv} layout="vertical" onFinish={(v) => accao.mutate({ caminho: 'converter', dados: { ...v, data_emissao: dataApi(v.data_emissao) } })}>
          <Form.Item name="tipo_destino" label="Converter em" rules={[{ required: true }]}>
            <Select options={destinos.map((t) => ({ value: t, label: `${t} — ${TIPOS_DOCUMENTO[t]}` }))} />
          </Form.Item>
          <Form.Item name="data_emissao" label="Data">
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
          {tipoDestino === 'NC' && (
            <>
              <Form.Item name="motivo_nota_credito" label="Motivo" rules={[{ required: true, message: 'Indique o motivo.' }]}>
                <Input maxLength={200} />
              </Form.Item>
              <Form.Item name="devolucao_mercadoria" valuePropName="checked">
                <Checkbox>A mercadoria volta ao stock</Checkbox>
              </Form.Item>
            </>
          )}
        </Form>
      </Modal>

      <Modal title="Descontabilizar (estorno)" open={descontab} onCancel={() => setDescontab(false)} okText="Descontabilizar" okButtonProps={{ danger: true }} confirmLoading={accao.isPending} onOk={() => formMotivo.submit()}>
        <Form form={formMotivo} layout="vertical" onFinish={(v) => accao.mutate({ caminho: 'descontabilizar', dados: v })}>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
          <Space>O lançamento é estornado (fica o rasto no Diário).</Space>
        </Form>
      </Modal>
    </>
  );
}
