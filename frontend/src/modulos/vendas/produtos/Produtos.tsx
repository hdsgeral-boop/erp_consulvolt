import { Alert, Button, Card, Checkbox, Col, Descriptions, Form, Input, InputNumber, Row, Select, Space, Tabs, Tag, Tooltip, theme } from 'antd';
import { ModalImportarProdutos, type EntidadeImportacao } from './ModalImportarProdutos';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type Key } from 'react';
import { useSearchParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { AlternarFormulario, PainelLinhas, useVistaFormulario } from '@/componentes/formularios';
import { BotoesExportar } from '@/componentes/impressao';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { CONTAS_CATEGORIA, NIVEIS, camposProdutoLinhas, corpoProduto, corpoProdutoLinha, erroNiveis, produtoParaFormulario, type Categoria, type ProdutoFicha, type ValoresProduto } from './formularioProduto';
import { Categorias, useCategorias } from './Categorias';
import { pedidoFichaTecnica } from './fichaTecnica';
import { CampoImagemProduto, MiniaturaProduto } from './imagemProduto';
import { ModalEditarMassaProdutos } from './ModalEditarMassaProdutos';
import { ServicosPOS } from './ServicosPOS';
import { accoesProduto, type EdicaoProduto } from './regrasProdutos';

/** Produto da listagem (ProdutoResource + imagem). */
type ProdutoLista = ProdutoFicha & { imagem_url?: string | null };

/** Tipos de operação AGT (legado: FacturaAGT.OPERACOES). Vazio: TB se movimenta stock, SG caso contrário. */
export const OPERACOES_FE: Record<string, string> = {
  TB: 'Transmissão de bens', SG: 'Prestação de serviço (geral)', SE: 'Serviços de educação', SS: 'Serviços de saúde',
  STP: 'Transporte de passageiros', SR: 'Serviços sujeitos a royalties', SIF: 'Intermediação financeira ou seguradora',
  SHS: 'Hotelaria e similares', ST: 'Telecomunicações', AS: 'Arrendamento e subarrendamento', QT: 'Quotas', RD: 'Repasse de despesas',
};

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

/**
 * Conta numa célula (legado celulaConta: código ou «—» em cinzento). Ronda 3: a conta vem da categoria; a que ainda vem do
 * próprio produto (antes da conversão) fica assinalada.
 */
const celulaConta = (v: string | null | undefined, origem?: string | null) =>
  v ? (
    <span style={{ fontFamily: 'monospace' }}>
      {v}
      {origem === 'produto' && <Tooltip title="Conta própria do produto (ainda não convertida para a categoria)"><sup aria-label="conta do produto"> *</sup></Tooltip>}
    </span>
  ) : (
    <span style={{ color: 'var(--ant-color-text-quaternary, #94a3b8)' }}>-</span>
  );

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
      title: 'Código', dataIndex: 'codigo', valorImpressao: (r) => r.codigo ?? '', filtroServidor: 'pesquisa',
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
    { title: 'Categoria', dataIndex: 'categoria_produto_id', render: (id: number | null) => (id ? nomeCategoria(id) : <Tag color="warning">Sem categoria</Tag>), valorImpressao: (r) => nomeCategoria(r.categoria_produto_id) },
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
    { title: 'Proveito (61/62)', key: 'c_venda', render: (_, r) => celulaConta(r.contas.venda, r.contas_origem?.venda), valorImpressao: (r) => r.contas.venda ?? '' },
    { title: 'Custo (71/75)', key: 'c_custo', render: (_, r) => celulaConta(r.contas.custo, r.contas_origem?.custo), valorImpressao: (r) => r.contas.custo ?? '' },
    { title: 'Compra (21)', key: 'c_compra', render: (_, r) => celulaConta(r.contas.compra, r.contas_origem?.compra), valorImpressao: (r) => r.contas.compra ?? '' },
    { title: 'Existências (26)', key: 'c_inv', render: (_, r) => celulaConta(r.contas.inventario, r.contas_origem?.inventario), valorImpressao: (r) => r.contas.inventario ?? '' },
    { title: 'IVA liquidado', key: 'c_ivl', render: (_, r) => celulaConta(r.contas.iva_liquidado, r.contas_origem?.iva_liquidado), valorImpressao: (r) => r.contas.iva_liquidado ?? '' },
    { title: 'IVA dedutível', key: 'c_ivd', render: (_, r) => celulaConta(r.contas.iva_dedutivel, r.contas_origem?.iva_dedutivel), valorImpressao: (r) => r.contas.iva_dedutivel ?? '' },
    { title: 'Quebras', key: 'c_queb', render: (_, r) => celulaConta(r.contas.quebra, r.contas_origem?.quebra), valorImpressao: (r) => r.contas.quebra ?? '' },
    { title: 'Sobras', key: 'c_sob', render: (_, r) => celulaConta(r.contas.sobra, r.contas_origem?.sobra), valorImpressao: (r) => r.contas.sobra ?? '' },
    { title: 'Imobilizado', key: 'c_atv', render: (_, r) => <span style={{ background: 'rgba(124, 58, 237, 0.08)', padding: '0 4px' }}>{celulaConta(r.contas.ativo, r.contas_origem?.ativo)}</span>, valorImpressao: (r) => r.contas.ativo ?? '' },
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
  const gereStock = Form.useWatch('movimenta_stock', form);
  const categoriaId = Form.useWatch('categoria_produto_id', form);
  const ficha = useQuery({
    queryKey: ['logistica', 'produto-ficha', edicao.id],
    queryFn: () => obter<ProdutoFicha & { imagem_url?: string | null }>(`/logistica/produtos/${edicao.id}`),
    enabled: !!edicao.id,
  });
  const gravar = useAccao<ProdutoFicha>({ invalidar: [['logistica']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar o produto' });
  // «Grade | Linhas» no cabeçalho do formulário, só em «Novo» (legado: formularios_linhas_config.js › produtos)
  const [vistaPreferida, guardarVista] = useVistaFormulario('vendas.produtos');
  const novo = edicao.modo === 'novo';
  const emLinhas = novo && vistaPreferida === 'linhas';
  const cliente = useQueryClient();
  const camposLinhas = useMemo(() => camposProdutoLinhas(categorias), [categorias]);

  useEffect(() => {
    if (edicao.modo === 'novo') {
      form.resetFields();
      form.setFieldsValue({ taxa_imposto: 14, movimenta_stock: true, caracteristicas: [] });
    } else if (ficha.data) form.setFieldsValue(produtoParaFormulario(ficha.data, edicao.modo === 'copiar'));
  }, [edicao, ficha.data, form]);
  useEffect(() => {
    if (ficha.error) notificarErro(ficha.error, 'Não foi possível obter o produto');
  }, [ficha.error]);

  const titulo = edicao.modo === 'editar' ? `Editar ${ficha.data?.nome ?? 'produto'}` : edicao.modo === 'copiar' ? 'Copiar produto' : 'Novo Produto / Serviço';

  return (
    <Card
      title={titulo}
      style={{ marginBottom: 16, borderTop: '4px solid var(--ant-color-primary, #2563eb)' }}
      loading={!!edicao.id && ficha.isLoading}
      extra={
        <Space>
          {novo && <AlternarFormulario vista={emLinhas ? 'linhas' : 'grade'} aoMudar={guardarVista} />}
          {edicao.modo === 'editar' && ficha.data && (
            <BotoesExportar tamanho="small" textoImprimir="Ficha técnica" obterPedido={() => (ficha.data ? pedidoFichaTecnica(ficha.data, stockActual) : null)} />
          )}
          <Button type="text" aria-label="Fechar" icon={<IconeFa nome="times" />} onClick={aoFechar} />
        </Space>
      }
    >
      {emLinhas && (
        <PainelLinhas
          chave="vendas.produtos"
          campos={camposLinhas}
          nomeRegistos="produtos e serviços"
          nota="Uma linha por produto ou serviço. Quartos, lavandaria e activos imobilizados registam-se na vista em grade (têm campos próprios). Linhas deixadas em branco são ignoradas. Cada linha é gravada com as mesmas regras do formulário."
          gravar={(r) => enviar('post', '/logistica/produtos', corpoProdutoLinha(r))}
          aoConcluir={(r) => {
            if (r.gravadas) void cliente.invalidateQueries({ queryKey: ['logistica'] });
            if (r.gravadas && !r.falhadas) aoFechar();
          }}
        />
      )}
      <div hidden={emLinhas}>
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
                    <Form.Item name="categoria_produto_id" label="Categoria" tooltip="A categoria define as contas contabilísticas do produto." rules={[{ required: true, message: 'Escolha a categoria (define as contas contabilísticas).' }]}>
                      <Select showSearch optionFilterProp="label" options={categorias.map((c) => ({ value: c.id, label: c.nome }))} />
                    </Form.Item>
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
              key: 'ficha',
              label: 'Ficha técnica',
              forceRender: true,
              children: <SeparadorFichaTecnica />,
            },
            ...(gereStock
              ? [{
                  key: 'niveis',
                  label: 'Níveis de stock',
                  forceRender: true,
                  children: <SeparadorNiveis />,
                }]
              : []),
            {
              key: 'contas',
              label: 'Contabilidade',
              forceRender: true,
              children: <ContasHerdadas categoria={categorias.find((c) => c.id === categoriaId)} ficha={edicao.modo === 'editar' ? ficha.data : undefined} activo={!!activo} />,
            },
            ...(edicao.modo === 'editar' && edicao.id
              ? [{ key: 'pos', label: 'Serviços do POS', children: <ServicosPOS produtoId={Number(edicao.id)} /> }]
              : []),
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
      </div>
    </Card>
  );
}

/** Separador «Ficha técnica»: especificações do produto (impressas em «Ficha técnica», no cabeçalho). */
function SeparadorFichaTecnica() {
  return (
    <Row gutter={16}>
      <Col xs={24} md={12}><Form.Item name="marca" label="Marca" rules={[{ max: 100 }]}><Input /></Form.Item></Col>
      <Col xs={24} md={12}><Form.Item name="modelo" label="Modelo" rules={[{ max: 100 }]}><Input /></Form.Item></Col>
      <Col xs={8} md={4}><Form.Item name="comprimento" label="Comprimento"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
      <Col xs={8} md={4}><Form.Item name="largura" label="Largura"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
      <Col xs={8} md={4}><Form.Item name="altura" label="Altura"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
      <Col xs={8} md={4}>
        <Form.Item name="unidade_dimensao" label="Unid. dimensão">
          <Select allowClear options={['mm', 'cm', 'm'].map((x) => ({ value: x, label: x }))} />
        </Form.Item>
      </Col>
      <Col xs={8} md={4}><Form.Item name="peso_liquido" label="Peso líquido (kg)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
      <Col xs={8} md={4}><Form.Item name="peso_bruto" label="Peso bruto (kg)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
      <Col span={24}>
        <Form.Item label="Composição / características">
          <Form.List name="caracteristicas">
            {(campos, { add, remove }) => (
              <Space direction="vertical" style={{ width: '100%' }}>
                {campos.map(({ key, name }) => (
                  <Space.Compact key={key} style={{ width: '100%' }}>
                    <Form.Item name={[name, 'chave']} noStyle rules={[{ max: 100 }]}><Input placeholder="Característica (ex.: Potência)" style={{ width: '40%' }} aria-label={`Característica ${name + 1}`} /></Form.Item>
                    <Form.Item name={[name, 'valor']} noStyle rules={[{ max: 500 }]}><Input placeholder="Valor (ex.: 1,5 kW)" style={{ width: '55%' }} aria-label={`Valor da característica ${name + 1}`} /></Form.Item>
                    <Button aria-label={`Retirar característica ${name + 1}`} icon={<IconeFa nome="times" />} onClick={() => remove(name)} />
                  </Space.Compact>
                ))}
                <Button type="dashed" icon={<IconeFa nome="plus" />} onClick={() => add({ chave: '', valor: '' })}>Acrescentar característica</Button>
              </Space>
            )}
          </Form.List>
        </Form.Item>
      </Col>
      <Col span={24}><Form.Item name="observacoes_tecnicas" label="Observações" rules={[{ max: 5000 }]}><Input.TextArea rows={3} /></Form.Item></Col>
    </Row>
  );
}

/** Separador «Níveis de stock»: mínimo ≤ segurança ≤ ponto de encomenda ≤ máximo (o servidor volta a validar). */
function SeparadorNiveis() {
  const regra = ({ getFieldsValue }: { getFieldsValue: (n: string[]) => Record<string, number | null> }) => ({
    validator: async () => {
      const erro = erroNiveis(getFieldsValue(NIVEIS.map(([k]) => k)));
      return erro ? Promise.reject(new Error(erro)) : undefined;
    },
  });
  return (
    <>
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 16 }}
        message="Regras de notificação (stock somado de todos os armazéns)"
        description="Abaixo do mínimo ou do stock de segurança: alerta crítico. Stock + encomendas em curso no ponto de encomenda ou abaixo: sugestão de reposição até ao máximo (Armazém › Níveis de stock). Acima do máximo: alerta de excesso."
      />
      <Row gutter={16}>
        {NIVEIS.map(([k, rotulo]) => (
          <Col xs={12} md={6} key={k}>
            <Form.Item name={k} label={rotulo} dependencies={NIVEIS.map(([x]) => x).filter((x) => x !== k)} rules={[regra]}>
              <InputNumber min={0} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        ))}
      </Row>
    </>
  );
}

/** Separador «Contabilidade»: as contas vêm da categoria (só leitura aqui; alteram-se em Vendas › Categorias). */
function ContasHerdadas({ categoria, ficha, activo }: { categoria?: Categoria; ficha?: ProdutoFicha; activo: boolean }) {
  const { token } = theme.useToken();
  const contas = CONTAS_CATEGORIA.filter((c) => activo || c.chave !== 'ativo');
  return (
    <>
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 16 }}
        message="As contas contabilísticas definem-se na categoria do produto"
        description={categoria ? `Categoria «${categoria.nome}». Para mudar uma conta, edite a categoria em Vendas › Categorias (afecta todos os produtos da categoria).` : 'Escolha a categoria no separador Geral.'}
      />
      <Descriptions size="small" bordered column={{ xs: 1, md: 2 }}>
        {contas.map((c) => {
          const daCategoria = (categoria as unknown as Record<string, string | null> | undefined)?.[c.campo] ?? null;
          // antes da conversão, um produto antigo pode ainda ter conta própria (usada quando a categoria não a define)
          const doProduto = !daCategoria && ficha?.contas_origem?.[c.chave] === 'produto' ? ficha.contas[c.chave] : null;
          return (
            <Descriptions.Item key={c.campo} label={c.rotulo}>
              {daCategoria ? <span style={{ fontFamily: 'monospace' }}>{daCategoria}</span>
                : doProduto ? <Tooltip title="Conta própria do produto (anterior à passagem das contas para as categorias)"><span style={{ fontFamily: 'monospace' }}>{doProduto} *</span></Tooltip>
                  : <span style={{ color: token.colorTextTertiary }}>Conta por omissão da configuração</span>}
            </Descriptions.Item>
          );
        })}
      </Descriptions>
    </>
  );
}
