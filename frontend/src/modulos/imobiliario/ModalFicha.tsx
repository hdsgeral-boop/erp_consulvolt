import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Switch, message } from 'antd';
import { useMutation } from '@tanstack/react-query';
import { useEffect, useMemo } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import type { Listas } from './api';
import { apagarFicha, gravarFicha, type Ficha, type ItemFicha } from './apiObras';
import { useRefrescar } from './hooks';
import { SeletorArtigo } from './SeletorArtigo';

interface Valores { nome: string; tipologia?: string | null; finalidade?: string | null; activo: boolean; notas?: string | null; itens: ItemFicha[] }

const LINHA_VAZIA: ItemFicha = { produto_id: null, descricao: null, quantidade: 1, obrigatorio: true };

/**
 * Criar e editar uma ficha-tipo de apetrechamento (imo_ficha_editar): o que cada género de fracção deve ter. O artigo do armazém é o que permite saber se há stock; sem artigo,
 * escreve-se a descrição. A ficha grava-se com os artigos numa só operação, o nome é único e o mesmo artigo não entra duas vezes.
 */
export function ModalFicha({ ficha, listas, aoFechar }: { ficha: Ficha | null | undefined; listas: Listas | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<Valores>();
  const aberto = ficha !== undefined;
  const refrescar = useRefrescar();
  const nomes = useMemo(() => Object.fromEntries((ficha?.itens ?? []).filter((i) => i.produto_id).map((i) => [i.produto_id as number, i.nome ?? ''])), [ficha]);
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(ficha ? { nome: ficha.nome, tipologia: ficha.tipologia, finalidade: ficha.finalidade, activo: ficha.activo, notas: ficha.notas, itens: ficha.itens.length ? ficha.itens : [LINHA_VAZIA] }
      : { nome: '', activo: true, itens: [LINHA_VAZIA] });
  }, [aberto, ficha, form]);
  const gravar = useMutation({
    mutationFn: (v: Valores) => gravarFicha({ nome: v.nome, tipologia: v.tipologia || null, finalidade: v.finalidade || null, activo: v.activo, notas: v.notas || null,
      itens: (v.itens ?? []).map((i) => ({ produto_id: i.produto_id || null, descricao: i.produto_id ? null : (i.descricao || null), quantidade: Math.max(1, Math.round(Number(i.quantidade) || 1)), obrigatorio: i.obrigatorio !== false })) }, ficha?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar a ficha'),
    onSuccess: async (r) => { message.success(`Ficha gravada com ${r.dados.n_itens} artigo(s).`); await refrescar(); aoFechar(); },
  });
  const apagar = useMutation({
    mutationFn: () => apagarFicha((ficha as Ficha).id), onError: (e) => notificarErro(e, 'Não foi possível apagar a ficha'),
    onSuccess: async () => { message.success('Ficha apagada.'); await refrescar(); aoFechar(); },
  });
  const op = (m: { valor: string; rotulo: string }[] | undefined) => (m ?? []).map((o) => ({ value: o.valor, label: o.rotulo }));

  return (
    <Modal title={ficha ? `Editar a ficha «${ficha.nome}»` : 'Nova ficha de apetrechamento'} open={aberto} onCancel={aoFechar} width={larguraModal(860)} destroyOnHidden
      footer={[
        ...(ficha ? [<Popconfirm key="ap" title="Apagar esta ficha e os seus artigos? Se já foi usada numa ordem, ponha-a inactiva em vez de a apagar." okText="Apagar" cancelText="Manter" okButtonProps={{ danger: true }} onConfirm={() => apagar.mutate()}><Button danger loading={apagar.isPending}>Apagar</Button></Popconfirm>] : []),
        <Button key="c" onClick={aoFechar}>Cancelar</Button>,
        <Button key="g" type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>,
      ]}>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Space wrap align="start" size={12}>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, whitespace: true, message: 'Dê um nome à ficha de apetrechamento.' }]}><Input maxLength={120} placeholder="Ex.: T2 mobilado" style={{ width: 260 }} /></Form.Item>
          <Form.Item name="tipologia" label="Tipologia"><Select allowClear style={{ width: 200 }} placeholder="— qualquer —" options={op(listas?.tipologias)} /></Form.Item>
          <Form.Item name="finalidade" label="Finalidade"><Select allowClear style={{ width: 200 }} placeholder="— qualquer —" options={op(listas?.finalidades)} /></Form.Item>
          <Form.Item name="activo" label="Activa" valuePropName="checked"><Switch checkedChildren="Activa" unCheckedChildren="Inactiva" /></Form.Item>
        </Space>
        <fieldset className="imo-grupo">
          <legend>O que deve ter</legend>
          <Alert type="info" showIcon style={{ marginBottom: 8 }} title="Escolha o artigo do armazém sempre que exista — é o que permite saber se há stock. Sem artigo, escreva a descrição." />
          <Form.List name="itens">
            {(campos, { add, remove }) => (
              <>
                {campos.map((c) => (
                  <Space key={c.key} wrap align="start" size={8} className="imo-linha-item">
                    <Form.Item name={[c.name, 'produto_id']} label={c.name === 0 ? 'Artigo' : undefined}>
                      <SeletorArtigo id={`imo-ficha-artigo-${c.name}`} nomeConhecido={nomes[form.getFieldValue(['itens', c.name, 'produto_id'])]} />
                    </Form.Item>
                    <Form.Item name={[c.name, 'descricao']} label={c.name === 0 ? 'Descrição (sem artigo)' : undefined}><Input maxLength={200} placeholder="descrição (sem artigo)" style={{ width: 220 }} aria-label={`Descrição da linha ${c.name + 1}`} /></Form.Item>
                    <Form.Item name={[c.name, 'quantidade']} label={c.name === 0 ? 'Quantidade' : undefined}><InputNumber min={1} precision={0} style={{ width: 100 }} aria-label={`Quantidade da linha ${c.name + 1}`} /></Form.Item>
                    <Form.Item name={[c.name, 'obrigatorio']} label={c.name === 0 ? 'Obrigatório' : undefined} valuePropName="checked"><Switch checkedChildren="Obrigatório" unCheckedChildren="Opcional" aria-label={`Obrigatório na linha ${c.name + 1}`} /></Form.Item>
                    <Form.Item label={c.name === 0 ? ' ' : undefined}><Button icon={<DeleteOutlined />} aria-label={`Retirar a linha ${c.name + 1}`} onClick={() => remove(c.name)} /></Form.Item>
                  </Space>
                ))}
                <Button icon={<PlusOutlined />} onClick={() => add({ ...LINHA_VAZIA })}>Artigo</Button>
              </>
            )}
          </Form.List>
        </fieldset>
        <Form.Item name="notas" label="Notas" style={{ marginTop: 12 }}><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}
