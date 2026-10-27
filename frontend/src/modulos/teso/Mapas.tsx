import { Alert, Button, Card, Col, DatePicker, Flex, Row, Select, Space, Table, Tabs, Tag, Typography, theme } from 'antd';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { BotoesExportar } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { pedidoDisponibilidades, pedidoExtrato } from './impressao';
import { MapaPendentes } from './MapaPendentes';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { BotaoCsv, ValorKz } from '../contab/comum/Componentes';
import type { Disponibilidades, ExtratoConta } from './api';
import { SeletorContaFinanceira } from './comum';
import { useContasCartaoMapa } from './contaFinanceira';
import { extratoConfere } from './regras';
import { scrollTabela } from '@/componentes/responsivo';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';

type LinhaDisponivel = Disponibilidades['contas'][number];
type MovimentoExtrato = ExtratoConta['movimentos'][number];
/** Linha da tabela do extracto: os movimentos e, à cabeça, a linha «SALDO ANTERIOR AO PERÍODO» do legado. */
export type LinhaExtrato = MovimentoExtrato & { saldoAnterior?: true };

/** Linhas do extracto como no legado (ui_tesouraria.js:3562-3570): a 1.ª é o saldo anterior ao período, só com o saldo. */
export function linhasExtrato(e: ExtratoConta): LinhaExtrato[] {
  const anterior: LinhaExtrato = {
    id: -1, saldoAnterior: true, data_documento: e.data_inicio, numero_lan: null, numero_documento: null, descricao: null, tipo_dc: 'D', valor: '0',
    saldo: e.saldo_inicial, diario: null, terceiro_id: null, terceiro: null, estorno_de_id: null, estornado_por_id: null,
  };
  return [anterior, ...e.movimentos];
}

/**
 * Tipos de Mapa do legado (select teso-map-type): o prefixo da conta. Todos geram o extracto da conta (razão com saldo
 * corrido). CARTAO não é um prefixo — lista as contas marcadas como cartão de tesouraria (classe 3).
 */
export type TipoMapa = '43' | '45' | 'CARTAO';
export const TIPOS_MAPA: { valor: TipoMapa; rotulo: string; titulo: string }[] = [
  { valor: '43', rotulo: 'Extrato Bancário (Contas 43)', titulo: 'Extrato Bancário' },
  { valor: '45', rotulo: 'Folha de Caixa (Contas 45)', titulo: 'Folha de Caixa' },
  { valor: 'CARTAO', rotulo: 'Extrato de Cartão (classe 3)', titulo: 'Extrato de Cartão' },
];
const PREFIXOS_MAPA: Record<'43' | '45', string[]> = { '43': ['43'], '45': ['45'] };

/** A conta combina com o tipo de mapa escolhido? CARTAO depende só da marcação da conta, os outros do prefixo. */
function contaCombinaComTipoMapa(conta: string, tipo: TipoMapa, cartao: boolean): boolean {
  return tipo === 'CARTAO' ? cartao : conta.startsWith(tipo);
}

/** Pedido do «Gerar Mapa»: conta e período (nulo sem conta ou com datas trocadas). */
export function pedidoMapa(conta: string | undefined, inicio: Dayjs, fim: Dayjs): { conta: string; periodo: [Dayjs, Dayjs] } | null {
  if (!conta || fim.isBefore(inicio, 'day')) return null;
  return { conta, periodo: [inicio.startOf('day'), fim.startOf('day')] };
}

/**
 * Tesouraria › Extractos e mapas de disponibilidades (ecrã teso_gestao_mapas). Endpoints próprios da Tesouraria com a
 * permissão do ecrã (ADR-064): GET /tesouraria/disponibilidades (saldos 43/45 à data) e GET /tesouraria/extrato-conta
 * (razão da conta com saldo corrido). Os valores são os do balancete e do razão da Contabilidade.
 */
export default function Mapas() {
  const [tipoMapa, setTipoMapa] = useState<TipoMapa>('43');
  const [contaForm, setContaForm] = useState<string>();
  // por omissão o mês corrente, do 1.º ao último dia (como o legado)
  const [dataIni, setDataIni] = useState<Dayjs>(() => dayjs().startOf('month'));
  const [dataFim, setDataFim] = useState<Dayjs>(() => dayjs().endOf('month').startOf('day'));
  const [data, setData] = useState<Dayjs>(dayjs());
  const [periodo, setPeriodo] = useState<[Dayjs, Dayjs]>([dayjs().startOf('month'), dayjs()]);
  const [conta, setConta] = useState<string>();
  const { token } = theme.useToken();
  const { pode } = useSessao();
  const cartoes = useContasCartaoMapa();
  const [separador, setSeparador] = useSeparadorUrl(pode('teso_gestao_pagamentos_view') ? ['saldos', 'extrato', 'pendentes'] : ['saldos', 'extrato']);
  const p = { data_inicio: dataApi(periodo[0]), data_fim: dataApi(periodo[1]) };

  const saldos = useQuery({
    queryKey: ['teso', 'mapas', 'disponibilidades', dataApi(data)],
    queryFn: () => obter<Disponibilidades>('/tesouraria/disponibilidades', { data: dataApi(data) }),
    retry: false,
  });
  const extrato = useQuery({
    queryKey: ['teso', 'mapas', 'extrato', conta, p],
    queryFn: () => obter<ExtratoConta>('/tesouraria/extrato-conta', { ...p, codigo_conta: conta }),
    enabled: !!conta,
    retry: false,
  });
  const erro = saldos.error ?? extrato.error;
  const e = extrato.data;

  return (
    <>
      {/* formulário de entrada do legado (renderMapasTesouraria): Tipo de Mapa, Conta, Data Inicial, Data Final, «Gerar Mapa» */}
      <Card style={{ marginBottom: 16 }} styles={{ body: { background: token.colorFillAlter } }}>
        <h3 style={{ marginTop: 0, color: token.colorPrimary, display: 'flex', alignItems: 'center', gap: 8 }}><IconeFa nome="print" /> Emissão de Mapas de Tesouraria</h3>
        <Row gutter={[12, 12]} align="bottom">
          <Col xs={24} sm={12} lg={5}>
            <label style={{ fontWeight: 600, fontSize: 13 }}>Tipo de Mapa</label>
            <Select<TipoMapa> style={{ width: '100%' }} value={tipoMapa} aria-label="Tipo de Mapa" options={TIPOS_MAPA.map((t) => ({ value: t.valor, label: t.rotulo }))}
              onChange={(v) => { setTipoMapa(v); if (contaForm && !contaCombinaComTipoMapa(contaForm, v, cartoes.has(contaForm))) setContaForm(undefined); }} />
          </Col>
          <Col xs={24} sm={12} lg={7}>
            <label style={{ fontWeight: 600, fontSize: 13 }}>Conta</label>
            <SeletorContaFinanceira key={tipoMapa} value={contaForm} onChange={setContaForm} prefixos={tipoMapa === 'CARTAO' ? [] : PREFIXOS_MAPA[tipoMapa]} comCartao={tipoMapa === 'CARTAO'} placeholder="Nenhuma conta seleccionada" style={{ width: '100%' }} />
          </Col>
          <Col xs={12} sm={8} lg={4}>
            <label style={{ fontWeight: 600, fontSize: 13 }}>Data Inicial</label>
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} value={dataIni} allowClear={false} onChange={(v) => v && setDataIni(v)} aria-label="Data Inicial" />
          </Col>
          <Col xs={12} sm={8} lg={4}>
            <label style={{ fontWeight: 600, fontSize: 13 }}>Data Final</label>
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} value={dataFim} allowClear={false} onChange={(v) => v && setDataFim(v)} aria-label="Data Final" />
          </Col>
          <Col xs={24} sm={8} lg={4}>
            <Button type="primary" block icon={<IconeFa nome="sync" />} disabled={!contaForm || dataFim.isBefore(dataIni)}
              onClick={() => {
                const p = pedidoMapa(contaForm, dataIni, dataFim);
                if (!p) return;
                const igual = p.conta === conta && p.periodo[0].isSame(periodo[0], 'day') && p.periodo[1].isSame(periodo[1], 'day');
                setConta(p.conta);
                setPeriodo(p.periodo);
                setSeparador('extrato');
                if (igual) void extrato.refetch();
              }}>
              Gerar Mapa
            </Button>
          </Col>
        </Row>
      </Card>
      {erro && (
        <Alert type="error" showIcon style={{ marginBottom: 16 }} message="Não foi possível obter o mapa." action={<Button size="small" onClick={() => notificarErro(erro)}>Detalhe</Button>} />
      )}
      <Tabs
        activeKey={separador}
        onChange={setSeparador}
        items={[
          {
            key: 'saldos',
            label: 'Disponibilidades',
            children: (
              <Card
                title={
                  <Space wrap>
                    <span>Saldos em</span>
                    <DatePicker format="DD/MM/YYYY" value={data} allowClear={false} onChange={(v) => v && setData(v)} />
                  </Space>
                }
                extra={
                  <Space wrap>
                  <BotoesExportar desactivado={!saldos.data} obterPedido={() => (saldos.data ? pedidoDisponibilidades(saldos.data) : null)} />
                  <BotaoCsv<LinhaDisponivel>
                    nome={`disponibilidades_${dataApi(data)}`}
                    linhas={saldos.data?.contas ?? []}
                    colunas={[
                      { titulo: 'Conta', valor: (l) => l.codigo_conta },
                      { titulo: 'Descrição', valor: (l) => l.descricao },
                      { titulo: 'Tipo', valor: (l) => (l.tipo === 'CAIXA' ? 'Caixa' : 'Banco') },
                      { titulo: 'Meio de pagamento', valor: (l) => l.meio_pagamento },
                      { titulo: 'Saldo', valor: (l) => l.saldo, numerico: true },
                    ]}
                  />
                  </Space>
                }
              >
                {saldos.data && (
                  <div style={{ marginBottom: 12 }}>
                    {/* só no sistema novo; cartões com as cores dos meios de pagamento (bancos / caixa) */}
                    <GrelhaKpi larguraMinima={200}>
                      <CartaoKpi rotulo="Bancos (43)" valor={formatarKz(saldos.data.totais.bancos)} unidade="Kz" icone="university" cor="#0891b2" />
                      <CartaoKpi rotulo="Caixa (45)" valor={formatarKz(saldos.data.totais.caixa)} unidade="Kz" icone="coins" cor="#059669" />
                      <CartaoKpi rotulo="Total disponível" valor={formatarKz(saldos.data.totais.total)} unidade="Kz" icone="wallet" cor="primario" />
                    </GrelhaKpi>
                  </div>
                )}
                <Table<LinhaDisponivel>
                  rowKey="codigo_conta"
                  size="small"
                  loading={saldos.isFetching}
                  dataSource={saldos.data?.contas ?? []}
                  pagination={false}
                  scroll={scrollTabela()}
                  onRow={(l) => ({ onClick: () => { setConta(l.codigo_conta); setSeparador('extrato'); }, style: { cursor: 'pointer' } })}
                  columns={[
                    { title: 'Conta', dataIndex: 'codigo_conta' },
                    { title: 'Descrição', dataIndex: 'descricao' },
                    { title: 'Tipo', dataIndex: 'tipo', responsive: ['sm'], render: (t: string) => (t === 'CAIXA' ? <Tag color="gold">Caixa</Tag> : <Tag color="blue">Banco</Tag>) },
                    { title: 'Meio de pagamento', dataIndex: 'meio_pagamento', responsive: ['md'], render: (v: string | null, l) => (v ? `${v}${l.codigo_moeda && l.codigo_moeda !== 'AOA' ? ` (${l.codigo_moeda})` : ''}` : '—') },
                    { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} forte /> },
                  ]}
                />
                <Typography.Text type="secondary">Clique numa conta para ver o extracto.</Typography.Text>
              </Card>
            ),
          },
          {
            key: 'extrato',
            label: 'Extracto da conta',
            children: (
              <Card>
                <Space style={{ marginBottom: 12 }} wrap>
                  <SeletorContaFinanceira value={conta} onChange={setConta} comCartao />
                  <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} allowClear={false} onChange={(v) => v?.[0] && v[1] && setPeriodo([v[0], v[1]])} />
                  <Button onClick={() => void extrato.refetch()} disabled={!conta}>Actualizar</Button>
                </Space>
                {e && (
                  <>
                    {/* cabeçalho do mapa do legado (ui_tesouraria.js:3532-3546): título, conta, período, n.º de movimentos e, à direita, Imprimir/Extrair/Excel */}
                    <Flex wrap gap={12} justify="space-between" align="flex-end" style={{ marginBottom: 16, paddingBottom: 12, borderBottom: `2px solid ${token.colorBorderSecondary}` }}>
                      <div>
                        <h2 style={{ margin: 0, color: token.colorPrimary }}>{cartoes.has(e.codigo_conta) ? 'Extrato de Cartão' : e.codigo_conta.startsWith('45') ? 'Folha de Caixa' : 'Extrato Bancário'}</h2>
                        <p style={{ margin: 0, fontSize: 17, fontWeight: 600 }}>{e.codigo_conta}{e.descricao ? ` - ${e.descricao}` : ''}</p>
                        <p style={{ margin: '3px 0 0', color: token.colorTextSecondary }}>Periodo: {formatarData(e.data_inicio)} a {formatarData(e.data_fim)}</p>
                        <div style={{ marginTop: 8, fontSize: 13, color: token.colorTextSecondary }}>Exibindo <strong style={{ color: token.colorPrimary }}>{e.movimentos.length}</strong> movimentos</div>
                      </div>
                      <Space wrap>
                        <BotoesExportar desactivado={!e} obterPedido={() => (e ? pedidoExtrato(e) : null)} />
                        <BotaoCsv<MovimentoExtrato>
                          nome={`extracto_${e.codigo_conta}`}
                          linhas={e.movimentos}
                          colunas={[
                            { titulo: 'Data', valor: (l) => l.data_documento },
                            { titulo: 'Lançamento', valor: (l) => l.numero_lan },
                            { titulo: 'Documento', valor: (l) => l.numero_documento },
                            { titulo: 'Terceiro', valor: (l) => l.terceiro?.nome ?? '' },
                            { titulo: 'Descrição', valor: (l) => l.descricao },
                            { titulo: 'Entrada', valor: (l) => (l.tipo_dc === 'D' ? l.valor : ''), numerico: true },
                            { titulo: 'Saída', valor: (l) => (l.tipo_dc === 'C' ? l.valor : ''), numerico: true },
                            { titulo: 'Saldo', valor: (l) => l.saldo, numerico: true },
                          ]}
                        />
                      </Space>
                    </Flex>
                    {!extratoConfere(e) && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="O saldo corrido não confere com o saldo final; actualize o extracto." />}
                    {/* colunas do legado pela ordem (ui_tesouraria.js:3551-3580): «SALDO ANTERIOR AO PERÍODO» na 1.ª linha e
                        «TOTAIS DO PERÍODO» no rodapé, alinhados com Débito / Crédito / Saldo; Diário e Lançamento só no sistema novo */}
                    <Table<LinhaExtrato>
                      rowKey="id"
                      size="small"
                      dataSource={linhasExtrato(e)}
                      pagination={{ pageSize: 50 }}
                      scroll={scrollTabela()}
                      onRow={(l) => (l.saldoAnterior ? { style: { fontStyle: 'italic', background: token.colorFillAlter } } : {})}
                      columns={[
                        { title: 'Data', dataIndex: 'data_documento', render: (v: string, l) => (l.saldoAnterior ? null : formatarData(v)) },
                        { title: 'Referência', dataIndex: 'numero_documento', render: (v: string | null, l) => (l.saldoAnterior ? null : <span style={{ color: token.colorPrimary, fontWeight: 600 }}>{v ?? `#${l.id}`}</span>) },
                        { title: 'Contrapartida', key: 'contrapartidas', render: (_, l) => (l.saldoAnterior ? null : <Typography.Text type="secondary" style={{ fontSize: 12.8 }}>{l.contrapartidas?.length ? l.contrapartidas.join(' / ') : '-'}</Typography.Text>) },
                        { title: 'Terceiro', key: 'terceiro', render: (_, l) => (l.saldoAnterior ? null : <Typography.Text type="secondary" style={{ fontSize: 12.8 }}>{l.terceiro?.nome?.trim() ?? ''}</Typography.Text>) },
                        {
                          title: 'Descrição / histórico', dataIndex: 'descricao', ellipsis: true, width: 300,
                          onCell: (l) => (l.saldoAnterior ? { style: { textAlign: 'right', fontWeight: 600 } } : {}),
                          render: (v: string | null, l) => (l.saldoAnterior ? 'SALDO ANTERIOR AO PERÍODO:' : v),
                        },
                        { title: 'Débito', align: 'right', render: (_, l) => (l.saldoAnterior ? null : l.tipo_dc === 'D' ? formatarKz(l.valor) : '-') },
                        { title: 'Crédito', align: 'right', render: (_, l) => (l.saldoAnterior ? null : l.tipo_dc === 'C' ? formatarKz(l.valor) : '-') },
                        { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string, l) => <strong style={{ color: l.saldoAnterior ? undefined : Number(v) < 0 ? token.colorError : token.colorPrimary, fontWeight: l.saldoAnterior ? 700 : 600, whiteSpace: 'nowrap' }}>{formatarKz(v)}</strong> },
                        { title: 'Estado Recon.', key: 'recon', align: 'center', render: (_, l) => (l.saldoAnterior ? null : l.reconciliacao_codigo ? <Tag color="green">Conciliado{l.reconciliacao_data ? ` · ${formatarData(l.reconciliacao_data)}` : ''}</Tag> : <Tag>Por conciliar</Tag>) },
                        { title: 'Diário', dataIndex: 'diario', render: (v: string | null, l) => (l.saldoAnterior ? null : v) },
                        { title: 'Lançamento', dataIndex: 'numero_lan', render: (v: string | null, l) => (l.saldoAnterior ? null : v) },
                      ]}
                      summary={() => (
                        <Table.Summary>
                          <Table.Summary.Row className="erp-linha-total">
                            <Table.Summary.Cell index={0} colSpan={5} align="right">TOTAIS DO PERÍODO:</Table.Summary.Cell>
                            <Table.Summary.Cell index={5} align="right">{formatarKz(e.debito)}</Table.Summary.Cell>
                            <Table.Summary.Cell index={6} align="right">{formatarKz(e.credito)}</Table.Summary.Cell>
                            <Table.Summary.Cell index={7} align="right"><span style={{ fontSize: 16 }}>{formatarKz(e.saldo_final)}</span></Table.Summary.Cell>
                            <Table.Summary.Cell index={8} colSpan={3} />
                          </Table.Summary.Row>
                        </Table.Summary>
                      )}
                    />
                  </>
                )}
              </Card>
            ),
          },
          ...(pode('teso_gestao_pagamentos_view') ? [{ key: 'pendentes', label: 'Pendentes', children: <MapaPendentes /> }] : []),
        ]}
      />
    </>
  );
}
