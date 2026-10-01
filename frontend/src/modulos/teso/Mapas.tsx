import { Alert, Button, Card, DatePicker, Space, Statistic, Table, Tabs, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { Balancete, LinhaBalancete, MovimentoRazao, Razao } from '../contab/api';
import { BotaoCsv, ValorKz } from '../contab/comum/Componentes';
import { SeletorContaFinanceira } from './comum';

/**
 * Tesouraria › Extractos e mapas de disponibilidades (ecrã teso_gestao_mapas). Não há endpoint próprio de tesouraria:
 * usa o balancete (contas 43/45) e o razão da Contabilidade, que exigem permissão de consulta dos mapas contabilísticos.
 */
export default function Mapas() {
  const [periodo, setPeriodo] = useState<[Dayjs, Dayjs]>([dayjs().startOf('year'), dayjs()]);
  const [conta, setConta] = useState<string>();
  const [separador, setSeparador] = useState('saldos');
  const p = { data_inicio: dataApi(periodo[0]), data_fim: dataApi(periodo[1]) };

  const saldos = useQuery({
    queryKey: ['teso', 'mapas', 'saldos', p],
    queryFn: () => obter<Balancete>('/contabilidade/relatorios/balancete', { ...p, filtro_contas: '43*,45*' }),
    retry: false,
  });
  const razao = useQuery({
    queryKey: ['teso', 'mapas', 'razao', conta, p],
    queryFn: () => obter<Razao>('/contabilidade/relatorios/razao', { ...p, codigo_conta: conta }),
    enabled: !!conta,
    retry: false,
  });
  const semAcesso = [saldos.error, razao.error].some((e) => e instanceof ErroApi && e.estado === 403);
  const linhasSaldo = (saldos.data?.linhas ?? []).filter((l) => /^4[35]/.test(l.codigo_conta));

  return (
    <>
      <CabecalhoPagina
        titulo="Extractos e disponibilidades"
        subtitulo="Saldos de bancos e caixa e extracto por conta"
        accoes={<DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} allowClear={false} onChange={(v) => v?.[0] && v[1] && setPeriodo([v[0], v[1]])} />}
      />
      {semAcesso && <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="Estes mapas usam o balancete e o razão da Contabilidade: peça a permissão de consulta dos mapas contabilísticos." />}
      <Tabs
        activeKey={separador}
        onChange={setSeparador}
        items={[
          {
            key: 'saldos',
            label: 'Disponibilidades',
            children: (
              <Card extra={<BotaoCsv<LinhaBalancete> nome="disponibilidades" linhas={linhasSaldo} colunas={[{ titulo: 'Conta', valor: (l) => l.codigo_conta }, { titulo: 'Descrição', valor: (l) => l.descricao }, { titulo: 'Saldo inicial', valor: (l) => l.saldo_inicial, numerico: true }, { titulo: 'Entradas', valor: (l) => l.debito, numerico: true }, { titulo: 'Saídas', valor: (l) => l.credito, numerico: true }, { titulo: 'Saldo final', valor: (l) => l.saldo_final, numerico: true }]} />}>
                <Table<LinhaBalancete>
                  rowKey="codigo_conta"
                  size="small"
                  loading={saldos.isFetching}
                  dataSource={linhasSaldo}
                  pagination={false}
                  onRow={(l) => ({ onClick: () => { setConta(l.codigo_conta); setSeparador('extrato'); }, style: { cursor: 'pointer' } })}
                  columns={[
                    { title: 'Conta', dataIndex: 'codigo_conta' },
                    { title: 'Descrição', dataIndex: 'descricao' },
                    { title: 'Saldo inicial', dataIndex: 'saldo_inicial', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                    { title: 'Entradas', dataIndex: 'debito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero /> },
                    { title: 'Saídas', dataIndex: 'credito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero /> },
                    { title: 'Saldo final', dataIndex: 'saldo_final', align: 'right', render: (v: string) => <ValorKz valor={v} forte /> },
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
                  <Button onClick={() => void razao.refetch()} disabled={!conta}>Actualizar</Button>
                </Space>
                {razao.data && (
                  <>
                    <Space size={32} wrap style={{ marginBottom: 12 }}>
                      <Statistic title="Saldo inicial" value={formatarKz(razao.data.saldo_inicial)} />
                      <Statistic title="Entradas" value={formatarKz(razao.data.debito)} />
                      <Statistic title="Saídas" value={formatarKz(razao.data.credito)} />
                      <Statistic title="Saldo final" value={formatarKz(razao.data.saldo_final)} />
                      <BotaoCsv<MovimentoRazao> nome={`extracto_${conta}`} linhas={razao.data.movimentos} colunas={[{ titulo: 'Data', valor: (l) => l.data_documento }, { titulo: 'Lançamento', valor: (l) => l.numero_lan }, { titulo: 'Documento', valor: (l) => l.numero_documento }, { titulo: 'Descrição', valor: (l) => l.descricao }, { titulo: 'Entrada', valor: (l) => (l.tipo_dc === 'D' ? l.valor : ''), numerico: true }, { titulo: 'Saída', valor: (l) => (l.tipo_dc === 'C' ? l.valor : ''), numerico: true }, { titulo: 'Saldo', valor: (l) => l.saldo, numerico: true }]} />
                    </Space>
                    <Table<MovimentoRazao>
                      rowKey="id"
                      size="small"
                      dataSource={razao.data.movimentos}
                      pagination={{ pageSize: 50 }}
                      scroll={{ x: 'max-content' }}
                      columns={[
                        { title: 'Data', dataIndex: 'data_documento', render: formatarData },
                        { title: 'Diário', dataIndex: 'diario' },
                        { title: 'Lançamento', dataIndex: 'numero_lan' },
                        { title: 'Documento', dataIndex: 'numero_documento' },
                        { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300 },
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
        ]}
      />
    </>
  );
}
