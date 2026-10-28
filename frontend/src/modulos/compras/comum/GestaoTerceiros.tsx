import { Alert, Button, Card, Col, Descriptions, Drawer, Form, Input, Modal, Row, Space, Table, Tag } from 'antd';
import { DeleteOutlined, EditOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState, type Key } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { ModalEdicaoMassaTerceiros, ModalImportarClientes } from './ModaisTerceiros';
import { enviar, obter } from '@/api/cliente';
import { AlternarFormulario, PainelLinhas, useVistaFormulario, type CampoFormulario } from '@/componentes/formularios';
import { emailInvalido } from '@/modulos/vendas/comum/valoresFormulario';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BotaoEditarEmMassa } from '@/componentes/vistas';
import { BarraFiltros, larguraGaveta, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import type { FichaTerceiro } from './referencias';
import { SeletorConta } from './Seletores';
import { SeletorConta as SeletorContaPlano } from '@/modulos/contab/comum/Seletores';
import { ContasBancariasTerceiro } from '@/modulos/contab/tabelasAux/ContasBancariasTerceiro';
import { contaCliente, contaFornecedor, prefixosConta, temPapel } from '@/modulos/contab/tabelasAux/contasTerceiro';
import { BotaoValidarNifAgt } from './ValidarNifAgt';

type Papel = 'CLIENTE' | 'FORNECEDOR';

interface Endereco {
  id: number;
  [chave: string]: unknown;
}

/** Títulos do legado: «Listagem de Clientes (Terceiros)» (js/ui_sales.js:428) e «Base de Dados de Fornecedores» (js/ui_compras_v2.js:678). */
const TEXTOS: Record<Papel, { titulo: string; singular: string; gerir: string[] }> = {
  CLIENTE: { titulo: 'Listagem de Clientes (Terceiros)', singular: 'cliente', gerir: ['vendas_clientes_gerir', 'aux_gerir'] },
  FORNECEDOR: { titulo: 'Base de Dados de Fornecedores', singular: 'fornecedor', gerir: ['compras_forn_gerir', 'aux_gerir'] },
};

/** Conta do papel da ficha (Fase A, contas_terceiro.js): a de cliente nos clientes, a de fornecedor nos fornecedores. */
const contaDaFicha = (papel: Papel, r: Partial<FichaTerceiro>) => (papel === 'CLIENTE' ? contaCliente(r) : contaFornecedor(r));

/** Aviso «também é fornecedor/cliente» da ficha (ui_sales.js:2093-2096, ui_compras_v2.js:2503-2506). */
export function avisoOutroPapel(papel: Papel, r: Partial<FichaTerceiro> | null | undefined): string | null {
  if (!r) return null;
  if (papel === 'CLIENTE' && temPapel(r.tipo, 'FORNECEDOR')) {
    return `Esta entidade também é fornecedor (conta ${contaFornecedor(r) || 'por definir'}). Aqui define-se só a conta de cliente; a de fornecedor mantém-se.`;
  }
  if (papel === 'FORNECEDOR' && temPapel(r.tipo, 'CLIENTE')) {
    return `Esta entidade também é cliente (conta ${contaCliente(r) || 'por definir'}). Aqui define-se só a conta de fornecedor; a de cliente mantém-se.`;
  }
  return null;
}

/** Célula de conta como no legado: código em etiqueta com contorno; sem conta, «-» cinzento. */
function CelulaConta({ codigo }: { codigo: string | null | undefined }) {
  return codigo ? <Tag style={{ fontWeight: 600 }}>{codigo}</Tag> : <span style={{ color: '#cbd5e1' }}>-</span>;
}

/** Conta do papel + etiqueta «+ fornecedor» / «+ cliente» quando a entidade tem o outro papel (ui_sales.js:468, ui_compras_v2.js:708). */
function CelulaContaPapel({ papel, r }: { papel: Papel; r: FichaTerceiro }) {
  const outro = papel === 'CLIENTE' ? temPapel(r.tipo, 'FORNECEDOR') : temPapel(r.tipo, 'CLIENTE');
  return (
    <>
      <CelulaConta codigo={contaDaFicha(papel, r)} />
      {outro && (
        <Tag color="blue" title={papel === 'CLIENTE' ? 'Também é fornecedor — a conta de compras é outra' : 'Também é cliente — a conta de vendas é outra'}>
          {papel === 'CLIENTE' ? '+ fornecedor' : '+ cliente'}
        </Tag>
      )}
    </>
  );
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
      { title: 'Nome', dataIndex: 'nome', ellipsis: true, filtroServidor: 'pesquisa', render: (v: string) => v.trim() },
      { title: 'NIF', dataIndex: 'nif', render: (v) => v || '—' },
      { title: 'Endereço', dataIndex: 'endereco', ellipsis: true, render: (v) => v || '-' },
      { title: 'Email', dataIndex: 'email', render: (v) => v || '-' },
      moeda,
      { title: <span title="Conta contabilística do cliente (classe 31, 37.91 ou 37.2)">Conta contabilística</span>, key: 'conta_cliente', render: (_, r) => <CelulaContaPapel papel="CLIENTE" r={r} />, valorImpressao: (r) => contaCliente(r) || '-' },
      colunaPapeis,
      { title: 'Telefone', dataIndex: 'telefone', render: (v) => v || '—', ocultaPorOmissao: true },
    ];
  }
  const contacto = (r: FichaTerceiro) => [r.endereco, r.telefone, r.email].filter((x) => x && x.trim()).join(' · ');
  return [
    { title: 'Nome / Razão Social', dataIndex: 'nome', ellipsis: true, filtroServidor: 'pesquisa', render: (v: string) => <strong>{v.trim()}</strong> },
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
    { title: <span title="Conta de fornecedor (classe 32, 37.0, 37.1 ou 37.92) — creditada na factura de compra">Conta de fornecedor (32/37.0)</span>, key: 'conta_fornecedor', render: (_, r) => <CelulaContaPapel papel="FORNECEDOR" r={r} />, valorImpressao: (r) => contaFornecedor(r) || '-' },
    { title: <span title="Conta de compras / trânsito usada na recepção (classe 328)">Compras / trânsito (328)</span>, dataIndex: 'conta_compra_transitoria', render: (v: string | null) => <CelulaConta codigo={v} /> },
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
    // Fase A: a ficha grava só a conta do seu papel; a do outro papel mantém-se
    conta_cliente: papel === 'CLIENTE' ? texto(v.conta_cliente ?? v.codigo_conta) : undefined,
    conta_fornecedor: papel === 'FORNECEDOR' ? texto(v.conta_fornecedor ?? v.codigo_conta) : undefined,
    conta_compra_transitoria: papel === 'FORNECEDOR' ? texto(v.conta_compra_transitoria) : undefined,
    codigo_moeda: texto(v.codigo_moeda)?.toUpperCase() ?? null,
    fe_pais: texto(v.fe_pais)?.toUpperCase() ?? null,
  };
}

/**
 * Vista Linhas da ficha de cliente / fornecedor (legado: formularios_linhas_config.js › terceiros — NIF, nome, conta do
 * papel, moeda e endereço), com as regras da grade: nome e conta obrigatórios, conta da classe do papel, moeda ISO de 3
 * letras e país ISO de 2. Cada linha é gravada pelo mesmo POST /terceiros (`corpoTerceiro`).
 */
export function camposTerceiro(papel: Papel): CampoFormulario[] {
  const prefixos = prefixosConta(papel);
  const conta: CampoFormulario = {
    nome: papel === 'CLIENTE' ? 'conta_cliente' : 'conta_fornecedor',
    rotulo: papel === 'CLIENTE' ? 'Conta de cliente (31)' : 'Conta de fornecedor (32/37.0)',
    obrigatorio: true,
    maxLength: 20,
    largura: 160,
    aliases: papel === 'CLIENTE' ? ['Conta_Cliente', 'Conta Cliente', 'Conta'] : ['Conta_Fornecedor', 'Conta Fornecedor', 'Conta'],
    validar: (v) => {
      const c = String(v ?? '').trim();
      return c && !prefixos.some((x) => c.replace(/\./g, '').startsWith(x.replace(/\./g, '')))
        ? `A conta tem de ser da classe ${papel === 'CLIENTE' ? '31, 37.91 ou 37.2' : '32, 37.0, 37.1 ou 37.92'}.`
        : null;
    },
  };
  return [
    { nome: 'nif', rotulo: 'NIF', maxLength: 30, largura: 130 },
    { nome: 'nome', rotulo: papel === 'CLIENTE' ? 'Nome do cliente' : 'Nome / entidade', obrigatorio: true, maxLength: 255, largura: 220, aliases: ['Nome', 'Entidade'] },
    conta,
    ...(papel === 'FORNECEDOR'
      ? [{ nome: 'conta_compra_transitoria', rotulo: 'Conta transitória de compras', maxLength: 20, largura: 150, emLinhas: 'opcional', seccao: 'Contas' } as CampoFormulario]
      : []),
    { nome: 'codigo_moeda', rotulo: 'Moeda', maxLength: 3, largura: 80, omissao: 'AOA', validar: (v) => (String(v ?? '').trim() && !/^[A-Za-z]{3}$/.test(String(v).trim()) ? 'Moeda: código ISO de 3 letras.' : null) },
    { nome: 'endereco', rotulo: 'Endereço', maxLength: 1000, largura: 200, aliases: ['Endereco', 'Morada', 'Rua'] },
    { nome: 'telefone', rotulo: 'Telefone', maxLength: 50, largura: 120 },
    { nome: 'email', rotulo: 'Email', maxLength: 150, largura: 180, aliases: ['E-mail'], validar: emailInvalido },
    { nome: 'fe_pais', rotulo: 'País (ISO)', maxLength: 2, largura: 80, omissao: 'AO', emLinhas: 'opcional', seccao: 'Facturação electrónica', aliases: ['País', 'Pais'],
      validar: (v) => (String(v ?? '').trim() && !/^[A-Za-z]{2}$/.test(String(v).trim()) ? 'País: código ISO de 2 letras (ex.: AO).' : null) },
  ];
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
  // «Grade | Linhas» no cabeçalho da ficha, só em «Novo» (legado: formularios_linhas_config.js › terceiros)
  const [vistaForm, guardarVistaForm] = useVistaFormulario(papel === 'CLIENTE' ? 'vendas.clientes' : 'compras.fornecedores');
  const emLinhas = edicao === 'novo' && vistaForm === 'linhas';
  const camposLinhas = useMemo(() => camposTerceiro(papel), [papel]);
  const cliente = useQueryClient();
  const nifActual = Form.useWatch('nif', form);

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
          extra={
            <Space>
              {edicao === 'novo' && <AlternarFormulario vista={emLinhas ? 'linhas' : 'grade'} aoMudar={guardarVistaForm} />}
              <Button size="small" onClick={() => setEdicao(null)} aria-label="Fechar">×</Button>
            </Space>
          }
        >
          {emLinhas && (
            <PainelLinhas
              chave={papel === 'CLIENTE' ? 'vendas.clientes' : 'compras.fornecedores'}
              campos={camposLinhas}
              nomeRegistos={papel === 'CLIENTE' ? 'clientes' : 'fornecedores'}
              nota={`Uma linha por ${t.singular}. Se a entidade já existir com o outro papel (mesmo NIF), o papel é acrescentado à ficha. Linhas deixadas em branco são ignoradas. Cada linha é gravada com as mesmas regras do formulário.`}
              gravar={(r) => enviar('post', '/terceiros', corpoTerceiro(papel, r as Partial<FichaTerceiro>))}
              aoConcluir={(r) => {
                if (r.gravadas) void cliente.invalidateQueries({ queryKey: ['terceiros'] });
                if (r.gravadas && !r.falhadas) setEdicao(null);
              }}
            />
          )}
          <div hidden={emLinhas}>
          {avisoOutroPapel(papel, edicao === 'novo' ? null : edicao) && (
            <Alert type="info" showIcon style={{ marginBottom: 12 }} message={avisoOutroPapel(papel, edicao === 'novo' ? null : edicao)} />
          )}
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
                  <Input addonAfter={<BotaoValidarNifAgt nif={nifActual} tamanho="small" apenasIcone />} />
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
                <Form.Item
                  name={papel === 'CLIENTE' ? 'conta_cliente' : 'conta_fornecedor'}
                  label={papel === 'CLIENTE' ? 'Conta de cliente (classe 31)' : 'Conta de fornecedor (classe 32 ou 37.0)'}
                  rules={[{ required: true, message: papel === 'CLIENTE' ? 'Indique a conta de cliente (classe 31).' : 'Indique a conta de fornecedor (classe 32 ou 37.0).' }]}
                >
                  <SeletorContaPlano prefixos={prefixosConta(papel)} placeholder={papel === 'CLIENTE' ? 'Ex.: 31.1.1' : 'Ex.: 32.1.1'} allowClear style={{ width: '100%' }} />
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
          </div>
        </Card>
      )}
      {/* título e botões dentro do cartão, como o legado (js/ui_sales.js:425-437) */}
      <Card
        title={t.titulo}
        extra={
          podeGerir && (
            <Space wrap>
              {/* «Editar em massa (N)» do legado (mapeamento_massa.js:310), botões pequenos (btn-sm) */}
              <BotaoEditarEmMassa linhas={linhasSel} chaves={seleccao} opcoes={{ onClick: () => setMassa(linhasSel) }} />
              {papel === 'CLIENTE' && (
                <Button size="small" icon={<IconeFa nome="file-import" />} onClick={() => setImportar(true)}>
                  Importar
                </Button>
              )}
              <Button size="small" type="primary" icon={<IconeFa nome={papel === 'CLIENTE' ? 'user-plus' : 'plus'} />} onClick={() => setEdicao('novo')}>
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
              {temPapel(f.tipo, 'CLIENTE') && (
                <Descriptions.Item label="Conta de cliente">{contaCliente(f) || <span style={{ color: '#b91c1c' }}>por definir</span>}</Descriptions.Item>
              )}
              {temPapel(f.tipo, 'FORNECEDOR') && (
                <Descriptions.Item label="Conta de fornecedor">{contaFornecedor(f) || <span style={{ color: '#b91c1c' }}>por definir</span>}</Descriptions.Item>
              )}
              {f.codigo_conta && <Descriptions.Item label="Conta antiga">{f.codigo_conta}</Descriptions.Item>}
              {f.e_fornecedor && <Descriptions.Item label="Conta transitória de compras">{f.conta_compra_transitoria || '—'}</Descriptions.Item>}
              <Descriptions.Item label="Moeda">{f.codigo_moeda || 'AOA'}</Descriptions.Item>
              <Descriptions.Item label="País (FE)">{f.fe_pais || '—'}</Descriptions.Item>
            </Descriptions>
            <div style={{ marginTop: 16 }}>
              <ContasBancariasTerceiro terceiroId={f.id} podeGerir={podeGerir} />
            </div>
            {(f.enderecos?.length ?? 0) > 0 && (
              <Card size="small" title="Endereços registados (mesmo NIF)" style={{ marginTop: 16 }}>
                <Table<Endereco>
                  size="small"
                  rowKey="id"
                  pagination={false}
                  dataSource={f.enderecos}
                  scroll={scrollTabela()}
                  columns={[
                    // enderecos_terceiros: Rua, Nº Porta, Bairro, Município, Província, País (ui_aux.js:641-649)
                    { title: 'Endereço', render: (_, e) => [e.rua, e.numero_porta ? `n.º ${String(e.numero_porta)}` : null, e.bairro].filter(Boolean).join(', ') || '—' },
                    { title: 'Localidade', render: (_, e) => [e.municipio, e.provincia, e.pais].filter(Boolean).join(', ') || '—' },
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
