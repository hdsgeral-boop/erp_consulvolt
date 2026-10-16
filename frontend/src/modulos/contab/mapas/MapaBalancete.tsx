import { Button, Card, Checkbox, DatePicker, Descriptions, Flex, Form, InputNumber, Space, Table, Tag, Typography, Upload, message } from 'antd';
import { UploadOutlined } from '@ant-design/icons';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { BotoesExportar, pares } from '@/componentes/impressao';
import { CartaoKpi, CORES_KPI, GrelhaKpi } from '@/componentes/vistas';
import { scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { Balancete, LinhaBalancete, LinhaIva, MapaIva } from '../api';
import { BotaoCsv, ValorKz } from '../comum/Componentes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { enviarFicheiro } from '../comum/ficheiros';
import { filtrosDosParametros, periodoDosParametros, tabelaDeColunas } from '../comum/impressao';
import { useMapa } from '../comum/useMapa';
import { periodoPorOmissao } from './periodoBalancete';
import { TituloMapa } from './TituloMapa';

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
 * Separador «Balancete do Razão» (tab 'razao', js/ui_reports.js:711-870): o balancete agregado pelas contas do razão
 * (2 dígitos, `nivel` 2) — Conta, Descrição, Débito, Crédito, Saldo devedor e Saldo credor.
 */
export function MapaBalanceteRazao() {
  return <SeparadorBalancete razao />;
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

function SeparadorBalancete({ razao = false }: { razao?: boolean }) {
  const mapa = useMapa<Balancete>(razao ? 'balancete_razao' : 'balancete', '/contabilidade/relatorios/balancete');
  const abrirExtrato = useAbrirExtrato();
  const p = mapa.parametros;
  const pequeno = useEcraPequeno();
  const t = mapa.data?.totais;
  const total = (k: string) => () => formatarKz(t?.[k]);
  const colunaSaldoInicial: ColunaApi<LinhaBalancete> = {
    title: 'Saldo inicial', dataIndex: 'saldo_inicial', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, responsive: ['md'], totalImpressao: total('saldo_inicial'),
  };
  const colunas: ColunaApi<LinhaBalancete>[] = [
    {
      title: 'Conta',
      dataIndex: 'codigo_conta',
      fixed: pequeno ? undefined : 'left',
      render: (v: string, r) => (
        <Typography.Link title="Abrir o extrato da conta" onClick={() => abrirExtrato({ conta: razao ? `${v}*` : v, terceiroId: r.terceiro_id, inicio: p?.data_inicio as string, fim: p?.data_fim as string })}>
          {v}
        </Typography.Link>
      ),
      valorImpressao: (l) => l.codigo_conta,
    },
    { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null, r) => `${v ?? ''}${r.terceiro ? ` · ${r.terceiro.trim()}` : ''}` },
    ...(razao ? [] : [colunaSaldoInicial]),
    { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: total('debito') },
    { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: total('credito') },
    { title: 'Saldo devedor', dataIndex: 'saldo_devedor', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: total('saldo_devedor') },
    { title: 'Saldo credor', dataIndex: 'saldo_credor', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero />, totalImpressao: total('saldo_credor') },
  ];
  const titulo = razao ? 'Balancete do Razão' : 'Balancete';
  const chavesTotais = razao || pequeno ? ['debito', 'credito', 'saldo_devedor', 'saldo_credor'] : ['saldo_inicial', 'debito', 'credito', 'saldo_devedor', 'saldo_credor'];
  return (
    <Card>
      <TituloMapa titulo={titulo} subtitulo={razao ? 'Saldos agregados pelas contas do razão (2 dígitos)' : undefined} />
      <FiltrosMapa
        modo="periodo"
        valoresIniciais={{ periodo: periodoPorOmissao() }}
        aCalcular={mapa.isFetching}
        aoCalcular={(q) => mapa.calcular(razao ? { ...q, nivel: 2 } : q)}
        extra={
          razao ? (
            <Form.Item name="excluir_estornos" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Excluir estornos</Checkbox>
            </Form.Item>
          ) : (
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
          )
        }
      />
      {mapa.data && (
        <>
          <Space wrap style={{ margin: '8px 0 12px' }}>
            <BotoesExportar
              obterPedido={async () =>
                mapa.data && {
                  titulo,
                  periodo: periodoDosParametros(p),
                  filtros: filtrosDosParametros(p),
                  conteudo: await tabelaDeColunas(colunas, mapa.data.linhas),
                }
              }
            />
            <BotaoCsv<LinhaBalancete>
              nome={`${razao ? 'balancete_razao' : 'balancete'}_${p?.data_inicio}_${p?.data_fim}`}
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
                    {chavesTotais.map((k, i) => (
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
