import { Alert, Form, InputNumber, Modal, Select, Space, Typography } from 'antd';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal } from '@/componentes/responsivo';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import type { ProdutoFicha } from './formularioProduto';

/** Campos de conta da edição em massa (mapeamento_massa.js do legado: rótulo e classe sugerida). */
export const CONTAS_MASSA = [
  { campo: 'codigo_conta', rotulo: 'Proveito (vendas 61 / serviços 62)', prefixo: '6', chave: 'venda' },
  { campo: 'conta_custo', rotulo: 'Custo (71 / 75)', prefixo: '7', chave: 'custo' },
  { campo: 'conta_compra', rotulo: 'Compra (21)', prefixo: '2', chave: 'compra' },
  { campo: 'conta_inventario', rotulo: 'Existências (26)', prefixo: '2', chave: 'inventario' },
  { campo: 'conta_iva_liquidado', rotulo: 'IVA liquidado (345)', prefixo: '34', chave: 'iva_liquidado' },
  { campo: 'conta_iva_dedutivel', rotulo: 'IVA dedutível (345)', prefixo: '34', chave: 'iva_dedutivel' },
  { campo: 'conta_quebra', rotulo: 'Quebras / faltas', prefixo: undefined, chave: 'quebra' },
  { campo: 'conta_sobra', rotulo: 'Sobras', prefixo: undefined, chave: 'sobra' },
  { campo: 'conta_ativo', rotulo: 'Activo imobilizado', prefixo: '1', chave: 'ativo' },
] as const;

const SIM_NAO = [{ value: 1, label: 'Sim' }, { value: 0, label: 'Não' }];
const TAXAS = [{ value: 14, label: '14% (geral)' }, { value: 7, label: '7% (reduzida)' }, { value: 5, label: '5%' }, { value: 2, label: '2%' }, { value: 0, label: '0% (isento)' }];

interface Valores {
  preco_modo?: 'DEFINIR' | 'PERCENT' | 'VALOR';
  preco_valor?: number;
  taxa_imposto?: number;
  categoria_produto_id?: number;
  movimenta_stock?: number;
  e_servico?: number;
  bloqueado?: number;
  contas?: Record<string, string | undefined>;
  retirar?: string[];
}

/** Valores do modal → corpo de POST /vendas/produtos/editar-em-massa (só o que foi escolhido). */
export function corpoEdicaoMassa(ids: number[], v: Valores): { ids: number[]; dados: Record<string, unknown>; contas: Record<string, string> } {
  const dados: Record<string, unknown> = {};
  if (v.preco_modo && v.preco_valor !== undefined && v.preco_valor !== null) dados.preco = { modo: v.preco_modo, valor: v.preco_valor };
  if (v.taxa_imposto !== undefined) dados.taxa_imposto = v.taxa_imposto;
  if (v.categoria_produto_id !== undefined) dados.categoria_produto_id = v.categoria_produto_id;
  for (const k of ['movimenta_stock', 'e_servico', 'bloqueado'] as const) if (v[k] !== undefined) dados[k] = v[k] === 1;
  const contas: Record<string, string> = {};
  for (const [campo, codigo] of Object.entries(v.contas ?? {})) if (codigo) contas[campo] = codigo;
  for (const campo of v.retirar ?? []) contas[campo] = '__RETIRAR__';
  return { ids, dados, contas };
}

/** Resumo «Actual: …» do legado para um campo de conta. */
const resumoConta = (linhas: ProdutoFicha[], chave: (typeof CONTAS_MASSA)[number]['chave']) => {
  const com = linhas.filter((p) => p.contas[chave]).length;
  return com ? `${com} de ${linhas.length} já com conta` : 'Nenhum com conta';
};

/** «Editar em massa» dos produtos/serviços seleccionados (dados e contas), como no legado. */
export function ModalEditarMassaProdutos({ linhas, categorias, aoFechar }: { linhas: ProdutoFicha[] | null; categorias: { id: number; nome: string }[]; aoFechar: () => void }) {
  const [form] = Form.useForm<Valores>();
  const modo = Form.useWatch('preco_modo', form);
  const gravar = useAccao<{ alterados: number }>({ invalidar: [['logistica']], aoSucesso: () => { form.resetFields(); aoFechar(); }, tituloErro: 'Não foi possível actualizar os produtos' });
  const lista = linhas ?? [];
  const nomes = lista.slice(0, 6).map((p) => p.nome).join(', ') + (lista.length > 6 ? '…' : '');

  return (
    <Modal
      open={!!linhas}
      width={larguraModal(760)}
      title={<Space><span>Editar em massa</span><Typography.Text type="secondary">{lista.length} produto(s)/serviço(s)</Typography.Text></Space>}
      okText="Aplicar"
      cancelText="Cancelar"
      confirmLoading={gravar.isPending}
      onCancel={aoFechar}
      onOk={() => form.submit()}
      destroyOnHidden
    >
      <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>{nomes}</Typography.Paragraph>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Só os campos escolhidos são alterados; «— Manter como está —» deixa o valor de cada registo." />
      <Form<Valores> form={form} layout="horizontal" labelCol={{ flex: '230px' }} labelWrap onFinish={(v) => gravar.mutate({ url: '/vendas/produtos/editar-em-massa', dados: corpoEdicaoMassa(lista.map((p) => p.id), v) })}>
        <Typography.Title level={5} style={{ textTransform: 'uppercase', fontSize: 12, color: '#475569' }}>Dados</Typography.Title>
        <Form.Item label="Preço unitário">
          <Space.Compact style={{ width: '100%' }}>
            <Form.Item name="preco_modo" noStyle>
              <Select allowClear placeholder="— Manter como está —" style={{ width: '60%' }}
                options={[{ value: 'DEFINIR', label: 'Definir o preço (Kz)' }, { value: 'PERCENT', label: 'Ajustar em % (ex.: 10 ou -5)' }, { value: 'VALOR', label: 'Somar / subtrair valor (Kz)' }]} />
            </Form.Item>
            <Form.Item name="preco_valor" noStyle rules={[{ required: !!modo, message: 'Indique o valor.' }]}>
              <InputNumber disabled={!modo} placeholder="Valor" style={{ width: '40%' }} />
            </Form.Item>
          </Space.Compact>
        </Form.Item>
        <Form.Item name="taxa_imposto" label="Taxa de IVA"><Select allowClear placeholder="— Manter como está —" options={TAXAS} /></Form.Item>
        <Form.Item name="categoria_produto_id" label="Categoria"><Select allowClear showSearch optionFilterProp="label" placeholder="— Manter como está —" options={categorias.map((c) => ({ value: c.id, label: c.nome }))} /></Form.Item>
        <Form.Item name="movimenta_stock" label="Controla stock (armazém)"><Select allowClear placeholder="— Manter como está —" options={SIM_NAO} /></Form.Item>
        <Form.Item name="e_servico" label="É serviço"><Select allowClear placeholder="— Manter como está —" options={SIM_NAO} /></Form.Item>
        <Form.Item name="bloqueado" label="Bloqueado para venda"><Select allowClear placeholder="— Manter como está —" options={SIM_NAO} /></Form.Item>
        <Typography.Title level={5} style={{ textTransform: 'uppercase', fontSize: 12, color: '#475569' }}>Contas</Typography.Title>
        {CONTAS_MASSA.map((c) => (
          <Form.Item key={c.campo} name={['contas', c.campo]} label={c.rotulo} extra={resumoConta(lista, c.chave)}>
            <SeletorConta prefixo={c.prefixo} />
          </Form.Item>
        ))}
        <Form.Item name="retirar" label="Retirar a conta">
          <Select mode="multiple" allowClear placeholder="— Nenhuma —" options={CONTAS_MASSA.map((c) => ({ value: c.campo, label: c.rotulo }))} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
