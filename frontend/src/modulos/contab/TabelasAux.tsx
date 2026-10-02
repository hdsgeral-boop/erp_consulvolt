import { Alert, Button, Card, Checkbox, Flex, Form, Input, Modal, Popconfirm, Select, Space, Table, Tabs, Tag, Typography, message } from 'antd';
import { CopyOutlined, DeleteOutlined, EditOutlined, ImportOutlined, PlusOutlined, SendOutlined, SyncOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { scrollTabela } from '@/componentes/responsivo';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar } from '@/componentes/impressao';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { tabelaDeColunas } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarDataHora } from '@/utilitarios/formatacao';
import type { ContaPlano, RegistoAux } from './api';
import { BotaoCsv, ValorKz } from './comum/Componentes';
import { usePlanoContas, useTabelaAux, type TabelaAux } from './comum/dados';
import { ModalImportar } from './comum/ficheiros';

const TABELAS: { chave: TabelaAux; titulo: string }[] = [
  { chave: 'diarios', titulo: 'Diários' },
  { chave: 'notas-demonstracao', titulo: 'Notas DEMO' },
  { chave: 'notas-fluxo-caixa', titulo: 'Notas de fluxo de caixa' },
  { chave: 'centros-custo', titulo: 'Centros de custo' },
];

/** Contabilidade › Terceiros e tabelas (ecrã tabelas_aux): diários, notas, centros de custo, plano de contas e reciclagem. Os terceiros têm módulo próprio. */
export default function TabelasAux() {
  const { pode } = useSessao();
  return (
    <>
      <CabecalhoPagina titulo="Tabelas auxiliares" subtitulo="Diários, notas, centros de custo, plano de contas e reciclagem de lançamentos" />
      <Tabs
        items={[
          ...TABELAS.map((t) => ({ key: t.chave, label: t.titulo, children: <TabelaSimples tabela={t.chave} titulo={t.titulo} /> })),
          { key: 'plano', label: 'Plano de contas', children: <PlanoContas /> },
          ...(pode('aux_reciclagem', 'tabelas_aux_view') ? [{ key: 'reciclagem', label: 'Reciclagem', children: <Reciclagem /> }] : []),
        ]}
      />
    </>
  );
}

function TabelaSimples({ tabela, titulo }: { tabela: TabelaAux; titulo: string }) {
  const { pode, empresas, empresa } = useSessao();
  const cliente = useQueryClient();
  const dados = useTabelaAux(tabela);
  const [filtro, setFiltro] = useState('');
  const [edicao, setEdicao] = useState<RegistoAux | 'novo' | null>(null);
  const [importar, setImportar] = useState(false);
  const [actualizar, setActualizar] = useState(false);
  const [copiar, setCopiar] = useState(false);
  const [envio, setEnvio] = useState<RegistoAux | null>(null);
  const [form] = Form.useForm<{ codigo: string; descricao: string }>();
  const [formEmpresa] = Form.useForm<{ empresa_id: number; substituir?: boolean }>();
  const gerir = pode('aux_gerir');
  const outras = empresas.filter((e) => e.id !== empresa?.id).map((e) => ({ value: e.id, label: e.nome }));

  const invalidar = () => {
    void cliente.invalidateQueries({ queryKey: ['contab', 'tabelas', tabela] });
    if (tabela === 'diarios') void cliente.invalidateQueries({ queryKey: ['contab', 'diarios'] });
  };
  const mutacao = useMutation({
    mutationFn: ({ metodo, caminho, corpo }: { metodo: 'post' | 'put' | 'delete'; caminho: string; corpo?: unknown }) => enviar<unknown>(metodo, `/contabilidade/tabelas/${caminho}`, corpo),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      setCopiar(false);
      setEnvio(null);
      invalidar();
    },
    onError: (e) => notificarErro(e),
  });

  const linhas = useMemo(() => {
    const f = filtro.toLowerCase();
    return (dados.data ?? []).filter((r) => !f || r.codigo.toLowerCase().includes(f) || (r.descricao ?? '').toLowerCase().includes(f));
  }, [dados.data, filtro]);

  const colunasAux: ColunaApi<RegistoAux>[] = [
          { title: 'Código', dataIndex: 'codigo', width: 140, render: (v: string) => <strong>{v}</strong> },
          { title: 'Descrição', dataIndex: 'descricao' },
          {
            title: '',
            width: 140,
            exportar: false,
            render: (_, r) => (
              <Space wrap>
                {gerir && <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" title="Editar" onClick={() => { form.setFieldsValue({ codigo: r.codigo, descricao: r.descricao ?? '' }); setEdicao(r); }} />}
                {gerir && outras.length > 0 && <Button size="small" type="text" icon={<SendOutlined />} aria-label="Enviar para outra empresa" title="Enviar para outra empresa" onClick={() => { formEmpresa.resetFields(); setEnvio(r); }} />}
                {pode('aux_eliminar') && (
                  <Popconfirm title={`Eliminar ${r.codigo}?`} okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => mutacao.mutateAsync({ metodo: 'delete', caminho: `${tabela}/${r.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" title="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ];
  return (
    <Card>
      <Flex justify="space-between" wrap gap={8} style={{ marginBottom: 12 }}>
        <Input.Search placeholder="Pesquisar código ou descrição" allowClear style={{ width: 280, maxWidth: '100%' }} onChange={(e) => setFiltro(e.target.value)} />
        <Space wrap>
          <BotoesExportar
            obterPedido={async () => ({ titulo, filtros: [filtro && `Pesquisa: ${filtro}`], conteudo: await tabelaDeColunas(colunasAux, linhas) })}
            desactivado={!linhas.length}
          />
          <BotaoCsv<RegistoAux> nome={tabela} linhas={linhas} colunas={[{ titulo: 'Código', valor: (r) => r.codigo }, { titulo: 'Descrição', valor: (r) => r.descricao }]} />
          {gerir && tabela === 'centros-custo' && (
            <Button icon={<SyncOutlined />} loading={mutacao.isPending} onClick={() => mutacao.mutate({ metodo: 'post', caminho: 'centros-custo/sincronizar' })}>
              Importar do plano de contas
            </Button>
          )}
          {gerir && <Button icon={<CopyOutlined />} onClick={() => { formEmpresa.resetFields(); setCopiar(true); }} disabled={!outras.length}>Copiar de outra empresa</Button>}
          {gerir && <Button icon={<ImportOutlined />} onClick={() => setImportar(true)}>Importar</Button>}
          {gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); setEdicao('novo'); }}>Novo</Button>}
        </Space>
      </Flex>
      <Table<RegistoAux>
        scroll={scrollTabela()}
        rowKey="id"
        size="small"
        loading={dados.isLoading}
        dataSource={linhas}
        pagination={{ pageSize: 50, showTotal: (n) => `${n} registo(s)` }}
        columns={colunasAux}
      />

      <Modal title={edicao === 'novo' ? `Novo registo — ${titulo}` : `Editar — ${titulo}`} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" confirmLoading={mutacao.isPending} onOk={() => form.submit()}>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) => mutacao.mutate(edicao && edicao !== 'novo' ? { metodo: 'put', caminho: `${tabela}/${edicao.id}`, corpo: v } : { metodo: 'post', caminho: tabela, corpo: v })}
        >
          <Form.Item name="codigo" label="Código" rules={[{ required: true }]}>
            <Input maxLength={20} />
          </Form.Item>
          <Form.Item name="descricao" label="Descrição" rules={[{ required: true }]}>
            <Input maxLength={255} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal title={`Copiar ${titulo.toLowerCase()} de outra empresa`} open={copiar} onCancel={() => setCopiar(false)} okText="Copiar" confirmLoading={mutacao.isPending} onOk={() => formEmpresa.submit()}>
        <Form form={formEmpresa} layout="vertical" onFinish={(v) => mutacao.mutate({ metodo: 'post', caminho: `${tabela}/copiar`, corpo: { empresa_origem_id: v.empresa_id } })}>
          <Form.Item name="empresa_id" label="Empresa de origem" rules={[{ required: true }]}>
            <Select options={outras} showSearch optionFilterProp="label" />
          </Form.Item>
          <Typography.Text type="secondary">Só são copiados os códigos que ainda não existem nesta empresa.</Typography.Text>
        </Form>
      </Modal>

      <Modal title={`Enviar ${envio?.codigo ?? ''} para outra empresa`} open={!!envio} onCancel={() => setEnvio(null)} okText="Enviar" confirmLoading={mutacao.isPending} onOk={() => formEmpresa.submit()}>
        <Form form={formEmpresa} layout="vertical" onFinish={(v) => envio && mutacao.mutate({ metodo: 'post', caminho: `${tabela}/${envio.id}/enviar`, corpo: { empresa_destino_id: v.empresa_id, substituir: !!v.substituir } })}>
          <Form.Item name="empresa_id" label="Empresa de destino" rules={[{ required: true }]}>
            <Select options={outras} showSearch optionFilterProp="label" />
          </Form.Item>
          <Form.Item name="substituir" valuePropName="checked">
            <Checkbox>Substituir a descrição se o código já existir</Checkbox>
          </Form.Item>
        </Form>
      </Modal>

      <ModalImportar
        aberto={importar}
        titulo={`Importar — ${titulo}`}
        url={`/contabilidade/tabelas/${tabela}/importar`}
        campos={{ actualizar_existentes: actualizar }}
        ajuda="Ficheiro XLSX, XLS ou CSV com as colunas código e descrição."
        extra={<Checkbox checked={actualizar} onChange={(e) => setActualizar(e.target.checked)}>Actualizar a descrição dos códigos existentes</Checkbox>}
        aoFechar={() => setImportar(false)}
        aoConcluir={invalidar}
      />
    </Card>
  );
}

function PlanoContas() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const plano = usePlanoContas();
  const [filtro, setFiltro] = useState('');
  const [tipo, setTipo] = useState<string>();
  const [edicao, setEdicao] = useState<ContaPlano | 'novo' | null>(null);
  const [form] = Form.useForm<{ codigo: string; descricao: string; tipo: 'M' | 'T'; codigo_moeda?: string; natureza_conta?: string }>();
  const gerir = pode('contab_plano_gerir');

  const mutacao = useMutation({
    mutationFn: ({ metodo, url, corpo }: { metodo: 'post' | 'put' | 'delete'; url: string; corpo?: unknown }) => enviar<unknown>(metodo, url, corpo),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      void cliente.invalidateQueries({ queryKey: ['contab', 'plano-contas'] });
    },
    onError: (e) => notificarErro(e),
  });

  const linhas = useMemo(() => {
    const f = filtro.toLowerCase();
    return (plano.data ?? []).filter((c) => (!tipo || c.tipo === tipo) && (!f || c.codigo.startsWith(f) || (c.descricao ?? '').toLowerCase().includes(f)));
  }, [plano.data, filtro, tipo]);

  if (plano.isError) return <Alert type="warning" showIcon message="Não tem acesso ao plano de contas nesta empresa." />;

  const colunasPlano: ColunaApi<ContaPlano>[] = [
          { title: 'Código', dataIndex: 'codigo', width: 160, render: (v: string, c) => <span style={{ paddingLeft: Math.max(0, v.length - 1) * 6, fontWeight: c.tipo === 'T' ? 600 : undefined }}>{v}</span> },
          { title: 'Descrição', dataIndex: 'descricao' },
          { title: 'Tipo', dataIndex: 'tipo', width: 130, render: (v: string) => (v === 'T' ? <Tag>Totalizadora</Tag> : <Tag color="blue">Movimento</Tag>) },
          { title: 'Moeda', dataIndex: 'codigo_moeda', width: 90, render: (v: string | null) => v ?? '—' },
          {
            title: '',
            width: 100,
            exportar: false,
            render: (_, c) => (
              <Space wrap>
                {gerir && (
                  <Button
                    size="small"
                    type="text"
                    icon={<EditOutlined />}
                    aria-label="Editar"
                    title="Editar"
                    onClick={() => { form.setFieldsValue({ codigo: c.codigo, descricao: c.descricao ?? '', tipo: c.tipo, codigo_moeda: c.codigo_moeda ?? undefined, natureza_conta: c.natureza_conta ?? undefined }); setEdicao(c); }}
                  />
                )}
                {pode('contab_tabelas_del') && (
                  <Popconfirm title={`Eliminar a conta ${c.codigo}?`} description="O servidor recusa contas com movimentos ou referências." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => mutacao.mutateAsync({ metodo: 'delete', url: `/contabilidade/plano-contas/${c.id}` })}>
                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" title="Eliminar" />
                  </Popconfirm>
                )}
              </Space>
            ),
          },
        ];
  return (
    <Card>
      <Flex justify="space-between" wrap gap={8} style={{ marginBottom: 12 }}>
        <Space wrap>
          <Input.Search placeholder="Código (prefixo) ou descrição" allowClear style={{ width: 280, maxWidth: '100%' }} onChange={(e) => setFiltro(e.target.value)} />
          <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 170 }} options={[{ value: 'M', label: 'Movimento' }, { value: 'T', label: 'Totalizadora' }]} />
        </Space>
        <Space wrap>
          <BotoesExportar
            obterPedido={async () => ({ titulo: 'Plano de contas', filtros: [filtro && `Pesquisa: ${filtro}`, tipo && `Tipo: ${tipo === 'T' ? 'Totalizadora' : 'Movimento'}`], conteudo: await tabelaDeColunas(colunasPlano, linhas) })}
            desactivado={!linhas.length}
          />
          <BotaoCsv<ContaPlano>
            nome="plano_contas"
            linhas={linhas}
            colunas={[{ titulo: 'Código', valor: (c) => c.codigo }, { titulo: 'Descrição', valor: (c) => c.descricao }, { titulo: 'Tipo', valor: (c) => c.tipo }, { titulo: 'Moeda', valor: (c) => c.codigo_moeda }]}
          />
          {gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); form.setFieldsValue({ tipo: 'M' }); setEdicao('novo'); }}>Nova conta</Button>}
        </Space>
      </Flex>
      <Table<ContaPlano>
        scroll={scrollTabela()}
        rowKey="id"
        size="small"
        loading={plano.isLoading}
        dataSource={linhas}
        pagination={{ pageSize: 100, showSizeChanger: true, showTotal: (n) => `${n} conta(s)` }}
        columns={colunasPlano}
      />
      <Modal title={edicao === 'novo' ? 'Nova conta' : `Editar conta ${edicao?.codigo ?? ''}`} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" confirmLoading={mutacao.isPending} onOk={() => form.submit()}>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) =>
            mutacao.mutate(edicao && edicao !== 'novo' ? { metodo: 'put', url: `/contabilidade/plano-contas/${edicao.id}`, corpo: v } : { metodo: 'post', url: '/contabilidade/plano-contas', corpo: v })
          }
        >
          <Form.Item name="codigo" label="Código" rules={[{ required: true }, { pattern: /^[0-9A-Za-z.]+$/, message: 'Só algarismos, letras e pontos.' }]}>
            <Input maxLength={20} />
          </Form.Item>
          <Form.Item name="descricao" label="Descrição" rules={[{ required: true }]}>
            <Input maxLength={255} />
          </Form.Item>
          <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
            <Select options={[{ value: 'M', label: 'Movimento (aceita lançamentos)' }, { value: 'T', label: 'Totalizadora' }]} />
          </Form.Item>
          <Space wrap>
            <Form.Item name="codigo_moeda" label="Moeda">
              <Input maxLength={10} style={{ width: 100 }} placeholder="AOA" />
            </Form.Item>
            <Form.Item name="natureza_conta" label="Natureza">
              <Input maxLength={255} />
            </Form.Item>
          </Space>
        </Form>
      </Modal>
    </Card>
  );
}

interface GrupoReciclagem {
  diario_id: number;
  diario: string;
  chave: string;
  numero_documento: string | null;
  data_documento: string;
  eliminado_em: string;
  linhas: number;
  debito: string;
  credito: string;
  equilibrado: boolean;
}

function Reciclagem() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const consulta = useQuery({ queryKey: ['contab', 'reciclagem'], queryFn: () => obter<GrupoReciclagem[]>('/contabilidade/reciclagem') });
  const [seleccao, setSeleccao] = useState<string[]>([]);
  const chave = (g: GrupoReciclagem) => `${g.diario_id}|${g.chave}`;
  const grupos = (consulta.data ?? []).filter((g) => seleccao.includes(chave(g))).map((g) => ({ diario_id: g.diario_id, chave: g.chave }));
  const mutacao = useMutation({
    mutationFn: ({ metodo, url, corpo }: { metodo: 'post' | 'delete'; url: string; corpo: unknown }) => enviar<unknown>(metodo, url, corpo),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setSeleccao([]);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e),
  });
  const gerir = pode('aux_reciclagem');

  return (
    <Card
      extra={
        gerir && (
          <Space wrap>
            <Button disabled={!grupos.length} loading={mutacao.isPending} onClick={() => mutacao.mutate({ metodo: 'post', url: '/contabilidade/reciclagem/restaurar', corpo: { grupos } })}>
              Restaurar ({grupos.length})
            </Button>
            <Popconfirm title={`Eliminar definitivamente ${grupos.length} documento(s)?`} okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} disabled={!grupos.length} onConfirm={() => mutacao.mutateAsync({ metodo: 'post', url: '/contabilidade/reciclagem/eliminar', corpo: { grupos } })}>
              <Button danger disabled={!grupos.length}>Eliminar definitivamente</Button>
            </Popconfirm>
            <Button
              danger
              type="primary"
              disabled={!consulta.data?.length}
              onClick={() =>
                Modal.confirm({
                  title: 'Esvaziar a reciclagem?',
                  content: 'Todas as linhas eliminadas são apagadas definitivamente. Esta operação não se desfaz.',
                  okText: 'Esvaziar',
                  okButtonProps: { danger: true },
                  cancelText: 'Cancelar',
                  onOk: () => mutacao.mutateAsync({ metodo: 'delete', url: '/contabilidade/reciclagem', corpo: { confirmar: true } }),
                })
              }
            >
              Esvaziar
            </Button>
          </Space>
        )
      }
    >
      <Typography.Paragraph type="secondary">Lançamentos eliminados (por documento). Restaurar devolve-os ao Diário; eliminar definitivamente apaga-os.</Typography.Paragraph>
      <Table<GrupoReciclagem>
        rowKey={chave}
        size="small"
        loading={consulta.isLoading}
        dataSource={consulta.data}
        pagination={{ pageSize: 50, showTotal: (n) => `${n} documento(s)` }}
        rowSelection={gerir ? { selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as string[]) } : undefined}
        scroll={scrollTabela()}
        columns={[
          { title: 'Diário', dataIndex: 'diario' },
          { title: 'Lançamento', dataIndex: 'chave' },
          { title: 'Documento', dataIndex: 'numero_documento' },
          { title: 'Data', dataIndex: 'data_documento', render: formatarData },
          { title: 'Eliminado em', dataIndex: 'eliminado_em', render: formatarDataHora },
          { title: 'Linhas', dataIndex: 'linhas', align: 'right' },
          { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
          { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
          { title: '', dataIndex: 'equilibrado', render: (v: boolean) => (v ? null : <Tag color="red">Desequilibrado</Tag>) },
        ]}
      />
    </Card>
  );
}
