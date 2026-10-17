import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Empty, Form, Input, Modal, Popconfirm, Row, Select, Space, Table, Tag, Typography, message, theme } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { ModalFormulario, SeccaoFormulario } from '@/componentes/formularios';
import { larguraModal, scrollTabela, useEcra } from '@/componentes/responsivo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { BotaoCsv, EtiquetaEstado, ValorKz } from './comum/Componentes';

interface Execucao {
  id: number;
  data_execucao: string | null;
  executado_em: string | null;
  data_fim: string | null;
  moeda: string | null;
  estado: string | null;
  utilizador: string | null;
  linhas: number | null;
  totais?: Record<string, unknown>;
}

interface Grupo {
  id: number;
  nome: string;
  holding: { id: number; nome: string; nif: string | null } | null;
  moeda_apresentacao: string;
  conta_reserva_cambial: string;
  eliminacao_ativa: boolean;
  prefixos_excluidos_eliminacao: string;
  conta_diferenca_eliminacao: string;
  membros: { empresa_id: number; nome: string; nif: string | null; percentagem: string; metodo: string }[];
  ultima_execucao_id: number | null;
  /** Resumo das eliminações da execução mais recente (null: sem eliminações registadas). */
  eliminacoes_ultima?: { execucao_id: number; divergentes: number } | null;
  execucoes: Execucao[];
}

interface MapaConsolidacao {
  moeda: string;
  data_fim: string;
  colunas: { chave: string; nome: string }[];
  linhas: { tipo: 'cabecalho' | 'conta' | 'subtotal'; codigo: string; descricao: string; valores?: Record<string, string> }[];
  total: Record<string, string>;
  resultado: Record<string, string>;
  lancamentos: number;
  aviso: string | null;
}

interface ValoresGrupo {
  nome: string;
  nif: string;
  moeda_apresentacao?: string;
  conta_reserva_cambial?: string;
  eliminacao_ativa: boolean;
  prefixos_excluidos_eliminacao?: string;
  conta_diferenca_eliminacao?: string;
  membros: number[];
}

interface Divergencia {
  a: string;
  b: string;
  saldo_ab: string;
  saldo_ba: string;
  dif_saldo: string;
  proveitos_ab: string;
  custos_ba: string;
  dif_ab: string;
  proveitos_ba: string;
  custos_ab: string;
  dif_ba: string;
}

const ROXO = '#7c3aed';
const ROTULO_SECCAO: React.CSSProperties = { fontSize: 12, fontWeight: 700, textTransform: 'uppercase', marginBottom: 8 };

/** «diferença» relevante como no legado: |v| ≥ 0,01 (js/consolidacao.js:1030). */
export const temDiferenca = (v: string | number | null | undefined) => Math.abs(Number(v ?? 0)) >= 0.01;
export const parDivergente = (d: Pick<Divergencia, 'dif_saldo' | 'dif_ab' | 'dif_ba'>) => temDiferenca(d.dif_saldo) || temDiferenca(d.dif_ab) || temDiferenca(d.dif_ba);

/**
 * Contabilidade › Consolidação de empresas (ecrã consolidacao, ADR-057).
 * Como o legado (renderConsolidacao, js/consolidacao.js:755-890): um cartão por holding com as acções de cada uma
 * (Mapa de Consolidação, Abrir nos Mapas, Eliminações e Divergências, Editar, Eliminar), as empresas do grupo,
 * a última consolidação, o bloco «Executar consolidação» e o histórico (últimas 5).
 */
export default function Consolidacao() {
  const { pode, empresas, recarregarEmpresas } = useSessao();
  const cliente = useQueryClient();
  const grupos = useQuery({ queryKey: ['contab', 'consolidacao', 'grupos'], queryFn: () => obter<Grupo[]>('/consolidacao/grupos') });
  const [edicao, setEdicao] = useState<Grupo | 'novo' | null>(null);
  const [mapaDe, setMapaDe] = useState<Grupo | null>(null);
  const [divergenciasDe, setDivergenciasDe] = useState<{ grupo: Grupo; execucao: number } | null>(null);
  const [execucaoAberta, setExecucaoAberta] = useState<number | null>(null);
  const [form] = Form.useForm<ValoresGrupo>();

  // a holding criada (ou eliminada) aparece (ou desaparece) logo na troca de empresa, sem novo login
  const invalidar = () => {
    void cliente.invalidateQueries({ queryKey: ['contab', 'consolidacao'] });
    void recarregarEmpresas().catch(() => undefined);
  };
  const gravar = useMutation({
    mutationFn: (v: ValoresGrupo) => (edicao && edicao !== 'novo' ? enviar<Grupo>('put', `/consolidacao/grupos/${edicao.id}`, v) : enviar<Grupo>('post', '/consolidacao/grupos', v)),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      invalidar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível gravar o grupo'),
  });

  const abrirEdicao = (g: Grupo | 'novo') => {
    form.resetFields();
    if (g === 'novo') form.setFieldsValue({ moeda_apresentacao: 'AOA', eliminacao_ativa: true, membros: [] });
    else
      form.setFieldsValue({
        nome: g.nome,
        nif: g.holding?.nif ?? '',
        moeda_apresentacao: g.moeda_apresentacao,
        conta_reserva_cambial: g.conta_reserva_cambial,
        eliminacao_ativa: g.eliminacao_ativa,
        prefixos_excluidos_eliminacao: g.prefixos_excluidos_eliminacao,
        conta_diferenca_eliminacao: g.conta_diferenca_eliminacao,
        membros: g.membros.map((m) => m.empresa_id),
      });
    setEdicao(g);
  };

  return (
    <>
      <CabecalhoPagina
        // legado: «<i class="fas fa-sitemap"></i> Consolidação de Empresas» (renderConsolidacao, js/consolidacao.js:773)
        titulo={
          <>
            <IconeFa nome="sitemap" /> Consolidação de Empresas
          </>
        }
        subtitulo="Crie uma holding, escolha as empresas do grupo e gere as contas consolidadas. Os mapas da contabilidade ficam disponíveis escolhendo a holding como empresa ativa."
        accoes={
          pode('consol_gerir') && (
            <Button type="primary" icon={<IconeFa nome="plus" />} onClick={() => abrirEdicao('novo')}>
              Nova Holding
            </Button>
          )
        }
      />
      {grupos.isLoading ? (
        <Card loading />
      ) : !grupos.data?.length ? (
        // legado: cartão vazio «Ainda não existe nenhuma holding» (js/consolidacao.js:782-786)
        <Card>
          <Empty
            image={<IconeFa nome="sitemap" style={{ fontSize: 48, opacity: 0.35 }} />}
            description={
              <>
                <Typography.Title level={5} style={{ marginBottom: 4 }}>Ainda não existe nenhuma holding</Typography.Title>
                <Typography.Text type="secondary">Clique em <strong>Nova Holding</strong> para escolher as empresas a consolidar.</Typography.Text>
              </>
            }
          />
        </Card>
      ) : (
        grupos.data.map((g) => (
          <CartaoHolding
            key={g.id}
            grupo={g}
            aoEditar={() => abrirEdicao(g)}
            aoAbrirMapa={() => setMapaDe(g)}
            aoAbrirDivergencias={(execucao) => setDivergenciasDe({ grupo: g, execucao })}
            aoAbrirExecucao={setExecucaoAberta}
            aoEliminado={invalidar}
          />
        ))
      )}

      {mapaDe && (
        <Modal open title={<><IconeFa nome="table" style={{ color: ROXO }} /> Mapa de Consolidação — {mapaDe.nome}</>} onCancel={() => setMapaDe(null)} footer={null} width={larguraModal(1200)}>
          <PainelMapa grupo={mapaDe} />
        </Modal>
      )}
      {divergenciasDe && (
        <Modal
          open
          title={<><IconeFa nome="exchange-alt" style={{ color: ROXO }} /> Eliminações e Divergências — {divergenciasDe.grupo.nome}</>}
          onCancel={() => setDivergenciasDe(null)}
          footer={null}
          width={larguraModal(1100)}
        >
          <EliminacoesExecucao id={divergenciasDe.execucao} />
        </Modal>
      )}
      {execucaoAberta && <DetalheExecucao id={execucaoAberta} aoFechar={() => setExecucaoAberta(null)} />}

      {/*
        Grade com secções (sem «Linhas»): cada grupo leva a sua lista de empresas e é raro criar vários de uma vez.
        «Moeda e eliminações» fica recolhida (valores por omissão AOA / eliminações activas).
      */}
      <ModalFormulario
        chave="contab.consolidacao.grupos"
        titulo={edicao === 'novo' ? 'Nova Holding' : 'Editar Holding'}
        aberto={edicao !== null}
        aoFechar={() => setEdicao(null)}
        criacao={false}
        campos={[]}
        gravarLinha={async () => undefined}
        destroyOnHidden={false}
        okText="Gravar"
        confirmLoading={gravar.isPending}
        onOk={() => form.submit()}
        width={larguraModal(640)}
      >
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)}>
          <SeccaoFormulario titulo="Holding e empresas">
            <Form.Item name="nome" label="Nome da holding" rules={[{ required: true }]}>
              <Input maxLength={255} placeholder="Ex.: GRUPO XPTO — CONSOLIDADO" />
            </Form.Item>
            <Form.Item name="nif" label="NIF da holding" rules={[{ required: true }]}>
              <Input maxLength={30} />
            </Form.Item>
            <Form.Item
              name="membros"
              label="Empresas a consolidar (100%, método integral)"
              rules={[{ required: true, message: 'Escolha pelo menos uma empresa.' }]}
              extra="A holding é uma empresa própria: aparece na troca de empresa a quem a cria e a quem acede a todas as empresas do grupo. Dentro da holding só estão o Início, o Dashboard e a Contabilidade (consolidação, mapas e lançamentos da holding)."
            >
              <Select mode="multiple" optionFilterProp="label" options={empresas.filter((e) => !e.e_consolidacao).map((e) => ({ value: e.id, label: e.nome }))} />
            </Form.Item>
          </SeccaoFormulario>
          <SeccaoFormulario key={edicao === 'novo' ? 'novo' : edicao?.id ?? 'fechado'} titulo="Moeda e eliminações" recolhida={edicao === 'novo'}>
            <Space wrap>
              <Form.Item name="moeda_apresentacao" label="Moeda por omissão">
                <Input maxLength={3} style={{ width: 100 }} />
              </Form.Item>
              <Form.Item name="conta_reserva_cambial" label="Conta de reservas de conversão">
                <Input maxLength={20} placeholder="599" style={{ width: 160 }} />
              </Form.Item>
              <Form.Item name="conta_diferenca_eliminacao" label="Conta de diferenças de eliminação">
                <Input maxLength={20} placeholder="598" style={{ width: 160 }} />
              </Form.Item>
            </Space>
            <Form.Item name="eliminacao_ativa" valuePropName="checked">
              <Checkbox>Eliminar operações intragrupo</Checkbox>
            </Form.Item>
            <Form.Item name="prefixos_excluidos_eliminacao" label="Contas não eliminadas (prefixos)">
              <Input maxLength={10} />
            </Form.Item>
          </SeccaoFormulario>
          <Typography.Text type="secondary">É exigido acesso à holding e a todas as empresas do grupo.</Typography.Text>
        </Form>
      </ModalFormulario>
    </>
  );
}

interface PropsCartao {
  grupo: Grupo;
  aoEditar: () => void;
  aoAbrirMapa: () => void;
  aoAbrirDivergencias: (execucaoId: number) => void;
  aoAbrirExecucao: (execucaoId: number) => void;
  aoEliminado: () => void;
}

/** Cartão de uma holding (legado js/consolidacao.js:787-887). As acções respeitam as permissões; o servidor volta a validá-las. */
function CartaoHolding({ grupo, aoEditar, aoAbrirMapa, aoAbrirDivergencias, aoAbrirExecucao, aoEliminado }: PropsCartao) {
  const { pode, empresas, escolherEmpresa } = useSessao();
  const { token } = theme.useToken();
  const navegar = useNavigate();
  const cliente = useQueryClient();
  // a lista vem do servidor ordenada da mais recente para a mais antiga (limite 5, como o «Histórico» do legado)
  const ultima = grupo.execucoes[0] ?? null;
  const [dataFim, setDataFim] = useState<Dayjs>(ultima?.data_fim ? dayjs(ultima.data_fim) : dayjs());
  const [moeda, setMoeda] = useState(grupo.moeda_apresentacao);
  const moedas = useQuery({ queryKey: ['sistema', 'moedas', 'activas'], queryFn: () => obter<{ codigo: string; nome: string }[]>('/sistema/moedas', { ativas: 1 }), staleTime: 300_000, retry: false });
  const opcoesMoeda = [...new Set(['AOA', grupo.moeda_apresentacao, ...(moedas.data ?? []).map((m) => m.codigo)])].map((c) => ({ value: c, label: c }));
  const holdingAcessivel = !!grupo.holding && empresas.some((e) => e.id === grupo.holding?.id);
  const el = grupo.eliminacoes_ultima ?? null;

  const executar = useMutation({
    mutationFn: () => enviar<{ execucao_id: number; equilibrado: boolean }>('post', `/consolidacao/grupos/${grupo.id}/executar`, { data_fim: dataApi(dataFim), moeda }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      if (!dados.equilibrado) message.warning('A consolidação não ficou equilibrada (débitos ≠ créditos): veja os totais da execução.');
      void cliente.invalidateQueries({ queryKey: ['contab', 'consolidacao'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível executar a consolidação'),
  });
  const eliminar = useMutation({
    mutationFn: () => enviar('delete', `/consolidacao/grupos/${grupo.id}`),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      aoEliminado();
    },
    onError: (e) => notificarErro(e, 'Não foi possível eliminar'),
  });
  // legado consolAbrirHolding (js/consolidacao.js:992): muda a empresa activa para a holding e abre os Relatórios Contabilísticos
  const abrirNosMapas = () =>
    grupo.holding &&
    escolherEmpresa(grupo.holding.id)
      .then(() => navegar('/m/contab/relatorios_contabeis'))
      .catch((e) => notificarErro(e, 'Não foi possível abrir a holding'));

  return (
    <Card
      style={{ marginBottom: 24, borderTop: `4px solid ${ROXO}` }}
      data-testid={`consol-holding-${grupo.id}`}
      title={
        <div style={{ whiteSpace: 'normal' }}>
          <span style={{ fontSize: 17 }}>
            <IconeFa nome="building" style={{ color: ROXO }} /> {grupo.holding?.nome ?? grupo.nome}
          </span>
          <div style={{ fontSize: 13, fontWeight: 400, color: token.colorTextSecondary, marginTop: 4 }}>
            NIF {grupo.holding?.nif || '-'} · Reservas de conversão: <strong>{grupo.conta_reserva_cambial || '5.9.9'}</strong>
          </div>
        </div>
      }
      extra={
        <Space wrap style={{ justifyContent: 'flex-end' }}>
          {ultima && (
            <Button size="small" style={{ color: ROXO, borderColor: ROXO }} icon={<IconeFa nome="table" />} onClick={aoAbrirMapa}>
              Mapa de Consolidação
            </Button>
          )}
          <Button size="small" icon={<IconeFa nome="chart-line" />} disabled={!holdingAcessivel} title={holdingAcessivel ? undefined : 'Não tem acesso à holding'} onClick={abrirNosMapas}>
            Abrir nos Mapas
          </Button>
          {el && (
            <Button size="small" danger={el.divergentes > 0} icon={<IconeFa nome="exchange-alt" />} onClick={() => aoAbrirDivergencias(el.execucao_id)}>
              Eliminações e Divergências
            </Button>
          )}
          {pode('consol_gerir') && (
            <Button size="small" icon={<IconeFa nome="edit" />} onClick={aoEditar}>
              Editar
            </Button>
          )}
          {pode('consol_eliminar') && (
            <Popconfirm
              title={`Eliminar a holding "${grupo.nome}"?`}
              description="Serão apagados os lançamentos consolidados, o plano de contas e as tabelas da holding. As empresas do grupo NÃO são afectadas."
              okText="Eliminar"
              cancelText="Cancelar"
              okButtonProps={{ danger: true }}
              onConfirm={() => eliminar.mutateAsync()}
            >
              <Button size="small" danger icon={<IconeFa nome="trash" />} aria-label={`Eliminar a holding ${grupo.nome}`} loading={eliminar.isPending} />
            </Popconfirm>
          )}
        </Space>
      }
    >
      <Row gutter={[20, 20]}>
        <Col xs={24} md={8}>
          <div style={{ ...ROTULO_SECCAO, color: token.colorTextSecondary }}>Empresas do grupo ({grupo.membros.length})</div>
          {grupo.membros.length ? (
            grupo.membros.map((m) => (
              <div key={m.empresa_id} style={{ padding: '6px 11px', background: token.colorFillAlter, border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 8, marginBottom: 6, fontSize: 13 }}>
                <strong>{m.nome}</strong> <Typography.Text type="secondary">NIF {m.nif || '-'}</Typography.Text>
              </div>
            ))
          ) : (
            <Typography.Text type="secondary" italic>Nenhuma</Typography.Text>
          )}
        </Col>
        <Col xs={24} md={8}>
          <div style={{ ...ROTULO_SECCAO, color: token.colorTextSecondary }}>Última consolidação</div>
          {ultima ? (
            <div style={{ fontSize: 13, lineHeight: 1.7 }}>
              Até <strong>{formatarData(ultima.data_fim)}</strong> em <strong>{ultima.moeda}</strong> · executada em {formatarData(ultima.data_execucao ?? ultima.executado_em)} por {ultima.utilizador || '-'}
              <br />
              Estado: <EtiquetaEstado estado={ultima.estado} />
              {ultima.linhas != null && ` · ${Number(ultima.linhas).toLocaleString('pt-PT')} linhas`}
            </div>
          ) : (
            <Typography.Text type="secondary" italic>Ainda não consolidado</Typography.Text>
          )}
        </Col>
        <Col xs={24} md={8}>
          <div style={{ background: token.colorPrimaryBg, border: `1px solid ${token.colorPrimaryBorder}`, borderRadius: 12, padding: 16 }}>
            <div style={{ ...ROTULO_SECCAO, color: ROXO }}>Executar consolidação</div>
            {pode('consol_executar') ? (
              <Space wrap align="end">
                <div>
                  <div style={{ fontSize: 12 }}>Até à data</div>
                  <DatePicker value={dataFim} onChange={(v) => v && setDataFim(v)} format="DD/MM/YYYY" allowClear={false} aria-label="Até à data" />
                </div>
                <div>
                  <div style={{ fontSize: 12 }}>Moeda</div>
                  <Select value={moeda} onChange={setMoeda} options={opcoesMoeda} style={{ width: 100 }} aria-label="Moeda" />
                </div>
                {/* legado: «Consolidar» a roxo (#7c3aed, js/consolidacao.js:824) */}
                <Button
                  type="primary"
                  icon={<IconeFa nome="play" />}
                  style={{ background: ROXO, borderColor: ROXO }}
                  loading={executar.isPending}
                  onClick={() =>
                    Modal.confirm({
                      title: `Consolidar até ${dataFim.format('DD/MM/YYYY')} em ${moeda}?`,
                      content: 'As linhas geradas pela execução anterior na holding são substituídas; as manuais são preservadas.',
                      okText: 'Executar',
                      cancelText: 'Cancelar',
                      onOk: () => executar.mutateAsync(),
                    })
                  }
                >
                  Consolidar
                </Button>
              </Space>
            ) : (
              <Typography.Text type="secondary">Sem permissão para executar.</Typography.Text>
            )}
          </div>
        </Col>
      </Row>
      {grupo.execucoes.length > 1 && (
        <div style={{ marginTop: 16 }}>
          <div style={{ ...ROTULO_SECCAO, color: token.colorTextSecondary, marginBottom: 6 }}>Histórico</div>
          <Table<Execucao>
            scroll={scrollTabela()}
            rowKey="id"
            size="small"
            pagination={false}
            dataSource={grupo.execucoes}
            onRow={(r) => ({ onClick: () => aoAbrirExecucao(r.id), style: { cursor: 'pointer' } })}
            columns={[
              // ordem do legado (js/consolidacao.js:833): Execução · Até · Moeda · Utilizador · Linhas · Estado
              { title: 'Execução', dataIndex: 'executado_em', render: formatarDataHora },
              { title: 'Até', dataIndex: 'data_fim', render: formatarData },
              { title: 'Moeda', dataIndex: 'moeda' },
              { title: 'Utilizador', dataIndex: 'utilizador', render: (v: string | null) => v || '-' },
              { title: 'Linhas', dataIndex: 'linhas', align: 'right', render: (v: number | null) => (v == null ? '-' : Number(v).toLocaleString('pt-PT')) },
              { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
            ]}
          />
        </div>
      )}
    </Card>
  );
}

/** Painel «Eliminações intragrupo» de uma execução (legado htmlEliminacoes, js/consolidacao.js:1026-1074). */
function EliminacoesExecucao({ id }: { id: number }) {
  const consulta = useQuery({ queryKey: ['contab', 'consolidacao', 'execucao', id], queryFn: () => obter<Execucao>(`/consolidacao/execucoes/${id}`) });
  if (consulta.isLoading) return <Card loading />;
  const e = consulta.data;
  const el = (e?.totais?.eliminacoes ?? null) as { ativa?: boolean; linhas?: number; diferenca?: string; excluidas?: string[]; sem_nif?: string[]; divergencias?: Divergencia[] } | null;
  return (
    <>
      {e && (
        <Typography.Paragraph style={{ marginTop: 0 }}>
          Consolidação até <strong>{formatarData(e.data_fim)}</strong> em <strong>{e.moeda}</strong>, executada em {formatarData(e.data_execucao ?? e.executado_em)}.
        </Typography.Paragraph>
      )}
      <PainelEliminacoes el={el} nome={`divergencias_intragrupo_${e?.data_fim ?? id}_${e?.moeda ?? ''}`} />
    </>
  );
}

function PainelEliminacoes({ el, nome }: { el: { ativa?: boolean; linhas?: number; diferenca?: string; excluidas?: string[]; sem_nif?: string[]; divergencias?: Divergencia[] } | null; nome: string }) {
  if (!el) return <Typography.Text italic>Esta consolidação foi executada antes das eliminações intragrupo. Consolide novamente.</Typography.Text>;
  if (el.ativa === false) return <Alert type="info" showIcon message="Eliminações intragrupo desactivadas nesta holding." />;
  const divs = el.divergencias ?? [];
  const divergentes = divs.filter(parDivergente).length;
  const ok = !temDiferenca(el.diferenca) && divergentes === 0;
  const celDif = (v: string) => (temDiferenca(v) ? <Typography.Text type="danger" strong>{formatarKz(v)}</Typography.Text> : <Typography.Text type="success" aria-label="Sem diferença"><IconeFa nome="check" /></Typography.Text>);
  const v = (k: keyof Divergencia) => ({ dataIndex: k, align: 'right' as const, render: (x: string) => formatarKz(x) });
  return (
    <Alert
      type={ok ? 'success' : 'error'}
      message={
        <Space wrap style={{ justifyContent: 'space-between', width: '100%' }}>
          <strong><IconeFa nome="exchange-alt" style={{ color: ROXO }} /> Eliminações intragrupo · {Number(el.linhas ?? 0).toLocaleString('pt-PT')} linhas</strong>
          <Space>
            <Tag color={ok ? 'success' : 'error'}>{ok ? 'Sem divergências' : `${divergentes} par(es) com divergência`}</Tag>
            <BotaoCsv<Divergencia>
              nome={nome}
              linhas={divs}
              colunas={[
                { titulo: 'Empresa A', valor: (d) => d.a },
                { titulo: 'Empresa B', valor: (d) => d.b },
                { titulo: 'Saldo A com B', valor: (d) => d.saldo_ab, numerico: true },
                { titulo: 'Saldo B com A', valor: (d) => d.saldo_ba, numerico: true },
                { titulo: 'Diferença saldos', valor: (d) => d.dif_saldo, numerico: true },
                { titulo: 'Proveitos A com B', valor: (d) => d.proveitos_ab, numerico: true },
                { titulo: 'Custos B com A', valor: (d) => d.custos_ba, numerico: true },
                { titulo: 'Diferença A→B', valor: (d) => d.dif_ab, numerico: true },
                { titulo: 'Proveitos B com A', valor: (d) => d.proveitos_ba, numerico: true },
                { titulo: 'Custos A com B', valor: (d) => d.custos_ab, numerico: true },
                { titulo: 'Diferença B→A', valor: (d) => d.dif_ba, numerico: true },
              ]}
            />
          </Space>
        </Space>
      }
      description={
        <>
          {temDiferenca(el.diferenca) && (
            <div><Typography.Text type="danger">As eliminações não fecham: diferença de <strong>{formatarKz(el.diferenca)}</strong> levada à conta de diferenças de eliminação.</Typography.Text></div>
          )}
          {!!el.sem_nif?.length && <div><Typography.Text type="warning">Empresas sem NIF (as operações com elas não podem ser identificadas): {el.sem_nif.join(', ')}</Typography.Text></div>}
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            Contas nunca eliminadas: {el.excluidas?.length ? el.excluidas.join(', ') : '-'} · O saldo de A com B deve ser o simétrico do saldo de B com A; os proveitos de uma empresa com a outra devem igualar os custos da outra.
          </Typography.Text>
          {divs.length > 0 && (
            <Table<Divergencia>
              style={{ marginTop: 8 }}
              scroll={scrollTabela()}
              size="small"
              pagination={false}
              rowKey={(d) => `${d.a}|${d.b}`}
              dataSource={divs}
              columns={[
                { title: 'Empresa A', dataIndex: 'a' },
                { title: 'Empresa B', dataIndex: 'b' },
                { title: 'Saldos recíprocos (D−C)', children: [{ title: 'A com B', ...v('saldo_ab') }, { title: 'B com A', ...v('saldo_ba') }, { title: 'Diferença', dataIndex: 'dif_saldo', align: 'right', render: celDif }] },
                { title: 'Proveitos de A com B vs Custos de B com A', children: [{ title: 'Proveitos A', ...v('proveitos_ab') }, { title: 'Custos B', ...v('custos_ba') }, { title: 'Diferença', dataIndex: 'dif_ab', align: 'right', render: celDif }] },
                { title: 'Proveitos de B com A vs Custos de A com B', children: [{ title: 'Proveitos B', ...v('proveitos_ba') }, { title: 'Custos A', ...v('custos_ab') }, { title: 'Diferença', dataIndex: 'dif_ba', align: 'right', render: celDif }] },
              ]}
            />
          )}
        </>
      }
    />
  );
}

function DetalheExecucao({ id, aoFechar }: { id: number; aoFechar: () => void }) {
  const consulta = useQuery({ queryKey: ['contab', 'consolidacao', 'execucao', id], queryFn: () => obter<Execucao>(`/consolidacao/execucoes/${id}`) });
  const t = (consulta.data?.totais ?? {}) as Record<string, unknown> & { eliminacoes?: Record<string, unknown>; empresas?: Record<string, unknown>[] };
  const el = t.eliminacoes ?? {};
  return (
    <Modal open title={`Execução ${id}`} onCancel={aoFechar} footer={null} width={larguraModal(860)}>
      {consulta.isLoading ? (
        <Card loading />
      ) : (
        <>
          <Descriptions bordered size="small" column={{ xs: 1, md: 2 }}>
            <Descriptions.Item label="Linhas">{String(t.linhas ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Linhas substituídas">{String(t.linhas_apagadas ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Débitos">{formatarKz(t.debitos as string)}</Descriptions.Item>
            <Descriptions.Item label="Créditos">{formatarKz(t.creditos as string)}</Descriptions.Item>
            <Descriptions.Item label="Reserva cambial">{formatarKz(t.reserva as string)}</Descriptions.Item>
            <Descriptions.Item label="Ajustes de conversão">{String(t.ajustes_conversao ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Contas criadas">{String(t.contas_criadas ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Terceiros criados">{String(t.terceiros_criados ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Eliminações (linhas)">{String(el.linhas ?? '—')}</Descriptions.Item>
            <Descriptions.Item label="Diferença de eliminação">{formatarKz(el.diferenca as string)}</Descriptions.Item>
          </Descriptions>
          {t.eliminacoes && (
            <div style={{ marginTop: 12 }}>
              <PainelEliminacoes el={t.eliminacoes as Parameters<typeof PainelEliminacoes>[0]['el']} nome={`divergencias_intragrupo_${id}`} />
            </div>
          )}
          {Array.isArray(t.empresas) && t.empresas.length > 0 && (
            <Table
              scroll={scrollTabela()}
              rowKey={(_, i) => String(i)}
              size="small"
              style={{ marginTop: 12 }}
              pagination={false}
              dataSource={t.empresas}
              columns={Object.keys(t.empresas[0]).map((k) => ({ title: k.replace(/_/g, ' '), dataIndex: k, render: (v: unknown) => String(v ?? '—') }))}
            />
          )}
        </>
      )}
    </Modal>
  );
}

function PainelMapa({ grupo }: { grupo: Grupo }) {
  const { token } = theme.useToken();
  const [filtros, setFiltros] = useState<Record<string, unknown>>({ nivel: '2', modo: 'saldo', ocultar_zeros: 1 });
  const [form] = Form.useForm();
  const { telemovel } = useEcra();
  const mapa = useQuery({ queryKey: ['contab', 'consolidacao', 'mapa', grupo.id, filtros], queryFn: () => obter<MapaConsolidacao>(`/consolidacao/grupos/${grupo.id}/mapa`, filtros) });
  const d = mapa.data;
  type Linha = MapaConsolidacao['linhas'][number];
  const refMapa = useRef<HTMLDivElement>(null);
  const estilo = (l: Linha) => (l.tipo === 'cabecalho' ? { fontWeight: 600, background: token.colorFillAlter } : l.tipo === 'subtotal' ? { fontWeight: 600 } : {});

  return (
    <Card>
      <Form
        form={form}
        layout="inline"
        initialValues={{ nivel: '2', modo: 'saldo', ocultar_zeros: true }}
        onFinish={(v) => setFiltros({ ...v, data_inicio: dataApi(v.data_inicio), data_fim: dataApi(v.data_fim), ocultar_zeros: v.ocultar_zeros ? 1 : undefined })}
        style={{ marginBottom: 16, rowGap: 8 }}
      >
        <Form.Item name="nivel" label="Nível">
          <Select style={{ width: 130 }} options={[{ value: 'classe', label: 'Classe' }, { value: '2', label: '2 dígitos' }, { value: 'conta', label: 'Conta' }]} />
        </Form.Item>
        <Form.Item name="modo" label="Valores">
          <Select style={{ width: 140 }} options={[{ value: 'saldo', label: 'Saldo' }, { value: 'periodo', label: 'Período' }]} />
        </Form.Item>
        <Form.Item name="data_inicio" label="De">
          <DatePicker format="DD/MM/YYYY" />
        </Form.Item>
        <Form.Item name="data_fim" label="Até">
          <DatePicker format="DD/MM/YYYY" />
        </Form.Item>
        <Form.Item name="contas" label="Contas">
          <Input style={{ width: 140 }} maxLength={200} allowClear />
        </Form.Item>
        <Form.Item name="ocultar_zeros" valuePropName="checked">
          <Checkbox>Ocultar zeros</Checkbox>
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={mapa.isFetching}>Actualizar</Button>
      </Form>
      {d && (
        <>
          {d.aviso && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={d.aviso} />}
          <Space wrap style={{ marginBottom: 12 }}>
            <Typography.Text type="secondary">Moeda {d.moeda} · até {formatarData(d.data_fim)} · {d.lancamentos} lançamento(s)</Typography.Text>
            <BotoesExportar
              obterPedido={() =>
                refMapa.current && {
                  titulo: `Mapa de Consolidação · ${grupo.nome}`,
                  periodo: `${filtros.data_inicio ? `${formatarData(filtros.data_inicio as string)} a ` : 'Até '}${formatarData(d.data_fim)}`,
                  filtros: [`Moeda: ${d.moeda}`, `Nível: ${filtros.nivel === 'classe' ? 'classe' : filtros.nivel === 'conta' ? 'conta' : '2 dígitos'}`, `Valores: ${filtros.modo === 'periodo' ? 'período' : 'saldo'}`, filtros.contas ? `Contas: ${String(filtros.contas)}` : null, `${d.lancamentos} lançamento(s)`],
                  conteudo: refMapa.current,
                }
              }
            />
            <BotaoCsv<Linha>
              nome={`mapa_consolidacao_${grupo.id}`}
              linhas={d.linhas.filter((l) => l.tipo !== 'cabecalho')}
              colunas={[{ titulo: 'Código', valor: (l) => l.codigo }, { titulo: 'Descrição', valor: (l) => l.descricao }, ...d.colunas.map((c) => ({ titulo: c.nome, valor: (l: Linha) => l.valores?.[c.chave], numerico: true }))]}
            />
          </Space>
          <div ref={refMapa}>
          <Table<Linha>
            rowKey={(l, i) => `${l.tipo}|${l.codigo}|${i}`}
            size="small"
            pagination={false}
            dataSource={d.linhas}
            scroll={scrollTabela()}
            onRow={(l) => ({ style: estilo(l) })}
            columns={[
              { title: 'Código', dataIndex: 'codigo', fixed: telemovel ? undefined : 'left' },
              { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 240 },
              ...d.colunas.map((c) => ({
                title: c.nome,
                key: c.chave,
                align: 'right' as const,
                render: (_: unknown, l: Linha) => (l.valores ? <ValorKz valor={l.valores[c.chave]} forte={c.chave === 'TOTAL'} discretoSeZero /> : null),
              })),
            ]}
            summary={() => (
              <>
                <Table.Summary.Row>
                  <Table.Summary.Cell index={0} colSpan={2}><strong>Total</strong></Table.Summary.Cell>
                  {d.colunas.map((c, i) => <Table.Summary.Cell key={c.chave} index={i + 2} align="right"><ValorKz valor={d.total[c.chave]} forte /></Table.Summary.Cell>)}
                </Table.Summary.Row>
                <Table.Summary.Row>
                  <Table.Summary.Cell index={0} colSpan={2}><strong>Resultado (classes 6 e 7)</strong></Table.Summary.Cell>
                  {d.colunas.map((c, i) => <Table.Summary.Cell key={c.chave} index={i + 2} align="right"><ValorKz valor={d.resultado[c.chave]} forte /></Table.Summary.Cell>)}
                </Table.Summary.Row>
              </>
            )}
          />
          </div>
        </>
      )}
    </Card>
  );
}
