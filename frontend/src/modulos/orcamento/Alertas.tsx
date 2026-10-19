import { Button, Card, Flex, Input, InputNumber, Modal, Progress, Radio, Select, Space, Table, Tabs, Tag, Tooltip, Typography } from 'antd';
import { CheckOutlined, CloseOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { pedidoTabela } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { useAccao } from '@/componentes/Accoes';
import { formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaOrc, Kz, rotuloOrc } from './comum/componentes';
import { corMonitor, MESES } from './comum/regras';
import type { AlertaOrcamental, LinhaMonitor, PedidoExcesso, RefOrcamento, RefRubrica } from './comum/tipos';

import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';
/** Orçamento › Alertas e aprovações (ecrã orc_alertas): pedidos de excesso para decidir, registo de alertas e monitor de consumo. */
export default function Alertas() {
  // contador de pedidos pendentes no separador (pílula âmbar do legado, orcamento_planeamento_ui.js:575-588)
  const pendentes = useQuery({ queryKey: ['orcamento', 'pedidos-excesso', 'PENDENTE'], queryFn: () => obter<PedidoExcesso[]>('/orcamento/pedidos-excesso', { estado: 'PENDENTE' }) });
  const n = pendentes.data?.length ?? 0;
  const [sep, setSep] = useSeparadorUrl(['monitor', 'pedidos', 'registo']);
  return (
    <>
      <CabecalhoPagina titulo="Alertas e aprovações orçamentais" subtitulo="Excessos detectados nos documentos (adjudicações, facturas, pagamentos e lançamentos)" />
      <Tabs
        destroyOnHidden
        activeKey={sep}
        onChange={setSep}
        items={[
          // ordem do legado: consumo vs orçamento, pedidos de aprovação, registo de alertas
          { key: 'monitor', label: <><IconeFa nome="tachometer-alt" /> Consumo vs orçamento</>, children: <Monitor /> },
          { key: 'pedidos', label: <><IconeFa nome="user-check" /> Pedidos de aprovação{n > 0 && <Tag color="gold" style={{ marginLeft: 6 }}>{n}</Tag>}</>, children: <Pedidos /> },
          { key: 'registo', label: <><IconeFa nome="history" /> Registo de alertas</>, children: <Registo /> },
        ]}
      />
    </>
  );
}

/** Rubrica e orçamento por nome: vêm na própria resposta (ADR-064). */
const nomeRubrica = (x: { rubrica?: RefRubrica | null; rubrica_orcamental_id: number | null }) =>
  (x.rubrica ? `${x.rubrica.codigo} ${x.rubrica.nome}` : x.rubrica_orcamental_id ? `#${x.rubrica_orcamental_id}` : '—');
const nomeOrcamento = (x: { orcamento?: RefOrcamento | null; orcamento_anual_id: number | null }) =>
  (x.orcamento ? `${x.orcamento.nome ?? ''} ${x.orcamento.ano} v${x.orcamento.versao}`.trim() : x.orcamento_anual_id ? `#${x.orcamento_anual_id}` : '—');

function Pedidos() {
  const { pode, utilizador } = useSessao();
  const [estado, setEstado] = useState<string | undefined>('PENDENTE');
  const [decidir, setDecidir] = useState<{ pedido: PedidoExcesso; decisao: 'APROVADO' | 'REJEITADO' } | null>(null);
  const q = useQuery({ queryKey: ['orcamento', 'pedidos-excesso', estado], queryFn: () => obter<PedidoExcesso[]>('/orcamento/pedidos-excesso', { estado }) });
  const colPedidos: ColunaApi<PedidoExcesso>[] = [
    { title: 'Pedido', key: 'p', valorImpressao: (p) => `${formatarDataHora(p.pedido_em)} · ${p.pedido_por ?? ''}`, render: (_, p) => <>{formatarDataHora(p.pedido_em)}<br /><Typography.Text type="secondary">{p.pedido_por}</Typography.Text></> },
    { title: 'Documento', key: 'd', valorImpressao: (p) => `${p.origem} · ${p.documento} (${formatarData(p.data_documento)})`, render: (_, p) => <>{p.origem} · {p.documento}<br /><Typography.Text type="secondary">{formatarData(p.data_documento)}</Typography.Text></> },
    // colunas do legado (orcamento_planeamento_ui.js:614-619)
    { title: 'Rubrica / orçamento', key: 'rubrica', valorImpressao: (x) => `${nomeRubrica(x)} · ${nomeOrcamento(x)}`, render: (_, x) => <>{nomeRubrica(x)}<br /><Typography.Text type="secondary" style={{ fontSize: 12 }}>{nomeOrcamento(x)}</Typography.Text></> },
    {
      title: 'Valor', dataIndex: 'valor', align: 'right', valorImpressao: (p) => `${formatarKz(p.valor)} (excesso ${formatarKz(p.valor_excesso)})`,
      render: (v, p) => <><ValorKz valor={v} />{Number(p.valor_excesso) > 0 && <div><Typography.Text type="danger" style={{ fontSize: 12 }}>excesso {formatarKz(p.valor_excesso)}</Typography.Text></div>}</>,
    },
    { title: 'Execução', dataIndex: 'percentagem', align: 'right', render: (v) => <strong>{(v ? `${Number(v).toLocaleString('pt-PT', { maximumFractionDigits: 1 })} %` : '—')}</strong>, valorImpressao: (p) => { const v = p.percentagem; return (v ? `${Number(v).toLocaleString('pt-PT', { maximumFractionDigits: 1 })} %` : '—'); } },
    {
      title: 'Motivo', dataIndex: 'motivo', valorImpressao: (p) => [p.motivo, p.nota_decisao].filter(Boolean).join(' · '),
      render: (v, p) => <>{v ?? '—'}{p.nota_decisao && <div><Typography.Text type="secondary" style={{ fontSize: 12 }}>{p.nota_decisao}</Typography.Text></div>}</>,
    },
    {
      title: 'Estado', key: 'e', valorImpressao: (p) => rotuloOrc(p.estado),
      render: (_, p) => (
        <>
          <EtiquetaOrc valor={p.estado} />{p.autoaprovado && <Tag>No acto</Tag>}
          {p.decidido_por && <div><Typography.Text type="secondary" style={{ fontSize: 12 }}>decidido por {p.decidido_por}{p.decidido_por === p.pedido_por ? ' · pelo próprio' : ''}</Typography.Text></div>}
        </>
      ),
    },
    {
      title: '', key: 'acc', align: 'right',
      render: (_, p) => pode('orc_aprovar_excesso') && p.estado === 'PENDENTE' && (
        p.pedido_por === utilizador?.nome_utilizador
          ? <Typography.Text type="secondary">Pedido seu</Typography.Text>
          : <Space size={4}>
              <Button size="small" icon={<CheckOutlined />} style={{ color: '#15803d', borderColor: '#15803d' }} onClick={() => setDecidir({ pedido: p, decisao: 'APROVADO' })}>Aprovar</Button>
              <Button size="small" icon={<CloseOutlined />} onClick={() => setDecidir({ pedido: p, decisao: 'REJEITADO' })}>Recusar</Button>
            </Space>
      ),
    },
  ];

  return (
    <Card>
      <BarraFiltros accoes={<BotoesExportar desactivado={!q.data?.length} obterPedido={() => pedidoTabela({ titulo: 'Pedidos de excesso orçamental', filtros: estado ? [`Estado: ${estado}`] : undefined, colunas: colPedidos, linhas: q.data ?? [] })} />}>
        <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 180 }}
          options={[{ value: 'PENDENTE', label: 'Pendentes' }, { value: 'APROVADO', label: 'Aprovados' }, { value: 'REJEITADO', label: 'Rejeitados' }, { value: 'UTILIZADO', label: 'Utilizados' }]} />
      </BarraFiltros>
      <TabelaComModos<PedidoExcesso> idVista="pedidos"
        rowKey="id"
        size="middle"
        loading={q.isFetching}
        dataSource={q.data}
        scroll={scrollTabela()}
        columns={colPedidos}
      />
      <ModalDecidir pedido={decidir?.pedido ?? null} decisaoInicial={decidir?.decisao} aoFechar={() => setDecidir(null)} />
    </Card>
  );
}

function ModalDecidir({ pedido, decisaoInicial = 'APROVADO', aoFechar }: { pedido: PedidoExcesso | null; decisaoInicial?: 'APROVADO' | 'REJEITADO'; aoFechar: () => void }) {
  const [decisao, setDecisao] = useState<'APROVADO' | 'REJEITADO'>(decisaoInicial);
  useEffect(() => { if (pedido) setDecisao(decisaoInicial); }, [pedido, decisaoInicial]);
  const [nota, setNota] = useState('');
  const accao = useAccao({ invalidar: [['orcamento']], aoSucesso: () => { setNota(''); aoFechar(); } });
  return (
    <Modal title={`Excesso em ${pedido?.documento ?? ''}`} open={!!pedido} onCancel={aoFechar} okText="Confirmar decisão" cancelText="Cancelar" confirmLoading={accao.isPending}
      okButtonProps={{ danger: decisao === 'REJEITADO' }}
      onOk={() => accao.mutate({ url: `/orcamento/pedidos-excesso/${pedido?.id}/decidir`, dados: { decisao, nota: nota.trim() || null } })}>
      <Typography.Paragraph>Excesso de <strong>{formatarKz(pedido?.valor_excesso, true)}</strong> sobre um orçado de {formatarKz(pedido?.valor_orcado, true)}. Motivo: {pedido?.motivo}</Typography.Paragraph>
      <Radio.Group value={decisao} onChange={(e) => setDecisao(e.target.value)} optionType="button" buttonStyle="solid" style={{ marginBottom: 12 }}
        options={[{ value: 'APROVADO', label: <><CheckOutlined /> Aprovar</> }, { value: 'REJEITADO', label: <><CloseOutlined /> Recusar</> }]} />
      <Input.TextArea rows={3} maxLength={1000} placeholder="Nota da decisão (opcional)" value={nota} onChange={(e) => setNota(e.target.value)} />
      <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>Aprovado, o documento pode ser gravado; o pedido passa a «utilizado» quando o documento for efectivamente gravado.</Typography.Paragraph>
    </Modal>
  );
}

function Monitor() {
  const [ano, setAno] = useState(dayjs().year());
  const [mes, setMes] = useState(dayjs().month() + 1);
  const [estado, setEstado] = useState<string>();
  const q = useQuery({ queryKey: ['orcamento', 'monitor', ano, mes], queryFn: () => obter<LinhaMonitor[]>('/orcamento/monitor', { ano, mes }) });
  const linhas = (q.data ?? []).filter((l) => !estado || l.estado === estado);
  // indicadores do legado (orcamento_planeamento_ui.js:605): acima do limite, em aviso e pedidos pendentes
  const pendentes = useQuery({ queryKey: ['orcamento', 'pedidos-excesso', 'PENDENTE'], queryFn: () => obter<PedidoExcesso[]>('/orcamento/pedidos-excesso', { estado: 'PENDENTE' }) });
  const acima = (q.data ?? []).filter((l) => l.estado === 'EXCEDIDO').length;
  const emAviso = (q.data ?? []).filter((l) => l.estado === 'AVISO').length;
  const colMonitor: ColunaApi<LinhaMonitor>[] = [
    // ordem do legado: Rubrica, Orçamento, Controlo…
    { title: 'Rubrica', dataIndex: 'rubrica' },
    { title: 'Orçamento', dataIndex: 'orcamento' },
    { title: 'Controlo', dataIndex: 'modo', render: (v) => <EtiquetaOrc valor={v} /> },
    { title: 'Orçado', dataIndex: 'orcado', align: 'right', render: (v) => <Kz valor={v} /> },
    { title: 'Compromissos', dataIndex: 'compromissos', align: 'right', render: (v) => <Kz valor={v} /> },
    { title: 'Consumido', dataIndex: 'consumido', align: 'right', render: (v) => <Kz valor={v} forte /> },
    { title: 'Disponível', dataIndex: 'disponivel', align: 'right', render: (v) => <Kz valor={v} /> },
    { title: 'Consumo', dataIndex: 'percentagem', width: 180, valorImpressao: (l) => (l.percentagem === null ? '—' : `${Number(l.percentagem).toLocaleString('pt-PT', { maximumFractionDigits: 1 })}%`), render: (v, l) => (v === null ? '—' : <Progress percent={Math.min(100, Math.round(v))} size="small" strokeColor={corMonitor(l.estado) === 'red' ? '#dc2626' : corMonitor(l.estado) === 'orange' ? '#d97706' : '#15803d'} format={() => `${Number(v).toLocaleString('pt-PT', { maximumFractionDigits: 1 })}%`} />) },
    // decisão 23: no modo NENHUM (sem controlo) o EXCEDIDO acima de 100 % é só informativo
    { title: 'Situação', dataIndex: 'estado', valorImpressao: (l) => `${l.estado}${l.modo === 'NENHUM' && l.estado === 'EXCEDIDO' ? ' (informativo)' : ''}`,
      render: (v, l) => (l.modo === 'NENHUM' && v === 'EXCEDIDO' ? <Tooltip title="Rubrica sem controlo: o excesso não bloqueia nem pede aprovação (só informativo)."><span><EtiquetaOrc valor={v} /></span></Tooltip> : <EtiquetaOrc valor={v} />) },
  ];

  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
        <Space wrap>
          <InputNumber prefix="Ano" min={2000} max={2100} value={ano} onChange={(v) => v && setAno(v)} style={{ width: 150 }} />
          <Select value={mes} onChange={setMes} style={{ width: 140 }} options={MESES.map((m, i) => ({ value: i + 1, label: `Até ${m}` }))} />
          <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 160 }}
            options={['EXCEDIDO', 'AVISO', 'OK', 'SEM_DOTACAO'].map((e) => ({ value: e, label: <EtiquetaOrc valor={e} /> }))} />
        </Space>
        <Space wrap>
        <BotoesExportar desactivado={!linhas.length} obterPedido={() => pedidoTabela({ titulo: 'Monitor orçamental', periodo: `Janeiro a ${MESES[mes - 1]} de ${ano}`, filtros: estado ? [`Estado: ${estado}`] : undefined, colunas: colMonitor, linhas })} />
        <BotaoCsv nome={`monitor-orcamental-${ano}-${mes}`} linhas={linhas} colunas={[
          { titulo: 'Orçamento', valor: (l) => l.orcamento }, { titulo: 'Rubrica', valor: (l) => l.rubrica }, { titulo: 'Modo', valor: (l) => l.modo },
          { titulo: 'Orçado', valor: (l) => l.orcado, numerico: true }, { titulo: 'Compromissos', valor: (l) => l.compromissos, numerico: true },
          { titulo: 'Consumido', valor: (l) => l.consumido, numerico: true }, { titulo: 'Disponível', valor: (l) => l.disponivel, numerico: true },
          { titulo: '%', valor: (l) => l.percentagem, numerico: true }, { titulo: 'Estado', valor: (l) => l.estado },
        ]} />
        </Space>
      </Flex>
      <div style={{ marginBottom: 12 }}>
        <GrelhaKpi larguraMinima={200}>
          <CartaoKpi barra="nenhuma" rotulo="Rubricas acima do limite" valor={acima} corValor={acima > 0 ? '#b91c1c' : undefined} />
          <CartaoKpi barra="nenhuma" rotulo="Rubricas em aviso" valor={emAviso} corValor={emAviso > 0 ? '#b45309' : undefined} />
          <CartaoKpi barra="nenhuma" rotulo="Pedidos de aprovação pendentes" valor={pendentes.data?.length ?? '—'} />
        </GrelhaKpi>
      </div>
      <Table<LinhaMonitor>
        rowKey={(l) => `${l.orcamento_anual_id}-${l.rubrica_orcamental_id}`}
        size="small"
        loading={q.isFetching}
        dataSource={linhas}
        scroll={scrollTabela()}
        pagination={{ defaultPageSize: 50 }}
        columns={colMonitor}
      />
      <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>Orçamentos aprovados do ano; consumo = realizado + compromissos (encomendas por facturar, facturas por contabilizar e pagamentos pendentes).</Typography.Paragraph>
    </Card>
  );
}

/** Acções do registo de alertas por extenso (mapa ACC do legado). */
const ACCOES_ALERTA: Record<string, string> = { CONTINUOU: 'Continuou após aviso', CANCELADO: 'Cancelou', BLOQUEADO: 'Bloqueado', PEDIU_APROVACAO: 'Pediu aprovação', APROVOU_NO_ACTO: 'Aprovou no acto' };
export const textoAccao = (a: string | null | undefined): string => (a ? ACCOES_ALERTA[a] ?? a : '—');

function Registo() {
  const q = useQuery({ queryKey: ['orcamento', 'alertas'], queryFn: () => obter<AlertaOrcamental[]>('/orcamento/alertas') });
  const colRegisto: ColunaApi<AlertaOrcamental>[] = [
    { title: 'Quando', dataIndex: 'em', render: formatarDataHora },
    { title: 'Utilizador', dataIndex: 'por' },
    // colunas do legado (orcamento_planeamento_ui.js:633-634): o orçamento só no título da rubrica
    { title: 'Documento', key: 'd', render: (_, a) => `${a.origem ?? ''} ${a.documento ?? ''}`.trim() },
    { title: 'Rubrica', key: 'rubrica', render: (_, x) => <span title={nomeOrcamento(x)}>{nomeRubrica(x)}</span>, valorImpressao: (x) => nomeRubrica(x) },
    { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
    { title: 'Execução', dataIndex: 'percentagem', align: 'right', render: (v) => (v ? `${Number(v).toLocaleString('pt-PT', { maximumFractionDigits: 1 })} %` : '—') },
    { title: 'Situação', dataIndex: 'estado', render: (v) => <EtiquetaOrc valor={v} />, valorImpressao: (a) => rotuloOrc(a.estado) },
    { title: 'Acção', dataIndex: 'acao', render: (v) => textoAccao(v), valorImpressao: (a) => textoAccao(a.acao) },
  ];

  return (
    <Card>
      <BarraFiltros accoes={<BotoesExportar desactivado={!q.data?.length} obterPedido={() => pedidoTabela({ titulo: 'Registo de alertas orçamentais', colunas: colRegisto, linhas: q.data ?? [] })} />}>
        <Typography.Text type="secondary">Últimas 300 ocorrências, incluindo as tentativas bloqueadas.</Typography.Text>
      </BarraFiltros>
      <Table<AlertaOrcamental>
        rowKey="id"
        size="small"
        loading={q.isFetching}
        dataSource={q.data}
        scroll={scrollTabela()}
        pagination={{ defaultPageSize: 50 }}
        columns={colRegisto}
      />
    </Card>
  );
}
