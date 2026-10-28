import { Alert, Button, Col, Form, Input, Modal, Row, Select, Space, Tag, Typography, theme } from 'antd';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { descarregar, enviar, obter } from '@/api/cliente';
import { SeccaoFormulario, useVistaFormulario, type CampoFormulario } from '@/componentes/formularios';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import type { FichaTerceiro } from '@/modulos/compras/comum/referencias';
import { BotaoValidarNifAgt } from '@/modulos/compras/comum/ValidarNifAgt';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { ModalImportar } from '../comum/ficheiros';
import { SeletorConta } from '../comum/Seletores';
import { CartaoGradeLinhas } from './CartaoGradeLinhas';
import { ContasBancariasTerceiro } from './ContasBancariasTerceiro';
import {
  OPCOES_TIPO,
  camposConta,
  contaCliente,
  contaFornecedor,
  prefixosConta,
  rotuloTipo,
  temPapel,
  validarContasTerceiro,
  type MoradaTerceiro,
  type PapelConta,
  type TipoTerceiro,
} from './contasTerceiro';

interface ValoresTerceiro extends MoradaTerceiro {
  nif?: string;
  nome?: string;
  tipo: TipoTerceiro;
  codigo_moeda?: string;
  conta_cliente?: string;
  conta_fornecedor?: string;
}

const CAMPOS_MORADA = ['rua', 'numero_porta', 'bairro', 'municipio', 'provincia', 'pais'] as const;
const texto = (x: string | null | undefined) => (x && x.trim() ? x.trim() : null);

/** Valores do formulário → corpo de POST/PUT /terceiros (o tipo escolhido substitui o anterior, como saveThirdParty). */
export function corpoTerceiroAux(v: ValoresTerceiro): Record<string, unknown> {
  const { cliente, fornecedor } = camposConta(v.tipo);
  return {
    papel: v.tipo,
    substituir_tipo: true,
    nif: texto(v.nif),
    nome: texto(v.nome),
    codigo_moeda: texto(v.codigo_moeda)?.toUpperCase() ?? null,
    // só se enviam as contas que o tipo pede; a outra fica como estava (um fornecedor que deixa de ser cliente mantém a conta 31)
    conta_cliente: cliente ? texto(v.conta_cliente) : undefined,
    conta_fornecedor: fornecedor ? texto(v.conta_fornecedor) : undefined,
    morada: Object.fromEntries(CAMPOS_MORADA.map((c) => [c, texto(v[c])])),
  };
}

const MOEDAS_TERCEIRO = ['AOA', 'USD', 'EUR', 'ZAR', 'CNY'];

/**
 * Colunas da vista Linhas (legado `terceiros`, formularios_linhas_config.js): NIF, Nome, Tipo, a conta de cada papel,
 * Moeda e o endereço (colunas opcionais). Cada linha passa pelas mesmas regras da grade (validarContasTerceiro) e pelo
 * mesmo corpo (corpoTerceiroAux).
 */
export const CAMPOS_TERCEIRO: CampoFormulario[] = [
  { nome: 'nif', rotulo: 'NIF', obrigatorio: true, largura: 130, maxLength: 30, aliases: ['NIF / Identificação', 'Contribuinte'] },
  { nome: 'nome', rotulo: 'Nome / Entidade', obrigatorio: true, largura: 220, maxLength: 255, aliases: ['Nome', 'Entidade'] },
  {
    nome: 'tipo', rotulo: 'Tipo', tipo: 'select', obrigatorio: true, largura: 170, omissao: 'CLIENTE', opcoes: OPCOES_TIPO,
    validar: (_, r) => validarContasTerceiro(r.tipo as string, r.conta_cliente as string, r.conta_fornecedor as string),
  },
  { nome: 'conta_cliente', rotulo: 'Conta de cliente (31)', largura: 150, maxLength: 20, aliases: ['Conta_Cliente', 'Conta Cliente', 'Conta_Contábil', 'Conta Contabil', 'Conta'] },
  { nome: 'conta_fornecedor', rotulo: 'Conta de fornecedor (32/37.0)', largura: 170, maxLength: 20, aliases: ['Conta_Fornecedor', 'Conta Fornecedor'] },
  { nome: 'codigo_moeda', rotulo: 'Moeda', tipo: 'select', largura: 90, omissao: 'AOA', opcoes: MOEDAS_TERCEIRO.map((m) => ({ value: m, label: m })) },
  { nome: 'rua', rotulo: 'Rua', largura: 160, maxLength: 150, aliases: ['Endereco', 'Endereço', 'Morada'], emLinhas: 'opcional', seccao: 'Endereço' },
  { nome: 'numero_porta', rotulo: 'Nº porta', largura: 80, maxLength: 50, emLinhas: 'opcional', seccao: 'Endereço' },
  { nome: 'bairro', rotulo: 'Bairro', largura: 120, maxLength: 150, emLinhas: 'opcional', seccao: 'Endereço' },
  { nome: 'municipio', rotulo: 'Município', largura: 120, maxLength: 150, emLinhas: 'opcional', seccao: 'Endereço' },
  { nome: 'provincia', rotulo: 'Província', largura: 120, maxLength: 150, emLinhas: 'opcional', seccao: 'Endereço' },
  { nome: 'pais', rotulo: 'País', largura: 100, maxLength: 150, emLinhas: 'opcional', seccao: 'Endereço' },
];

/** Nota do legado para a vista Linhas dos terceiros. */
const NOTA_TERCEIROS =
  'Uma linha por terceiro. O mesmo terceiro pode ser cliente e fornecedor (Tipo «Cliente e Fornecedor»): a venda integra pela conta de cliente (31) e a compra pela de fornecedor (32/37.0), ambas de movimento.';

/** Célula de conta por papel (ui_aux.js:184-186): código em etiqueta; sem conta, «por definir» a vermelho; sem o papel, «—». */
function CelulaContaPapel({ t, papel }: { t: FichaTerceiro; papel: PapelConta }) {
  const { token } = theme.useToken();
  if (!temPapel(t.tipo, papel)) return <span style={{ color: token.colorTextQuaternary }}>—</span>;
  const codigo = papel === 'FORNECEDOR' ? contaFornecedor(t) : contaCliente(t);
  return codigo ? (
    <Tag title={`Conta de ${papel === 'FORNECEDOR' ? 'fornecedor' : 'cliente'}`} style={{ fontWeight: 600 }}>
      {codigo}
    </Tag>
  ) : (
    <span style={{ color: token.colorError }} title="Sem conta associada — a integração do documento vai parar aqui">
      por definir
    </span>
  );
}

/** Coluna «Banco» (ui_aux.js:194-196): contas bancárias mapeadas (pode receber carta de pagamento). */
function CelulaBanco({ t }: { t: FichaTerceiro }) {
  const { token } = theme.useToken();
  const n = t.contas_bancarias ?? 0;
  if (!n) return <span style={{ color: token.colorTextQuaternary }} title="Sem conta bancária mapeada — não pode receber carta de pagamento">—</span>;
  return (
    <Tag color="green" title={`Pode receber carta de ordem de pagamento${t.banco_predefinido ? ` · predefinida: ${t.banco_predefinido}` : ''}`} style={{ fontWeight: 600 }}>
      <IconeFa nome="university" /> {n}
      {t.banco_predefinido ? ` · ${t.banco_predefinido}` : ''}
    </Tag>
  );
}

const valorContaImpressao = (t: FichaTerceiro, papel: PapelConta) =>
  !temPapel(t.tipo, papel) ? '—' : (papel === 'FORNECEDOR' ? contaFornecedor(t) : contaCliente(t)) || 'por definir';

/**
 * Tabelas auxiliares › Terceiros (Versão Actual, js/ui_aux.js:169-305 e 579-1005): formulário «Novo Terceiro» à esquerda
 * (NIF, Nome, Tipo com «Cliente e Fornecedor», Endereço estruturado, Moeda, a conta de cada papel que o tipo pede e as
 * contas bancárias) e «Terceiros Registados» (ID, NIF, Nome, Tipo, Conta cliente, Conta fornecedor, Banco, Ações) à direita,
 * sobre /api/terceiros. As permissões são validadas no servidor: aux_gerir para gravar, aux_eliminar para eliminar.
 */
export function SeparadorTerceiros() {
  const { pode } = useSessao();
  const gerir = pode('aux_gerir');
  const eliminarPerm = pode('aux_eliminar', 'vendas_dados_del');
  const cliente = useQueryClient();
  const [form] = Form.useForm<ValoresTerceiro>();
  const tipo = Form.useWatch('tipo', form) ?? 'CLIENTE';
  const nifActual = Form.useWatch('nif', form);
  const [edicao, setEdicao] = useState<FichaTerceiro | null>(null);
  const [pesquisa, setPesquisa] = useState('');
  const [importar, setImportar] = useState(false);
  const [aviso, setAviso] = useState<string | null>(null);
  const contas = camposConta(tipo);
  const { token } = theme.useToken();
  const [vista, setVista] = useVistaFormulario('contab.terceiros');
  const emLinhas = gerir && !edicao && vista === 'linhas';
  const temMorada = !!edicao && CAMPOS_MORADA.some((c) => !!edicao.morada?.[c]?.trim());

  const limpar = () => {
    setEdicao(null);
    setAviso(null);
    form.resetFields();
    form.setFieldsValue({ tipo: 'CLIENTE', codigo_moeda: 'AOA' });
  };

  const editar = async (t: FichaTerceiro) => {
    setAviso(null);
    let ficha = t;
    try {
      ficha = await obter<FichaTerceiro>(`/terceiros/${t.id}`);
    } catch (e) {
      notificarErro(e, 'Não foi possível abrir a ficha completa');
    }
    setEdicao(ficha);
    form.resetFields();
    form.setFieldsValue({
      nif: ficha.nif ?? undefined,
      nome: ficha.nome?.trim(),
      tipo: (OPCOES_TIPO.find((o) => o.value === ficha.tipo)?.value ?? 'CLIENTE') as TipoTerceiro,
      codigo_moeda: ficha.codigo_moeda || 'AOA',
      conta_cliente: contaCliente(ficha) || undefined,
      conta_fornecedor: contaFornecedor(ficha) || undefined,
      ...Object.fromEntries(CAMPOS_MORADA.map((c) => [c, ficha.morada?.[c] ?? undefined])),
    });
  };

  // depois de gravar, reabre o registo para que as contas bancárias (que precisam do id) possam ser já adicionadas (ui_aux.js:653-654)
  const gravar = useAccao<FichaTerceiro>({ invalidar: [['terceiros']], aoSucesso: (t) => void editar(t), tituloErro: 'Não foi possível gravar o terceiro' });
  const eliminar = useAccao({ invalidar: [['terceiros']] });

  const colunas: ColunaApi<FichaTerceiro>[] = [
    { title: 'ID', dataIndex: 'id', width: 70, render: (v: number) => <Typography.Text type="secondary" style={{ fontSize: 12 }}>{v}</Typography.Text>, valorImpressao: (t) => t.id },
    { title: 'NIF', dataIndex: 'nif', render: (v: string | null) => v || '—' },
    { title: 'Nome', dataIndex: 'nome', ellipsis: true, render: (v: string) => v.trim() },
    { title: 'Tipo', key: 'tipo', render: (_, t) => rotuloTipo(t.tipo), valorImpressao: (t) => rotuloTipo(t.tipo), valorFiltro: (t) => rotuloTipo(t.tipo) },
    { title: 'Conta cliente', key: 'conta_cliente', width: 120, render: (_, t) => <CelulaContaPapel t={t} papel="CLIENTE" />, valorImpressao: (t) => valorContaImpressao(t, 'CLIENTE') },
    { title: 'Conta fornecedor', key: 'conta_fornecedor', width: 130, render: (_, t) => <CelulaContaPapel t={t} papel="FORNECEDOR" />, valorImpressao: (t) => valorContaImpressao(t, 'FORNECEDOR') },
    {
      title: 'Banco',
      key: 'banco',
      align: 'center',
      render: (_, t) => <CelulaBanco t={t} />,
      valorImpressao: (t) => (t.contas_bancarias ? `${t.contas_bancarias}${t.banco_predefinido ? ` · ${t.banco_predefinido}` : ''}` : '—'),
    },
  ];

  const descarregarModelo = async () => {
    try {
      await descarregar('/sistema/migracao/modelos/terceiros', undefined, 'Template_Terceiros.xlsx');
    } catch (e) {
      notificarErro(e, 'Não foi possível descarregar o modelo');
    }
  };

  return (
    <Row gutter={[16, 16]}>
      {gerir && (
        <Col xs={24} lg={emLinhas ? 24 : 8}>
          <CartaoGradeLinhas
            chave="contab.terceiros"
            titulo={edicao ? `Editar Terceiro ${edicao.id}` : 'Novo Terceiro'}
            criacao={!edicao}
            vista={vista}
            aoMudarVista={setVista}
            campos={CAMPOS_TERCEIRO}
            form={form}
            nota={NOTA_TERCEIROS}
            nomeRegistos="terceiros"
            invalidar={[['terceiros']]}
            gravarLinha={(r) => enviar('post', '/terceiros', corpoTerceiroAux(r as unknown as ValoresTerceiro))}
            extra={
              <Space size={4}>
                <Button size="small" icon={<IconeFa nome="file-download" />} onClick={() => void descarregarModelo()}>
                  Template
                </Button>
                <Button size="small" icon={<IconeFa nome="file-import" />} onClick={() => setImportar(true)}>
                  Excel
                </Button>
              </Space>
            }
          >
            <Form<ValoresTerceiro>
              form={form}
              layout="vertical"
              initialValues={{ tipo: 'CLIENTE', codigo_moeda: 'AOA' }}
              onFinish={(v) => {
                const erro = validarContasTerceiro(v.tipo, v.conta_cliente, v.conta_fornecedor);
                setAviso(erro);
                if (erro) return;
                const dados = corpoTerceiroAux(v);
                gravar.mutate(edicao ? { metodo: 'put', url: `/terceiros/${edicao.id}`, dados } : { metodo: 'post', url: '/terceiros', dados });
              }}
            >
              <Form.Item name="nif" label="NIF / Identificação" rules={[{ required: true, whitespace: true, message: 'O NIF é obrigatório.' }, { max: 30 }]}>
                <Input placeholder="NIF" addonAfter={<BotaoValidarNifAgt nif={nifActual} tamanho="small" apenasIcone />} />
              </Form.Item>
              <Form.Item name="nome" label="Nome / Entidade" rules={[{ required: true, whitespace: true, message: 'O nome é obrigatório.' }, { max: 255 }]}>
                <Input placeholder="Nome completo" />
              </Form.Item>
              <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
                <Select options={OPCOES_TIPO} onChange={() => setAviso(null)} />
              </Form.Item>

              {/* Endereço estruturado: secção recolhível (aberta quando a ficha já tem morada) */}
              <SeccaoFormulario key={edicao?.id ?? 'novo'} titulo="Endereço" recolhida={!temMorada}>
                <Form.Item name="rua" label="Rua" rules={[{ max: 150 }]}>
                  <Input placeholder="Nome da rua" />
                </Form.Item>
                <Row gutter={8}>
                  <Col span={8}>
                    <Form.Item name="numero_porta" label="Nº Porta" rules={[{ max: 50 }]}>
                      <Input placeholder="Nº" />
                    </Form.Item>
                  </Col>
                  <Col span={16}>
                    <Form.Item name="bairro" label="Bairro" rules={[{ max: 150 }]}>
                      <Input placeholder="Bairro" />
                    </Form.Item>
                  </Col>
                </Row>
                <Row gutter={8}>
                  <Col span={12}>
                    <Form.Item name="municipio" label="Município" rules={[{ max: 150 }]}>
                      <Input placeholder="Município" />
                    </Form.Item>
                  </Col>
                  <Col span={12}>
                    <Form.Item name="provincia" label="Província" rules={[{ max: 150 }]}>
                      <Input placeholder="Província" />
                    </Form.Item>
                  </Col>
                </Row>
                <Form.Item name="pais" label="País" rules={[{ max: 150 }]}>
                  <Input placeholder="País" />
                </Form.Item>
              </SeccaoFormulario>

              <SeccaoFormulario titulo="Moeda e contas contabilísticas">
                <Form.Item name="codigo_moeda" label="Moeda por omissão">
                  <Select options={['AOA', 'USD', 'EUR', 'ZAR', 'CNY'].map((m) => ({ value: m, label: m }))} />
                </Form.Item>

                {/* Um terceiro pode ser cliente e fornecedor: cada papel tem a sua conta (a venda integra pela 31, a compra pela 32/37.0) */}
                {contas.cliente && (
                  <Form.Item
                    name="conta_cliente"
                    label={
                      <span>
                        Conta de cliente (classe 31) <span style={{ color: token.colorError }}>*</span>{' '}
                        <Typography.Text type="secondary" style={{ fontSize: 12, fontWeight: 400 }}>
                          (integração das facturas de venda)
                        </Typography.Text>
                      </span>
                    }
                  >
                    <SeletorConta prefixos={prefixosConta('CLIENTE')} placeholder="Ex.: 31.1.1" allowClear style={{ width: '100%' }} />
                  </Form.Item>
                )}
                {contas.fornecedor && (
                  <Form.Item
                    name="conta_fornecedor"
                    label={
                      <span>
                        Conta de fornecedor (classe 32 ou 37.0) <span style={{ color: token.colorError }}>*</span>{' '}
                        <Typography.Text type="secondary" style={{ fontSize: 12, fontWeight: 400 }}>
                          (integração das facturas de compra)
                        </Typography.Text>
                      </span>
                    }
                  >
                    <SeletorConta prefixos={prefixosConta('FORNECEDOR')} placeholder="Ex.: 32.1.1" allowClear style={{ width: '100%' }} />
                  </Form.Item>
                )}
                {edicao?.codigo_conta?.trim() && (
                  <Form.Item
                    label={
                      <span>
                        Conta antiga{' '}
                        <Typography.Text type="secondary" style={{ fontSize: 12, fontWeight: 400 }}>
                          (registo anterior às contas por papel — só leitura)
                        </Typography.Text>
                      </span>
                    }
                  >
                    <Input value={edicao.codigo_conta} readOnly variant="filled" />
                  </Form.Item>
                )}
              </SeccaoFormulario>
              {aviso && <Alert type="error" showIcon message={aviso} style={{ marginBottom: 12 }} />}

              {/* Contas bancárias: a carta de ordem de pagamento só se emite a quem tiver uma mapeada */}
              <ContasBancariasTerceiro terceiroId={edicao?.id ?? null} podeGerir={gerir} />

              <Button type="primary" htmlType="submit" block icon={<IconeFa nome={edicao ? 'save' : 'plus'} />} loading={gravar.isPending}>
                {edicao ? 'Guardar Alterações' : 'Adicionar Terceiro'}
              </Button>
              {edicao && (
                <Button block style={{ marginTop: 8 }} onClick={limpar}>
                  Cancelar edição
                </Button>
              )}
            </Form>
          </CartaoGradeLinhas>
        </Col>
      )}
      <Col xs={24} lg={gerir && !emLinhas ? 16 : 24}>
        <Typography.Title level={5} style={{ marginTop: 0 }}>
          Terceiros Registados
        </Typography.Title>
        <Space wrap style={{ marginBottom: 8 }}>
          <Input.Search placeholder="Nome, NIF ou conta" allowClear style={{ width: 280, maxWidth: '100%' }} onSearch={setPesquisa} />
        </Space>
        <TabelaApi<FichaTerceiro>
          url="/terceiros"
          chaveConsulta={['terceiros', 'lista', 'tabelas_aux']}
          filtros={{ pesquisa }}
          columns={colunas}
          size="small"
          impressao={{ titulo: 'Terceiros Registados', filtros: [pesquisa && `Pesquisa: ${pesquisa}`] }}
          accoes={(t) => [
            {
              icone: 'edit', titulo: 'Editar', variante: 'primario', oculto: !gerir,
              desactivado: t.tipo === 'CREDOR_DEVEDOR' && 'Edite-o no separador «Credores e Devedores» (ficha própria, com a conta da classe 37).',
              onClick: () => void editar(t),
            },
            {
              icone: 'trash',
              titulo: 'Eliminar',
              variante: 'perigo',
              oculto: !eliminarPerm,
              onClick: () =>
                Modal.confirm({
                  title: `Eliminar ${t.nome.trim()}?`,
                  content: 'A eliminação é recusada se a entidade tiver documentos ou movimentos.',
                  okText: 'Eliminar',
                  okButtonProps: { danger: true },
                  cancelText: 'Cancelar',
                  onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/terceiros/${t.id}` }),
                }),
            },
          ]}
        />
      </Col>
      <ModalImportar<{ criados: number; actualizados: number }>
        aberto={importar}
        titulo="Importar terceiros (Excel)"
        url="/sistema/migracao/importar/terceiros"
        campos={{ decisao: 'IGNORAR' }}
        comSimulacao
        ajuda={
          <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
            Colunas do modelo: NIF, Nome, Tipo (Cliente, Fornecedor, «Cliente, Fornecedor» ou Colaborador), Conta_Cliente (classe 31) e Conta_Fornecedor (classe 32 ou
            37.0). Os terceiros que já existem (mesmo NIF) não são alterados. Se alguma linha tiver erro, nada é gravado.
          </Typography.Paragraph>
        }
        aoFechar={() => setImportar(false)}
        aoConcluir={() => void cliente.invalidateQueries({ queryKey: ['terceiros'] })}
      />
    </Row>
  );
}
