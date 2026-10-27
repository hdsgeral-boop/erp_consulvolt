import { Alert, Form, InputNumber, Modal, Select, Space, Typography, message } from 'antd';
import type { ClipboardEvent } from 'react';
import { useAccao } from '@/componentes/Accoes';
import { SeccaoFormulario } from '@/componentes/formularios';
import { ColarExcelBotao } from '@/componentes/formularios/ColarExcelBotao';
import { colagemParaLinhas, pareceColagemDeGrade, posicaoColagem, type ResultadoColagemGrade } from '@/componentes/formularios/colagemGrade';
import type { CampoFormulario } from '@/componentes/formularios/tipos';
import { larguraModal } from '@/componentes/responsivo';
import type { ProdutoFicha } from './formularioProduto';

const SIM_NAO = [{ value: 1, label: 'Sim' }, { value: 0, label: 'Não' }];
const TAXAS = [{ value: 14, label: '14% (geral)' }, { value: 7, label: '7% (reduzida)' }, { value: 5, label: '5%' }, { value: 2, label: '2%' }, { value: 0, label: '0% (isento)' }];
const MODOS_PRECO = [
  { value: 'DEFINIR', label: 'Definir o preço (Kz)' },
  { value: 'PERCENT', label: 'Ajustar em % (ex.: 10 ou -5)' },
  { value: 'VALOR', label: 'Somar / subtrair valor (Kz)' },
];

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

/** «Editar em massa» dos produtos/serviços seleccionados (dados; as contas vêm da categoria — ronda 3). */
export function ModalEditarMassaProdutos({ linhas, categorias, aoFechar }: { linhas: ProdutoFicha[] | null; categorias: { id: number; nome: string }[]; aoFechar: () => void }) {
  const [form] = Form.useForm<Valores>();
  const modo = Form.useWatch('preco_modo', form);
  const gravar = useAccao<{ alterados: number }>({ invalidar: [['logistica']], aoSucesso: () => { form.resetFields(); aoFechar(); }, tituloErro: 'Não foi possível actualizar os produtos' });
  const lista = linhas ?? [];
  const nomes = lista.slice(0, 6).map((p) => p.nome).join(', ') + (lista.length > 6 ? '…' : '');

  // Colagem do Excel: aqui não há uma linha por produto (o mesmo valor aplica-se a todos os seleccionados) — os
  // «campos» coláveis são os do próprio formulário. Todos são seguros: selects fixos ou com a lista de categorias
  // já carregada (ao contrário de terceiro/conta, que pedem pesquisa ao servidor).
  const camposColaveis: CampoFormulario[] = [
    { nome: 'preco_modo', rotulo: 'Preço (modo)', tipo: 'select', opcoes: MODOS_PRECO },
    { nome: 'preco_valor', rotulo: 'Preço (valor)', tipo: 'numero', precisao: 2 },
    { nome: 'taxa_imposto', rotulo: 'Taxa de IVA', tipo: 'select', opcoes: TAXAS },
    { nome: 'categoria_produto_id', rotulo: 'Categoria', tipo: 'select', opcoes: categorias.map((c) => ({ value: c.id, label: c.nome })) },
    { nome: 'movimenta_stock', rotulo: 'Controla stock (armazém)', tipo: 'select', opcoes: SIM_NAO },
    { nome: 'e_servico', rotulo: 'É serviço', tipo: 'select', opcoes: SIM_NAO },
    { nome: 'bloqueado', rotulo: 'Bloqueado para venda', tipo: 'select', opcoes: SIM_NAO },
  ];
  const indiceColagem = Object.fromEntries(camposColaveis.map((c, i) => [c.nome, i]));

  const avisoColagem = (r: ResultadoColagemGrade, preenchidos: number) => {
    const extra = r.linhas.length > 1 ? ' (as restantes linhas coladas foram ignoradas — este formulário aplica um único conjunto de valores)' : '';
    if (r.naoReconhecidos) {
      const det = Object.entries(r.exemplos).map(([k, s]) => `${k}: ${s.join(', ')}`).join('; ');
      void message.warning(`${preenchidos} campo(s) preenchido(s)${extra}. ${r.naoReconhecidos} valor(es) não reconhecido(s) — corrija-os antes de aplicar: ${det}`);
    } else if (preenchidos) {
      void message.success(`${preenchidos} campo(s) preenchido(s) a partir do Excel${extra}.`);
    } else {
      void message.info('Nada reconhecido no texto colado.');
    }
  };

  // Só os campos com valor reconhecido são aplicados — um valor não reconhecido fica por preencher (em vez de
  // limpar o campo), para não apagar por engano uma escolha feita a seguir ao aviso.
  const aplicarColagem = (linha: Record<string, unknown> | undefined): number => {
    const valores = Object.fromEntries(Object.entries(linha ?? {}).filter(([, v]) => v !== null && v !== undefined));
    if (Object.keys(valores).length) form.setFieldsValue(valores);
    return Object.keys(valores).length;
  };

  const colarBloco = (texto: string) => {
    const r = colagemParaLinhas(texto, camposColaveis);
    avisoColagem(r, aplicarColagem(r.linhas[0]));
  };

  // Colagem directa (Ctrl+V) num campo: lê o texto posicionalmente a partir dessa coluna.
  const aoColarCampo = (e: ClipboardEvent<HTMLDivElement>) => {
    const texto = e.clipboardData?.getData('text') ?? '';
    if (!pareceColagemDeGrade(texto)) return;
    const pos = posicaoColagem(e.target);
    if (!pos) return;
    e.preventDefault();
    const r = colagemParaLinhas(texto, camposColaveis, pos.coluna);
    avisoColagem(r, aplicarColagem(r.linhas[0]));
  };

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
        <SeccaoFormulario titulo="Dados" extra={<ColarExcelBotao colunas={camposColaveis.map((c) => c.rotulo)} aoColar={colarBloco} tamanho="small" />}>
        <div data-colar-linha={0} onPaste={aoColarCampo}>
        <Form.Item label="Preço unitário">
          <Space.Compact style={{ width: '100%' }}>
            <div data-colar-coluna={indiceColagem.preco_modo} style={{ width: '60%' }}>
              <Form.Item name="preco_modo" noStyle>
                <Select allowClear placeholder="— Manter como está —" style={{ width: '100%' }} options={MODOS_PRECO} />
              </Form.Item>
            </div>
            <div data-colar-coluna={indiceColagem.preco_valor} style={{ width: '40%' }}>
              <Form.Item name="preco_valor" noStyle rules={[{ required: !!modo, message: 'Indique o valor.' }]}>
                <InputNumber disabled={!modo} placeholder="Valor" style={{ width: '100%' }} />
              </Form.Item>
            </div>
          </Space.Compact>
        </Form.Item>
        <div data-colar-coluna={indiceColagem.taxa_imposto}>
          <Form.Item name="taxa_imposto" label="Taxa de IVA"><Select allowClear placeholder="— Manter como está —" options={TAXAS} /></Form.Item>
        </div>
        <div data-colar-coluna={indiceColagem.categoria_produto_id}>
          <Form.Item name="categoria_produto_id" label="Categoria"><Select allowClear showSearch optionFilterProp="label" placeholder="— Manter como está —" options={categorias.map((c) => ({ value: c.id, label: c.nome }))} /></Form.Item>
        </div>
        <div data-colar-coluna={indiceColagem.movimenta_stock}>
          <Form.Item name="movimenta_stock" label="Controla stock (armazém)"><Select allowClear placeholder="— Manter como está —" options={SIM_NAO} /></Form.Item>
        </div>
        <div data-colar-coluna={indiceColagem.e_servico}>
          <Form.Item name="e_servico" label="É serviço"><Select allowClear placeholder="— Manter como está —" options={SIM_NAO} /></Form.Item>
        </div>
        <div data-colar-coluna={indiceColagem.bloqueado}>
          <Form.Item name="bloqueado" label="Bloqueado para venda"><Select allowClear placeholder="— Manter como está —" options={SIM_NAO} /></Form.Item>
        </div>
        </div>
        </SeccaoFormulario>
        {/* ronda 3: as contas definem-se na categoria — mudar a categoria aqui muda as contas dos produtos escolhidos */}
        <Alert type="info" showIcon message="Contas contabilísticas" description="As contas vêm da categoria do produto: para as mudar, escolha outra categoria acima ou edite a categoria em Vendas › Categorias." />
      </Form>
    </Modal>
  );
}
