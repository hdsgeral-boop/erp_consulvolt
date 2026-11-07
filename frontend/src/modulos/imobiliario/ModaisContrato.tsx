import { Alert, Checkbox, DatePicker, Form, Input, InputNumber, Modal, Select, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useRef, useState } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { actualizarRenda, CHAVES_CT, listarContas, movimentarCaucao, terminarContrato, type FichaContrato } from './apiContratos';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';
import { chaveDePedido } from './logicaContratos';

/** Pede um motivo escrito e confirma (suspender, anular, desligar o pagamento…). O servidor volta a exigi-lo. */
export function ModalMotivo({ titulo, aberto, texto, okText, aoConfirmar, aoFechar, carregando, opcional }: {
  titulo: string; aberto: boolean; texto?: string; okText: string; aoConfirmar: (motivo: string) => void; aoFechar: () => void; carregando?: boolean; opcional?: boolean;
}) {
  const [motivo, setMotivo] = useState('');
  useEffect(() => { if (aberto) setMotivo(''); }, [aberto]);

  return (
    <Modal title={titulo} open={aberto} onCancel={aoFechar} onOk={() => aoConfirmar(motivo.trim())} okText={okText} cancelText="Cancelar" okButtonProps={{ disabled: !opcional && motivo.trim() === '' }} confirmLoading={carregando} destroyOnHidden>
      {texto && <p>{texto}</p>}
      <label htmlFor="imo-motivo">Motivo{opcional ? ' (opcional)' : ''}</label>
      <Input.TextArea id="imo-motivo" rows={3} maxLength={300} value={motivo} onChange={(e) => setMotivo(e.target.value)} />
    </Modal>
  );
}

/** Terminar ou denunciar um contrato: data, natureza, motivo e, havendo subarrendamentos, a confirmação da cessação em cascata. */
export function ModalTerminar({ contrato, aoFechar }: { contrato: FichaContrato | null; aoFechar: (feito?: boolean) => void }) {
  const [form] = Form.useForm<{ data: dayjs.Dayjs; denuncia: boolean; motivo: string; cascata: boolean }>();
  const refrescar = useRefrescar();
  const filhos = (contrato?.filhos ?? []).filter((f) => ['ACTIVO', 'SUSPENSO', 'RASCUNHO'].includes(f.estado));
  useEffect(() => { if (contrato) { form.resetFields(); form.setFieldsValue({ data: dayjs(), denuncia: false, cascata: false }); } }, [contrato, form]);
  const terminar = useMutation({
    mutationFn: (v: { data: dayjs.Dayjs; denuncia: boolean; motivo: string; cascata: boolean }) => terminarContrato((contrato as FichaContrato).id, { data: v.data.format('YYYY-MM-DD'), motivo: v.motivo, denuncia: !!v.denuncia, cascata: !!v.cascata }),
    onError: (e) => notificarErro(e, 'Não foi possível terminar o contrato'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(true); },
  });

  return (
    <Modal title={contrato ? `Terminar o contrato ${contrato.numero}` : 'Terminar'} open={!!contrato} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Terminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
      confirmLoading={terminar.isPending} width={larguraModal(600)} destroyOnHidden>
      {contrato && contrato.conta_corrente.em_divida > 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title={`Há ${formatarKz(contrato.conta_corrente.em_divida)} em dívida. A saída não apaga a dívida: continua a cobrar-se.`} />}
      {contrato && contrato.caucao_em_poder > 0 && <Alert type="info" showIcon style={{ marginBottom: 12 }} title={`Há ${formatarKz(contrato.caucao_em_poder)} de caução em poder: devolva-a ou retenha-a (com motivo) no separador Caução. Quem movimenta a caução não é quem termina o contrato.`} />}
      {filhos.length > 0 && <Alert type="error" showIcon style={{ marginBottom: 12 }} title={`Este contrato dá título a ${filhos.length} subarrendamento(s): ${filhos.map((f) => f.numero).join(', ')}. Caducam com ele.`} />}
      <Form form={form} layout="vertical" onFinish={(v) => terminar.mutate(v)} disabled={terminar.isPending}>
        <Form.Item name="data" label="Data de saída" rules={[{ required: true, message: 'Indique a data de saída.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="denuncia" label="Natureza"><Select options={[{ value: false, label: 'Termo do contrato' }, { value: true, label: 'Denúncia' }]} /></Form.Item>
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Indique o motivo da saída.' }]}><Input.TextArea rows={2} maxLength={300} placeholder="fim do prazo, denúncia pelo inquilino, incumprimento…" /></Form.Item>
        {filhos.length > 0 && (
          <Form.Item name="cascata" valuePropName="checked" rules={[{ validator: (_, v) => (v ? Promise.resolve() : Promise.reject(new Error('Confirme a cessação em cascata.'))) }]}>
            <Checkbox>Compreendo e confirmo a cessação em cascata dos subarrendamentos</Checkbox>
          </Form.Item>
        )}
        <p>As rendas futuras ainda não documentadas são anuladas, a renda do período da saída fica proporcional e as fracções ficam disponíveis.</p>
      </Form>
    </Modal>
  );
}

/** Acordo de nova renda a partir de uma data (recalcula as rendas ainda não documentadas). */
export function ModalActualizarRenda({ contrato, aoFechar }: { contrato: FichaContrato | null; aoFechar: (feito?: boolean) => void }) {
  const [form] = Form.useForm<{ renda_nova: number; data_efeito: dayjs.Dayjs; recalcular: boolean }>();
  const refrescar = useRefrescar();
  useEffect(() => { if (contrato) { form.resetFields(); form.setFieldsValue({ renda_nova: contrato.renda_no_periodo, data_efeito: dayjs().startOf('month'), recalcular: true }); } }, [contrato, form]);
  const gravar = useMutation({
    mutationFn: (v: { renda_nova: number; data_efeito: dayjs.Dayjs; recalcular: boolean }) => actualizarRenda((contrato as FichaContrato).id, { renda_nova: v.renda_nova, data_efeito: v.data_efeito.format('YYYY-MM-DD'), recalcular: !!v.recalcular }),
    onError: (e) => notificarErro(e, 'Não foi possível actualizar a renda'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(true); },
  });
  const regra = contrato ? (contrato.actualizacao === 'PERC' ? `${contrato.actualizacao_valor} % a cada ${contrato.actualizacao_meses} meses` : contrato.actualizacao === 'VALOR' ? `${formatarKz(contrato.actualizacao_valor)} a cada ${contrato.actualizacao_meses} meses` : null) : null;

  return (
    <Modal title={contrato ? `Actualizar a renda de ${contrato.numero}` : 'Actualizar a renda'} open={!!contrato} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Actualizar" cancelText="Cancelar"
      confirmLoading={gravar.isPending} width={larguraModal(520)} destroyOnHidden>
      <p>{regra ? `A regra do contrato (${regra}) dá ${formatarKz(contrato?.renda_no_periodo)}. A actualização só afecta rendas ainda não facturadas.` : 'Este contrato não tem cláusula de actualização: o novo valor tem de ser acordado por escrito com a outra parte.'}</p>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item label="Renda actual"><InputNumber disabled value={contrato?.renda} style={{ width: '100%' }} aria-label="Renda actual" /></Form.Item>
        <Form.Item name="renda_nova" label="Renda nova (Kz)" rules={[{ required: true, message: 'A renda nova tem de ser maior do que zero.' }]}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="data_efeito" label="Data de efeito" rules={[{ required: true, message: 'Indique a data de efeito.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="recalcular" valuePropName="checked"><Checkbox>Recalcular as rendas ainda não facturadas a partir da data de efeito</Checkbox></Form.Item>
      </Form>
    </Modal>
  );
}

/** Movimento da caução (recebida, devolvida, retida). A chave do pedido impede lançar duas vezes se o pedido se repetir. */
export function ModalCaucao({ contrato, tipo, aoFechar }: { contrato: FichaContrato | null; tipo: 'RECEBIDA' | 'DEVOLVIDA' | 'RETIDA' | null; aoFechar: (feito?: boolean) => void }) {
  const [form] = Form.useForm<{ valor: number; data: dayjs.Dayjs; conta_financeira?: string; motivo?: string }>();
  const refrescar = useRefrescar();
  const chave = useRef(chaveDePedido());
  const aberto = !!contrato && !!tipo;
  const contas = useQuery({ queryKey: CHAVES_CT.contas, queryFn: listarContas, enabled: aberto && tipo !== 'RETIDA' });
  const resumo = contrato?.caucao_ledger.resumo;
  const lado = contrato?.posicao;
  useEffect(() => {
    if (!aberto || !resumo) return;
    chave.current = chaveDePedido();
    form.resetFields();
    form.setFieldsValue({ data: dayjs(), valor: tipo === 'RECEBIDA' ? resumo.por_receber : resumo.em_poder });
  }, [aberto, tipo, resumo, form]);
  const gravar = useMutation({
    mutationFn: (v: { valor: number; data: dayjs.Dayjs; conta_financeira?: string; motivo?: string }) => movimentarCaucao((contrato as FichaContrato).id, { tipo: tipo as string, valor: v.valor, data: v.data.format('YYYY-MM-DD'),
      conta_financeira: v.conta_financeira, motivo: v.motivo, chave: chave.current }),
    onError: (e) => notificarErro(e, 'Não foi possível registar o movimento da caução'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(true); },
  });
  const titulo = tipo === 'RECEBIDA' ? (lado === 'SENHORIO' ? 'Receber a caução' : 'Entregar a caução') : tipo === 'DEVOLVIDA' ? 'Devolver a caução' : 'Reter a caução';

  return (
    <Modal title={titulo} open={aberto} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Registar e contabilizar" cancelText="Cancelar" confirmLoading={gravar.isPending} width={larguraModal(520)} destroyOnHidden>
      {resumo && <p>Acordada {formatarKz(resumo.acordada)} · recebida {formatarKz(resumo.recebida)} · em poder {formatarKz(resumo.em_poder)}.</p>}
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item name="valor" label="Valor (Kz)" rules={[{ required: true, message: 'Indique o valor.' }]}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="data" label="Data" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        {tipo !== 'RETIDA' && (
          <Form.Item name="conta_financeira" label="Conta de disponibilidades" rules={[{ required: true, message: 'Indique a conta de disponibilidades.' }]}>
            <Select showSearch optionFilterProp="label" loading={contas.isLoading} placeholder="— conta —" options={(contas.data ?? []).map((c) => ({ value: c.codigo, label: `${c.codigo} — ${c.descricao ?? ''}` }))} />
          </Form.Item>
        )}
        {tipo === 'RETIDA' && <Form.Item name="motivo" label="Motivo da retenção" extra="Retida sem motivo escrito é uma retenção indefensável." rules={[{ required: true, message: 'Indique o motivo da retenção.' }]}><Input.TextArea rows={2} maxLength={300} /></Form.Item>}
        <p>O movimento é lançado na contabilidade com as contas da configuração do módulo. Para corrigir, anula-se (fica o estorno).</p>
      </Form>
    </Modal>
  );
}
