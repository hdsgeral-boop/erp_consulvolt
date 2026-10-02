import { Alert, Button, Card, Checkbox, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Radio, Row, Statistic, Table, Tag } from 'antd';
import { SwapOutlined, ToolOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ColumnsType } from 'antd/es/table';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { LinhasProdutos, type LinhaProdutoForm } from '@/modulos/compras/comum/LinhasProdutos';
import { SeletorArmazem, SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { ExtractoArtigo } from './comum/ExtractoArtigo';
import type { LinhaStock, RespostaStock } from './comum/tipos';
import { BarraFiltros, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { pedidoStockPorArmazem } from './comum/impressao';

/** Armazém › Níveis de stock (ecrã armazem_stock): stock e valorização por armazém, ajustes, transferências e extracto. */
export default function NiveisStock() {
  const { pode } = useSessao();
  const [armazem, setArmazem] = useState<number>();
  const [comStock, setComStock] = useState(true);
  const [pesquisa, setPesquisa] = useState('');
  const [soRupturas, setSoRupturas] = useState(false);
  const [modal, setModal] = useState<'ajuste' | 'transferencia' | null>(null);
  const [extracto, setExtracto] = useState<LinhaStock | null>(null);

  const consulta = useQuery({
    queryKey: ['logistica', 'stock', armazem, comStock],
    queryFn: () => obter<RespostaStock>('/logistica/stock', { armazem_id: armazem, com_stock: comStock ? 1 : undefined }),
  });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar o stock');
  }, [consulta.error]);

  const linhas = useMemo(
    () => (consulta.data?.linhas ?? []).filter((l) => (!soRupturas || l.ruptura) && contemTexto(pesquisa, l.codigo, l.nome, l.armazem)),
    [consulta.data, pesquisa, soRupturas],
  );
  const v = consulta.data?.valorizacao;
  const pequeno = useEcraPequeno();
  const [nomeArmazem, setNomeArmazem] = useState<string>();

  const colunas: ColumnsType<LinhaStock> = [
    { title: 'Código', dataIndex: 'codigo', render: (x) => x || '—', sorter: (a, b) => String(a.codigo).localeCompare(String(b.codigo)) },
    { title: 'Produto', dataIndex: 'nome', sorter: (a, b) => a.nome.localeCompare(b.nome) },
    { title: 'Armazém', dataIndex: 'armazem', responsive: ['md'] },
    { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (q: string) => <span style={{ color: Number(q) < 0 ? '#cf1322' : undefined }}>{formatarNumero(q)}</span>, sorter: (a, b) => Number(a.quantidade) - Number(b.quantidade) },
    { title: 'Mínimo', dataIndex: 'stock_minimo', align: 'right', responsive: ['lg'], render: (x: string | null) => (x ? formatarNumero(x) : '—') },
    { title: 'Custo médio (Kz)', dataIndex: 'custo_medio', align: 'right', responsive: ['lg'], render: (x: string | null) => formatarKz(x) },
    { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (x: string | null) => formatarKz(x), sorter: (a, b) => Number(a.valor) - Number(b.valor) },
    { title: '', dataIndex: 'ruptura', render: (r: boolean) => (r ? <Tag color="red">Ruptura</Tag> : null) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Níveis de stock"
        subtitulo="Quantidades e valorização ao custo médio, por armazém"
        impressaoDesactivada={!linhas.length}
        impressao={() =>
          pedidoStockPorArmazem(linhas, [
            armazem ? `Armazém: ${nomeArmazem ?? `#${armazem}`}` : 'Todos os armazéns',
            comStock && 'Só com stock',
            soRupturas && 'Só rupturas',
            pesquisa && `Pesquisa: ${pesquisa}`,
          ])
        }
        accoes={
          <>
            {pode('armazem_transferencia', 'armazem_ajuste') && <Button icon={<SwapOutlined />} onClick={() => setModal('transferencia')}>Transferência</Button>}
            {pode('armazem_ajuste') && <Button icon={<ToolOutlined />} onClick={() => setModal('ajuste')}>Ajuste manual</Button>}
          </>
        }
      />
      {v && (
        <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
          <Col xs={24} sm={12} md={6}>
            <Card><Statistic title="Valor total do stock (Kz)" value={formatarKz(v.total)} /></Card>
          </Col>
          <Col xs={24} sm={12} md={6}>
            <Card><Statistic title="Artigos em ruptura" value={v.rupturas} valueStyle={v.rupturas ? { color: '#cf1322' } : undefined} /></Card>
          </Col>
          <Col xs={24} md={12}>
            <Card size="small" title="Por armazém">
              {v.por_armazem.map((a) => (
                <Flex key={a.armazem} justify="space-between" gap={8} wrap>
                  <span>{a.armazem} ({a.produtos} artigos)</span>
                  <strong>{formatarKz(a.valor)} Kz</strong>
                </Flex>
              ))}
            </Card>
          </Col>
        </Row>
      )}
      <Card>
        <BarraFiltros>
          <Input.Search placeholder="Código, produto ou armazém" allowClear style={{ width: 280, maxWidth: '100%' }} onSearch={setPesquisa} onChange={(e) => !e.target.value && setPesquisa('')} />
          <SeletorArmazem allowClear placeholder="Todos os armazéns" style={{ width: 240, maxWidth: '100%' }} value={armazem} onChange={(x, o) => { setArmazem(x); setNomeArmazem(o && !Array.isArray(o) && o.label ? String(o.label) : undefined); }} />
          <Checkbox checked={comStock} onChange={(e) => setComStock(e.target.checked)}>Só com stock</Checkbox>
          <Checkbox checked={soRupturas} onChange={(e) => setSoRupturas(e.target.checked)}>Só rupturas</Checkbox>
        </BarraFiltros>
        <Table<LinhaStock>
          rowKey={(r) => `${r.armazem_id}-${r.produto_id}`}
          size={pequeno ? 'small' : 'middle'}
          scroll={scrollTabela()}
          loading={consulta.isFetching}
          dataSource={linhas}
          columns={colunas}
          pagination={{ defaultPageSize: 50, showSizeChanger: true, showTotal: (t) => `${t} linha(s)` }}
          onRow={(r) => ({ onClick: () => setExtracto(r), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ExtractoArtigo key={extracto ? `${extracto.produto_id}-${extracto.armazem_id}` : 'nenhum'} produtoId={extracto?.produto_id ?? null} armazemInicial={extracto?.armazem_id} aoFechar={() => setExtracto(null)} />
      <ModalAjuste aberto={modal === 'ajuste'} aoFechar={() => setModal(null)} />
      <ModalTransferencia aberto={modal === 'transferencia'} aoFechar={() => setModal(null)} />
    </>
  );
}

function ModalAjuste({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ produto_id: number; armazem_id: number; sentido: 'E' | 'S'; quantidade: number; custo_unitario?: number; data: Dayjs; motivo: string }>();
  const sentido = Form.useWatch('sentido', form);
  const accao = useAccao({ invalidar: [['logistica']], aoSucesso: aoFechar, tituloErro: 'Não foi possível registar o ajuste' });
  useEffect(() => {
    if (aberto) form.setFieldsValue({ sentido: 'E', data: dayjs(), quantidade: undefined, custo_unitario: undefined, motivo: '' });
  }, [aberto, form]);
  return (
    <Modal title="Ajuste manual de stock" open={aberto} onCancel={aoFechar} okText="Registar ajuste" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(620)}>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: '/logistica/ajustes', dados: { ...v, data: dataApi(v.data), custo_unitario: v.sentido === 'E' ? v.custo_unitario : undefined } })}>
        <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="Os ajustes ficam registados com o motivo. Para regularizações de contagem física use o inventário." />
        <Row gutter={16}>
          <Col xs={24}>
            <Form.Item name="produto_id" label="Produto" rules={[{ required: true, message: 'Escolha o produto.' }]}>
              <SeletorProduto apenasStock />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="armazem_id" label="Armazém" rules={[{ required: true, message: 'Escolha o armazém.' }]}>
              <SeletorArmazem />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="data" label="Data" rules={[{ required: true }]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="sentido" label="Sentido">
              <Radio.Group optionType="button" options={[{ value: 'E', label: 'Entrada' }, { value: 'S', label: 'Saída' }]} />
            </Form.Item>
          </Col>
          <Col xs={12} md={8}>
            <Form.Item name="quantidade" label="Quantidade" rules={[{ required: true, message: 'Indique a quantidade.' }]}>
              <InputNumber min={0.001} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          {sentido === 'E' && (
            <Col xs={12} md={8}>
              <Form.Item name="custo_unitario" label="Custo unit. (Kz)" tooltip="Vazio: custo médio actual.">
                <InputNumber min={0} precision={2} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          )}
          <Col span={24}>
            <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Indique o motivo.' }, { min: 5, message: 'Pelo menos 5 caracteres.' }]}>
              <Input.TextArea rows={2} maxLength={500} />
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </Modal>
  );
}

function ModalTransferencia({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ armazem_origem_id: number; armazem_destino_id: number; data: Dayjs; observacoes?: string; linhas: LinhaProdutoForm[] }>();
  const accao = useAccao({ invalidar: [['logistica']], aoSucesso: aoFechar, tituloErro: 'Não foi possível registar a transferência' });
  useEffect(() => {
    if (aberto) form.setFieldsValue({ data: dayjs(), observacoes: undefined, linhas: [{ quantidade: 1 }] });
  }, [aberto, form]);
  return (
    <Modal title="Transferência entre armazéns" open={aberto} onCancel={aoFechar} okText="Transferir" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(820)}>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) =>
          accao.mutate({
            url: '/logistica/transferencias',
            dados: { ...v, data: dataApi(v.data), observacoes: v.observacoes || undefined, linhas: v.linhas.map((l) => ({ produto_id: l.produto_id, quantidade: l.quantidade })) },
          })
        }
      >
        <Row gutter={16}>
          <Col xs={24} md={9}>
            <Form.Item name="armazem_origem_id" label="Origem" rules={[{ required: true, message: 'Escolha a origem.' }]}>
              <SeletorArmazem />
            </Form.Item>
          </Col>
          <Col xs={24} md={9}>
            <Form.Item
              name="armazem_destino_id"
              label="Destino"
              dependencies={['armazem_origem_id']}
              rules={[{ required: true, message: 'Escolha o destino.' }, ({ getFieldValue }) => ({ validator: async (_, x) => (x && x === getFieldValue('armazem_origem_id') ? Promise.reject(new Error('O destino tem de ser diferente da origem.')) : undefined) })]}
            >
              <SeletorArmazem />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="data" label="Data" rules={[{ required: true }]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
        <LinhasProdutos form={form} apenasStock />
        <Form.Item name="observacoes" label="Observações" style={{ marginTop: 16 }} rules={[{ max: 500 }]}>
          <Input.TextArea rows={2} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
