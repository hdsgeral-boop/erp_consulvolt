import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, listarFracoes, listarImoveis } from './api';
import {
  CHAVES_OB, gravarNecessidades, gravarOrdem, listarArmazens, obterPlanoPedido, pedirMaterial, type FichaOrdem, type ListasObras, type ResultadoPedido,
} from './apiObras';
import { useRefrescar } from './hooks';
import { formatarQuantidade } from './logicaObras';
import { SeletorArtigo } from './SeletorArtigo';

interface ValoresOrdem {
  tipo: string; prioridade: string; imovel_id?: number; fracao_id?: number | null; descricao: string; quem_paga: string; debitavel: boolean; custo_estimado?: number | null;
  data_pedido?: dayjs.Dayjs; data_prevista?: dayjs.Dayjs | null; responsavel?: string; pedido_por?: string; notas?: string;
}

/** Nova ordem ou edição (imo_obra_editar). O imóvel deriva da fracção; «quem paga» senhorio nunca é debitável; alterar o custo ou quem paga retira a aprovação. */
export function ModalOrdem({ ordem, listas, imovelInicial, fracaoInicial, aoFechar }: {
  ordem: FichaOrdem | null | undefined; listas: ListasObras | undefined; imovelInicial?: number | null; fracaoInicial?: number | null; aoFechar: (gravada?: FichaOrdem) => void;
}) {
  const [form] = Form.useForm<ValoresOrdem>();
  const aberto = ordem !== undefined;
  const refrescar = useRefrescar();
  const imovel = Form.useWatch('imovel_id', form) as number | undefined;
  const quem = (Form.useWatch('quem_paga', form) as string | undefined) ?? 'SENHORIO';
  const imoveis = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis, enabled: aberto });
  const fracoes = useQuery({ queryKey: CHAVES.fracoes(imovel ?? 0), queryFn: () => listarFracoes(imovel as number), enabled: aberto && !!imovel });
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(ordem ? {
      tipo: ordem.tipo, prioridade: ordem.prioridade, imovel_id: ordem.imovel_id, fracao_id: ordem.fracao_id, descricao: ordem.descricao, quem_paga: ordem.quem_paga, debitavel: ordem.debitavel,
      custo_estimado: ordem.custo_estimado, data_pedido: dayjs(ordem.data_pedido), data_prevista: ordem.data_prevista ? dayjs(ordem.data_prevista) : null,
      responsavel: ordem.responsavel ?? undefined, pedido_por: ordem.pedido_por ?? undefined, notas: ordem.notas ?? undefined,
    } : { tipo: 'CORRECTIVA', prioridade: 'NORMAL', quem_paga: 'SENHORIO', debitavel: false, data_pedido: dayjs(), imovel_id: imovelInicial ?? undefined, fracao_id: fracaoInicial ?? undefined });
  }, [aberto, ordem, imovelInicial, fracaoInicial, form]);
  const gravar = useMutation({
    mutationFn: (v: ValoresOrdem) => gravarOrdem({
      tipo: v.tipo, prioridade: v.prioridade, imovel_id: v.imovel_id, fracao_id: v.fracao_id ?? null, descricao: v.descricao, quem_paga: v.quem_paga, debitavel: v.quem_paga === 'SENHORIO' ? false : !!v.debitavel,
      custo_estimado: v.custo_estimado ?? null, data_pedido: v.data_pedido?.format('YYYY-MM-DD'), data_prevista: v.data_prevista ? v.data_prevista.format('YYYY-MM-DD') : null,
      responsavel: v.responsavel || null, pedido_por: v.pedido_por || null, notas: v.notas || null }, ordem?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar a ordem'),
    onSuccess: async (r) => { message.success(ordem ? 'Ordem gravada.' : `Ordem ${r.dados.numero} aberta.`); await refrescar(); aoFechar(r.dados); },
  });
  const op = (m: { chave: string; rotulo: string; ajuda?: string }[] | undefined) => (m ?? []).map((o) => ({ value: o.chave, label: o.rotulo }));

  return (
    <Modal title={ordem ? `Editar a ordem ${ordem.numero}` : 'Nova ordem de trabalho'} open={aberto} onCancel={() => aoFechar()} width={larguraModal(760)} destroyOnHidden
      footer={[<Button key="c" onClick={() => aoFechar()}>Cancelar</Button>, <Button key="g" type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>]}>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Space wrap align="start" size={12}>
          <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select style={{ width: 220 }} options={op(listas?.tipos)} /></Form.Item>
          <Form.Item name="prioridade" label="Prioridade"><Select style={{ width: 140 }} options={op(listas?.prioridades)} /></Form.Item>
          <Form.Item name="imovel_id" label="Imóvel" rules={[{ required: true, message: 'Indique o imóvel ou a fracção a que a ordem pertence.' }]}>
            <Select showSearch optionFilterProp="label" style={{ width: 280 }} loading={imoveis.isLoading} placeholder="— imóvel —" options={(imoveis.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} · ${i.nome}` }))}
              onChange={() => form.setFieldValue('fracao_id', null)} />
          </Form.Item>
          <Form.Item name="fracao_id" label="Fracção" extra="Vazio = o trabalho é do imóvel todo (ex.: telhado, fachada).">
            <Select allowClear showSearch optionFilterProp="label" style={{ width: 240 }} disabled={!imovel} loading={fracoes.isFetching} placeholder="— o imóvel todo —" options={(fracoes.data ?? []).map((f) => ({ value: f.id, label: f.designacao }))} />
          </Form.Item>
        </Space>
        <Form.Item name="descricao" label="Descrição" rules={[{ required: true, whitespace: true, message: 'Descreva o trabalho.' }]}><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
        <Space wrap align="start" size={12}>
          <Form.Item name="quem_paga" label="Quem paga" extra="Vem da cláusula do contrato."><Select style={{ width: 220 }} options={op(listas?.quem_paga)} /></Form.Item>
          {quem !== 'SENHORIO' && <Form.Item name="debitavel" label="Debitar ao inquilino" valuePropName="checked" extra="Entra como encargo na renda quando a obra estiver concluída e aprovada."><Switch checkedChildren="Sim" unCheckedChildren="Não" /></Form.Item>}
          <Form.Item name="custo_estimado" label="Custo estimado (Kz)"><InputNumber min={0} precision={2} style={{ width: 170 }} /></Form.Item>
          <Form.Item name="data_pedido" label="Data do pedido"><DatePicker format="DD/MM/YYYY" allowClear={false} /></Form.Item>
          <Form.Item name="data_prevista" label="Data prevista"><DatePicker format="DD/MM/YYYY" /></Form.Item>
        </Space>
        <Space wrap align="start" size={12}>
          <Form.Item name="responsavel" label="Responsável"><Input maxLength={120} style={{ width: 240 }} /></Form.Item>
          <Form.Item name="pedido_por" label="Pedido por"><Input maxLength={120} placeholder="inquilino, porteiro, vistoria…" style={{ width: 280 }} /></Form.Item>
        </Space>
        <Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}

interface LinhaNec { produto_id?: number | null; descricao?: string | null; quantidade?: number | null; nome?: string }

/** O que é preciso para fazer o trabalho (imo_obra_editar). As linhas já pedidas ou recebidas não se mexem aqui; o mesmo artigo não entra duas vezes. */
export function ModalNecessidades({ ordem, aoFechar }: { ordem: FichaOrdem | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ linhas: LinhaNec[] }>();
  const refrescar = useRefrescar();
  const jaPedidas = (ordem?.necessidades_lista ?? []).filter((n) => n.estado !== 'POR_PEDIR');
  const porPedir = (ordem?.necessidades_lista ?? []).filter((n) => n.estado === 'POR_PEDIR');
  const nomes = useMemo(() => Object.fromEntries(porPedir.filter((n) => n.produto_id).map((n) => [n.produto_id as number, n.nome])), [ordem]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!ordem) return;
    form.resetFields();
    form.setFieldsValue({ linhas: porPedir.length ? porPedir.map((n) => ({ produto_id: n.produto_id, descricao: n.descricao, quantidade: Number(n.quantidade) })) : [{ produto_id: null, descricao: null, quantidade: 1 }] });
  }, [ordem]); // eslint-disable-line react-hooks/exhaustive-deps
  const gravar = useMutation({
    mutationFn: (v: { linhas: LinhaNec[] }) => gravarNecessidades((ordem as FichaOrdem).id, (v.linhas ?? []).map((l) => ({ produto_id: l.produto_id || null, descricao: l.produto_id ? null : (l.descricao || null), quantidade: Number(l.quantidade) || 1 }))),
    onError: (e) => notificarErro(e, 'Não foi possível gravar as necessidades'),
    onSuccess: async () => { message.success('Necessidades gravadas.'); await refrescar(); aoFechar(); },
  });

  return (
    <Modal title="Necessidades da ordem" open={!!ordem} onCancel={aoFechar} width={larguraModal(820)} destroyOnHidden
      footer={[<Button key="c" onClick={aoFechar}>Cancelar</Button>, <Button key="g" type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>]}>
      <p>O que é preciso para fazer o trabalho. O que já foi pedido ao armazém ou a compras não se mexe aqui.</p>
      {jaPedidas.length > 0 && <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Já pedido" description={jaPedidas.map((n) => `${formatarQuantidade(n.quantidade)} × ${n.nome}`).join(' · ')} />}
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.List name="linhas">
          {(campos, { add, remove }) => (
            <>
              {campos.map((c) => (
                <Space key={c.key} wrap align="start" size={8}>
                  <Form.Item name={[c.name, 'produto_id']} label={c.name === 0 ? 'Artigo' : undefined}><SeletorArtigo id={`imo-nec-artigo-${c.name}`} nomeConhecido={nomes[form.getFieldValue(['linhas', c.name, 'produto_id'])]} /></Form.Item>
                  <Form.Item name={[c.name, 'descricao']} label={c.name === 0 ? 'Descrição (sem artigo)' : undefined}><Input maxLength={200} style={{ width: 220 }} aria-label={`Descrição da linha ${c.name + 1}`} /></Form.Item>
                  <Form.Item name={[c.name, 'quantidade']} label={c.name === 0 ? 'Quantidade' : undefined}><InputNumber min={0.001} step={0.5} precision={3} style={{ width: 120 }} aria-label={`Quantidade da linha ${c.name + 1}`} /></Form.Item>
                  <Form.Item label={c.name === 0 ? ' ' : undefined}><Button icon={<DeleteOutlined />} aria-label={`Retirar a linha ${c.name + 1}`} onClick={() => remove(c.name)} /></Form.Item>
                </Space>
              ))}
              <Button icon={<PlusOutlined />} onClick={() => add({ produto_id: null, descricao: null, quantidade: 1 })}>Linha</Button>
            </>
          )}
        </Form.List>
      </Form>
    </Modal>
  );
}

/** Pedir o material (imo_obra_pedir): o que há em stock sai por requisição ao armazém (a de Manutenção de Activos), o resto segue como pedido de compra e passa pela deliberação por escalões. */
export function ModalPedirMaterial({ ordem, aoFechar }: { ordem: FichaOrdem | null; aoFechar: (feito?: ResultadoPedido) => void }) {
  const refrescar = useRefrescar();
  const [armazem, setArmazem] = useState<number | null>(null);
  const [requerente, setRequerente] = useState('');
  const armazens = useQuery({ queryKey: CHAVES_OB.armazens, queryFn: listarArmazens, enabled: !!ordem });
  useEffect(() => { if (ordem) { setRequerente(''); setArmazem(null); } }, [ordem]);
  useEffect(() => { if (!armazem && armazens.data?.length) setArmazem(armazens.data[0].id); }, [armazens.data, armazem]);
  const plano = useQuery({ queryKey: CHAVES_OB.planoPedido(ordem?.id ?? 0, armazem), queryFn: () => obterPlanoPedido((ordem as FichaOrdem).id, armazem), enabled: !!ordem && (armazem !== null || armazens.isSuccess) });
  const linhas = plano.data ?? [];
  const pedivel = linhas.filter((l) => !l.bloqueada);
  const nArm = pedivel.filter((l) => Number(l.para_armazem) > 0).length;
  const nCompra = pedivel.filter((l) => Number(l.para_compra) > 0).length;
  const pedir = useMutation({
    mutationFn: () => pedirMaterial((ordem as FichaOrdem).id, { armazem_id: armazem, requerente: requerente.trim() || undefined }),
    onError: (e) => notificarErro(e, 'Não foi possível pedir o material'),
    onSuccess: async (r) => {
      const d = r.dados;
      message.success(`Material pedido: ${[d.requisicao_numero ? `requisição ${d.requisicao_numero} (${d.armazem} linha(s))` : null, d.pedido_compra_numero ? `pedido de compra ${d.pedido_compra_numero} (${d.compra} linha(s)) — segue para deliberação` : null].filter(Boolean).join(' e ')}.`, 8);
      await refrescar();
      aoFechar(d);
    },
  });

  return (
    <Modal title="Pedir material" open={!!ordem} onCancel={() => aoFechar()} width={larguraModal(820)} destroyOnHidden okText="Pedir" cancelText="Cancelar" onOk={() => pedir.mutate()}
      okButtonProps={{ disabled: pedivel.length === 0 }} confirmLoading={pedir.isPending}>
      <p>O que há em stock sai por requisição ao armazém; o resto segue como pedido de compra e passa pela deliberação por escalões.</p>
      <Space wrap style={{ marginBottom: 12 }}>
        {(armazens.data ?? []).length > 0 ? (
          <>
            <label htmlFor="imo-pedir-armazem">Armazém</label>
            <Select id="imo-pedir-armazem" style={{ minWidth: 220 }} value={armazem ?? undefined} options={(armazens.data ?? []).map((a) => ({ value: a.id, label: a.nome }))} onChange={setArmazem} />
          </>
        ) : <Tag>Sem armazéns configurados — tudo segue para compras.</Tag>}
        <label htmlFor="imo-pedir-requerente">Requerente</label>
        <Input id="imo-pedir-requerente" style={{ width: 220 }} maxLength={100} value={requerente} placeholder="o seu nome, por omissão" onChange={(e) => setRequerente(e.target.value)} />
      </Space>
      <Table size="small" rowKey="necessidade_id" loading={plano.isFetching} dataSource={linhas} pagination={false} scroll={{ x: 'max-content' }}
        locale={{ emptyText: 'Não há necessidades por pedir nesta ordem.' }}
        columns={[
          { title: 'Artigo', dataIndex: 'nome', key: 'nome' },
          { title: 'Precisa', dataIndex: 'quantidade', key: 'q', align: 'right' as const, render: (v: string) => formatarQuantidade(v) },
          { title: 'Stock', dataIndex: 'stock', key: 's', align: 'right' as const, render: (v: string | null) => (v === null ? '—' : formatarQuantidade(v)) },
          { title: 'Armazém', dataIndex: 'para_armazem', key: 'a', align: 'right' as const, render: (v: string) => <span style={{ color: '#15803d' }}>{formatarQuantidade(v)}</span> },
          { title: 'Compras', dataIndex: 'para_compra', key: 'c', align: 'right' as const, render: (v: string) => <span style={{ color: '#1d4ed8' }}>{formatarQuantidade(v)}</span> },
          { title: 'Porquê', dataIndex: 'porque', key: 'p' },
        ]} />
      {linhas.some((l) => l.bloqueada) && <Alert type="warning" showIcon style={{ marginTop: 12 }} title="Há linhas sem artigo: ficam por pedir até lhes indicar um artigo (um serviço, por exemplo), porque o pedido de compra exige artigo." />}
      <p style={{ marginTop: 12 }}>{nArm} linha(s) ao armazém · {nCompra} linha(s) a compras.</p>
    </Modal>
  );
}
