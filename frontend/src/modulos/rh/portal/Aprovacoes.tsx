import { Alert, Button, Card, Descriptions, Form, Input, Modal, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { atrasosDasMinhasUnidades, CHAVE_PORTAL_APROVACOES, decidirImpedimentoPortal, entregasParaValidar, impedimentosParaDecidir, validarEntregaPortal } from '@/modulos/estrutura/tarefas/api';
import { LigacaoAnexo, ModalMotivoCurto, TagImpedimento, useMutacao } from '@/modulos/estrutura/tarefas/comum';
import { colunasImpedimentos } from '@/modulos/estrutura/tarefas/Impedimentos';
import { fq, totalMultas } from '@/modulos/estrutura/tarefas/regras';
import { nomeDe, type Atraso, type DecisaoValidacao, type Execucao, type Impedimento } from '@/modulos/estrutura/tarefas/tipos';
import { TIPOS_PEDIDO, type PedidoPortal, type TipoPedido } from '../api';
import { EstadoTag } from '../comum/componentes';
import { useAccaoRh, useAvisarErro } from '../comum/consultas';
import { EtapasPedido, resumoPedido } from '../comum/Pedidos';
import { analisePedido, comprasParaMim, decidirCompra, equipaDirecta, type CompraParaMim, type MembroEquipa } from './api';

/**
 * Portal › Aprovações por secções (portal_ui.js desenharAprovacoes, 1450-1528): pedidos de RH com «Analisar» antes de
 * decidir, pedidos de compra, entregas para validar, impedimentos, entregáveis em atraso (com exposição a multas) e a
 * equipa directa. Cada secção carrega por si; as que o servidor não serve a este utilizador ficam escondidas.
 */
export function Aprovacoes() {
  return (
    <>
      <PedidosParaMim />
      <ComprasParaMim />
      <EntregasParaMim />
      <ImpedimentosParaMim />
      <AtrasosDasMinhasUnidades />
      <EquipaDirecta />
    </>
  );
}

/** Pedidos à espera do utilizador (chefia directa; o RH decide em «Pedidos do Portal»). */
function PedidosParaMim() {
  const q = useQuery({ queryKey: ['rh', 'portal', 'aprovacoes'], queryFn: () => obter<PedidoPortal[]>('/rh/portal/aprovacoes') });
  useAvisarErro(q.error);
  const [decidir, setDecidir] = useState<PedidoPortal | null>(null);
  const [analisar, setAnalisar] = useState<PedidoPortal | null>(null);
  const [form] = Form.useForm<{ decisao: 'APROVADO' | 'RECUSADO'; nota?: string }>();
  const decisao = Form.useWatch('decisao', form);
  const accao = useAccaoRh(() => { setDecidir(null); setAnalisar(null); });
  const lista = Array.isArray(q.data) ? q.data : [];
  const abrirDecidir = (p: PedidoPortal, d: 'APROVADO' | 'RECUSADO' = 'APROVADO') => { form.resetFields(); form.setFieldsValue({ decisao: d }); setDecidir(p); };
  return (
    <Card title={`Pedidos à sua espera (${lista.length})`} style={{ marginBottom: 16 }}>
      <Table<PedidoPortal> rowKey="id" size="small" loading={q.isFetching} dataSource={lista} pagination={false} scroll={scrollTabela()}
        locale={{ emptyText: 'Não há pedidos à sua espera.' }}
        columns={[
          { title: 'N.º', dataIndex: 'id', render: (v: number) => `#${v}` },
          { title: 'Pedido por', dataIndex: 'criado_por' },
          { title: 'Tipo', dataIndex: 'tipo', render: (t: TipoPedido) => TIPOS_PEDIDO[t] },
          { title: 'Resumo', render: (_, p) => resumoPedido(p) },
          { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
          {
            title: '', key: 'a', render: (_, p) => p.tipo !== 'DOCUMENTO' && (
              <Space size={4} wrap>
                <Button size="small" icon={<IconeFa nome="search" />} title="Ver tudo antes de decidir: saldo, quem mais está fora, pedidos anteriores" onClick={() => setAnalisar(p)}>Analisar</Button>
                <Button size="small" type="primary" onClick={() => abrirDecidir(p)}>Decidir</Button>
              </Space>
            ),
          },
        ]}
        expandable={{ expandedRowRender: (p) => <EtapasPedido pedido={p} /> }} />
      <Modal title={`Decidir pedido #${decidir?.id ?? ''}`} open={decidir !== null} onCancel={() => setDecidir(null)} okText="Confirmar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        {decidir && <Typography.Paragraph>{TIPOS_PEDIDO[decidir.tipo]} — {resumoPedido(decidir)}</Typography.Paragraph>}
        <Form form={form} layout="vertical" onFinish={(v) => decidir && accao.mutate({ metodo: 'post', url: `/rh/portal/pedidos/${decidir.id}/decidir`, dados: v })}>
          <Form.Item name="decisao" label="Decisão"><Select options={[{ value: 'APROVADO', label: 'Aprovar' }, { value: 'RECUSADO', label: 'Recusar' }]} /></Form.Item>
          <Form.Item name="nota" label="Nota" rules={[{ required: decisao === 'RECUSADO', min: 3, message: 'Indique o motivo da recusa.' }]}><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
        </Form>
      </Modal>
      <AnalisePedido pedido={analisar} aoFechar={() => setAnalisar(null)} aoDecidir={(d) => { if (analisar) { const p = analisar; setAnalisar(null); abrirDecidir(p, d); } }} />
    </Card>
  );
}

/** Painel de análise antes de decidir um pedido de RH (portal_ui.js analisePedidoRH, 1303-1387; GET pedidos/{id}/analise). */
function AnalisePedido({ pedido: p, aoFechar, aoDecidir }: { pedido: PedidoPortal | null; aoFechar: () => void; aoDecidir: (d: 'APROVADO' | 'RECUSADO') => void }) {
  const q = useQuery({ queryKey: ['rh', 'portal', 'pedidos', p?.id, 'analise'], queryFn: () => analisePedido(p!.id), enabled: !!p, retry: false });
  const a = q.data;
  const c = a?.colaborador;
  return (
    <Modal open={!!p} title={`Análise — ${p ? TIPOS_PEDIDO[p.tipo] : ''}`} onCancel={aoFechar} width={larguraModal(860)} destroyOnHidden
      footer={<Space wrap><Button onClick={aoFechar}>Fechar</Button><Button danger onClick={() => aoDecidir('RECUSADO')}>Recusar</Button><Button type="primary" icon={<IconeFa nome="check" />} onClick={() => aoDecidir('APROVADO')}>Aprovar</Button></Space>}>
      {p && (
        <Descriptions size="small" column={{ xs: 1, md: 2 }} title="O pedido" style={{ marginBottom: 12 }}>
          <Descriptions.Item label="Colaborador"><b>{c?.nome_completo ?? p.criado_por ?? '—'}</b></Descriptions.Item>
          <Descriptions.Item label="Pedido em">{formatarData(p.criado_em)}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={p.estado} /></Descriptions.Item>
          <Descriptions.Item label="Detalhe">{resumoPedido(p)}</Descriptions.Item>
          {c && <Descriptions.Item label="Unidade">{c.unidade ?? '—'}</Descriptions.Item>}
          {c && <Descriptions.Item label="Admissão">{formatarData(c.data_admissao)}</Descriptions.Item>}
          {(a?.anexos ?? []).map((x) => <Descriptions.Item key={x.url} label="Anexo"><LigacaoAnexo url={x.url} rotulo={x.rotulo} /></Descriptions.Item>)}
        </Descriptions>
      )}
      {q.isLoading && <Spin />}
      {q.isError && <Alert type="warning" showIcon title="Não foi possível obter a análise do pedido; pode decidir na mesma." />}
      {a && (
        <>
          {a.saldo_ferias && (
            <Descriptions size="small" column={{ xs: 2, md: 5 }} title={`Saldo de férias ${a.saldo_ferias.ano}`} style={{ marginBottom: 12 }}>
              <Descriptions.Item label="Direito">{a.saldo_ferias.direito} dia(s)</Descriptions.Item>
              <Descriptions.Item label="Já marcados">{a.saldo_ferias.marcados} dia(s)</Descriptions.Item>
              <Descriptions.Item label="Já gozados">{a.saldo_ferias.gozados} dia(s)</Descriptions.Item>
              <Descriptions.Item label="Saldo com este pedido">{a.saldo_ferias.saldo} dia(s)</Descriptions.Item>
              <Descriptions.Item label="Este pedido">{a.saldo_ferias.dias_pedidos} dia(s)</Descriptions.Item>
            </Descriptions>
          )}
          {a.equipa_ausente.length > 0 && (
            <div style={{ marginBottom: 12 }}>
              <Typography.Text strong>Quem mais está fora no mesmo período</Typography.Text>
              <div style={{ fontSize: '0.85rem' }}>{a.equipa_ausente.map((s) => <div key={`${s.colaborador_id}-${s.tipo}-${s.de}`}>{s.nome} — {formatarData(s.de)} a {formatarData(s.ate)} ({s.tipo === 'AUSENCIA' ? 'ausência' : 'férias'}, {s.estado.toLowerCase()})</div>)}</div>
            </div>
          )}
          {a.avisos.map((x) => <Alert key={x} type="warning" showIcon icon={<IconeFa nome="exclamation-triangle" />} style={{ marginBottom: 8 }} title={x} />)}
          {p && <EtapasPedido pedido={p} />}
          {a.ultimos_pedidos.length > 0 && (
            <div style={{ marginTop: 12 }}>
              <Typography.Text strong>Pedidos anteriores do mesmo tipo ({a.ultimos_pedidos.length})</Typography.Text>
              <Table rowKey="id" size="small" pagination={false} dataSource={a.ultimos_pedidos} style={{ marginTop: 4 }}
                columns={[
                  { title: 'Data', dataIndex: 'criado_em', render: (v: string | null) => formatarData(v) },
                  { title: 'Detalhe', key: 'd', render: (_, x) => (p ? resumoPedido({ tipo: p.tipo, dados: x.dados }) : '') },
                  { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
                  { title: 'Decisão', dataIndex: 'decisao', render: (v: string[]) => (v?.length ? v.join('; ') : '—') },
                ]} />
            </div>
          )}
        </>
      )}
    </Modal>
  );
}

/** Pedidos de compra à minha espera (compras/aprovacoes) com a decisão pelo portal (compras/{pedido}/decidir). */
function ComprasParaMim() {
  const q = useQuery({ queryKey: [...CHAVE_PORTAL_APROVACOES, 'compras'], queryFn: comprasParaMim, retry: false });
  const [recusar, setRecusar] = useState<CompraParaMim | null>(null);
  const accao = useMutacao((p: { id: number; decisao: 'APROVAR' | 'RECUSAR'; nota?: string }) => decidirCompra(p.id, { decisao: p.decisao, nota: p.nota }), { invalidar: [['rh', 'portal'], ['compras']], aoSucesso: () => setRecusar(null) });
  if (q.isError) return null;
  const lista = Array.isArray(q.data) ? q.data : [];
  return (
    <Card title={`Pedidos de compra à sua espera (${lista.length})`} style={{ marginBottom: 16 }}>
      <Table<CompraParaMim> rowKey="id" size="small" loading={q.isFetching} dataSource={lista} pagination={false} scroll={scrollTabela()}
        locale={{ emptyText: 'Não há pedidos de compra à sua espera.' }}
        columns={[
          { title: 'N.º', key: 'n', render: (_, c) => <b>{c.numero_pedido ?? `#${c.id}`}</b> },
          { title: 'Requisitante', dataIndex: 'nome_requerente', render: (v: string | null) => v ?? '—' },
          { title: 'Data', dataIndex: 'data', render: (v: string | null) => formatarData(v) },
          { title: 'Valor estimado', dataIndex: 'valor_estimado', align: 'right', render: (v) => (v != null ? formatarKz(v) : '—') },
          { title: 'Nível a decidir', key: 'nivel', render: (_, c) => <>{c.nivel_atual ?? '—'} <Typography.Text type="secondary">({c.niveis_aprovados} de {c.niveis_total} aprovados)</Typography.Text></> },
          {
            title: '', key: 'a', render: (_, c) => (
              <Space size={4}>
                <Button size="small" type="primary" icon={<IconeFa nome="check" />} loading={accao.isPending} onClick={() => accao.mutate({ id: c.id, decisao: 'APROVAR' })}>Aprovar</Button>
                <Button size="small" danger onClick={() => setRecusar(c)}>Recusar</Button>
              </Space>
            ),
          },
        ]} />
      <ModalMotivoCurto aberto={recusar !== null} titulo={`Recusar o pedido de compra ${recusar?.numero_pedido ?? ''}`} textoOk="Recusar" perigo carregando={accao.isPending}
        aoFechar={() => setRecusar(null)} aoConfirmar={(nota) => recusar && accao.mutate({ id: recusar.id, decisao: 'RECUSAR', nota })} />
    </Card>
  );
}

/** Entregas à sua espera (portal_ui.js:1392-1445): validar, devolver ou recusar as entregas pendentes das unidades por que respondo. */
function EntregasParaMim() {
  const q = useQuery({ queryKey: [...CHAVE_PORTAL_APROVACOES, 'entregas'], queryFn: entregasParaValidar, retry: false });
  const [motivo, setMotivo] = useState<{ execucao: Execucao; decisao: Exclude<DecisaoValidacao, 'VALIDADA'> } | null>(null);
  const validar = useMutacao((p: { id: number; decisao: DecisaoValidacao; motivo?: string }) => validarEntregaPortal(p.id, { decisao: p.decisao, motivo: p.motivo ?? null }), { aoSucesso: () => setMotivo(null) });
  if (q.isError) return null;
  const lista = Array.isArray(q.data) ? q.data : [];
  const medida = (x: Execucao) => x.unidade_medida ?? (typeof x.entregavel === 'object' && x.entregavel ? x.entregavel.unidade ?? '' : '');
  const colunas: ColumnsType<Execucao> = [
    { title: 'Data', dataIndex: 'data', render: (v: string) => <span style={{ whiteSpace: 'nowrap' }}>{formatarData(v)}</span> },
    { title: 'Quem', key: 'quem', render: (_, x) => x.colaborador_nome ?? `#${x.colaborador_id}` },
    { title: 'Entregável', key: 'e', render: (_, x) => <><b>{nomeDe(x.entregavel) === '—' ? `#${x.entregavel_id}` : nomeDe(x.entregavel)}</b><div style={{ fontSize: '0.74rem', color: '#64748b' }}>{[x.unidade_nome, nomeDe(x.tarefa) !== '—' ? nomeDe(x.tarefa) : null].filter(Boolean).join(' · ')}</div></> },
    { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (v, x) => <span style={{ whiteSpace: 'nowrap' }}><b>{fq(v)}</b> {medida(x)}</span> },
    { title: 'Evidência', key: 'ev', render: (_, x) => <LigacaoAnexo url={x.url_evidencia} /> },
    { title: 'Observações', dataIndex: 'observacoes', responsive: ['md'], render: (v: string | null) => <span style={{ fontSize: '0.8rem', color: '#64748b' }}>{v ?? ''}</span> },
    {
      title: '', key: 'a', render: (_, x) => x.pode_validar === false ? <span title={x.motivo_nao_valida || 'Não pode validar esta entrega'} style={{ color: '#cbd5e1' }}><IconeFa nome="lock" /></span> : (
        <Space size={4} wrap>
          <Button size="small" type="primary" style={{ background: '#15803d' }} icon={<IconeFa nome="check" />} title="Validar: passa a contar para a produtividade" onClick={() => validar.mutate({ id: x.id, decisao: 'VALIDADA' })}>Validar</Button>
          <Button size="small" style={{ color: '#9a3412' }} icon={<IconeFa nome="undo" />} title="Volta a quem entregou, com o que tem de melhorar" onClick={() => setMotivo({ execucao: x, decisao: 'DEVOLVIDA' })}>Devolver</Button>
          <Button size="small" danger icon={<IconeFa nome="ban" />} title="Não conta, e não volta" onClick={() => setMotivo({ execucao: x, decisao: 'RECUSADA' })}>Recusar</Button>
        </Space>
      ),
    },
  ];
  return (
    <Card title={`Entregas à sua espera (${lista.length})`} style={{ marginBottom: 16 }}>
      <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>Entregas pendentes dos entregáveis das unidades por que responde. Só entregas validadas vão para a produtividade; quem entregou nunca valida a própria entrega.</Typography.Paragraph>
      <Table<Execucao> rowKey="id" size="small" loading={q.isFetching} dataSource={lista} columns={colunas} pagination={lista.length > 20 ? { pageSize: 20 } : false} scroll={scrollTabela()}
        locale={{ emptyText: 'Não há entregas à espera do seu visto.' }} />
      <ModalMotivoCurto aberto={motivo !== null} perigo={motivo?.decisao === 'RECUSADA'} carregando={validar.isPending}
        titulo={motivo?.decisao === 'DEVOLVIDA' ? 'Devolver para correcção' : 'Recusar a entrega'}
        mensagem={motivo?.decisao === 'DEVOLVIDA' ? 'O que tem de ser melhorado ou corrigido? É isto que quem entregou vai ler.' : 'Porque é que esta entrega não é aceite? Uma recusa não volta a contar.'}
        rotulo={motivo?.decisao === 'DEVOLVIDA' ? 'O que corrigir' : 'Motivo'} textoOk={motivo?.decisao === 'DEVOLVIDA' ? 'Devolver' : 'Recusar'}
        aoFechar={() => setMotivo(null)} aoConfirmar={(m) => motivo && validar.mutate({ id: motivo.execucao.id, decisao: motivo.decisao, motivo: m })} />
    </Card>
  );
}

/** Impedimentos à sua espera (portal_ui.js:1469-1501): aceitar (justifica o período) ou recusar com nota. */
function ImpedimentosParaMim() {
  const q = useQuery({ queryKey: [...CHAVE_PORTAL_APROVACOES, 'impedimentos'], queryFn: impedimentosParaDecidir, retry: false });
  const [recusar, setRecusar] = useState<Impedimento | null>(null);
  const decidir = useMutacao((p: { id: number; estado: 'ACEITE' | 'RECUSADO'; nota?: string }) => decidirImpedimentoPortal(p.id, { estado: p.estado, nota: p.nota ?? null }), { aoSucesso: () => setRecusar(null) });
  if (q.isError) return null;
  const lista = Array.isArray(q.data) ? q.data : [];
  const colunas = colunasImpedimentos((i) => i.colaborador_nome ?? `#${i.colaborador_id}`, (i, estado) => (estado === 'ACEITE' ? decidir.mutate({ id: i.id, estado }) : setRecusar(i)));
  colunas.splice(2, 0, { title: 'Entregável', key: 'e', render: (_, i) => <><b>{nomeDe(i.entregavel) === '—' ? `#${i.entregavel_id}` : nomeDe(i.entregavel)}</b>{i.unidade_nome && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>{i.unidade_nome}</div>}</> });
  return (
    <Card title={`Impedimentos à sua espera (${lista.length})`} style={{ marginBottom: 16 }}>
      <Table<Impedimento> rowKey="id" size="small" loading={q.isFetching} dataSource={lista} columns={colunas} pagination={false} scroll={scrollTabela()}
        locale={{ emptyText: 'Ninguém reportou falta de condições para executar.' }} />
      <ModalMotivoCurto aberto={recusar !== null} titulo="Recusar o impedimento" rotulo="Nota da decisão" textoOk="Recusar" perigo carregando={decidir.isPending}
        mensagem="Porque é que o impedimento não é aceite? O período continua por cumprir." aoFechar={() => setRecusar(null)}
        aoConfirmar={(nota) => recusar && decidir.mutate({ id: recusar.id, estado: 'RECUSADO', nota })} />
    </Card>
  );
}

/** Entregáveis em atraso nas unidades por que respondo (portal_ui.js:1504-1528), com a exposição a multas. */
function AtrasosDasMinhasUnidades() {
  const q = useQuery({ queryKey: [...CHAVE_PORTAL_APROVACOES, 'atrasos'], queryFn: atrasosDasMinhasUnidades, retry: false });
  if (q.isError) return null;
  const lista = Array.isArray(q.data) ? q.data : [];
  const multa = totalMultas(lista);
  const colunas: ColumnsType<Atraso> = [
    { title: 'Entregável', key: 'e', render: (_, a) => <><b>{a.entregavel.nome}</b><div style={{ fontSize: '0.74rem', color: '#64748b' }}>{a.tarefa.nome}{a.entregavel.prazo_texto ? ` · ${a.entregavel.prazo_texto}` : ''}</div></> },
    { title: 'Unidade', key: 'u', render: (_, a) => a.unidade?.nome ?? '—' },
    { title: 'Quem executa', key: 'd', render: (_, a) => a.dono?.nome ?? '— da unidade —' },
    { title: 'Prazo', key: 'p', render: (_, a) => <span style={{ whiteSpace: 'nowrap', color: '#b91c1c' }}><b>{formatarData(a.limite)}</b><div style={{ fontSize: '0.74rem' }}>há {a.dias_atraso} dia(s)</div></span> },
    { title: 'Falta', key: 'f', render: (_, a) => <span style={{ whiteSpace: 'nowrap' }}>{a.falta != null ? `${fq(a.falta)} ${a.entregavel.unidade}` : 'por fazer'}<div style={{ fontSize: '0.74rem', color: '#64748b' }}>feito {fq(a.realizado)}{a.meta != null ? ` de ${fq(a.meta)}` : ''}</div></span> },
    { title: 'Risco', key: 'r', render: (_, a) => <span style={{ fontSize: '0.8rem' }}>{a.multa_valor ? <b style={{ color: '#b91c1c' }}>{formatarKz(a.multa_valor, true)}</b> : null}{a.multa_descricao && <div style={{ color: '#64748b', fontSize: '0.74rem' }}>{a.multa_descricao}</div>}{!a.multa_valor && !a.multa_descricao ? '—' : null}</span> },
    { title: 'Justificação', key: 'j', render: (_, a) => (a.impedimento ? <><TagImpedimento estado={a.impedimento.estado} /><div style={{ color: '#64748b', fontSize: '0.74rem' }}>{a.impedimento.motivo}</div></> : <span style={{ color: '#94a3b8' }}>sem justificação</span>) },
  ];
  return (
    <Card title={<span style={lista.length ? { color: '#b91c1c' } : undefined}>Entregáveis em atraso nas suas unidades ({lista.length})</span>} style={{ marginBottom: 16 }}>
      {lista.length > 0 && <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>Prazo ultrapassado sem estar cumprido.{multa ? <> Exposição a multas: <b style={{ color: '#b91c1c' }}>{formatarKz(multa, true)}</b>.</> : null}</Typography.Paragraph>}
      <Table<Atraso> rowKey={(a) => `${a.entregavel.id}-${a.periodo_de}`} size="small" loading={q.isFetching} dataSource={lista} columns={colunas} pagination={false} scroll={scrollTabela()}
        locale={{ emptyText: 'Nada em atraso. Os prazos das suas unidades estão cumpridos.' }} />
    </Card>
  );
}

/** A minha equipa directa (GET equipa): unidade, cargo, férias do ano e último pedido. */
function EquipaDirecta() {
  const q = useQuery({ queryKey: ['rh', 'portal', 'equipa'], queryFn: equipaDirecta, retry: false });
  if (q.isError) return null;
  const lista = Array.isArray(q.data) ? q.data : [];
  if (!lista.length) return null;
  return (
    <Card title={`A minha equipa directa (${lista.length})`} style={{ marginBottom: 16 }}>
      <Table<MembroEquipa> rowKey="id" size="small" loading={q.isFetching} dataSource={lista} pagination={false} scroll={scrollTabela()}
        columns={[
          { title: 'Colaborador', dataIndex: 'nome_completo', render: (v: string) => <b>{v}</b> },
          { title: 'Unidade', dataIndex: 'unidade', render: (v: string | null) => v ?? '—' },
          { title: 'Cargo', dataIndex: 'cargo', render: (v: string | null) => v ?? '—' },
          { title: `Férias ${dayjs().year()}`, key: 'f', align: 'right', render: (_, m) => (m.ferias ? `${m.ferias.marcados} de ${m.ferias.direito ?? '—'} dia(s)` : '—') },
          { title: 'Último pedido', key: 'u', render: (_, m) => (m.ultimo_pedido ? <>{TIPOS_PEDIDO[m.ultimo_pedido.tipo] ?? m.ultimo_pedido.tipo} · <Tag>{m.ultimo_pedido.estado}</Tag> {formatarData(m.ultimo_pedido.criado_em)}</> : '—') },
        ]} />
    </Card>
  );
}
