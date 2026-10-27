import { Alert, Button, Card, Col, DatePicker, Flex, InputNumber, Popconfirm, Row, Select, Space, Table, Tabs, Tag, Typography, message } from 'antd';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import { ImportOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useRef, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { BotoesExportar, pares, tabelaHtml } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../contab/comum/Componentes';
import { enviarFicheiro, ModalImportar } from '../contab/comum/ficheiros';
import { descarregarModeloExtrato } from './modeloExtrato';
import type { LinhaExtratoBancario, MapaReconciliacao, MeioPagamento, SugestaoReconciliacao } from './api';
import { SeletorContaFinanceira, useMeiosPagamento } from './comum';
import { tipoMeio } from './MeiosPagamento';
import { CartaoKpi, EstadoVazio, GrelhaKpi } from '@/componentes/vistas';
import { somaCorrespondencia } from './regras';
import { BotaoEditarLinha, HistoricoFiltrado, ModalEditarLinhaExtrato, ModalRascunhos, PainelRascunhos, type RascunhoReconciliacao } from './ReconciliacaoExtras';
import { scrollTabela } from '@/componentes/responsivo';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';

const PREFIXO_BANCOS = ['43'];

const ROTULO_ESTADO_EXTRATO: Record<string, string> = { PENDENTE: 'Pendente', CONCILIADO: 'Conciliado', ANULADO: 'Anulado' };

/** Mapa de reconciliação impresso: resumo dos saldos e as linhas por reconciliar do diário e do extracto. */
export function pedidoMapaReconciliacao(d: MapaReconciliacao) {
  type LD = MapaReconciliacao['por_reconciliar_diario']['linhas'][number];
  type LE = MapaReconciliacao['por_reconciliar_extrato']['linhas'][number];
  return {
    titulo: `Mapa de reconciliação bancária — conta ${d.conta}`,
    periodo: `Em ${formatarData(d.data)}`,
    conteudo:
      pares(
        [
          ['Saldo no diário', `${formatarKz(d.saldo_diario)} Kz`],
          ['Diário por reconciliar (líquido)', `${formatarKz(d.por_reconciliar_diario.total)} Kz`],
          ['Extracto por reconciliar (líquido)', `${formatarKz(d.por_reconciliar_extrato.total)} Kz`],
          ['Saldo esperado no banco', `${formatarKz(d.saldo_banco_esperado)} Kz`],
        ],
        2,
      ) +
      tabelaHtml({
        legenda: 'Movimentos do diário por reconciliar',
        colunas: [
          { titulo: 'Data', valor: (l: LD) => l.data_documento, formato: 'data' },
          { titulo: 'Lançamento', valor: (l) => l.numero_lan },
          { titulo: 'Documento', valor: (l) => l.numero_documento ?? '' },
          { titulo: 'Descrição', valor: (l) => l.descricao ?? '', quebrar: true },
          { titulo: 'Débito (Kz)', valor: (l) => (l.tipo_dc === 'D' ? l.valor : null), formato: 'moeda', somar: true },
          { titulo: 'Crédito (Kz)', valor: (l) => (l.tipo_dc === 'C' ? l.valor : null), formato: 'moeda', somar: true },
        ],
        linhas: d.por_reconciliar_diario.linhas,
        totais: true,
        vazio: 'Sem movimentos do diário por reconciliar.',
      }) +
      tabelaHtml({
        legenda: 'Linhas do extracto por reconciliar',
        colunas: [
          { titulo: 'Data', valor: (l: LE) => l.data, formato: 'data' },
          { titulo: 'Referência', valor: (l) => l.referencia ?? '' },
          { titulo: 'Descrição', valor: (l) => l.descricao ?? '', quebrar: true },
          { titulo: 'Entrada (Kz)', valor: (l) => (l.tipo_dc === 'C' ? l.valor : null), formato: 'moeda', somar: true },
          { titulo: 'Saída (Kz)', valor: (l) => (l.tipo_dc === 'D' ? l.valor : null), formato: 'moeda', somar: true },
        ],
        linhas: d.por_reconciliar_extrato.linhas,
        totais: true,
        vazio: 'Sem linhas do extracto por reconciliar.',
      }),
  };
}

const CRITERIOS: Record<string, string> = { MESMA_DATA: 'Mesma data', TOLERANCIA_DATA: 'Data próxima', VALOR_UNICO: 'Valor único' };

/**
 * Conta bancária inicial (o legado abria com a 1.ª conta 43 do select já escolhida e mostrava logo o conteúdo):
 * o meio de pagamento predefinido se for de bancos ou cartão, senão o 1.º meio activo desses, por código.
 */
export function contaBancariaInicial(meios: MeioPagamento[] | undefined): string | undefined {
  const bancos = (meios ?? []).filter((m) => m.ativo && (PREFIXO_BANCOS.some((p) => m.codigo_conta?.startsWith(p)) || tipoMeio(m) === 'CARTAO'));
  return (bancos.find((m) => m.predefinido) ?? [...bancos].sort((a, b) => a.codigo_conta.localeCompare(b.codigo_conta))[0])?.codigo_conta;
}

/**
 * Quadro-resumo de uma linha do legado (ui_tesouraria.js:4574-4612): ERP débito/crédito/saldo, banco débito (saída)/crédito
 * (entrada)/saldo e a diferença. Os totais D/C e o saldo do extracto só vêm do servidor quando ele os devolver
 * (`diario_debito`, `diario_credito`, `extrato_debito`, `extrato_credito`, `saldo_extrato`); sem o saldo do extracto, a
 * diferença é a dos movimentos por reconciliar (diário − extracto), que é zero quando tudo está conciliado.
 */
export function resumoConciliacao(d: MapaReconciliacao): {
  erpDebito: string | null; erpCredito: string | null; saldoErp: string; bancoDebito: string | null; bancoCredito: string | null; saldoBanco: string; diferenca: string;
} {
  const saldoBanco = d.saldo_extrato ?? d.saldo_banco_esperado;
  const diferenca = d.saldo_extrato !== undefined && d.saldo_extrato !== null
    ? deCentimos(paraCentimos(d.saldo_diario) - paraCentimos(d.saldo_extrato))
    : deCentimos(paraCentimos(d.por_reconciliar_diario.total) - paraCentimos(d.por_reconciliar_extrato.total));
  return {
    erpDebito: d.diario_debito ?? null, erpCredito: d.diario_credito ?? null, saldoErp: d.saldo_diario,
    bancoDebito: d.extrato_debito ?? null, bancoCredito: d.extrato_credito ?? null, saldoBanco, diferenca,
  };
}

/** Saldo corrido das linhas do extracto (óptica do banco: crédito = entrada), pela ordem da data; as anuladas não contam. */
export function saldoCorridoExtrato(linhas: Pick<LinhaExtratoBancario, 'id' | 'data' | 'tipo_dc' | 'valor' | 'estado'>[]): Map<number, string> {
  const ordem = [...linhas].sort((a, b) => a.data.localeCompare(b.data) || a.id - b.id);
  let c = 0;
  const m = new Map<number, string>();
  for (const l of ordem) {
    if (l.estado !== 'ANULADO') c += (l.tipo_dc === 'C' ? 1 : -1) * paraCentimos(l.valor);
    m.set(l.id, deCentimos(c));
  }
  return m;
}

const corErp = (dc: 'D' | 'C') => (dc === 'D' ? '#059669' : '#dc2626');
const corBanco = (dc: 'D' | 'C') => (dc === 'C' ? '#059669' : '#dc2626');
const Valor = ({ v, cor }: { v: string; cor: string }) => <span style={{ color: cor, whiteSpace: 'nowrap', fontWeight: 600 }}>{formatarKz(v)}</span>;

/**
 * Tesouraria › Conciliação bancária (ecrã teso_gestao_conciliacao), com a disposição do legado (ui_tesouraria.js:3891-4045,
 * 4528-4745, 5172-5334): sem título próprio (o agregador «Tesouraria - Gestão» já o dá); cartão «Nova conciliação ou
 * Reprocessamento» com a conta e a data; quadro-resumo ERP / Banco / Diferença; separadores «Conciliados (N)» e «Itens por
 * Reconciliar / Divergências (N)», extracto carregado e mapa; e o histórico sempre visível por baixo.
 */
export default function Conciliacao() {
  const [conta, setConta] = useState<string>();
  const [data, setData] = useState<Dayjs>(dayjs());
  const [separador, setSeparador] = useSeparadorUrl(['auto', 'manual', 'extrato', 'mapa']);
  const meios = useMeiosPagamento();
  const [escolhida, setEscolhida] = useState(false);
  const [verRascunhos, setVerRascunhos] = useState(false);
  const [rascunho, setRascunho] = useState<RascunhoReconciliacao | null>(null);
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const ficheiro = useRef<HTMLInputElement>(null);
  const mapa = useQuery({ ...opcoesMapa(conta ?? '', data), enabled: !!conta });
  useEffect(() => {
    if (!escolhida && !conta) {
      const inicial = contaBancariaInicial(meios.data);
      if (inicial) setConta(inicial);
    }
  }, [meios.data, conta, escolhida]);
  const escolherConta = (v: string | undefined) => { setEscolhida(true); setConta(v); };
  /** Correspondência automática de novo sobre o que já está carregado (reprocessStoredReconciliation do legado). */
  const correrAutomatica = () => {
    setSeparador('auto');
    void cliente.invalidateQueries({ queryKey: ['teso', 'reconciliacao'] });
  };
  const reprocessar = () => {
    if (mapa.data && !mapa.data.por_reconciliar_extrato.linhas.length) {
      message.warning('Não existem movimentos carregados pendentes para esta conta no período selecionado.');
      return;
    }
    correrAutomatica();
  };
  // «Carregar e conciliar»: o mesmo endpoint de importação do separador do extracto, seguido da correspondência automática
  const carregar = useMutation({
    mutationFn: (f: File) => enviarFicheiro<{ lote: string; importadas: number; duplicadas: number; ignoradas: string[] }>('/tesouraria/extrato/importar', f, { codigo_conta: conta }),
    onSuccess: ({ dados }) => {
      message.success(`Extracto carregado: ${dados.importadas} linha(s) nova(s)${dados.duplicadas ? `, ${dados.duplicadas} já existente(s)` : ''}${dados.ignoradas?.length ? `, ${dados.ignoradas.length} ignorada(s)` : ''}. Correspondência automática actualizada.`);
      void cliente.invalidateQueries({ queryKey: ['teso'] });
      correrAutomatica();
    },
    onError: (e) => notificarErro(e, 'Não foi possível carregar o extracto'),
  });
  const aoEscolherFicheiro = (e: React.ChangeEvent<HTMLInputElement>) => {
    const f = e.target.files?.[0];
    e.target.value = '';
    if (!f) return;
    const erro = validarFicheiroExtrato(f);
    if (erro) message.error(erro);
    else carregar.mutate(f);
  };
  return (
    <>
      {/* cartão do legado (ui_tesouraria.js:3943-4005): botões do cabeçalho e, à direita do corpo, REPROCESSAR EXISTENTES e CARREGAR E CONCILIAR */}
      <Card style={{ marginBottom: 12 }} title={<><IconeFa nome="plus-circle" /> Nova conciliação ou Reprocessamento</>}
        extra={
          <Space wrap>
            <Button size="small" icon={<IconeFa nome="balance-scale" />} disabled={!conta} onClick={() => setSeparador('manual')}>Gerir Divergências (Pendentes)</Button>
            <Button size="small" icon={<IconeFa nome="university" />} disabled={!conta} onClick={() => setSeparador('extrato')}>Extratos Carregados</Button>
            <Button size="small" icon={<IconeFa nome="edit" />} style={{ color: '#d97706', borderColor: '#d97706' }} onClick={() => setVerRascunhos(true)}>Rascunhos</Button>
            <Button size="small" icon={<IconeFa nome="file-excel" />} style={{ color: 'var(--ant-color-primary)', borderColor: 'var(--ant-color-primary)' }} onClick={descarregarModeloExtrato}>Modelo</Button>
          </Space>
        }>
        <Flex wrap gap={12} align="flex-end" justify="space-between">
          <Space wrap>
            <SeletorContaFinanceira value={conta} onChange={escolherConta} prefixos={PREFIXO_BANCOS} comCartao placeholder="Conta Bancária / Cartão" />
            <DatePicker value={data} onChange={(v) => v && setData(v)} format="DD/MM/YYYY" allowClear={false} aria-label="Data de referência" />
          </Space>
          <Space wrap>
            <Button icon={<IconeFa nome="sync-alt" />} style={{ color: '#0891b2', borderColor: '#0891b2' }} disabled={!conta} onClick={reprocessar}>REPROCESSAR EXISTENTES</Button>
            {pode('teso_conc_importar') && (
              <>
                <input ref={ficheiro} type="file" accept={ACEITAR_EXTRATO} style={{ display: 'none' }} onChange={aoEscolherFicheiro} data-testid="ficheiro-extrato" aria-label="Ficheiro do extracto" />
                <Button type="primary" icon={<IconeFa nome="file-upload" />} style={{ background: '#0891b2', border: 'none' }} disabled={!conta} loading={carregar.isPending} onClick={() => ficheiro.current?.click()}>
                  CARREGAR E CONCILIAR
                </Button>
              </>
            )}
          </Space>
        </Flex>
      </Card>
      <ModalRascunhos
        aberto={verRascunhos}
        aoFechar={() => setVerRascunhos(false)}
        aoContinuar={(r) => {
          setVerRascunhos(false);
          escolherConta(r.codigo_conta);
          setSeparador('manual');
          setRascunho(r);
        }}
      />
      {!conta ? (
        <EstadoVazio icone="balance-scale" titulo={meios.isLoading ? 'A carregar as contas bancárias…' : 'Escolha a conta bancária'}
          texto="Seleccione acima a conta bancária (43) ou de cartão e a data de referência para ver a correspondência, o extracto, o mapa e o histórico." />
      ) : (
        <>
          <QuadroResumo conta={conta} data={data} aoTratar={() => setSeparador('manual')} />
          <Separadores conta={conta} data={data} separador={separador} setSeparador={setSeparador} rascunho={rascunho} aoAplicarRascunho={() => setRascunho(null)} />
          <div style={{ marginTop: 16 }}>
            <HistoricoFiltrado contaInicial={conta} aoReprocessar={(c) => { if (c) escolherConta(c); correrAutomatica(); }} />
          </div>
        </>
      )}
    </>
  );
}

/** Ficheiros que o servidor aceita no extracto (XLSX, XLS ou CSV, até 10 MB — a validação definitiva é a do servidor). */
export const ACEITAR_EXTRATO = '.xlsx,.xls,.csv';
const MAXIMO_EXTRATO = 10 * 1024 * 1024;

export function validarFicheiroExtrato(f: Pick<File, 'name' | 'size'>): string | null {
  if (!/\.(xlsx|xls|csv)$/i.test(f.name)) return 'Use um ficheiro XLSX, XLS ou CSV.';
  if (f.size > MAXIMO_EXTRATO) return 'O ficheiro excede 10 MB.';
  if (f.size === 0) return 'O ficheiro está vazio.';
  return null;
}

function QuadroResumo({ conta, data, aoTratar }: { conta: string; data: Dayjs; aoTratar: () => void }) {
  const mapa = useMapaReconciliacao(conta, data);
  if (!mapa.data) return <Card loading style={{ marginBottom: 12 }} />;
  const r = resumoConciliacao(mapa.data);
  const zero = !paraCentimos(r.diferenca);
  // cabeçalhos com fundos translúcidos (ERP verde, banco ciano, saldos cinzento, diferença rosa): legíveis nos dois temas
  const cab = (fundo: string, cor?: string) => ({ style: { background: fundo, color: cor } });
  const opc = (v: string | null) => (v === null ? <Typography.Text type="secondary">—</Typography.Text> : formatarKz(v));
  return (
    <Card size="small" style={{ marginBottom: 12 }} data-testid="quadro-conciliacao">
      <Table
        rowKey="conta" size="small" pagination={false} scroll={scrollTabela()}
        dataSource={[{ conta, ...r }]}
        columns={[
          { title: 'Conta', dataIndex: 'conta', render: (v: string) => <Tag color="blue">{v}</Tag> },
          { title: 'ERP Débito', key: 'ed', align: 'right', onHeaderCell: () => cab('rgba(16,185,129,0.1)'), render: () => opc(r.erpDebito) },
          { title: 'ERP Crédito', key: 'ec', align: 'right', onHeaderCell: () => cab('rgba(16,185,129,0.1)'), render: () => opc(r.erpCredito) },
          { title: 'Saldo ERP', key: 'se', align: 'right', onHeaderCell: () => cab('rgba(148,163,184,0.18)'), render: () => <strong>{formatarKz(r.saldoErp)}</strong> },
          { title: 'Banco Débito (Saída)', key: 'bd', align: 'right', onHeaderCell: () => cab('rgba(8,145,178,0.1)'), render: () => opc(r.bancoDebito) },
          { title: 'Banco Crédito (Entrada)', key: 'bc', align: 'right', onHeaderCell: () => cab('rgba(8,145,178,0.1)'), render: () => opc(r.bancoCredito) },
          { title: 'Saldo Banco', key: 'sb', align: 'right', onHeaderCell: () => cab('rgba(148,163,184,0.18)'), render: () => <strong>{formatarKz(r.saldoBanco)}</strong> },
          {
            title: 'Diferença', key: 'dif', align: 'right', onHeaderCell: () => cab('rgba(190,18,60,0.1)', '#be123c'),
            render: () => (
              <Space size={6}>
                <strong style={{ color: zero ? '#059669' : '#be123c', fontSize: 15 }}>{formatarKz(r.diferenca)}</strong>
                {!zero && <Button size="small" danger onClick={aoTratar}>TRATAR →</Button>}
              </Space>
            ),
          },
        ]}
      />
    </Card>
  );
}

function opcoesMapa(conta: string, data: Dayjs) {
  return { queryKey: ['teso', 'reconciliacao', 'mapa', conta, dataApi(data)], queryFn: () => obter<MapaReconciliacao>('/tesouraria/reconciliacao/mapa', { codigo_conta: conta, data: dataApi(data) }) };
}

function useMapaReconciliacao(conta: string, data: Dayjs) {
  return useQuery(opcoesMapa(conta, data));
}

function useConfirmar(conta: string, aoConcluir: () => void) {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: ({ grupos, tipo }: { grupos: { extrato: number[]; lancamentos: number[] }[]; tipo: 'AUTOMATICA' | 'MANUAL' }) => enviar('post', '/tesouraria/reconciliacao', { codigo_conta: conta, tipo, grupos }),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      aoConcluir();
      void cliente.invalidateQueries({ queryKey: ['teso'] });
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível reconciliar'),
  });
}

function Separadores({ conta, data, separador, setSeparador, rascunho, aoAplicarRascunho }: {
  conta: string; data: Dayjs; separador: string; setSeparador: (s: string) => void; rascunho?: RascunhoReconciliacao | null; aoAplicarRascunho?: () => void;
}) {
  const { pode } = useSessao();
  const podeConfirmar = pode('teso_conc_confirmar');
  const mapa = useMapaReconciliacao(conta, data);
  const [tolerancia, setTolerancia] = useState(1);
  const sugestoes = useQuery({
    queryKey: ['teso', 'reconciliacao', 'sugestoes', conta, dataApi(data), tolerancia],
    queryFn: () => obter<SugestaoReconciliacao[]>('/tesouraria/reconciliacao/sugestoes', { codigo_conta: conta, data_fim: dataApi(data), tolerancia_dias: tolerancia }),
  });
  const [selSug, setSelSug] = useState<string[]>([]);
  const [selExt, setSelExt] = useState<number[]>([]);
  const [selDia, setSelDia] = useState<number[]>([]);
  const confirmar = useConfirmar(conta, () => {
    setSelSug([]);
    setSelExt([]);
    setSelDia([]);
  });

  const extrato = mapa.data?.por_reconciliar_extrato.linhas ?? [];
  const diario = mapa.data?.por_reconciliar_diario.linhas ?? [];
  const porIdE = useMemo(() => new Map(extrato.map((l) => [l.id, l])), [extrato]);
  const porIdD = useMemo(() => new Map(diario.map((l) => [l.id, l])), [diario]);
  const soma = somaCorrespondencia(extrato.filter((l) => selExt.includes(l.id)), diario.filter((l) => selDia.includes(l.id)));
  const chaveS = (s: SugestaoReconciliacao) => `${s.linha_extrato_id}-${s.lancamento_id}`;
  const nSug = sugestoes.data?.length ?? 0;
  const nPend = extrato.length + diario.length;
  // repõe a selecção com as linhas do rascunho que ainda estão por reconciliar (do painel do separador ou de «Rascunhos» no topo)
  const abrirRascunho = (r: RascunhoReconciliacao) => {
    setSelExt(r.grupos.flatMap((g) => g.extrato).filter((id) => porIdE.has(id)));
    setSelDia(r.grupos.flatMap((g) => g.lancamentos).filter((id) => porIdD.has(id)));
    message.info(`Rascunho #${r.id} aberto (${r.grupos.length} grupo(s)).`);
  };
  useEffect(() => {
    if (rascunho && rascunho.codigo_conta === conta && mapa.data && !mapa.isFetching) {
      abrirRascunho(rascunho);
      aoAplicarRascunho?.();
    }
  }, [rascunho, conta, mapa.data, mapa.isFetching]);

  const automatica = (
    <Card
      title="Correspondência automática (1:1)"
      extra={
        <Space wrap>
          <span>Tolerância (dias)</span>
          <InputNumber min={0} max={5} value={tolerancia} onChange={(v) => setTolerancia(v ?? 1)} style={{ width: 70 }} aria-label="Tolerância (dias)" />
          {podeConfirmar && (
            <Button type="primary" disabled={!selSug.length} loading={confirmar.isPending}
              onClick={() => confirmar.mutate({ tipo: 'AUTOMATICA', grupos: (sugestoes.data ?? []).filter((s) => selSug.includes(chaveS(s))).map((s) => ({ extrato: [s.linha_extrato_id], lancamentos: [s.lancamento_id] })) })}>
              Confirmar {selSug.length} sugestão(ões)
            </Button>
          )}
        </Space>
      }
    >
      {/* cabeçalho duplo do legado (ui_tesouraria.js:4638-4648): ERP (CONTABILIDADE) | BANCO (EXTRATO) */}
      <Table<SugestaoReconciliacao>
        rowKey={chaveS}
        size="small"
        bordered
        loading={sugestoes.isFetching}
        dataSource={sugestoes.data}
        pagination={{ pageSize: 20 }}
        locale={{ emptyText: 'Sem sugestões: importe o extracto ou use a correspondência manual.' }}
        rowSelection={podeConfirmar ? { selectedRowKeys: selSug, onChange: (k) => setSelSug(k as string[]) } : undefined}
        scroll={scrollTabela()}
        columns={[
          {
            title: 'ERP (CONTABILIDADE)', key: 'erp', onHeaderCell: () => ({ style: { background: 'rgba(16,185,129,0.1)' } }),
            children: [
              { title: 'Data', key: 'ed', render: (_, s) => formatarData(porIdD.get(s.lancamento_id)?.data_documento) },
              { title: 'Referência', key: 'er', render: (_, s) => { const d = porIdD.get(s.lancamento_id); return d ? d.numero_documento ?? d.numero_lan : `#${s.lancamento_id}`; } },
              { title: 'Valor', key: 'ev', align: 'right', render: (_, s) => { const d = porIdD.get(s.lancamento_id); return <Valor v={s.valor} cor={d ? corErp(d.tipo_dc) : 'inherit'} />; } },
            ],
          },
          {
            title: 'BANCO (EXTRATO)', key: 'banco', onHeaderCell: () => ({ style: { background: 'rgba(8,145,178,0.1)' } }),
            children: [
              { title: 'Data', key: 'bd', render: (_, s) => formatarData(porIdE.get(s.linha_extrato_id)?.data) },
              { title: 'Referência', key: 'br', render: (_, s) => { const e = porIdE.get(s.linha_extrato_id); return e ? e.referencia ?? e.descricao ?? '—' : `#${s.linha_extrato_id}`; } },
              { title: 'Valor', key: 'bv', align: 'right', render: (_, s) => { const e = porIdE.get(s.linha_extrato_id); return <Valor v={s.valor} cor={e ? corBanco(e.tipo_dc) : 'inherit'} />; } },
            ],
          },
          { title: 'Critério', dataIndex: 'criterio', render: (v: string) => <Tag>{CRITERIOS[v] ?? v}</Tag> },
        ]}
      />
    </Card>
  );

  const totalLiq = (ls: { tipo_dc: 'D' | 'C'; valor: string }[], positivo: 'D' | 'C') => deCentimos(ls.reduce((t, l) => t + (l.tipo_dc === positivo ? 1 : -1) * paraCentimos(l.valor), 0));
  const manual = (
    <Card
      title={<><IconeFa nome="hand-pointer" /> Espaço de Tratamento Manual</>}
      extra={podeConfirmar && (
        <PainelRascunhos
          conta={conta}
          extrato={selExt}
          lancamentos={selDia}
          aoAbrir={abrirRascunho}
        />
      )}
    >
      <Flex wrap gap={16} align="center" justify="space-between" style={{ marginBottom: 12 }}>
        <Space wrap size={16}>
          <span>ERP seleccionado: <strong>{formatarKz(soma.diario)}</strong></span>
          <span>Banco seleccionado: <strong>{formatarKz(soma.extrato)}</strong></span>
          <span data-testid="diferenca-manual">Diferença: <strong style={{ fontSize: 22.4, fontWeight: 800, color: soma.casa ? '#059669' : '#ef4444' }}>{formatarKz(soma.diferenca)}</strong></span>
        </Space>
        {podeConfirmar && (
          <Button type="primary" disabled={!soma.casa} loading={confirmar.isPending} onClick={() => confirmar.mutate({ tipo: 'MANUAL', grupos: [{ extrato: selExt, lancamentos: selDia }] })}>
            Reconciliar selecção
          </Button>
        )}
      </Flex>
      <Row gutter={16}>
        <Col xs={24} lg={12}>
          <Flex justify="space-between" align="baseline">
            <Typography.Title level={5}>Pendentes no ERP ({diario.length})</Typography.Title>
            <strong style={{ color: '#92400e' }}>{formatarKz(totalLiq(diario, 'D'))}</strong>
          </Flex>
          <Table scroll={scrollTabela()}
            rowKey="id"
            size="small"
            loading={mapa.isLoading}
            dataSource={diario}
            pagination={{ pageSize: 15 }}
            rowSelection={podeConfirmar ? { selectedRowKeys: selDia, onChange: (k) => setSelDia(k as number[]) } : undefined}
            columns={[
              { title: 'Data', dataIndex: 'data_documento', render: formatarData },
              { title: 'Ref.', key: 'ref', render: (_, l) => <span title={l.descricao ?? undefined}>{l.numero_documento ?? l.numero_lan}</span> },
              { title: 'Valor', key: 'v', align: 'right', render: (_, l) => <Valor v={l.valor} cor={corErp(l.tipo_dc)} /> },
            ]}
          />
        </Col>
        <Col xs={24} lg={12}>
          <Flex justify="space-between" align="baseline">
            <Typography.Title level={5}>Pendentes no Banco ({extrato.length})</Typography.Title>
            <strong style={{ color: '#0e7490' }}>{formatarKz(totalLiq(extrato, 'C'))}</strong>
          </Flex>
          <Table scroll={scrollTabela()}
            rowKey="id"
            size="small"
            loading={mapa.isLoading}
            dataSource={extrato}
            pagination={{ pageSize: 15 }}
            rowSelection={podeConfirmar ? { selectedRowKeys: selExt, onChange: (k) => setSelExt(k as number[]) } : undefined}
            columns={[
              { title: 'Data', dataIndex: 'data', render: formatarData },
              { title: 'Ref.', key: 'ref', render: (_, l) => <span title={l.descricao ?? undefined}>{l.referencia ?? l.descricao ?? '—'}</span> },
              { title: 'Valor', key: 'v', align: 'right', render: (_, l) => <Valor v={l.valor} cor={corBanco(l.tipo_dc)} /> },
            ]}
          />
        </Col>
      </Row>
    </Card>
  );

  return (
    <Tabs
      activeKey={separador}
      onChange={setSeparador}
      items={[
        { key: 'auto', label: <><IconeFa nome="check-circle" style={{ color: '#10b981' }} /> Conciliados ({nSug})</>, children: automatica },
        { key: 'manual', label: <><IconeFa nome="exclamation-triangle" style={{ color: '#f59e0b' }} /> Itens por Reconciliar / Divergências ({nPend})</>, children: manual },
        { key: 'extrato', label: 'Extratos carregados', children: <Extrato conta={conta} /> },
        { key: 'mapa', label: 'Mapa de reconciliação', children: <Mapa conta={conta} data={data} /> },
      ]}
    />
  );
}

function Extrato({ conta }: { conta: string }) {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [estado, setEstado] = useState<string>();
  const [importar, setImportar] = useState(false);
  const [editar, setEditar] = useState<LinhaExtratoBancario | null>(null);
  const linhas = useQuery({ queryKey: ['teso', 'extrato', conta, estado], queryFn: () => obter<LinhaExtratoBancario[]>('/tesouraria/extrato', { codigo_conta: conta, estado }) });
  const saldos = useMemo(() => saldoCorridoExtrato(linhas.data ?? []), [linhas.data]);
  const anular = useMutation({
    mutationFn: (id: number) => enviar('post', `/tesouraria/extrato/${id}/anular`),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['teso'] });
    },
    onError: (e) => notificarErro(e),
  });
  return (
    <Card
      extra={
        <Space wrap>
          <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 160 }} options={[{ value: 'PENDENTE', label: 'Pendentes' }, { value: 'CONCILIADO', label: 'Conciliadas' }, { value: 'ANULADO', label: 'Anuladas' }]} />
          {pode('teso_conc_importar') && <Button icon={<ImportOutlined />} onClick={() => setImportar(true)}>Importar extracto</Button>}
          <BotoesExportar
            desactivado={!linhas.data?.length}
            obterPedido={() => ({
              titulo: `Extracto bancário importado — conta ${conta}`,
              filtros: [estado && `Estado: ${ROTULO_ESTADO_EXTRATO[estado] ?? estado}`],
              conteudo: tabelaHtml({
                colunas: [
                  { titulo: 'Data', valor: (l: LinhaExtratoBancario) => l.data, formato: 'data' },
                  { titulo: 'Referência', valor: (l) => l.referencia ?? '' },
                  { titulo: 'Conta', valor: (l) => l.codigo_conta },
                  { titulo: 'Descrição', valor: (l) => l.descricao ?? '', quebrar: true },
                  { titulo: 'Estado', valor: (l) => ROTULO_ESTADO_EXTRATO[l.estado] ?? l.estado },
                  { titulo: 'Débito (Kz)', valor: (l) => (l.tipo_dc === 'D' ? l.valor : null), formato: 'moeda', somar: true },
                  { titulo: 'Crédito (Kz)', valor: (l) => (l.tipo_dc === 'C' ? l.valor : null), formato: 'moeda', somar: true },
                  { titulo: 'Saldo (Kz)', valor: (l) => saldos.get(l.id) ?? null, formato: 'moeda' },
                ],
                linhas: linhas.data ?? [],
                totais: true,
              }),
            })}
          />
        </Space>
      }
    >
      {/* colunas do legado (ui_tesouraria.js:5062-5073): Data, Referência, Conta, Descrição, Estado, Débito, Crédito, Saldo, Ações */}
      <Table<LinhaExtratoBancario>
        rowKey="id"
        size="small"
        loading={linhas.isLoading}
        dataSource={linhas.data}
        pagination={{ pageSize: 50, showTotal: (n) => `${n} linha(s)` }}
        scroll={scrollTabela()}
        columns={[
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Referência', dataIndex: 'referencia', responsive: ['md'] },
          { title: 'Conta', dataIndex: 'codigo_conta', responsive: ['lg'] },
          { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300, responsive: ['sm'] },
          { title: 'Estado', dataIndex: 'estado', render: (v: string, l) => <span title={l.reconciliacao_codigo ?? undefined}><EtiquetaEstado estado={v} /></span> },
          { title: 'Débito', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : null) },
          { title: 'Crédito', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : null) },
          { title: 'Saldo', key: 'saldo', align: 'right', render: (_, l) => <ValorKz valor={saldos.get(l.id) ?? null} /> },
          {
            title: 'Ações',
            render: (_, l) => (
              <Space size={0}>
                <BotaoEditarLinha linha={l} aoEditar={setEditar} />
                {pode('teso_conc_anular') && l.estado === 'PENDENTE' ? (
                  <Popconfirm title="Anular esta linha do extracto?" okText="Anular" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => anular.mutateAsync(l.id)}>
                    <Button size="small" danger type="link">Anular</Button>
                  </Popconfirm>
                ) : null}
              </Space>
            ),
          },
        ]}
      />
      <ModalEditarLinhaExtrato linha={editar} aoFechar={() => setEditar(null)} />
      <ModalImportar
        aberto={importar}
        titulo={`Importar extracto — conta ${conta}`}
        url="/tesouraria/extrato/importar"
        campos={{ codigo_conta: conta }}
        ajuda="Ficheiro do banco (XLSX, XLS ou CSV). As linhas já importadas são ignoradas."
        aoFechar={() => setImportar(false)}
        aoConcluir={() => void cliente.invalidateQueries({ queryKey: ['teso'] })}
      />
    </Card>
  );
}

function Mapa({ conta, data }: { conta: string; data: Dayjs }) {
  const mapa = useMapaReconciliacao(conta, data);
  const d = mapa.data;
  if (!d) return <Card loading />;
  return (
    <Card title={`Mapa de reconciliação em ${formatarData(d.data)}`} extra={<BotoesExportar obterPedido={() => pedidoMapaReconciliacao(d)} />}>
      <GrelhaKpi larguraMinima={200}>
        <CartaoKpi barra="nenhuma" rotulo="Saldo no diário" valor={formatarKz(d.saldo_diario)} unidade="Kz" />
        <CartaoKpi barra="nenhuma" rotulo="Diário por reconciliar (líquido)" valor={formatarKz(d.por_reconciliar_diario.total)} unidade="Kz" />
        <CartaoKpi barra="nenhuma" rotulo="Extracto por reconciliar (líquido)" valor={formatarKz(d.por_reconciliar_extrato.total)} unidade="Kz" />
        <CartaoKpi barra="nenhuma" rotulo="Saldo esperado no banco" valor={formatarKz(d.saldo_banco_esperado)} unidade="Kz" />
      </GrelhaKpi>
      <Alert style={{ marginTop: 16 }} type="info" showIcon message="Saldo esperado no banco = saldo do diário − movimentos do diário por reconciliar + movimentos do extracto por reconciliar." />
    </Card>
  );
}
