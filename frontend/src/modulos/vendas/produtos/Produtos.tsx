import { Button, Card, Checkbox, Col, Form, Input, InputNumber, Modal, Row, Select, Space, Tabs, Tag, Tooltip } from 'antd';
import { ModalImportarProdutos, type EntidadeImportacao } from './ModalImportarProdutos';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState, type Key } from 'react';
import { useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { TabelaComModos } from '@/componentes/vistas';
import { corpoProduto, produtoParaFormulario, type ProdutoFicha, type ValoresProduto } from './formularioProduto';
import { CampoImagemProduto, MiniaturaProduto } from './imagemProduto';
import { ModalEditarMassaProdutos } from './ModalEditarMassaProdutos';
import { accoesProduto, type EdicaoProduto } from './regrasProdutos';

interface Categoria {
  id: number;
  nome: string;
}

/** Produto da listagem (ProdutoResource + imagem). */
type ProdutoLista = ProdutoFicha & { imagem_url?: string | null };

/** Tipos de operação AGT (legado: FacturaAGT.OPERACOES). Vazio: TB se movimenta stock, SG caso contrário. */
export const OPERACOES_FE: Record<string, string> = {
  TB: 'Transmissão de bens', SG: 'Prestação de serviço (geral)', SE: 'Serviços de educação', SS: 'Serviços de saúde',
  STP: 'Transporte de passageiros', SR: 'Serviços sujeitos a royalties', SIF: 'Intermediação financeira ou seguradora',
  SHS: 'Hotelaria e similares', ST: 'Telecomunicações', AS: 'Arrendamento e subarrendamento', QT: 'Quotas', RD: 'Repasse de despesas',
};

function useCategorias() {
  return useQuery({ queryKey: ['logistica', 'categorias'], queryFn: () => obter<Categoria[]>('/logistica/categorias-produtos'), staleTime: 300_000 });
}

/** Stock actual por produto (soma dos armazéns) — coluna «STOCK» e «Stock Atual» da ficha. */
function useStockProdutos() {
  return useQuery({ queryKey: ['logistica', 'stock-por-produto'], queryFn: () => obter<Record<string, string>>('/vendas/produtos/stock'), staleTime: 60_000, retry: false });
}

/**
 * Vendas › Produtos/Servicos e Vendas › Categorias (ecrã vendas_produtos). No legado são dois separadores do agregador
 * «Gestão de Vendas»; aqui o separador «Categorias» abre este ecrã com ?separador=categorias.
 */
export default function Produtos() {
  const [procura] = useSearchParams();
  return procura.get('separador') === 'categorias' ? <Categorias /> : <ListaProdutos />;
}

/** Conta numa célula (legado celulaConta: código ou «—» em cinzento). */
const celulaConta = (v: string | null | undefined) => (v ? <span style={{ fontFamily: 'monospace' }}>{v}</span> : <span style={{ color: '#94a3b8' }}>-</span>);

function ListaProdutos() {
  const { pode } = useSessao();
  const podeGerir = pode('vendas_produtos_gerir');
  const podeEliminar = pode('vendas_dados_del');
  const categorias = useCategorias();
  const stock = useStockProdutos();
  const [importar, setImportar] = useState<EntidadeImportacao | null>(null);
  const [pesquisa, setPesquisa] = useState('');
  const [categoria, setCategoria] = useState<number>();
  const [bloqueado, setBloqueado] = useState<string>();
  const [comStock, setComStock] = useState<string>();
  const [edicao, setEdicao] = useState<EdicaoProduto | null>(null);
  const [massa, setMassa] = useState<ProdutoLista[] | null>(null);
  const [seleccionados, setSeleccionados] = useState<{ chaves: Key[]; linhas: ProdutoLista[] }>({ chaves: [], linhas: [] });

  const bloquear = useAccao({ invalidar: [['logistica']] });
  const eliminar = useAccao({ invalidar: [['logistica']] });
  const nomeCategoria = (id: number | null) => categorias.data?.find((c) => c.id === id)?.nome ?? '—';
  const stockDe = (id: number) => stock.data?.[String(id)] ?? '0';
  const pequeno = useEcraPequeno();

  const colunas: ColunaApi<ProdutoLista>[] = [
    {
      title: 'Código', dataIndex: 'codigo', valorImpressao: (r) => r.codigo ?? '',
      render: (v, r) => <Space size={8}><MiniaturaProduto url={r.imagem_url} /><strong>{v || '—'}</strong></Space>,
    },
    {
      title: 'Nome', dataIndex: 'nome', valorImpressao: (r) => r.nome,
      render: (v: string, r) => (
        <Space size={4} wrap>
          <span>{v}</span>
          {r.e_ativo_imobilizado && <Tag color="purple" style={{ fontSize: 11 }}><IconeFa nome="building" /> Imobilizado</Tag>}
          {r.bloqueado && <Tag color="red" style={{ fontSize: 11 }}><IconeFa nome="ban" /> Bloqueado</Tag>}
        </Space>
      ),
    },
    { title: 'Categoria', dataIndex: 'categoria_produto_id', ocultaPorOmissao: true, render: nomeCategoria, valorImpressao: (r) => nomeCategoria(r.categoria_produto_id) },
    { title: 'Preço unitário', dataIndex: 'preco_unitario', align: 'right', render: (v: string | null) => formatarKz(v ?? 0, true) },
    { title: 'Taxa IVA', dataIndex: 'taxa_imposto', align: 'right', valorImpressao: (r) => `${formatarNumero(r.taxa_imposto)}%${r.codigo_isencao_fe ? ` · ${r.codigo_isencao_fe}` : ''}`, render: (v: string | null, r) => (r.codigo_isencao_fe ? <Tooltip title="Motivo de isenção AGT">{formatarNumero(v)}% · {r.codigo_isencao_fe}</Tooltip> : `${formatarNumero(v)}%`) },
    {
      title: 'Stock', key: 'stock', align: 'right', valorImpressao: (r) => (r.e_ativo_imobilizado || !r.movimenta_stock ? '-' : formatarNumero(stockDe(r.id))),
      render: (_, r) => (r.e_ativo_imobilizado || !r.movimenta_stock ? <span style={{ color: '#94a3b8' }}>-</span> : <span style={{ fontWeight: 600, color: Number(stockDe(r.id)) <= 0 ? '#b91c1c' : undefined }}>{formatarNumero(stockDe(r.id))}</span>), // número simples, como o legado
    },
    {
      title: 'Tipo', key: 'tipo', ocultaPorOmissao: true,
      valorImpressao: (r) => [r.movimenta_stock && 'Stock', r.e_servico && 'Serviço', r.e_quarto && 'Quarto', r.lavandaria && 'Lavandaria'].filter(Boolean).join(', '),
      render: (_, r) => (
        <Space size={4} wrap>
          {r.movimenta_stock && <Tag color="blue">Stock</Tag>}
          {r.e_servico && <Tag color="purple">Serviço</Tag>}
          {r.e_quarto && <Tag color="gold">Quarto</Tag>}
          {r.lavandaria && <Tag color="cyan">Lavandaria</Tag>}
        </Space>
      ),
    },
    { title: 'Proveito (61/62)', key: 'c_venda', render: (_, r) => celulaConta(r.contas.venda), valorImpressao: (r) => r.contas.venda ?? '' },
    { title: 'Custo (71/75)', key: 'c_custo', render: (_, r) => celulaConta(r.contas.custo), valorImpressao: (r) => r.contas.custo ?? '' },
    { title: 'Compra (21)', key: 'c_compra', render: (_, r) => celulaConta(r.contas.compra), valorImpressao: (r) => r.contas.compra ?? '' },
    { title: 'Existências (26)', key: 'c_inv', render: (_, r) => celulaConta(r.contas.inventario), valorImpressao: (r) => r.contas.inventario ?? '' },
    { title: 'IVA liquidado', key: 'c_ivl', render: (_, r) => celulaConta(r.contas.iva_liquidado), valorImpressao: (r) => r.contas.iva_liquidado ?? '' },
    { title: 'IVA dedutível', key: 'c_ivd', render: (_, r) => celulaConta(r.contas.iva_dedutivel), valorImpressao: (r) => r.contas.iva_dedutivel ?? '' },
    { title: 'Quebras', key: 'c_queb', render: (_, r) => celulaConta(r.contas.quebra), valorImpressao: (r) => r.contas.quebra ?? '' },
    { title: 'Sobras', key: 'c_sob', render: (_, r) => celulaConta(r.contas.sobra), valorImpressao: (r) => r.contas.sobra ?? '' },
    { title: 'Imobilizado', key: 'c_atv', render: (_, r) => <span style={{ background: '#f5f3ff', padding: '0 4px' }}>{celulaConta(r.contas.ativo)}</span>, valorImpressao: (r) => r.contas.ativo ?? '' },
  ];

  return (
    <>
      <ModalImportarProdutos entidade={importar} aoFechar={() => setImportar(null)} />
      {edicao && <FormularioProduto edicao={edicao} aoFechar={() => setEdicao(null)} categorias={categorias.data ?? []} stockActual={edicao.id ? stockDe(edicao.id) : undefined} />}
      <Card
        title="Catálogo de Produtos e Serviços"
        extra={podeGerir && (
          <Space wrap>
            {/* «Editar em massa ( N )» no cabeçalho, como o antigo */}
            <Button icon={<IconeFa nome="edit" />} disabled={!seleccionados.linhas.length} onClick={() => setMassa(seleccionados.linhas)}>Editar em massa ( {seleccionados.linhas.length} )</Button>
            <Button icon={<IconeFa nome="file-import" />} onClick={() => setImportar('produtos')}>Importar</Button>
            <Button type="primary" icon={<IconeFa nome="plus-circle" />} onClick={() => setEdicao({ modo: 'novo' })}>+ Novo Produto</Button>
          </Space>
        )}
      >
        <BarraFiltros>
          <Input.Search placeholder="Código ou nome" allowClear style={{ width: 260, maxWidth: '100%' }} onSearch={setPesquisa} />
          <Select placeholder="Categoria" allowClear style={{ width: 200, maxWidth: '100%' }} value={categoria} onChange={setCategoria} options={(categorias.data ?? []).map((c) => ({ value: c.id, label: c.nome }))} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={bloqueado} onChange={setBloqueado} options={[{ value: '0', label: 'Activos' }, { value: '1', label: 'Bloqueados' }]} />
          <Select placeholder="Stock" allowClear style={{ width: 190 }} value={comStock} onChange={setComStock} options={[{ value: '1', label: 'Movimentam stock' }, { value: '0', label: 'Não movimentam stock' }]} />
        </BarraFiltros>
        <TabelaApi<ProdutoLista>
          url="/logistica/produtos"
          chaveConsulta={['logistica', 'produtos']}
          filtros={{ pesquisa, categoria_produto_id: categoria, bloqueado, movimenta_stock: comStock }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          seleccao={podeGerir ? { semBarra: true, chaves: seleccionados.chaves, aoMudar: (chaves, linhas) => setSeleccionados({ chaves, linhas }) } : undefined}
          accoes={(r) => accoesProduto(r, { podeGerir, podeEliminar }, {
            editar: () => setEdicao({ modo: 'editar', id: r.id }),
            copiar: () => setEdicao({ modo: 'copiar', id: r.id }),
            bloquear: () => bloquear.mutateAsync({ url: `/logistica/produtos/${r.id}/bloquear` }),
            eliminar: () => eliminar.mutateAsync({ metodo: 'delete', url: `/logistica/produtos/${r.id}` }),
          })}
          impressao={{
            titulo: 'Catálogo de Produtos e Serviços',
            orientacao: 'paisagem',
            filtros: [
              pesquisa && `Pesquisa: ${pesquisa}`,
              categoria !== undefined && `Categoria: ${nomeCategoria(categoria)}`,
              bloqueado && (bloqueado === '1' ? 'Bloqueados' : 'Activos'),
              comStock && (comStock === '1' ? 'Movimentam stock' : 'Não movimentam stock'),
            ],
          }}
          vazio={{ icone: 'box-open', titulo: 'Sem produtos', texto: 'Ainda não há produtos ou serviços com estes filtros.' }}
          onRow={(r) => ({ onDoubleClick: () => podeGerir && setEdicao({ modo: 'editar', id: r.id }) })}
        />
      </Card>
      <ModalEditarMassaProdutos linhas={massa} categorias={categorias.data ?? []} aoFechar={() => setMassa(null)} />
    </>
  );
}

/**
 * Formulário do produto NA PÁGINA (como o legado, que o abre por cima da lista), com «Imagem do Produto» e «Stock Atual»
 * (só de leitura: o stock só muda por movimentos de inventário).
 */
function FormularioProduto({ edicao, aoFechar, categorias, stockActual }: { edicao: EdicaoProduto; aoFechar: () => void; categorias: Categoria[]; stockActual?: string }) {
  const { pode } = useSessao();
  const [form] = Form.useForm<ValoresProduto>();
  const eQuarto = Form.useWatch('e_quarto', form);
  const lavandaria = Form.useWatch('lavandaria_ativa', form);
  const activo = Form.useWatch('e_ativo_imobilizado', form);
  const ficha = useQuery({
    queryKey: ['logistica', 'produto-ficha', edicao.id],
    queryFn: () => obter<ProdutoFicha & { imagem_url?: string | null }>(`/logistica/produtos/${edicao.id}`),
    enabled: !!edicao.id,
  });
  const gravar = useAccao<ProdutoFicha>({ invalidar: [['logistica']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar o produto' });

  useEffect(() => {
    if (edicao.modo === 'novo') {
      form.resetFields();
      form.setFieldsValue({ taxa_imposto: 14, movimenta_stock: true });
    } else if (ficha.data) form.setFieldsValue(produtoParaFormulario(ficha.data, edicao.modo === 'copiar'));
  }, [edicao, ficha.data, form]);
  useEffect(() => {
    if (ficha.error) notificarErro(ficha.error, 'Não foi possível obter o produto');
  }, [ficha.error]);

  const titulo = edicao.modo === 'editar' ? `Editar ${ficha.data?.nome ?? 'produto'}` : edicao.modo === 'copiar' ? 'Copiar produto' : 'Novo Produto / Serviço';
  const conta = (nome: keyof ValoresProduto, rotulo: string, prefixo?: string) => (
    <Col xs={24} md={12}>
      <Form.Item name={nome} label={rotulo}>
        <SeletorConta prefixo={prefixo} />
      </Form.Item>
    </Col>
  );

  return (
    <Card
      title={titulo}
      style={{ marginBottom: 16, borderTop: '4px solid var(--ant-color-primary, #2563eb)' }}
      loading={!!edicao.id && ficha.isLoading}
      extra={<Button type="text" aria-label="Fechar" icon={<IconeFa nome="times" />} onClick={aoFechar} />}
    >
      <Form<ValoresProduto>
        form={form}
        layout="vertical"
        onFinish={(v) =>
          gravar.mutate(edicao.modo === 'editar' && edicao.id ? { metodo: 'put', url: `/logistica/produtos/${edicao.id}`, dados: corpoProduto(v) } : { url: '/logistica/produtos', dados: corpoProduto(v) })
        }
      >
        <Tabs
          items={[
            {
              key: 'geral',
              label: 'Geral',
              forceRender: true,
              children: (
                <Row gutter={16}>
                  <Col xs={24} md={6}>
                    <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { max: 50 }]}><Input /></Form.Item>
                  </Col>
                  <Col xs={24} md={10}>
                    <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}><Input /></Form.Item>
                  </Col>
                  <Col xs={12} md={5}>
                    <Form.Item name="preco_unitario" label="Preço Unitário"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
                  </Col>
                  <Col xs={12} md={3}>
                    <Form.Item name="taxa_imposto" label="Taxa IVA"><InputNumber min={0} max={100} style={{ width: '100%' }} addonAfter="%" /></Form.Item>
                  </Col>
                  <Col xs={24} md={10}>
                    <Form.Item name="categoria_produto_id" label="Categoria"><Select allowClear options={categorias.map((c) => ({ value: c.id, label: c.nome }))} /></Form.Item>
                  </Col>
                  <Col xs={12} md={6}>
                    <Form.Item label="Stock Atual" tooltip="Só de leitura: o stock muda com recepções, vendas, guias e inventários.">
                      <Input disabled value={edicao.modo === 'editar' ? formatarNumero(stockActual ?? '0') : '0'} />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={8}>
                    <Form.Item label="Imagem do Produto">
                      <CampoImagemProduto produtoId={edicao.modo === 'editar' ? edicao.id : undefined} url={edicao.modo === 'editar' ? ficha.data?.imagem_url : null} podeGerir={pode('vendas_produtos_gerir')} />
                    </Form.Item>
                  </Col>
                  <Col span={24}>
                    <Space wrap size="large">
                      <Form.Item name="movimenta_stock" valuePropName="checked" noStyle><Checkbox>Gerir Stock</Checkbox></Form.Item>
                      <Form.Item name="e_servico" valuePropName="checked" noStyle><Checkbox>Serviço</Checkbox></Form.Item>
                      <Form.Item name="e_quarto" valuePropName="checked" noStyle><Checkbox>é Quarto</Checkbox></Form.Item>
                      <Form.Item name="lavandaria_ativa" valuePropName="checked" noStyle><Checkbox>Serviço de lavandaria</Checkbox></Form.Item>
                      <Form.Item name="e_ativo_imobilizado" valuePropName="checked" noStyle><Checkbox>é Ativo Imobilizado</Checkbox></Form.Item>
                    </Space>
                  </Col>
                </Row>
              ),
            },
            {
              key: 'fe',
              label: 'Dados fiscais (AGT)',
              forceRender: true,
              children: (
                <Row gutter={16}>
                  <Col xs={12} md={6}>
                    <Form.Item name="unidade_fe" label="Unidade" rules={[{ max: 10 }]}><Input placeholder="UN" /></Form.Item>
                  </Col>
                  <Col xs={12} md={8}>
                    <Form.Item name="tipo_operacao_fe" label="Tipo de operação" rules={[{ max: 20 }]}>
                      <Select allowClear placeholder="Automático (TB/SG)" options={Object.entries(OPERACOES_FE).map(([value, l]) => ({ value, label: `${value} — ${l}` }))} />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={10}>
                    <Form.Item name="codigo_isencao_fe" label="Motivo de isenção (IVA 0%)" rules={[{ pattern: /^[Mm]\d{2}$/, message: 'Código AGT (M00 a M99).' }]}>
                      <Input placeholder="Ex.: M10" maxLength={3} style={{ textTransform: 'uppercase' }} />
                    </Form.Item>
                  </Col>
                </Row>
              ),
            },
            {
              key: 'contas',
              label: 'Contas',
              forceRender: true,
              children: (
                <Row gutter={16}>
                  {conta('codigo_conta', 'Conta de Receita (61/62)', '6')}
                  {conta('conta_custo', 'Conta de Custo (71/75)', '7')}
                  {conta('conta_compra', 'Compras (21)', '2')}
                  {conta('conta_inventario', 'Existências (26)', '2')}
                  {conta('conta_iva_liquidado', 'IVA Liquidado', '34')}
                  {conta('conta_iva_dedutivel', 'IVA Dedutível', '34')}
                  {conta('conta_quebra', 'Quebras de inventário')}
                  {conta('conta_sobra', 'Sobras de inventário')}
                  {activo && conta('conta_ativo', 'Conta do activo', '1')}
                </Row>
              ),
            },
            ...(eQuarto
              ? [{
                  key: 'hotelaria', label: 'Hotelaria', forceRender: true,
                  children: (
                    <Row gutter={16}>
                      <Col xs={24} md={8}><Form.Item name="preco_por_hora" label="Preço por hora (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
                      <Col xs={24} md={8}><Form.Item name="preco_por_dia" label="Preço por dia (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
                      <Col xs={24} md={8}><Form.Item name="horas_minimas" label="Horas mínimas"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
                    </Row>
                  ),
                }]
              : []),
            ...(lavandaria
              ? [{
                  key: 'lavandaria', label: 'Lavandaria', forceRender: true,
                  children: (
                    <Row gutter={16}>
                      <Col xs={24} md={8}><Form.Item name="lavandaria_grupo" label="Grupo" rules={[{ max: 50 }]}><Input /></Form.Item></Col>
                      <Col xs={12} md={8}><Form.Item name="lavandaria_unidade" label="Unidade" rules={[{ max: 20 }]}><Select allowClear options={[{ value: 'PECA', label: 'Peça' }, { value: 'KG', label: 'Kg' }]} /></Form.Item></Col>
                      <Col xs={12} md={8}><Form.Item name="lavandaria_dias_entrega" label="Dias de entrega"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
                      <Col xs={12} md={8}><Form.Item name="lavandaria_preco_peca" label="Preço por peça (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
                      <Col xs={12} md={8}><Form.Item name="lavandaria_preco_kg" label="Preço por kg (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
                      <Col xs={24} md={8}><Form.Item name="lavandaria_requer_orcamento" valuePropName="checked" label=" "><Checkbox>Requer orçamento</Checkbox></Form.Item></Col>
                    </Row>
                  ),
                }]
              : []),
          ]}
        />
        <Space wrap>
          <Button type="primary" htmlType="submit" loading={gravar.isPending} icon={<IconeFa nome="save" />}>Guardar</Button>
          <Button onClick={aoFechar}>Cancelar</Button>
        </Space>
      </Form>
    </Card>
  );
}

/** Vendas › Categorias: formulário rápido «Nome da Categoria: + Adicionar» na página, Importar e a lista (NOME, AÇÕES). */
function Categorias() {
  const { pode } = useSessao();
  const podeGerir = pode('vendas_produtos_gerir');
  const podeEliminar = pode('vendas_dados_del');
  const consulta = useCategorias();
  const [importar, setImportar] = useState<EntidadeImportacao | null>(null);
  const [edicao, setEdicao] = useState<Categoria | null>(null);
  const [formNova] = Form.useForm<{ nome: string }>();
  const [form] = Form.useForm<{ nome: string }>();
  const adicionar = useAccao({ invalidar: [['logistica', 'categorias']], aoSucesso: () => formNova.resetFields() });
  const gravar = useAccao({ invalidar: [['logistica', 'categorias']], aoSucesso: () => setEdicao(null) });
  const eliminar = useAccao({ invalidar: [['logistica', 'categorias']] });

  useEffect(() => {
    if (edicao) form.setFieldsValue({ nome: edicao.nome });
  }, [edicao, form]);

  return (
    <>
      <ModalImportarProdutos entidade={importar} aoFechar={() => setImportar(null)} />
      {/* dois cartões, como o legado (js/ui_sales.js:5406-5429): «Nova Categoria» e «Listagem de Categorias» */}
      {podeGerir && (
        <Card title="Nova Categoria" style={{ marginBottom: '2rem' }}>
          <Form form={formNova} layout="vertical" onFinish={(v) => adicionar.mutate({ url: '/logistica/categorias-produtos', dados: v })}>
            <div style={{ display: 'flex', gap: '1rem', alignItems: 'flex-end' }}>
              <Form.Item name="nome" label="Nome da Categoria:" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]} style={{ flex: 1, marginBottom: 0 }}>
                <Input placeholder="Ex: Bebidas, Servicos de Consultoria..." />
              </Form.Item>
              <Button type="primary" htmlType="submit" loading={adicionar.isPending} icon={<IconeFa nome="plus" />}>Adicionar</Button>
            </div>
          </Form>
        </Card>
      )}
      <Card title="Listagem de Categorias" extra={podeGerir && <Button icon={<IconeFa nome="file-import" />} onClick={() => setImportar('categorias')}>Importar</Button>}>
      <TabelaComModos<Categoria>
        idVista="categorias"
        rowKey="id"
        loading={consulta.isFetching}
        dataSource={consulta.data ?? []}
        columns={[{ title: 'Nome', dataIndex: 'nome', total: 'contagem' }]}
        impressao={{ titulo: 'Categorias de produtos' }}
        accoes={(c) => [
          podeGerir && { icone: 'fa-edit', titulo: 'Editar', onClick: () => setEdicao(c) },
          podeEliminar && { icone: 'fa-trash', titulo: 'Eliminar', variante: 'perigo', cheio: true, confirmar: { titulo: `Eliminar a categoria ${c.nome}?`, texto: 'É recusado se houver produtos nesta categoria.' }, onClick: () => eliminar.mutateAsync({ metodo: 'delete', url: `/logistica/categorias-produtos/${c.id}` }) },
        ]}
        vazio={{ icone: 'tags', titulo: 'Sem categorias', texto: 'Acrescente a primeira categoria acima.' }}
      />
      <Modal title="Editar categoria" open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(v) => edicao && gravar.mutate({ metodo: 'put', url: `/logistica/categorias-produtos/${edicao.id}`, dados: v })}>
          <Form.Item name="nome" label="Nome da Categoria" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}><Input /></Form.Item>
        </Form>
      </Modal>
      </Card>
    </>
  );
}
