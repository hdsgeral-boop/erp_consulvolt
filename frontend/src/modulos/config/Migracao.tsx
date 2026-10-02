import { Alert, Button, Card, Checkbox, Col, Empty, Flex, Form, Input, InputNumber, List, Radio, Row, Select, Skeleton, Space, Table, Tabs, Tag, Typography, Upload, message } from 'antd';
import { DownloadOutlined, UploadOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { descarregar, enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BarraFiltros, scrollTabela } from '@/componentes/responsivo';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import { usePlanoContas } from '@/modulos/contab/comum/dados';
import { enviarFicheiro } from '@/modulos/contab/comum/ficheiros';
import { eFolhaExcel, lerTabelaColada } from './comum/regras';

interface ColunaModelo {
  cabecalho: string;
  campo: string;
  obrigatorio: boolean;
  instrucao: string;
  exemplo: string | number | null;
}

interface Modelo {
  entidade: string;
  rotulo: string;
  permissao: string;
  colunas: ColunaModelo[];
}

interface ResultadoImportacao {
  entidade: string;
  simulacao: boolean;
  novos: number;
  existentes: unknown[];
  repetidos: number;
  rejeitadas: { linha: number; motivo: string }[];
  criados: number;
  actualizados: number;
  ignorados: number;
  erros: { linha: number; motivo: string }[];
  /** Só nas importações a partir de ficheiro .xlsx (lido no servidor). */
  linhas_lidas?: number;
  linhas_exemplo_ignoradas?: number;
}

/** Configurações › Migração de dados (config_migracao): modelos Excel, importação em massa com simulação e edição em massa. */
export default function Migracao() {
  const { pode } = useSessao();
  const massa = pode('vendas_clientes_gerir', 'compras_forn_gerir', 'vendas_produtos_gerir');
  return (
    <>
      <CabecalhoPagina titulo="Migração de dados" subtitulo="Carregamento inicial em massa a partir dos modelos e alterações em massa de clientes, fornecedores e produtos" />
      <Tabs
        items={[
          { key: 'importar', label: 'Importação em massa', children: <Importacao /> },
          ...(massa ? [{ key: 'massa', label: 'Edição em massa', children: <EdicaoMassa /> }] : []),
        ]}
      />
    </>
  );
}

function Importacao() {
  const cliente = useQueryClient();
  const modelos = useQuery({ queryKey: ['sistema', 'migracao', 'modelos'], queryFn: () => obter<Modelo[]>('/sistema/migracao/modelos'), staleTime: 3_600_000 });
  const [entidade, setEntidade] = useState<string | undefined>();
  const [conteudo, setConteudo] = useState('');
  const [decisao, setDecisao] = useState<'IGNORAR' | 'ACTUALIZAR'>('IGNORAR');
  const [contaOmissao, setContaOmissao] = useState('');
  const [resultado, setResultado] = useState<ResultadoImportacao | null>(null);
  const [aDescarregar, setADescarregar] = useState(false);
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const modelo = modelos.data?.find((m) => m.entidade === entidade);
  const linhas = lerTabelaColada(conteudo);
  const cabecalhosEmFalta = modelo && linhas.length && !ficheiro ? modelo.colunas.filter((c) => c.obrigatorio && !(c.cabecalho in linhas[0])).map((c) => c.cabecalho) : [];

  const executar = useMutation({
    // .xlsx/.xls: o próprio modelo preenchido vai ao servidor (multipart) e é lido lá; texto colado ou CSV: linhas em JSON
    mutationFn: (simular: boolean) =>
      ficheiro
        ? enviarFicheiro<ResultadoImportacao>(`/sistema/migracao/importar/${entidade}`, ficheiro, { decisao, simular, conta_omissao: contaOmissao || undefined })
        : enviar<ResultadoImportacao>('post', `/sistema/migracao/importar/${entidade}`, { linhas, decisao, simular, conta_omissao: contaOmissao || undefined }),
    onSuccess: ({ dados, mensagem }, simular) => {
      setResultado(dados);
      if (!simular) {
        message.success(mensagem);
        setConteudo('');
        setFicheiro(null);
        void cliente.invalidateQueries();
      }
    },
    onError: (e) => notificarErro(e, 'Não foi possível importar'),
  });

  if (modelos.isLoading) return <Skeleton active />;
  if (!modelos.data?.length) return <Empty description="Não tem permissão para importar nenhuma entidade (cada entidade exige a tarefa de gestão do seu módulo)." />;

  return (
    <Row gutter={16}>
      <Col xs={24} lg={8}>
        <Card size="small" title="Entidades">
          <List
            size="small"
            dataSource={modelos.data}
            renderItem={(m) => (
              <List.Item
                onClick={() => {
                  setEntidade(m.entidade);
                  setResultado(null);
                  setConteudo('');
                  setFicheiro(null);
                }}
                style={{ cursor: 'pointer', background: m.entidade === entidade ? '#e6f4ff' : undefined, paddingLeft: 8 }}
              >
                {m.rotulo}
              </List.Item>
            )}
          />
        </Card>
      </Col>
      <Col xs={24} lg={16}>
        {!modelo ? (
          <Empty description="Escolha a entidade a importar." />
        ) : (
          <Card
            size="small"
            title={modelo.rotulo}
            extra={
              <Button
                icon={<DownloadOutlined />}
                loading={aDescarregar}
                onClick={async () => {
                  setADescarregar(true);
                  try {
                    await descarregar(`/sistema/migracao/modelos/${modelo.entidade}`, undefined, `Template_${modelo.entidade}.xlsx`);
                  } catch (e) {
                    notificarErro(e, 'Não foi possível descarregar o modelo');
                  } finally {
                    setADescarregar(false);
                  }
                }}
              >
                Modelo Excel
              </Button>
            }
          >
            <Table
              size="small"
              rowKey="campo"
              pagination={false}
              scroll={scrollTabela()}
              dataSource={modelo.colunas}
              style={{ marginBottom: 12 }}
              columns={[
                { title: 'Coluna', dataIndex: 'cabecalho', render: (v: string, c) => <>{v}{c.obrigatorio && <Tag color="red" style={{ marginLeft: 6 }}>obrigatória</Tag>}</> },
                { title: 'Instrução', dataIndex: 'instrucao' },
                { title: 'Exemplo', dataIndex: 'exemplo', render: (v: unknown) => <Typography.Text code>{String(v ?? '')}</Typography.Text> },
              ]}
            />
            <Alert
              type="info"
              showIcon
              style={{ marginBottom: 12 }}
              message="Preencha o modelo no Excel e carregue o ficheiro .xlsx (a linha de exemplo, se ficar, é ignorada). Também pode colar a tabela (com o cabeçalho) ou carregar um CSV."
            />
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
                    setResultado(null);
                    return false;
                  }}
                >
                  <Button icon={<UploadOutlined />}>Carregar ficheiro (.xlsx ou CSV)</Button>
                </Upload>
                {ficheiro && (
                  <Tag closable color="blue" onClose={() => { setFicheiro(null); setResultado(null); }}>
                    {ficheiro.name}
                  </Tag>
                )}
              </Space>
              <Input.TextArea rows={8} value={conteudo} disabled={ficheiro !== null} onChange={(e) => { setConteudo(e.target.value); setResultado(null); }} placeholder={modelo.colunas.map((c) => c.cabecalho).join('\t')} style={{ fontFamily: 'monospace' }} />
              {cabecalhosEmFalta.length > 0 && <Alert type="error" showIcon message={`Faltam colunas obrigatórias no cabeçalho: ${cabecalhosEmFalta.join(', ')}.`} />}
              <Flex gap={16} wrap align="center">
                <Typography.Text>{ficheiro ? (resultado?.linhas_lidas !== undefined ? `${resultado.linhas_lidas} linha(s) no ficheiro` : 'Ficheiro lido no servidor') : `${linhas.length} linha(s)`}</Typography.Text>
                <Radio.Group value={decisao} onChange={(e) => setDecisao(e.target.value)} options={[{ value: 'IGNORAR', label: 'Ignorar existentes' }, { value: 'ACTUALIZAR', label: 'Actualizar existentes' }]} />
                {modelo.entidade === 'terceiros' && <Input style={{ width: 200 }} placeholder="Conta por omissão (opcional)" value={contaOmissao} onChange={(e) => setContaOmissao(e.target.value)} maxLength={20} />}
              </Flex>
              <Space>
                <Button disabled={(!ficheiro && !linhas.length) || cabecalhosEmFalta.length > 0} loading={executar.isPending} onClick={() => executar.mutate(true)}>
                  Validar (simulação)
                </Button>
                <Button type="primary" disabled={!resultado?.simulacao || resultado.rejeitadas.length > 0 || resultado.erros.length > 0} loading={executar.isPending} onClick={() => executar.mutate(false)}>
                  Importar
                </Button>
              </Space>
              {resultado && (
                <Alert
                  type={resultado.rejeitadas.length || resultado.erros.length ? 'warning' : 'success'}
                  showIcon
                  message={
                    resultado.simulacao
                      ? `Simulação: ${resultado.novos} novo(s), ${resultado.existentes.length} existente(s), ${resultado.repetidos} repetido(s) no ficheiro, ${resultado.rejeitadas.length} rejeitado(s), ${resultado.erros.length} erro(s).${resultado.linhas_exemplo_ignoradas ? ` Linha de exemplo do modelo ignorada.` : ''}`
                      : `Concluído: ${resultado.criados} criado(s), ${resultado.actualizados} actualizado(s), ${resultado.ignorados} ignorado(s).`
                  }
                  description={
                    (resultado.rejeitadas.length > 0 || resultado.erros.length > 0) && (
                      <ul style={{ paddingLeft: 18, margin: 0, maxHeight: 220, overflow: 'auto' }}>
                        {[...resultado.rejeitadas, ...resultado.erros].map((r, i) => <li key={i}>Linha {r.linha}: {r.motivo}</li>)}
                      </ul>
                    )
                  }
                />
              )}
            </Space>
          </Card>
        )}
      </Col>
    </Row>
  );
}

type EntidadeMassa = 'clientes' | 'fornecedores' | 'produtos';

const CONTAS_MASSA: Record<EntidadeMassa, [string, string][]> = {
  clientes: [['codigo_conta', 'Conta do cliente']],
  fornecedores: [['codigo_conta', 'Conta do fornecedor'], ['conta_compra_transitoria', 'Conta de compras transitória']],
  produtos: [
    ['codigo_conta', 'Conta de proveitos'], ['conta_custo', 'Conta de custo'], ['conta_compra', 'Conta de compras'], ['conta_inventario', 'Conta de inventário'],
    ['conta_iva_liquidado', 'IVA liquidado'], ['conta_iva_dedutivel', 'IVA dedutível'], ['conta_quebra', 'Quebras'], ['conta_sobra', 'Sobras'], ['conta_ativo', 'Conta de activo'],
  ],
};

interface RegistoMassa {
  id: number;
  nome: string;
  codigo?: string | null;
  nif?: string | null;
  codigo_conta?: string | null;
  preco_unitario?: string | null;
  contas?: Record<string, string | null> | null;
}

function EdicaoMassa() {
  const { pode } = useSessao();
  const plano = usePlanoContas();
  const disponiveis = (['clientes', 'fornecedores', 'produtos'] as EntidadeMassa[]).filter((e) =>
    pode(e === 'clientes' ? 'vendas_clientes_gerir' : e === 'fornecedores' ? 'compras_forn_gerir' : 'vendas_produtos_gerir'),
  );
  const [entidade, setEntidade] = useState<EntidadeMassa>(disponiveis[0]);
  const [pesquisa, setPesquisa] = useState('');
  const [ids, setIds] = useState<number[]>([]);
  const [form] = Form.useForm();
  const cliente = useQueryClient();
  const contasMov = (plano.data ?? []).filter((c) => c.tipo === 'M').map((c) => ({ value: c.codigo, label: `${c.codigo} — ${c.descricao ?? ''}` }));
  const aplicar = useMutation({
    mutationFn: async () => {
      const v = await form.validateFields();
      const dados: Record<string, unknown> = {};
      for (const k of ['codigo_moeda', 'taxa_imposto', 'movimenta_stock', 'e_servico', 'bloqueado']) if (v[k] !== undefined && v[k] !== null && v[k] !== '') dados[k] = v[k];
      const contas = Object.fromEntries(CONTAS_MASSA[entidade].map(([k]) => [k, v[`conta_${k}`]]).filter(([, x]) => x));
      const preco = entidade === 'produtos' && v.preco_modo && v.preco_valor !== undefined && v.preco_valor !== null ? { modo: v.preco_modo, valor: v.preco_valor } : undefined;
      return enviar<{ alterados: number; avisos: string[] }>('post', `/sistema/migracao/edicao-massa/${entidade}`, { ids, dados, contas, preco });
    },
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      dados.avisos?.forEach((a) => message.warning(a));
      setIds([]);
      form.resetFields();
      void cliente.invalidateQueries();
    },
    onError: (e) => notificarErro(e, 'Não foi possível aplicar as alterações'),
  });
  const simNao = [{ value: true, label: 'Sim' }, { value: false, label: 'Não' }];

  return (
    <Row gutter={16}>
      <Col xs={24} xl={14}>
        <Card size="small">
          <BarraFiltros style={{ marginBottom: 12 }}>
            <Radio.Group value={entidade} optionType="button" onChange={(e) => { setEntidade(e.target.value); setIds([]); form.resetFields(); }} options={disponiveis.map((e) => ({ value: e, label: e[0].toUpperCase() + e.slice(1) }))} />
            <Input.Search placeholder="Pesquisar" allowClear style={{ width: 240 }} onSearch={setPesquisa} />
          </BarraFiltros>
          <TabelaApi<RegistoMassa>
            key={entidade}
            url={entidade === 'produtos' ? '/logistica/produtos' : '/terceiros'}
            chaveConsulta={['sistema', 'migracao', 'massa', entidade]}
            filtros={entidade === 'produtos' ? { pesquisa } : { pesquisa, papel: entidade === 'clientes' ? 'CLIENTE' : 'FORNECEDOR' }}
            size="small"
            porPagina={50}
            scroll={scrollTabela()}
            impressao={{ titulo: `Lista de ${entidade}`, filtros: pesquisa ? [`Pesquisa: ${pesquisa}`] : undefined }}
            rowSelection={{ selectedRowKeys: ids, preserveSelectedRowKeys: true, onChange: (k) => setIds(k as number[]) }}
            columns={[
              { title: entidade === 'produtos' ? 'Código' : 'NIF', key: 'c', render: (_, r) => (entidade === 'produtos' ? r.codigo : r.nif) ?? '—' },
              { title: 'Nome', dataIndex: 'nome' },
              { title: 'Conta', key: 'conta', render: (_, r) => r.codigo_conta ?? r.contas?.codigo_conta ?? '—' },
              ...(entidade === 'produtos' ? [{ title: 'Preço', dataIndex: 'preco_unitario', align: 'right' as const, render: (v: string | null) => formatarKz(v) }] : []),
            ]}
          />
        </Card>
      </Col>
      <Col xs={24} xl={10}>
        <Card size="small" title={`Alterações a aplicar a ${ids.length} registo(s)`}>
          <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>Só os campos preenchidos são alterados.</Typography.Paragraph>
          <Form form={form} layout="vertical">
            {entidade !== 'produtos' && (
              <Form.Item name="codigo_moeda" label="Moeda" normalize={(v: string) => v?.toUpperCase()} rules={[{ len: 3, message: '3 letras.' }]}>
                <Input maxLength={3} style={{ width: 120 }} />
              </Form.Item>
            )}
            {entidade === 'produtos' && (
              <Row gutter={8}>
                <Col xs={24} md={12}>
                  <Form.Item name="taxa_imposto" label="Taxa de IVA">
                    <Select allowClear options={[14, 7, 5, 2, 0].map((t) => ({ value: t, label: `${t}%` }))} />
                  </Form.Item>
                </Col>
                <Col xs={24} md={12}><Form.Item name="movimenta_stock" label="Movimenta stock"><Select allowClear options={simNao} /></Form.Item></Col>
                <Col xs={24} md={12}><Form.Item name="e_servico" label="É serviço"><Select allowClear options={simNao} /></Form.Item></Col>
                <Col xs={24} md={12}><Form.Item name="bloqueado" label="Bloqueado"><Select allowClear options={simNao} /></Form.Item></Col>
                <Col xs={24} md={12}>
                  <Form.Item name="preco_modo" label="Preço">
                    <Select allowClear options={[{ value: 'DEFINIR', label: 'Definir valor' }, { value: 'PERCENTAGEM', label: 'Alterar em %' }, { value: 'SOMAR', label: 'Somar valor' }]} />
                  </Form.Item>
                </Col>
                <Col xs={24} md={12}><Form.Item name="preco_valor" label="Valor"><InputNumber style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
              </Row>
            )}
            {CONTAS_MASSA[entidade].map(([k, rotulo]) => (
              <Form.Item key={k} name={`conta_${k}`} label={rotulo}>
                <Select allowClear showSearch optionFilterProp="label" options={[{ value: '__RETIRAR__', label: '(retirar a conta)' }, ...contasMov]} loading={plano.isLoading} />
              </Form.Item>
            ))}
            <Form.Item name="ciente" valuePropName="checked" rules={[{ validator: (_, v) => (v ? Promise.resolve() : Promise.reject(new Error('Confirme.'))) }]}>
              <Checkbox>Confirmo a alteração dos registos seleccionados</Checkbox>
            </Form.Item>
            <Button type="primary" disabled={!ids.length} loading={aplicar.isPending} onClick={() => aplicar.mutate()}>
              Aplicar
            </Button>
          </Form>
        </Card>
      </Col>
    </Row>
  );
}
