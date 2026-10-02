import { Alert, Button, Card, Checkbox, DatePicker, Descriptions, Flex, Form, InputNumber, Space, Statistic, Table, Tabs, Tag, Typography, Upload, message } from 'antd';
import { UploadOutlined } from '@ant-design/icons';
import type { Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, pares } from '@/componentes/impressao';
import { scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { Balancete, LinhaBalancete, LinhaIva, MapaIva, MovimentoRazao, Razao } from '../api';
import { BotaoCsv, ValorKz } from '../comum/Componentes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { enviarFicheiro } from '../comum/ficheiros';
import { filtrosDosParametros, periodoDosParametros, tabelaDeColunas } from '../comum/impressao';
import { SeletorConta } from '../comum/Seletores';
import { rotuloTerceiro } from '../comum/terceiro';
import { useAbrirLancamento, useMapa } from '../comum/useMapa';

/** Mapas › Balancete, razão e IVA (ecrã contab_mapa_balancete). */
export default function MapaBalancete() {
  const [separador, setSeparador] = useState('balancete');
  const [contaRazao, setContaRazao] = useState<{ conta: string; inicio?: string; fim?: string } | null>(null);
  return (
    <>
      <CabecalhoPagina titulo="Balancete, razão e IVA" subtitulo="Mapas contabilísticos por período" />
      <Tabs
        activeKey={separador}
        onChange={setSeparador}
        items={[
          {
            key: 'balancete',
            label: 'Balancete',
            children: (
              <SeparadorBalancete
                aoAbrirConta={(conta, inicio, fim) => {
                  setContaRazao({ conta, inicio, fim });
                  setSeparador('razao');
                }}
              />
            ),
          },
          { key: 'razao', label: 'Razão (extracto da conta)', children: <SeparadorRazao inicial={contaRazao} /> },
          { key: 'iva', label: 'Mapa de IVA', children: <SeparadorIva /> },
        ]}
      />
    </>
  );
}

function SeparadorBalancete({ aoAbrirConta }: { aoAbrirConta: (conta: string, inicio?: string, fim?: string) => void }) {
  const mapa = useMapa<Balancete>('balancete', '/contabilidade/relatorios/balancete');
  const p = mapa.parametros;
  const pequeno = useEcraPequeno();
  const t = mapa.data?.totais;
  const total = (k: string) => () => formatarKz(t?.[k]);
  const colunas: ColunaApi<LinhaBalancete>[] = [
    {
      title: 'Conta',
      dataIndex: 'codigo_conta',
      fixed: pequeno ? undefined : 'left',
      render: (v: string) => <Typography.Link onClick={() => aoAbrirConta(v, p?.data_inicio as string, p?.data_fim as string)}>{v}</Typography.Link>,
      valorImpressao: (l) => l.codigo_conta,
    },
    { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null, r) => `${v ?? ''}${r.terceiro ? ` · ${r.terceiro.trim()}` : ''}` },
    { title: 'Saldo inicial', dataIndex: 'saldo_inicial', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, responsive: ['md'], totalImpressao: total('saldo_inicial') },
    { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: total('debito') },
    { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: total('credito') },
    { title: 'Saldo devedor', dataIndex: 'saldo_devedor', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: total('saldo_devedor') },
    { title: 'Saldo credor', dataIndex: 'saldo_credor', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: total('saldo_credor') },
  ];
  return (
    <Card>
      <FiltrosMapa
        modo="periodo"
        aCalcular={mapa.isFetching}
        aoCalcular={mapa.calcular}
        extra={
          <>
            <Form.Item name="nivel" label="Nível" tooltip="N.º de dígitos da conta (vazio = todas as contas de movimento)" style={{ marginBottom: 8 }}>
              <InputNumber min={1} max={20} style={{ width: 90 }} />
            </Form.Item>
            <Form.Item name="totalizadoras" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Com totalizadoras</Checkbox>
            </Form.Item>
            <Form.Item name="por_terceiro" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Por terceiro</Checkbox>
            </Form.Item>
            <Form.Item name="so_movimento" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Só com movimento</Checkbox>
            </Form.Item>
            <Form.Item name="sem_saldo_zero" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Sem saldo zero</Checkbox>
            </Form.Item>
            <Form.Item name="sem_saldo_inicial" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Sem saldo inicial</Checkbox>
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
                  conteudo: await tabelaDeColunas(colunas, mapa.data.linhas),
                }
              }
            />
            <BotaoCsv<LinhaBalancete>
              nome={`balancete_${p?.data_inicio}_${p?.data_fim}`}
              linhas={mapa.data.linhas}
              colunas={[
                { titulo: 'Conta', valor: (l) => l.codigo_conta },
                { titulo: 'Descrição', valor: (l) => l.descricao },
                { titulo: 'Terceiro', valor: (l) => l.terceiro?.trim() },
                { titulo: 'Saldo inicial', valor: (l) => l.saldo_inicial, numerico: true },
                { titulo: 'Débito', valor: (l) => l.debito, numerico: true },
                { titulo: 'Crédito', valor: (l) => l.credito, numerico: true },
                { titulo: 'Saldo devedor', valor: (l) => l.saldo_devedor, numerico: true },
                { titulo: 'Saldo credor', valor: (l) => l.saldo_credor, numerico: true },
              ]}
            />
            {t && t.debito !== t.credito && <Tag color="red">Débito ≠ crédito: verifique os desequilíbrios</Tag>}
          </Space>
          <Table<LinhaBalancete>
            rowKey={(r) => `${r.codigo_conta}|${r.terceiro_id ?? ''}`}
            size="small"
            columns={colunas}
            dataSource={mapa.data.linhas}
            pagination={{ pageSize: 100, showSizeChanger: true, showTotal: (n) => `${n} conta(s)` }}
            scroll={scrollTabela()}
            summary={() =>
              t ? (
                <Table.Summary fixed>
                  <Table.Summary.Row>
                    <Table.Summary.Cell index={0} colSpan={2}>
                      <strong>Totais</strong>
                    </Table.Summary.Cell>
                    {(pequeno ? ['debito', 'credito', 'saldo_devedor', 'saldo_credor'] : ['saldo_inicial', 'debito', 'credito', 'saldo_devedor', 'saldo_credor']).map((k, i) => (
                      <Table.Summary.Cell key={k} index={i + 2} align="right">
                        <ValorKz valor={t[k]} forte />
                      </Table.Summary.Cell>
                    ))}
                  </Table.Summary.Row>
                </Table.Summary>
              ) : null
            }
          />
        </>
      )}
    </Card>
  );
}

function SeparadorRazao({ inicial }: { inicial: { conta: string; inicio?: string; fim?: string } | null }) {
  const mapa = useMapa<Razao>('razao', '/contabilidade/relatorios/razao');
  const abrir = useAbrirLancamento();
  const [conta, setConta] = useState<string | undefined>(inicial?.conta);
  const { calcular } = mapa;
  // conta escolhida no balancete: actualiza o selector e calcula com o mesmo período
  useEffect(() => {
    if (!inicial) return;
    setConta(inicial.conta);
    if (inicial.inicio && inicial.fim) calcular({ codigo_conta: inicial.conta, data_inicio: inicial.inicio, data_fim: inicial.fim });
  }, [inicial, calcular]);
  const d = mapa.data;
  const colunas: ColunaApi<MovimentoRazao>[] = [
    { title: 'Data', dataIndex: 'data_documento', render: formatarData },
    { title: 'Diário', dataIndex: 'diario', responsive: ['md'] },
    { title: 'N.º lançamento', dataIndex: 'numero_lan', render: (v: string, r) => (abrir ? <Typography.Link onClick={() => abrir(r.id)}>{v}</Typography.Link> : v), valorImpressao: (r) => r.numero_lan },
    { title: 'Documento', dataIndex: 'numero_documento', responsive: ['md'] },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300 },
    { title: 'Terceiro', key: 'terceiro', ellipsis: true, width: 200, responsive: ['lg'], render: (_, r) => (r.terceiro_id ? rotuloTerceiro(r.terceiro, r.terceiro_id) : '—') },
    { title: 'Débito', align: 'right', render: (_, r) => (r.tipo_dc === 'D' ? <ValorKz valor={r.valor} /> : null), totalImpressao: () => formatarKz(d?.debito) },
    { title: 'Crédito', align: 'right', render: (_, r) => (r.tipo_dc === 'C' ? <ValorKz valor={r.valor} /> : null), totalImpressao: () => formatarKz(d?.credito) },
    { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
    { title: '', exportar: false, render: (_, r) => (r.estorno_de_id ? <Tag color="purple">Estorno</Tag> : r.estornado_por_id ? <Tag color="red">Estornado</Tag> : null) },
  ];
  return (
    <Card>
      <FiltrosMapa
        modo="periodo"
        aCalcular={mapa.isFetching}
        aoCalcular={(p) => conta && mapa.calcular({ ...p, codigo_conta: conta })}
        avancados={{ terceiro: true }}
        extra={
          <>
            <Form.Item label="Conta" required style={{ marginBottom: 8 }}>
              <SeletorConta value={conta} onChange={setConta} incluirTotalizadoras style={{ width: 260 }} />
            </Form.Item>
            <Form.Item name="excluir_estornos" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Excluir estornos</Checkbox>
            </Form.Item>
          </>
        }
      />
      {!conta && <Alert type="info" showIcon message="Escolha a conta e o período e carregue em «Calcular»." style={{ marginTop: 8 }} />}
      {d && (
        <>
          <Space size={32} wrap style={{ margin: '12px 0' }}>
            <Statistic title="Saldo inicial" value={formatarKz(d.saldo_inicial)} />
            <Statistic title="Débitos" value={formatarKz(d.debito)} />
            <Statistic title="Créditos" value={formatarKz(d.credito)} />
            <Statistic title="Saldo final" value={formatarKz(d.saldo_final)} />
            <BotoesExportar
              obterPedido={async () => ({
                titulo: `Razão da conta ${d.codigo_conta}`,
                periodo: periodoDosParametros(mapa.parametros),
                filtros: filtrosDosParametros(mapa.parametros).filter((x) => !x.startsWith('Conta:')),
                conteudo:
                  pares([
                    ['Saldo inicial', formatarKz(d.saldo_inicial)],
                    ['Débitos', formatarKz(d.debito)],
                    ['Créditos', formatarKz(d.credito)],
                    ['Saldo final', formatarKz(d.saldo_final)],
                  ], 4) + (await tabelaDeColunas(colunas, d.movimentos)),
              })}
            />
            <BotaoCsv<MovimentoRazao>
              nome={`razao_${d.codigo_conta}`}
              linhas={d.movimentos}
              colunas={[
                { titulo: 'Data', valor: (l) => l.data_documento },
                { titulo: 'Diário', valor: (l) => l.diario },
                { titulo: 'N.º lançamento', valor: (l) => l.numero_lan },
                { titulo: 'Documento', valor: (l) => l.numero_documento },
                { titulo: 'Descrição', valor: (l) => l.descricao },
                { titulo: 'NIF', valor: (l) => l.terceiro?.nif },
                { titulo: 'Terceiro', valor: (l) => l.terceiro?.nome?.trim() },
                { titulo: 'Débito', valor: (l) => (l.tipo_dc === 'D' ? l.valor : ''), numerico: true },
                { titulo: 'Crédito', valor: (l) => (l.tipo_dc === 'C' ? l.valor : ''), numerico: true },
                { titulo: 'Saldo', valor: (l) => l.saldo, numerico: true },
              ]}
            />
          </Space>
          <Table<MovimentoRazao> rowKey="id" size="small" columns={colunas} dataSource={d.movimentos} pagination={{ pageSize: 100, showTotal: (n) => `${n} movimento(s)` }} scroll={scrollTabela()} />
        </>
      )}
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

  const colunas: ColunaApi<LinhaIva>[] = [
    { title: 'Data', dataIndex: 'data_documento', render: formatarData },
    { title: 'Diário', dataIndex: 'diario', responsive: ['md'] },
    { title: 'Documento', dataIndex: 'numero_documento', render: (v: string | null) => v?.trim() },
    { title: 'Conta', dataIndex: 'codigo_conta', render: (v: string, r) => <span title={r.descricao_conta ?? ''}>{v}</span> },
    { title: 'NIF', dataIndex: 'nif', responsive: ['md'] },
    { title: 'Terceiro', dataIndex: 'nome', ellipsis: true, width: 200, responsive: ['md'] },
    { title: 'Total doc.', dataIndex: 'total_documento', align: 'right', render: (v: string) => <ValorKz valor={v} />, responsive: ['lg'], totalImpressao: () => formatarKz(d?.totais.total_documento) },
    { title: 'Base', dataIndex: 'base', align: 'right', render: (v: string) => <ValorKz valor={v} />, totalImpressao: () => formatarKz(d?.totais.base) },
    { title: 'IVA a débito', dataIndex: 'iva_debito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: () => formatarKz(d?.totais.iva_debito) },
    { title: 'IVA a crédito', dataIndex: 'iva_credito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: () => formatarKz(d?.totais.iva_credito) },
  ];

  const colunasAgt: ColunaApi<ReconciliacaoAgt['linhas'][number]>[] = [
                  { title: 'Estado', dataIndex: 'estado', render: (v: string) => <Tag color={CORES_AGT[v]}>{v.replace(/_/g, ' ')}</Tag> },
                  { title: 'NIF', dataIndex: 'nif' },
                  { title: 'Nome', dataIndex: 'nome' },
                  { title: 'Documento', dataIndex: 'documento' },
                  { title: 'Base sistema', dataIndex: 'base_sistema', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                  { title: 'IVA sistema', dataIndex: 'iva_sistema', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                  { title: 'Base AGT', dataIndex: 'base_agt', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                  { title: 'IVA AGT', dataIndex: 'iva_agt', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                  { title: 'Diferença IVA', dataIndex: 'diferenca_iva', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero /> },
                ];
  return (
    <Space direction="vertical" style={{ width: '100%' }} size={16}>
      <Card>
        <FiltrosMapa modo="periodo" periodoObrigatorio={false} aCalcular={mapa.isFetching} aoCalcular={(p) => (p.data_fim ? mapa.calcular(p) : message.warning('Indique pelo menos a data final.'))} />
        {d && (
          <>
            <Space size={32} wrap style={{ margin: '12px 0' }}>
              <Statistic title="Linhas" value={d.totais.linhas} />
              <Statistic title="Base" value={formatarKz(d.totais.base)} />
              <Statistic title="IVA a débito" value={formatarKz(d.totais.iva_debito)} />
              <Statistic title="IVA a crédito" value={formatarKz(d.totais.iva_credito)} />
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
