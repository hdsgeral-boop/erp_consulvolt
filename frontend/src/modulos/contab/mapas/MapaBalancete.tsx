import { Button, Card, Checkbox, DatePicker, Descriptions, Flex, Form, InputNumber, Skeleton, Space, Table, Tag, Typography, Upload, message } from 'antd';
import { UploadOutlined } from '@ant-design/icons';
import type { Dayjs } from 'dayjs';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { useNavigate } from 'react-router-dom';
import { BotoesExportar, pares } from '@/componentes/impressao';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { CartaoKpi, CORES_KPI, GrelhaKpi } from '@/componentes/vistas';
import { scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { eZero, somar } from '@/utilitarios/decimal';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { Balancete, LinhaBalancete, LinhaIva, MapaIva } from '../api';
import { BotaoCsv, ValorKz } from '../comum/Componentes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { enviarFicheiro } from '../comum/ficheiros';
import { filtrosDosParametros, periodoDosParametros, tabelaDeColunas } from '../comum/impressao';
import { useMapa } from '../comum/useMapa';
import { periodoPorOmissao } from './periodoBalancete';
import { TituloMapa } from './TituloMapa';
import { achatar, agruparPorNivel, arvorePorPrefixo, chavesExpansiveis } from './arvoreContas';
import { BotaoDetalheConta, BotoesArvore, DetalheConta, type PedidoDetalheConta } from './DetalheConta';
import { BalancetePorEmpresa, InterruptorPorEmpresa, usePorEmpresa } from './PorEmpresa';
import './mapas.css';

/** Conta escolhida no balancete → separador Extrato (legado: renderRelatoriosContabeis('extrato', { accCode }), js/ui_reports.js:1660-1666). */
export interface PedidoExtratoConta {
  conta: string;
  terceiroId?: number | null;
  inicio?: string;
  fim?: string;
}

function useAbrirExtrato() {
  const navegar = useNavigate();
  return (pedido: PedidoExtratoConta) => navegar('/m/contab/contab_mapa_extrato', { state: { extrato: pedido } });
}

/** Separador «Balancete» (ecrã contab_mapa_balancete). */
export default function MapaBalancete() {
  return <SeparadorBalancete />;
}

/**
 * Separador «Balancete do Razão» (tab 'razao', js/ui_reports.js:742-891): o balancete agregado pelas contas do razão
 * (2 dígitos, `nivel` 2) — Conta, Descrição, Movimento (Débito, Crédito) e Saldo (Débito, Crédito).
 */
export function MapaBalanceteRazao() {
  return <SeparadorRazao />;
}

/** Separador «Mapa IVA» (tab 'iva', js/ui_reports.js:6119-6500). */
export function MapaIvaSeparador() {
  return (
    <>
      <TituloMapa titulo="Mapa de Reconciliação de IVA" />
      <SeparadorIva />
    </>
  );
}

/** Linha do balancete como aparece na tabela: chave, conta «filha» de uma totalizadora e os terceiros como sub-linhas. */
export type LinhaBalanceteVista = LinhaBalancete & { chave: string; filha?: boolean; eTerceiro?: boolean; filhos?: LinhaBalanceteVista[] };

const semPontos = (c: string) => String(c).replace(/\./g, '');

/**
 * Linhas da tabela do balancete (js/ui_reports.js:530-650): as contas de movimento debaixo de uma totalizadora ficam
 * recuadas e com a descrição mais clara; os terceiros («Quebrar por Terceiros») são sub-linhas da conta.
 */
export function linhasVistaBalancete(linhas: LinhaBalancete[]): LinhaBalanceteVista[] {
  const prefixos = linhas.filter((l) => l.totalizadora).map((l) => semPontos(l.codigo_conta));
  return linhas.map((l) => {
    const cod = semPontos(l.codigo_conta);
    const filhos = l.terceiros?.length
      ? l.terceiros.map((t) => ({ ...t, codigo_conta: l.codigo_conta, descricao: t.nome ?? null, chave: `${l.codigo_conta}|t${t.terceiro_id}`, eTerceiro: true }))
      : undefined;
    return {
      ...l,
      chave: `${l.codigo_conta}|${l.terceiro_id ?? ''}${l.totalizadora ? '|T' : ''}`,
      filha: !l.totalizadora && prefixos.some((p) => cod.startsWith(p) && cod.length > p.length),
      filhos,
    };
  });
}

/** Classe da linha (totalizadora Σ — 1.º nível mais carregada —, terceiro, conta de movimento). */
export function classeLinhaBalancete(l: LinhaBalanceteVista): string {
  if (l.totalizadora) return `erp-bal-totalizadora${semPontos(l.codigo_conta).length === 1 ? ' erp-bal-classe' : ''}`;
  if (l.eTerceiro) return 'erp-bal-terceiro';
  return `erp-bal-movimento${l.filha ? ' erp-bal-filha' : ''}`;
}

/** Valor do balancete como no legado: «-» quando é zero (js/ui_reports.js:575-582). */
function ValorBal({ valor, classe }: { valor: string | null | undefined; classe?: string }) {
  if (eZero(valor)) return <span className="erp-bal-vazio">-</span>;
  return (
    <span className={classe} style={{ whiteSpace: 'nowrap' }}>
      {formatarKz(valor)}
    </span>
  );
}

function SeparadorBalancete() {
  const mapa = useMapa<Balancete>('balancete', '/contabilidade/relatorios/balancete');
  const abrirExtrato = useAbrirExtrato();
  const p = mapa.parametros;
  const pequeno = useEcraPequeno();
  const t = mapa.data?.totais;
  const linhas = useMemo(() => (mapa.data ? linhasVistaBalancete(mapa.data.linhas) : []), [mapa.data]);
  // árvore: cada totalizadora (Σ) abre até às contas de movimento, e estas aos terceiros («Quebrar por Terceiros»)
  const arvore = useMemo(() => arvorePorPrefixo(linhas), [linhas]);
  const todasChaves = useMemo(() => chavesExpansiveis(arvore), [arvore]);
  const [abertas, setAbertas] = useState<string[]>([]);
  // como o legado (lista aberta), cada cálculo mostra a árvore toda expandida
  useEffect(() => setAbertas(todasChaves), [todasChaves]);
  const [detalhe, setDetalhe] = useState<PedidoDetalheConta | null>(null);
  const abrirDetalhe = (r: LinhaBalanceteVista) =>
    setDetalhe({ conta: r.codigo_conta, descricao: r.eTerceiro ? null : r.descricao, terceiroId: r.terceiro_id, terceiroNome: r.eTerceiro ? r.descricao : r.terceiro, inicio: p?.data_inicio as string, fim: p?.data_fim as string, filtros: p });
  const planas = useMemo(() => achatar(arvore), [arvore]);
  const [porEmpresa, setPorEmpresa] = useState(false);
  const pe = usePorEmpresa('balancete', p, porEmpresa);
  // SI devedor/credor: soma das contas de movimento mostradas (o legado preenchia-os ao filtrar, js/ui_reports.js:3523)
  const movimento = linhas.filter((l) => !l.totalizadora);
  const totaisSi = { saldo_inicial_devedor: somar(movimento.map((l) => l.saldo_inicial_devedor)), saldo_inicial_credor: somar(movimento.map((l) => l.saldo_inicial_credor)) };
  const total = (k: string) => () => formatarKz(t?.[k]);
  const extrato = (r: LinhaBalanceteVista) => abrirExtrato({ conta: r.codigo_conta, terceiroId: r.terceiro_id, inicio: p?.data_inicio as string, fim: p?.data_fim as string });

  // colunas do legado (js/ui_reports.js:686-696): Conta · Descrição · SI Devedor · SI Credor · Débitos · Créditos · S. Devedor · S. Credor
  const colunas: ColunaApi<LinhaBalanceteVista>[] = [
    {
      title: 'Conta',
      dataIndex: 'codigo_conta',
      fixed: pequeno ? undefined : 'left',
      render: (v: string, r) =>
        r.eTerceiro ? null : r.totalizadora ? (
          <span className="erp-bal-codigo">{v}</span>
        ) : (
          <span className="erp-bal-codigo">
            <Typography.Link title="Abrir o extrato da conta" onClick={() => extrato(r)}>
              {v}
            </Typography.Link>
            <BotaoDetalheConta aoClicar={() => abrirDetalhe(r)} />
          </span>
        ),
      valorImpressao: (l) => (l.eTerceiro ? '' : l.codigo_conta),
    },
    {
      title: 'Descrição',
      dataIndex: 'descricao',
      render: (v: string | null, r) =>
        r.eTerceiro ? (
          <span className="erp-bal-descricao">
            <IconeFa nome="user-tag" style={{ fontSize: '0.75em', marginRight: 4 }} />
            <Typography.Link title="Abrir o extrato da conta deste terceiro" onClick={() => extrato(r)}>
              {v?.trim() || 'Terceiro desconhecido'}
            </Typography.Link>
            <BotaoDetalheConta aoClicar={() => abrirDetalhe(r)} titulo="Ver os movimentos da conta deste terceiro no período" />
          </span>
        ) : (
          <span className="erp-bal-descricao">{`${r.totalizadora ? 'Σ ' : ''}${v ?? (r.totalizadora ? '' : 'Conta desconhecida')}${r.terceiro ? ` · ${r.terceiro.trim()}` : ''}`}</span>
        ),
      valorImpressao: (l) => `${l.totalizadora ? 'Σ ' : ''}${l.descricao ?? ''}${l.terceiro ? ` · ${l.terceiro.trim()}` : ''}`,
    },
    { title: 'SI Devedor', dataIndex: 'saldo_inicial_devedor', align: 'right', render: (v: string) => <ValorBal valor={v} />, responsive: ['md'], totalImpressao: () => formatarKz(totaisSi.saldo_inicial_devedor) },
    { title: 'SI Credor', dataIndex: 'saldo_inicial_credor', align: 'right', render: (v: string) => <ValorBal valor={v} />, responsive: ['md'], totalImpressao: () => formatarKz(totaisSi.saldo_inicial_credor) },
    { title: 'Débitos', dataIndex: 'debito', align: 'right', render: (v: string) => <ValorBal valor={v} />, totalImpressao: total('debito') },
    { title: 'Créditos', dataIndex: 'credito', align: 'right', render: (v: string) => <ValorBal valor={v} />, totalImpressao: total('credito') },
    { title: 'S. Devedor', dataIndex: 'saldo_devedor', align: 'right', render: (v: string) => <ValorBal valor={v} classe="erp-bal-sd" />, totalImpressao: total('saldo_devedor') },
    { title: 'S. Credor', dataIndex: 'saldo_credor', align: 'right', render: (v: string) => <ValorBal valor={v} classe="erp-bal-sc" />, totalImpressao: total('saldo_credor') },
  ];
  const totaisVisiveis: { valor: string | undefined; classe?: string }[] = [
    ...(pequeno ? [] : [{ valor: totaisSi.saldo_inicial_devedor }, { valor: totaisSi.saldo_inicial_credor }]),
    { valor: t?.debito },
    { valor: t?.credito },
    { valor: t?.saldo_devedor, classe: 'erp-bal-sd' },
    { valor: t?.saldo_credor, classe: 'erp-bal-sc' },
  ];
  const marcador = (texto: ReactNode, dica: string, destaque = false) => (
    <span title={dica} style={destaque ? { color: 'var(--ant-color-primary, #2563eb)', fontWeight: 600 } : undefined}>
      {texto}
    </span>
  );
  return (
    <Card>
      <TituloMapa titulo="Balancete" subtitulo={periodoDosParametros(p)} />
      <FiltrosMapa
        modo="periodo"
        // como o legado, as contas totalizadoras (Σ) aparecem sempre — salvo com «Apenas Movimento (S/ Totalizadoras)»
        valoresIniciais={{ periodo: periodoPorOmissao(), totalizadoras: true }}
        aCalcular={mapa.isFetching}
        aoCalcular={(q) => mapa.calcular(q)}
        extra={
          <>
            {/* opções do legado, pela mesma ordem e com os mesmos rótulos (js/ui_reports.js:202-221) */}
            <Form.Item name="por_terceiro" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Quebrar por Terceiros</Checkbox>
            </Form.Item>
            <Form.Item name="so_movimento" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>{marcador('Apenas Movimento (S/ Totalizadoras)', 'Apenas contas com movimento no período (exclui totalizadoras)')}</Checkbox>
            </Form.Item>
            <Form.Item name="sem_saldo_inicial" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>{marcador('S/ Saldos Iniciais (Apenas Período)', 'Ver apenas os movimentos do período seleccionado')}</Checkbox>
            </Form.Item>
            <Form.Item name="sem_saldo_zero" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>{marcador('Ocultar Saldo 0', 'Ocultar contas cujo saldo final (SD ou SC) seja 0', true)}</Checkbox>
            </Form.Item>
            {/* opções do sistema novo */}
            <Form.Item name="totalizadoras" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Com totalizadoras</Checkbox>
            </Form.Item>
            <Form.Item name="nivel" label="Nível" tooltip="N.º de dígitos da conta (vazio = todas as contas de movimento)" style={{ marginBottom: 8 }}>
              <InputNumber min={1} max={20} style={{ width: 90 }} />
            </Form.Item>
            <Form.Item name="excluir_estornos" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Excluir estornos</Checkbox>
            </Form.Item>
          </>
        }
      />
      {mapa.data && (
        <>
          <Space wrap style={{ margin: '8px 0 12px' }}>
            <BotoesExportar
              obterPedido={async () =>
                mapa.data && {
                  titulo: 'Balancete',
                  periodo: periodoDosParametros(p),
                  filtros: filtrosDosParametros(p),
                  conteudo: await tabelaDeColunas(colunas, planas),
                }
              }
            />
            <BotaoCsv<LinhaBalanceteVista>
              nome={`balancete_${p?.data_inicio}_${p?.data_fim}`}
              linhas={planas}
              colunas={[
                { titulo: 'Conta', valor: (l) => l.codigo_conta },
                { titulo: 'Descrição', valor: (l) => (l.eTerceiro ? null : l.descricao) },
                { titulo: 'Terceiro', valor: (l) => (l.eTerceiro ? l.descricao : l.terceiro?.trim()) },
                { titulo: 'SI Devedor', valor: (l) => l.saldo_inicial_devedor, numerico: true },
                { titulo: 'SI Credor', valor: (l) => l.saldo_inicial_credor, numerico: true },
                { titulo: 'Débitos', valor: (l) => l.debito, numerico: true },
                { titulo: 'Créditos', valor: (l) => l.credito, numerico: true },
                { titulo: 'S. Devedor', valor: (l) => l.saldo_devedor, numerico: true },
                { titulo: 'S. Credor', valor: (l) => l.saldo_credor, numerico: true },
              ]}
            />
            <InterruptorPorEmpresa ligado={porEmpresa} aoMudar={setPorEmpresa} />
            {todasChaves.length > 0 && !porEmpresa && <BotoesArvore aoExpandir={() => setAbertas(todasChaves)} aoRecolher={() => setAbertas([])} />}
            {t && t.debito !== t.credito && <Tag color="red">Débito ≠ crédito: verifique os desequilíbrios</Tag>}
          </Space>
          {porEmpresa ? (
            <BalancetePorEmpresa dados={pe.data} periodo={periodoDosParametros(p)} carregando={pe.isFetching} />
          ) : (
          <Table<LinhaBalanceteVista>
            className="erp-mapa-contab"
            rowKey="chave"
            size="small"
            columns={colunas}
            dataSource={arvore}
            rowClassName={classeLinhaBalancete}
            expandable={{ childrenColumnName: 'filhos', expandedRowKeys: abertas, onExpandedRowsChange: (k) => setAbertas(k.map(String)), indentSize: 12 }}
            pagination={{ pageSize: 100, showSizeChanger: true, showTotal: (n) => `${n} conta(s) de 1.º nível` }}
            scroll={scrollTabela()}
            summary={() =>
              t ? (
                <Table.Summary fixed>
                  <Table.Summary.Row>
                    <Table.Summary.Cell index={0} colSpan={2} align="right">
                      <strong>TOTAIS:</strong>
                    </Table.Summary.Cell>
                    {totaisVisiveis.map((c, i) => (
                      <Table.Summary.Cell key={i} index={i + 2} align="right">
                        <strong>
                          <ValorBal valor={c.valor} classe={c.classe} />
                        </strong>
                      </Table.Summary.Cell>
                    ))}
                  </Table.Summary.Row>
                </Table.Summary>
              ) : null
            }
          />
          )}
        </>
      )}
      <DetalheConta pedido={detalhe} aoFechar={() => setDetalhe(null)} />
    </Card>
  );
}

/** «Balancete do razão» (js/ui_reports.js:829-891): cabeçalho azul em duas linhas (Movimento / Saldo) e «Total Geral». */
function SeparadorRazao() {
  const mapa = useMapa<Balancete>('balancete_razao', '/contabilidade/relatorios/balancete');
  const abrirExtrato = useAbrirExtrato();
  const p = mapa.parametros;
  const t = mapa.data?.totais;
  // cada conta do razão abre as suas contas de movimento: o mesmo balancete sem nível, pedido uma vez quando se abre a 1.ª
  const [abertas, setAbertas] = useState<string[]>([]);
  const [detalhe, setDetalhe] = useState<PedidoDetalheConta | null>(null);
  useEffect(() => setAbertas([]), [mapa.data]);
  const pMovimento = p ? { ...p, nivel: undefined, totalizadoras: undefined } : null;
  const movimento = useQuery({
    queryKey: ['contab', 'mapa', 'balancete_razao', 'movimento', pMovimento],
    queryFn: () => obter<Balancete>('/contabilidade/relatorios/balancete', pMovimento ?? undefined),
    enabled: !!pMovimento && abertas.length > 0,
    retry: false,
  });
  const porRazao = useMemo(() => agruparPorNivel(movimento.data?.linhas ?? [], 2), [movimento.data]);
  const todas = (mapa.data?.linhas ?? []).map((l) => l.codigo_conta);
  const colunasMovimento: ColunaApi<LinhaBalancete>[] = [
    {
      title: 'Conta',
      dataIndex: 'codigo_conta',
      render: (v: string, r) => (
        <span style={{ whiteSpace: 'nowrap', paddingLeft: 16 }}>
          <Typography.Link title="Abrir o extrato da conta" onClick={() => abrirExtrato({ conta: v, inicio: p?.data_inicio as string, fim: p?.data_fim as string })}>
            {v}
          </Typography.Link>
          <BotaoDetalheConta aoClicar={() => setDetalhe({ conta: v, descricao: r.descricao, inicio: p?.data_inicio as string, fim: p?.data_fim as string, filtros: p })} />
        </span>
      ),
    },
    { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null) => <Typography.Text type="secondary">{v ?? 'Conta desconhecida'}</Typography.Text> },
    { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string) => <ValorBal valor={v} /> },
    { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string) => <ValorBal valor={v} /> },
    { title: 'S. Devedor', dataIndex: 'saldo_devedor', align: 'right', render: (v: string) => <ValorBal valor={v} classe="erp-bal-sd" /> },
    { title: 'S. Credor', dataIndex: 'saldo_credor', align: 'right', render: (v: string) => <ValorBal valor={v} classe="erp-bal-sc" /> },
  ];
  const kz = (v: string) => <span style={{ whiteSpace: 'nowrap' }}>{formatarKz(v)}</span>;
  const total = (k: string) => () => formatarKz(t?.[k]);
  const colunaConta: ColunaApi<LinhaBalancete> = {
    title: 'Conta',
    dataIndex: 'codigo_conta',
    render: (v: string) => (
      <Typography.Link className="erp-mapa-conta" title="Abrir o extrato da conta" onClick={() => abrirExtrato({ conta: `${v}*`, inicio: p?.data_inicio as string, fim: p?.data_fim as string })}>
        {v}
      </Typography.Link>
    ),
    valorImpressao: (l) => l.codigo_conta,
  };
  const colunaDescricao: ColunaApi<LinhaBalancete> = { title: 'Descrição', dataIndex: 'descricao' };
  const valor = (titulo: string, k: keyof LinhaBalancete & string): ColunaApi<LinhaBalancete> => ({ title: titulo, dataIndex: k, align: 'right', render: kz, totalImpressao: total(k) });
  // impressão/PDF: colunas simples (o motor de impressão não agrupa cabeçalhos)
  const colunasImpressao: ColunaApi<LinhaBalancete>[] = [
    colunaConta,
    colunaDescricao,
    valor('Movimento — Débito', 'debito'),
    valor('Movimento — Crédito', 'credito'),
    valor('Saldo — Débito', 'saldo_devedor'),
    valor('Saldo — Crédito', 'saldo_credor'),
  ];
  const colunas = [
    colunaConta,
    colunaDescricao,
    { title: 'Movimento', children: [valor('DÉBITO', 'debito'), valor('CRÉDITO', 'credito')] },
    { title: 'Saldo', children: [valor('DÉBITO', 'saldo_devedor'), valor('CRÉDITO', 'saldo_credor')] },
  ];
  return (
    <Card>
      <TituloMapa
        titulo={
          <span style={{ color: 'var(--ant-color-primary, #2563eb)' }}>
            <IconeFa nome="balance-scale" /> Balancete do razão
          </span>
        }
        subtitulo={periodoDosParametros(p) ?? 'Saldos agregados pelas contas do razão (2 dígitos)'}
      />
      <FiltrosMapa
        modo="periodo"
        valoresIniciais={{ periodo: periodoPorOmissao() }}
        aCalcular={mapa.isFetching}
        aoCalcular={(q) => mapa.calcular({ ...q, nivel: 2 })}
        extra={
          <Form.Item name="excluir_estornos" valuePropName="checked" style={{ marginBottom: 8 }}>
            <Checkbox>Excluir estornos</Checkbox>
          </Form.Item>
        }
      />
      {mapa.data && (
        <>
          <Space wrap style={{ margin: '8px 0 12px' }}>
            <BotoesExportar
              obterPedido={async () =>
                mapa.data && {
                  titulo: 'Balancete do razão',
                  periodo: periodoDosParametros(p),
                  filtros: filtrosDosParametros(p),
                  conteudo: await tabelaDeColunas(colunasImpressao, mapa.data.linhas),
                }
              }
            />
            <BotaoCsv<LinhaBalancete>
              nome={`balancete_razao_${p?.data_inicio}_${p?.data_fim}`}
              linhas={mapa.data.linhas}
              colunas={[
                { titulo: 'Conta', valor: (l) => l.codigo_conta },
                { titulo: 'Descrição', valor: (l) => l.descricao },
                { titulo: 'Movimento débito', valor: (l) => l.debito, numerico: true },
                { titulo: 'Movimento crédito', valor: (l) => l.credito, numerico: true },
                { titulo: 'Saldo devedor', valor: (l) => l.saldo_devedor, numerico: true },
                { titulo: 'Saldo credor', valor: (l) => l.saldo_credor, numerico: true },
              ]}
            />
            <BotoesArvore aoExpandir={() => setAbertas(todas)} aoRecolher={() => setAbertas([])} desactivado={!todas.length} />
            {t && t.debito !== t.credito && <Tag color="red">Débito ≠ crédito: verifique os desequilíbrios</Tag>}
          </Space>
          <Table<LinhaBalancete>
            className="erp-mapa-contab erp-mapa-razao"
            rowKey="codigo_conta"
            size="small"
            bordered
            columns={colunas}
            dataSource={mapa.data.linhas}
            pagination={false}
            expandable={{
              expandedRowKeys: abertas,
              onExpandedRowsChange: (k) => setAbertas(k.map(String)),
              expandedRowRender: (r) =>
                movimento.isLoading ? (
                  <Skeleton active paragraph={{ rows: 2 }} />
                ) : (
                  <Table<LinhaBalancete>
                    rowKey="codigo_conta"
                    size="small"
                    pagination={false}
                    showHeader={false}
                    columns={colunasMovimento}
                    dataSource={porRazao.get(r.codigo_conta) ?? []}
                    locale={{ emptyText: 'Sem contas de movimento.' }}
                  />
                ),
            }}
            scroll={scrollTabela()}
            summary={() =>
              t ? (
                <Table.Summary fixed>
                  <Table.Summary.Row>
                    <Table.Summary.Cell index={0} colSpan={2} align="center">
                      <strong>Total Geral</strong>
                    </Table.Summary.Cell>
                    {(['debito', 'credito', 'saldo_devedor', 'saldo_credor'] as const).map((k, i) => (
                      <Table.Summary.Cell key={k} index={i + 2} align="right">
                        <ValorKz valor={t[k]} />
                      </Table.Summary.Cell>
                    ))}
                  </Table.Summary.Row>
                </Table.Summary>
              ) : null
            }
          />
        </>
      )}
      <DetalheConta pedido={detalhe} aoFechar={() => setDetalhe(null)} />
    </Card>
  );
}

interface ReconciliacaoAgt {
  mes: string;
  sistema: { documentos: number; base: string; iva: string };
  agt: { documentos: number; base: string; iva: string };
  contagem: Record<string, number>;
  linhas: { nif: string; nome: string; documento: string; data: string | null; base_sistema: string; iva_sistema: string; base_agt: string; iva_agt: string; estado: string; diferenca_iva: string }[];
}

const CORES_AGT: Record<string, string> = { CONCILIADO: 'green', DIVERGENTE: 'orange', FALTA_NO_SISTEMA: 'red', FALTA_NA_AGT: 'volcano' };

function SeparadorIva() {
  const { pode } = useSessao();
  const mapa = useMapa<MapaIva>('iva', '/contabilidade/relatorios/iva');
  const [mes, setMes] = useState<Dayjs | null>(null);
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [agt, setAgt] = useState<ReconciliacaoAgt | null>(null);
  const [aReconciliar, setAReconciliar] = useState(false);
  const d = mapa.data;

  const reconciliar = async () => {
    if (!mes || !ficheiro) return;
    setAReconciliar(true);
    try {
      const r = await enviarFicheiro<ReconciliacaoAgt>('/contabilidade/relatorios/iva/reconciliacao-agt', ficheiro, { mes: mes.format('YYYY-MM') });
      setAgt(r.dados);
      message.success(r.mensagem);
    } catch (e) {
      notificarErro(e, 'Não foi possível reconciliar com a AGT');
    } finally {
      setAReconciliar(false);
    }
  };

  // colunas pela ordem do legado (ui_reports.js:6542-6555): Data · Diário · NIF · Nome Terceiro · Nº Documento · Conta IVA ·
  // Desc. Conta · Descrição do Lançamento · Total Documento · Base Sujeita · IVA Débito · IVA Crédito
  const colunas: ColunaApi<LinhaIva>[] = [
    { title: 'Data', dataIndex: 'data_documento', render: formatarData },
    { title: 'Diário', dataIndex: 'diario', responsive: ['md'] },
    { title: 'NIF', dataIndex: 'nif', responsive: ['md'] },
    { title: 'Nome Terceiro', dataIndex: 'nome', ellipsis: true, width: 200, responsive: ['md'] },
    { title: 'Nº Documento', dataIndex: 'numero_documento', render: (v: string | null) => v?.trim() },
    { title: 'Conta IVA', dataIndex: 'codigo_conta' },
    { title: 'Desc. Conta', dataIndex: 'descricao_conta', ellipsis: true, responsive: ['xl'] },
    { title: 'Descrição do Lançamento', dataIndex: 'descricao', ellipsis: true, responsive: ['lg'] },
    { title: 'Total Documento', dataIndex: 'total_documento', align: 'right', render: (v: string) => <ValorKz valor={v} />, responsive: ['lg'], totalImpressao: () => formatarKz(d?.totais.total_documento) },
    { title: 'Base Sujeita', dataIndex: 'base', align: 'right', render: (v: string) => <ValorKz valor={v} />, totalImpressao: () => formatarKz(d?.totais.base) },
    { title: 'IVA Débito', dataIndex: 'iva_debito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: () => formatarKz(d?.totais.iva_debito) },
    { title: 'IVA Crédito', dataIndex: 'iva_credito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: () => formatarKz(d?.totais.iva_credito) },
  ];

  // ordem do legado (ui_reports.js:6479-6490): Estado · NIF · Nome / Entidade · Documento · Data Doc. · Base (Sist) · Base (AGT) ·
  // IVA (Sist) · IVA (AGT) · Dif. IVA
  const colunasAgt: ColunaApi<ReconciliacaoAgt['linhas'][number]>[] = [
    { title: 'Estado', dataIndex: 'estado', render: (v: string) => <Tag color={CORES_AGT[v]}>{v.replace(/_/g, ' ')}</Tag> },
    { title: 'NIF', dataIndex: 'nif' },
    { title: 'Nome / Entidade', dataIndex: 'nome' },
    { title: 'Documento', dataIndex: 'documento' },
    { title: 'Data Doc.', dataIndex: 'data', render: (v: string | null) => formatarData(v) },
    { title: 'Base (Sist)', dataIndex: 'base_sistema', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
    { title: 'Base (AGT)', dataIndex: 'base_agt', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
    { title: 'IVA (Sist)', dataIndex: 'iva_sistema', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
    { title: 'IVA (AGT)', dataIndex: 'iva_agt', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
    { title: 'Dif. IVA', dataIndex: 'diferenca_iva', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero /> },
  ];
  // IVA apurado (legado: crédito − débito; ≥ 0 «IVA a Pagar» a laranja, < 0 «IVA a Receber» a verde)
  const apurado = d ? Number(d.totais.iva_credito) - Number(d.totais.iva_debito) : 0;
  const aPagar = apurado >= 0;
  return (
    <Space direction="vertical" style={{ width: '100%' }} size={16}>
      <Card>
        <FiltrosMapa modo="periodo" periodoObrigatorio={false} aCalcular={mapa.isFetching} aoCalcular={(p) => (p.data_fim ? mapa.calcular(p) : message.warning('Indique pelo menos a data final.'))} />
        {d && (
          <>
            {/* cartões do legado (#iva-summary-cards, ui_reports.js:6499-6536): 5 por linha, pela mesma ordem e cores */}
            <GrelhaKpi larguraMinima={180} style={{ margin: '12px 0' }}>
              <CartaoKpi barra="nenhuma" icone="balance-scale" cor={aPagar ? '#d97706' : CORES_KPI.sucesso} corValor={aPagar ? '#d97706' : CORES_KPI.sucesso}
                rotulo="IVA Apurado" valor={formatarKz(Math.abs(apurado).toFixed(2))} secundaria={aPagar ? 'IVA a Pagar' : 'IVA a Receber'} />
              <CartaoKpi barra="nenhuma" icone="arrow-up" cor={CORES_KPI.primario} corValor={CORES_KPI.primario}
                rotulo="IVA Débito (Cl.345)" valor={formatarKz(d.totais.iva_debito)} secundaria="IVA liquidado / suportado" />
              <CartaoKpi barra="nenhuma" icone="arrow-down" cor="#dc2626" corValor="#dc2626"
                rotulo="IVA Crédito (Cl.345)" valor={formatarKz(d.totais.iva_credito)} secundaria="IVA dedutível / reembolsável" />
              <CartaoKpi barra="nenhuma" icone="calculator" cor="#1d4ed8" corValor="#1d4ed8"
                rotulo="Base Tributável Total" valor={formatarKz(d.totais.base)} secundaria="Valor sujeito a IVA" />
              <CartaoKpi barra="nenhuma" icone="file-invoice-dollar" cor={CORES_KPI.cinzento}
                rotulo="Volume Transaccional" valor={formatarKz(d.totais.total_documento)} secundaria={`${d.totais.linhas} lançamentos`} />
            </GrelhaKpi>
            <Space wrap style={{ marginBottom: 12 }}>
              <BotoesExportar
                obterPedido={async () => ({
                  titulo: 'Mapa de IVA',
                  periodo: periodoDosParametros(mapa.parametros),
                  filtros: filtrosDosParametros(mapa.parametros),
                  conteudo: await tabelaDeColunas(colunas, d.linhas),
                })}
              />
              <BotaoCsv<LinhaIva>
                nome="mapa_iva"
                linhas={d.linhas}
                colunas={[
                  { titulo: 'Data', valor: (l) => l.data_documento },
                  { titulo: 'Diário', valor: (l) => l.diario },
                  { titulo: 'Documento', valor: (l) => l.numero_documento?.trim() },
                  { titulo: 'Conta', valor: (l) => l.codigo_conta },
                  { titulo: 'NIF', valor: (l) => l.nif },
                  { titulo: 'Terceiro', valor: (l) => l.nome },
                  { titulo: 'Total documento', valor: (l) => l.total_documento, numerico: true },
                  { titulo: 'Base', valor: (l) => l.base, numerico: true },
                  { titulo: 'IVA débito', valor: (l) => l.iva_debito, numerico: true },
                  { titulo: 'IVA crédito', valor: (l) => l.iva_credito, numerico: true },
                ]}
              />
            </Space>
            <Table<LinhaIva> rowKey="id" size="small" columns={colunas} dataSource={d.linhas} pagination={{ pageSize: 50, showTotal: (n) => `${n} linha(s)` }} scroll={scrollTabela()} />
          </>
        )}
      </Card>
      {pode('contab_agt') && (
        <Card title="Reconciliação do IVA dedutível com a AGT">
          <Space wrap>
            <DatePicker picker="month" format="MM/YYYY" value={mes} onChange={setMes} placeholder="Mês" />
            <Upload accept=".xlsx,.xls,.csv,.txt" maxCount={1} beforeUpload={(f) => { setFicheiro(f); return false; }} onRemove={() => setFicheiro(null)} fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}>
              <Button icon={<UploadOutlined />}>Ficheiro da AGT</Button>
            </Upload>
            <Button type="primary" disabled={!mes || !ficheiro} loading={aReconciliar} onClick={() => void reconciliar()}>
              Reconciliar
            </Button>
          </Space>
          {agt && (
            <>
              {/* legado (#agt-rec-summary, ui_reports.js:6468-6473): 4 cartões com barra à esquerda */}
              <GrelhaKpi larguraMinima={180} style={{ marginTop: 16 }}>
                <CartaoKpi barra="esquerda" cor={CORES_KPI.verde} corValor={CORES_KPI.verde} rotulo="Conciliados" valor={agt.contagem.CONCILIADO ?? 0} />
                <CartaoKpi barra="esquerda" cor={CORES_KPI.laranja} corValor={CORES_KPI.laranja} rotulo="Divergentes" valor={agt.contagem.DIVERGENTE ?? 0} />
                <CartaoKpi barra="esquerda" cor={CORES_KPI.azul} corValor={CORES_KPI.azul} rotulo="Falta no Sist." valor={agt.contagem.FALTA_NO_SISTEMA ?? 0} />
                <CartaoKpi barra="esquerda" cor={CORES_KPI.vermelho} corValor={CORES_KPI.vermelho} rotulo="Falta na AGT" valor={agt.contagem.FALTA_NA_AGT ?? 0} />
              </GrelhaKpi>
              <Descriptions size="small" bordered column={{ xs: 1, md: 3 }} style={{ margin: '16px 0' }}>
                <Descriptions.Item label="Sistema">{agt.sistema.documentos} doc. · base {formatarKz(agt.sistema.base)} · IVA {formatarKz(agt.sistema.iva)}</Descriptions.Item>
                <Descriptions.Item label="AGT">{agt.agt.documentos} doc. · base {formatarKz(agt.agt.base)} · IVA {formatarKz(agt.agt.iva)}</Descriptions.Item>
                <Descriptions.Item label="Resultado">
                  <Space wrap>
                    {Object.entries(agt.contagem).map(([k, n]) => (
                      <Tag key={k} color={CORES_AGT[k]}>{k.replace(/_/g, ' ').toLowerCase()}: {n}</Tag>
                    ))}
                  </Space>
                </Descriptions.Item>
              </Descriptions>
              <Flex justify="end" style={{ marginBottom: 8 }}>
                <BotoesExportar
                  tamanho="small"
                  obterPedido={async () => ({
                    titulo: 'Reconciliação do IVA dedutível com a AGT',
                    periodo: `Mês ${agt.mes}`,
                    filtros: Object.entries(agt.contagem).map(([k, n]) => `${k.replace(/_/g, ' ').toLowerCase()}: ${n}`),
                    conteudo:
                      pares([
                        ['Sistema', `${agt.sistema.documentos} doc. · base ${formatarKz(agt.sistema.base)} · IVA ${formatarKz(agt.sistema.iva)}`],
                        ['AGT', `${agt.agt.documentos} doc. · base ${formatarKz(agt.agt.base)} · IVA ${formatarKz(agt.agt.iva)}`],
                      ], 2) + (await tabelaDeColunas(colunasAgt, agt.linhas)),
                  })}
                />
              </Flex>
              <Table
                rowKey={(r, i) => `${r.nif}|${r.documento}|${i}`}
                size="small"
                dataSource={agt.linhas}
                pagination={{ pageSize: 50 }}
                scroll={scrollTabela()}
                columns={colunasAgt}
              />
            </>
          )}
        </Card>
      )}
    </Space>
  );
}
