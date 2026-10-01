import { Alert, Button, Card, Checkbox, DatePicker, Flex, Form, Input, InputNumber, Modal, Popconfirm, Radio, Select, Space, Switch, Table, Tabs, Tag, Typography, Upload, message } from 'antd';
import { CloudSyncOutlined, DeleteOutlined, EditOutlined, PlusOutlined, StarOutlined, UploadOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { enviarFicheiro } from '@/modulos/contab/comum/ficheiros';
import { eFolhaExcel, lerTabelaColada } from './comum/regras';

interface Moeda {
  id: number;
  codigo: string;
  nome: string;
  simbolo: string;
  casas_decimais: number;
  ativo: boolean;
  base: boolean;
}

interface Cambio {
  id: number;
  codigo_moeda: string;
  data_taxa: string;
  taxa: number;
  ambito: 'TODAS' | 'EMPRESA';
  empresa_id: number | null;
  fonte_dados: string | null;
  taxa_compra_bai: number | null;
  taxa_venda_bai: number | null;
}

interface ResultadoImportacao {
  novos: number;
  existentes: number;
  bloqueados: { linha: number; codigo_moeda: string; data_taxa: string }[];
  rejeitadas: { linha: number; motivo: string }[];
  importados: number;
  actualizados: number;
  mantidos: number;
}

interface PrevisaoBai {
  data: string;
  itens: {
    codigo_moeda: string;
    nome: string;
    disponivel: boolean;
    compra: number | null;
    venda: number | null;
    media: number | null;
    ultimo: { taxa: number; data_taxa: string; fonte_dados: string | null } | null;
    variacao: number | null;
    alerta: boolean;
  }[];
}

const CHAVE = ['sistema', 'moedas'];
const taxa = (v: number | null | undefined) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 4, maximumFractionDigits: 6 }).format(v));

function useMoedas() {
  return useQuery({ queryKey: [...CHAVE, 'lista'], queryFn: () => obter<Moeda[]>('/sistema/moedas') });
}

/** Moeda funcional da empresa activa (GET /api/sistema/moedas/funcional — config_moedas_view ou config_moedas_gerir). */
interface MoedaFuncional {
  codigo_moeda: string;
  nome: string | null;
  simbolo: string | null;
  casas_decimais: number;
  base: boolean;
}

function useMoedaFuncional(activo: boolean) {
  return useQuery({ queryKey: [...CHAVE, 'funcional'], queryFn: () => obter<MoedaFuncional>('/sistema/moedas/funcional'), enabled: activo, staleTime: 300_000 });
}

/** Configurações › Moedas e câmbios (config_moedas): moedas, moeda funcional, câmbios, importação e câmbios do BAI. */
export default function Moedas() {
  const { pode } = useSessao();
  const gerir = pode('config_moedas_gerir');
  return (
    <>
      <CabecalhoPagina titulo="Moedas e câmbios" subtitulo="Moedas activas, taxas de câmbio por data (Kz por unidade de moeda) e actualização a partir do BAI" />
      <Tabs
        items={[
          { key: 'cambios', label: 'Câmbios', children: <Cambios gerir={gerir} /> },
          { key: 'moedas', label: 'Moedas', children: <ListaMoedas gerir={gerir} /> },
          ...(gerir ? [{ key: 'importar', label: 'Importar', children: <Importar /> }, { key: 'bai', label: 'Câmbios do BAI', children: <Bai /> }] : []),
        ]}
      />
    </>
  );
}

function ListaMoedas({ gerir }: { gerir: boolean }) {
  const { pode } = useSessao();
  const moedas = useMoedas();
  const funcional = useMoedaFuncional(pode('config_moedas_view', 'config_moedas_gerir'));
  const [edicao, setEdicao] = useState<Moeda | 'nova' | null>(null);
  const [form] = Form.useForm<{ codigo: string; nome: string; simbolo: string; casas_decimais?: number; ativo?: boolean }>();
  const accao = useAccao({ invalidar: [CHAVE, ['sistema', 'gestao-empresas']], aoSucesso: () => setEdicao(null) });
  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    if (edicao !== 'nova') form.setFieldsValue(edicao);
    else form.setFieldsValue({ casas_decimais: 2 });
  }, [edicao, form]);
  return (
    <Card>
      <Flex justify="space-between" align="center" wrap gap={8} style={{ marginBottom: 12 }}>
        <Typography.Text>
          {funcional.data ? (
            <>
              Moeda funcional da empresa activa: <strong>{funcional.data.codigo_moeda}</strong>
              {funcional.data.nome ? ` — ${funcional.data.nome}` : ''}
            </>
          ) : null}
        </Typography.Text>
        {gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>Nova moeda</Button>}
      </Flex>
      <Table<Moeda>
        rowKey="id"
        size="middle"
        loading={moedas.isLoading}
        dataSource={moedas.data}
        pagination={false}
        columns={[
          { title: 'Código', dataIndex: 'codigo', width: 90, render: (v: string, m) => (<><strong>{v}</strong>{m.base && <Tag color="gold" style={{ marginLeft: 6 }}>base</Tag>}{funcional.data?.codigo_moeda === v && <Tag color="blue" style={{ marginLeft: 6 }}>funcional</Tag>}</>) },
          { title: 'Nome', dataIndex: 'nome' },
          { title: 'Símbolo', dataIndex: 'simbolo', width: 90 },
          { title: 'Casas', dataIndex: 'casas_decimais', width: 80 },
          { title: 'Estado', dataIndex: 'ativo', width: 100, render: (a: boolean) => (a ? <Tag color="green">Activa</Tag> : <Tag>Inactiva</Tag>) },
          ...(gerir
            ? [
                {
                  title: '',
                  key: 'a',
                  width: 110,
                  render: (_: unknown, m: Moeda) => (
                    <Space>
                      <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" title="Editar" onClick={() => setEdicao(m)} />
                      <Popconfirm
                        title={`Definir ${m.codigo} como moeda funcional da empresa activa?`}
                        description="A contabilidade da empresa passa a ser apresentada nesta moeda."
                        okText="Definir"
                        cancelText="Cancelar"
                        onConfirm={() => accao.mutateAsync({ metodo: 'put', url: '/sistema/moedas/funcional', dados: { codigo_moeda: m.codigo } })}
                      >
                        <Button size="small" type="text" icon={<StarOutlined />} disabled={!m.ativo} aria-label="Moeda funcional" title="Definir como moeda funcional" />
                      </Popconfirm>
                    </Space>
                  ),
                },
              ]
            : []),
        ]}
      />
      <Modal title={edicao === 'nova' ? 'Nova moeda' : `Moeda ${edicao?.codigo ?? ''}`} open={!!edicao} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) =>
            edicao === 'nova'
              ? accao.mutate({ url: '/sistema/moedas', dados: { codigo: v.codigo.toUpperCase(), nome: v.nome, simbolo: v.simbolo, casas_decimais: v.casas_decimais } })
              : accao.mutate({ metodo: 'put', url: `/sistema/moedas/${(edicao as Moeda).id}`, dados: { nome: v.nome, simbolo: v.simbolo, ativo: v.ativo } })
          }
        >
          <Form.Item name="codigo" label="Código ISO 4217" rules={[{ required: true, pattern: /^[A-Za-z]{3}$/, message: '3 letras (ex.: USD).' }]}>
            <Input maxLength={3} disabled={edicao !== 'nova'} style={{ textTransform: 'uppercase', width: 120 }} />
          </Form.Item>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
          <Form.Item name="simbolo" label="Símbolo" rules={[{ required: true, message: 'Indique o símbolo.' }]}><Input maxLength={10} style={{ width: 120 }} /></Form.Item>
          {edicao === 'nova' ? (
            <Form.Item name="casas_decimais" label="Casas decimais"><InputNumber min={0} max={6} /></Form.Item>
          ) : (
            <Form.Item name="ativo" label="Activa" valuePropName="checked"><Switch disabled={typeof edicao === 'object' && !!edicao?.base} /></Form.Item>
          )}
        </Form>
      </Modal>
    </Card>
  );
}

function Cambios({ gerir }: { gerir: boolean }) {
  const moedas = useMoedas();
  const [filtros, setFiltros] = useState<{ codigo_moeda?: string; ambito?: string; de?: string; ate?: string }>({});
  const [edicao, setEdicao] = useState<Cambio | 'novo' | null>(null);
  const [form] = Form.useForm<{ data_taxa: Dayjs; codigo_moeda: string; taxa: number; ambito: 'TODAS' | 'EMPRESA'; fonte_dados?: string; substituir?: boolean }>();
  const accao = useAccao({ invalidar: [CHAVE], aoSucesso: () => setEdicao(null) });
  const estrangeiras = (moedas.data ?? []).filter((m) => !m.base && m.ativo);
  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    if (edicao === 'novo') form.setFieldsValue({ data_taxa: dayjs(), ambito: 'TODAS', fonte_dados: 'MANUAL' });
    else form.setFieldsValue({ ...edicao, data_taxa: dayjs(edicao.data_taxa), fonte_dados: edicao.fonte_dados ?? undefined });
  }, [edicao, form]);
  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 12 }}>
        <Space wrap>
          <Select allowClear placeholder="Moeda" style={{ width: 140 }} value={filtros.codigo_moeda} onChange={(v?: string) => setFiltros({ ...filtros, codigo_moeda: v })} options={estrangeiras.map((m) => ({ value: m.codigo, label: m.codigo }))} />
          <Select allowClear placeholder="Âmbito" style={{ width: 170 }} value={filtros.ambito} onChange={(v?: string) => setFiltros({ ...filtros, ambito: v })} options={[{ value: 'TODAS', label: 'Todas as empresas' }, { value: 'EMPRESA', label: 'Só esta empresa' }]} />
          <DatePicker.RangePicker format="DD/MM/YYYY" allowEmpty={[true, true]} onChange={(v) => setFiltros({ ...filtros, de: dataApi(v?.[0]), ate: dataApi(v?.[1]) })} />
        </Space>
        {gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('novo')}>Novo câmbio</Button>}
      </Flex>
      <TabelaApi<Cambio>
        url="/sistema/cambios"
        chaveConsulta={[...CHAVE, 'cambios']}
        filtros={filtros}
        porPagina={50}
        size="small"
        columns={[
          { title: 'Data', dataIndex: 'data_taxa', width: 110, render: formatarData },
          { title: 'Moeda', dataIndex: 'codigo_moeda', width: 90, render: (v: string) => <strong>{v}</strong> },
          { title: 'Taxa (Kz)', dataIndex: 'taxa', align: 'right', render: taxa },
          { title: 'Compra BAI', dataIndex: 'taxa_compra_bai', align: 'right', render: taxa },
          { title: 'Venda BAI', dataIndex: 'taxa_venda_bai', align: 'right', render: taxa },
          { title: 'Âmbito', dataIndex: 'ambito', render: (a: string) => (a === 'EMPRESA' ? <Tag color="blue">Esta empresa</Tag> : <Tag>Todas</Tag>) },
          { title: 'Origem', dataIndex: 'fonte_dados', render: (v: string | null) => v ?? '—' },
          ...(gerir
            ? [
                {
                  title: '',
                  key: 'a',
                  width: 90,
                  render: (_: unknown, c: Cambio) => (
                    <Space>
                      <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" title="Editar" onClick={() => setEdicao(c)} />
                      <Popconfirm title={`Eliminar o câmbio ${c.codigo_moeda} de ${formatarData(c.data_taxa)}?`} description="Não é possível se já foi usado em documentos." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/sistema/cambios/${c.id}` })}>
                        <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" title="Eliminar" />
                      </Popconfirm>
                    </Space>
                  ),
                },
              ]
            : []),
        ]}
      />
      <Modal title={edicao === 'novo' ? 'Novo câmbio' : 'Editar câmbio'} open={!!edicao} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) =>
            accao.mutate({
              metodo: edicao === 'novo' ? 'post' : 'put',
              url: edicao === 'novo' ? '/sistema/cambios' : `/sistema/cambios/${(edicao as Cambio).id}`,
              dados: { ...v, data_taxa: dataApi(v.data_taxa), taxa: String(v.taxa) },
            })
          }
        >
          <Space size={16} wrap>
            <Form.Item name="data_taxa" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
            <Form.Item name="codigo_moeda" label="Moeda" rules={[{ required: true, message: 'Escolha a moeda.' }]}>
              <Select style={{ width: 140 }} options={estrangeiras.map((m) => ({ value: m.codigo, label: `${m.codigo} — ${m.nome}` }))} />
            </Form.Item>
            <Form.Item name="taxa" label="Taxa (Kz por 1 unidade)" rules={[{ required: true, message: 'Indique a taxa.' }]}>
              <InputNumber min={0.000001} step={0.0001} decimalSeparator="," style={{ width: 180 }} />
            </Form.Item>
          </Space>
          <Form.Item name="ambito" label="Âmbito">
            <Radio.Group options={[{ value: 'TODAS', label: 'Todas as empresas' }, { value: 'EMPRESA', label: 'Só a empresa activa' }]} />
          </Form.Item>
          <Form.Item name="fonte_dados" label="Origem"><Input maxLength={50} /></Form.Item>
          <Form.Item name="substituir" valuePropName="checked"><Checkbox>Substituir se já existir câmbio nesta data</Checkbox></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}

function Importar() {
  const cliente = useQueryClient();
  const [conteudo, setConteudo] = useState('');
  const [decisao, setDecisao] = useState<'IGNORAR' | 'ACTUALIZAR'>('IGNORAR');
  const [simulacao, setSimulacao] = useState<ResultadoImportacao | null>(null);
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const linhas = lerTabelaColada(conteudo);
  const executar = useMutation({
    // .xlsx/.xls: o ficheiro vai ao servidor (multipart) e é lido lá; texto colado ou CSV: linhas em JSON
    mutationFn: (simular: boolean) =>
      ficheiro
        ? enviarFicheiro<ResultadoImportacao>('/sistema/cambios/importar', ficheiro, { decisao, simular })
        : enviar<ResultadoImportacao>('post', '/sistema/cambios/importar', { linhas, decisao, simular }),
    onSuccess: ({ dados, mensagem }, simular) => {
      if (simular) setSimulacao(dados);
      else {
        message.success(mensagem);
        setSimulacao(null);
        setConteudo('');
        setFicheiro(null);
        void cliente.invalidateQueries({ queryKey: CHAVE });
      }
    },
    onError: (e) => notificarErro(e, 'Não foi possível importar'),
  });
  return (
    <Card>
      <Typography.Paragraph>
        Carregue o ficheiro Excel (.xlsx) ou CSV, ou cole as linhas copiadas do Excel (com cabeçalho). Colunas: <Typography.Text code>Data</Typography.Text> <Typography.Text code>Moeda</Typography.Text>{' '}
        <Typography.Text code>Taxa</Typography.Text> e, opcionalmente, <Typography.Text code>Origem</Typography.Text> <Typography.Text code>Âmbito</Typography.Text> (Todas | Empresa).
      </Typography.Paragraph>
      <Space direction="vertical" style={{ width: '100%' }}>
        <Space wrap>
          <Upload
            accept=".xlsx,.xls,.csv,.txt"
            showUploadList={false}
            beforeUpload={async (f) => {
              if (eFolhaExcel(f.name)) {
                setFicheiro(f);
                setConteudo('');
              } else {
                setFicheiro(null);
                setConteudo(await f.text());
              }
              setSimulacao(null);
              return false;
            }}
          >
            <Button icon={<UploadOutlined />}>Carregar ficheiro (.xlsx ou CSV)</Button>
          </Upload>
          {ficheiro && (
            <Tag closable onClose={() => { setFicheiro(null); setSimulacao(null); }} color="blue">
              {ficheiro.name}
            </Tag>
          )}
        </Space>
        <Input.TextArea rows={8} value={conteudo} disabled={ficheiro !== null} onChange={(e) => { setConteudo(e.target.value); setSimulacao(null); }} placeholder={'Data\tMoeda\tTaxa\n2026-09-30\tUSD\t912,50'} style={{ fontFamily: 'monospace' }} />
        <Flex gap={16} wrap align="center">
          <Typography.Text>{ficheiro ? 'Ficheiro lido no servidor' : `${linhas.length} linha(s) lida(s)`}</Typography.Text>
          <Radio.Group value={decisao} onChange={(e) => setDecisao(e.target.value)} options={[{ value: 'IGNORAR', label: 'Manter os câmbios existentes' }, { value: 'ACTUALIZAR', label: 'Actualizar os existentes' }]} />
          <Button disabled={!ficheiro && !linhas.length} loading={executar.isPending} onClick={() => executar.mutate(true)}>Validar (simulação)</Button>
          <Button type="primary" disabled={!simulacao || simulacao.novos + simulacao.existentes === 0} loading={executar.isPending} onClick={() => executar.mutate(false)}>Importar</Button>
        </Flex>
        {simulacao && (
          <Alert
            type={simulacao.rejeitadas.length || simulacao.bloqueados.length ? 'warning' : 'success'}
            showIcon
            message={`${simulacao.novos} novo(s), ${simulacao.existentes} já existente(s), ${simulacao.bloqueados.length} bloqueado(s) (em uso), ${simulacao.rejeitadas.length} rejeitado(s).`}
            description={
              (simulacao.rejeitadas.length > 0 || simulacao.bloqueados.length > 0) && (
                <ul style={{ paddingLeft: 18, margin: 0, maxHeight: 200, overflow: 'auto' }}>
                  {simulacao.rejeitadas.map((r) => <li key={`r${r.linha}`}>Linha {r.linha}: {r.motivo}</li>)}
                  {simulacao.bloqueados.map((b) => <li key={`b${b.linha}`}>Linha {b.linha}: {b.codigo_moeda} de {formatarData(b.data_taxa)} já usado em documentos (não é alterado)</li>)}
                </ul>
              )
            }
          />
        )}
      </Space>
    </Card>
  );
}

function Bai() {
  const cliente = useQueryClient();
  const [escolhidas, setEscolhidas] = useState<string[]>([]);
  const previsao = useQuery({ queryKey: [...CHAVE, 'bai'], queryFn: () => obter<PrevisaoBai>('/sistema/cambios/bai'), enabled: false, retry: false });
  const gravar = useMutation({
    mutationFn: () => enviar<{ novos: number; substituidos: number; bloqueados: string[] }>('post', '/sistema/cambios/bai', { moedas: escolhidas }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      if (dados.bloqueados?.length) Modal.warning({ title: 'Câmbios não substituídos', content: `Já usados em documentos: ${dados.bloqueados.join(', ')}.` });
      void cliente.invalidateQueries({ queryKey: CHAVE });
    },
    onError: (e) => notificarErro(e),
  });
  useEffect(() => {
    if (previsao.error) notificarErro(previsao.error, 'Não foi possível ler os câmbios do BAI');
  }, [previsao.error]);
  useEffect(() => {
    if (previsao.data) setEscolhidas(previsao.data.itens.filter((i) => i.disponivel && !i.alerta).map((i) => i.codigo_moeda));
  }, [previsao.data]);
  return (
    <Card>
      <Flex gap={12} align="center" wrap style={{ marginBottom: 12 }}>
        <Button icon={<CloudSyncOutlined />} loading={previsao.isFetching} onClick={() => void previsao.refetch()}>Consultar BAI</Button>
        {previsao.data && <Typography.Text type="secondary">Câmbios de {formatarData(previsao.data.data)} (média compra/venda de divisas)</Typography.Text>}
      </Flex>
      {previsao.data && (
        <>
          <Table
            size="small"
            rowKey="codigo_moeda"
            pagination={false}
            dataSource={previsao.data.itens}
            rowSelection={{ selectedRowKeys: escolhidas, onChange: (k) => setEscolhidas(k as string[]), getCheckboxProps: (i) => ({ disabled: !i.disponivel }) }}
            columns={[
              { title: 'Moeda', dataIndex: 'codigo_moeda', render: (v: string, i) => <><strong>{v}</strong> <Typography.Text type="secondary">{i.nome}</Typography.Text></> },
              { title: 'Compra', dataIndex: 'compra', align: 'right', render: taxa },
              { title: 'Venda', dataIndex: 'venda', align: 'right', render: taxa },
              { title: 'Média (a gravar)', dataIndex: 'media', align: 'right', render: (v: number | null) => <strong>{taxa(v)}</strong> },
              { title: 'Último registado', dataIndex: 'ultimo', render: (u: PrevisaoBai['itens'][number]['ultimo']) => (u ? `${taxa(u.taxa)} em ${formatarData(u.data_taxa)}` : '—') },
              {
                title: 'Variação',
                dataIndex: 'variacao',
                align: 'right',
                render: (v: number | null, i) => (v === null ? '—' : <Tag color={i.alerta ? 'red' : undefined}>{v > 0 ? '+' : ''}{formatarNumero(v)}%</Tag>),
              },
              { title: '', dataIndex: 'disponivel', render: (d: boolean) => (d ? null : <Tag>Indisponível</Tag>) },
            ]}
          />
          {previsao.data.itens.some((i) => i.alerta) && <Alert style={{ marginTop: 12 }} type="warning" showIcon message="Variações grandes face ao último câmbio não ficam marcadas por omissão: confirme antes de gravar." />}
          <Flex justify="end" style={{ marginTop: 12 }}>
            <Button type="primary" disabled={!escolhidas.length} loading={gravar.isPending} onClick={() => gravar.mutate()}>Gravar {escolhidas.length} câmbio(s)</Button>
          </Flex>
        </>
      )}
    </Card>
  );
}
