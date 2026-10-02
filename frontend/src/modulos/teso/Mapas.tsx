import { Alert, Button, Card, DatePicker, Space, Statistic, Table, Tabs, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { pedidoDisponibilidades, pedidoExtrato } from './impressao';
import { MapaPendentes } from './MapaPendentes';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { BotaoCsv, ValorKz } from '../contab/comum/Componentes';
import type { Disponibilidades, ExtratoConta } from './api';
import { SeletorContaFinanceira } from './comum';
import { extratoConfere } from './regras';
import { scrollTabela } from '@/componentes/responsivo';

type LinhaDisponivel = Disponibilidades['contas'][number];
type MovimentoExtrato = ExtratoConta['movimentos'][number];

/**
 * Tesouraria › Extractos e mapas de disponibilidades (ecrã teso_gestao_mapas). Endpoints próprios da Tesouraria com a
 * permissão do ecrã (ADR-064): GET /tesouraria/disponibilidades (saldos 43/45 à data) e GET /tesouraria/extrato-conta
 * (razão da conta com saldo corrido). Os valores são os do balancete e do razão da Contabilidade.
 */
export default function Mapas() {
  const [data, setData] = useState<Dayjs>(dayjs());
  const [periodo, setPeriodo] = useState<[Dayjs, Dayjs]>([dayjs().startOf('month'), dayjs()]);
  const [conta, setConta] = useState<string>();
  const [separador, setSeparador] = useState('saldos');
  const { pode } = useSessao();
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
      <CabecalhoPagina titulo="Extractos e disponibilidades" subtitulo="Saldos de bancos (43) e caixa (45) e extracto por conta" />
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
                  <Space size={32} wrap style={{ marginBottom: 12 }}>
                    <Statistic title="Bancos (43)" value={formatarKz(saldos.data.totais.bancos)} />
                    <Statistic title="Caixa (45)" value={formatarKz(saldos.data.totais.caixa)} />
                    <Statistic title="Total disponível" value={formatarKz(saldos.data.totais.total)} />
                  </Space>
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
                  <SeletorContaFinanceira value={conta} onChange={setConta} />
                  <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} allowClear={false} onChange={(v) => v?.[0] && v[1] && setPeriodo([v[0], v[1]])} />
                  <Button onClick={() => void extrato.refetch()} disabled={!conta}>Actualizar</Button>
                  <BotoesExportar desactivado={!e} obterPedido={() => (e ? pedidoExtrato(e) : null)} />
                </Space>
                {e && (
                  <>
                    <Space size={32} wrap style={{ marginBottom: 12 }}>
                      <Statistic title="Saldo inicial" value={formatarKz(e.saldo_inicial)} />
                      <Statistic title="Entradas" value={formatarKz(e.debito)} />
                      <Statistic title="Saídas" value={formatarKz(e.credito)} />
                      <Statistic title="Saldo final" value={formatarKz(e.saldo_final)} />
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
                    {!extratoConfere(e) && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="O saldo corrido não confere com o saldo final; actualize o extracto." />}
                    <Table<MovimentoExtrato>
                      rowKey="id"
                      size="small"
                      dataSource={e.movimentos}
                      pagination={{ pageSize: 50 }}
                      scroll={scrollTabela()}
                      columns={[
                        { title: 'Data', dataIndex: 'data_documento', render: formatarData },
                        { title: 'Diário', dataIndex: 'diario', responsive: ['lg'] },
                        { title: 'Lançamento', dataIndex: 'numero_lan', responsive: ['md'] },
                        { title: 'Documento', dataIndex: 'numero_documento' },
                        { title: 'Terceiro', key: 'terceiro', responsive: ['md'], render: (_, l) => l.terceiro?.nome?.trim() ?? '—' },
                        { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300, responsive: ['lg'] },
                        { title: 'Entrada', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : null) },
                        { title: 'Saída', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : null) },
                        { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                      ]}
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
