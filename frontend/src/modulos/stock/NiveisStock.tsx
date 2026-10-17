import { Alert, Button, Card, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Radio, Row, Tag } from 'antd';
import { SwapOutlined, ToolOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { TabelaComModos, type ColunaLegado } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { LinhasProdutos, type LinhaProdutoForm } from '@/modulos/compras/comum/LinhasProdutos';
import { SeletorArmazem, SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { rotuloProduto, useArmazens, useCatalogo } from '@/modulos/compras/comum/referencias';
import { AlertasNiveis } from './comum/AlertasNiveis';
import { ExtractoArtigo } from './comum/ExtractoArtigo';
import type { LinhaStock, RespostaStock } from './comum/tipos';
import { larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { statusStock } from './comum/paridade';

/** Armazém › Níveis de stock (ecrã armazem_stock): stock e valorização por armazém, ajustes, transferências e extracto. */
export default function NiveisStock() {
  const { pode } = useSessao();
  const [parametros] = useSearchParams();
  // «Ver Stock» dos Armazens (2.5.2) abre os níveis filtrados pelo armazém (?armazem=ID)
  const [armazem, setArmazem] = useState<number | undefined>(() => {
    const id = Number(parametros.get('armazem'));
    return Number.isInteger(id) && id > 0 ? id : undefined;
  });
  const comStock = true;
  const [pesquisa, setPesquisa] = useState('');
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
    () => (consulta.data?.linhas ?? []).filter((l) => contemTexto(pesquisa, l.codigo, l.nome, l.armazem)),
    [consulta.data, pesquisa],
  );
  const pequeno = useEcraPequeno();
  const [nomeArmazem, setNomeArmazem] = useState<string>();

  // Colunas, ordem e nomes do legado (renderStockTab, ui_warehouse.js:186-192); o mínimo e a ruptura ficam em «Colunas ▾».
  const colunas: ColunaLegado<LinhaStock>[] = [
    { title: 'Armazém', dataIndex: 'armazem', render: (x: string | null) => x || 'N/D', sorter: (a, b) => String(a.armazem).localeCompare(String(b.armazem)) },
    { title: 'Código', dataIndex: 'codigo', render: (x) => x || '-', sorter: (a, b) => String(a.codigo).localeCompare(String(b.codigo)) },
    { title: 'Produto', dataIndex: 'nome', render: (x: string) => <strong>{x}</strong>, sorter: (a, b) => a.nome.localeCompare(b.nome) },
    { title: 'Stock Local', dataIndex: 'quantidade', align: 'right', render: (q: string) => <strong style={Number(q) <= 5 ? { color: '#ef4444' } : undefined}>{formatarNumero(q)}</strong>, sorter: (a, b) => Number(a.quantidade) - Number(b.quantidade) },
    { title: 'Custo Médio (CMP)', dataIndex: 'custo_medio', align: 'right', total: true, formatoTotal: (t) => <strong>{formatarKz(t)} Kz</strong>, render: (x: string | null) => (x === null ? '-' : `${formatarKz(x)} Kz`) },
    {
      title: 'V. Total (CMP)',
      dataIndex: 'valor',
      align: 'right',
      total: true,
      formatoTotal: (t) => <strong style={{ color: 'var(--ant-color-primary, #2563eb)' }}>{formatarKz(t)} Kz</strong>,
      render: (x: string | null) => <strong>{x === null ? '-' : `${formatarKz(x)} Kz`}</strong>,
      sorter: (a, b) => Number(a.valor) - Number(b.valor),
    },
    { title: 'Status', key: 'estado', align: 'center', valorImpressao: (l) => statusStock(l.quantidade).rotulo, render: (_, l) => statusStock(l.quantidade).rotulo },
    { title: 'Mínimo', dataIndex: 'stock_minimo', align: 'right', ocultaPorOmissao: true, render: (x: string | null) => (x ? formatarNumero(x) : '—') },
    { title: 'Ruptura', key: 'ruptura', align: 'center', ocultaPorOmissao: true, valorImpressao: (l) => (l.ruptura ? 'Sim' : 'Não'), render: (_, l) => (l.ruptura ? <Tag color="orange">Ruptura</Tag> : '—') },
  ];

  return (
    <>
      {/* ronda 3: regras de notificação dos níveis de stock e sugestões de reposição (gera o pedido de compra) */}
      <AlertasNiveis />
      <Card
        title="Inventário por Armazém"
        extra={
          // legado (renderStockTab, ui_warehouse.js:174-180): «Todos os Armazens» e «Procurar produto...» à direita do título;
          // Transferência e Ajuste manual são funções do sistema novo, em botões pequenos a seguir
          <Flex gap={8} wrap align="center">
            <SeletorArmazem allowClear placeholder="Todos os Armazens" style={{ width: 200, maxWidth: '100%' }} value={armazem} onChange={(x, o) => { setArmazem(x); setNomeArmazem(o && !Array.isArray(o) && o.label ? String(o.label) : undefined); }} />
            <Input placeholder="Procurar produto..." allowClear style={{ width: 280, maxWidth: '100%' }} value={pesquisa} onChange={(e) => setPesquisa(e.target.value)} />
            {pode('armazem_transferencia', 'armazem_ajuste') && <Button size="small" icon={<SwapOutlined />} title="Transferência entre armazéns" onClick={() => setModal('transferencia')}>Transferência</Button>}
            {pode('armazem_ajuste') && <Button size="small" icon={<ToolOutlined />} title="Ajuste manual de stock" onClick={() => setModal('ajuste')}>Ajuste manual</Button>}
          </Flex>
        }
      >
        <TabelaComModos<LinhaStock>
          rowKey={(r) => `${r.armazem_id}-${r.produto_id}`}
          size={pequeno ? 'small' : 'middle'}
          scroll={scrollTabela()}
          loading={consulta.isFetching}
          dataSource={linhas}
          columns={colunas}
          rotuloTotal=""
          impressao={{
            titulo: 'Inventário por Armazém',
            rotuloTotal: 'VALOR TOTAL EM STOCK',
            filtros: [
              armazem ? `Armazém: ${nomeArmazem ?? (consulta.data?.linhas.find((l) => l.armazem_id === armazem)?.armazem ?? 'N/D')}` : 'Todos os Armazens',
              pesquisa && `Pesquisa: ${pesquisa}`,
            ],
          }}
          onRow={(r) => ({ onClick: () => setExtracto(r), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ExtractoArtigo key={extracto ? `${extracto.produto_id}-${extracto.armazem_id}` : 'nenhum'} produtoId={extracto?.produto_id ?? null} armazemInicial={extracto?.armazem_id} aoFechar={() => setExtracto(null)} />
      <ModalAjuste aberto={modal === 'ajuste'} aoFechar={() => setModal(null)} />
      <ModalTransferencia aberto={modal === 'transferencia'} aoFechar={() => setModal(null)} />
    </>
  );
}

/** Data da grade (dayjs) ou da vista Linhas ('AAAA-MM-DD') → API. */
const dataDe = (d: unknown): string | null => (dayjs.isDayjs(d) ? dataApi(d) ?? null : typeof d === 'string' && d ? d : null);

/** Regras de gravação do ajuste, comuns à grade e à vista Linhas: o custo unitário só conta nas entradas. */
export function dadosAjuste(v: Record<string, unknown>): Record<string, unknown> {
  const sentido = v.sentido ?? 'E';
  return {
    produto_id: v.produto_id, armazem_id: v.armazem_id, sentido, quantidade: v.quantidade, data: dataDe(v.data), motivo: typeof v.motivo === 'string' ? v.motivo.trim() : v.motivo,
    custo_unitario: sentido === 'E' && v.custo_unitario !== null && v.custo_unitario !== '' ? v.custo_unitario ?? undefined : undefined,
  };
}

const SENTIDOS = [{ value: 'E', label: 'Entrada' }, { value: 'S', label: 'Saída' }];

function ModalAjuste({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ produto_id: number; armazem_id: number; sentido: 'E' | 'S'; quantidade: number; custo_unitario?: number; data: Dayjs; motivo: string }>();
  const sentido = Form.useWatch('sentido', form);
  const accao = useAccao({ invalidar: [['logistica']], aoSucesso: aoFechar, tituloErro: 'Não foi possível registar o ajuste' });
  useEffect(() => {
    if (aberto) form.setFieldsValue({ sentido: 'E', data: dayjs(), quantidade: undefined, custo_unitario: undefined, motivo: '' });
  }, [aberto, form]);
  // Vista Linhas: um ajuste por linha (cada linha é um movimento com o seu motivo)
  const catalogo = useCatalogo();
  const armazens = useArmazens();
  const campos = useMemo<CampoFormulario[]>(() => [
    { nome: 'produto_id', rotulo: 'Produto', tipo: 'select', obrigatorio: true, largura: 260, aliases: ['Artigo', 'Código'],
      opcoes: (catalogo.data ?? []).filter((p) => p.movimenta_stock).map((p) => ({ value: p.id, label: rotuloProduto(p) })) },
    { nome: 'armazem_id', rotulo: 'Armazém', tipo: 'select', obrigatorio: true, largura: 170, opcoes: (armazens.data ?? []).map((a) => ({ value: a.id, label: a.nome })) },
    { nome: 'data', rotulo: 'Data', tipo: 'data', obrigatorio: true, omissao: () => dayjs().format('YYYY-MM-DD'), largura: 140 },
    { nome: 'sentido', rotulo: 'Sentido', tipo: 'select', obrigatorio: true, opcoes: SENTIDOS, omissao: 'E', largura: 110 },
    { nome: 'quantidade', rotulo: 'Quantidade', tipo: 'numero', obrigatorio: true, min: 0.001, largura: 110 },
    { nome: 'custo_unitario', rotulo: 'Custo unit. (Kz)', tipo: 'numero', min: 0, precisao: 2, largura: 120, aliases: ['Custo unitário'],
      validar: (c, r) => (r.sentido === 'S' && c !== null && c !== undefined ? 'O custo unitário só se indica nas entradas.' : null) },
    { nome: 'motivo', rotulo: 'Motivo', tipo: 'textoLongo', obrigatorio: true, maxLength: 500, largura: 240,
      validar: (m) => (typeof m === 'string' && m.trim() && m.trim().length < 5 ? 'Motivo: pelo menos 5 caracteres.' : null) },
  ], [catalogo.data, armazens.data]);
  return (
    <ModalFormulario chave="stock.ajustes" titulo="Ajuste manual de stock" aberto={aberto} aoFechar={aoFechar} criacao form={form} campos={campos}
      nomeRegistos="ajustes de stock" invalidar={[['logistica']]} larguraLinhas={1250} destroyOnHidden={false}
      gravarLinha={(r) => enviar('post', '/logistica/ajustes', dadosAjuste(r))}
      nota="Uma linha por ajuste (produto, armazém e sentido). Sem custo unitário, a entrada usa o custo médio actual. Os ajustes ficam registados com o motivo; para regularizações de contagem física use o inventário."
      okText="Registar ajuste" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(620)}>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: '/logistica/ajustes', dados: dadosAjuste(v) })}>
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
              <Radio.Group optionType="button" options={SENTIDOS} />
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
    </ModalFormulario>
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
