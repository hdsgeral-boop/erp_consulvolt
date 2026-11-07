import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, Button, DatePicker, Form, Input, InputNumber, Modal, Radio, Select, Space, Switch, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, listarFracoes, listarImoveis, listarTerceiros, obterTaxas } from './api';
import {
  abaterNegocio, CHAVES_NEG, concluirNegocio, gravarCustosNegocio, gravarNegocio, listarCategoriasActivo, registarPagamentoNegocio, tratarContratosNegocio, type FichaNegocio, type IsencaoNegocio,
  type ListasNegocios, type Negocio, type TipoNegocio,
} from './apiNegocios';
import { useRefrescar } from './hooks';
import { formatarMoeda, previaImpostos, ROTULO_TIPO, rotuloResultado } from './logicaNegocios';

interface ValoresNegocio {
  tipo: TipoNegocio; terceiro_id?: number; imovel_id?: number | null; fracao_id?: number | null; preco?: number; vpt?: number | null; sinal?: number | null; finalidade: string; isencao: IsencaoNegocio;
  data_promessa?: dayjs.Dayjs | null; data_escritura?: dayjs.Dayjs | null; data_registo?: dayjs.Dayjs | null; notario?: string; notas?: string;
}

const data = (d?: dayjs.Dayjs | null) => (d ? d.format('YYYY-MM-DD') : null);

/**
 * Novo negócio ou edição (imo_neg_editar). Os impostos recalculam-se enquanto não houver escritura e depois ficam fixos; a pré-visualização usa as taxas do ano da escritura (por validar).
 * Depois da escritura o preço, as partes e o imóvel já não mudam (o servidor recusa) e o formulário trava esses campos.
 */
export function ModalNegocio({ negocio, listas, aoFechar }: { negocio: FichaNegocio | Negocio | null | undefined; listas: ListasNegocios | undefined; aoFechar: (gravado?: FichaNegocio) => void }) {
  const [form] = Form.useForm<ValoresNegocio>();
  const aberto = negocio !== undefined;
  const refrescar = useRefrescar();
  const tipo = (Form.useWatch('tipo', form) as TipoNegocio | undefined) ?? 'COMPRA';
  const imovel = Form.useWatch('imovel_id', form) as number | null | undefined;
  const preco = Form.useWatch('preco', form) as number | undefined;
  const vpt = Form.useWatch('vpt', form) as number | null | undefined;
  const isencao = (Form.useWatch('isencao', form) as IsencaoNegocio | undefined) ?? 'NAO';
  const dEscritura = Form.useWatch('data_escritura', form) as dayjs.Dayjs | null | undefined;
  const dPromessa = Form.useWatch('data_promessa', form) as dayjs.Dayjs | null | undefined;
  const ano = (dEscritura ?? dPromessa ?? dayjs()).year();
  const fixo = !!negocio && ['ESCRITURA', 'REGISTADO'].includes(negocio.estado);
  const imoveis = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis, enabled: aberto });
  const fracoes = useQuery({ queryKey: CHAVES.fracoes(imovel ?? 0), queryFn: () => listarFracoes(imovel as number), enabled: aberto && !!imovel });
  const terceiros = useQuery({ queryKey: ['imobiliario', 'terceiros'], queryFn: () => listarTerceiros(), enabled: aberto });
  const taxas = useQuery({ queryKey: CHAVES.taxas(ano), queryFn: () => obterTaxas(ano), enabled: aberto });
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(negocio ? {
      tipo: negocio.tipo, terceiro_id: negocio.terceiro_id, imovel_id: negocio.imovel_id, fracao_id: negocio.fracao_id, preco: negocio.preco, vpt: negocio.vpt, sinal: negocio.sinal, finalidade: negocio.finalidade,
      isencao: negocio.isencao, data_promessa: negocio.data_promessa ? dayjs(negocio.data_promessa) : null, data_escritura: negocio.data_escritura ? dayjs(negocio.data_escritura) : null,
      data_registo: negocio.data_registo ? dayjs(negocio.data_registo) : null, notario: negocio.notario ?? undefined, notas: negocio.notas ?? undefined,
    } : { tipo: 'COMPRA', finalidade: 'HABITACAO', isencao: 'NAO' });
  }, [aberto, negocio, form]);

  const previa = useMemo(() => {
    const t = taxas.data?.taxas;
    if (!t || !preco || preco <= 0) return null;
    return previaImpostos(preco, vpt ?? 0, { ip_transmissao: t.ip_transmissao ?? 2, selo_transmissao: t.selo_transmissao ?? 0.3, escritura_fixo: t.escritura_fixo ?? 2000 }, isencao);
  }, [taxas.data, preco, vpt, isencao]);

  const gravar = useMutation({
    mutationFn: (v: ValoresNegocio) => gravarNegocio({
      tipo: v.tipo, terceiro_id: v.terceiro_id, imovel_id: v.imovel_id ?? null, fracao_id: v.fracao_id ?? null, preco: v.preco, vpt: v.vpt ?? 0, sinal: v.sinal ?? 0, finalidade: v.finalidade, isencao: v.isencao,
      data_promessa: data(v.data_promessa), data_escritura: data(v.data_escritura), data_registo: data(v.data_registo), notario: v.notario || null, notas: v.notas || null }, negocio?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar o negócio'),
    onSuccess: async (r) => { message.success(negocio ? 'Negócio gravado.' : `Negócio ${r.dados.numero} criado em negociação.`); await refrescar(); aoFechar(r.dados); },
  });
  const op = (m: { chave: string; rotulo: string }[] | undefined) => (m ?? []).map((o) => ({ value: o.chave, label: o.rotulo }));
  const parte = tipo === 'COMPRA' ? 'Vendedor' : 'Comprador';

  return (
    <Modal title={negocio ? `Editar o negócio ${negocio.numero}` : 'Novo negócio'} open={aberto} onCancel={() => aoFechar()} width={larguraModal(820)} destroyOnHidden
      footer={[<Button key="c" onClick={() => aoFechar()}>Cancelar</Button>, <Button key="g" type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>]}>
      <p>O número é atribuído na gravação. Os impostos recalculam-se enquanto não houver escritura; depois ficam fixos, porque foram esses que se declararam.</p>
      {fixo && <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Escriturado: só se completam as datas, o notário e as notas. O preço, as partes, o imóvel e a isenção já não mudam." />}
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
          <Radio.Group disabled={!!negocio} optionType="button" options={(listas?.tipos ?? [{ chave: 'COMPRA', rotulo: 'Compra' }, { chave: 'VENDA', rotulo: 'Venda' }]).map((t) => ({ value: t.chave, label: ROTULO_TIPO[t.chave as TipoNegocio] ?? t.rotulo }))} />
        </Form.Item>
        <Space wrap align="start" size={12}>
          <Form.Item name="terceiro_id" label={parte} rules={[{ required: true, message: tipo === 'COMPRA' ? 'Indique o vendedor.' : 'Indique o comprador.' }]}>
            <Select showSearch optionFilterProp="label" style={{ width: 300 }} disabled={fixo} loading={terceiros.isLoading} placeholder="— escolher —" options={(terceiros.data ?? []).map((t) => ({ value: t.id, label: `${t.nome}${t.nif ? ` · ${t.nif}` : ''}` }))} />
          </Form.Item>
          <Form.Item name="imovel_id" label="Imóvel" extra={tipo === 'COMPRA' ? 'Numa compra pode ficar em branco até ter o imóvel no cadastro.' : undefined}>
            <Select allowClear showSearch optionFilterProp="label" style={{ width: 300 }} disabled={fixo} loading={imoveis.isLoading} placeholder="— imóvel —" options={(imoveis.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} · ${i.nome}` }))}
              onChange={() => form.setFieldValue('fracao_id', null)} />
          </Form.Item>
          <Form.Item name="fracao_id" label="Fracção" extra="Em branco, o negócio é do imóvel todo.">
            <Select allowClear showSearch optionFilterProp="label" style={{ width: 220 }} disabled={fixo || !imovel} loading={fracoes.isFetching} placeholder="— o imóvel todo —" options={(fracoes.data ?? []).map((f) => ({ value: f.id, label: f.designacao }))} />
          </Form.Item>
        </Space>
        <Space wrap align="start" size={12}>
          <Form.Item name="preco" label="Preço (Kz)" rules={[{ required: true, type: 'number', min: 0.01, message: 'O preço tem de ser maior do que zero.' }]}><InputNumber min={0} precision={2} style={{ width: 190 }} disabled={fixo} /></Form.Item>
          <Form.Item name="vpt" label="Valor patrimonial (Kz)" extra="Da matriz. É a base do imposto se for maior do que o preço."><InputNumber min={0} precision={2} style={{ width: 190 }} disabled={fixo} /></Form.Item>
          <Form.Item name="sinal" label="Sinal (Kz)" extra="Entregue na promessa."><InputNumber min={0} precision={2} style={{ width: 170 }} disabled={fixo} /></Form.Item>
          <Form.Item name="finalidade" label="Finalidade"><Select style={{ width: 160 }} disabled={fixo} options={op(listas?.finalidades)} /></Form.Item>
          <Form.Item name="isencao" label="Isenção do Imposto Predial" extra="As isenções do OGE são anuais: confirme se vigoram no ano da escritura."><Select style={{ width: 230 }} disabled={fixo} options={op(listas?.isencoes)} /></Form.Item>
        </Space>
        <Space wrap align="start" size={12}>
          <Form.Item name="data_promessa" label="Data da promessa"><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="data_escritura" label="Data da escritura"><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="data_registo" label="Data do registo"><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="notario" label="Cartório / notário" extra="Obrigatório para escriturar."><Input maxLength={150} style={{ width: 280 }} /></Form.Item>
        </Space>
        <div role="status" aria-live="polite" className="imo-aviso" style={{ marginBottom: 12 }}>
          {previa ? (
            <>
              <strong>Impostos da transmissão ({ano}, por validar)</strong>
              <div>Base {formatarMoeda(previa.base)}{previa.baseEVpt ? ' — o valor patrimonial é maior do que o preço' : ''}</div>
              <div>Imposto Predial {formatarMoeda(previa.ip)} · Imposto de Selo {formatarMoeda(previa.selo)} · Escritura {formatarMoeda(previa.escritura)}</div>
              <div><strong>Total, a cargo do adquirente — {tipo === 'COMPRA' ? 'a empresa' : 'o comprador'}: {formatarMoeda(previa.total)}</strong></div>
            </>
          ) : 'Indique o preço para ver os impostos da transmissão.'}
        </div>
        <Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}

interface LinhaCusto { tipo: string; designacao?: string; valor?: number; capitaliza?: boolean; pago?: boolean; data?: dayjs.Dayjs }

/** Encargos do negócio (imo_neg_editar): os capitalizáveis entram no custo de aquisição do bem; os outros são gasto do período. Os impostos da transmissão já estão contados à parte. */
export function ModalCustos({ negocio, listas, aoFechar }: { negocio: FichaNegocio | null; listas: ListasNegocios | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<{ linhas: LinhaCusto[] }>();
  const refrescar = useRefrescar();
  useEffect(() => {
    if (!negocio) return;
    form.resetFields();
    form.setFieldsValue({ linhas: negocio.custos.map((c) => ({ tipo: c.tipo, designacao: c.designacao, valor: c.valor, capitaliza: c.capitaliza, pago: c.pago, data: dayjs(c.data) })) });
  }, [negocio, form]);
  const gravar = useMutation({
    mutationFn: (v: { linhas?: LinhaCusto[] }) => gravarCustosNegocio((negocio as FichaNegocio).id, (v.linhas ?? []).map((l) => ({ tipo: l.tipo, designacao: l.designacao, valor: l.valor, capitaliza: !!l.capitaliza, pago: !!l.pago, data: l.data ? l.data.format('YYYY-MM-DD') : undefined }))),
    onError: (e) => notificarErro(e, 'Não foi possível gravar os encargos'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(); },
  });
  const tipos = listas?.tipos_custo ?? [];

  return (
    <Modal title="Encargos do negócio" open={negocio !== null} onCancel={aoFechar} width={larguraModal(860)} destroyOnHidden
      footer={[<Button key="c" onClick={aoFechar}>Cancelar</Button>, <Button key="g" type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>]}>
      <p>Os capitalizáveis entram no custo de aquisição do bem — e por isso mudam a mais-valia de uma venda futura. Os outros são gasto do período. Os impostos da transmissão já estão contados à parte: não os repita aqui.</p>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.List name="linhas">
          {(campos, { add, remove }) => (
            <>
              {campos.map((c) => (
                <Space key={c.key} wrap align="start" size={8} style={{ display: 'flex', marginBottom: 4 }}>
                  <Form.Item name={[c.name, 'tipo']} label={c.name === 0 ? 'Tipo' : undefined} rules={[{ required: true }]}>
                    <Select aria-label="Tipo de encargo" style={{ width: 230 }} options={tipos.map((t) => ({ value: t.chave, label: t.rotulo }))}
                      onChange={(v) => form.setFieldValue(['linhas', c.name, 'capitaliza'], tipos.find((t) => t.chave === v)?.capitaliza ?? false)} />
                  </Form.Item>
                  <Form.Item name={[c.name, 'designacao']} label={c.name === 0 ? 'Descrição' : undefined} rules={[{ required: true, whitespace: true, message: 'Indique a descrição.' }]}><Input aria-label="Descrição do encargo" maxLength={200} style={{ width: 220 }} /></Form.Item>
                  <Form.Item name={[c.name, 'valor']} label={c.name === 0 ? 'Valor (Kz)' : undefined} rules={[{ required: true, type: 'number', min: 0.01, message: 'Indique o valor.' }]}><InputNumber aria-label="Valor do encargo" min={0} precision={2} style={{ width: 150 }} /></Form.Item>
                  <Form.Item name={[c.name, 'data']} label={c.name === 0 ? 'Data' : undefined}><DatePicker aria-label="Data do encargo" format="DD/MM/YYYY" /></Form.Item>
                  <Form.Item name={[c.name, 'capitaliza']} label={c.name === 0 ? 'Capitaliza' : undefined} valuePropName="checked"><Switch aria-label="Entra no custo do bem" checkedChildren="Sim" unCheckedChildren="Não" /></Form.Item>
                  <Form.Item name={[c.name, 'pago']} label={c.name === 0 ? 'Já pago' : undefined} valuePropName="checked"><Switch aria-label="Já pago" checkedChildren="Sim" unCheckedChildren="Não" /></Form.Item>
                  <Button aria-label="Remover o encargo" icon={<DeleteOutlined />} danger onClick={() => remove(c.name)} style={{ marginTop: c.name === 0 ? 30 : 0 }} />
                </Space>
              ))}
              <Button icon={<PlusOutlined />} onClick={() => add({ tipo: 'MEDIACAO', capitaliza: true, pago: false, data: dayjs() })}>Acrescentar</Button>
            </>
          )}
        </Form.List>
      </Form>
    </Modal>
  );
}

/** Pagamento do negócio (imo_neg_pagamento): sinal, prestação ou saldo. A chave torna o pedido repetível sem duplicar. */
export function ModalPagamento({ negocio, listas, aoFechar }: { negocio: FichaNegocio | null; listas: ListasNegocios | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<{ tipo: string; valor?: number; data: dayjs.Dayjs; meio?: string; referencia?: string }>();
  const refrescar = useRefrescar();
  const chave = useMemo(() => `pag-${negocio?.id ?? 0}-${Date.now()}`, [negocio]);   // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (negocio) { form.resetFields(); form.setFieldsValue({ tipo: negocio.sinal > 0 && negocio.pago_total === 0 ? 'SINAL' : 'PRESTACAO', data: dayjs() }); } }, [negocio, form]);
  const gravar = useMutation({
    mutationFn: (v: { tipo: string; valor?: number; data: dayjs.Dayjs; meio?: string; referencia?: string }) => registarPagamentoNegocio((negocio as FichaNegocio).id, { tipo: v.tipo, valor: v.valor, data: v.data.format('YYYY-MM-DD'),
      meio: v.meio || null, referencia: v.referencia || null, chave }),
    onError: (e) => notificarErro(e, 'Não foi possível registar o pagamento'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoFechar(); },
  });
  const compra = negocio?.tipo === 'COMPRA';

  return (
    <Modal title={compra ? 'Registar pagamento ao vendedor' : 'Registar recebimento do comprador'} open={negocio !== null} onCancel={aoFechar} destroyOnHidden
      footer={[<Button key="c" onClick={aoFechar}>Cancelar</Button>, <Button key="g" type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Registar</Button>]}>
      {negocio && <p>Preço {formatarMoeda(negocio.preco)} · {compra ? 'pago' : 'recebido'} {formatarMoeda(negocio.pago_total)} · falta {formatarMoeda(negocio.por_liquidar)}.</p>}
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select options={(listas?.tipos_pagamento ?? []).map((t) => ({ value: t.chave, label: t.rotulo }))} /></Form.Item>
        <Form.Item name="valor" label="Valor (Kz)" rules={[{ required: true, type: 'number', min: 0.01, message: 'Indique o valor do pagamento.' }]}><InputNumber min={0} precision={2} style={{ width: 220 }} /></Form.Item>
        <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" allowClear={false} /></Form.Item>
        <Form.Item name="meio" label="Meio (transferência, cheque…)"><Input maxLength={20} /></Form.Item>
        <Form.Item name="referencia" label="Referência"><Input maxLength={120} /></Form.Item>
      </Form>
    </Modal>
  );
}

/** O que acontece aos contratos em vigor da fracção vendida: transmitir (termina-os aqui, em cascata, com o motivo registado) ou manter. */
export function ModalContratosVenda({ negocio, listas, aoFechar }: { negocio: FichaNegocio | null; listas: ListasNegocios | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<{ destino: string }>();
  const refrescar = useRefrescar();
  useEffect(() => { if (negocio) { form.resetFields(); form.setFieldsValue({ destino: 'TRANSMITE' }); } }, [negocio, form]);
  const confirmar = useMutation({
    mutationFn: (v: { destino: string }) => tratarContratosNegocio((negocio as FichaNegocio).id, v.destino),
    onError: (e) => notificarErro(e, 'Não foi possível tratar os contratos'),
    onSuccess: async (r) => { message.success(r.dados.terminados ? `${r.dados.terminados} contrato(s) transmitido(s) e terminado(s) nesta empresa.` : 'Os contratos mantêm-se. A venda fica registada como não abrangendo o arrendamento.'); await refrescar(); aoFechar(); },
  });

  return (
    <Modal title="Contratos sobre o imóvel vendido" open={negocio !== null} onCancel={aoFechar} okText="Confirmar" cancelText="Cancelar" confirmLoading={confirmar.isPending} onOk={() => form.submit()} destroyOnHidden>
      <p>A venda não faz cessar o arrendamento: a posição de senhorio passa para o comprador. O que muda é que a empresa deixa de os gerir e as rendas futuras deixam de ser dela.</p>
      <ul>{(negocio?.contratos_em_vigor ?? []).map((c) => <li key={c.id}>{c.numero} — até {c.fim ?? 'sem termo'}, renda {formatarMoeda(c.renda)}</li>)}</ul>
      <Form form={form} layout="vertical" onFinish={(v) => confirmar.mutate(v)}>
        <Form.Item name="destino" label="O que lhes acontece" rules={[{ required: true }]} extra="Transmitir termina-os aqui na data da escritura, com o motivo registado — não os apaga. Manter deixa-os vivos e impede de concluir a venda enquanto estiverem em vigor.">
          <Select options={(listas?.destinos_contratos ?? []).map((d) => ({ value: d.chave, label: d.rotulo }))} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Concluir o negócio. Compra: leva o custo ao imobilizado (categoria do bem se o imóvel ainda não tem bem). Venda: factura em Vendas e marca as fracções vendidas; o abate é outro passo. */
export function ModalConcluir({ negocio, aoFechar }: { negocio: FichaNegocio | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ categoria_ativo_id?: number }>();
  const refrescar = useRefrescar();
  const compra = negocio?.tipo === 'COMPRA';
  const categorias = useQuery({ queryKey: CHAVES_NEG.categorias, queryFn: listarCategoriasActivo, enabled: !!negocio && compra });
  useEffect(() => { if (negocio) form.resetFields(); }, [negocio, form]);
  const concluir = useMutation({
    mutationFn: (v: { categoria_ativo_id?: number }) => concluirNegocio((negocio as FichaNegocio).id, { categoria_ativo_id: v.categoria_ativo_id ?? null }),
    onError: (e) => notificarErro(e, 'Não foi possível concluir o negócio'),
    onSuccess: async (r) => { message.success(r.mensagem, 6); (r.dados.avisos ?? []).forEach((a) => message.warning(a, 10)); await refrescar(); aoFechar(); },
  });
  const rv = negocio?.resultado_venda;

  return (
    <Modal title={compra ? 'Concluir a compra' : 'Concluir a venda'} open={negocio !== null} onCancel={aoFechar} okText="Concluir" cancelText="Voltar" confirmLoading={concluir.isPending} onOk={() => form.submit()} destroyOnHidden>
      {negocio && compra && (
        <>
          <p>O imóvel passa a próprio e fica com o custo de aquisição de {formatarMoeda(negocio.custo_aquisicao_calculado?.total)} (preço {formatarMoeda(negocio.preco)} mais {formatarMoeda(Number(negocio.custo_aquisicao_calculado?.total ?? 0) - negocio.preco)} de impostos e encargos).
            Se o imóvel ainda não tem bem no imobilizado, nasce um e a aquisição é lançada.</p>
          <Form form={form} layout="vertical" onFinish={(v) => concluir.mutate(v)}>
            <Form.Item name="categoria_ativo_id" label="Categoria do bem no imobilizado" extra="Dá a conta do imobilizado e as amortizações. Só é necessária se o imóvel ainda não tem bem.">
              <Select allowClear showSearch optionFilterProp="label" loading={categorias.isLoading} placeholder="— categoria —" options={(categorias.data ?? []).map((c) => ({ value: c.id, label: `${c.nome}${c.conta_ativo ? ` (${c.conta_ativo})` : ''}` }))} />
            </Form.Item>
          </Form>
        </>
      )}
      {negocio && !compra && (
        <Form form={form} onFinish={(v) => concluir.mutate(v)}>
          {rv?.conhecido ? (
            <p>Apura-se uma {rotuloResultado(rv.resultado_contabilistico).toLowerCase()} de {formatarMoeda(rv.resultado_contabilistico)} (preço {formatarMoeda(negocio.preco)} menos o valor contabilístico {formatarMoeda(rv.valor_liquido)}).
              A factura sai sem IVA (a transmissão de imóveis é isenta) e a fracção passa a vendida. O bem só é abatido no passo seguinte, por outra pessoa.</p>
          ) : <Alert type="warning" showIcon title={`${rv?.motivo ?? ''} A venda conclui-se à mesma, mas sem resultado apurado nem abate.`} />}
        </Form>
      )}
    </Modal>
  );
}

/** Abate do bem vendido: regista o abate em Activos (só na última quota) e lança a alienação com a mais ou menos-valia. Outra pessoa que não quem concluiu a venda. */
export function ModalAbater({ negocio, aoFechar }: { negocio: FichaNegocio | null; aoFechar: () => void }) {
  const refrescar = useRefrescar();
  const abater = useMutation({
    mutationFn: () => abaterNegocio((negocio as FichaNegocio).id), onError: (e) => notificarErro(e, 'Não foi possível abater o bem'),
    onSuccess: async (r) => { message.success(`${r.mensagem}${r.dados.lancamento ? ` Lançamento ${r.dados.lancamento}.` : ''}`, 8); r.dados.avisos.forEach((a) => message.info(a, 10)); await refrescar(); aoFechar(); },
  });
  const rv = negocio?.resultado_venda;

  return (
    <Modal title="Abater o bem vendido" open={negocio !== null} onCancel={aoFechar} okText="Abater e lançar" cancelText="Voltar" confirmLoading={abater.isPending} onOk={() => abater.mutate()} destroyOnHidden>
      {rv?.conhecido && negocio && (
        <>
          <p>Quota vendida: {(Number(rv.quota) * 100).toLocaleString('pt-PT', { maximumFractionDigits: 1 })} % ({rv.criterio_quota}){rv.parcial ? ' — venda parcial: o bem continua no imobilizado até se vender o resto' : ''}.</p>
          <ul>
            <li>Custo da quota: {formatarMoeda(rv.custo)} · amortizações: {formatarMoeda(rv.amortizacoes)} · valor líquido: {formatarMoeda(rv.valor_liquido)}</li>
            <li>{rotuloResultado(rv.resultado_contabilistico)}: <strong>{formatarMoeda(rv.resultado_contabilistico)}</strong> (preço {formatarMoeda(negocio.preco)} menos o valor líquido)</li>
          </ul>
          <p>O lançamento é equilibrado e usa as contas da mais e da menos-valia da configuração. Repetir este passo não lança duas vezes.</p>
        </>
      )}
    </Modal>
  );
}
