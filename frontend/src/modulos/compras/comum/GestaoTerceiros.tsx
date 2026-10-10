import { Button, Card, Col, Descriptions, Drawer, Form, Input, Modal, Row, Space, Table, Tag } from 'antd';
import { DeleteOutlined, EditOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState, type Key } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { ModalEdicaoMassaTerceiros, ModalImportarClientes } from './ModaisTerceiros';
import { obter } from '@/api/cliente';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, larguraGaveta, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import type { FichaTerceiro } from './referencias';
import { SeletorConta } from './Seletores';

type Papel = 'CLIENTE' | 'FORNECEDOR';

interface Endereco {
  id: number;
  [chave: string]: unknown;
}

/** Títulos do legado: «Listagem de Clientes (Terceiros)» (js/ui_sales.js:428) e «Base de Dados de Fornecedores» (js/ui_compras_v2.js:678). */
const TEXTOS: Record<Papel, { titulo: string; singular: string; gerir: string[]; prefixoConta: string }> = {
  CLIENTE: { titulo: 'Listagem de Clientes (Terceiros)', singular: 'cliente', gerir: ['vendas_clientes_gerir', 'aux_gerir'], prefixoConta: '31' },
  FORNECEDOR: { titulo: 'Base de Dados de Fornecedores', singular: 'fornecedor', gerir: ['compras_forn_gerir', 'aux_gerir'], prefixoConta: '32' },
};

/** Célula de conta como no legado: código em etiqueta com contorno; sem conta, «-» cinzento. */
function CelulaConta({ codigo }: { codigo: string | null | undefined }) {
  return codigo ? <Tag style={{ fontWeight: 600 }}>{codigo}</Tag> : <span style={{ color: '#cbd5e1' }}>-</span>;
}

const papeis = (r: FichaTerceiro) => [r.e_cliente && 'Cliente', r.e_fornecedor && 'Fornecedor'].filter(Boolean).join(', ');

/**
 * Colunas das listas do legado. Clientes: NOME, NIF, ENDEREÇO, EMAIL, MOEDA, CONTA CONTABILÍSTICA (+ Papéis e Telefone do
 * sistema novo). Fornecedores: NOME / RAZÃO SOCIAL, NIF, MORADA / CONTACTO, MOEDA, CONTA DE TERCEIRO (32/37.0),
 * COMPRAS / TRÂNSITO (328).
 */
export function colunasTerceiros(papel: Papel): ColunaApi<FichaTerceiro>[] {
  const colunaPapeis: ColunaApi<FichaTerceiro> = {
    title: 'Papéis',
    key: 'papeis',
    ocultaPorOmissao: true, // o legado não mostra os papéis (fica no menu «Colunas»)
    valorImpressao: papeis,
    valorFiltro: papeis,
    render: (_, r) => (
      <Space wrap size={4}>
        {r.e_cliente && <Tag color="blue">Cliente</Tag>}
        {r.e_fornecedor && <Tag color="purple">Fornecedor</Tag>}
        {papel === 'CLIENTE' && !r.e_cliente && r.tem_documentos_venda && <Tag color="orange" title="Tem documentos de venda; ao gravar a ficha passa a ter o papel de cliente">Com vendas</Tag>}
      </Space>
    ),
  };
  const moeda: ColunaApi<FichaTerceiro> = { title: 'Moeda', dataIndex: 'codigo_moeda', render: (v) => v || 'AOA' };
  if (papel === 'CLIENTE') {
    return [
      { title: 'Nome', dataIndex: 'nome', ellipsis: true, render: (v: string) => v.trim() },
      { title: 'NIF', dataIndex: 'nif', render: (v) => v || '—' },
      { title: 'Endereço', dataIndex: 'endereco', ellipsis: true, render: (v) => v || '-' },
      { title: 'Email', dataIndex: 'email', render: (v) => v || '-' },
      moeda,
      { title: 'Conta contabilística', dataIndex: 'codigo_conta', render: (v: string | null) => <CelulaConta codigo={v} /> },
      colunaPapeis,
      { title: 'Telefone', dataIndex: 'telefone', render: (v) => v || '—', ocultaPorOmissao: true },
    ];
  }
  const contacto = (r: FichaTerceiro) => [r.endereco, r.telefone, r.email].filter((x) => x && x.trim()).join(' · ');
  return [
    { title: 'Nome / Razão Social', dataIndex: 'nome', ellipsis: true, render: (v: string) => <strong>{v.trim()}</strong> },
    { title: 'NIF', dataIndex: 'nif', render: (v) => v || '—' },
    {
      title: 'Morada / Contacto', key: 'contacto', ellipsis: true, valorImpressao: (r) => contacto(r) || '-', valorFiltro: contacto,
      render: (_, r) => (
        <>
          {r.endereco || '-'}
          {(r.telefone || r.email) && <span style={{ display: 'block', fontSize: 12, color: '#64748b' }}>{[r.telefone, r.email].filter(Boolean).join(' · ')}</span>}
        </>
      ),
    },
    moeda,
    { title: 'Conta de terceiro (32/37.0)', dataIndex: 'codigo_conta', render: (v: string | null) => <CelulaConta codigo={v} /> },
    { title: 'Compras / trânsito (328)', dataIndex: 'conta_compra_transitoria', render: (v: string | null) => <CelulaConta codigo={v} /> },
    colunaPapeis,
  ];
}

/** Valores do formulário → corpo do pedido (campos vazios vão como null para limpar o valor gravado). */
export function corpoTerceiro(papel: Papel, v: Partial<FichaTerceiro>): Record<string, unknown> {
  const texto = (x: string | null | undefined) => (x && x.trim() ? x.trim() : null);
  return {
    papel,
    nome: texto(v.nome),
    nif: texto(v.nif),
    endereco: texto(v.endereco),
    email: texto(v.email),
    telefone: texto(v.telefone),
    codigo_conta: texto(v.codigo_conta),
    conta_compra_transitoria: papel === 'FORNECEDOR' ? texto(v.conta_compra_transitoria) : undefined,
    codigo_moeda: texto(v.codigo_moeda)?.toUpperCase() ?? null,
    fe_pais: texto(v.fe_pais)?.toUpperCase() ?? null,
  };
}

/**
 * Gestão de clientes (vendas_clientes) ou fornecedores (compras_fornecedores) sobre /api/terceiros.
 * A mesma entidade pode ser cliente e fornecedor: gravar com outro papel acrescenta-o à ficha.
 */
export function GestaoTerceiros({ papel }: { papel: Papel }) {
  const t = TEXTOS[papel];
  const { pode } = useSessao();
  const podeGerir = pode(...t.gerir);
  const podeEliminar = pode('vendas_dados_del', 'aux_eliminar');
  const [pesquisa, setPesquisa] = useState('');
  const [edicao, setEdicao] = useState<FichaTerceiro | 'novo' | null>(null);
  const [vista, setVista] = useState<number | null>(null);
  const [seleccao, setSeleccao] = useState<Key[]>([]);
  const [massa, setMassa] = useState<FichaTerceiro[] | null>(null);
  const [linhasSel, setLinhasSel] = useState<FichaTerceiro[]>([]);
  const [importar, setImportar] = useState(false);
  const [form] = Form.useForm<FichaTerceiro>();
  const pequeno = useEcraPequeno();

  const ficha = useQuery({
    queryKey: ['terceiros', 'ficha-completa', vista],
    queryFn: () => obter<FichaTerceiro & { enderecos?: Endereco[] }>(`/terceiros/${vista}`),
    enabled: vista !== null,
  });

  const gravar = useAccao<FichaTerceiro>({
    invalidar: [['terceiros']],
    aoSucesso: () => setEdicao(null),
    tituloErro: `Não foi possível gravar o ${t.singular}`,
  });
  const eliminar = useAccao({ invalidar: [['terceiros']], aoSucesso: () => setVista(null) });

  useEffect(() => {
    if (edicao === 'novo') form.setFieldsValue({ codigo_moeda: 'AOA', fe_pais: 'AO' } as FichaTerceiro);
    else if (edicao) form.setFieldsValue(edicao);
  }, [edicao, form]);

  const confirmarEliminar = (r: FichaTerceiro) =>
    Modal.confirm({
      title: `Eliminar ${r.nome.trim()}?`,
      content: 'A eliminação é recusada se a entidade tiver documentos ou movimentos.',
      okText: 'Eliminar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/terceiros/${r.id}` }),
    });

  const colunas = colunasTerceiros(papel);
  const f = ficha.data;
  return (
    <>
      {/* ficha num cartão acima da lista, como o legado (showCustomerForm, js/ui_sales.js:2083-2135) */}
      {edicao !== null && (
        <Card
          style={{ borderTop: '4px solid var(--erp-primaria, #2563eb)', marginBottom: '2rem' }}
          title={edicao === 'novo' ? (papel === 'CLIENTE' ? 'Novo Cliente' : 'Novo Fornecedor') : papel === 'CLIENTE' ? 'Editar Cliente' : 'Editar Fornecedor'}
          extra={<Button size="small" onClick={() => setEdicao(null)} aria-label="Fechar">×</Button>}
        >
          <Form<FichaTerceiro>
            form={form}
            layout="vertical"
            preserve={false}
            onFinish={(v) =>
              gravar.mutate(
                edicao === 'novo' || edicao === null
                  ? { metodo: 'post', url: '/terceiros', dados: corpoTerceiro(papel, v) }
                  : { metodo: 'put', url: `/terceiros/${edicao.id}`, dados: corpoTerceiro(papel, v) },
              )
            }
          >
            <Row gutter={16}>
              <Col xs={24} md={16}>
                <Form.Item name="nome" label={papel === 'CLIENTE' ? 'Nome do Cliente' : 'Nome'} rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                  <Input />
                </Form.Item>
              </Col>
              <Col xs={24} md={8}>
                <Form.Item name="nif" label="NIF" rules={[{ max: 30 }]}>
                  <Input />
                </Form.Item>
              </Col>
              <Col xs={24} md={12}>
                <Form.Item name="endereco" label="Endereço" rules={[{ max: 1000 }]}>
                  <Input />
                </Form.Item>
              </Col>
              <Col xs={24} md={12}>
                <Form.Item name="email" label="Email" rules={[{ type: 'email', message: 'Email inválido.' }, { max: 150 }]}>
                  <Input />
                </Form.Item>
              </Col>
              <Col xs={24} md={12}>
                <Form.Item name="telefone" label="Telefone" rules={[{ max: 50 }]}>
                  <Input />
                </Form.Item>
              </Col>
              <Col xs={24} md={12}>
                <Form.Item name="codigo_conta" label="Conta Contabilística (Plano de Contas)" rules={[{ required: true, message: 'Associe a conta contabilística da entidade.' }]}>
                  <SeletorConta prefixo={t.prefixoConta} placeholder={`Ex.: ${t.prefixoConta}121…`} />
                </Form.Item>
              </Col>
              {papel === 'FORNECEDOR' && (
                <Col xs={24} md={12}>
                  <Form.Item name="conta_compra_transitoria" label="Conta transitória de compras" tooltip="Conta 3.2.8 usada na recepção de mercadoria antes da factura.">
                    <SeletorConta prefixo="328" placeholder="Ex.: 3281" />
                  </Form.Item>
                </Col>
              )}
              <Col xs={12} md={6}>
                <Form.Item name="codigo_moeda" label="Moeda por omissão" rules={[{ pattern: /^[A-Za-z]{3}$/, message: 'Código ISO de 3 letras.' }]}>
                  <Input maxLength={3} style={{ textTransform: 'uppercase' }} />
                </Form.Item>
              </Col>
              <Col xs={12} md={6}>
                <Form.Item name="fe_pais" label="País (código ISO, facturação electrónica)" rules={[{ pattern: /^[A-Za-z]{2}$/, message: 'Código ISO de 2 letras (ex.: AO).' }]}>
                  <Input maxLength={2} placeholder="AO" style={{ textTransform: 'uppercase' }} />
                </Form.Item>
              </Col>
            </Row>
          </Form>
          <Space>
            <Button type="primary" loading={gravar.isPending} onClick={() => form.submit()}>
              Guardar
            </Button>
            <Button onClick={() => setEdicao(null)}>Cancelar</Button>
          </Space>
        </Card>
      )}
      {/* título e botões dentro do cartão, como o legado (js/ui_sales.js:425-437) */}
      <Card
        title={t.titulo}
        extra={
          podeGerir && (
            <Space wrap>
              <Button icon={<IconeFa nome="edit" />} disabled={!linhasSel.length} onClick={() => setMassa(linhasSel)}>
                Editar em massa ( {linhasSel.length} )
              </Button>
              {papel === 'CLIENTE' && (
                <Button icon={<IconeFa nome="file-import" />} onClick={() => setImportar(true)}>
                  Importar
                </Button>
              )}
              <Button type="primary" icon={<IconeFa nome={papel === 'CLIENTE' ? 'user-plus' : 'plus'} />} onClick={() => setEdicao('novo')}>
                {papel === 'CLIENTE' ? '+ Novo Cliente' : 'Novo Fornecedor'}
              </Button>
            </Space>
          )
        }
      >
        <BarraFiltros>
          <Input.Search placeholder="Nome ou NIF" allowClear style={{ width: 300, maxWidth: '100%' }} onSearch={setPesquisa} />
        </BarraFiltros>
        <TabelaApi<FichaTerceiro>
          url="/terceiros"
          chaveConsulta={['terceiros', 'lista', papel]}
          filtros={{ papel, pesquisa }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{ titulo: t.titulo, filtros: [pesquisa && `Pesquisa: ${pesquisa}`] }}
          seleccao={podeGerir ? { semBarra: true, chaves: seleccao, aoMudar: (k, linhas) => { setSeleccao(k); setLinhasSel(linhas); } } : undefined}
          accoes={(r) => [
            { icone: 'edit', titulo: 'Editar', oculto: !podeGerir, onClick: () => setEdicao(r) },
            { icone: 'trash', titulo: 'Eliminar', variante: 'perigo', cheio: true, oculto: !podeEliminar, onClick: () => confirmarEliminar(r) },
          ]}
          onRow={(r) => ({ onClick: () => setVista(r.id), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalEdicaoMassaTerceiros papel={papel} fichas={massa} aoFechar={() => setMassa(null)} aoConcluir={() => { setSeleccao([]); setLinhasSel([]); }} />
      {papel === 'CLIENTE' && <ModalImportarClientes aberto={importar} aoFechar={() => setImportar(false)} />}

      <Drawer
        title={f?.nome?.trim() ?? 'Ficha'}
        open={vista !== null}
        onClose={() => setVista(null)}
        width={larguraGaveta(560)}
        loading={ficha.isLoading}
        extra={
          f && (
            <Space wrap>
              {podeGerir && <Button icon={<EditOutlined />} onClick={() => setEdicao(f)}>Editar</Button>}
              {podeEliminar && <Button danger icon={<DeleteOutlined />} onClick={() => confirmarEliminar(f)}>Eliminar</Button>}
            </Space>
          )
        }
      >
        {f && (
          <>
            <Descriptions column={1} size="small" bordered>
              <Descriptions.Item label="NIF">{f.nif || '—'}</Descriptions.Item>
              <Descriptions.Item label="Tipo">{f.tipo ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Endereço">{f.endereco || '—'}</Descriptions.Item>
              <Descriptions.Item label="Telefone">{f.telefone || '—'}</Descriptions.Item>
              <Descriptions.Item label="Email">{f.email || '—'}</Descriptions.Item>
              <Descriptions.Item label="Conta contabilística">{f.codigo_conta || '—'}</Descriptions.Item>
              {f.e_fornecedor && <Descriptions.Item label="Conta transitória de compras">{f.conta_compra_transitoria || '—'}</Descriptions.Item>}
              <Descriptions.Item label="Moeda">{f.codigo_moeda || 'AOA'}</Descriptions.Item>
              <Descriptions.Item label="País (FE)">{f.fe_pais || '—'}</Descriptions.Item>
            </Descriptions>
            {(f.enderecos?.length ?? 0) > 0 && (
              <Card size="small" title="Endereços registados (mesmo NIF)" style={{ marginTop: 16 }}>
                <Table<Endereco>
                  size="small"
                  rowKey="id"
                  pagination={false}
                  dataSource={f.enderecos}
                  scroll={scrollTabela()}
                  columns={[
                    { title: 'Endereço', render: (_, e) => String(e.endereco ?? e.morada ?? e.descricao ?? '—') },
                    { title: 'Localidade', render: (_, e) => String(e.localidade ?? e.cidade ?? '—') },
                  ]}
                />
              </Card>
            )}
          </>
        )}
      </Drawer>

    </>
  );
}
