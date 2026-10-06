import { Alert, Button, Card, Col, DatePicker, Descriptions, Dropdown, Form, Input, InputNumber, Modal, Popconfirm, Row, Segmented, Skeleton, Space, Statistic, Tag, Typography, message } from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, DownOutlined, FileDoneOutlined, PlusOutlined, TagsOutlined, UnlockOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { pares, tabelaHtml } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../contab/comum/Componentes';
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import { SeletorAux, SeletorConta, SeletorTerceiro, SeletorUnidade } from '../contab/comum/Seletores';
import { porId, useTabelaAux } from '../contab/comum/dados';
import { ModalMotivo } from '@/componentes/Accoes';
import { ModalLiquidarFacturas } from './caixa/ModalLiquidarFacturas';
import { ModalClassificarMovimentos } from './caixa/ModalClassificarMovimentos';
import { CONFIG_LIQUIDACAO, type ModoLiquidacao } from './caixaLiquidacao';
import type { MovimentoCaixa, SessaoCaixa } from './api';
import { SeletorContaFinanceira } from './comum';
import { accoesSessao } from './regras';
import { COLUNAS_DESCRICOES, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';

import { TabelaComModos } from '@/componentes/vistas';
const ROTULO_SESSAO: Record<string, string> = { ABERTA: 'Aberta', FECHADA: 'Fechada', CONTABILIZADA: 'Contabilizada' };

/** Folha de caixa impressa de uma sessão: resumo de saldos, movimentos (entradas/saídas) com totais e assinaturas. */
export function pedidoFolhaCaixa(s: SessaoCaixa) {
  const movs = s.movimentos ?? [];
  return {
    titulo: `Folha de caixa — sessão #${s.id}`,
    periodo: `${formatarData(s.data_abertura)}${s.data_fecho ? ` a ${formatarData(s.data_fecho)}` : ''}`,
    filtros: [`Conta ${s.codigo_conta}`, `Estado: ${ROTULO_SESSAO[s.estado] ?? s.estado}`],
    conteudo:
      pares(
        [
          ['Saldo de abertura', `${formatarKz(s.saldo_abertura)} Kz`],
          ['Saldo do sistema', `${formatarKz(s.saldo_sistema)} Kz`],
          ['Saldo contado', s.saldo_fisico !== null ? `${formatarKz(s.saldo_fisico)} Kz` : '—'],
          ['Diferença', s.diferenca !== null && s.diferenca !== undefined ? `${formatarKz(s.diferenca)} Kz` : '—'],
          ['Operador', s.operador ?? '—'],
          ['Lançamentos', s.numeros_lan_contabilizacao ?? '—'],
        ],
        3,
      ) +
      tabelaHtml({
        colunas: [
          { titulo: 'Data', valor: (m: MovimentoCaixa) => m.data_documento, formato: 'data' },
          { titulo: 'Documento', valor: (m) => m.numero_documento ?? '' },
          { titulo: 'Terceiro', valor: (m) => m.terceiro?.nome?.trim() ?? '', quebrar: true },
          { titulo: 'Descrição', valor: (m) => m.descricao ?? '', quebrar: true },
          { titulo: 'Débito', valor: (m) => m.conta_debito },
          { titulo: 'Crédito', valor: (m) => m.conta_credito },
          { titulo: 'Entrada (Kz)', valor: (m) => (m.tipo === 'REC' ? m.valor : null), formato: 'moeda', somar: true },
          { titulo: 'Saída (Kz)', valor: (m) => (m.tipo === 'PAG' ? m.valor : null), formato: 'moeda', somar: true },
          { titulo: 'Origem', valor: (m) => m.tipo_origem ?? 'Manual' },
        ],
        linhas: movs,
        totais: true,
        vazio: 'Sem movimentos.',
      }) +
      '<div class="imp-sem-quebra" style="display:flex;justify-content:space-around;gap:10mm;margin-top:14mm"><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">O operador de caixa</div><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">O responsável</div></div>',
  };
}

/** Tesouraria › Folha de Caixa (ecrã teso_folha_caixa): sessões por conta 45, movimentos, fecho com contagem e contabilização (diário CX). */
export default function FolhaCaixa() {
  return (
    <Routes>
      <Route index element={<ListaSessoes />} />
      <Route path=":id" element={<DetalheSessao />} />
    </Routes>
  );
}

function ListaSessoes() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [conta, setConta] = useState<string>();
  const [abrir, setAbrir] = useState(false);
  const [form] = Form.useForm<{ codigo_conta: string; data: Dayjs; saldo_abertura?: number }>();
  const sessoes = useQuery({ queryKey: ['teso', 'caixa', 'sessoes', conta], queryFn: () => obter<SessaoCaixa[]>('/tesouraria/caixa/sessoes', { codigo_conta: conta }) });
  const pequeno = useEcraPequeno();
  const abertura = useMutation({
    mutationFn: (v: { codigo_conta: string; data: Dayjs; saldo_abertura?: number }) => enviar<SessaoCaixa>('post', '/tesouraria/caixa/sessoes', { ...v, data: dataApi(v.data) }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      if (dados.aviso) message.warning(dados.aviso, 8);
      void cliente.invalidateQueries({ queryKey: ['teso', 'caixa'] });
      navegar(String(dados.id));
    },
    onError: (e) => notificarErro(e, 'Não foi possível abrir a sessão'),
  });

  return (
    <>
      <CabecalhoPagina
        titulo="Folha de Caixa"
        subtitulo="Sessões de caixa por conta"
        impressaoDesactivada={!sessoes.data?.length}
        impressao={() => ({
          titulo: 'Sessões de caixa',
          filtros: [conta ? `Conta ${conta}` : 'Todas as contas de caixa'],
          conteudo: tabelaHtml({
            colunas: [
              { titulo: 'Sessão', valor: (r: SessaoCaixa) => `#${r.id}` },
              { titulo: 'Conta', valor: (r) => r.codigo_conta },
              { titulo: 'Abertura', valor: (r) => r.data_abertura, formato: 'data' },
              { titulo: 'Fecho', valor: (r) => r.data_fecho, formato: 'data' },
              { titulo: 'Operador', valor: (r) => r.operador ?? '' },
              { titulo: 'Saldo de abertura', valor: (r) => r.saldo_abertura, formato: 'moeda' },
              { titulo: 'Saldo de fecho', valor: (r) => r.saldo_fecho, formato: 'moeda' },
              { titulo: 'Contado', valor: (r) => r.saldo_fisico, formato: 'moeda' },
              { titulo: 'Estado', valor: (r) => ROTULO_SESSAO[r.estado] ?? r.estado },
            ],
            linhas: sessoes.data ?? [],
          }),
        })}
        accoes={pode('teso_caixa_operar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.setFieldsValue({ data: dayjs() }); setAbrir(true); }}>Abrir sessão</Button>}
      />
      <Card>
        <Space wrap style={{ marginBottom: 16 }}>
          <SeletorContaFinanceira value={conta} onChange={setConta} allowClear prefixos={['45']} placeholder="Conta de caixa" />
        </Space>
        <TabelaComModos<SessaoCaixa> idVista="sessoes"
          rowKey="id"
          loading={sessoes.isLoading}
          dataSource={sessoes.data}
          size={pequeno ? 'small' : 'middle'}
          pagination={{ pageSize: 25 }}
          scroll={scrollTabela()}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
          columns={[
            { title: 'Sessão', dataIndex: 'id', render: (v: number) => <strong>#{v}</strong> },
            { title: 'Conta', dataIndex: 'codigo_conta', responsive: ['sm'] },
            { title: 'Abertura', dataIndex: 'data_abertura', render: formatarData },
            { title: 'Fecho', dataIndex: 'data_fecho', responsive: ['md'], render: formatarData },
            { title: 'Operador', dataIndex: 'operador', responsive: ['lg'] },
            { title: 'Saldo de abertura', dataIndex: 'saldo_abertura', align: 'right', responsive: ['lg'], render: (v: string | null) => <ValorKz valor={v} /> },
            { title: 'Saldo de fecho', dataIndex: 'saldo_fecho', align: 'right', responsive: ['md'], render: (v: string | null) => <ValorKz valor={v} /> },
            { title: 'Contado', dataIndex: 'saldo_fisico', align: 'right', responsive: ['lg'], render: (v: string | null) => <ValorKz valor={v} /> },
            { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
          ]}
        />
      </Card>
      <Modal width={larguraModal(480)} title="Abrir sessão de caixa" open={abrir} onCancel={() => setAbrir(false)} okText="Abrir" confirmLoading={abertura.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(v) => abertura.mutate(v)}>
          <Form.Item name="codigo_conta" label="Conta de caixa" rules={[{ required: true }]}>
            <SeletorContaFinanceira prefixos={['45']} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}>
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="saldo_abertura" label="Saldo de abertura" tooltip="Vazio = saldo de fecho da sessão anterior">
            <InputNumber min={0} precision={2} style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}

interface ValoresMovimento {
  tipo: 'REC' | 'PAG';
  data_documento: Dayjs;
  conta_contrapartida: string;
  valor: number;
  descricao: string;
  terceiro_id?: number;
  numero_documento?: string;
  referencia?: string;
  centro_custo_id?: number;
  unidade_negocio_id?: number;
  nota_demonstracao_id?: number;
  nota_fluxo_caixa_id?: number;
}

function DetalheSessao() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [movimento, setMovimento] = useState(false);
  const [fecho, setFecho] = useState(false);
  const [descontab, setDescontab] = useState(false);
  // A-11: pagar/receber facturas, classificar linhas (notas, UN, CC) e reabrir a sessão fechada
  const [liquidar, setLiquidar] = useState<ModoLiquidacao | null>(null);
  const [seleccionados, setSeleccionados] = useState<number[]>([]);
  const [classificar, setClassificar] = useState(false);
  const [reabrir, setReabrir] = useState(false);
  const notasDemo = porId(useTabelaAux('notas-demonstracao').data, (n) => n.codigo);
  const notasFluxo = porId(useTabelaAux('notas-fluxo-caixa').data, (n) => n.codigo);
  const [formMov] = Form.useForm<ValoresMovimento>();
  const [formFecho] = Form.useForm<{ saldo_fisico: number; data: Dayjs }>();
  const [formMotivo] = Form.useForm<{ motivo: string }>();
  const contado = Form.useWatch('saldo_fisico', formFecho);
  const consulta = useQuery({ queryKey: ['teso', 'caixa', 'sessao', id], queryFn: () => obter<SessaoCaixa>(`/tesouraria/caixa/sessoes/${id}`) });

  const accao = useMutation({
    mutationFn: ({ metodo = 'post', caminho, dados }: { metodo?: 'post' | 'put' | 'delete'; caminho: string; dados?: unknown }) => enviar<SessaoCaixa | null>(metodo, `/tesouraria/caixa/sessoes/${id}${caminho}`, dados),
    onSuccess: ({ mensagem }, { caminho, metodo }) => {
      message.success(mensagem);
      setMovimento(false);
      setFecho(false);
      setDescontab(false);
      setClassificar(false);
      setReabrir(false);
      setSeleccionados([]);
      formMov.resetFields();
      formMotivo.resetFields();
      void cliente.invalidateQueries({ queryKey: ['teso'] });
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      if (metodo === 'delete' && caminho === '') navegar('..');
    },
    onError: (e) => notificarErro(e),
  });

  if (consulta.isLoading) return <Skeleton active />;
  const s = consulta.data;
  if (!s) return <Alert type="error" message="Sessão não encontrada." />;
  const a = accoesSessao(s, pode);
  const diferencaFecho = contado !== undefined && contado !== null && s.saldo_sistema !== undefined ? deCentimos(paraCentimos(contado) - paraCentimos(s.saldo_sistema)) : null;

  return (
    <>
      <CabecalhoPagina
        titulo={`Sessão de caixa #${s.id}`}
        subtitulo={`Conta ${s.codigo_conta} · ${formatarData(s.data_abertura)}`}
        impressao={() => pedidoFolhaCaixa(s)}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.podeRegistar && <Button icon={<PlusOutlined />} onClick={() => { formMov.setFieldsValue({ tipo: 'REC', data_documento: dayjs(s.data_abertura) }); setMovimento(true); }}>Registar movimento</Button>}
            {a.podeRegistar && (
              <Dropdown menu={{ items: (['PAGAR', 'RECEBER'] as const).map((m) => ({ key: m, label: CONFIG_LIQUIDACAO[m].botao })), onClick: ({ key }) => setLiquidar(key as ModoLiquidacao) }}>
                <Button icon={<FileDoneOutlined />}>Facturas <DownOutlined /></Button>
              </Dropdown>
            )}
            {a.podeReabrir && <Button icon={<UnlockOutlined />} onClick={() => setReabrir(true)}>Reabrir sessão</Button>}
            {a.podeFechar && <Button type="primary" onClick={() => { formFecho.setFieldsValue({ data: dayjs(), saldo_fisico: Number(s.saldo_sistema ?? 0) }); setFecho(true); }}>Fechar sessão</Button>}
            {a.podeContabilizar && <Button type="primary" loading={accao.isPending} onClick={() => Modal.confirm({ title: 'Contabilizar a sessão?', content: 'São gerados os lançamentos no diário de caixa.', okText: 'Contabilizar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ caminho: '/contabilizar' }) })}>Contabilizar</Button>}
            {a.podeDescontabilizar && <Button danger onClick={() => setDescontab(true)}>Descontabilizar</Button>}
            {a.podeEliminar && (
              <Popconfirm title="Eliminar esta sessão (sem movimentos)?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', caminho: '' })}>
                <Button danger icon={<DeleteOutlined />}>Eliminar</Button>
              </Popconfirm>
            )}
          </>
        }
      />
      <Card style={{ marginBottom: 16 }}>
        <Space size={[40, 16]} wrap>
          <Statistic title="Saldo de abertura" value={formatarKz(s.saldo_abertura)} />
          <Statistic title="Saldo do sistema" value={formatarKz(s.saldo_sistema)} />
          {s.saldo_fisico !== null && <Statistic title="Saldo contado" value={formatarKz(s.saldo_fisico)} />}
          {s.diferenca !== null && s.diferenca !== undefined && <Statistic title="Diferença" value={formatarKz(s.diferenca)} valueStyle={{ color: paraCentimos(s.diferenca) === 0 ? undefined : '#cf1322' }} />}
        </Space>
        <Descriptions size="small" column={COLUNAS_DESCRICOES} style={{ marginTop: 16 }}>
          <Descriptions.Item label="Estado"><EtiquetaEstado estado={s.estado} /></Descriptions.Item>
          <Descriptions.Item label="Operador">{s.operador ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Fecho">{formatarData(s.data_fecho)}</Descriptions.Item>
          <Descriptions.Item label="Lançamentos">{s.numeros_lan_contabilizacao ?? '—'}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card
        title="Movimentos"
        extra={
          a.podeClassificar && (
            <Button size="small" icon={<TagsOutlined />} disabled={!seleccionados.length} onClick={() => setClassificar(true)}>
              Classificar{seleccionados.length ? ` (${seleccionados.length})` : ''}
            </Button>
          )
        }
      >
        <TabelaComModos<MovimentoCaixa> idVista="movimentos"
          rowKey="id"
          size="small"
          dataSource={s.movimentos ?? []}
          pagination={false}
          scroll={scrollTabela()}
          rowSelection={a.podeClassificar ? { selectedRowKeys: seleccionados, onChange: (k) => setSeleccionados(k as number[]), getCheckboxProps: (m) => ({ disabled: !!m.contabilizado }) } : undefined}
          columns={[
            { title: 'Data', dataIndex: 'data_documento', render: formatarData },
            { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => (v === 'REC' ? <Tag color="green">Entrada</Tag> : <Tag color="volcano">Saída</Tag>) },
            { title: 'Documento', dataIndex: 'numero_documento', responsive: ['sm'] },
            { title: 'Terceiro', key: 'terceiro', responsive: ['md'], render: (_, m) => m.terceiro?.nome?.trim() ?? (m.terceiro_id ? `#${m.terceiro_id}` : '—') },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300, responsive: ['md'] },
            { title: 'Débito', dataIndex: 'conta_debito', responsive: ['lg'] },
            { title: 'Crédito', dataIndex: 'conta_credito', responsive: ['lg'] },
            { title: 'Entrada', align: 'right', render: (_, m) => (m.tipo === 'REC' ? <ValorKz valor={m.valor} /> : null) },
            { title: 'Saída', align: 'right', render: (_, m) => (m.tipo === 'PAG' ? <ValorKz valor={m.valor} /> : null) },
            { title: 'Origem', dataIndex: 'tipo_origem', responsive: ['lg'], render: (v: string | null) => (v ? <Tag>{v}</Tag> : 'Manual') },
            { title: 'Nota DEMO', dataIndex: 'nota_demonstracao_id', responsive: ['lg'], render: (v: number | null) => (v ? notasDemo.get(v) ?? `#${v}` : <Typography.Text type="warning">—</Typography.Text>) },
            { title: 'Nota fluxo', dataIndex: 'nota_fluxo_caixa_id', responsive: ['lg'], render: (v: number | null) => (v ? notasFluxo.get(v) ?? `#${v}` : <Typography.Text type="warning">—</Typography.Text>) },
            {
              title: '',
              render: (_, m) =>
                a.podeRegistar && !m.contabilizado ? (
                  <Popconfirm title="Remover este movimento?" okText="Remover" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', caminho: `/movimentos/${m.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Remover" />
                  </Popconfirm>
                ) : null,
            },
          ]}
        />
      </Card>

      <Modal title="Registar movimento de caixa" open={movimento} onCancel={() => setMovimento(false)} okText="Registar" confirmLoading={accao.isPending} onOk={() => formMov.submit()} width={larguraModal(720)}>
        <Form form={formMov} layout="vertical" onFinish={(v) => accao.mutate({ caminho: '/movimentos', dados: { ...v, data_documento: dataApi(v.data_documento) } })}>
          <Row gutter={12}>
            <Col xs={24} sm={10}>
              <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
                <Segmented block options={[{ value: 'REC', label: 'Entrada (recebimento)' }, { value: 'PAG', label: 'Saída (pagamento)' }]} />
              </Form.Item>
            </Col>
            <Col xs={24} sm={7}>
              <Form.Item name="data_documento" label="Data" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} sm={7}>
              <Form.Item name="valor" label="Valor (Kz)" rules={[{ required: true }]}>
                <InputNumber min={0.01} precision={2} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          </Row>
          <Form.Item name="conta_contrapartida" label="Conta de contrapartida" rules={[{ required: true }]}>
            <SeletorConta style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="descricao" label="Descrição" rules={[{ required: true, min: 3 }]}>
            <Input maxLength={1000} />
          </Form.Item>
          <Row gutter={12}>
            <Col xs={24} sm={12}><Form.Item name="terceiro_id" label="Terceiro"><SeletorTerceiro style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={6}><Form.Item name="numero_documento" label="N.º documento"><Input maxLength={100} /></Form.Item></Col>
            <Col xs={24} sm={6}><Form.Item name="referencia" label="Referência"><Input maxLength={100} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="centro_custo_id" label="Centro de custo"><SeletorAux tabela="centros-custo" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="unidade_negocio_id" label="Unidade de negócio"><SeletorUnidade style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="nota_demonstracao_id" label="Nota às demonstrações"><SeletorAux tabela="notas-demonstracao" placeholder="Sem nota" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="nota_fluxo_caixa_id" label="Nota de fluxo de caixa"><SeletorAux tabela="notas-fluxo-caixa" placeholder="Sem nota" style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
        </Form>
      </Modal>

      <Modal width={larguraModal(480)} title="Fechar sessão (contagem)" open={fecho} onCancel={() => setFecho(false)} okText="Fechar sessão" confirmLoading={accao.isPending} onOk={() => formFecho.submit()}>
        <Form form={formFecho} layout="vertical" onFinish={(v) => accao.mutate({ caminho: '/fechar', dados: { saldo_fisico: v.saldo_fisico, data: dataApi(v.data) } })}>
          <Typography.Paragraph>Saldo do sistema: <strong>{formatarKz(s.saldo_sistema, true)}</strong></Typography.Paragraph>
          <Form.Item name="saldo_fisico" label="Saldo contado (Kz)" rules={[{ required: true }]}>
            <InputNumber min={0} precision={2} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="data" label="Data de fecho" rules={[{ required: true }]}>
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
          {diferencaFecho && paraCentimos(diferencaFecho) !== 0 && (
            <Alert type="warning" showIcon message={`Diferença de ${formatarKz(diferencaFecho, true)} (${paraCentimos(diferencaFecho) > 0 ? 'sobra' : 'quebra'} de caixa).`} />
          )}
        </Form>
      </Modal>

      {liquidar && <ModalLiquidarFacturas sessao={s} modo={liquidar} aoFechar={() => setLiquidar(null)} />}
      {classificar && (
        <ModalClassificarMovimentos
          quantidade={seleccionados.length}
          carregando={accao.isPending}
          aoFechar={() => setClassificar(false)}
          aoConfirmar={(campos) => accao.mutate({ metodo: 'put', caminho: '/movimentos/classificacao', dados: { movimentos: seleccionados, ...campos } })}
        />
      )}
      <ModalMotivo
        aberto={reabrir}
        titulo={`Reabrir a sessão de caixa #${s.id}`}
        textoOk="Reabrir"
        aviso="A sessão volta a ficar aberta com os mesmos movimentos; a contagem e o fecho anteriores ficam na auditoria e terá de a fechar de novo."
        carregando={accao.isPending}
        aoConfirmar={(motivo) => accao.mutate({ caminho: '/reabrir', dados: { motivo } })}
        aoFechar={() => setReabrir(false)}
      />

      <Modal width={larguraModal(480)} title="Descontabilizar a sessão (estorno)" open={descontab} onCancel={() => setDescontab(false)} okText="Descontabilizar" okButtonProps={{ danger: true }} confirmLoading={accao.isPending} onOk={() => formMotivo.submit()}>
        <Form form={formMotivo} layout="vertical" onFinish={(v) => accao.mutate({ caminho: '/descontabilizar', dados: v })}>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
