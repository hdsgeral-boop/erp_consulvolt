import { Alert, Button, Card, Col, DatePicker, Form, Input, InputNumber, Modal, Row, Select, Space, Statistic, Tabs, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { TabelaComModos } from '@/componentes/vistas';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { useSessao } from '@/sessao/SessaoContexto';
import {
  anularRenda, aprovarRendas, CHAVES_CT, debitarExtra, desligarPagamento, facturarRendas, gerarRendasEmFalta, listarContas, obterRendas, pagarRendas, recalcularRenda, registarRetencao,
  type Lado, type Renda, type ResultadoLote,
} from './apiContratos';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';
import { COR_RENDA, fimDoMes, rendaSeleccionavel, textoAtraso } from './logicaContratos';
import { ModalMotivo } from './ModaisContrato';
import './imobiliario.css';

const dataPt = (d: string) => d.split('-').reverse().join('/');

/**
 * Gestão Imobiliária › Rendas do período (ecrã imo_rendas): as rendas cujo período cobre o mês, a receber (facturar) e a pagar (Tesouraria).
 * Ver: imo_rendas_view · gerar e recalcular: imo_renda_gerar · aprovar e anular: imo_renda_aprovar · facturar: imo_renda_facturar · pagar e
 * desligar o pagamento: imo_renda_pagar · debitar um encargo ao inquilino: imo_sub_debitar. Quem aprova não gera nem factura (segregação
 * no catálogo); o servidor recusa o que não for permitido.
 */
export default function Rendas() {
  const { pode } = useSessao();
  const refrescar = useRefrescar();
  const [mes, setMes] = useState(dayjs());
  const [lado, setLado] = useState<Lado>('SENHORIO');
  const [marcadas, setMarcadas] = useState<number[]>([]);
  const [resultado, setResultado] = useState<{ titulo: string; falhas: { id: number; erro: string }[] } | null>(null);
  const [anular, setAnular] = useState<Renda | null>(null);
  const [desligar, setDesligar] = useState<Renda | null>(null);
  const [extra, setExtra] = useState<Renda | null>(null);
  const [pagar, setPagar] = useState(false);
  const periodo = mes.format('YYYY-MM');
  const q = useQuery({ queryKey: CHAVES_CT.rendas(periodo, lado), queryFn: () => obterRendas(periodo, lado) });
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar as rendas'); }, [q.error]);
  useEffect(() => { setMarcadas([]); }, [periodo, lado]);
  const p = { gerar: pode('imo_renda_gerar'), aprovar: pode('imo_renda_aprovar'), facturar: pode('imo_renda_facturar'), pagar: pode('imo_renda_pagar'), debitar: pode('imo_sub_debitar') };
  const linhas = q.data?.linhas ?? [];
  const seleccionaveis = useMemo(() => linhas.filter((l) => rendaSeleccionavel(l.estado)).map((l) => l.id), [linhas]);
  const aoErro = (t: string) => (e: unknown) => notificarErro(e, t);
  const depois = (texto: string) => async () => { message.success(texto); setMarcadas([]); await refrescar(); };
  const lote = (titulo: string) => async (r: { dados: ResultadoLote; mensagem: string }) => {
    if (r.dados.falhas.length > 0) setResultado({ titulo: `${r.dados.falhas.length} renda(s) ficaram por tratar`, falhas: r.dados.falhas }); else setResultado(null);
    message.success(r.mensagem || titulo); setMarcadas([]); await refrescar();
  };

  const gerar = useMutation({ mutationFn: () => gerarRendasEmFalta(fimDoMes(periodo)), onError: aoErro('Não foi possível gerar as rendas'), onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); } });
  const aprovar = useMutation({ mutationFn: () => aprovarRendas(marcadas), onError: aoErro('Não foi possível aprovar as rendas'), onSuccess: async (r) => { if (r.dados.ignoradas.length) setResultado({ titulo: 'Rendas que não se aprovaram', falhas: r.dados.ignoradas.map((i) => ({ id: i.id, erro: i.motivo })) }); message.success(r.mensagem); setMarcadas([]); await refrescar(); } });
  const facturar = useMutation({ mutationFn: () => facturarRendas(marcadas), onError: aoErro('Não foi possível facturar'), onSuccess: lote('Facturação concluída.') });
  const anulaR = useMutation({ mutationFn: (m: string) => anularRenda((anular as Renda).id, m), onError: aoErro('Não foi possível anular a renda'), onSuccess: async () => { setAnular(null); await depois('Renda anulada.')(); } });
  const desligaR = useMutation({ mutationFn: (m: string) => desligarPagamento((desligar as Renda).id, m), onError: aoErro('Não foi possível desligar o pagamento'), onSuccess: async () => { setDesligar(null); await depois('Pagamento desligado.')(); } });
  const recalcula = useMutation({ mutationFn: (r: Renda) => recalcularRenda(r.id), onError: aoErro('Não foi possível recalcular a renda'), onSuccess: async () => { await depois('Renda recalculada. Se estava aprovada, tem de ser aprovada de novo.')(); } });
  const retencao = useMutation({ mutationFn: (r: Renda) => registarRetencao(r.id), onError: aoErro('Não foi possível registar a retenção'), onSuccess: async (r) => { await depois(r.mensagem)(); } });

  const confirmarFacturar = () => Modal.confirm({ title: 'Facturar rendas', content: `Vão ser emitidos ${marcadas.length} documento(s) fiscal(is). Depois de emitidos não se alteram nem se apagam: corrigem-se com nota de crédito. Confirma?`,
    okText: 'Facturar', cancelText: 'Cancelar', onOk: () => facturar.mutateAsync() });

  const t = q.data?.totais;
  const tomada = lado === 'INQUILINO';

  return (
    <>
      <CabecalhoModulo titulo="Rendas do período" icone="file-invoice-dollar" subtitulo={tomada ? 'As rendas que a empresa paga aos senhorios. O pagamento cria um documento pendente na Tesouraria.' : 'As rendas que a empresa recebe. Facturam-se em Vendas depois de aprovadas.'} />
      <Tabs activeKey={lado} onChange={(k) => setLado(k as Lado)} items={[{ key: 'SENHORIO', label: 'A receber' }, { key: 'INQUILINO', label: 'A pagar' }]} />
      <Row gutter={[12, 12]} className="imo-kpis">
        <Col xs={12} md={6}><Card size="small"><Statistic title="Previstas" value={formatarKz(t?.previstas)} loading={q.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Aprovadas" value={formatarKz(t?.aprovadas)} loading={q.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title={tomada ? 'Pagas' : 'Facturadas'} value={formatarKz(tomada ? t?.pagas : t?.facturadas)} loading={q.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title={tomada ? 'A pagar no período' : 'A receber no período'} value={formatarKz(t?.total_receber)} loading={q.isLoading} /></Card></Col>
      </Row>
      <Card style={{ marginTop: 12 }}>
        <Space wrap style={{ marginBottom: 12 }}>
          <label htmlFor="imo-rendas-periodo">Período</label>
          <DatePicker id="imo-rendas-periodo" picker="month" allowClear={false} value={mes} onChange={(v) => v && setMes(v)} format="MM/YYYY" />
          {p.gerar && <Button loading={gerar.isPending} onClick={() => gerar.mutate()}>Gerar rendas em falta</Button>}
          <Button onClick={() => setMarcadas(seleccionaveis)} disabled={seleccionaveis.length === 0}>Marcar todas</Button>
          <Button onClick={() => setMarcadas([])} disabled={marcadas.length === 0}>Desmarcar</Button>
          {p.aprovar && <Button type="primary" disabled={marcadas.length === 0} loading={aprovar.isPending} onClick={() => aprovar.mutate()}>Aprovar marcadas</Button>}
          {!tomada && p.facturar && <Button disabled={marcadas.length === 0} onClick={confirmarFacturar}>Facturar marcadas</Button>}
          {tomada && p.pagar && <Button disabled={marcadas.length === 0} onClick={() => setPagar(true)}>Pagar marcadas</Button>}
        </Space>
        {resultado && <Alert type="warning" showIcon closable onClose={() => setResultado(null)} style={{ marginBottom: 12 }} title={resultado.titulo}
          description={<ul className="imo-lista">{resultado.falhas.map((f) => <li key={f.id}>Renda {f.id}: {f.erro}</li>)}</ul>} />}
        {(q.data?.outro_lado ?? 0) > 0 && linhas.length === 0 && <Alert type="info" showIcon style={{ marginBottom: 12 }} title={`Há ${q.data?.outro_lado} renda(s) do outro lado: veja o separador ao lado.`} />}
        <TabelaComModos<Renda>
          idVista={`imobiliario_rendas_${lado}`} rowKey="id" size="middle" loading={q.isFetching} dataSource={linhas} scroll={{ x: 'max-content' }} impressao={{ titulo: `Rendas do período ${periodo}` }}
          rowSelection={{ selectedRowKeys: marcadas, onChange: (k) => setMarcadas(k as number[]), getCheckboxProps: (r: Renda) => ({ disabled: !rendaSeleccionavel(r.estado) }) }}
          columns={[
            { title: 'Contrato', dataIndex: 'contrato_numero', ocultavel: false, principal: true, render: (v: string | undefined, r: Renda) => <span>{v}<br /><small>{r.periodo}</small></span>, valorImpressao: (r: Renda) => r.contrato_numero ?? '' },
            { title: tomada ? 'Senhorio' : 'Inquilino', dataIndex: 'terceiro_nome', render: (v: string | null | undefined) => v ?? '—', valorImpressao: (r: Renda) => r.terceiro_nome ?? '—' },
            { title: 'Imóvel / fracção', dataIndex: 'imovel_nome', render: (v: string | null | undefined, r: Renda) => `${v ?? '—'} — ${r.fracao_designacao ?? '—'}`, valorImpressao: (r: Renda) => `${r.imovel_nome ?? ''} — ${r.fracao_designacao ?? ''}` },
            { title: 'Vencimento', dataIndex: 'vencimento', render: (v: string, r: Renda) => <span>{dataPt(v)}{r.atraso > 0 ? <><br /><small>{textoAtraso(r.atraso)}</small></> : null}</span>, valorImpressao: (r: Renda) => dataPt(r.vencimento) },
            { title: 'Renda', dataIndex: 'renda', align: 'right', render: (v: number, r: Renda) => formatarKz(v) + (r.factor < 1 ? ' *' : ''), valorImpressao: (r: Renda) => formatarKz(r.renda) },
            { title: 'Encargos / extras', dataIndex: 'encargos', align: 'right', ocultaPorOmissao: true, render: (v: number, r: Renda) => formatarKz(v + r.extras_total), valorImpressao: (r: Renda) => formatarKz(r.encargos + r.extras_total) },
            { title: 'IVA', dataIndex: 'iva_valor', align: 'right', ocultaPorOmissao: true, render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.iva_valor) },
            { title: tomada ? 'Valor do documento' : 'A facturar', dataIndex: 'total_facturar', align: 'right', render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.total_facturar) },
            { title: 'IP retido', dataIndex: 'ip_valor', align: 'right', render: (v: number, r: Renda) => (r.ip_retido ? formatarKz(v) : '—'), valorImpressao: (r: Renda) => (r.ip_retido ? formatarKz(r.ip_valor) : '—') },
            { title: tomada ? 'A pagar' : 'A receber', dataIndex: 'total_receber', align: 'right', render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.total_receber) },
            ...(tomada ? [] : [{ title: 'Selo (senhorio)', dataIndex: 'selo_valor', align: 'right' as const, ocultaPorOmissao: true, render: (v: number, r: Renda) => (r.selo_encargo === 'SENHORIO' ? formatarKz(v) : '—'), valorImpressao: (r: Renda) => (r.selo_encargo === 'SENHORIO' ? formatarKz(r.selo_valor) : '—') }]),
            { title: tomada ? 'Pago' : 'Recebido', dataIndex: 'pago', align: 'right', ocultaPorOmissao: true, render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.pago) },
            { title: 'Dívida', dataIndex: 'divida', align: 'right', ocultaPorOmissao: true, render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.divida) },
            { title: 'Estado', dataIndex: 'estado', etiqueta: true, render: (v: Renda['estado']) => <Tag color={COR_RENDA[v]}>{v.charAt(0) + v.slice(1).toLowerCase()}</Tag>, valorImpressao: (r: Renda) => r.estado },
            { title: 'Documento', dataIndex: 'documento', render: (v: string | null) => v ?? '—', valorImpressao: (r: Renda) => r.documento ?? '—' },
          ]}
          accoes={(r) => [
            ...(p.gerar && rendaSeleccionavel(r.estado) ? [{ icone: 'calculator', titulo: 'Recalcular', onClick: () => recalcula.mutate(r) }] : []),
            ...(p.debitar && !tomada && rendaSeleccionavel(r.estado) ? [{ icone: 'plus', titulo: 'Debitar um encargo ao inquilino', onClick: () => setExtra(r) }] : []),
            ...(p.aprovar && rendaSeleccionavel(r.estado) ? [{ icone: 'ban', titulo: 'Anular', variante: 'perigo' as const, onClick: () => setAnular(r) }] : []),
            ...(p.pagar && tomada && r.estado === 'PAGA' ? [{ icone: 'undo', titulo: 'Desligar o pagamento', onClick: () => setDesligar(r) }] : []),
            ...(p.facturar && !tomada && r.estado === 'FACTURADA' && r.ip_retido && r.ip_valor > 0 && !r.retencao_lancamento ? [{ icone: 'file-invoice', titulo: 'Registar o Imposto Predial retido', onClick: () => retencao.mutate(r) }] : []),
          ]}
          vazio={{ titulo: `Nada ${tomada ? 'a pagar' : 'a receber'} neste período`, texto: 'Use «Gerar rendas em falta» ou verifique se há contratos activos cuja vigência cubra o mês.' }}
        />
        <p style={{ marginTop: 8 }}>* Período proporcional aos dias em que o contrato vigora. {tomada ? 'O pagamento cria um documento pendente na Tesouraria; integrá-lo faz-se lá.' : 'O recebimento vem dos recibos de Vendas.'} As taxas são as do ano da renda e estão por validar.</p>
      </Card>
      <ModalMotivo titulo="Anular a renda" aberto={!!anular} okText="Anular" texto="Uma renda anulada não se regenera. A renda já facturada corrige-se com nota de crédito em Vendas." aoConfirmar={(m) => anulaR.mutate(m)} aoFechar={() => setAnular(null)} carregando={anulaR.isPending} />
      <ModalMotivo titulo="Desligar o pagamento" aberto={!!desligar} okText="Desligar" texto="A renda volta a «aprovada» e o documento pendente da Tesouraria é anulado. Um documento já integrado não se desliga daqui." aoConfirmar={(m) => desligaR.mutate(m)} aoFechar={() => setDesligar(null)} carregando={desligaR.isPending} />
      <ModalExtra renda={extra} aoFechar={() => setExtra(null)} />
      <ModalPagar aberto={pagar} ids={marcadas} total={linhas.filter((l) => marcadas.includes(l.id)).reduce((s, l) => s + l.total_receber, 0)} aoFechar={(r) => { setPagar(false); if (r) { void lote('Pagamentos criados.')(r); } }} />
    </>
  );
}

function ModalExtra({ renda, aoFechar }: { renda: Renda | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ designacao: string; valor: number }>();
  const refrescar = useRefrescar();
  useEffect(() => { if (renda) { form.resetFields(); } }, [renda, form]);
  const gravar = useMutation({
    mutationFn: (v: { designacao: string; valor: number }) => debitarExtra((renda as Renda).id, v.designacao, v.valor), onError: (e) => notificarErro(e, 'Não foi possível debitar o encargo'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(); },
  });

  return (
    <Modal title={renda ? `Debitar um encargo na renda de ${renda.periodo}` : 'Debitar um encargo'} open={!!renda} onCancel={aoFechar} onOk={() => form.submit()} okText="Debitar" cancelText="Cancelar" confirmLoading={gravar.isPending} width={larguraModal(520)} destroyOnHidden>
      <p>Entra como encargo extraordinário na renda. Não aumenta o Imposto Predial nem o Selo, que incidem sobre a renda: é um débito de despesa. A renda volta a «prevista».</p>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item name="designacao" label="Designação" rules={[{ required: true, message: 'Indique a designação.' }]}><Input maxLength={150} placeholder="Reparação da canalização" /></Form.Item>
        <Form.Item name="valor" label="Valor a debitar (Kz)" rules={[{ required: true, message: 'Indique o valor a debitar.' }]}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
      </Form>
    </Modal>
  );
}

function ModalPagar({ aberto, ids, total, aoFechar }: { aberto: boolean; ids: number[]; total: number; aoFechar: (r?: { dados: ResultadoLote; mensagem: string }) => void }) {
  const [form] = Form.useForm<{ conta_financeira: string; data?: dayjs.Dayjs; referencia?: string }>();
  const contas = useQuery({ queryKey: CHAVES_CT.contas, queryFn: listarContas, enabled: aberto });
  useEffect(() => { if (aberto) form.resetFields(); }, [aberto, form]);
  const gravar = useMutation({
    mutationFn: (v: { conta_financeira: string; data?: dayjs.Dayjs; referencia?: string }) => pagarRendas(ids, { conta_financeira: v.conta_financeira, data: v.data?.format('YYYY-MM-DD'), referencia: v.referencia }),
    onError: (e) => notificarErro(e, 'Não foi possível criar os pagamentos'), onSuccess: (r) => aoFechar(r),
  });

  return (
    <Modal title={`Pagar ${ids.length} renda(s)`} open={aberto} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Criar pagamentos" cancelText="Cancelar" confirmLoading={gravar.isPending} width={larguraModal(560)} destroyOnHidden>
      <p>Total a sair de caixa: <b>{formatarKz(total)}</b>. O pagamento fica pendente na Tesouraria, para conferir e integrar.</p>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item name="conta_financeira" label="Conta de disponibilidades" rules={[{ required: true, message: 'Escolha a conta de disponibilidades.' }]}>
          <Select showSearch optionFilterProp="label" loading={contas.isLoading} placeholder="— conta —" options={(contas.data ?? []).map((c) => ({ value: c.codigo, label: `${c.codigo} — ${c.descricao ?? ''}` }))} />
        </Form.Item>
        <Form.Item name="data" label="Data do pagamento" extra="Em branco usa-se a data de vencimento de cada renda."><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="referencia" label="Referência"><Input maxLength={60} placeholder="n.º da transferência ou do recibo do senhorio" /></Form.Item>
      </Form>
    </Modal>
  );
}
