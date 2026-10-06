import { Alert, Button, Card, Col, DatePicker, Empty, InputNumber, Popconfirm, Row, Select, Space, Statistic, Table, Tabs, Tag, Typography, message } from 'antd';
import { ImportOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, pares, tabelaHtml } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../contab/comum/Componentes';
import { ModalImportar } from '../contab/comum/ficheiros';
import type { LinhaExtratoBancario, MapaReconciliacao, SugestaoReconciliacao } from './api';
import { SeletorContaFinanceira } from './comum';
import { somaCorrespondencia } from './regras';
import { BotaoEditarLinha, HistoricoFiltrado, ModalEditarLinhaExtrato, PainelRascunhos } from './ReconciliacaoExtras';
import { scrollTabela } from '@/componentes/responsivo';

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

/** Tesouraria › Reconciliação bancária (ecrã teso_gestao_conciliacao): extracto, correspondência automática/manual, mapa e histórico. */
export default function Conciliacao() {
  const [conta, setConta] = useState<string>();
  const [data, setData] = useState<Dayjs>(dayjs());
  return (
    <>
      <CabecalhoPagina
        titulo="Reconciliação bancária"
        subtitulo="Extracto do banco contra os movimentos do diário"
        accoes={
          <Space wrap>
            <SeletorContaFinanceira value={conta} onChange={setConta} prefixos={['43']} placeholder="Conta bancária" />
            <DatePicker value={data} onChange={(v) => v && setData(v)} format="DD/MM/YYYY" allowClear={false} aria-label="Data de referência" />
          </Space>
        }
      />
      {!conta ? (
        <Empty description="Escolha a conta bancária." />
      ) : (
        <Tabs
          items={[
            { key: 'corresp', label: 'Correspondência', children: <Correspondencia conta={conta} data={data} /> },
            { key: 'extrato', label: 'Extracto', children: <Extrato conta={conta} /> },
            { key: 'mapa', label: 'Mapa de reconciliação', children: <Mapa conta={conta} data={data} /> },
            { key: 'hist', label: 'Histórico', children: <HistoricoFiltrado contaInicial={conta} /> },
          ]}
        />
      )}
    </>
  );
}

function useMapaReconciliacao(conta: string, data: Dayjs) {
  return useQuery({ queryKey: ['teso', 'reconciliacao', 'mapa', conta, dataApi(data)], queryFn: () => obter<MapaReconciliacao>('/tesouraria/reconciliacao/mapa', { codigo_conta: conta, data: dataApi(data) }) });
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

function Correspondencia({ conta, data }: { conta: string; data: Dayjs }) {
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

  return (
    <Space direction="vertical" style={{ width: '100%' }} size={16}>
      <Card
        title="Sugestões automáticas (1:1)"
        extra={
          <Space wrap>
            <span>Tolerância (dias)</span>
            <InputNumber min={0} max={5} value={tolerancia} onChange={(v) => setTolerancia(v ?? 1)} style={{ width: 70 }} />
            {podeConfirmar && (
              <Button
                type="primary"
                disabled={!selSug.length}
                loading={confirmar.isPending}
                onClick={() =>
                  confirmar.mutate({
                    tipo: 'AUTOMATICA',
                    grupos: (sugestoes.data ?? []).filter((s) => selSug.includes(chaveS(s))).map((s) => ({ extrato: [s.linha_extrato_id], lancamentos: [s.lancamento_id] })),
                  })
                }
              >
                Confirmar {selSug.length} sugestão(ões)
              </Button>
            )}
          </Space>
        }
      >
        <Table<SugestaoReconciliacao>
          rowKey={chaveS}
          size="small"
          loading={sugestoes.isFetching}
          dataSource={sugestoes.data}
          pagination={{ pageSize: 20 }}
          locale={{ emptyText: 'Sem sugestões: importe o extracto ou use a correspondência manual.' }}
          rowSelection={podeConfirmar ? { selectedRowKeys: selSug, onChange: (k) => setSelSug(k as string[]) } : undefined}
          scroll={scrollTabela()}
          columns={[
            { title: 'Extracto', render: (_, s) => { const e = porIdE.get(s.linha_extrato_id); return e ? `${formatarData(e.data)} · ${e.descricao ?? e.referencia ?? ''}` : `#${s.linha_extrato_id}`; } },
            { title: 'Diário', render: (_, s) => { const d = porIdD.get(s.lancamento_id); return d ? `${formatarData(d.data_documento)} · ${d.numero_lan} · ${d.descricao ?? ''}` : `#${s.lancamento_id}`; } },
            { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
            { title: 'Critério', dataIndex: 'criterio', render: (v: string) => <Tag>{CRITERIOS[v] ?? v}</Tag> },
          ]}
        />
      </Card>
      <Card
        title="Correspondência manual"
        extra={
          podeConfirmar && (
            <Space wrap>
              <PainelRascunhos
                conta={conta}
                extrato={selExt}
                lancamentos={selDia}
                aoAbrir={(r) => {
                  // repõe a selecção com as linhas do rascunho que ainda estão por reconciliar
                  setSelExt(r.grupos.flatMap((g) => g.extrato).filter((id) => porIdE.has(id)));
                  setSelDia(r.grupos.flatMap((g) => g.lancamentos).filter((id) => porIdD.has(id)));
                  message.info(`Rascunho #${r.id} aberto (${r.grupos.length} grupo(s)).`);
                }}
              />
              <Typography.Text type={soma.casa ? 'success' : 'secondary'}>
                Extracto {formatarKz(soma.extrato)} · Diário {formatarKz(soma.diario)} · Diferença {formatarKz(soma.diferenca)}
              </Typography.Text>
              <Button type="primary" disabled={!soma.casa} loading={confirmar.isPending} onClick={() => confirmar.mutate({ tipo: 'MANUAL', grupos: [{ extrato: selExt, lancamentos: selDia }] })}>
                Reconciliar selecção
              </Button>
            </Space>
          )
        }
      >
        <Row gutter={16}>
          <Col xs={24} lg={12}>
            <Typography.Title level={5}>Extracto por reconciliar</Typography.Title>
            <Table scroll={scrollTabela()}
              rowKey="id"
              size="small"
              loading={mapa.isLoading}
              dataSource={extrato}
              pagination={{ pageSize: 15 }}
              rowSelection={podeConfirmar ? { selectedRowKeys: selExt, onChange: (k) => setSelExt(k as number[]) } : undefined}
              columns={[
                { title: 'Data', dataIndex: 'data', render: formatarData },
                { title: 'Descrição', render: (_, l) => l.descricao ?? l.referencia ?? '—', ellipsis: true },
                { title: 'Entrada', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : null) },
                { title: 'Saída', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : null) },
              ]}
            />
          </Col>
          <Col xs={24} lg={12}>
            <Typography.Title level={5}>Diário por reconciliar</Typography.Title>
            <Table scroll={scrollTabela()}
              rowKey="id"
              size="small"
              loading={mapa.isLoading}
              dataSource={diario}
              pagination={{ pageSize: 15 }}
              rowSelection={podeConfirmar ? { selectedRowKeys: selDia, onChange: (k) => setSelDia(k as number[]) } : undefined}
              columns={[
                { title: 'Data', dataIndex: 'data_documento', render: formatarData },
                { title: 'Lançamento', dataIndex: 'numero_lan' },
                { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
                { title: 'Débito', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : null) },
                { title: 'Crédito', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : null) },
              ]}
            />
          </Col>
        </Row>
      </Card>
    </Space>
  );
}

function Extrato({ conta }: { conta: string }) {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [estado, setEstado] = useState<string>();
  const [importar, setImportar] = useState(false);
  const [editar, setEditar] = useState<LinhaExtratoBancario | null>(null);
  const linhas = useQuery({ queryKey: ['teso', 'extrato', conta, estado], queryFn: () => obter<LinhaExtratoBancario[]>('/tesouraria/extrato', { codigo_conta: conta, estado }) });
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
                  { titulo: 'Descrição', valor: (l) => l.descricao ?? '', quebrar: true },
                  { titulo: 'Entrada (Kz)', valor: (l) => (l.tipo_dc === 'C' ? l.valor : null), formato: 'moeda', somar: true },
                  { titulo: 'Saída (Kz)', valor: (l) => (l.tipo_dc === 'D' ? l.valor : null), formato: 'moeda', somar: true },
                  { titulo: 'Estado', valor: (l) => ROTULO_ESTADO_EXTRATO[l.estado] ?? l.estado },
                  { titulo: 'Reconciliação', valor: (l) => l.reconciliacao_codigo ?? '' },
                ],
                linhas: linhas.data ?? [],
                totais: true,
              }),
            })}
          />
        </Space>
      }
    >
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
          { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 320, responsive: ['sm'] },
          { title: 'Entrada', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : null) },
          { title: 'Saída', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : null) },
          { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
          { title: 'Reconciliação', dataIndex: 'reconciliacao_codigo', responsive: ['lg'] },
          {
            title: '',
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
      <Space size={40} wrap>
        <Statistic title="Saldo no diário" value={formatarKz(d.saldo_diario)} />
        <Statistic title="Diário por reconciliar (líquido)" value={formatarKz(d.por_reconciliar_diario.total)} />
        <Statistic title="Extracto por reconciliar (líquido)" value={formatarKz(d.por_reconciliar_extrato.total)} />
        <Statistic title="Saldo esperado no banco" value={formatarKz(d.saldo_banco_esperado)} />
      </Space>
      <Alert style={{ marginTop: 16 }} type="info" showIcon message="Saldo esperado no banco = saldo do diário − movimentos do diário por reconciliar + movimentos do extracto por reconciliar." />
    </Card>
  );
}


