import { Alert, DatePicker, Form, Input, InputNumber, Modal, Select, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useRef } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES_CT, listarContas } from './apiContratos';
import { cancelarReserva, movimentarCaucaoReserva, obterPrevisao, receberReserva, registarVistoria, type FichaReserva, type ListasParque } from './apiParque';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';
import { chaveDePedido } from './logicaContratos';

const opContas = (c: { codigo: string; descricao: string | null }[] | undefined) => (c ?? []).map((x) => ({ value: x.codigo, label: `${x.codigo} — ${x.descricao ?? ''}` }));

/** Cancelar uma reserva (imo_reserva_cancelar): mostra o que se devolve pela escala do espaço e pede o motivo. Com factura por pagar emite a nota de crédito; paga, deixa o reembolso por tratar. */
export function ModalCancelarReserva({ reserva, aoFechar }: { reserva: FichaReserva | null; aoFechar: (feito?: boolean) => void }) {
  const [form] = Form.useForm<{ motivo: string; devolver?: number }>();
  const refrescar = useRefrescar();
  const p = useQuery({ queryKey: ['imobiliario', 'areas', 'previsao', reserva?.id ?? 0], queryFn: () => obterPrevisao((reserva as FichaReserva).id), enabled: !!reserva });
  useEffect(() => { if (reserva) form.resetFields(); }, [reserva, form]);
  useEffect(() => { if (p.data) form.setFieldsValue({ devolver: p.data.devolve }); }, [p.data, form]);
  const cancelar = useMutation({
    mutationFn: (v: { motivo: string; devolver?: number }) => cancelarReserva((reserva as FichaReserva).id, v.motivo, p.data && p.data.recebido > 0 ? v.devolver : undefined),
    onError: (e) => notificarErro(e, 'Não foi possível cancelar a reserva'),
    onSuccess: async (r) => { message.success(r.dados.previsao.recebido > 0 ? `Reserva cancelada. A devolver ${formatarKz(r.dados.previsao.devolve_aplicado ?? r.dados.previsao.devolve)} de ${formatarKz(r.dados.previsao.recebido)}.` : r.mensagem); await refrescar(); aoFechar(true); },
  });
  const d = p.data;

  return (
    <Modal title="Cancelar a reserva" open={!!reserva} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Cancelar a reserva" cancelText="Manter" okButtonProps={{ danger: true }} confirmLoading={cancelar.isPending} width={larguraModal(560)} destroyOnHidden>
      {d && (
        <Alert type={d.recebido > 0 ? 'warning' : 'info'} showIcon style={{ marginBottom: 12 }} title={d.explicacao}
          description={d.recebido > 0 ? <span>Recebido {formatarKz(d.recebido)} · devolve-se {formatarKz(d.devolve)} ({d.pct} %) · fica retido {formatarKz(d.retem)}. A nota de crédito da devolução faz-se em Vendas, depois de anular o recibo.{d.caucao_a_devolver > 0 ? ` A caução em poder (${formatarKz(d.caucao_a_devolver)}) continua por devolver.` : ''}</span>
            : d.facturada ? 'Nada foi pago: a factura emitida é anulada com uma nota de crédito.' : undefined} />
      )}
      <Form form={form} layout="vertical" onFinish={(v) => cancelar.mutate(v)} disabled={cancelar.isPending}>
        <Form.Item name="motivo" label="Motivo do cancelamento" extra="Fica no registo da reserva." rules={[{ required: true, whitespace: true, message: 'Diga o motivo do cancelamento.' }]}><Input.TextArea rows={2} maxLength={300} /></Form.Item>
        {d && d.recebido > 0 && <Form.Item name="devolver" label="Devolver (Kz)" extra="Por omissão, o que a escala do espaço manda; nunca mais do que foi recebido."><InputNumber min={0} max={d.recebido} precision={2} style={{ width: '100%' }} /></Form.Item>}
      </Form>
    </Modal>
  );
}

/** Receber o pagamento da utilização (recibo sobre a factura) e/ou da caução (imo_reserva_receber). */
export function ModalReceberReserva({ reserva, aoFechar }: { reserva: FichaReserva | null; aoFechar: (feito?: boolean) => void }) {
  const [form] = Form.useForm<{ valor: number; caucao: number; data: dayjs.Dayjs; meio_pagamento: string; conta_financeira: string }>();
  const refrescar = useRefrescar();
  const chave = useRef(chaveDePedido());
  const contas = useQuery({ queryKey: CHAVES_CT.contas, queryFn: listarContas, enabled: !!reserva });
  const s = reserva?.situacao;
  useEffect(() => {
    if (!reserva || !s) return;
    chave.current = chaveDePedido();
    form.resetFields();
    form.setFieldsValue({ valor: s.venda_id ? s.por_pagar : 0, caucao: s.caucao.por_receber, data: dayjs(), meio_pagamento: 'NUMERARIO' });
  }, [reserva, s, form]);
  const gravar = useMutation({
    mutationFn: (v: { valor: number; caucao: number; data: dayjs.Dayjs; meio_pagamento: string; conta_financeira: string }) => receberReserva((reserva as FichaReserva).id,
      { valor: v.valor ?? 0, caucao: v.caucao ?? 0, data: v.data.format('YYYY-MM-DD'), meio_pagamento: v.meio_pagamento, conta_financeira: v.conta_financeira, chave: chave.current }),
    onError: (e) => notificarErro(e, 'Não foi possível registar o recebimento'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(true); },
  });

  return (
    <Modal title="Receber da reserva" open={!!reserva} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Registar" cancelText="Cancelar" confirmLoading={gravar.isPending} width={larguraModal(540)} destroyOnHidden>
      <p>A utilização é receita; a caução é dinheiro à guarda da empresa, a devolver — por isso vão por caminhos diferentes.</p>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item name="valor" label="Utilização (Kz)" extra={s?.venda_id ? (s.por_pagar > 0 ? `Faltam ${formatarKz(s.por_pagar)}.` : 'Já está pago.') : 'Facture a reserva antes de receber a utilização.'}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="caucao" label="Caução (Kz)" extra={s && s.caucao.por_receber > 0 ? `Faltam ${formatarKz(s.caucao.por_receber)} de caução.` : 'Sem caução por receber.'}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="data" label="Data" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isAfter(dayjs(), 'day')} /></Form.Item>
        <Form.Item name="meio_pagamento" label="Meio"><Select options={[{ value: 'NUMERARIO', label: 'Numerário' }, { value: 'TRANSFERENCIA', label: 'Transferência' }, { value: 'TPA', label: 'Multicaixa (TPA)' }]} /></Form.Item>
        <Form.Item name="conta_financeira" label="Conta de caixa ou banco" extra="Onde o dinheiro entrou. O ERP não põe conta nenhuma por omissão." rules={[{ required: true, message: 'Indique a conta de caixa ou banco.' }]}>
          <Select showSearch optionFilterProp="label" loading={contas.isLoading} placeholder="— conta —" options={opContas(contas.data)} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Vistoria de entrada (o estado em que se entregou o espaço) ou de saída (o que justifica reter caução) — imo_reserva_vistoria. */
export function ModalVistoriaReserva({ reserva, listas, aoFechar }: { reserva: FichaReserva | null; listas: ListasParque | undefined; aoFechar: (feito?: boolean) => void }) {
  const [form] = Form.useForm<{ quando: string; estado: string; data: dayjs.Dayjs; observacoes?: string; url?: string; custo_danos?: number }>();
  const refrescar = useRefrescar();
  useEffect(() => { if (reserva) { form.resetFields(); form.setFieldsValue({ quando: 'DEPOIS', estado: 'BOM', data: dayjs() }); } }, [reserva, form]);
  const gravar = useMutation({
    mutationFn: (v: { quando: string; estado: string; data: dayjs.Dayjs; observacoes?: string; url?: string; custo_danos?: number }) => registarVistoria((reserva as FichaReserva).id, { ...v, data: v.data.format('YYYY-MM-DD') }),
    onError: (e) => notificarErro(e, 'Não foi possível registar a vistoria'),
    onSuccess: async () => { message.success('Vistoria registada.'); await refrescar(); aoFechar(true); },
  });

  return (
    <Modal title="Vistoria" open={!!reserva} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Registar" cancelText="Cancelar" confirmLoading={gravar.isPending} width={larguraModal(540)} destroyOnHidden>
      <p>A de entrada é a fotografia do estado em que se entregou o espaço; a de saída é o que justifica reter caução — e sem ela não se retém nada.</p>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item name="quando" label="Momento"><Select options={[{ value: 'DEPOIS', label: 'Saída — depois de usar' }, { value: 'ANTES', label: 'Entrada — antes de entregar' }]} /></Form.Item>
        <Form.Item name="estado" label="Como ficou"><Select options={(listas?.estados_vistoria ?? []).map((o) => ({ value: o.valor, label: o.rotulo }))} /></Form.Item>
        <Form.Item name="data" label="Data" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="observacoes" label="O que se encontrou"><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
        <Form.Item name="url" label="Fotografias ou relatório (endereço)" extra="O ERP guarda o endereço do documento, não o ficheiro."><Input maxLength={1000} placeholder="https://…" /></Form.Item>
        <Form.Item name="custo_danos" label="Custo estimado dos danos (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
      </Form>
    </Modal>
  );
}

/** Fechar a caução (imo_reserva_caucao): devolver o que não se retém e reter por danos (com a vistoria de saída que o prova). A retenção é facturada à parte; só essa parte é receita. */
export function ModalCaucaoReserva({ reserva, aoFechar }: { reserva: FichaReserva | null; aoFechar: (feito?: boolean) => void }) {
  const [form] = Form.useForm<{ tipo: string; valor: number; data: dayjs.Dayjs; conta_financeira?: string; motivo?: string }>();
  const refrescar = useRefrescar();
  const chave = useRef(chaveDePedido());
  const contas = useQuery({ queryKey: CHAVES_CT.contas, queryFn: listarContas, enabled: !!reserva });
  const tipo = Form.useWatch('tipo', form) as string | undefined;
  const c = reserva?.situacao.caucao;
  useEffect(() => {
    if (!reserva || !c) return;
    chave.current = chaveDePedido();
    form.resetFields();
    form.setFieldsValue({ tipo: 'DEVOLVIDA', valor: c.em_poder, data: dayjs() });
  }, [reserva, c, form]);
  const gravar = useMutation({
    mutationFn: (v: { tipo: string; valor: number; data: dayjs.Dayjs; conta_financeira?: string; motivo?: string }) => movimentarCaucaoReserva((reserva as FichaReserva).id,
      { tipo: v.tipo, valor: v.valor, data: v.data.format('YYYY-MM-DD'), conta_financeira: v.conta_financeira, motivo: v.motivo, chave: chave.current }),
    onError: (e) => notificarErro(e, 'Não foi possível registar o movimento da caução'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(true); },
  });
  const semVistoria = !reserva?.situacao.vistoria_depois;

  return (
    <Modal title="Fechar a caução" open={!!reserva} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Registar e contabilizar" cancelText="Cancelar" confirmLoading={gravar.isPending} width={larguraModal(540)} destroyOnHidden>
      {c && <p>Há {formatarKz(c.em_poder)} à guarda da empresa (recebida {formatarKz(c.recebida)}, devolvida {formatarKz(c.devolvida)}, retida {formatarKz(c.retida)}). O que não se retiver volta ao reservante; o que se retiver passa a ser receita e é facturado.</p>}
      {semVistoria && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Sem vistoria de saída só se pode devolver: reter dinheiro de outra pessoa sem prova não se faz. Registe a vistoria primeiro." />}
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item name="tipo" label="Movimento"><Select options={[{ value: 'DEVOLVIDA', label: 'Devolver' }, { value: 'RETIDA', label: 'Reter por danos', disabled: semVistoria }, { value: 'RECEBIDA', label: 'Receber caução em falta' }]} /></Form.Item>
        <Form.Item name="valor" label="Valor (Kz)" rules={[{ required: true, message: 'Indique o valor.' }]}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="data" label="Data" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        {tipo === 'RETIDA'
          ? <Form.Item name="motivo" label="O que se encontrou" extra="Obrigatório para reter." rules={[{ required: true, whitespace: true, message: 'Para reter caução é preciso dizer o que se encontrou.' }]}><Input.TextArea rows={2} maxLength={300} /></Form.Item>
          : <Form.Item name="conta_financeira" label="Conta de caixa ou banco" rules={[{ required: true, message: 'Indique a conta de caixa ou banco.' }]}><Select showSearch optionFilterProp="label" loading={contas.isLoading} placeholder="— conta —" options={opContas(contas.data)} /></Form.Item>}
      </Form>
    </Modal>
  );
}

