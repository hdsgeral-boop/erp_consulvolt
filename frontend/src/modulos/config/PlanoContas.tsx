import { Alert, Button, Card, Checkbox, Col, Collapse, Flex, Form, Input, Radio, Row, Select, Space, Table, Tabs, Tag, Tooltip, Typography } from 'antd';
import type { Key } from 'react';
import { PlusOutlined, SwapOutlined } from '@ant-design/icons';
import { useMutation } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { enviar } from '@/api/cliente';
import { NavConfig } from './comum/NavConfig';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { scrollTabela } from '@/componentes/responsivo';
import { TabelaLocalImprimivel } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { usePlanoContas } from '@/modulos/contab/comum/dados';
import { BotaoCsv } from '@/modulos/contab/comum/Componentes';
import type { ContaPlano } from '@/modulos/contab/api';
import { IconeFa } from '@/componentes/fluxos';
import { tituloPlanoContas } from './comum/paridade';
import { CelulaUso, CopiarOutraEmpresa, ImportarPlano, baixarTemplatePlano, useContasEmUso } from './plano/PlanoContasParidade';
import { DadosBancariosConta, useDadosBancariosConta } from './plano/DadosBancariosConta';
import { CartaoTesourariaConta, useDadosCartaoConta } from './plano/CartaoTesourariaConta';
import { BarraAccoesMassa } from './plano/AccoesMassaPlano';
import { CAMPOS_BANCARIOS, dadosBancariosParaApi, ibanContaValido, swiftValido, type DadosBancarios } from './plano/contasBancarias';
import { CAMPOS_CARTAO, dadosCartaoParaApi, useContasCartaoMapa, type DadosCartao } from './plano/cartaoTesouraria';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';

/** Conta do plano: regras comuns à grade e à vista Linhas (vazios = null; dados bancários só nas 42/43 de movimento). */
export function dadosConta(v: Record<string, unknown>): Record<string, unknown> {
  const codigo = String(v.codigo ?? '').trim();
  const tipo = String(v.tipo ?? 'M');
  return { ...v, codigo, tipo, codigo_moeda: v.codigo_moeda || null, natureza_conta: v.natureza_conta || null,
    ...dadosBancariosParaApi(codigo, tipo, v as DadosBancarios), ...dadosCartaoParaApi(v as DadosCartao) };
}

const TIPOS_CONTA = [{ value: 'M', label: 'Movimento' }, { value: 'T', label: 'Totalizadora' }];
const BANCARIOS = 'Dados bancários (42/43)';

/** Vista Linhas das contas (legado: formularios_linhas_config.js › plano_contas — código, descrição, tipo, moeda). */
export const CAMPOS_CONTA: CampoFormulario[] = [
  { nome: 'codigo', rotulo: 'Código da conta', obrigatorio: true, largura: 140, maxLength: 20, aliases: ['Código', 'Codigo', 'Conta'],
    validar: (c) => (c && !/^[0-9A-Za-z.]+$/.test(String(c).trim()) ? 'Só algarismos, letras e pontos.' : null) },
  { nome: 'descricao', rotulo: 'Descrição', obrigatorio: true, largura: 260, maxLength: 255, aliases: ['Descricao', 'Designação', 'Nome'] },
  { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', obrigatorio: true, opcoes: TIPOS_CONTA, omissao: 'M', largura: 140, aliases: ['M/T', 'Tipo (M/T)'] },
  { nome: 'codigo_moeda', rotulo: 'Moeda', largura: 90, maxLength: 10, aliases: ['Moeda da conta'] },
  { nome: 'natureza_conta', rotulo: 'Natureza', largura: 160, maxLength: 255, emLinhas: 'opcional', seccao: 'Outros' },
  { nome: 'banco_nome', rotulo: 'Banco', largura: 180, maxLength: 100, emLinhas: 'opcional', seccao: BANCARIOS },
  { nome: 'banco_balcao', rotulo: 'Balcão / Agência', largura: 150, maxLength: 100, emLinhas: 'opcional', seccao: BANCARIOS },
  { nome: 'banco_numero_conta', rotulo: 'Nº de conta', largura: 150, maxLength: 40, emLinhas: 'opcional', seccao: BANCARIOS },
  { nome: 'banco_iban', rotulo: 'IBAN', largura: 230, maxLength: 50, emLinhas: 'opcional', seccao: BANCARIOS,
    validar: (v) => (v && String(v).trim() && !ibanContaValido(String(v)) ? 'IBAN inválido.' : null) },
  { nome: 'banco_swift', rotulo: 'SWIFT / BIC', largura: 120, maxLength: 15, emLinhas: 'opcional', seccao: BANCARIOS,
    validar: (v) => (v && String(v).trim() && !swiftValido(String(v)) ? 'SWIFT/BIC inválido.' : null) },
  { nome: 'banco_titular', rotulo: 'Titular da conta', largura: 180, maxLength: 150, emLinhas: 'opcional', seccao: BANCARIOS },
];

interface ContagemTabela {
  tabela: string;
  coluna: string;
  rotulo: string;
  registos: number;
}

interface SimulacaoEmpresa {
  empresa_id: number;
  empresa: string;
  origem: { codigo: string; descricao: string | null; tipo: string } | null;
  destino: { codigo: string; descricao: string | null; tipo: string } | null;
  pode_substituir: boolean;
  motivo: string | null;
  alteraveis: ContagemTabela[];
  total_alteraveis: number;
  informativos: ContagemTabela[];
  total_informativos: number;
}

/**
 * Configurações › Plano de contas (config_plano): contas (M/T), criação/edição/eliminação e substituição de conta com simulação.
 * Paridade com o antigo (renderPlanoContas, js/app_v2.js:8320-8402): título «Plano de Contas (N contas)», barra
 * Copiar de outra empresa · Substituir conta · Baixar Template · Importar Excel · Nova Conta, colunas
 * CÓDIGO · DESCRIÇÃO · TIPO · USO · AÇÕES (Editar, Excluir).
 * Fase A (legado novo, app_v2.js:9050-9163): nas contas 42/43 de movimento, o bloco «Dados bancários da conta».
 * Caixas de selecção na 1.ª coluna e barra de acções em massa (app_v2.js:8828-9037): «Modificar em Massa» e
 * «Eliminar Seleccionadas» (AccoesMassaPlano), com as permissões verificadas também no servidor.
 */
export default function PlanoContas() {
  const { pode } = useSessao();
  const plano = usePlanoContas();
  const substituir = pode('contab_conta_substituir');
  const [separador, setSeparador] = useSeparadorUrl(substituir ? ['contas', 'substituir'] : ['contas']);
  return (
    <>
      <NavConfig actual="config_plano" />
      <CabecalhoPagina titulo={tituloPlanoContas(plano.data?.length)} subtitulo="Contas de movimento e totalizadoras da empresa activa" />
      <Tabs
        activeKey={separador}
        onChange={setSeparador}
        items={[
          { key: 'contas', label: 'Contas', children: <Contas aoSubstituir={substituir ? () => setSeparador('substituir') : undefined} /> },
          ...(substituir ? [{ key: 'substituir', label: 'Substituir conta', children: <Substituir /> }] : []),
        ]}
      />
    </>
  );
}

function Contas({ aoSubstituir }: { aoSubstituir?: () => void }) {
  const { pode } = useSessao();
  const plano = usePlanoContas();
  const uso = useContasEmUso(pode('config_plano_view', 'contab_plano_gerir', 'lancamentos_view'));
  const [filtro, setFiltro] = useState('');
  const [tipo, setTipo] = useState<'M' | 'T' | undefined>();
  const [edicao, setEdicao] = useState<ContaPlano | 'nova' | null>(null);
  const [janela, setJanela] = useState<'copiar' | 'importar' | null>(null);
  const [seleccao, setSeleccao] = useState<Key[]>([]);
  const [form] = Form.useForm<{ codigo: string; descricao: string; tipo: 'M' | 'T'; codigo_moeda?: string; natureza_conta?: string } & DadosBancarios & DadosCartao>();
  const gerir = pode('contab_plano_gerir');
  const eliminar = pode('contab_tabelas_del');
  const accao = useAccao({ invalidar: [['contab', 'plano-contas']], aoSucesso: () => setEdicao(null) });
  const emUso = (c: ContaPlano) => !!uso.data?.has(c.codigo);
  const cartoes = useContasCartaoMapa();
  // dados bancários/cartão gravados (não vêm todos na lista em cache do plano): lidos ao abrir a conta
  const editada = edicao && edicao !== 'nova' ? edicao : undefined;
  const bancarios = useDadosBancariosConta(editada?.codigo, editada?.tipo);
  const cartao = useDadosCartaoConta(editada?.codigo, editada?.tipo, editada?.cartao_tesouraria);

  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    if (edicao === 'nova') form.setFieldsValue({ tipo: 'M' });
    else form.setFieldsValue({ ...edicao, descricao: edicao.descricao ?? '', codigo_moeda: edicao.codigo_moeda ?? undefined, natureza_conta: edicao.natureza_conta ?? undefined });
  }, [edicao, form]);

  useEffect(() => {
    const d = bancarios.data;
    if (!d || !editada || d.codigo !== editada.codigo) return;
    form.setFieldsValue(Object.fromEntries(CAMPOS_BANCARIOS.map((c) => [c, d[c] ?? undefined])));
  }, [bancarios.data, editada, form]);

  useEffect(() => {
    const d = cartao.data;
    if (!d || !editada || d.codigo !== editada.codigo) return;
    form.setFieldsValue(Object.fromEntries(CAMPOS_CARTAO.map((c) => [c, d[c] ?? undefined])));
  }, [cartao.data, editada, form]);

  const linhas = useMemo(() => {
    const f = filtro.toLowerCase();
    return (plano.data ?? []).filter((c) => (!tipo || c.tipo === tipo) && (!f || c.codigo.startsWith(f) || (c.descricao ?? '').toLowerCase().includes(f)));
  }, [plano.data, filtro, tipo]);

  const comSeleccao = gerir || eliminar;
  // a selecção só guarda contas que ainda existem (depois de eliminar/recarregar)
  const idsSeleccionados = useMemo(() => {
    const existentes = new Set((plano.data ?? []).map((c) => c.id));
    return seleccao.map(Number).filter((id) => existentes.has(id));
  }, [seleccao, plano.data]);

  return (
    <Card>
      {comSeleccao && <BarraAccoesMassa ids={idsSeleccionados} podeModificar={gerir} podeEliminar={eliminar} aoLimpar={() => setSeleccao([])} />}
      <TabelaLocalImprimivel<ContaPlano>
        titulo={tituloPlanoContas(plano.data?.length)}
        filtros={[tipo ? `Tipo: ${tipo === 'M' ? 'Movimento' : 'Totalizadoras'}` : null, filtro ? `Pesquisa: ${filtro}` : null]}
        filtrosEcra={<>
          <Input.Search placeholder="Código (prefixo) ou descrição" allowClear style={{ width: 280 }} onChange={(e) => setFiltro(e.target.value)} />
          <Radio.Group value={tipo ?? ''} onChange={(e) => setTipo(e.target.value || undefined)} optionType="button" options={[{ value: '', label: 'Todas' }, { value: 'M', label: 'Movimento' }, { value: 'T', label: 'Totalizadoras' }]} />
        </>}
        accoes={<>
          {gerir && <Button icon={<IconeFa nome="copy" />} style={{ color: '#1d4ed8' }} onClick={() => setJanela('copiar')}>Copiar de outra empresa</Button>}
          {aoSubstituir && <Button icon={<IconeFa nome="exchange-alt" />} style={{ color: '#b45309', borderColor: '#f59e0b' }} onClick={aoSubstituir}>Substituir conta</Button>}
          {gerir && <Button icon={<IconeFa nome="file-download" />} onClick={() => void baixarTemplatePlano()}>Baixar Template</Button>}
          {gerir && <Button icon={<IconeFa nome="file-import" />} onClick={() => setJanela('importar')}>Importar Excel</Button>}
          <BotaoCsv<ContaPlano>
            nome="plano_contas"
            linhas={linhas}
            colunas={[{ titulo: 'Código', valor: (c) => c.codigo }, { titulo: 'Descrição', valor: (c) => c.descricao }, { titulo: 'Tipo', valor: (c) => c.tipo }, { titulo: 'Moeda', valor: (c) => c.codigo_moeda }, { titulo: 'Uso', valor: (c) => (emUso(c) ? 'Em Uso' : '') }]}
          />
          {gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>Nova Conta</Button>}
        </>}
        rowKey="id"
        size="small"
        loading={plano.isLoading}
        dataSource={linhas}
        // legado: caixa de selecção na 1.ª coluna e «Seleccionar todos» no cabeçalho (js/app_v2.js:8830, 8873)
        rowSelection={
          comSeleccao
            ? {
                selectedRowKeys: seleccao,
                onChange: setSeleccao,
                preserveSelectedRowKeys: true,
                columnWidth: 40,
                selections: [Table.SELECTION_ALL, Table.SELECTION_INVERT, Table.SELECTION_NONE],
                getCheckboxProps: (c: ContaPlano) => ({ title: `Seleccionar a conta ${c.codigo}` }),
              }
            : undefined
        }
        pagination={{ pageSize: 100, showSizeChanger: false, showTotal: (n) => `${n} conta(s)` }}
        columns={[
          { title: 'Código', dataIndex: 'codigo', width: 150, render: (v: string, c) => <span style={{ paddingLeft: Math.max(0, v.replace(/\./g, '').length - 1) * 6, fontWeight: c.tipo === 'T' ? 600 : 400, fontFamily: 'monospace' }}>{v}</span> },
          { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null, c) => {
            const cartao = c.cartao_tesouraria ? cartoes.get(c.codigo) : undefined;
            return (
              <span style={{ fontWeight: c.tipo === 'T' ? 600 : 400 }}>
                {v}
                {c.cartao_tesouraria && (
                  <Tooltip title={cartao ? `${cartao.cartao_banco ?? ''}${cartao.cartao_numero ? ` · ${cartao.cartao_numero}` : ''}` : 'Cartão de tesouraria'}>
                    <Tag color="purple" style={{ marginLeft: 6 }} icon={<IconeFa nome="credit-card" />}>Cartão</Tag>
                  </Tooltip>
                )}
              </span>
            );
          } },
          {
            title: 'Tipo',
            dataIndex: 'tipo',
            width: 150,
            align: 'center',
            valorImpressao: (c) => `${c.tipo === 'T' ? 'Totalizadora' : 'Movimento'}${c.codigo_moeda && c.codigo_moeda !== 'AOA' ? ` (${c.codigo_moeda})` : ''}`,
            render: (t: string, c) => (
              <>
                {t === 'T' ? <Tag color="green">Totalizadora</Tag> : <Tag color="blue">Movimento</Tag>}
                {c.codigo_moeda && c.codigo_moeda !== 'AOA' && <Tag title="Moeda da conta">{c.codigo_moeda}</Tag>}
              </>
            ),
          },
          { title: 'Uso', key: 'uso', width: 110, align: 'center', valorImpressao: (c) => (emUso(c) ? 'Em Uso' : '-'), valorFiltro: (c) => (emUso(c) ? 'Em Uso' : '-'), render: (_, c) => <CelulaUso emUso={emUso(c)} /> },
        ]}
        accoesLinha={(c) => [
          gerir && { icone: 'edit', titulo: 'Editar', onClick: () => setEdicao(c) },
          eliminar && {
            icone: 'trash',
            titulo: 'Excluir',
            variante: 'perigo',
            desactivado: emUso(c) ? 'Conta em uso: não pode ser eliminada (use «Substituir conta»)' : false,
            confirmar: { titulo: `Eliminar a conta ${c.codigo}?`, texto: 'Só é possível sem movimentos nem subcontas.', textoOk: 'Eliminar', perigo: true },
            onClick: () => accao.mutateAsync({ metodo: 'delete', url: `/contabilidade/plano-contas/${c.id}` }),
          },
        ]}
      />
      <CopiarOutraEmpresa aberto={janela === 'copiar'} aoFechar={() => setJanela(null)} />
      <ImportarPlano aberto={janela === 'importar'} aoFechar={() => setJanela(null)} />
      <ModalFormulario chave="config.plano_contas" titulo={edicao === 'nova' ? 'Nova conta' : `Conta ${edicao?.codigo ?? ''}`} aberto={!!edicao} aoFechar={() => setEdicao(null)} criacao={edicao === 'nova'} form={form}
        campos={CAMPOS_CONTA} nomeRegistos="contas" invalidar={[['contab', 'plano-contas']]} gravarLinha={(r) => enviar('post', '/contabilidade/plano-contas', dadosConta(r))}
        okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()}>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) =>
            accao.mutate({
              metodo: edicao === 'nova' ? 'post' : 'put',
              url: edicao === 'nova' ? '/contabilidade/plano-contas' : `/contabilidade/plano-contas/${(edicao as ContaPlano).id}`,
              dados: dadosConta(v),
            })
          }
        >
          <Row gutter={16}>
            <Col xs={24} sm={12} md={10}>
              <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { pattern: /^[0-9A-Za-z.]+$/, message: 'Só algarismos, letras e pontos.' }]}>
                <Input maxLength={20} />
              </Form.Item>
            </Col>
            <Col xs={24} md={14}>
              <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
                <Radio.Group options={TIPOS_CONTA} />
              </Form.Item>
            </Col>
          </Row>
          <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Indique a descrição.' }]}><Input maxLength={255} /></Form.Item>
          <Row gutter={16}>
            <Col xs={24} sm={12} md={10}><Form.Item name="codigo_moeda" label="Moeda (opcional)"><Input maxLength={10} /></Form.Item></Col>
            <Col xs={24} md={14}><Form.Item name="natureza_conta" label="Natureza"><Input maxLength={255} /></Form.Item></Col>
          </Row>
          <DadosBancariosConta />
          <CartaoTesourariaConta />
        </Form>
      </ModalFormulario>
    </Card>
  );
}

function Substituir() {
  const { empresas, empresa } = useSessao();
  const plano = usePlanoContas();
  const [form] = Form.useForm<{ origem: string; destino: string; empresas: number[] }>();
  const [simulacao, setSimulacao] = useState<SimulacaoEmpresa[] | null>(null);
  const [confirmado, setConfirmado] = useState(false);
  const opcoes = (plano.data ?? []).filter((c) => c.tipo === 'M').map((c) => ({ value: c.codigo, label: `${c.codigo} - ${c.descricao ?? ''}` }));

  const simular = useMutation({
    mutationFn: async () => enviar<SimulacaoEmpresa[]>('post', '/sistema/plano-contas/substituir/simular', await form.validateFields()),
    onSuccess: ({ dados }) => {
      setSimulacao(dados);
      setConfirmado(false);
    },
    onError: (e) => notificarErro(e, 'Não foi possível simular'),
  });
  const executar = useAccao<{ empresa_id: number; empresa: string; alterados: Record<string, number>; total: number }[]>({
    invalidar: [['contab']],
    aoSucesso: () => {
      setSimulacao(null);
      setConfirmado(false);
    },
    tituloErro: 'A substituição não foi feita',
  });
  const total = (simulacao ?? []).reduce((s, e) => s + e.total_alteraveis, 0);
  const bloqueadas = (simulacao ?? []).filter((e) => !e.pode_substituir);

  return (
    <Card>
      <Alert
        type="warning"
        showIcon
        style={{ marginBottom: 16 }}
        message="Substitui a conta de origem pela de destino nos dados mestre e documentos por contabilizar"
        description="Lançamentos e documentos já contabilizados não são alterados (use estornos). Simule sempre antes; a operação é registada na auditoria."
      />
      <Form form={form} layout="vertical" initialValues={{ empresas: empresa ? [empresa.id] : [] }} onValuesChange={() => setSimulacao(null)}>
        <Row gutter={16}>
          <Col xs={24} md={8}>
            <Form.Item name="origem" label="Conta de origem (sai)" rules={[{ required: true, message: 'Indique a conta.' }]}>
              <Select showSearch optionFilterProp="label" options={opcoes} loading={plano.isLoading} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="destino" label="Conta de destino (entra)" dependencies={['origem']} rules={[{ required: true, message: 'Indique a conta.' }, ({ getFieldValue }) => ({ validator: (_, v) => (v && v === getFieldValue('origem') ? Promise.reject(new Error('A conta de destino tem de ser diferente.')) : Promise.resolve()) })]}>
              <Select showSearch optionFilterProp="label" options={opcoes} loading={plano.isLoading} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="empresas" label="Empresas" rules={[{ required: true, type: 'array', min: 1, message: 'Escolha pelo menos uma.' }]} extra="As contas têm de existir em cada empresa.">
              <Select mode="multiple" optionFilterProp="label" options={empresas.map((e) => ({ value: e.id, label: e.nome }))} />
            </Form.Item>
          </Col>
        </Row>
        <Button icon={<SwapOutlined />} loading={simular.isPending} onClick={() => simular.mutate()}>Simular</Button>
      </Form>
      {simulacao && (
        <div style={{ marginTop: 16 }}>
          <Collapse
            defaultActiveKey={simulacao.map((e) => String(e.empresa_id))}
            items={simulacao.map((e) => ({
              key: String(e.empresa_id),
              label: (
                <Space wrap>
                  <strong>{e.empresa}</strong>
                  {e.pode_substituir ? <Tag color="green">{formatarNumero(e.total_alteraveis)} registo(s) a alterar</Tag> : <Tag color="red">Impossível</Tag>}
                  {e.total_informativos > 0 && <Tag>{formatarNumero(e.total_informativos)} informativo(s)</Tag>}
                </Space>
              ),
              children: (
                <>
                  {e.motivo && <Alert type="error" showIcon message={e.motivo} style={{ marginBottom: 8 }} />}
                  <Row gutter={16}>
                    <Col xs={24} lg={12}>
                      <Typography.Text strong>Alterados</Typography.Text>
                      <Table size="small" rowKey={(l) => `${l.tabela}.${l.coluna}`} pagination={false} scroll={scrollTabela()} dataSource={e.alteraveis} locale={{ emptyText: 'Nenhum.' }} columns={[{ title: 'Onde', dataIndex: 'rotulo' }, { title: 'Registos', dataIndex: 'registos', align: 'right', render: formatarNumero }]} />
                    </Col>
                    <Col xs={24} lg={12}>
                      <Typography.Text strong>Não alterados (rever)</Typography.Text>
                      <Table size="small" rowKey={(l) => `${l.tabela}.${l.coluna}`} pagination={false} scroll={scrollTabela()} dataSource={e.informativos} locale={{ emptyText: 'Nenhum.' }} columns={[{ title: 'Onde', dataIndex: 'rotulo' }, { title: 'Registos', dataIndex: 'registos', align: 'right', render: formatarNumero }]} />
                    </Col>
                  </Row>
                </>
              ),
            }))}
          />
          <Flex gap={16} align="center" wrap style={{ marginTop: 16 }}>
            <Checkbox checked={confirmado} disabled={!!bloqueadas.length || total === 0} onChange={(e) => setConfirmado(e.target.checked)}>
              Confirmo a substituição de {formatarNumero(total)} registo(s) em {simulacao.length} empresa(s)
            </Checkbox>
            <Button
              type="primary"
              danger
              disabled={!confirmado}
              loading={executar.isPending}
              onClick={() => executar.mutate({ url: '/sistema/plano-contas/substituir', dados: { ...form.getFieldsValue(), confirmar: true } })}
            >
              Substituir
            </Button>
            {bloqueadas.length > 0 && <Typography.Text type="danger">Corrija as empresas marcadas como impossíveis (ou retire-as) antes de substituir.</Typography.Text>}
          </Flex>
        </div>
      )}
    </Card>
  );
}
