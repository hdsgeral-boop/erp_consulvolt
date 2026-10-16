import { Alert, Button, Card, Checkbox, Collapse, DatePicker, Flex, Form, Input, Modal, Select, Space, Tag, Typography, message, theme } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useRef, useState, type Key } from 'react';
import { useLocation } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BotaoImprimir, pares, type PedidoImpressao } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { EstadoVazio, TabelaComModos } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { equilibrio, somar } from '@/utilitarios/decimal';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { Extrato, MovimentoExtrato } from '../api';
import { IndicadorEquilibrio, ValorKz } from '../comum/Componentes';
import { filtrosDosParametros, periodoDosParametros, tabelaDeColunas } from '../comum/impressao';
import { SeletorAux, SeletorConta, SeletorDiario, SeletorTerceiro, SeletorUnidade } from '../comum/Seletores';
import { useAbrirLancamento, useMapa } from '../comum/useMapa';
import type { PedidoExtratoConta } from './MapaBalancete';
import { contasParaColar, descreverUltimo, normalizarContasColadas } from './separadoresRelatorios';
import { TituloMapa } from './TituloMapa';

interface Compensacao {
  reconciliacao_codigo: string;
  data: string | null;
  estado: string | null;
  valor_total: string | null;
  linhas: { id: number; data_documento: string; codigo_conta: string; numero_lan: string; numero_documento: string | null; descricao: string | null; tipo_dc: 'D' | 'C'; valor: string }[];
}

interface ValoresExtrato {
  inicio: Dayjs | null;
  fim: Dayjs | null;
  ano?: number;
  mes?: number;
  filtro_contas?: string;
  terceiro_id?: number;
  tipo: 'todos' | 'aberto' | 'compensado';
  diario_id?: number;
  centro_custo_id?: number;
  unidade_negocio_id?: number;
  incluir_classe_9?: boolean;
}

/** «Todo histórico» (setExtratoAllDates, js/ui_reports.js:1788-1794). */
const TODO_HISTORICO = { inicio: dayjs('1900-01-01'), fim: dayjs('2099-12-31') };
const MES_ACTUAL = () => ({ inicio: dayjs().startOf('month'), fim: dayjs().endOf('month') });
const ANOS = Array.from({ length: dayjs().year() - 2022 }, (_, i) => 2023 + i);

/** Parâmetros do pedido; sem conta nem terceiro pede o último lançamento da empresa (abertura do legado). */
function paraParametros(v: ValoresExtrato): Record<string, unknown> {
  const p: Record<string, unknown> = { data_inicio: dataApi(v.inicio), data_fim: dataApi(v.fim), tipo: v.tipo };
  for (const k of ['filtro_contas', 'terceiro_id', 'diario_id', 'centro_custo_id', 'unidade_negocio_id'] as const) {
    const x = v[k];
    if (x !== undefined && x !== null && x !== '') p[k] = typeof x === 'string' ? x.trim() : x;
  }
  if (v.incluir_classe_9) p.incluir_classe_9 = 1;
  if (!p.filtro_contas && !p.terceiro_id) p.ultimo_lancamento = 1;
  return p;
}

/**
 * Separador «Extrato» (Extrato de Conta Corrente, gerarExtratoContaCorrente — js/ui_reports.js:1480-2300): abre já
 * calculado («Todo histórico», último lançamento da empresa, como no legado), com Colar múltiplas contas, Gerar Extrato,
 * Nova Consulta, Imprimir, Extrair, Ver Ecrã Cheio, Excel e Colunas; colunas Data, Conta, Nome conta, Terceiro, Nº Doc,
 * Contrapartida, Descrição, Comp., Débito, Crédito, Saldo acumulado e Ações («Ver Fluxo Completo»). Mantém a compensação
 * de movimentos e a regularização de diferenças.
 */
export default function MapaExtrato() {
  const { token } = theme.useToken();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const abrir = useAbrirLancamento();
  const { state } = useLocation() as { state?: { extrato?: PedidoExtratoConta } | null };
  const mapa = useMapa<Extrato>('extrato', '/contabilidade/relatorios/extrato');
  const [form] = Form.useForm<ValoresExtrato>();
  const [seleccao, setSeleccao] = useState<Key[]>([]);
  const [regularizar, setRegularizar] = useState(false);
  const [colar, setColar] = useState<string | null>(null);
  const [codigo, setCodigo] = useState<string | null>(null);
  const [limpo, setLimpo] = useState(false);
  const [criterio, setCriterio] = useState(false);
  const area = useRef<HTMLDivElement>(null);
  const [formReg] = Form.useForm<{ codigo_conta: string; data: Dayjs; diario_id?: number }>();
  const d = limpo ? undefined : mapa.data;
  const podeCompensar = pode('contab_compensar');
  const { calcular } = mapa;

  const gerar = (v: ValoresExtrato) => {
    if (!v.inicio && !v.fim) return void message.warning('Deve preencher pelo menos uma das datas (Data Inicial ou Data Final).');
    setSeleccao([]);
    setLimpo(false);
    calcular(paraParametros(v));
  };

  // Abertura: conta vinda do balancete (drill-down) ou «Todo histórico» com o último lançamento (js/ui_reports.js:1640-1680)
  const pedidoInicial = state?.extrato;
  useEffect(() => {
    const v: ValoresExtrato = pedidoInicial
      ? {
          inicio: pedidoInicial.inicio ? dayjs(pedidoInicial.inicio) : TODO_HISTORICO.inicio,
          fim: pedidoInicial.fim ? dayjs(pedidoInicial.fim) : TODO_HISTORICO.fim,
          filtro_contas: pedidoInicial.conta,
          terceiro_id: pedidoInicial.terceiroId ?? undefined,
          tipo: 'todos',
        }
      : { ...TODO_HISTORICO, tipo: 'todos' };
    form.setFieldsValue(v);
    setCriterio(!!pedidoInicial);
    gerar(v);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pedidoInicial]);

  const compensacao = useQuery({
    queryKey: ['contab', 'compensacao', codigo],
    queryFn: () => obter<Compensacao>(`/contabilidade/compensacoes/${codigo}`),
    enabled: !!codigo,
  });
  const aposMutacao = (mensagem: string) => {
    message.success(mensagem);
    setSeleccao([]);
    setRegularizar(false);
    setCodigo(null);
    void cliente.invalidateQueries({ queryKey: ['contab'] });
  };
  const compensar = useMutation({
    mutationFn: () => enviar('post', '/contabilidade/compensacoes', { linhas: seleccao }),
    onSuccess: ({ mensagem }) => aposMutacao(mensagem),
    onError: (e) => notificarErro(e, 'Não foi possível compensar'),
  });
  const regularizacao = useMutation({
    mutationFn: (v: { codigo_conta: string; data: Dayjs; diario_id?: number }) =>
      enviar('post', '/contabilidade/compensacoes/regularizar', { linhas: seleccao, codigo_conta: v.codigo_conta, data: dataApi(v.data), diario_id: v.diario_id }),
    onSuccess: ({ mensagem }) => aposMutacao(mensagem),
    onError: (e) => notificarErro(e, 'Não foi possível regularizar'),
  });
  const reverter = useMutation({
    mutationFn: (c: string) => enviar('delete', `/contabilidade/compensacoes/${c}`),
    onSuccess: ({ mensagem }) => aposMutacao(mensagem),
    onError: (e) => notificarErro(e, 'Não foi possível reverter a compensação'),
  });

  const movimentos = d?.movimentos ?? [];
  const seleccionadas = movimentos.filter((m) => seleccao.includes(m.id));
  const eq = equilibrio(seleccionadas);
  const comMoeda = movimentos.some((m) => m.codigo_moeda && m.codigo_moeda !== 'AOA');
  const somaDC = (linhas: MovimentoExtrato[], dc: 'D' | 'C') => formatarKz(somar(linhas.filter((l) => l.tipo_dc === dc).map((l) => l.valor)));

  const colunas: ColunaApi<MovimentoExtrato>[] = [
    { title: 'Data', dataIndex: 'data_documento', render: formatarData, width: 100 },
    { title: 'Conta', dataIndex: 'codigo_conta' },
    { title: 'Nome Conta', dataIndex: 'descricao_conta', ellipsis: true, width: 200, render: (v: string | null) => <Typography.Text type="secondary">{v ?? '-'}</Typography.Text>, valorImpressao: (r) => r.descricao_conta ?? '-' },
    { title: 'Terceiro', dataIndex: 'terceiro', ellipsis: true, width: 180, render: (v: string | null) => <Typography.Text type="secondary">{v?.trim() || '-'}</Typography.Text>, valorImpressao: (r) => r.terceiro?.trim() || '-' },
    { title: 'Nº Doc', dataIndex: 'numero_documento', render: (v: string | null) => v ?? '' },
    { title: 'Contrapartida', dataIndex: 'contrapartidas', ellipsis: true, width: 160, render: (v: string | null) => <Typography.Text type="secondary">{v || '-'}</Typography.Text>, valorImpressao: (r) => r.contrapartidas || '-' },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 280, render: (v: string | null) => <span title={v ?? ''}>{v || '-'}</span>, valorImpressao: (r) => r.descricao || '-' },
    {
      title: 'Comp.',
      dataIndex: 'reconciliacao_codigo',
      align: 'center',
      valorImpressao: (r) => r.reconciliacao_codigo ?? '',
      render: (v: string | null) =>
        v ? (
          <Tag color="blue" style={{ cursor: 'pointer' }} onClick={() => setCodigo(v)} title="Ver o auto de compensação">
            <IconeFa nome="link" /> {v.split('_')[2] || v}
          </Tag>
        ) : null,
    },
    { title: 'Débito', key: 'debito', align: 'right', render: (_, r) => (r.tipo_dc === 'D' ? <ValorKz valor={r.valor} /> : null), valorImpressao: (r) => (r.tipo_dc === 'D' ? formatarKz(r.valor) : ''), total: (l) => somaDC(l, 'D'), totalImpressao: (l) => somaDC(l, 'D') },
    { title: 'Crédito', key: 'credito', align: 'right', render: (_, r) => (r.tipo_dc === 'C' ? <ValorKz valor={r.valor} /> : null), valorImpressao: (r) => (r.tipo_dc === 'C' ? formatarKz(r.valor) : ''), total: (l) => somaDC(l, 'C'), totalImpressao: (l) => somaDC(l, 'C') },
    ...(comMoeda
      ? ([
          { title: 'Moeda', dataIndex: 'codigo_moeda', align: 'center', render: (v: string | null) => (v && v !== 'AOA' ? v : '') },
          { title: 'Valor na Moeda', dataIndex: 'valor_moeda', align: 'right', render: (v: string | null, r) => (r.codigo_moeda && r.codigo_moeda !== 'AOA' && v ? `${r.tipo_dc === 'C' ? '-' : ''}${formatarKz(v)}` : '') },
        ] as ColunaApi<MovimentoExtrato>[])
      : []),
    { title: 'Saldo Acumulado', dataIndex: 'saldo', align: 'right', render: (v: string) => <ValorKz valor={v} forte />, ocultavel: false },
  ];

  const parametros = mapa.parametros;
  const ultimo = d?.ambito === 'ultimo_lancamento';
  const pedido = async (): Promise<PedidoImpressao | null> =>
    d
      ? {
          titulo: 'Extrato de Conta Corrente',
          periodo: periodoDosParametros(parametros),
          filtros: [
            ...filtrosDosParametros(parametros),
            ultimo ? 'Último lançamento registado' : null,
            parametros?.tipo && parametros.tipo !== 'todos' ? `Movimentos: ${parametros.tipo === 'aberto' ? 'apenas em aberto' : 'apenas compensados'}` : null,
          ].filter((x): x is string => !!x),
          conteudo:
            pares([
              ['Saldo inicial', formatarKz(d.saldo_inicial)],
              ['Débitos', formatarKz(d.debito)],
              ['Créditos', formatarKz(d.credito)],
              ['Saldo final', formatarKz(d.saldo_final)],
            ], 4) + (await tabelaDeColunas(colunas, d.movimentos)),
        }
      : null;

  const ecraCheio = () => {
    if (document.fullscreenElement) return void document.exitFullscreen?.().catch(() => undefined);
    void area.current?.requestFullscreen?.().catch(() => undefined);
  };

  const novaConsulta = () => {
    form.setFieldsValue({ ...MES_ACTUAL(), ano: undefined, mes: undefined, filtro_contas: undefined, terceiro_id: undefined, tipo: 'todos' });
    setCriterio(false);
    setSeleccao([]);
    setLimpo(true);
  };

  const aplicarAnoMes = () => {
    const { ano, mes } = form.getFieldsValue(['ano', 'mes']);
    if (!ano) return;
    const base = dayjs(`${ano}-${String(mes ?? 1).padStart(2, '0')}-01`);
    form.setFieldsValue(mes ? { inicio: base, fim: base.endOf('month') } : { inicio: base.startOf('year'), fim: base.endOf('year') });
  };

  return (
    <Card>
      <TituloMapa titulo="Extrato de Conta Corrente" />
      <Form<ValoresExtrato>
        form={form}
        layout="vertical"
        initialValues={{ ...TODO_HISTORICO, tipo: 'todos' }}
        onFinish={gerar}
        onValuesChange={(_, todos) => setCriterio(!!(todos.filtro_contas?.trim() || todos.terceiro_id))}
        style={{ background: token.colorFillAlter, border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadius, padding: '12px 16px', marginBottom: 16 }}
      >
        <Flex gap={12} wrap align="end">
          <Form.Item name="inicio" label="Data Inicial" style={{ marginBottom: 8 }}>
            <DatePicker format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item name="fim" label="Data Final" style={{ marginBottom: 8 }}>
            <DatePicker format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item style={{ marginBottom: 8 }}>
            <Button
              size="small"
              icon={<IconeFa nome="calendar-alt" />}
              title="Selecionar Todo o histórico"
              onClick={() => {
                form.setFieldsValue({ ...TODO_HISTORICO, ano: undefined, mes: undefined });
                form.submit();
              }}
            >
              Todo histórico
            </Button>
          </Form.Item>
          <Form.Item name="ano" label="Ano" style={{ marginBottom: 8 }}>
            <Select allowClear placeholder="-" style={{ width: 90 }} options={ANOS.map((a) => ({ value: a, label: String(a) }))} onChange={aplicarAnoMes} />
          </Form.Item>
          <Form.Item name="mes" label="Período" style={{ marginBottom: 8 }}>
            <Select allowClear placeholder="-" style={{ width: 90 }} options={Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: String(i + 1) }))} onChange={aplicarAnoMes} />
          </Form.Item>
          <Form.Item label="Conta(s)" style={{ marginBottom: 8, flex: '1 1 200px' }}>
            <Space.Compact style={{ width: '100%' }}>
              <Form.Item name="filtro_contas" noStyle>
                <Input placeholder="Ex: 11, 21-25, 31*" maxLength={500} allowClear />
              </Form.Item>
              <Button icon={<IconeFa nome="paste" />} title="Colar múltiplas contas" aria-label="Colar múltiplas contas" onClick={() => setColar(contasParaColar(form.getFieldValue('filtro_contas')))} />
            </Space.Compact>
          </Form.Item>
          <Form.Item name="terceiro_id" label="Terceiro (Cliente/Fornecedor)" style={{ marginBottom: 8, flex: '1 1 220px' }}>
            <SeletorTerceiro placeholder="[Todos os Terceiros com Movimento]" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="tipo" label="Exibir Movimentos" style={{ marginBottom: 8 }}>
            <Select
              style={{ width: 190 }}
              options={[
                { value: 'todos', label: 'Todos os Movimentos' },
                { value: 'aberto', label: 'Apenas em Aberto' },
                { value: 'compensado', label: 'Apenas Compensados' },
              ]}
            />
          </Form.Item>
          <Form.Item style={{ marginBottom: 8 }}>
            <Space wrap>
              <Button type="primary" htmlType="submit" icon={<IconeFa nome="search" />} loading={mapa.isFetching}>
                Gerar Extrato
              </Button>
              <Button icon={<IconeFa nome="sync" />} onClick={novaConsulta}>
                Nova Consulta
              </Button>
              <span title={criterio ? undefined : 'Selecione uma conta ou terceiro para activar'}>
                <BotaoImprimir obterPedido={pedido} desactivado={!criterio || !d} />
              </span>
              <span title={criterio ? undefined : 'Selecione uma conta ou terceiro para activar'}>
                <BotaoImprimir obterPedido={pedido} modo="pdf" texto="Extrair" tipo="primary" desactivado={!criterio || !d} />
              </span>
            </Space>
          </Form.Item>
        </Flex>
        <Collapse
          ghost
          size="small"
          items={[
            {
              key: 'mais',
              label: 'Mais filtros',
              children: (
                <Flex gap={12} wrap align="center">
                  <Form.Item name="diario_id" style={{ marginBottom: 0 }}>
                    <SeletorDiario allowClear />
                  </Form.Item>
                  <Form.Item name="centro_custo_id" style={{ marginBottom: 0 }}>
                    <SeletorAux tabela="centros-custo" placeholder="Centro de custo" />
                  </Form.Item>
                  <Form.Item name="unidade_negocio_id" style={{ marginBottom: 0 }}>
                    <SeletorUnidade />
                  </Form.Item>
                  <Form.Item name="incluir_classe_9" valuePropName="checked" style={{ marginBottom: 0 }}>
                    <Checkbox>Incluir classe 9</Checkbox>
                  </Form.Item>
                </Flex>
              ),
            },
          ]}
        />
      </Form>

      {!d ? (
        <EstadoVazio
          icone="file-invoice-dollar"
          titulo={null}
          texto={
            mapa.isFetching ? 'A gerar o extrato…' : (
              <>
                Utilize os filtros acima e clique em <strong>Gerar Extrato</strong> para visualizar a conta corrente.
              </>
            )
          }
        />
      ) : (
        <div ref={area} style={{ background: token.colorBgContainer }}>
          <Flex justify="space-between" align="center" wrap gap={8} style={{ marginBottom: 12 }}>
            <div>
              <Typography.Title level={5} style={{ margin: 0 }}>
                Extrato de Conta Corrente
              </Typography.Title>
              <Typography.Text type="secondary" style={{ fontSize: 13 }}>
                Exibindo <strong style={{ color: token.colorPrimary }}>{movimentos.length}</strong> movimentos · Saldo inicial {formatarKz(d.saldo_inicial)} · Saldo final{' '}
                <strong>{formatarKz(d.saldo_final)}</strong>
              </Typography.Text>
            </div>
            <Space wrap className="imp-nao-imprimir">
              <Button size="small" icon={<IconeFa nome="expand" />} onClick={ecraCheio}>
                Ver Ecrã Cheio
              </Button>
              <BotaoImprimir obterPedido={pedido} tamanho="small" />
              <BotaoImprimir obterPedido={pedido} modo="pdf" texto="Extrair" tipo="primary" tamanho="small" />
            </Space>
          </Flex>
          {ultimo && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={descreverUltimo(d.ultimo)} />}
          <TabelaComModos<MovimentoExtrato>
            idVista="extrato"
            modos={false}
            rowKey="id"
            size="small"
            loading={mapa.isFetching}
            columns={colunas}
            dataSource={movimentos}
            scroll={scrollTabela()}
            impressao={{ titulo: 'Extrato de Conta Corrente', periodo: periodoDosParametros(parametros), filtros: filtrosDosParametros(parametros), nomeFicheiro: 'extrato_conta_corrente' }}
            accoes={(r) => [{ icone: 'project-diagram', titulo: 'Ver Fluxo Completo', variante: 'info', oculto: !abrir, onClick: () => abrir?.(r.id) }]}
            seleccao={
              podeCompensar
                ? {
                    chaves: seleccao,
                    aoMudar: (k) => setSeleccao(k),
                    bloquearLinha: (r) => (r.reconciliacao_codigo ? 'Movimento já compensado' : r.estornado_por_id || r.estorno_de_id ? 'Movimento estornado' : false),
                    ajuda: seleccao.length > 0 ? <IndicadorEquilibrio linhas={seleccionadas} /> : 'Seleccione movimentos da mesma conta para compensar.',
                    accoes: [
                      {
                        texto: `Compensar ${seleccao.length} movimento(s)`,
                        icone: 'link',
                        variante: 'sucesso',
                        desactivado: seleccao.length < 2 ? 'Seleccione pelo menos dois movimentos' : !eq.equilibrado ? 'A soma D − C dos movimentos tem de ser zero' : false,
                        confirmar: 'Deseja compensar estes {n} movimentos? Eles deixarão de aparecer como pendentes.',
                        onClick: () => compensar.mutateAsync(),
                      },
                      {
                        texto: 'Regularizar a diferença e compensar',
                        icone: 'balance-scale',
                        desactivado: eq.equilibrado ? 'Os movimentos já estão equilibrados' : false,
                        onClick: () => {
                          formReg.setFieldsValue({ data: dayjs() });
                          setRegularizar(true);
                        },
                      },
                    ],
                  }
                : undefined
            }
            vazio={{
              icone: 'search',
              titulo: 'Nenhum movimento encontrado',
              texto: 'Não foram encontrados registos para os filtros selecionados no intervalo de datas indicado.',
              accoes: <Button size="small" onClick={novaConsulta}>Limpar Filtros</Button>,
            }}
          />
        </div>
      )}

      <Modal
        title="Colar múltiplas Contas"
        open={colar !== null}
        onCancel={() => setColar(null)}
        okText="Aplicar"
        cancelText="Cancelar"
        onOk={() => {
          const contas = normalizarContasColadas(colar ?? '');
          form.setFieldsValue({ filtro_contas: contas });
          setCriterio(!!contas || !!form.getFieldValue('terceiro_id'));
          setColar(null);
        }}
      >
        <Typography.Paragraph type="secondary">Cole aqui as contas copiadas do Excel (uma por linha) ou separadas por vírgula.</Typography.Paragraph>
        <Input.TextArea rows={7} value={colar ?? ''} onChange={(e) => setColar(e.target.value)} placeholder={'Ex:\n21\n24\n26'} style={{ fontFamily: 'monospace' }} maxLength={5000} autoFocus />
      </Modal>

      <Modal
        title={`Regularizar diferença de ${formatarKz(eq.diferenca)} Kz`}
        open={regularizar}
        onCancel={() => setRegularizar(false)}
        okText="Lançar e compensar"
        confirmLoading={regularizacao.isPending}
        onOk={() => formReg.submit()}
      >
        <Form form={formReg} layout="vertical" onFinish={(v) => regularizacao.mutate(v)}>
          <Typography.Paragraph type="secondary">É gravado um lançamento pela diferença contra a conta indicada e os movimentos ficam compensados.</Typography.Paragraph>
          <Form.Item name="codigo_conta" label="Conta de contrapartida" rules={[{ required: true, message: 'Indique a conta.' }]}>
            <SeletorConta style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="data" label="Data do lançamento" rules={[{ required: true }]}>
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="diario_id" label="Diário (opcional)">
            <SeletorDiario allowClear style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title={`Compensação ${codigo ?? ''}`}
        open={!!codigo}
        onCancel={() => setCodigo(null)}
        width={larguraModal(820)}
        footer={
          <Space wrap>
            <Button onClick={() => setCodigo(null)}>Fechar</Button>
            {pode('contab_reconc_rev') && codigo && (
              <Button
                danger
                loading={reverter.isPending}
                onClick={() => Modal.confirm({ title: `Reverter a compensação ${codigo}?`, okText: 'Reverter', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => reverter.mutateAsync(codigo) })}
              >
                Reverter compensação
              </Button>
            )}
          </Space>
        }
      >
        {compensacao.data && (
          <>
            <Typography.Paragraph>
              {compensacao.data.data ? `Data: ${formatarData(compensacao.data.data)} · ` : ''}
              {compensacao.data.valor_total ? `Valor: ${formatarKz(compensacao.data.valor_total, true)}` : ''}
            </Typography.Paragraph>
            <TabelaComModos
              idVista="compensacao"
              modos={false}
              rowKey="id"
              size="small"
              scroll={scrollTabela()}
              pagination={false}
              filtrosColuna={false}
              dataSource={compensacao.data.linhas}
              columns={[
                { title: 'Data', dataIndex: 'data_documento', render: formatarData },
                { title: 'Conta', dataIndex: 'codigo_conta' },
                { title: 'N.º lançamento', dataIndex: 'numero_lan' },
                { title: 'Documento', dataIndex: 'numero_documento' },
                { title: 'Descrição', dataIndex: 'descricao' },
                { title: 'D/C', dataIndex: 'tipo_dc' },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
              ]}
            />
          </>
        )}
      </Modal>
    </Card>
  );
}
