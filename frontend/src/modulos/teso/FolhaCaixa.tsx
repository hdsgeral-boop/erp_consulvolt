import { Alert, Button, Card, Col, DatePicker, Descriptions, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Segmented, Skeleton, Space, Tag, Typography, message } from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, TagsOutlined, UnlockOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
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
import { porId, usePlanoContas, useTabelaAux, useUnidadesNegocio } from '../contab/comum/dados';
import type { RegistoAux } from '../contab/api';
import { ModalMotivo } from '@/componentes/Accoes';
import { ModalLiquidarFacturas } from './caixa/ModalLiquidarFacturas';
import { ModalClassificarMovimentos } from './caixa/ModalClassificarMovimentos';
import type { ModoLiquidacao } from './caixaLiquidacao';
import type { MovimentoCaixa, SessaoCaixa } from './api';
import { SeletorContaFinanceira } from './comum';
import { accoesSessao } from './regras';
import { COLUNAS_DESCRICOES, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';

import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { ModalFormulario, SeccaoFormulario, type CampoFormulario } from '@/componentes/formularios';
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
        accoes={pode('teso_caixa_operar') && <Button icon={<IconeFa nome="door-open" />} style={{ color: '#059669', borderColor: '#059669' }} onClick={() => { form.setFieldsValue({ data: dayjs() }); setAbrir(true); }}>Abrir sessão</Button>}
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
            { title: 'Data Abertura', dataIndex: 'data_abertura', render: formatarData },
            {
              title: 'Conta', dataIndex: 'codigo_conta', responsive: ['sm'], valorImpressao: (r) => `${r.codigo_conta}${r.descricao_conta ? ` ${r.descricao_conta}` : ''}`,
              render: (v: string, r) => <><strong>{v}</strong>{r.descricao_conta && <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{r.descricao_conta}</Typography.Text>}</>,
            },
            { title: 'Operador', dataIndex: 'operador', responsive: ['lg'] },
            { title: 'Saldo Inicial', dataIndex: 'saldo_abertura', align: 'right', responsive: ['lg'], render: (v: string | null) => <ValorKz valor={v} /> },
            { title: 'Entradas', dataIndex: 'total_entradas', align: 'right', responsive: ['md'], render: (v: string | null | undefined) => (v === undefined || v === null ? '—' : <span style={{ color: '#059669', whiteSpace: 'nowrap' }}>{formatarKz(v)}</span>) },
            { title: 'Saídas', dataIndex: 'total_saidas', align: 'right', responsive: ['md'], render: (v: string | null | undefined) => (v === undefined || v === null ? '—' : <span style={{ color: '#dc2626', whiteSpace: 'nowrap' }}>{formatarKz(v)}</span>) },
            { title: 'Saldo Final', key: 'saldo_final', align: 'right', render: (_, r) => <strong><ValorKz valor={r.saldo_fecho ?? r.saldo_sistema ?? null} /></strong>, valorImpressao: (r) => formatarKz(r.saldo_fecho ?? r.saldo_sistema ?? null) },
            { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
            { title: 'Sessão', dataIndex: 'id', ocultaPorOmissao: true, render: (v: number) => <strong>#{v}</strong> },
            { title: 'Fecho', dataIndex: 'data_fecho', ocultaPorOmissao: true, render: formatarData },
            { title: 'Contado', dataIndex: 'saldo_fisico', align: 'right', ocultaPorOmissao: true, render: (v: string | null) => <ValorKz valor={v} /> },
            {
              title: 'Acções', key: 'accoes', valorImpressao: () => '',
              render: (_, r) => <Button size="small" icon={<IconeFa nome={r.estado === 'ABERTA' ? 'cash-register' : 'eye'} />} onClick={(e) => { e.stopPropagation(); navegar(String(r.id)); }}>{r.estado === 'ABERTA' ? 'Ir para a caixa' : 'Ver'}</Button>,
            },
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

const TIPOS_MOVIMENTO = [{ value: 'REC', label: 'Entrada (recebimento)' }, { value: 'PAG', label: 'Saída (pagamento)' }];

/** Totais de entradas e saídas dos movimentos da sessão (resumo do legado). */
export function totaisMovimentos(movs: Pick<MovimentoCaixa, 'tipo' | 'valor'>[]): { entradas: number; saidas: number } {
  let e = 0;
  let sa = 0;
  for (const m of movs) {
    if (m.tipo === 'REC') e += paraCentimos(m.valor ?? 0);
    else if (m.tipo === 'PAG') sa += paraCentimos(m.valor ?? 0);
  }
  return { entradas: Number(deCentimos(e)), saidas: Number(deCentimos(sa)) };
}

/** Data do formulário (dayjs) ou da vista Linhas ('AAAA-MM-DD') → API. */
const dataDe = (d: unknown): string | undefined => (dayjs.isDayjs(d) ? dataApi(d) : typeof d === 'string' && d ? d : undefined);

/** Regras de gravação do movimento de caixa, comuns à grade e à vista Linhas: data na API e textos vazios omitidos. */
export function dadosMovimento(v: Record<string, unknown>): Record<string, unknown> {
  const dados: Record<string, unknown> = {};
  for (const [k, x] of Object.entries(v)) if (x !== undefined && x !== null && !(typeof x === 'string' && !x.trim())) dados[k] = typeof x === 'string' ? x.trim() : x;
  dados.data_documento = dataDe(v.data_documento);
  return dados;
}

const opcoesAux = (l: RegistoAux[] | undefined) => (l ?? []).map((r) => ({ value: r.id, label: `${r.codigo} — ${r.descricao ?? ''}` }));
const SO_DIGITOS = /^\d+$/;

/** Vista Linhas do «Registar movimento de caixa»: um movimento por linha, com as mesmas regras do formulário. */
function useCamposMovimento(activo: boolean): CampoFormulario[] {
  const plano = usePlanoContas(activo);
  const un = useUnidadesNegocio(activo);
  const cc = useTabelaAux('centros-custo', activo);
  const nd = useTabelaAux('notas-demonstracao', activo);
  const nf = useTabelaAux('notas-fluxo-caixa', activo);
  return useMemo<CampoFormulario[]>(() => [
    { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', obrigatorio: true, opcoes: [{ value: 'REC', label: 'Entrada' }, { value: 'PAG', label: 'Saída' }], largura: 110, aliases: ['Entrada/Saída'] },
    { nome: 'data_documento', rotulo: 'Data', tipo: 'data', obrigatorio: true, largura: 140 },
    { nome: 'valor', rotulo: 'Valor (Kz)', tipo: 'numero', obrigatorio: true, min: 0.01, precisao: 2, largura: 130, aliases: ['Valor'] },
    { nome: 'conta_contrapartida', rotulo: 'Conta de contrapartida', tipo: 'select', obrigatorio: true, largura: 230, aliases: ['Contrapartida', 'Conta'],
      opcoes: (plano.data ?? []).filter((c) => c.tipo === 'M').map((c) => ({ value: c.codigo, label: `${c.codigo} - ${c.descricao ?? ''}` })) },
    { nome: 'descricao', rotulo: 'Descrição', obrigatorio: true, largura: 240, maxLength: 1000, validar: (d) => (typeof d === 'string' && d.trim() && d.trim().length < 3 ? 'A descrição tem de ter pelo menos 3 caracteres.' : null) },
    { nome: 'terceiro_id', rotulo: 'Terceiro', tipo: 'personalizado', largura: 220, emLinhas: 'opcional', seccao: 'Terceiro e documento',
      celula: ({ valor, aoMudar, rotulo }) => <div aria-label={rotulo}><SeletorTerceiro value={(valor as number | undefined) ?? undefined} onChange={(x) => aoMudar(x ?? null)} style={{ width: '100%' }} /></div>,
      converter: (t) => (SO_DIGITOS.test(t.trim()) ? Number(t.trim()) : undefined) },
    { nome: 'numero_documento', rotulo: 'N.º documento', largura: 130, maxLength: 100, emLinhas: 'opcional', seccao: 'Terceiro e documento', aliases: ['Nº Doc.', 'Documento'] },
    { nome: 'referencia', rotulo: 'Referência', largura: 130, maxLength: 100, emLinhas: 'opcional', seccao: 'Terceiro e documento' },
    { nome: 'unidade_negocio_id', rotulo: 'Unidade de negócio', tipo: 'select', largura: 170, emLinhas: 'opcional', seccao: 'Classificação', aliases: ['UN'],
      opcoes: (un.data ?? []).map((x) => ({ value: x.id, label: `${x.codigo ? `${x.codigo} — ` : ''}${x.nome}` })) },
    { nome: 'centro_custo_id', rotulo: 'Centro de custo', tipo: 'select', largura: 170, emLinhas: 'opcional', seccao: 'Classificação', aliases: ['CC'], opcoes: opcoesAux(cc.data) },
    { nome: 'nota_demonstracao_id', rotulo: 'Nota DEMO', tipo: 'select', largura: 170, emLinhas: 'opcional', seccao: 'Classificação', aliases: ['Nota às demonstrações'], opcoes: opcoesAux(nd.data) },
    { nome: 'nota_fluxo_caixa_id', rotulo: 'Nota fluxo', tipo: 'select', largura: 170, emLinhas: 'opcional', seccao: 'Classificação', aliases: ['Nota de fluxo de caixa'], opcoes: opcoesAux(nf.data) },
  ], [plano.data, un.data, cc.data, nd.data, nf.data]);
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
  const unidades = porId(useUnidadesNegocio().data, (u) => `${u.codigo ? `${u.codigo} ` : ''}${u.nome}`);
  const centros = porId(useTabelaAux('centros-custo').data, (c) => c.codigo);
  const [formMov] = Form.useForm<ValoresMovimento>();
  const camposMov = useCamposMovimento(movimento);
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
  const totais = totaisMovimentos(s.movimentos ?? []);
  const registar = (tipo: 'REC' | 'PAG') => { formMov.setFieldsValue({ tipo, data_documento: dayjs(s.data_abertura) }); setMovimento(true); };
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
            {a.podeReabrir && <Button icon={<UnlockOutlined />} onClick={() => setReabrir(true)}>Reabrir sessão</Button>}
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
        {/* resumo da sessão do legado (ui_folha_caixa.js:147-175 e 1246-1263): saldo inicial, entradas, saídas, saldo actual / diferença */}
        <GrelhaKpi larguraMinima={180}>
          <CartaoKpi rotulo="Saldo Inicial" valor={formatarKz(s.saldo_abertura)} unidade="Kz" cor="cinzento" />
          <CartaoKpi rotulo="Entradas" valor={`+${formatarKz(totais.entradas)}`} unidade="Kz" cor="sucesso" corValor="#059669" />
          <CartaoKpi rotulo="Saídas" valor={`-${formatarKz(totais.saidas)}`} unidade="Kz" cor="#dc2626" corValor="#dc2626" />
          <CartaoKpi rotulo="Saldo Actual" valor={formatarKz(s.saldo_sistema)} unidade="Kz" cor="primario" corValor={paraCentimos(s.saldo_sistema ?? 0) < 0 ? '#dc2626' : '#2563eb'} />
          {s.saldo_fisico !== null && <CartaoKpi rotulo="Saldo contado" valor={formatarKz(s.saldo_fisico)} unidade="Kz" cor="roxo" />}
          {s.diferenca !== null && s.diferenca !== undefined && (
            <CartaoKpi rotulo="Diferença" valor={formatarKz(s.diferenca)} unidade="Kz" cor={paraCentimos(s.diferenca) === 0 ? '#059669' : '#f59e0b'} corValor={paraCentimos(s.diferenca) === 0 ? '#059669' : '#f59e0b'}
              secundaria={paraCentimos(s.diferenca) === 0 ? 'Sem desvio' : paraCentimos(s.diferenca) > 0 ? 'Sobra' : 'Quebra'} />
          )}
        </GrelhaKpi>
        {(a.podeRegistar || a.podeFechar) && (
          <Flex wrap gap={8} justify="space-between" style={{ marginTop: 16 }} data-testid="botoes-sessao">
            <Space wrap>
              {a.podeRegistar && <>
                <Button danger icon={<IconeFa nome="arrow-circle-down" />} onClick={() => registar('PAG')}>Registar Saída / Despesa</Button>
                <Button icon={<IconeFa nome="arrow-circle-up" />} style={{ color: '#059669', borderColor: '#059669' }} onClick={() => registar('REC')}>Registar Entrada / Receita</Button>
                <Button icon={<IconeFa nome="file-invoice-dollar" />} style={{ color: '#0284c7', borderColor: '#0284c7' }} onClick={() => setLiquidar('PAGAR')}>Pagar Faturas Fornecedor</Button>
                <Button icon={<IconeFa nome="hand-holding-usd" />} style={{ color: '#7c3aed', borderColor: '#7c3aed' }} onClick={() => setLiquidar('RECEBER')}>Receber Faturas Cliente</Button>
              </>}
            </Space>
            {a.podeFechar && <Button icon={<IconeFa nome="lock" />} style={{ color: '#d97706', borderColor: '#f59e0b' }} onClick={() => { formFecho.setFieldsValue({ data: dayjs(), saldo_fisico: Number(s.saldo_sistema ?? 0) }); setFecho(true); }}>Fechar Sessão</Button>}
          </Flex>
        )}
        <Descriptions size="small" column={COLUNAS_DESCRICOES} style={{ marginTop: 16 }}>
          <Descriptions.Item label="Estado"><EtiquetaEstado estado={s.estado} /></Descriptions.Item>
          <Descriptions.Item label="Operador">{s.operador ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Fecho">{formatarData(s.data_fecho)}</Descriptions.Item>
          <Descriptions.Item label="Lançamentos">{s.numeros_lan_contabilizacao ?? '—'}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card
        title={`Movimentos da Sessão (${(s.movimentos ?? []).length})`}
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
            {
              title: 'Tipo', dataIndex: 'tipo', valorImpressao: (m) => (m.tipo === 'REC' ? 'Entrada' : 'Saída'),
              render: (v: string) => (v === 'REC' ? <Tag color="success" icon={<IconeFa nome="arrow-up" />}> Entrada</Tag> : <Tag color="error" icon={<IconeFa nome="arrow-down" />}> Saída</Tag>),
            },
            { title: 'Nº Doc.', dataIndex: 'numero_documento', responsive: ['sm'] },
            { title: 'Referência', dataIndex: 'referencia', responsive: ['md'], render: (v: string | null) => v ?? '—' },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260, responsive: ['md'] },
            { title: 'Terceiro', key: 'terceiro', responsive: ['md'], render: (_, m) => m.terceiro?.nome?.trim() ?? (m.terceiro_id ? `#${m.terceiro_id}` : '—'), valorImpressao: (m) => m.terceiro?.nome?.trim() ?? '' },
            { title: 'UN', dataIndex: 'unidade_negocio_id', responsive: ['lg'], render: (v: number | null) => (v ? unidades.get(v) ?? `#${v}` : '—') },
            { title: 'CC', dataIndex: 'centro_custo_id', responsive: ['lg'], render: (v: number | null) => (v ? centros.get(v) ?? `#${v}` : '—') },
            { title: 'Nota DEMO', dataIndex: 'nota_demonstracao_id', responsive: ['lg'], render: (v: number | null) => (v ? notasDemo.get(v) ?? `#${v}` : <Typography.Text type="warning">—</Typography.Text>) },
            { title: 'Nota Fluxo', dataIndex: 'nota_fluxo_caixa_id', responsive: ['lg'], render: (v: number | null) => (v ? notasFluxo.get(v) ?? `#${v}` : <Typography.Text type="warning">—</Typography.Text>) },
            {
              title: 'Valor', dataIndex: 'valor', align: 'right', valorImpressao: (m) => `${m.tipo === 'REC' ? '+' : '-'}${formatarKz(m.valor)}`,
              render: (v: string, m) => <strong style={{ color: m.tipo === 'REC' ? '#059669' : '#dc2626', whiteSpace: 'nowrap' }}>{m.tipo === 'REC' ? '+' : '-'}{formatarKz(v)}</strong>,
            },
            { title: 'Débito', dataIndex: 'conta_debito', ocultaPorOmissao: true },
            { title: 'Crédito', dataIndex: 'conta_credito', ocultaPorOmissao: true },
            { title: 'Origem', dataIndex: 'tipo_origem', ocultaPorOmissao: true, render: (v: string | null) => (v ? <Tag>{v}</Tag> : 'Manual') },
            {
              title: 'Acções', key: 'integrado', valorImpressao: (m) => (m.contabilizado ? 'Integrado' : ''),
              render: (_, m) => (m.contabilizado ? <Tag color="blue" icon={<IconeFa nome="check-double" />}> Integrado</Tag> : null),
            },
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

      <ModalFormulario chave="teso.caixa_movimentos" titulo="Registar movimento de caixa" aberto={movimento} aoFechar={() => setMovimento(false)} criacao form={formMov} campos={camposMov}
        nomeRegistos="movimentos de caixa" invalidar={[['teso'], ['contab']]} gravarLinha={(r) => enviar('post', `/tesouraria/caixa/sessoes/${id}/movimentos`, dadosMovimento(r))}
        nota="Um movimento por linha (entrada ou saída desta sessão), com a conta de contrapartida. Linhas deixadas em branco são ignoradas. Cada linha é gravada com as mesmas regras do formulário. Campos com * são obrigatórios."
        okText="Registar" confirmLoading={accao.isPending} onOk={() => formMov.submit()} width={larguraModal(720)}>
        <Form form={formMov} layout="vertical" onFinish={(v) => accao.mutate({ caminho: '/movimentos', dados: dadosMovimento(v as unknown as Record<string, unknown>) })}>
          <Row gutter={12}>
            <Col xs={24} sm={10}>
              <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
                <Segmented block options={TIPOS_MOVIMENTO} />
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
          <SeccaoFormulario titulo="Terceiro e documento">
            <Row gutter={12}>
              <Col xs={24} sm={12}><Form.Item name="terceiro_id" label="Terceiro"><SeletorTerceiro style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={24} sm={6}><Form.Item name="numero_documento" label="N.º documento"><Input maxLength={100} /></Form.Item></Col>
              <Col xs={24} sm={6}><Form.Item name="referencia" label="Referência"><Input maxLength={100} /></Form.Item></Col>
            </Row>
          </SeccaoFormulario>
          <SeccaoFormulario titulo="Classificação (UN, CC e notas)" recolhida>
            <Row gutter={12}>
              <Col xs={24} sm={12}><Form.Item name="centro_custo_id" label="Centro de custo"><SeletorAux tabela="centros-custo" style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={24} sm={12}><Form.Item name="unidade_negocio_id" label="Unidade de negócio"><SeletorUnidade style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={24} sm={12}><Form.Item name="nota_demonstracao_id" label="Nota às demonstrações"><SeletorAux tabela="notas-demonstracao" placeholder="Sem nota" style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={24} sm={12}><Form.Item name="nota_fluxo_caixa_id" label="Nota de fluxo de caixa"><SeletorAux tabela="notas-fluxo-caixa" placeholder="Sem nota" style={{ width: '100%' }} /></Form.Item></Col>
            </Row>
          </SeccaoFormulario>
        </Form>
      </ModalFormulario>

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
