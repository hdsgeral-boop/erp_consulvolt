import { Alert, Button, Card, Checkbox, DatePicker, Descriptions, Flex, Input, InputNumber, Modal, Space, Table, Tag, theme, Tooltip, Typography } from 'antd';
import { CloudUploadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { NavActivos } from './comum/NavActivos';
import { BotoesExportar, CSS_SIMULACAO_COMUM, marcaSimulacao, tabelaHtml, tabelaLancamentoHtml, type PedidoImpressao } from '@/componentes/impressao';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { IconeFa } from '@/componentes/fluxos';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv, IndicadorEquilibrio, ValorKz } from '@/modulos/contab/comum/Componentes';
import { somar } from '@/utilitarios/decimal';
import { pedidoTabela } from './comum/impressao';
import { useAccao } from '@/componentes/Accoes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaActivos } from './comum/componentes';
import { accoesPeriodo, codigoPeriodo, ordenarPeriodos, rotuloPeriodo } from './comum/regras';
import type { LinhaPeriodo, MesPendente, PeriodoAmortizacoes, PeriodoRegistado, PreVisualizacao, Verificacao } from './comum/tipos';
import { COLUNAS_DESCRICOES, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { acumuladaLinha, estadoLegado, linhaComAccoes, liquidoLinha, maisRecentesPrimeiro, quotaManual, seleccaoInicial, textoCalcular, totalMeses } from './fiscal/amortizacoes';

type Painel = 'reabrir' | 'calculo' | 'integracao' | null;

/**
 * Activos › Amortizações (ecrã activos_amortizacoes), como no legado (renderAmortizationView, js/ui_assets.js:1750-1925):
 * barra com «Mês de Referência», «Estado» e as acções (Calcular/Recalcular Período, Integrar na Contabilidade, Reabrir
 * Período, Reabrir em Massa, Cálculo em Massa, Integração em Massa), painel das ferramentas em massa e o cartão «Mapa de
 * Amortizações - Período MM-AAAA» com as acções por linha (cota manual e integração individual dos períodos em falta).
 * Toda a lógica (cálculo, integração no diário AM, estorno na reabertura) é a do servidor (ServicoAmortizacoes).
 */
export default function Amortizacoes() {
  const { pode } = useSessao();
  const [mes, setMes] = useState(dayjs()); // mês corrente, como o legado (Mapa de Amortizações - Período MM-AAAA do mês actual)
  const [filtro, setFiltro] = useState('');
  const [painel, setPainel] = useState<Painel>(null);
  const [previsao, setPrevisao] = useState(false);
  const [reabrir, setReabrir] = useState<string[]>([]);
  const [cota, setCota] = useState<{ linha: LinhaPeriodo; valor: number | null } | null>(null);
  const [verificacao, setVerificacao] = useState(false);
  const periodo = codigoPeriodo(mes.year(), mes.month() + 1);
  const q = useQuery({ queryKey: ['activos', 'amortizacoes', 'periodo', periodo], queryFn: () => obter<PeriodoAmortizacoes>('/ativos/amortizacoes', { periodo }) });
  const calcular = useAccao({ invalidar: [['activos']] });
  const quota = useAccao({ invalidar: [['activos']], aoSucesso: () => setCota(null) });
  const individual = useAccao({ invalidar: [['activos']] });
  const p = q.data;
  const acc = accoesPeriodo(p?.estado ?? 'ABERTO', pode);
  const estado = estadoLegado(p);
  const integrado = estado.chave === 'INTEGRADO';
  const temRascunhos = (p?.contagens.RASCUNHO ?? 0) > 0;
  const t = filtro.trim().toLowerCase();
  const linhas = (p?.linhas ?? []).filter((l) => !t || `${l.codigo} ${l.descricao} ${l.categoria}`.toLowerCase().includes(t));
  const podeCota = pode('activos_amort_calcular', 'activos_mapa_editar');
  const podeIntegrar = pode('activos_amort_integrar');

  const integrarIndividual = (l: LinhaPeriodo) => Modal.confirm({
    title: `Integrar ${l.codigo ?? ''} até ${rotuloPeriodo(periodo)}?`,
    content: 'Integra individualmente todos os períodos em falta até à data: calcula os meses em falta e gera um lançamento equilibrado por período no diário AM.',
    okText: 'Integrar', cancelText: 'Cancelar',
    onOk: () => individual.mutateAsync({ url: `/ativos/bens/${l.ativo_imobilizado_id}/amortizacoes/integrar`, dados: { ate: periodo } }),
  });

  const colunas: ColunaApi<LinhaPeriodo>[] = [
    {
      title: 'Nº Inventário', dataIndex: 'codigo', fixed: 'left', valorImpressao: (l) => l.codigo ?? '',
      render: (v, l) => <Link to={`/m/activos/activos/${l.ativo_imobilizado_id}`}><Button size="small" style={{ fontWeight: 700 }}>{v ?? '—'}</Button></Link>,
    },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
    { title: 'Data Aquisição', dataIndex: 'data_aquisicao', render: (v) => (v ? formatarData(v) : '—') },
    { title: 'Vlr Aquisição', dataIndex: 'valor_aquisicao', align: 'right', render: (v) => <ValorKz valor={v} />, totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.valor_aquisicao))) },
    {
      title: 'Amort. Acumulada', key: 'acumulada', align: 'right', valorImpressao: (l) => formatarKz(acumuladaLinha(l)),
      render: (_, l) => <ValorKz valor={acumuladaLinha(l)} />, totalImpressao: (ls) => formatarKz(somar(ls.map(acumuladaLinha))),
    },
    {
      title: 'Cota do Período', key: 'quota', align: 'right', valorImpressao: (l) => formatarKz(l.quota), totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.quota))),
      render: (_, l) => {
        const manual = quotaManual(l);
        const pendente = l.estado === 'RASCUNHO';
        return (
          <span style={{ color: pendente ? '#f59e0b' : '#ef4444', fontWeight: pendente ? 700 : 400, whiteSpace: 'nowrap' }}>
            {manual && <Tooltip title="Valor Manual"><span><IconeFa nome="hand-paper" /> </span></Tooltip>}
            {formatarKz(l.quota)}
          </span>
        );
      },
    },
    {
      title: 'Vlr Líquido', key: 'liquido', align: 'right', valorImpressao: (l) => formatarKz(liquidoLinha(l)),
      render: (_, l) => <ValorKz valor={liquidoLinha(l)} forte />, totalImpressao: (ls) => formatarKz(somar(ls.map(liquidoLinha))),
    },
    {
      title: 'Estado', dataIndex: 'estado', valorImpressao: (l) => (l.estado === 'INTEGRADO' ? 'Sim' : 'Não'),
      render: (v) => (v === 'INTEGRADO'
        ? <Tag color="success" icon={<IconeFa nome="check" />}> Sim</Tag>
        : <Tag style={{ color: '#f59e0b', background: 'transparent' }}>Não</Tag>),
    },
    {
      title: 'Ações', key: 'accoes', valorImpressao: () => '',
      render: (_, l) => (linhaComAccoes(l) && (podeCota || podeIntegrar) ? (
        <Space size={4} style={{ whiteSpace: 'nowrap' }}>
          {podeCota && (
            <Tooltip title="Definir cota manual para este mês">
              <Button size="small" icon={<IconeFa nome="edit" />} aria-label="Definir cota manual para este mês" onClick={() => setCota({ linha: l, valor: Number(l.quota) })} />
            </Tooltip>
          )}
          {podeIntegrar && (
            <Tooltip title="Integrar individualmente todos os períodos em falta até à data">
              <Button size="small" type="primary" style={{ background: '#10b981' }} icon={<IconeFa nome="file-import" />} aria-label="Integrar individualmente todos os períodos em falta até à data"
                loading={individual.isPending} onClick={() => integrarIndividual(l)} />
            </Tooltip>
          )}
        </Space>
      ) : <Typography.Text type="secondary" style={{ fontSize: 12 }}>---</Typography.Text>),
    },
  ];

  const corEstado: Record<string, string> = { success: '#16a34a', warning: '#d97706', default: '#475569' };

  return (
    <>
      <NavActivos actual="activos_amortizacoes" />
      <Card style={{ marginBottom: 24 }} styles={{ body: { padding: 20 } }}>
        <Flex justify="space-between" align="center" wrap gap={12}>
          <Flex align="center" gap={16} wrap>
            <div>
              <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginBottom: 4 }}>Mês de Referência</Typography.Text>
              <DatePicker picker="month" format="MM/YYYY" value={mes} onChange={(d) => d && setMes(d)} allowClear={false} aria-label="Mês de Referência" />
            </div>
            <div>
              <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginBottom: 4 }}>Estado</Typography.Text>
              <Tag color={estado.cor === 'default' ? undefined : estado.cor} style={{ padding: '6px 14px', fontSize: 14, color: corEstado[estado.cor] }}
                icon={<IconeFa nome={estado.icone} />}> {estado.rotulo}</Tag>
            </div>
          </Flex>
          <Space wrap>
            {!integrado ? (
              <>
                {acc.calcular && (
                  <Button type="primary" icon={<IconeFa nome="calculator" />} loading={calcular.isPending}
                    onClick={() => calcular.mutate({ url: '/ativos/amortizacoes/calcular', dados: { periodos: [periodo] } })}>
                    {textoCalcular(p)}
                  </Button>
                )}
                {temRascunhos && podeIntegrar && (
                  <Button type="primary" style={{ background: '#10b981' }} icon={<IconeFa nome="file-import" />} onClick={() => setPrevisao(true)}>Integrar na Contabilidade</Button>
                )}
              </>
            ) : acc.reabrir && (
              <Button danger type="primary" size="small" icon={<IconeFa nome="unlock" />} onClick={() => setReabrir([periodo])}>Reabrir Período</Button>
            )}
            {pode('activos_amort_anular') && (
              <Tooltip title="Reabrir multiplos períodos">
                <Button size="small" icon={<IconeFa nome="history" />} onClick={() => setPainel(painel === 'reabrir' ? null : 'reabrir')}>Reabrir em Massa</Button>
              </Tooltip>
            )}
            {pode('activos_amort_calcular') && (
              <Tooltip title="Calcular períodos em atraso">
                <Button size="small" style={{ background: '#f59e0b', color: '#fff', borderColor: '#f59e0b' }} icon={<IconeFa nome="calculator" />}
                  onClick={() => setPainel(painel === 'calculo' ? null : 'calculo')}>Cálculo em Massa</Button>
              </Tooltip>
            )}
            {podeIntegrar && (
              <Tooltip title="Integrar todos os Cálculos pendentes">
                <Button size="small" style={{ background: '#10b981', color: '#fff', borderColor: '#10b981' }} icon={<IconeFa nome="file-import" />}
                  onClick={() => setPainel(painel === 'integracao' ? null : 'integracao')}>Integração em Massa</Button>
              </Tooltip>
            )}
            <Tooltip title="Confere as quotas registadas com a regra de cálculo e os acumulados das fichas (só leitura)">
              <Button size="small" icon={<IconeFa nome="check-double" />} onClick={() => setVerificacao(true)}>Verificação</Button>
            </Tooltip>
          </Space>
        </Flex>
      </Card>

      {painel === 'reabrir' && <PainelReabrir aoFechar={() => setPainel(null)} aoReabrir={setReabrir} />}
      {painel === 'calculo' && <PainelCalculo aoFechar={() => setPainel(null)} />}
      {painel === 'integracao' && <PainelIntegracao aoFechar={() => setPainel(null)} />}

      <Card
        title={`Mapa de Amortizações - Período ${periodo}`}
        extra={(
          <Space wrap className="erp-oculto-impressao">
            <Input.Search placeholder="Filtrar activos" allowClear onSearch={setFiltro} style={{ width: 200 }} size="small" />
            <BotoesExportar tamanho="small" desactivado={!linhas.length} obterPedido={() => pedidoTabela({
              titulo: `Mapa de Amortizações - Período ${periodo}`,
              periodo: rotuloPeriodo(periodo),
              filtros: [`Estado: ${estado.rotulo}`, filtro ? `Filtro: ${filtro}` : null],
              colunas: colunas.filter((c) => c.key !== 'accoes'),
              linhas,
              totais: 'Total',
            })} />
          </Space>
        )}
      >
        <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 8 }}>{linhas.length} registos encontrados</Typography.Text>
        <Table<LinhaPeriodo>
          rowKey="ativo_imobilizado_id"
          size="small"
          loading={q.isFetching}
          dataSource={linhas}
          scroll={scrollTabela()}
          locale={{ emptyText: 'Nenhum activo elegível para este período.' }}
          pagination={{ defaultPageSize: 50, showSizeChanger: true, showTotal: (n) => `${n} registos` }}
          columns={colunas}
        />
      </Card>

      <Modal
        title={cota ? `Definir cota manual para o período ${periodo}` : ''}
        open={!!cota}
        onCancel={() => setCota(null)}
        okText="Gravar"
        cancelText="Cancelar"
        confirmLoading={quota.isPending}
        destroyOnHidden
        onOk={() => cota && quota.mutate({ metodo: 'put', url: '/ativos/amortizacoes/quota', dados: { ativo_imobilizado_id: cota.linha.ativo_imobilizado_id, periodo, valor: cota.valor ?? 0 } })}
      >
        <Typography.Paragraph>{cota?.linha.codigo} — {cota?.linha.descricao}</Typography.Paragraph>
        <Typography.Paragraph type="secondary">A cota fica em rascunho até à integração do período. Valor 0 retira o rascunho.</Typography.Paragraph>
        <InputNumber min={0} precision={2} style={{ width: 220 }} value={cota?.valor} onChange={(v) => cota && setCota({ ...cota, valor: v })} suffix="Kz" autoFocus aria-label="Cota do período" />
      </Modal>
      <ModalPrevisualizar periodo={previsao ? periodo : null} podeIntegrar={podeIntegrar} aoFechar={() => setPrevisao(false)} />
      <ModalReabrir periodos={reabrir} aoFechar={() => setReabrir([])} />
      <Modal title="Verificação das amortizações" open={verificacao} onCancel={() => setVerificacao(false)} footer={null} width={larguraModal(960)} destroyOnHidden>
        <VerificacaoAmortizacoes />
      </Modal>
    </>
  );
}

/** Cartão de painel em massa (legado: card com borda superior colorida, título com ícone e «×»). */
function CartaoPainel({ cor, icone, titulo, aoFechar, children }: { cor: string; icone: string; titulo: string; aoFechar: () => void; children: ReactNode }) {
  return (
    <Card style={{ borderTop: `4px solid ${cor}`, marginBottom: 24 }} title={<span><IconeFa nome={icone} /> {titulo}</span>}
      extra={<Button size="small" aria-label="Fechar" onClick={aoFechar}>×</Button>}>
      {children}
    </Card>
  );
}

/** Grelha de períodos com caixas de selecção (todas marcadas de início) e «Seleccionar Tudo». */
function GrelhaPeriodos({ lista, escolhidos, aoMudar, tom, distintivo, largura = 200 }: {
  lista: { periodo: string }[]; escolhidos: string[]; aoMudar: (v: string[]) => void; tom: 'neutro' | 'aviso'; distintivo?: (p: string) => ReactNode; largura?: number;
}) {
  // cores do tema (certas também na tela nocturna); cantos de 4 px como o legado
  const { token } = theme.useToken();
  const fundo = tom === 'aviso' ? token.colorWarningBg : token.colorFillAlter;
  const borda = tom === 'aviso' ? token.colorWarningBorder : token.colorBorderSecondary;
  return (
    <Checkbox.Group value={escolhidos} onChange={(v) => aoMudar(v as string[])} style={{ display: 'grid', gridTemplateColumns: `repeat(auto-fill, minmax(${largura}px, 1fr))`, gap: 10, marginBottom: 24, width: '100%' }}>
      {lista.map((m) => (
        <label key={m.periodo} style={{ background: fundo, padding: 10, borderRadius: token.borderRadius, border: `1px solid ${borda}`, cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <span style={{ display: 'flex', alignItems: 'center', gap: 10 }}><Checkbox value={m.periodo} /> <strong>{m.periodo}</strong></span>
          {distintivo?.(m.periodo)}
        </label>
      ))}
    </Checkbox.Group>
  );
}

function BotaoTudo({ lista, aoMudar }: { lista: { periodo: string }[]; aoMudar: (v: string[]) => void }) {
  return <Button size="small" icon={<IconeFa nome="check-double" />} onClick={() => aoMudar(seleccaoInicial(lista))}>Seleccionar Tudo</Button>;
}

/** «Histórico de Fechos - Reabertura em Massa» (showBulkReopenAmortizations). */
function PainelReabrir({ aoFechar, aoReabrir }: { aoFechar: () => void; aoReabrir: (p: string[]) => void }) {
  const q = useQuery({ queryKey: ['activos', 'amortizacoes', 'periodos-registados'], queryFn: () => obter<PeriodoRegistado[]>('/ativos/amortizacoes/periodos-registados') });
  const lista = maisRecentesPrimeiro(q.data ?? []);
  const [escolhidos, setEscolhidos] = useState<string[] | null>(null);
  const sel = escolhidos ?? seleccaoInicial(lista);
  return (
    <CartaoPainel cor="#ef4444" icone="history" titulo="Histórico de Fechos - Reabertura em Massa" aoFechar={aoFechar}>
      {q.isLoading ? <Typography.Text type="secondary">A carregar…</Typography.Text> : !lista.length ? (
        <Alert type="info" showIcon message="Não existem períodos contabilizados para reabrir." />
      ) : (
        <>
          <Flex justify="space-between" align="center" wrap gap={8} style={{ marginBottom: 16 }}>
            <Typography.Text type="secondary" style={{ fontSize: 13 }}>Selecione os períodos que deseja anular. Isto irá estornar os lançamentos na contabilidade e reverter os saldos dos activos.</Typography.Text>
            <BotaoTudo lista={lista} aoMudar={setEscolhidos} />
          </Flex>
          <GrelhaPeriodos lista={lista} escolhidos={sel} aoMudar={setEscolhidos} tom="neutro" largura={180}
            distintivo={(p) => { const x = lista.find((m) => m.periodo === p); return x ? <Tag color={x.integrados ? 'success' : 'warning'} style={{ fontSize: 11 }}>{x.integrados ? 'integrado' : 'rascunho'}</Tag> : null; }} />
          <Flex justify="end" gap={10}>
            <Button onClick={aoFechar}>Cancelar</Button>
            <Button danger type="primary" icon={<IconeFa nome="unlock" />} disabled={!sel.length} onClick={() => aoReabrir(ordenarPeriodos(sel))}>Reabrir Períodos Seleccionados</Button>
          </Flex>
        </>
      )}
    </CartaoPainel>
  );
}

function usePendentes() {
  return useQuery({ queryKey: ['activos', 'amortizacoes', 'pendentes'], queryFn: () => obter<{ por_calcular: MesPendente[]; por_integrar: MesPendente[] }>('/ativos/amortizacoes/pendentes') });
}

/** «Processamento em Massa de Pendentes» (showBulkAmortizationProcessor): meses fechados por calcular. */
function PainelCalculo({ aoFechar }: { aoFechar: () => void }) {
  const q = usePendentes();
  const lista = q.data?.por_calcular ?? [];
  const [escolhidos, setEscolhidos] = useState<string[] | null>(null);
  const sel = escolhidos ?? seleccaoInicial(lista);
  const calcular = useAccao({ invalidar: [['activos']], aoSucesso: aoFechar });
  const confirmar = () => Modal.confirm({
    title: `Deseja processar o Cálculo de ${sel.length} período(s)?`, content: 'Os valores serão guardados como rascunho.', okText: 'Processar', cancelText: 'Cancelar',
    onOk: () => calcular.mutateAsync({ url: '/ativos/amortizacoes/calcular', dados: { periodos: ordenarPeriodos(sel) } }),
  });
  return (
    <CartaoPainel cor="#f59e0b" icone="play" titulo="Processamento em Massa de Pendentes" aoFechar={aoFechar}>
      {q.isLoading ? <Typography.Text type="secondary">A carregar…</Typography.Text> : !lista.length ? (
        <Alert type="info" showIcon message="Não foram detectados períodos pendentes de amortização." />
      ) : (
        <>
          <Flex justify="space-between" align="center" wrap gap={8} style={{ marginBottom: 16 }}>
            <Typography.Text type="secondary" style={{ fontSize: 13 }}>Seleccione os períodos que deseja calcular como rascunho.</Typography.Text>
            <BotaoTudo lista={lista} aoMudar={setEscolhidos} />
          </Flex>
          <GrelhaPeriodos lista={lista} escolhidos={sel} aoMudar={setEscolhidos} tom="aviso"
            distintivo={(p) => <Tag color="warning" style={{ fontSize: 11, border: 0 }}>{lista.find((m) => m.periodo === p)?.ativos ?? 0} activos</Tag>} />
          <Flex justify="end" gap={10}>
            <Button onClick={aoFechar}>Cancelar</Button>
            <Button style={{ background: '#f59e0b', color: '#fff', borderColor: '#f59e0b' }} icon={<IconeFa nome="play" />} disabled={!sel.length} loading={calcular.isPending} onClick={confirmar}>
              Dar Ordem de Processamento (Cálculo)
            </Button>
          </Flex>
        </>
      )}
    </CartaoPainel>
  );
}

/** «Integração em Massa na Contabilidade» (showBulkIntegrationPreview): todos os períodos com rascunhos. */
function PainelIntegracao({ aoFechar }: { aoFechar: () => void }) {
  const { token } = theme.useToken();
  const q = usePendentes();
  const lista = q.data?.por_integrar ?? [];
  const periodos = ordenarPeriodos(lista.map((m) => m.periodo));
  const integrar = useAccao({ invalidar: [['activos']], aoSucesso: aoFechar });
  const confirmar = () => Modal.confirm({
    title: `Confirma a integração contabilística de ${periodos.length} períodos?`, content: 'Esta acção irá gerar todos os lançamentos correspondentes no diário AM.', okText: 'Integrar', cancelText: 'Cancelar',
    onOk: () => integrar.mutateAsync({ url: '/ativos/amortizacoes/integrar', dados: { periodos } }),
  });
  return (
    <CartaoPainel cor="#10b981" icone="file-import" titulo="Integração em Massa na Contabilidade" aoFechar={aoFechar}>
      {q.isLoading ? <Typography.Text type="secondary">A carregar…</Typography.Text> : !lista.length ? (
        <Alert type="info" showIcon message="Não existem cálculos pendentes (rascunhos) para integrar." />
      ) : (
        <>
          <Typography.Paragraph type="secondary" style={{ fontSize: 13 }}>Serão gerados lançamentos no Diário AM para os seguintes períodos:</Typography.Paragraph>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: 10, marginBottom: 24 }}>
            {lista.map((m) => (
              <div key={m.periodo} style={{ background: token.colorSuccessBg, padding: 10, borderRadius: token.borderRadius, border: `1px solid ${token.colorSuccessBorder}`, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <strong>{m.periodo}</strong><Tag color="success" style={{ fontSize: 11, marginInlineEnd: 0 }}>{m.ativos} activos</Tag>
              </div>
            ))}
          </div>
          <Alert type="info" style={{ marginBottom: 24 }} message={<>Total Global a Contabilizar: <strong>{formatarKz(totalMeses(lista))}</strong></>} />
          <Flex justify="end" gap={10}>
            <Button onClick={aoFechar}>Cancelar</Button>
            <Button type="primary" style={{ background: '#10b981' }} icon={<IconeFa nome="check-double" />} loading={integrar.isPending} onClick={confirmar}>
              Confirmar Integração de {periodos.length} Períodos
            </Button>
          </Flex>
        </>
      )}
    </CartaoPainel>
  );
}

/**
 * Impressão da pré-visualização («Simular e Integrar» do legado, js/ui_assets.js `previewAmortizationIntegration`):
 * linhas do lançamento a gerar (com totais e equilíbrio) e movimentos por activo, marcada «Simulação» — nada integrado.
 */
export function pedidoPreVisualizacaoAmortizacoes(p: PreVisualizacao): PedidoImpressao {
  const linhas = tabelaLancamentoHtml(p.linhas.map((l) => ({ conta: l.codigo_conta, tipo_dc: l.tipo_dc, valor: l.valor, unidade: l.unidade_negocio_codigo ?? (l.unidade_negocio_id ? `#${l.unidade_negocio_id}` : null), centro: l.centro_custo_codigo ?? (l.centro_custo_id ? `#${l.centro_custo_id}` : null) })));
  const movimentos = tabelaHtml({
    legenda: `Movimentos por activo (${p.movimentos.length})`,
    linhas: p.movimentos,
    totais: 'Total',
    colunas: [
      { titulo: 'Activo', valor: (m) => `${m.codigo ?? '—'} — ${m.descricao}`, quebrar: true },
      { titulo: 'Conta a débito', valor: (m) => m.conta_debito },
      { titulo: 'Conta a crédito', valor: (m) => m.conta_credito },
      { titulo: 'Valor (Kz)', valor: (m) => m.valor, formato: 'moeda', somar: true },
    ],
  });
  return {
    titulo: 'Simulação da integração das amortizações',
    subtitulo: `Documento ${p.numero_documento}`,
    periodo: rotuloPeriodo(p.periodo),
    filtros: [`Total: ${formatarKz(p.total)} Kz`],
    conteudo: `${marcaSimulacao('pré-visualização do lançamento de integração calculada no servidor; nada foi integrado na contabilidade.')}${linhas}${movimentos}`,
    cssExtra: CSS_SIMULACAO_COMUM,
  };
}

/** Pré-visualização do lançamento de integração (movimentos por activo e linhas agrupadas) e botão para integrar. */
function ModalPrevisualizar({ periodo, podeIntegrar, aoFechar }: { periodo: string | null; podeIntegrar: boolean; aoFechar: () => void }) {
  const q = useQuery({
    queryKey: ['activos', 'amortizacoes', 'previsao', periodo],
    queryFn: () => obter<PreVisualizacao>('/ativos/amortizacoes/pre-visualizacao', { periodo }),
    enabled: !!periodo,
    retry: false,
  });
  const integrar = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  const p = q.data;
  return (
    <Modal
      title={`Integração de ${periodo ? rotuloPeriodo(periodo) : ''}`}
      open={!!periodo}
      onCancel={aoFechar}
      width={larguraModal(960)}
      destroyOnHidden
      footer={[
        <BotoesExportar key="p" chave="activos pre-visualizacao integracao" desactivado={!p} textoImprimir="Imprimir simulação" excel={false}
          obterPedido={() => p && pedidoPreVisualizacaoAmortizacoes(p)} />,
        <Button key="c" onClick={aoFechar}>Fechar</Button>,
        podeIntegrar && (
          <Button key="i" type="primary" icon={<CloudUploadOutlined />} disabled={!p} loading={integrar.isPending}
            onClick={() => integrar.mutate({ url: '/ativos/amortizacoes/integrar', dados: { periodos: [periodo] } })}>
            Integrar na contabilidade
          </Button>
        ),
      ]}
    >
      {q.error && <Alert type="warning" showIcon message={(q.error as Error).message} />}
      {p && (
        <>
          <Descriptions size="small" column={COLUNAS_DESCRICOES} style={{ marginBottom: 12 }}>
            <Descriptions.Item label="Documento">{p.numero_documento}</Descriptions.Item>
            <Descriptions.Item label="Activos">{p.movimentos.length}</Descriptions.Item>
            <Descriptions.Item label="Total"><ValorKz valor={p.total} forte /></Descriptions.Item>
          </Descriptions>
          <Typography.Title level={5}>Linhas do lançamento</Typography.Title>
          <Table
            size="small"
            rowKey={(_, i) => String(i)}
            pagination={false}
            dataSource={p.linhas}
            columns={[
              { title: 'Conta', dataIndex: 'codigo_conta' },
              { title: 'UN', dataIndex: 'unidade_negocio_id', render: (v, l) => l.unidade_negocio_codigo ?? (v ? `#${v}` : '—') },
              { title: 'CC', dataIndex: 'centro_custo_id', render: (v, l) => l.centro_custo_codigo ?? (v ? `#${v}` : '—') },
              { title: 'Débito', key: 'd', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : '') },
              { title: 'Crédito', key: 'c', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : '') },
            ]}
          />
          <div style={{ margin: '12px 0' }}><IndicadorEquilibrio linhas={p.linhas} /></div>
          <Typography.Title level={5}>Movimentos por activo</Typography.Title>
          <Table
            size="small"
            rowKey="ativo_imobilizado_id"
            pagination={{ pageSize: 10 }}
            dataSource={p.movimentos}
            columns={[
              { title: 'Activo', key: 'a', render: (_, m) => `${m.codigo} — ${m.descricao}` },
              { title: 'Débito', dataIndex: 'conta_debito' },
              { title: 'Crédito', dataIndex: 'conta_credito' },
              { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
            ]}
          />
        </>
      )}
    </Modal>
  );
}

/** Reabertura de um ou vários períodos (cancelAmortizationPeriod / executeBulkReopenAmortizations): estorno e anulação dos cálculos. */
function ModalReabrir({ periodos, aoFechar }: { periodos: string[]; aoFechar: () => void }) {
  const [motivo, setMotivo] = useState('');
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => { setMotivo(''); aoFechar(); } });
  const varios = periodos.length > 1;
  return (
    <Modal
      title={varios ? `Reabrir ${periodos.length} período(s)` : `Reabrir ${periodos.map(rotuloPeriodo).join(', ')}`}
      open={periodos.length > 0}
      onCancel={aoFechar}
      okText="Reabrir"
      cancelText="Cancelar"
      okButtonProps={{ danger: true }}
      confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ url: '/ativos/amortizacoes/reabrir', dados: { periodos, motivo: motivo.trim() || null } })}
    >
      <Alert type="warning" showIcon style={{ marginBottom: 12 }}
        message={varios ? `Tem a certeza que deseja reabrir ${periodos.length} período(s)? Os lançamentos são estornados e os cálculos anulados (incluindo os migrados).`
          : 'Os lançamentos do período são estornados e os cálculos anulados (incluindo os migrados).'}
        description={varios ? periodos.join(', ') : undefined} />
      <Input.TextArea rows={3} maxLength={500} placeholder="Motivo (opcional)" value={motivo} onChange={(e) => setMotivo(e.target.value)} />
    </Modal>
  );
}

function VerificacaoAmortizacoes() {
  const q = useQuery({ queryKey: ['activos', 'amortizacoes', 'verificacao'], queryFn: () => obter<Verificacao>('/ativos/amortizacoes/verificacao') });
  const v = q.data;
  return (
    <Card loading={q.isLoading} variant="borderless">
      {v && (
        <>
          <Alert
            style={{ marginBottom: 16 }}
            type={v.quotas_divergentes.length || v.acumulados_divergentes.length ? 'warning' : 'success'}
            showIcon
            message={`${v.registos} quota(s) verificada(s): ${v.quotas_divergentes.length} divergente(s) do cálculo, ${v.acumulados_divergentes.length} ficha(s) com acumulado diferente do integrado.`}
            description="Diferenças de 1 cêntimo nos registos migrados resultam do arredondamento em vírgula flutuante do sistema antigo (ADR-051); os registos migrados não são alterados."
          />
          <Flex justify="end" style={{ marginBottom: 8 }}>
            <BotaoCsv nome="verificacao-amortizacoes" linhas={v.quotas_divergentes} colunas={[
              { titulo: 'Activo', valor: (l) => l.codigo }, { titulo: 'Período', valor: (l) => l.periodo }, { titulo: 'Registado', valor: (l) => l.registado, numerico: true },
              { titulo: 'Esperado', valor: (l) => l.esperado, numerico: true }, { titulo: 'Diferença', valor: (l) => l.diferenca, numerico: true }, { titulo: 'Integrado', valor: (l) => l.contabilizado },
            ]} />
          </Flex>
          <Table
            size="small"
            rowKey={(l) => `${l.ativo_imobilizado_id}-${l.periodo}`}
            dataSource={v.quotas_divergentes}
            pagination={{ pageSize: 15 }}
            columns={[
              { title: 'Activo', dataIndex: 'codigo' },
              { title: 'Período', dataIndex: 'periodo', render: rotuloPeriodo },
              { title: 'Registado', dataIndex: 'registado', align: 'right', render: (x) => <ValorKz valor={x} /> },
              { title: 'Esperado', dataIndex: 'esperado', align: 'right', render: (x) => <ValorKz valor={x} /> },
              { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (x) => <ValorKz valor={x} forte /> },
              { title: 'Estado', dataIndex: 'contabilizado', render: (c) => <EtiquetaActivos valor={c ? 'INTEGRADO' : 'RASCUNHO'} /> },
            ]}
          />
          {v.acumulados_divergentes.length > 0 && (
            <Table
              style={{ marginTop: 16 }}
              size="small"
              rowKey="ativo_imobilizado_id"
              dataSource={v.acumulados_divergentes}
              pagination={false}
              title={() => <strong>Acumulado da ficha ≠ integrado</strong>}
              columns={[
                { title: 'Activo', dataIndex: 'codigo' },
                { title: 'Na ficha', dataIndex: 'ficha', align: 'right', render: (x) => <ValorKz valor={x} /> },
                { title: 'Integrado', dataIndex: 'derivado', align: 'right', render: (x) => <ValorKz valor={x} /> },
              ]}
            />
          )}
        </>
      )}
    </Card>
  );
}
