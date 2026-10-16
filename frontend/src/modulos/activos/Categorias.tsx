import { Card, Col, Form, Input, InputNumber, Row } from 'antd';
import { useEffect, useState } from 'react';
import { NavActivos } from './comum/NavActivos';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { BotaoCartao } from './inventario/BotaoCartao';
import { ouTracos, percentagem } from './inventario/regrasInventario';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { useCategorias } from './comum/componentes';
import { contaForaDaClasse } from './comum/regras';
import type { CategoriaActivo } from './comum/tipos';
import { larguraModal } from '@/componentes/responsivo';
import { enviar } from '@/api/cliente';
import { ModalFormulario, SeccaoFormulario, type CampoFormulario } from '@/componentes/formularios';

import { TabelaComModos } from '@/componentes/vistas';
/** Activos › Categorias e taxas (ecrã activos_categorias): taxa anual, vida útil e contas de gasto, acumulada, venda, perda e activo. */
export default function Categorias() {
  const { pode } = useSessao();
  const q = useCategorias();
  const [editar, setEditar] = useState<CategoriaActivo | null | undefined>(undefined);
  const eliminar = useAccao({ invalidar: [['activos']] });
  const gerir = pode('activos_cat_gerir');

  // Colunas e rótulos curtos do legado (renderAssetCategories); as contas em falta ficam a vermelho porque sem 73/18 não há integração.
  const emFalta = <span style={{ color: '#cf1322' }} title="Sem esta conta não é possível integrar amortizações">---</span>;
  const colunas: ColunaApi<CategoriaActivo>[] = [
    { title: 'Nome', dataIndex: 'nome', ocultavel: false, render: (v) => <strong>{v}</strong> },
    { title: 'Taxa (%)', dataIndex: 'taxa_anual', align: 'center', render: (v) => percentagem(v), valorImpressao: (c) => percentagem(c.taxa_anual) },
    { title: 'Amort. Exerc.', dataIndex: 'conta_gasto', render: (v) => v ?? emFalta, valorImpressao: (c) => ouTracos(c.conta_gasto) },
    { title: 'Amort. Acum.', dataIndex: 'conta_amortizacao_acumulada', render: (v) => v ?? emFalta, valorImpressao: (c) => ouTracos(c.conta_amortizacao_acumulada) },
    { title: 'C. Venda', dataIndex: 'conta_venda', render: (v) => ouTracos(v) },
    { title: 'C. Perda', dataIndex: 'conta_perda', render: (v) => ouTracos(v) },
    // extras do sistema novo: escondidos por omissão («Colunas ▾»)
    { title: 'Vida útil (meses)', dataIndex: 'vida_util_padrao', align: 'right', ocultaPorOmissao: true, render: (v) => ouTracos(v) },
    { title: 'C. Activo', dataIndex: 'conta_ativo', ocultaPorOmissao: true, render: (v) => ouTracos(v) },
    { title: 'Activos', dataIndex: 'ativos', align: 'right', ocultaPorOmissao: true },
  ];

  return (
    <>
      <NavActivos actual="activos_categorias" />
      <Card
        title="Categorias de Imobilizado"
        extra={gerir && <BotaoCartao icone="plus" variante="primario" onClick={() => setEditar(null)}>Nova Categoria</BotaoCartao>}
      >
        <TabelaComModos<CategoriaActivo>
          idVista="activos_categorias"
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={q.data}
          scroll={{ x: 'max-content' }}
          columns={colunas}
          impressao={{ titulo: 'Categorias de Imobilizado' }}
          accoes={(c) => gerir ? [
            { icone: 'edit', titulo: 'Editar', onClick: () => setEditar(c) },
            {
              icone: 'trash', titulo: 'Excluir', variante: 'perigo',
              desactivado: c.ativos ? 'Categoria com activos: não pode ser excluída' : false,
              confirmar: { titulo: `Deseja realmente apagar a categoria "${c.nome}"?`, textoOk: 'Excluir', perigo: true },
              onClick: () => eliminar.mutateAsync({ metodo: 'delete', url: `/ativos/categorias/${c.id}` }).catch(() => undefined),
            },
          ] : []}
        />
      </Card>
      <ModalCategoria categoria={editar} aoFechar={() => setEditar(undefined)} />
    </>
  );
}

/** Regras de gravação da categoria, comuns à grade e à vista Linhas: vazios passam a nulos. */
export function dadosCategoria(v: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x === undefined || x === '' ? null : x]));
}

const contaDaClasse = (prefixo: string) => (c: unknown) => (contaForaDaClasse(c, prefixo) ? `A conta tem de ser da classe ${prefixo}.` : null);

/** Vista Linhas das categorias (uma categoria por linha; as contas escrevem-se pelo código). */
export const CAMPOS_CATEGORIA: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Nome', obrigatorio: true, largura: 220, maxLength: 255, aliases: ['Categoria', 'Nome da categoria'] },
  { nome: 'taxa_anual', rotulo: 'Taxa anual (%)', tipo: 'numero', min: 0, max: 100, precisao: 4, largura: 110, aliases: ['Taxa', 'Taxa (%)'] },
  { nome: 'vida_util_padrao', rotulo: 'Vida útil (meses)', tipo: 'numero', min: 0, precisao: 0, largura: 110, aliases: ['Vida útil'] },
  { nome: 'conta_gasto', rotulo: 'Conta de gasto (73)', largura: 130, placeholder: 'Conta 73…', aliases: ['Amort. Exerc.'], validar: contaDaClasse('73') },
  { nome: 'conta_amortizacao_acumulada', rotulo: 'Conta de amortização acumulada (18)', largura: 130, placeholder: 'Conta 18…', aliases: ['Amort. Acum.'], validar: contaDaClasse('18') },
  { nome: 'conta_venda', rotulo: 'Conta de ganho na venda (68)', largura: 130, placeholder: 'Conta 68…', emLinhas: 'opcional', seccao: 'Contas', aliases: ['C. Venda'], validar: contaDaClasse('68') },
  { nome: 'conta_perda', rotulo: 'Conta de perda / sinistro (78)', largura: 130, placeholder: 'Conta 78…', emLinhas: 'opcional', seccao: 'Contas', aliases: ['C. Perda'], validar: contaDaClasse('78') },
  { nome: 'conta_ativo', rotulo: 'Conta do activo (11–14)', largura: 130, placeholder: 'Conta 1…', emLinhas: 'opcional', seccao: 'Contas', aliases: ['C. Activo'], validar: contaDaClasse('1') },
];

export function ModalCategoria({ categoria, aoFechar }: { categoria: CategoriaActivo | null | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<Partial<CategoriaActivo> & { taxa_anual?: number }>();
  const aberto = categoria !== undefined;
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    if (categoria) form.setFieldsValue({ ...categoria, taxa_anual: categoria.taxa_anual !== null ? Number(categoria.taxa_anual) : undefined } as never);
  }, [aberto, categoria, form]);

  const valores = Form.useWatch([], form) as Partial<Record<keyof CategoriaActivo, unknown>> | undefined;
  // mapeamento anterior fora da classe (ui_assets.js:1136-1150): mostra-se assinalado e não se bloqueia — o servidor aceita se não mudar
  const conta = (nome: keyof CategoriaActivo, rotulo: string, prefixo: string) => (
    <Col xs={24} md={12}>
      <Form.Item name={nome} label={rotulo}
        {...(contaForaDaClasse(valores?.[nome], prefixo) ? { validateStatus: 'warning' as const, help: `Fora da classe ${prefixo} (mapeamento anterior) — troque-a por uma conta ${prefixo}.` } : {})}>
        <SeletorConta prefixo={prefixo} placeholder={`Conta ${prefixo}…`} />
      </Form.Item>
    </Col>
  );

  return (
    <ModalFormulario chave="activos.categorias" titulo={categoria ? `Editar categoria «${categoria.nome}»` : 'Nova categoria'} aberto={aberto} aoFechar={aoFechar}
      criacao={!categoria} form={form} campos={CAMPOS_CATEGORIA} nomeRegistos="categorias" invalidar={[['activos']]}
      gravarLinha={(r) => enviar('post', '/ativos/categorias', dadosCategoria(r))}
      nota="Uma linha por categoria. As contas escrevem-se pelo código (gasto 73, amortização acumulada 18, venda 68, perda 78, activo 11–14). Linhas deixadas em branco são ignoradas."
      onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(720)}>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const dados = dadosCategoria(v);
          accao.mutate(categoria ? { metodo: 'put', url: `/ativos/categorias/${categoria.id}`, dados } : { url: '/ativos/categorias', dados });
        }}
      >
        <Row gutter={12}>
          <Col xs={24} md={12}>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}>
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item name="taxa_anual" label="Taxa anual (%)">
              <InputNumber min={0} max={100} precision={4} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item name="vida_util_padrao" label="Vida útil (meses)">
              <InputNumber min={0} precision={0} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
        {/* grupos do legado (renderAssetCategories): amortização (73/18) e venda/perda (68/78); a conta do activo é do sistema novo */}
        <SeccaoFormulario titulo="Contas de amortização">
          <Row gutter={12}>
            {conta('conta_gasto', 'Conta de gasto (73)', '73')}
            {conta('conta_amortizacao_acumulada', 'Conta de amortização acumulada (18)', '18')}
          </Row>
        </SeccaoFormulario>
        <SeccaoFormulario titulo="Contas de venda, perda e do activo" recolhida={!!categoria && !categoria.conta_venda && !categoria.conta_perda && !categoria.conta_ativo}>
          <Row gutter={12}>
            {conta('conta_venda', 'Conta de ganho na venda (68)', '68')}
            {conta('conta_perda', 'Conta de perda / sinistro (78)', '78')}
            {conta('conta_ativo', 'Conta do activo (11–14)', '1')}
          </Row>
        </SeccaoFormulario>
      </Form>
    </ModalFormulario>
  );
}
