import { Button, Checkbox, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Switch, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, listarImoveis } from './api';
import { eliminarEspaco, gravarEspaco, type Espaco, type ListasParque } from './apiParque';
import { useRefrescar } from './hooks';

type Valores = Partial<Omit<Espaco, 'id'>> & { nome?: string };

const POR_OMISSAO: Valores = {
  tipo: 'SALAO', regime: 'RESERVA_GRATUITA', hora_abre: '08:00', hora_fecha: '22:00', dias_semana: [0, 1, 2, 3, 4, 5, 6], antecedencia_min_dias: 0, antecedencia_max_dias: 0, exige_aprovacao: false,
  preco: 0, preco_unidade: 'HORA', caucao: 0, prazo_pagamento_horas: 48, cancel_dias: 7, cancel_pct: 50, activo: true,
};

/** Criar e editar um espaço comum e o seu regulamento (imo_area_editar): horário, dias, duração, antecedência, limite por fracção, aprovação, preço, caução, prazo e escala de cancelamento. */
export function ModalEspaco({ espaco, imovelInicial, listas, aoFechar }: { espaco: Espaco | null | undefined; imovelInicial: number | null; listas: ListasParque | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<Valores>();
  const aberto = espaco !== undefined;
  const refrescar = useRefrescar();
  const regime = (Form.useWatch('regime', form) as string | undefined) ?? 'RESERVA_GRATUITA';
  const def = listas?.regimes.find((r) => r.valor === regime);
  const imoveis = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis, enabled: aberto });
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(espaco ? { ...espaco } : { ...POR_OMISSAO, imovel_id: imovelInicial ?? undefined });
  }, [aberto, espaco, imovelInicial, form]);
  const gravar = useMutation({
    mutationFn: (v: Valores) => gravarEspaco({ ...v, regulamento_url: v.regulamento_url || null }, espaco?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar o espaço'),
    onSuccess: async () => { message.success(espaco ? 'Espaço actualizado.' : 'Espaço criado.'); await refrescar(); aoFechar(); },
  });
  const apagar = useMutation({
    mutationFn: () => eliminarEspaco((espaco as Espaco).id), onError: (e) => notificarErro(e, 'Não foi possível apagar o espaço'),
    onSuccess: async () => { message.success('Espaço apagado.'); await refrescar(); aoFechar(); },
  });
  const op = (m: { valor: string; rotulo: string }[] | undefined) => (m ?? []).map((o) => ({ value: o.valor, label: o.rotulo }));
  const dias = (listas?.dias ?? ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado']).map((d, i) => ({ value: i, label: d.slice(0, 3) }));

  return (
    <Modal title={espaco ? `Editar ${espaco.nome}` : 'Novo espaço comum'} open={aberto} onCancel={aoFechar} width={larguraModal(860)} destroyOnHidden
      footer={[
        ...(espaco ? [<Popconfirm key="ap" title={`Apagar «${espaco.nome}»? Se já teve reservas, ponha-o inactivo em vez de o apagar.`} okText="Apagar" cancelText="Manter" okButtonProps={{ danger: true }} onConfirm={() => apagar.mutate()}><Button danger loading={apagar.isPending}>Apagar</Button></Popconfirm>] : []),
        <Button key="c" onClick={aoFechar}>Cancelar</Button>,
        <Button key="g" type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>,
      ]}>
      <p>O regulamento do espaço é o que evita a discussão de sábado de manhã: horário, duração, quantas vezes por mês cada fracção pode usar e quem aprova.</p>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Space wrap align="start" size={12}>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, whitespace: true, message: 'Dê um nome ao espaço (ex.: «Salão de festas»).' }]}><Input maxLength={120} placeholder="Salão de festas" style={{ width: 240 }} /></Form.Item>
          <Form.Item name="imovel_id" label="Imóvel" rules={[{ required: true, message: 'Escolha o imóvel a que o espaço pertence.' }]}>
            <Select showSearch optionFilterProp="label" style={{ width: 280 }} loading={imoveis.isLoading} placeholder="— imóvel —" options={(imoveis.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} · ${i.nome}` }))} />
          </Form.Item>
          <Form.Item name="tipo" label="Tipo"><Select style={{ width: 200 }} options={op(listas?.tipos_area)} /></Form.Item>
          <Form.Item name="localizacao" label="Localização"><Input maxLength={150} placeholder="Cobertura, bloco B" style={{ width: 220 }} /></Form.Item>
          <Form.Item name="capacidade" label="Capacidade (pessoas)"><InputNumber min={0} precision={0} style={{ width: 150 }} /></Form.Item>
        </Space>
        <Form.Item name="regime" label="Regime" extra={def?.ajuda}><Select style={{ maxWidth: 360 }} options={op(listas?.regimes)} /></Form.Item>
        {def?.reserva && (
          <fieldset className="imo-grupo">
            <legend>Quando se pode usar</legend>
            <Space wrap align="start" size={12}>
              <Form.Item name="hora_abre" label="Abre às" rules={[{ required: true, message: 'Indique a hora de abertura.' }]}><Input type="time" style={{ width: 130 }} /></Form.Item>
              <Form.Item name="hora_fecha" label="Fecha às" rules={[{ required: true, message: 'Indique a hora de fecho.' }]}><Input type="time" style={{ width: 130 }} /></Form.Item>
              <Form.Item name="dias_semana" label="Dias"><Checkbox.Group options={dias} /></Form.Item>
            </Space>
            <Space wrap align="start" size={12}>
              <Form.Item name="duracao_min" label="Duração mínima (h)"><InputNumber min={0} step={0.5} precision={1} style={{ width: 140 }} /></Form.Item>
              <Form.Item name="duracao_max" label="Duração máxima (h)"><InputNumber min={0} step={0.5} precision={1} style={{ width: 140 }} /></Form.Item>
              <Form.Item name="antecedencia_min_dias" label="Antecedência mínima (dias)" extra="Para dar tempo de preparar o espaço."><InputNumber min={0} precision={0} style={{ width: 170 }} /></Form.Item>
              <Form.Item name="antecedencia_max_dias" label="Antecedência máxima (dias)" extra="0 = sem limite. Impede marcar o salão para daqui a dois anos."><InputNumber min={0} precision={0} style={{ width: 170 }} /></Form.Item>
              <Form.Item name="limite_mes_fraccao" label="Limite por fracção/mês" extra="Sem limite, uma família fica com todos os sábados."><InputNumber min={0} precision={0} style={{ width: 160 }} /></Form.Item>
              <Form.Item name="exige_aprovacao" label="Aprovação" valuePropName="checked"><Switch checkedChildren="Exige" unCheckedChildren="Não exige" /></Form.Item>
            </Space>
          </fieldset>
        )}
        {def?.paga && (
          <fieldset className="imo-grupo">
            <legend>Quanto custa</legend>
            <Space wrap align="start" size={12}>
              <Form.Item name="preco" label="Preço (Kz)" rules={[{ required: true, type: 'number', min: 0.01, message: 'Um espaço de reserva paga precisa de preço.' }]}><InputNumber min={0} precision={2} style={{ width: 160 }} /></Form.Item>
              <Form.Item name="preco_unidade" label="Cobrado"><Select style={{ width: 230 }} options={op(listas?.unidades_preco)} /></Form.Item>
              <Form.Item name="caucao" label="Caução (Kz)" extra="Devolve-se se não houver danos. Nunca é proveito."><InputNumber min={0} precision={2} style={{ width: 160 }} /></Form.Item>
              <Form.Item name="prazo_pagamento_horas" label="Prazo para pagar (horas)" extra="Passado o prazo sem pagar, a reserva cai e liberta o horário."><InputNumber min={1} precision={0} style={{ width: 170 }} /></Form.Item>
              <Form.Item name="cancel_dias" label="Devolve tudo até (dias antes)"><InputNumber min={0} precision={0} style={{ width: 170 }} /></Form.Item>
              <Form.Item name="cancel_pct" label="Depois devolve (%)" extra="Quem desiste em cima da hora deixa o espaço por ocupar."><InputNumber min={0} max={100} precision={0} style={{ width: 150 }} /></Form.Item>
            </Space>
            <p>A receita das reservas vai pelo artigo de serviço configurado em Modelos e Taxas › Facturação e contas.</p>
          </fieldset>
        )}
        <Form.Item name="regulamento" label="Regulamento"><Input.TextArea rows={3} maxLength={10000} placeholder="Devolver limpo, sem música depois das 22h…" /></Form.Item>
        <Space wrap align="start" size={12}>
          <Form.Item name="regulamento_url" label="Regulamento (endereço)"><Input maxLength={1000} placeholder="https://…" style={{ width: 340 }} /></Form.Item>
          <Form.Item name="activo" label="Activo" valuePropName="checked" extra="O espaço está em uso."><Switch /></Form.Item>
        </Space>
      </Form>
    </Modal>
  );
}
