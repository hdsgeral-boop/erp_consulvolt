import { Button, Card, Checkbox, Col, Drawer, Form, Input, InputNumber, Modal, Row, Select, Space, Table, Tabs, Tag, Tooltip } from 'antd';
import { CopyOutlined, DeleteOutlined, EditOutlined, ImportOutlined, LockOutlined, PlusOutlined, UnlockOutlined } from '@ant-design/icons';
import { ModalImportarProdutos, type EntidadeImportacao } from './ModalImportarProdutos';
import { useQuery } from '@tanstack/react-query';

import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, larguraGaveta, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { corpoProduto, produtoParaFormulario, type ProdutoFicha, type ValoresProduto } from './formularioProduto';

interface Categoria {
  id: number;
  nome: string;
}

/** Tipos de operação AGT (legado: FacturaAGT.OPERACOES). Vazio: TB se movimenta stock, SG caso contrário. */
export const OPERACOES_FE: Record<string, string> = {
  TB: 'Transmissão de bens', SG: 'Prestação de serviço (geral)', SE: 'Serviços de educação', SS: 'Serviços de saúde',
  STP: 'Transporte de passageiros', SR: 'Serviços sujeitos a royalties', SIF: 'Intermediação financeira ou seguradora',
  SHS: 'Hotelaria e similares', ST: 'Telecomunicações', AS: 'Arrendamento e subarrendamento', QT: 'Quotas', RD: 'Repasse de despesas',
};

function useCategorias() {
  return useQuery({ queryKey: ['logistica', 'categorias'], queryFn: () => obter<Categoria[]>('/logistica/categorias-produtos'), staleTime: 300_000 });
}

/** Vendas › Produtos e serviços (ecrã vendas_produtos): produtos, serviços, quartos e lavandaria; categorias. */
export default function Produtos() {
  const { pode } = useSessao();
  const [importar, setImportar] = useState<EntidadeImportacao | null>(null);
  return (
    <>
      <CabecalhoPagina
        titulo="Produtos e serviços"
        subtitulo="Catálogo usado na facturação, POS, compras e armazém"
        accoes={pode('vendas_produtos_gerir') && (
          <Space wrap>
            <Button icon={<ImportOutlined />} onClick={() => setImportar('produtos')}>Importar produtos</Button>
            <Button icon={<ImportOutlined />} onClick={() => setImportar('categorias')}>Importar categorias</Button>
          </Space>
        )}
      />
      <ModalImportarProdutos entidade={importar} aoFechar={() => setImportar(null)} />
      <Tabs
        items={[
          { key: 'produtos', label: 'Produtos', children: <ListaProdutos /> },
          { key: 'categorias', label: 'Categorias', children: <Categorias /> },
        ]}
      />
    </>
  );
}

function ListaProdutos() {
  const { pode } = useSessao();
  const podeGerir = pode('vendas_produtos_gerir');
  const podeEliminar = pode('vendas_dados_del');
  const categorias = useCategorias();
  const [pesquisa, setPesquisa] = useState('');
  const [categoria, setCategoria] = useState<number>();
  const [bloqueado, setBloqueado] = useState<string>();
  const [stock, setStock] = useState<string>();
  const [edicao, setEdicao] = useState<{ modo: 'novo' | 'editar' | 'copiar'; id?: number } | null>(null);

  const bloquear = useAccao({ invalidar: [['logistica']] });
  const eliminar = useAccao({ invalidar: [['logistica']] });
  const nomeCategoria = (id: number | null) => categorias.data?.find((c) => c.id === id)?.nome ?? '—';
  const pequeno = useEcraPequeno();

  const colunas: ColunaApi<ProdutoFicha>[] = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => <strong>{v || '—'}</strong> },
    { title: 'Nome', dataIndex: 'nome' },
    { title: 'Categoria', dataIndex: 'categoria_produto_id', responsive: ['md'], render: nomeCategoria },
    { title: 'Preço (Kz)', dataIndex: 'preco_unitario', align: 'right', render: (v: string | null) => formatarKz(v) },
    { title: 'IVA %', dataIndex: 'taxa_imposto', align: 'right', responsive: ['sm'], valorImpressao: (r) => `${formatarNumero(r.taxa_imposto)}${r.codigo_isencao_fe ? ` · ${r.codigo_isencao_fe}` : ''}`, render: (v: string | null, r) => (r.codigo_isencao_fe ? <Tooltip title="Motivo de isenção AGT">{formatarNumero(v)} · {r.codigo_isencao_fe}</Tooltip> : formatarNumero(v)) },
    {
      title: 'Tipo',
      key: 'tipo',
      responsive: ['lg'],
      valorImpressao: (r) => [r.movimenta_stock && 'Stock', r.e_servico && 'Serviço', r.e_quarto && 'Quarto', r.lavandaria && 'Lavandaria', r.e_ativo_imobilizado && 'Activo'].filter(Boolean).join(', '),
      render: (_, r) => (
        <Space size={4} wrap>
          {r.movimenta_stock && <Tag color="blue">Stock</Tag>}
          {r.e_servico && <Tag color="purple">Serviço</Tag>}
          {r.e_quarto && <Tag color="gold">Quarto</Tag>}
          {r.lavandaria && <Tag color="cyan">Lavandaria</Tag>}
          {r.e_ativo_imobilizado && <Tag>Activo</Tag>}
        </Space>
      ),
    },
    { title: 'Conta de venda', key: 'conta', responsive: ['lg'], render: (_, r) => r.contas.venda || '—' },
    { title: 'Estado', dataIndex: 'bloqueado', responsive: ['sm'], render: (b: boolean) => (b ? <Tag color="red">Bloqueado</Tag> : <Tag color="green">Activo</Tag>) },
    {
      title: '',
      key: 'accoes',
      align: 'right',
      render: (_, r) => (
        <Space wrap={false}>
          {podeGerir && (
            <>
              <Tooltip title="Editar"><Button size="small" icon={<EditOutlined />} onClick={() => setEdicao({ modo: 'editar', id: r.id })} /></Tooltip>
              <Tooltip title="Copiar"><Button size="small" icon={<CopyOutlined />} onClick={() => setEdicao({ modo: 'copiar', id: r.id })} /></Tooltip>
              <Tooltip title={r.bloqueado ? 'Desbloquear' : 'Bloquear'}>
                <Button size="small" icon={r.bloqueado ? <UnlockOutlined /> : <LockOutlined />} loading={bloquear.isPending && bloquear.variables?.url.includes(`/${r.id}/`)} onClick={() => bloquear.mutate({ url: `/logistica/produtos/${r.id}/bloquear` })} />
              </Tooltip>
            </>
          )}
          {podeEliminar && (
            <Tooltip title="Eliminar">
              <Button
                size="small"
                danger
                icon={<DeleteOutlined />}
                onClick={() =>
                  Modal.confirm({
                    title: `Eliminar ${r.nome}?`,
                    content: 'É recusado se o produto tiver documentos ou movimentos (nesse caso, bloqueie-o).',
                    okText: 'Eliminar',
                    okButtonProps: { danger: true },
                    cancelText: 'Cancelar',
                    onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/logistica/produtos/${r.id}` }),
                  })
                }
              />
            </Tooltip>
          )}
        </Space>
      ),
    },
  ];

  return (
    <Card>
      <BarraFiltros accoes={podeGerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao({ modo: 'novo' })}>Novo produto</Button>}>
          <Input.Search placeholder="Código ou nome" allowClear style={{ width: 260, maxWidth: '100%' }} onSearch={setPesquisa} />
          <Select placeholder="Categoria" allowClear style={{ width: 200, maxWidth: '100%' }} value={categoria} onChange={setCategoria} options={(categorias.data ?? []).map((c) => ({ value: c.id, label: c.nome }))} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={bloqueado} onChange={setBloqueado} options={[{ value: '0', label: 'Activos' }, { value: '1', label: 'Bloqueados' }]} />
          <Select placeholder="Stock" allowClear style={{ width: 190 }} value={stock} onChange={setStock} options={[{ value: '1', label: 'Movimentam stock' }, { value: '0', label: 'Não movimentam stock' }]} />
      </BarraFiltros>
      <TabelaApi<ProdutoFicha>
        url="/logistica/produtos"
        chaveConsulta={['logistica', 'produtos']}
        filtros={{ pesquisa, categoria_produto_id: categoria, bloqueado, movimenta_stock: stock }}
        columns={colunas}
        size={pequeno ? 'small' : 'middle'}
        impressao={{
          titulo: 'Lista de produtos e serviços',
          filtros: [
            pesquisa && `Pesquisa: ${pesquisa}`,
            categoria !== undefined && `Categoria: ${nomeCategoria(categoria)}`,
            bloqueado && (bloqueado === '1' ? 'Bloqueados' : 'Activos'),
            stock && (stock === '1' ? 'Movimentam stock' : 'Não movimentam stock'),
          ],
        }}
        onRow={(r) => ({ onDoubleClick: () => podeGerir && setEdicao({ modo: 'editar', id: r.id }) })}
      />
      <FormularioProduto edicao={edicao} aoFechar={() => setEdicao(null)} categorias={categorias.data ?? []} />
    </Card>
  );
}

function FormularioProduto({ edicao, aoFechar, categorias }: { edicao: { modo: 'novo' | 'editar' | 'copiar'; id?: number } | null; aoFechar: () => void; categorias: Categoria[] }) {
  const [form] = Form.useForm<ValoresProduto>();
  const eQuarto = Form.useWatch('e_quarto', form);
  const lavandaria = Form.useWatch('lavandaria_ativa', form);
  const activo = Form.useWatch('e_ativo_imobilizado', form);
  const ficha = useQuery({
    queryKey: ['logistica', 'produto-ficha', edicao?.id],
    queryFn: () => obter<ProdutoFicha>(`/logistica/produtos/${edicao?.id}`),
    enabled: !!edicao?.id,
  });
  const gravar = useAccao<ProdutoFicha>({ invalidar: [['logistica']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar o produto' });

  useEffect(() => {
    if (!edicao) return;
    if (edicao.modo === 'novo') {
      form.resetFields();
      form.setFieldsValue({ taxa_imposto: 14, movimenta_stock: true });
    } else if (ficha.data) form.setFieldsValue(produtoParaFormulario(ficha.data, edicao.modo === 'copiar'));
  }, [edicao, ficha.data, form]);
  useEffect(() => {
    if (ficha.error) notificarErro(ficha.error, 'Não foi possível obter o produto');
  }, [ficha.error]);

  const titulo = edicao?.modo === 'editar' ? `Editar ${ficha.data?.nome ?? 'produto'}` : edicao?.modo === 'copiar' ? 'Copiar produto' : 'Novo produto';
  const conta = (nome: keyof ValoresProduto, rotulo: string, prefixo?: string) => (
    <Col xs={24} md={12}>
      <Form.Item name={nome} label={rotulo}>
        <SeletorConta prefixo={prefixo} />
      </Form.Item>
    </Col>
  );

  return (
    <Drawer
      title={titulo}
      open={!!edicao}
      onClose={aoFechar}
      width={larguraGaveta(760)}
      loading={!!edicao?.id && ficha.isLoading}
      destroyOnHidden
      extra={
        <Space wrap>
          <Button onClick={aoFechar}>Cancelar</Button>
          <Button type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>
        </Space>
      }
    >
      <Form<ValoresProduto>
        form={form}
        layout="vertical"
        onFinish={(v) =>
          gravar.mutate(
            edicao?.modo === 'editar' && edicao.id ? { metodo: 'put', url: `/logistica/produtos/${edicao.id}`, dados: corpoProduto(v) } : { url: '/logistica/produtos', dados: corpoProduto(v) },
          )
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
                  <Col xs={24} md={8}>
                    <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { max: 50 }]}>
                      <Input />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={16}>
                    <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                      <Input />
                    </Form.Item>
                  </Col>
                  <Col xs={24} md={10}>
                    <Form.Item name="categoria_produto_id" label="Categoria">
                      <Select allowClear options={categorias.map((c) => ({ value: c.id, label: c.nome }))} />
                    </Form.Item>
                  </Col>
                  <Col xs={12} md={8}>
                    <Form.Item name="preco_unitario" label="Preço de venda (Kz, s/ IVA)">
                      <InputNumber min={0} precision={2} style={{ width: '100%' }} />
                    </Form.Item>
                  </Col>
                  <Col xs={12} md={6}>
                    <Form.Item name="taxa_imposto" label="IVA %">
                      <InputNumber min={0} max={100} style={{ width: '100%' }} />
                    </Form.Item>
                  </Col>
                  <Col span={24}>
                    <Space wrap size="large">
                      <Form.Item name="movimenta_stock" valuePropName="checked" noStyle><Checkbox>Movimenta stock</Checkbox></Form.Item>
                      <Form.Item name="e_servico" valuePropName="checked" noStyle><Checkbox>Serviço</Checkbox></Form.Item>
                      <Form.Item name="e_quarto" valuePropName="checked" noStyle><Checkbox>Quarto (hotelaria)</Checkbox></Form.Item>
                      <Form.Item name="lavandaria_ativa" valuePropName="checked" noStyle><Checkbox>Serviço de lavandaria</Checkbox></Form.Item>
                      <Form.Item name="e_ativo_imobilizado" valuePropName="checked" noStyle><Checkbox>Activo imobilizado</Checkbox></Form.Item>
                    </Space>
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
                  {conta('codigo_conta', 'Proveitos (venda)', '6')}
                  {conta('conta_custo', 'Custo (CMV)', '7')}
                  {conta('conta_compra', 'Compras', '2')}
                  {conta('conta_inventario', 'Inventário', '2')}
                  {conta('conta_iva_liquidado', 'IVA liquidado', '34')}
                  {conta('conta_iva_dedutivel', 'IVA dedutível', '34')}
                  {conta('conta_quebra', 'Quebras de inventário')}
                  {conta('conta_sobra', 'Sobras de inventário')}
                  {activo && conta('conta_ativo', 'Conta do activo', '1')}
                </Row>
              ),
            },
            {
              key: 'fe',
              label: 'Facturação electrónica',
              forceRender: true,
              children: (
                <Row gutter={16}>
                  <Col xs={12} md={6}>
                    <Form.Item name="unidade_fe" label="Unidade" rules={[{ max: 10 }]}>
                      <Input placeholder="UN" />
                    </Form.Item>
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
            ...(eQuarto
              ? [
                  {
                    key: 'hotelaria',
                    label: 'Hotelaria',
                    forceRender: true,
                    children: (
                      <Row gutter={16}>
                        <Col xs={24} md={8}><Form.Item name="preco_por_hora" label="Preço por hora (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
                        <Col xs={24} md={8}><Form.Item name="preco_por_dia" label="Preço por dia (Kz)"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
                        <Col xs={24} md={8}><Form.Item name="horas_minimas" label="Horas mínimas"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
                      </Row>
                    ),
                  },
                ]
              : []),
            ...(lavandaria
              ? [
                  {
                    key: 'lavandaria',
                    label: 'Lavandaria',
                    forceRender: true,
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
                  },
                ]
              : []),
          ]}
        />
      </Form>
    </Drawer>
  );
}

function Categorias() {
  const { pode } = useSessao();
  const podeGerir = pode('vendas_produtos_gerir');
  const podeEliminar = pode('vendas_dados_del');
  const consulta = useCategorias();
  const [edicao, setEdicao] = useState<Categoria | 'nova' | null>(null);
  const [form] = Form.useForm<{ nome: string }>();
  const gravar = useAccao({ invalidar: [['logistica', 'categorias']], aoSucesso: () => setEdicao(null) });
  const eliminar = useAccao({ invalidar: [['logistica', 'categorias']] });

  useEffect(() => {
    if (edicao) form.setFieldsValue({ nome: edicao === 'nova' ? '' : edicao.nome });
  }, [edicao, form]);

  return (
    <Card
      extra={
        <Space wrap>
          {podeGerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>Nova categoria</Button>}
          <BotoesExportar
            desactivado={!consulta.data?.length}
            obterPedido={() => ({ titulo: 'Categorias de produtos', conteudo: tabelaHtml({ colunas: [{ titulo: 'Categoria', valor: (c: Categoria) => c.nome }], linhas: consulta.data ?? [] }) })}
          />
        </Space>
      }
    >
      <Table<Categoria>
        rowKey="id"
        scroll={scrollTabela()}
        loading={consulta.isFetching}
        dataSource={consulta.data ?? []}
        pagination={{ defaultPageSize: 25 }}
        columns={[
          { title: 'Categoria', dataIndex: 'nome' },
          {
            title: '',
            key: 'accoes',
            align: 'right',
            render: (_, c) => (
              <Space wrap>
                {podeGerir && <Button size="small" icon={<EditOutlined />} onClick={() => setEdicao(c)} aria-label="Editar" />}
                {podeEliminar && (
                  <Button
                    size="small"
                    danger
                    icon={<DeleteOutlined />}
                    aria-label="Eliminar"
                    onClick={() =>
                      Modal.confirm({
                        title: `Eliminar a categoria ${c.nome}?`,
                        okText: 'Eliminar',
                        okButtonProps: { danger: true },
                        cancelText: 'Cancelar',
                        onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/logistica/categorias-produtos/${c.id}` }),
                      })
                    }
                  />
                )}
              </Space>
            ),
          },
        ]}
      />
      <Modal width={larguraModal(480)} title={edicao === 'nova' ? 'Nova categoria' : 'Editar categoria'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onOk={() => form.submit()}>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) => gravar.mutate(edicao && edicao !== 'nova' ? { metodo: 'put', url: `/logistica/categorias-produtos/${edicao.id}`, dados: v } : { url: '/logistica/categorias-produtos', dados: v })}
        >
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
            <Input />
          </Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
