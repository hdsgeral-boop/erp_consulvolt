import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Row, Select, Space, Tabs, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import { useImpressao } from '@/componentes/impressao';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { TabelaComModos } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EstadoTag, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores, useInfotipos } from './comum/consultas';
import type { ConfiguracaoEmprestimos, Emprestimo } from './faseB/tipos';
import { TabelaPlano } from './faseB/TabelaPlano';

/**
 * RH › Empréstimos (ecrã rh_emprestimos; Versão Actual: emprestimos_ui.js, separadores Lista e Configurações).
 * O colaborador pede no Portal; a chefia (se configurado) e o RH aprovam no circuito do portal; aprovado, o plano de
 * amortização é gerado. Aqui o RH acompanha, lança o capital na conta corrente (rh_emp_lancar), cancela enquanto não houver
 * prestações descontadas (rh_emp_gerir) e configura contas, diário, rubrica de desconto e limites (rh_emp_config).
 * As prestações entram no cálculo pelo botão «Importar Empréstimos» (Calcular).
 */
export const ESTADOS_EMPRESTIMO = [
  { value: 'PENDENTE_CHEFIA', label: 'Aguarda a chefia' }, { value: 'PENDENTE_RH', label: 'Aguarda o RH' }, { value: 'APROVADO', label: 'Aprovado' },
  { value: 'LIQUIDADO', label: 'Liquidado' }, { value: 'RECUSADO', label: 'Recusado' }, { value: 'CANCELADO', label: 'Cancelado' },
];

export default function Emprestimos() {
  const { pode } = useSessao();
  return (
    <>
      <CabecalhoPagina titulo="Empréstimos a colaboradores" subtitulo="Pedidos do portal, plano de amortização (prestação constante), conta corrente e desconto no salário" />
      <Tabs type="card" items={[
        { key: 'lista', label: <span><IconeFa nome="hand-holding-usd" /> Lista</span>, children: <Lista /> },
        ...(pode('rh_emp_config') || pode('rh_emprestimos_view') ? [{ key: 'config', label: <span><IconeFa nome="cog" /> Configurações</span>, children: <Configuracoes /> }] : []),
      ]} />
    </>
  );
}

/** Documento de autorização de desconto no salário (empDocumento, emprestimos_ui.js): o título que legitima o desconto. */
export function autorizacaoDescontoHtml(e: Emprestimo, nome: string, empresa: string): string {
  const esc = (s: unknown) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));
  const plano = e.prestacoes_plano ?? e.plano ?? [];
  return `<h2 style="text-align:center">Autorização de desconto no salário</h2>
    <p>Eu, <b>${esc(nome)}</b>, autorizo <b>${esc(empresa)}</b> a descontar no meu salário as prestações do empréstimo <b>${esc(e.numero)}</b>,
    no valor de ${esc(formatarKz(e.capital, true))}, em ${esc(e.prestacoes)} prestações mensais de ${esc(formatarKz(e.valor_prestacao, true))},
    com início em ${esc(e.inicio_desconto)}${Number(e.taxa_juro) > 0 ? `, à taxa de juro anual de ${esc(e.taxa_juro)} %` : ', sem juros'}.</p>
    <table style="width:100%;border-collapse:collapse" border="1" cellpadding="4"><thead><tr><th>#</th><th>Mês</th><th>Prestação</th><th>Em dívida</th></tr></thead><tbody>
    ${plano.map((p) => `<tr><td>${p.numero}</td><td>${esc(p.mes_ano)}</td><td style="text-align:right">${esc(formatarKz(p.total))}</td><td style="text-align:right">${esc(formatarKz(p.saldo))}</td></tr>`).join('')}
    </tbody></table>
    <p>Em caso de cessação do contrato, o saldo em dívida é descontado nas importâncias a receber, nos limites da lei.</p>
    <p style="margin-top:48px">______________________________<br/>${esc(nome)}</p>`;
}

function Lista() {
  const { pode, empresa } = useSessao();
  const colaboradores = useColaboradores();
  const [estado, setEstado] = useState<string>();
  const [colaborador, setColaborador] = useState<number>();
  const [ver, setVer] = useState<number | null>(null);
  const [cancelar, setCancelar] = useState<Emprestimo | null>(null);
  const [lancar, setLancar] = useState<Emprestimo | null>(null);
  const q = useQuery({ queryKey: ['rh', 'emprestimos', { estado, colaborador }], queryFn: () => obter<Emprestimo[]>('/rh/emprestimos', { estado, colaborador_id: colaborador }) });
  useAvisarErro(q.error);
  const detalhe = useQuery({ queryKey: ['rh', 'emprestimos', ver], queryFn: () => obter<Emprestimo>(`/rh/emprestimos/${ver}`), enabled: ver !== null });
  const [formCancelar] = Form.useForm<{ motivo: string }>();
  const [formLancar] = Form.useForm<{ data: Dayjs }>();
  const accao = useAccaoRh(() => { setCancelar(null); setLancar(null); });
  const { imprimir } = useImpressao();

  const colunas: ColunaApi<Emprestimo>[] = [
    { title: 'Número', dataIndex: 'numero', render: (v: string) => <strong>{v}</strong> },
    { title: 'Colaborador', dataIndex: 'nome', render: (v: string | null, e) => v ?? colaboradores.nome(e.colaborador_id) },
    { title: 'Capital', dataIndex: 'capital', align: 'right', render: (v: string) => formatarKz(v) },
    { title: 'Prestações', key: 'prest', align: 'right', valorImpressao: (e) => `${e.prestacoes} × ${formatarKz(e.valor_prestacao)}`, render: (_, e) => `${e.prestacoes} × ${formatarKz(e.valor_prestacao)}` },
    { title: 'Início', dataIndex: 'inicio_desconto', responsive: ['md'] },
    { title: 'Juro/ano', dataIndex: 'taxa_juro', align: 'right', responsive: ['lg'], render: (v: string) => (Number(v) > 0 ? `${Number(v)} %` : 'Sem juros') },
    { title: 'Em dívida', dataIndex: 'em_divida', align: 'right', render: (v: string | undefined) => formatarKz(v ?? 0) },
    { title: 'Conta corrente', dataIndex: 'lancamento_referencia', responsive: ['lg'], render: (v: string | null) => v ?? '—' },
    { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EstadoTag estado={v} /> },
  ];

  return (
    <Card>
      <BarraFiltros>
        <Select placeholder="Estado" allowClear style={{ width: 180 }} value={estado} onChange={setEstado} options={ESTADOS_EMPRESTIMO} />
        <SeletorColaborador value={colaborador} onChange={setColaborador} />
      </BarraFiltros>
      <TabelaComModos<Emprestimo> rowKey="id" size="small" loading={q.isFetching} columns={colunas} dataSource={q.data ?? []} scroll={scrollTabela()}
        accoes={(e) => [
          { icone: 'list-ol', titulo: 'Ver o plano de amortização', variante: 'info', onClick: () => setVer(e.id) },
          pode('rh_emp_lancar') && ['APROVADO', 'LIQUIDADO'].includes(e.estado) && !e.lancamento_referencia && { icone: 'book', titulo: 'Lançar na conta corrente', variante: 'primario',
            onClick: () => { formLancar.setFieldsValue({ data: dayjs() }); setLancar(e); } },
          pode('rh_emp_gerir') && ['PENDENTE_CHEFIA', 'PENDENTE_RH', 'APROVADO'].includes(e.estado) && !(e.descontadas ?? 0) && { icone: 'ban', titulo: 'Cancelar empréstimo', variante: 'perigo',
            onClick: () => { formCancelar.resetFields(); setCancelar(e); } },
        ]}
        vazio={{ icone: 'hand-holding-usd', titulo: 'Sem empréstimos', texto: 'Os pedidos são feitos pelos colaboradores no Portal.' }} />

      <Modal open={ver !== null} title={detalhe.data ? `Empréstimo ${detalhe.data.numero}` : 'Empréstimo'} width={larguraModal(820)} onCancel={() => setVer(null)}
        footer={detalhe.data && ['APROVADO', 'LIQUIDADO'].includes(detalhe.data.estado) ? (
          <Button icon={<IconeFa nome="print" />} onClick={() => detalhe.data && void imprimir({ titulo: 'Autorização de desconto', subtitulo: detalhe.data.numero, modo: 'pdf', orientacao: 'retrato',
            nomeFicheiro: `Autorizacao_${detalhe.data.numero.replace(/\W+/g, '_')}`, conteudo: autorizacaoDescontoHtml(detalhe.data, detalhe.data.nome ?? colaboradores.nome(detalhe.data.colaborador_id), empresa?.nome ?? '') }).catch(notificarErro)}>
            Autorização de desconto
          </Button>
        ) : null}>
        {detalhe.data && (
          <Space direction="vertical" style={{ width: '100%' }}>
            <Descriptions size="small" bordered column={{ xs: 1, md: 2 }}>
              <Descriptions.Item label="Colaborador">{detalhe.data.nome}</Descriptions.Item>
              <Descriptions.Item label="Estado"><EstadoTag estado={detalhe.data.estado} /></Descriptions.Item>
              <Descriptions.Item label="Capital">{formatarKz(detalhe.data.capital, true)}</Descriptions.Item>
              <Descriptions.Item label="Total a pagar">{formatarKz(detalhe.data.total_a_pagar, true)}{Number(detalhe.data.total_juros) > 0 ? ` (juros ${formatarKz(detalhe.data.total_juros)})` : ''}</Descriptions.Item>
              <Descriptions.Item label="Em dívida">{formatarKz(detalhe.data.em_divida, true)}</Descriptions.Item>
              <Descriptions.Item label="Aprovado em">{formatarData(detalhe.data.aprovado_em)}</Descriptions.Item>
              <Descriptions.Item label="Motivo" span={2}>{detalhe.data.motivo}</Descriptions.Item>
              {detalhe.data.motivo_cancelamento && <Descriptions.Item label="Cancelamento" span={2}>{detalhe.data.motivo_cancelamento}</Descriptions.Item>}
            </Descriptions>
            {(detalhe.data.prestacoes_plano ?? []).length ? <TabelaPlano plano={detalhe.data.prestacoes_plano ?? []} comJuro={Number(detalhe.data.taxa_juro) > 0} />
              : <Alert type="info" showIcon message="O plano de amortização é gerado quando o pedido for aprovado." />}
          </Space>
        )}
      </Modal>

      <Modal open={lancar !== null} title={`Lançar ${lancar?.numero ?? ''} na conta corrente`} okText="Lançar" cancelText="Cancelar" confirmLoading={accao.isPending} onCancel={() => setLancar(null)} onOk={() => formLancar.submit()} destroyOnHidden>
        <Typography.Paragraph>Débito na conta de empréstimos ao pessoal e crédito na conta de contrapartida, pelo capital ({formatarKz(lancar?.capital, true)}), no diário configurado.</Typography.Paragraph>
        <Form form={formLancar} layout="vertical" onFinish={(v) => lancar && accao.mutate({ metodo: 'post', url: `/rh/emprestimos/${lancar.id}/conta-corrente`, dados: { data: dataApi(v.data) } })}>
          <Form.Item name="data" label="Data do lançamento" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        </Form>
      </Modal>

      <Modal open={cancelar !== null} title={`Cancelar ${cancelar?.numero ?? ''}`} okText="Cancelar empréstimo" okButtonProps={{ danger: true }} cancelText="Voltar" confirmLoading={accao.isPending}
        onCancel={() => setCancelar(null)} onOk={() => formCancelar.submit()} destroyOnHidden>
        <Typography.Paragraph type="secondary">Só é possível enquanto nenhuma prestação tiver sido descontada e o capital não tiver sido lançado na conta corrente.</Typography.Paragraph>
        <Form form={formCancelar} layout="vertical" onFinish={(v) => cancelar && accao.mutate({ metodo: 'post', url: `/rh/emprestimos/${cancelar.id}/cancelar`, dados: v })}>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (mínimo 5 caracteres).' }]}><Input.TextArea rows={3} maxLength={1000} /></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}

interface Diario { id: number; codigo: string; nome: string | null; descricao?: string | null }

function Configuracoes() {
  const { pode } = useSessao();
  const editar = pode('rh_emp_config');
  const infotipos = useInfotipos();
  const q = useQuery({ queryKey: ['rh', 'emprestimos', 'configuracao'], queryFn: () => obter<ConfiguracaoEmprestimos>('/rh/emprestimos/configuracao') });
  const diarios = useQuery({ queryKey: ['contabilidade', 'diarios'], queryFn: () => obter<Diario[]>('/contabilidade/diarios'), retry: false, staleTime: 300_000 });
  useAvisarErro(q.error);
  const [form] = Form.useForm();
  const accao = useAccaoRh();
  useEffect(() => {
    if (q.data) form.setFieldsValue({ ...q.data, multiplo_salario_max: Number(q.data.multiplo_salario_max), taxa_juro_padrao: Number(q.data.taxa_juro_padrao) });
  }, [q.data, form]);
  return (
    <Card loading={q.isLoading}>
      {(q.data?.por_configurar ?? []).length > 0 && (
        <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="Os colaboradores ainda não podem pedir empréstimos" description={`Falta configurar: ${q.data?.por_configurar.join('; ')}.`} />
      )}
      <Form form={form} layout="vertical" disabled={!editar} style={{ maxWidth: 820 }} onFinish={(v) => accao.mutate({ metodo: 'put', url: '/rh/emprestimos/configuracao', dados: v })}>
        <Row gutter={16}>
          <Col xs={24} md={12}><Form.Item name="conta_emprestimo" label="Conta de empréstimos ao pessoal (conta corrente)" extra="Conta de movimento do plano (débito)."><Input maxLength={20} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="conta_contrapartida" label="Conta de contrapartida do pagamento" extra="Ex.: a conta do banco (crédito)."><Input maxLength={20} /></Form.Item></Col>
          <Col xs={24} md={12}>
            <Form.Item name="diario_id" label="Diário" extra={diarios.isError ? 'Sem acesso à lista de diários.' : undefined}>
              <Select allowClear showSearch optionFilterProp="label" options={(diarios.data ?? []).map((d) => ({ value: d.id, label: `${d.codigo} — ${d.nome ?? d.descricao ?? ''}` }))} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="infotipo_desconto_id" label="Rubrica de desconto no cálculo" extra="Rubrica do tipo DESCONTO.">
              <Select allowClear showSearch optionFilterProp="label" options={infotipos.lista.filter((i) => i.tipo === 'DESCONTO').map((i) => ({ value: i.id, label: i.nome }))} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}><Form.Item name="prestacoes_max" label="Máximo de prestações" rules={[{ required: true }]}><InputNumber min={1} max={120} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="multiplo_salario_max" label="Tecto (× salário base)" rules={[{ required: true }]} extra="0 = sem tecto."><InputNumber min={0} max={100} step={0.5} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="taxa_juro_padrao" label="Taxa de juro anual (%)" rules={[{ required: true }]}><InputNumber min={0} max={100} step={0.5} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="exige_chefia" label="Parecer da chefia" valuePropName="checked"><Checkbox>Exigir</Checkbox></Form.Item></Col>
        </Row>
        {editar && <Button type="primary" htmlType="submit" loading={accao.isPending}>Gravar configuração</Button>}
      </Form>
    </Card>
  );
}
