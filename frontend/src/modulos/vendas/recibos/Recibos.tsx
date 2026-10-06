import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Flex, Form, Input, InputNumber, Modal, Row, Segmented, Select, Skeleton, Space, Statistic, Table, Tag, Typography, message } from 'antd';
import { BookOutlined, UndoOutlined } from '@ant-design/icons';
import { BarraFiltros, COLUNAS_DESCRICOES, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { somar } from '@/utilitarios/decimal';
import { pedidoRecibo } from '../impressao/documentoRecibo';
import { ArrowLeftOutlined, CheckCircleTwoTone, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';

import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { EstadoTag } from '@/modulos/compras/comum/estados';
import { SeletorConta, SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import type { DocumentoVenda } from '../api';
import { alocacoesParaPedido, distribuirMontante, totalAlocado } from './alocacao';
import { accoesRecibo, MEIOS_RECIBO, rotuloMeio, type ReciboVenda } from './tipos';

/** Vendas › Facturação › Recibos de clientes (/api/vendas/recibos). */
export function Recibos() {
  return (
    <Routes>
      <Route index element={<ListaRecibos />} />
      <Route path="novo" element={<NovoRecibo />} />
      <Route path=":id" element={<DetalheRecibo />} />
    </Routes>
  );
}

function ListaRecibos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [cliente, setCliente] = useState<number>();
  const [pesquisa, setPesquisa] = useState('');
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [nomeCliente, setNomeCliente] = useState<string>();
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const pequeno = useEcraPequeno();
  // M-06: contabilizar/descontabilizar os recibos seleccionados (postSelectedReceipts / unpostSelectedReceipts do legado)
  const lote = useAccao<{ ok: number; erros: number; resultados: { numero: string | null; sucesso: boolean; mensagem: string }[] }>({
    invalidar: [['vendas']],
    aoSucesso: (r) => {
      setSeleccao([]);
      if (r.erros) Modal.warning({ title: 'Alguns recibos não foram tratados', content: <ul>{r.resultados.filter((x) => !x.sucesso).map((x, i) => <li key={i}><strong>{x.numero}</strong>: {x.mensagem}</li>)}</ul> });
    },
  });
  const descontabilizarLote = () => {
    let motivo = '';
    Modal.confirm({
      title: 'Descontabilizar os recibos seleccionados',
      content: <Input.TextArea rows={2} maxLength={500} placeholder="Motivo (obrigatório)" aria-label="Motivo" onChange={(e) => { motivo = e.target.value; }} />,
      okText: 'Descontabilizar', okButtonProps: { danger: true }, cancelText: 'Cancelar',
      onOk: () => (motivo.trim().length < 5 ? Promise.reject(message.error('Indique o motivo (mínimo 5 caracteres).')) : lote.mutate({ url: '/vendas/recibos/descontabilizar', dados: { ids: seleccao, motivo } })),
    });
  };

  const colunas: ColunaApi<ReciboVenda>[] = [
    { title: 'Recibo', dataIndex: 'numero_recibo', fixed: 'left', render: (v: string, r) => <Space size={4}><strong>{v}</strong>{r.tipo_recibo === 'ADIANTAMENTO' && <Tag color="purple">Adiantamento</Tag>}</Space>, valorImpressao: (r) => `${r.numero_recibo}${r.tipo_recibo === 'ADIANTAMENTO' ? ' (adiantamento)' : ''}` },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Cliente', render: (_, r) => r.cliente?.nome ?? `#${r.cliente_id}` },
    { title: 'Montante (Kz)', dataIndex: 'montante_total', align: 'right', render: (v: string) => formatarKz(v), totalImpressao: (ls) => formatarKz(somar(ls.map((l) => (l.estado === 'ANULADO' ? 0 : l.montante_total)))) },
    { title: 'Meio', dataIndex: 'meio_pagamento', responsive: ['md'], render: rotuloMeio },
    { title: 'Conta', dataIndex: 'codigo_conta', responsive: ['lg'], render: (v) => v || '—' },
    { title: 'Estado', dataIndex: 'estado', responsive: ['sm'], render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', responsive: ['lg'], render: (c: boolean) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null), valorImpressao: (r) => (r.contabilizado ? 'Sim' : 'Não') },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Recibos"
        subtitulo="Recebimentos de clientes que liquidam facturas"
        accoes={pode('vendas_recibos') && <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>Novo recibo</Button>}
      />
      <Card>
        <BarraFiltros
          accoes={(pode('vendas_fat_contabilizar') || pode('vendas_fat_unpost')) && (
            <Space wrap>
              {pode('vendas_fat_contabilizar') && (
                <Button icon={<BookOutlined />} disabled={!seleccao.length} loading={lote.isPending} onClick={() => lote.mutate({ url: '/vendas/recibos/contabilizar', dados: { ids: seleccao } })}>
                  Contabilizar seleccionados
                </Button>
              )}
              {pode('vendas_fat_unpost') && <Button danger icon={<UndoOutlined />} disabled={!seleccao.length} onClick={descontabilizarLote}>Descontabilizar seleccionados</Button>}
            </Space>
          )}
        >
          <Input.Search placeholder="N.º do recibo" allowClear style={{ width: 200, maxWidth: '100%' }} onSearch={setPesquisa} />
          <SeletorTerceiro papel="CLIENTE" style={{ width: 320, maxWidth: '100%' }} value={cliente} onChange={(v, o) => { setCliente(v); setNomeCliente(o && !Array.isArray(o) && o.label ? String(o.label) : undefined); }} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
        </BarraFiltros>
        <TabelaApi<ReciboVenda>
          url="/vendas/recibos"
          chaveConsulta={['vendas', 'recibos']}
          filtros={{ cliente_id: cliente, pesquisa, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          rowSelection={{
            selectedRowKeys: seleccao,
            onChange: (k) => setSeleccao(k.map(Number)),
            getCheckboxProps: (r) => ({ disabled: r.estado === 'ANULADO' || !!r.venda_origem_id, 'aria-label': `Seleccionar ${r.numero_recibo}` }),
          }}
          impressao={{
            titulo: 'Lista de recibos de clientes',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: [!!cliente && `Cliente: ${nomeCliente ?? `#${cliente}`}`, pesquisa && `Pesquisa: ${pesquisa}`],
            rotuloTotal: 'Total (sem anulados)',
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}

interface ValoresRecibo {
  cliente_id?: number;
  data: Dayjs;
  codigo_conta?: string;
  meio_pagamento: string;
  referencia_pagamento?: string;
  contabilizar: boolean;
  montante?: number;
  observacoes?: string;
}

function NovoRecibo() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [form] = Form.useForm<ValoresRecibo>();
  const clienteId = Form.useWatch('cliente_id', form);
  const [alocacoes, setAlocacoes] = useState<Record<number, number | null>>({});
  const [recebido, setRecebido] = useState<number | null>(null);
  // M-18: recibo de adiantamento (showDirectReceiptForm do legado) — sem facturas; aloca-se depois no detalhe
  const [tipoRecibo, setTipoRecibo] = useState<'NORMAL' | 'ADIANTAMENTO'>('NORMAL');
  const adiantamento = tipoRecibo === 'ADIANTAMENTO';
  const montanteAdiantamento = Form.useWatch('montante', form) ?? 0;

  const pendentes = useQuery({
    queryKey: ['vendas', 'pendentes', clienteId],
    queryFn: () => obterPagina<DocumentoVenda>('/vendas/documentos', { cliente_id: clienteId, pendentes: 1, por_pagina: 200 }),
    enabled: !!clienteId,
  });
  useEffect(() => {
    setAlocacoes({});
    setRecebido(null);
  }, [clienteId]);

  const facturas = pendentes.data?.itens ?? [];
  const total = totalAlocado(alocacoes);
  const distribuicao = recebido !== null ? distribuirMontante(facturas, recebido) : null;
  const emitir = useAccao<ReciboVenda>({ invalidar: [['vendas']], aoSucesso: (r) => navegar(`../${r.id}`), tituloErro: 'Não foi possível emitir o recibo' });

  return (
    <>
      <CabecalhoPagina titulo="Novo recibo" accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
      <Form<ValoresRecibo>
        form={form}
        layout="vertical"
        initialValues={{ data: dayjs(), meio_pagamento: 'NUMERARIO', contabilizar: pode('vendas_fat_contabilizar') }}
        onFinish={(v) => {
          if (adiantamento) {
            emitir.mutate({
              url: '/vendas/recibos',
              dados: { tipo_recibo: 'ADIANTAMENTO', cliente_id: v.cliente_id, data: dataApi(v.data), codigo_conta: v.codigo_conta, meio_pagamento: v.meio_pagamento,
                referencia_pagamento: v.referencia_pagamento || undefined, contabilizar: v.contabilizar, montante: v.montante, observacoes: v.observacoes || undefined },
            });
            return;
          }
          const linhas = alocacoesParaPedido(alocacoes);
          if (!linhas.length) {
            form.setFields([{ name: 'cliente_id', errors: ['Indique o montante a liquidar em pelo menos uma factura.'] }]);
            return;
          }
          emitir.mutate({
            url: '/vendas/recibos',
            dados: { cliente_id: v.cliente_id, data: dataApi(v.data), codigo_conta: v.codigo_conta, meio_pagamento: v.meio_pagamento, referencia_pagamento: v.referencia_pagamento || undefined, contabilizar: v.contabilizar, alocacoes: linhas },
          });
        }}
      >
        <Card
          title="Recebimento"
          style={{ marginBottom: 16 }}
          extra={
            <Segmented
              value={tipoRecibo}
              onChange={(v) => setTipoRecibo(v as 'NORMAL' | 'ADIANTAMENTO')}
              options={[{ value: 'NORMAL', label: 'Liquidar facturas' }, { value: 'ADIANTAMENTO', label: 'Adiantamento (sem factura)' }]}
            />
          }
        >
          {adiantamento && (
            <Alert type="info" showIcon style={{ marginBottom: 16 }}
              message="Recibo de adiantamento: D caixa/banco / C adiantamentos de clientes (conta configurada nas contas de vendas). Depois de contabilizado, aloque-o às facturas no detalhe do recibo." />
          )}
          <Row gutter={16}>
            <Col xs={24} md={10}>
              <Form.Item name="cliente_id" label="Cliente" rules={[{ required: true, message: 'Escolha o cliente.' }]}>
                <SeletorTerceiro papel="CLIENTE" />
              </Form.Item>
            </Col>
            <Col xs={12} md={5}>
              <Form.Item name="data" label="Data" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={9}>
              <Form.Item name="codigo_conta" label="Conta de caixa/banco" rules={[{ required: true, message: 'Indique a conta (45 caixa, 43 bancos).' }]}>
                <SeletorConta prefixo="4" placeholder="Ex.: 4511" />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name="meio_pagamento" label="Meio de pagamento">
                <Select options={MEIOS_RECIBO} />
              </Form.Item>
            </Col>
            <Col xs={12} md={8}>
              <Form.Item name="referencia_pagamento" label="Referência" rules={[{ max: 50 }]}>
                <Input />
              </Form.Item>
            </Col>
            <Col xs={24} md={10}>
              <Form.Item name="contabilizar" valuePropName="checked" label=" ">
                <Checkbox disabled={!pode('vendas_fat_contabilizar')}>Contabilizar ao emitir</Checkbox>
              </Form.Item>
            </Col>
            {adiantamento && (
              <>
                <Col xs={24} md={6}>
                  <Form.Item name="montante" label="Montante (Kz)" rules={[{ required: true, message: 'Indique o montante.' }]}>
                    <InputNumber min={0.01} precision={2} style={{ width: '100%' }} />
                  </Form.Item>
                </Col>
                <Col xs={24} md={18}>
                  <Form.Item name="observacoes" label="Descrição">
                    <Input maxLength={255} placeholder="Ex.: sinal da encomenda, adiantamento da obra…" />
                  </Form.Item>
                </Col>
              </>
            )}
          </Row>
        </Card>
        {!adiantamento && <Card
          title="Facturas a liquidar"
          style={{ marginBottom: 16 }}
          extra={
            <Space wrap>
              <span>Valor recebido</span>
              <InputNumber min={0} precision={2} style={{ width: 170 }} value={recebido} disabled={!facturas.length} onChange={(v) => setRecebido(v)} />
              <Button disabled={!distribuicao} onClick={() => distribuicao && setAlocacoes(distribuicao.alocacoes)}>Distribuir</Button>
            </Space>
          }
        >
          {distribuicao && distribuicao.excedente > 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`O valor recebido excede o pendente em ${formatarKz(distribuicao.excedente)} Kz.`} />}
          <Table<DocumentoVenda>
            rowKey="id"
            size="small"
            pagination={false}
            loading={pendentes.isFetching}
            dataSource={facturas}
            scroll={scrollTabela()}
            locale={{ emptyText: clienteId ? 'O cliente não tem facturas pendentes.' : 'Escolha o cliente.' }}
            columns={[
              { title: 'Factura', dataIndex: 'numero_documento' },
              { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
              { title: 'Vencimento', dataIndex: 'data_vencimento', render: formatarData },
              { title: 'Total (Kz)', dataIndex: 'total_bruto', align: 'right', render: (v: string) => formatarKz(v) },
              { title: 'Pendente (Kz)', dataIndex: 'valor_pendente', align: 'right', render: (v: string | null) => formatarKz(v) },
              {
                title: 'A liquidar (Kz)',
                key: 'liq',
                render: (_, d) => (
                  <Space wrap>
                    <InputNumber min={0} max={Number(d.valor_pendente ?? 0)} precision={2} style={{ width: 150 }} value={alocacoes[d.id] ?? null} onChange={(v) => setAlocacoes((a) => ({ ...a, [d.id]: v }))} />
                    <Button size="small" onClick={() => setAlocacoes((a) => ({ ...a, [d.id]: Number(d.valor_pendente ?? 0) }))}>Total</Button>
                  </Space>
                ),
              },
            ]}
          />
          <Flex justify="end" style={{ marginTop: 16 }}>
            <Statistic title="Total do recibo (Kz)" value={formatarKz(total)} />
          </Flex>
        </Card>}
        <Space wrap>
          <Button type="primary" htmlType="submit" loading={emitir.isPending} disabled={adiantamento ? !(Number(montanteAdiantamento) > 0) : total <= 0}>
            {adiantamento ? 'Emitir recibo de adiantamento' : 'Emitir recibo'}
          </Button>
          <Button onClick={() => navegar('..')}>Cancelar</Button>
        </Space>
      </Form>
    </>
  );
}

function DetalheRecibo() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, setModal] = useState<'anular' | 'descontabilizar' | 'alocar' | null>(null);
  const consulta = useQuery({ queryKey: ['vendas', 'recibo', id], queryFn: () => obter<ReciboVenda>(`/vendas/recibos/${id}`) });
  const accao = useAccao<ReciboVenda>({ invalidar: [['vendas']], aoSucesso: () => setModal(null) });

  if (consulta.isLoading) return <Skeleton active />;
  const r = consulta.data;
  if (!r) return <Alert type="error" message="Recibo não encontrado." />;
  const a = accoesRecibo(r, pode);

  return (
    <>
      <CabecalhoPagina
        titulo={`Recibo ${r.numero_recibo}`}
        subtitulo={r.cliente?.nome}
        impressao={() => pedidoRecibo(r)}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.contabilizar && <Button type="primary" loading={accao.isPending} onClick={() => accao.mutate({ url: `/vendas/recibos/${r.id}/contabilizar` })}>Contabilizar</Button>}
            {a.alocar && <Button type="primary" onClick={() => setModal('alocar')}>Alocar a facturas</Button>}
            {a.descontabilizar && <Button danger onClick={() => setModal('descontabilizar')}>Descontabilizar</Button>}
            {a.anular && <Button danger onClick={() => setModal('anular')}>Anular</Button>}
          </>
        }
      />
      {r.estado === 'ANULADO' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Recibo anulado${r.motivo_anulacao ? `: ${r.motivo_anulacao}` : '.'}`} />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={COLUNAS_DESCRICOES} size="small">
          <Descriptions.Item label="Cliente">{r.cliente?.nome ?? `#${r.cliente_id}`}{r.cliente?.nif ? ` (NIF ${r.cliente.nif})` : ''}</Descriptions.Item>
          <Descriptions.Item label="Data">{formatarData(r.data)}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={r.estado} /></Descriptions.Item>
          <Descriptions.Item label="Montante">{formatarKz(r.montante_total, true)}</Descriptions.Item>
          {r.tipo_recibo === 'ADIANTAMENTO' && (
            <Descriptions.Item label="Adiantamento"><Tag color="purple">Saldo por alocar: {formatarKz(r.saldo_adiantamento ?? 0)} Kz</Tag></Descriptions.Item>
          )}
          <Descriptions.Item label="Meio">{rotuloMeio(r.meio_pagamento)}{r.referencia_pagamento ? ` · ${r.referencia_pagamento}` : ''}</Descriptions.Item>
          <Descriptions.Item label="Conta">{r.codigo_conta ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Contabilização">{r.contabilizado ? <Tag color="green">Contabilizado{r.numero_lan_contabilizacao ? ` (${r.numero_lan_contabilizacao})` : ''}</Tag> : 'Por contabilizar'}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card title="Facturas liquidadas">
        <Table scroll={scrollTabela()}
          rowKey="venda_id"
          size="small"
          pagination={false}
          dataSource={r.alocacoes ?? []}
          onRow={(l) => ({ onClick: () => navegar(`/m/vendas/vendas_faturacao/${l.venda_id}`), style: { cursor: 'pointer' } })}
          columns={[
            { title: 'Factura', dataIndex: 'numero_documento', render: (v: string | null, l) => v ?? `#${l.venda_id}` },
            ...(r.tipo_recibo === 'ADIANTAMENTO' ? [
              { title: 'Data da alocação', dataIndex: 'data_alocacao', render: (v: string | null) => formatarData(v) },
              { title: 'Lançamento', dataIndex: 'numero_lan', render: (v: string | null) => v ?? '—' },
            ] : []),
            { title: 'Montante (Kz)', dataIndex: 'montante', align: 'right', render: (v: string) => formatarKz(v) },
          ]}
        />
        <Typography.Text type="secondary">Clique numa factura para abrir o documento.</Typography.Text>
      </Card>
      {modal === 'alocar' && <ModalAlocar recibo={r} aoFechar={() => setModal(null)} />}
      <ModalMotivo
        aberto={modal === 'descontabilizar'}
        titulo={`Descontabilizar o recibo ${r.numero_recibo}`}
        textoOk="Descontabilizar"
        aviso="O lançamento é estornado (fica o rasto no Diário)."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/vendas/recibos/${r.id}/descontabilizar`, dados: { motivo } })}
      />
      <ModalMotivo
        aberto={modal === 'anular'}
        titulo={`Anular o recibo ${r.numero_recibo}`}
        textoOk="Anular"
        aviso="As facturas voltam a ficar pendentes no valor liquidado."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/vendas/recibos/${r.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}

/** M-18: alocar o saldo de um recibo de adiantamento a facturas do cliente (D adiantamentos / C cliente por alocação). */
function ModalAlocar({ recibo, aoFechar }: { recibo: ReciboVenda; aoFechar: () => void }) {
  const [alocacoes, setAlocacoes] = useState<Record<number, number | null>>({});
  const [data, setData] = useState<Dayjs>(dayjs());
  const saldo = Number(recibo.saldo_adiantamento ?? 0);
  const pendentes = useQuery({
    queryKey: ['vendas', 'pendentes', recibo.cliente_id],
    queryFn: () => obterPagina<DocumentoVenda>('/vendas/documentos', { cliente_id: recibo.cliente_id, pendentes: 1, por_pagina: 200 }),
  });
  const facturas = (pendentes.data?.itens ?? []).filter((f) => f.contabilizado);
  const total = totalAlocado(alocacoes);
  const accao = useAccao<ReciboVenda>({ invalidar: [['vendas']], aoSucesso: aoFechar, tituloErro: 'Não foi possível alocar o adiantamento' });

  return (
    <Modal
      open
      title={`Alocar o adiantamento ${recibo.numero_recibo}`}
      width={760}
      style={{ maxWidth: 'calc(100vw - 32px)' }}
      onCancel={aoFechar}
      okText="Alocar"
      okButtonProps={{ disabled: total <= 0 || total > saldo + 0.005 }}
      confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ url: `/vendas/recibos/${recibo.id}/alocar`, dados: { data: dataApi(data), alocacoes: alocacoesParaPedido(alocacoes) } })}
    >
      <Space wrap style={{ marginBottom: 12 }}>
        <Tag color="purple">Saldo por alocar: {formatarKz(saldo)} Kz</Tag>
        <span>Data</span>
        <DatePicker format="DD/MM/YYYY" value={data} onChange={(d) => d && setData(d)} allowClear={false} />
        <Button onClick={() => setAlocacoes(distribuirMontante(facturas, saldo).alocacoes)} disabled={!facturas.length}>Distribuir o saldo</Button>
      </Space>
      {total > saldo + 0.005 && <Alert type="error" showIcon style={{ marginBottom: 12 }} message={`O total a alocar (${formatarKz(total)} Kz) excede o saldo do adiantamento.`} />}
      <Table<DocumentoVenda>
        rowKey="id"
        size="small"
        pagination={false}
        loading={pendentes.isFetching}
        dataSource={facturas}
        scroll={scrollTabela()}
        locale={{ emptyText: 'O cliente não tem facturas contabilizadas pendentes.' }}
        columns={[
          { title: 'Factura', dataIndex: 'numero_documento' },
          { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
          { title: 'Pendente (Kz)', dataIndex: 'valor_pendente', align: 'right', render: (v: string | null) => formatarKz(v) },
          {
            title: 'A alocar (Kz)', key: 'aloc',
            render: (_, d) => <InputNumber min={0} max={Number(d.valor_pendente ?? 0)} precision={2} style={{ width: 150 }} value={alocacoes[d.id] ?? null}
              aria-label={`Montante a alocar a ${d.numero_documento}`} onChange={(v) => setAlocacoes((a) => ({ ...a, [d.id]: v }))} />,
          },
        ]}
      />
      <Flex justify="end" style={{ marginTop: 12 }}><Statistic title="Total a alocar (Kz)" value={formatarKz(total)} /></Flex>
    </Modal>
  );
}
