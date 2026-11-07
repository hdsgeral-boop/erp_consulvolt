import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, Button, DatePicker, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Switch, Table, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, listarFracoes, listarImoveis } from './api';
import { CHAVES_OB, eliminarPlano, gerarOrdensPlano, gravarPlano, obterCalendarioPlano, type LinhaCalendario, type ListasObras, type Plano } from './apiObras';
import { useRefrescar } from './hooks';
import { COR_DATA_PLANO, proporDatas, ROTULO_DATA_PLANO } from './logicaObras';

interface ValoresPlano {
  tipo: 'PREVENTIVA' | 'VISTORIA'; nome?: string; descricao: string; imovel_id?: number; fracao_id?: number | null; periodicidade: string; primeira?: dayjs.Dayjs; quantas: number; datas: (dayjs.Dayjs | null)[];
  prioridade: string; custo_estimado?: number | null; antecedencia_dias: number; quem_paga: string; debitavel: boolean; responsavel?: string; notas?: string; activo: boolean;
}

/**
 * Novo plano ou edição (imo_plano_editar). Só a manutenção preventiva e a vistoria se planeiam: uma reparação nasce de uma avaria e um apetrechamento de um arrendamento. A periodicidade só
 * propõe as datas; depois ficam escritas uma a uma e corrigem-se à mão — a vistoria de Dezembro que passa para Janeiro não obriga a refazer o plano.
 */
export function ModalPlano({ plano, listas, aoFechar }: { plano: Plano | null | undefined; listas: ListasObras | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<ValoresPlano>();
  const aberto = plano !== undefined;
  const refrescar = useRefrescar();
  const imovel = Form.useWatch('imovel_id', form) as number | undefined;
  const quem = (Form.useWatch('quem_paga', form) as string | undefined) ?? 'SENHORIO';
  const imoveis = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis, enabled: aberto });
  const fracoes = useQuery({ queryKey: CHAVES.fracoes(imovel ?? 0), queryFn: () => listarFracoes(imovel as number), enabled: aberto && !!imovel });
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(plano ? {
      tipo: plano.tipo, nome: plano.nome, descricao: plano.descricao, imovel_id: plano.imovel_id, fracao_id: plano.fracao_id, periodicidade: plano.periodicidade, primeira: plano.datas[0] ? dayjs(plano.datas[0]) : undefined,
      quantas: plano.datas.length || 4, datas: plano.datas.map((d) => dayjs(d)), prioridade: plano.prioridade, custo_estimado: plano.custo_estimado, antecedencia_dias: plano.antecedencia_dias,
      quem_paga: plano.quem_paga, debitavel: plano.debitavel, responsavel: plano.responsavel ?? undefined, notas: plano.notas ?? undefined, activo: plano.activo,
    } : { tipo: 'PREVENTIVA', periodicidade: 'ANUAL', quantas: 4, datas: [], prioridade: 'NORMAL', antecedencia_dias: 30, quem_paga: 'SENHORIO', debitavel: false, activo: true });
  }, [aberto, plano, form]);
  const propor = () => {
    const primeira = form.getFieldValue('primeira') as dayjs.Dayjs | undefined;
    if (!primeira) { message.warning('Indique a primeira data.'); return; }
    const datas = proporDatas(primeira.format('YYYY-MM-DD'), form.getFieldValue('periodicidade'), Number(form.getFieldValue('quantas')) || 1);
    form.setFieldValue('datas', datas.map((d) => dayjs(d)));
    message.info(`${datas.length} data(s) propostas. Corrija-as à vontade antes de gravar.`);
  };
  const gravar = useMutation({
    mutationFn: (v: ValoresPlano) => gravarPlano({
      tipo: v.tipo, nome: v.nome || null, descricao: v.descricao, imovel_id: v.imovel_id, fracao_id: v.fracao_id ?? null, periodicidade: v.periodicidade, quantas: v.quantas,
      datas: (v.datas ?? []).filter((d): d is dayjs.Dayjs => !!d).map((d) => d.format('YYYY-MM-DD')), prioridade: v.prioridade, custo_estimado: v.custo_estimado ?? null,
      antecedencia_dias: v.antecedencia_dias ?? 30, quem_paga: v.quem_paga, debitavel: v.quem_paga === 'SENHORIO' ? false : !!v.debitavel, responsavel: v.responsavel || null, notas: v.notas || null, activo: v.activo }, plano?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar o plano'),
    onSuccess: async () => { message.success('Plano gravado.'); await refrescar(); aoFechar(); },
  });
  const apagar = useMutation({
    mutationFn: () => eliminarPlano((plano as Plano).id), onError: (e) => notificarErro(e, 'Não foi possível eliminar o plano'),
    onSuccess: async (r) => { message.success(`Plano eliminado.${r.dados.ordens_historicas > 0 ? ` As ${r.dados.ordens_historicas} ordem(ns) que gerou ficam no histórico.` : ''}`); await refrescar(); aoFechar(); },
  });
  const op = (m: { chave: string; rotulo: string }[] | undefined) => (m ?? []).map((o) => ({ value: o.chave, label: o.rotulo }));

  return (
    <Modal title={plano ? `Editar o plano «${plano.nome}»` : 'Novo plano de manutenção ou vistoria'} open={aberto} onCancel={aoFechar} width={larguraModal(820)} destroyOnHidden
      footer={[
        ...(plano ? [<Popconfirm key="ap" title="Eliminar o plano? As ordens já geradas ficam — o que se apaga é o calendário. Com ordens por fechar, feche-as ou cancele-as primeiro." okText="Eliminar" cancelText="Manter" okButtonProps={{ danger: true }} onConfirm={() => apagar.mutate()}><Button danger loading={apagar.isPending}>Eliminar</Button></Popconfirm>] : []),
        <Button key="c" onClick={aoFechar}>Cancelar</Button>, <Button key="g" type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>,
      ]}>
      <p>Só a manutenção preventiva e a vistoria se planeiam: uma reparação nasce de uma avaria e um apetrechamento de um arrendamento, e nenhum dos dois tem data marcada de antemão.</p>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Space wrap align="start" size={12}>
          <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select style={{ width: 220 }} options={op(listas?.tipos.filter((t) => listas.tipos_planeaveis.includes(t.chave)))} /></Form.Item>
          <Form.Item name="nome" label="Nome do plano"><Input maxLength={120} placeholder="Vistoria trimestral, Pintura anual…" style={{ width: 280 }} /></Form.Item>
        </Space>
        <Form.Item name="descricao" label="O que se faz em cada data" extra="É esta a descrição que vai para cada ordem gerada." rules={[{ required: true, whitespace: true, message: 'Descreva o que se vai fazer em cada data.' }]}><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
        <Space wrap align="start" size={12}>
          <Form.Item name="imovel_id" label="Imóvel" rules={[{ required: true, message: 'Indique o imóvel ou a fracção do plano.' }]}>
            <Select showSearch optionFilterProp="label" style={{ width: 280 }} loading={imoveis.isLoading} placeholder="— imóvel —" options={(imoveis.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} · ${i.nome}` }))} onChange={() => form.setFieldValue('fracao_id', null)} />
          </Form.Item>
          <Form.Item name="fracao_id" label="Fracção" extra="Em branco, o plano é do imóvel todo."><Select allowClear showSearch optionFilterProp="label" style={{ width: 240 }} disabled={!imovel} loading={fracoes.isFetching} placeholder="— o imóvel todo —" options={(fracoes.data ?? []).map((f) => ({ value: f.id, label: f.designacao }))} /></Form.Item>
        </Space>
        <fieldset className="imo-grupo">
          <legend>Datas</legend>
          <Space wrap align="end" size={12}>
            <Form.Item name="primeira" label="Primeira data"><DatePicker format="DD/MM/YYYY" /></Form.Item>
            <Form.Item name="periodicidade" label="Periodicidade"><Select style={{ width: 200 }} options={op(listas?.periodicidades)} /></Form.Item>
            <Form.Item name="quantas" label="Quantas"><InputNumber min={1} max={60} precision={0} style={{ width: 90 }} /></Form.Item>
            <Form.Item><Button onClick={propor}>Propor datas</Button></Form.Item>
          </Space>
          <Alert type="info" showIcon style={{ marginBottom: 8 }} title="A periodicidade só propõe as datas. Depois ficam escritas uma a uma e corrigem-se à mão." />
          <Form.List name="datas">
            {(campos, { add, remove }) => (
              <>
                <Space wrap size={8}>
                  {campos.map((c) => (
                    <Space.Compact key={c.key}>
                      <Form.Item name={c.name} noStyle><DatePicker format="DD/MM/YYYY" allowClear={false} aria-label={`Data ${c.name + 1} do plano`} /></Form.Item>
                      <Button icon={<DeleteOutlined />} aria-label={`Retirar a data ${c.name + 1}`} onClick={() => remove(c.name)} />
                    </Space.Compact>
                  ))}
                </Space>
                <div style={{ marginTop: 8 }}><Button icon={<PlusOutlined />} onClick={() => add(dayjs())}>Acrescentar data</Button></div>
              </>
            )}
          </Form.List>
        </fieldset>
        <Space wrap align="start" size={12} style={{ marginTop: 12 }}>
          <Form.Item name="prioridade" label="Prioridade"><Select style={{ width: 140 }} options={op(listas?.prioridades)} /></Form.Item>
          <Form.Item name="custo_estimado" label="Custo estimado por ordem (Kz)"><InputNumber min={0} precision={2} style={{ width: 190 }} /></Form.Item>
          <Form.Item name="antecedencia_dias" label="Antecedência (dias)" extra="Com quantos dias se gera a ordem antes da data. 0 é válido: só no próprio dia."><InputNumber min={0} precision={0} style={{ width: 150 }} /></Form.Item>
          <Form.Item name="quem_paga" label="Quem paga"><Select style={{ width: 220 }} options={op(listas?.quem_paga)} /></Form.Item>
          {quem !== 'SENHORIO' && <Form.Item name="debitavel" label="Debitável ao inquilino" valuePropName="checked"><Switch checkedChildren="Sim" unCheckedChildren="Não" /></Form.Item>}
          <Form.Item name="responsavel" label="Responsável"><Input maxLength={120} style={{ width: 220 }} /></Form.Item>
          <Form.Item name="activo" label="Plano activo" valuePropName="checked"><Switch checkedChildren="Activo" unCheckedChildren="Inactivo" /></Form.Item>
        </Space>
        <Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}

/** O calendário do plano: cada data com o estado da ordem ou «em falta» / «por gerar», e a geração das ordens (imo_plano_gerar). Gerar nunca duplica. */
export function ModalCalendario({ plano, podeGerar, aoAbrirOrdem, aoFechar }: { plano: Plano | null; podeGerar: boolean; aoAbrirOrdem: (id: number) => void; aoFechar: () => void }) {
  const refrescar = useRefrescar();
  const q = useQuery({ queryKey: CHAVES_OB.calendario(plano?.id ?? 0), queryFn: () => obterCalendarioPlano((plano as Plano).id), enabled: !!plano });
  const gerar = useMutation({
    mutationFn: (todas: boolean) => gerarOrdensPlano((plano as Plano).id, todas), onError: (e) => notificarErro(e, 'Não foi possível gerar as ordens'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); },
  });
  const c = q.data;
  return (
    <Modal title={plano ? `Calendário — ${plano.nome}` : 'Calendário'} open={!!plano} onCancel={aoFechar} width={larguraModal(680)} destroyOnHidden footer={<Button onClick={aoFechar}>Fechar</Button>}>
      {plano && <p>{plano.descricao} · {plano.periodicidade.toLowerCase()}</p>}
      {c && c.em_falta > 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title={`${c.em_falta} data(s) já passaram sem ordem gerada. Gerá-las agora cria as ordens com a data de pedido na própria data — fica o rasto de que foram recuperadas.`} />}
      {podeGerar && c && (c.por_gerar > 0 || c.em_falta > 0) && (
        <Space wrap style={{ marginBottom: 12 }}>
          <Button type="primary" loading={gerar.isPending} onClick={() => gerar.mutate(false)}>Gerar as próximas</Button>
          <Button loading={gerar.isPending} onClick={() => gerar.mutate(true)}>Gerar todas</Button>
        </Space>
      )}
      <Table<LinhaCalendario> size="small" rowKey="data" loading={q.isFetching} dataSource={c?.linhas ?? []} pagination={false} scroll={{ x: 'max-content' }}
        columns={[
          { title: 'Data', dataIndex: 'data', key: 'data', render: (v: string, l: LinhaCalendario) => <span>{v}{l.passada ? <small style={{ color: '#b91c1c' }}> já passou</small> : null}</span> },
          { title: 'Estado', dataIndex: 'estado', key: 'estado', render: (v: string) => <Tag color={COR_DATA_PLANO[v] ?? 'default'}>{ROTULO_DATA_PLANO[v] ?? v}</Tag> },
          { title: 'Ordem', key: 'ordem', render: (_: unknown, l: LinhaCalendario) => (l.ordem_id ? <Button size="small" type="link" aria-label={`Abrir a ordem ${l.ordem_numero}`} onClick={() => { aoFechar(); aoAbrirOrdem(l.ordem_id as number); }}>{l.ordem_numero}</Button> : '—') },
        ]} />
      {c && <p style={{ marginTop: 12 }}><small>«Gerar as próximas» cria só o que está dentro dos {c.antecedencia_dias} dias de antecedência do plano (até {c.horizonte}), para a lista de ordens não encher de coisas que só acontecem daqui a muito tempo.</small></p>}
    </Modal>
  );
}
