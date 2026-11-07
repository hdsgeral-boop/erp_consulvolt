import { Alert, Button, DatePicker, Form, Input, InputNumber, Modal, Select, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { listarFracoes } from './api';
import { CHAVES_PK, gravarReserva, obterDia, verificarReserva, type Espaco, type FichaReserva } from './apiParque';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';

interface Valores { espaco_id?: number; fracao_id?: number | null; data?: dayjs.Dayjs; hora_inicio?: string; hora_fim?: string; pessoas?: number | null; motivo?: string; contacto?: string; autorizacao_motivo?: string }

/**
 * Marcar (ou alterar) uma reserva (imo_reserva_marcar). A verificação em directo mostra o dia do espaço, os horários livres, o que impede a marcação,
 * o preço e a caução; a autorização de quem deve rendas só aparece se for preciso (e exige imo_reserva_devedor, no servidor). Só marca quem tem
 * contrato em vigor numa fracção do imóvel; para um evento da administração deixa-se a fracção em branco.
 */
export function ModalReserva({ reserva, espacoInicial, espacos, podeAutorizar, aoFechar }: {
  reserva: FichaReserva | null | undefined; espacoInicial: number | null; espacos: Espaco[]; podeAutorizar: boolean; aoFechar: (gravada?: FichaReserva) => void;
}) {
  const [form] = Form.useForm<Valores>();
  const aberto = reserva !== undefined;
  const refrescar = useRefrescar();
  const w = Form.useWatch([], form) as Valores | undefined;
  const reservaveis = useMemo(() => espacos.filter((e) => e.activo && e.regime !== 'GRATUITO'), [espacos]);
  const espaco = reservaveis.find((e) => e.id === w?.espaco_id) ?? null;
  const data = w?.data ? dayjs(w.data).format('YYYY-MM-DD') : undefined;
  const fracoes = useQuery({ queryKey: CHAVES_PK.fracoesDoImovel(espaco?.imovel_id ?? 0), queryFn: () => listarFracoes(espaco?.imovel_id as number), enabled: aberto && !!espaco });
  const principais = useMemo(() => (fracoes.data ?? []).filter((f) => f.natureza === 'HABITACIONAL'), [fracoes.data]);
  const dia = useQuery({ queryKey: CHAVES_PK.dia(espaco?.id ?? 0, data ?? ''), queryFn: () => obterDia(espaco?.id as number, data as string), enabled: aberto && !!espaco && !!data });
  const quer = !!espaco && !!data && !!w?.hora_inicio && !!w?.hora_fim;
  const ver = useQuery({
    queryKey: ['imobiliario', 'areas', 'verificar', espaco?.id, w?.fracao_id ?? null, data, w?.hora_inicio, w?.hora_fim, w?.pessoas ?? null, reserva?.id ?? null],
    queryFn: () => verificarReserva({ espaco_id: espaco?.id as number, fracao_id: w?.fracao_id ?? null, data, hora_inicio: w?.hora_inicio, hora_fim: w?.hora_fim, excepto: reserva?.id ?? null, pessoas: w?.pessoas ?? null }),
    enabled: aberto && quer, staleTime: 0,
  });
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    if (reserva) form.setFieldsValue({ espaco_id: reserva.espaco_id, fracao_id: reserva.fracao_id, data: dayjs(reserva.data), hora_inicio: reserva.hora_inicio, hora_fim: reserva.hora_fim, pessoas: reserva.pessoas, motivo: reserva.motivo ?? undefined,
      contacto: reserva.contacto ?? undefined, autorizacao_motivo: reserva.autorizacao_motivo ?? undefined });
    else {
      const e = reservaveis.find((x) => x.id === espacoInicial) ?? reservaveis[0];
      form.setFieldsValue({ espaco_id: e?.id, data: dayjs(), hora_inicio: e?.hora_abre, hora_fim: e?.hora_fecha });
    }
  }, [aberto, reserva, espacoInicial, reservaveis, form]);

  const gravar = useMutation({
    mutationFn: (v: Valores) => gravarReserva({ ...v, data: v.data ? dayjs(v.data).format('YYYY-MM-DD') : undefined, fracao_id: v.fracao_id ?? null,
      autorizacao_motivo: ver.data?.exige_autorizacao ? v.autorizacao_motivo : undefined }, reserva?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar a reserva'),
    onSuccess: async (r) => { message.success(reserva ? 'Reserva actualizada.' : 'Reserva marcada.'); await refrescar(); aoFechar(r.dados); },
  });
  const v = ver.data;
  const impedida = !!v && v.impedimentos.length > 0;
  const falta = !quer || ver.isFetching || impedida || (!!v?.exige_autorizacao && (!podeAutorizar || !(w?.autorizacao_motivo ?? '').trim()));
  const preencher = (inicio: string, fim: string) => form.setFieldsValue({ hora_inicio: inicio, hora_fim: fim });

  return (
    <Modal title={reserva ? 'Editar reserva' : 'Marcar espaço'} open={aberto} onCancel={() => aoFechar()} width={larguraModal(760)} destroyOnHidden
      footer={[<Button key="c" onClick={() => aoFechar()}>Cancelar</Button>, <Button key="g" type="primary" disabled={falta} loading={gravar.isPending} onClick={() => form.submit()}>{reserva ? 'Gravar' : 'Marcar'}</Button>]}>
      <p>Só marca quem tem contrato em vigor numa fracção deste imóvel. Para um evento da administração, deixe a fracção em branco.</p>
      {reservaveis.length === 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Não há espaços com reserva activos." />}
      <Form form={form} layout="vertical" onFinish={(x) => gravar.mutate(x)} disabled={gravar.isPending}>
        <Form.Item name="espaco_id" label="Espaço" rules={[{ required: true, message: 'Escolha o espaço.' }]}>
          <Select disabled={!!reserva} showSearch optionFilterProp="label" placeholder="— espaço —" options={reservaveis.map((e) => ({ value: e.id, label: `${e.nome}${e.imovel_nome ? ` — ${e.imovel_nome}` : ''}` }))} onChange={() => form.setFieldsValue({ fracao_id: null })} />
        </Form.Item>
        <Form.Item name="fracao_id" label="Fracção" extra="Em branco: reserva interna, em nome da administração.">
          <Select allowClear showSearch optionFilterProp="label" loading={fracoes.isLoading} placeholder="— reserva interna (administração) —" options={principais.map((f) => ({ value: f.id, label: f.designacao || f.codigo }))} />
        </Form.Item>
        <div className="imo-linha-form">
          <Form.Item name="data" label="Data" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: 160 }} /></Form.Item>
          <Form.Item name="hora_inicio" label="Das" rules={[{ required: true, message: 'Indique a hora de início.' }]}><Input type="time" style={{ width: 120 }} /></Form.Item>
          <Form.Item name="hora_fim" label="Às" rules={[{ required: true, message: 'Indique a hora de fim.' }]}><Input type="time" style={{ width: 120 }} /></Form.Item>
          <Form.Item name="pessoas" label="Pessoas" extra={espaco?.capacidade ? `Cabem ${espaco.capacidade}.` : undefined}><InputNumber min={1} precision={0} style={{ width: 100 }} /></Form.Item>
        </div>
        {dia.data && (
          <div className="imo-livres" aria-live="polite">
            <strong>{dia.data.dia}, {dia.data.data} — {!dia.data.abre ? 'o espaço não abre neste dia' : dia.data.vazio ? `livre o dia todo (${dia.data.hora_abre}–${dia.data.hora_fecha})` : `${dia.data.ocupadas.length} reserva(s)`}</strong>
            {dia.data.ocupadas.map((o) => <Tag key={o.id} color="orange">{o.inicio}–{o.fim} ocupado</Tag>)}
            {dia.data.livres.length > 0 && !dia.data.vazio && <span>Livre:</span>}
            {dia.data.livres.length > 0 && !dia.data.vazio && dia.data.livres.map((l) => <Button key={l.inicio} size="small" onClick={() => preencher(l.inicio, l.fim)}>{l.inicio}–{l.fim}</Button>)}
          </div>
        )}
        <Form.Item name="motivo" label="Motivo"><Input maxLength={300} placeholder="aniversário, assembleia de condóminos…" /></Form.Item>
        <Form.Item name="contacto" label="Contacto"><Input maxLength={100} placeholder="telefone de quem marca" /></Form.Item>
        {v?.exige_autorizacao && (
          <Form.Item name="autorizacao_motivo" label="Autorização" extra="A fracção deve rendas. Para marcar assim mesmo é preciso que alguém assuma a decisão — fica gravada com o nome de quem autoriza."
            rules={[{ required: true, whitespace: true, message: 'Indique porque se autoriza apesar da dívida.' }]}>
            <Input maxLength={300} disabled={!podeAutorizar} placeholder="porque se autoriza apesar da dívida" />
          </Form.Item>
        )}
      </Form>
      {impedida && <Alert type="error" showIcon style={{ marginBottom: 8 }} title="Não se pode marcar assim:" description={<ul className="imo-lista">{v?.impedimentos.map((i) => <li key={i}>{i}</li>)}</ul>} />}
      {v && v.avisos.length > 0 && <Alert type="warning" showIcon style={{ marginBottom: 8 }} title={v.avisos.join(' ')} />}
      {v?.exige_autorizacao && !podeAutorizar && <Alert type="error" showIcon style={{ marginBottom: 8 }} title="Só quem tem a permissão de autorizar reservas de devedores pode marcar esta reserva." />}
      {v && !impedida && Number(v.valor) > 0 && <Alert type="info" showIcon title={`${formatarKz(Number(v.valor))} — ${v.detalhe}${Number(v.caucao) > 0 ? `, mais ${formatarKz(Number(v.caucao))} de caução` : ''}. O espaço só fica garantido depois do pagamento.`} />}
    </Modal>
  );
}
