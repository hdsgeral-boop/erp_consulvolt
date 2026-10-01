import { Alert, Button, Card, Col, Descriptions, Flex, Form, Image, Input, Modal, Radio, Row, Select, Space, Table, Tag, Typography, Upload, message } from 'antd';
import { CloudDownloadOutlined, CopyOutlined, DeleteOutlined, ImportOutlined, UploadOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';
import { enviarFicheiro } from '@/modulos/contab/comum/ficheiros';
import { descarregarFicheiro, lerComoDataUrl } from './comum/ficheiros';

interface ResumoCopia {
  empresa_origem: { id: number; nome: string | null };
  exportado_em?: string;
  linhas: number;
  por_tabela: Record<string, number>;
  empresa_destino: { id?: number; nome?: string; nif?: string };
  importadas?: number;
  ignoradas?: number;
  nulificadas?: number | Record<string, number>;
}

/** Configurações › Geral (config_geral): cópia de segurança da empresa, importação, clonagem da estrutura e logótipo de entrada. */
export default function ConfigGeral() {
  const { pode, utilizador } = useSessao();
  const superAdmin = utilizador?.papel === 'SUPER_ADMINISTRADOR';
  const nada = !pode('config_backup') && !pode('config_ferramentas') && !superAdmin;
  return (
    <>
      <CabecalhoPagina titulo="Configurações gerais" subtitulo="Cópias de segurança, clonagem de empresas e logótipo do ecrã de entrada" />
      {nada && <Alert type="info" showIcon message="Não tem permissões para as ferramentas deste ecrã (cópias de segurança, ferramentas ou administração do sistema)." />}
      <Row gutter={[16, 16]}>
        {pode('config_backup') && (
          <Col xs={24} xl={12}>
            <Exportar />
          </Col>
        )}
        {pode('config_ferramentas') && (
          <>
            <Col xs={24} xl={12}>
              <Importar />
            </Col>
            <Col xs={24} xl={12}>
              <Clonar />
            </Col>
          </>
        )}
        {superAdmin && (
          <Col xs={24} xl={12}>
            <LogotipoEntrada />
          </Col>
        )}
      </Row>
    </>
  );
}

function Exportar() {
  const { empresa } = useSessao();
  const [aExportar, setAExportar] = useState(false);
  return (
    <Card title="Cópia de segurança da empresa" style={{ height: '100%' }}>
      <Typography.Paragraph>
        Descarrega um ficheiro JSON com todos os dados de <strong>{empresa?.nome}</strong> (tabelas com empresa). Guarde-o num local seguro: contém dados pessoais e
        financeiros.
      </Typography.Paragraph>
      <Button
        type="primary"
        icon={<CloudDownloadOutlined />}
        loading={aExportar}
        onClick={async () => {
          setAExportar(true);
          try {
            await descarregarFicheiro('/sistema/copias/exportar', undefined, `copia_empresa_${empresa?.id ?? ''}.json`);
            message.success('Cópia de segurança descarregada.');
          } catch (e) {
            notificarErro(e, 'Não foi possível exportar a cópia');
          } finally {
            setAExportar(false);
          }
        }}
      >
        Exportar cópia
      </Button>
    </Card>
  );
}

function ResumoDaCopia({ r }: { r: ResumoCopia }) {
  const tabelas = Object.entries(r.por_tabela).map(([tabela, n]) => ({ tabela, n }));
  return (
    <>
      <Descriptions size="small" column={1} bordered style={{ marginBottom: 8 }}>
        <Descriptions.Item label="Origem">{r.empresa_origem.nome ?? `#${r.empresa_origem.id}`}</Descriptions.Item>
        {r.exportado_em && <Descriptions.Item label="Exportada em">{formatarDataHora(r.exportado_em)}</Descriptions.Item>}
        <Descriptions.Item label="Destino">{r.empresa_destino.nome ?? (r.empresa_destino.id ? `Empresa #${r.empresa_destino.id}` : '—')}{r.empresa_destino.nif ? ` (NIF ${r.empresa_destino.nif})` : ''}</Descriptions.Item>
        <Descriptions.Item label="Linhas">{formatarNumero(r.importadas ?? r.linhas)} em {tabelas.length} tabela(s){r.ignoradas ? ` · ${r.ignoradas} ignorada(s)` : ''}</Descriptions.Item>
      </Descriptions>
      <Table size="small" rowKey="tabela" dataSource={tabelas} pagination={tabelas.length > 8 ? { pageSize: 8, size: 'small' } : false} columns={[{ title: 'Tabela', dataIndex: 'tabela' }, { title: 'Linhas', dataIndex: 'n', align: 'right', render: formatarNumero }]} />
    </>
  );
}

function Importar() {
  const { pode, empresas } = useSessao();
  const cliente = useQueryClient();
  const [ficheiro, setFicheiro] = useState<File | null>(null);
  const [modo, setModo] = useState<'existente' | 'nova'>('nova');
  const [form] = Form.useForm<{ empresa_destino_id?: number; nome?: string; nif?: string }>();
  const [simulacao, setSimulacao] = useState<ResumoCopia | null>(null);
  const [resultado, setResultado] = useState<ResumoCopia | null>(null);
  const executar = useMutation({
    mutationFn: async (simular: boolean) => {
      const v = await form.validateFields();
      return enviarFicheiro<ResumoCopia>('/sistema/copias/importar', ficheiro!, modo === 'existente' ? { empresa_destino_id: v.empresa_destino_id, simular } : { nome: v.nome, nif: v.nif, simular });
    },
    onSuccess: ({ dados, mensagem }, simular) => {
      if (simular) setSimulacao(dados);
      else {
        message.success(mensagem);
        setResultado(dados);
        setSimulacao(null);
        setFicheiro(null);
        void cliente.invalidateQueries({ queryKey: ['sistema'] });
      }
    },
    onError: (e) => notificarErro(e, 'Não foi possível importar a cópia'),
  });
  return (
    <Card title="Importar cópia de segurança" style={{ height: '100%' }}>
      <Typography.Paragraph type="secondary">Importa uma cópia para uma empresa nova ou para uma empresa existente vazia. Valide primeiro (simulação).</Typography.Paragraph>
      <Space direction="vertical" style={{ width: '100%' }}>
        <Upload
          accept=".json,application/json"
          maxCount={1}
          beforeUpload={(f) => {
            setFicheiro(f);
            setSimulacao(null);
            return false;
          }}
          onRemove={() => setFicheiro(null)}
          fileList={ficheiro ? [{ uid: '1', name: ficheiro.name, status: 'done' }] : []}
        >
          <Button icon={<UploadOutlined />}>Escolher ficheiro (.json)</Button>
        </Upload>
        <Radio.Group value={modo} onChange={(e) => setModo(e.target.value)}>
          <Radio value="nova" disabled={!pode('config_empresas_gerir')}>Empresa nova</Radio>
          <Radio value="existente">Empresa existente (vazia)</Radio>
        </Radio.Group>
        {!pode('config_empresas_gerir') && modo === 'nova' && <Alert type="warning" showIcon message="Criar uma empresa nova exige também a permissão de gerir empresas." />}
        <Form form={form} layout="vertical">
          {modo === 'existente' ? (
            <Form.Item name="empresa_destino_id" label="Empresa de destino" rules={[{ required: true, message: 'Escolha a empresa.' }]}>
              <Select showSearch optionFilterProp="label" options={empresas.map((e) => ({ value: e.id, label: e.nome }))} />
            </Form.Item>
          ) : (
            <Row gutter={8}>
              <Col span={14}>
                <Form.Item name="nome" label="Nome da nova empresa" rules={[{ required: true, message: 'Indique o nome.' }]}>
                  <Input maxLength={255} />
                </Form.Item>
              </Col>
              <Col span={10}>
                <Form.Item name="nif" label="NIF" rules={[{ required: true, message: 'Indique o NIF.' }]}>
                  <Input maxLength={30} />
                </Form.Item>
              </Col>
            </Row>
          )}
        </Form>
        <Space>
          <Button icon={<ImportOutlined />} disabled={!ficheiro} loading={executar.isPending} onClick={() => executar.mutate(true)}>
            Validar (simulação)
          </Button>
          <Button
            type="primary"
            danger
            disabled={!ficheiro || !simulacao}
            loading={executar.isPending}
            onClick={() =>
              Modal.confirm({
                title: 'Importar a cópia?',
                content: `Vão ser criadas ${formatarNumero(simulacao?.linhas)} linha(s). A operação é feita numa transacção e regista-se na auditoria.`,
                okText: 'Importar',
                cancelText: 'Cancelar',
                okButtonProps: { danger: true },
                onOk: () => executar.mutateAsync(false),
              })
            }
          >
            Importar
          </Button>
        </Space>
        {simulacao && <ResumoDaCopia r={simulacao} />}
        {resultado && (
          <Alert type="success" showIcon message={`Importada para ${resultado.empresa_destino.nome ?? `#${resultado.empresa_destino.id}`}: ${formatarNumero(resultado.importadas)} linha(s).`} description="Volte a entrar ou escolha a empresa no topo para a abrir." />
        )}
      </Space>
    </Card>
  );
}

function Clonar() {
  const { pode, empresas, empresa } = useSessao();
  const cliente = useQueryClient();
  const [form] = Form.useForm<{ empresa_origem_id: number; nome: string; nif: string }>();
  const [simulacao, setSimulacao] = useState<ResumoCopia | null>(null);
  const gerirEmpresas = pode('config_empresas_gerir');
  const executar = useMutation({
    mutationFn: async (simular: boolean) => enviar<ResumoCopia>('post', '/sistema/copias/clonar', { ...(await form.validateFields()), simular }),
    onSuccess: ({ dados, mensagem }, simular) => {
      if (simular) setSimulacao(dados);
      else {
        message.success(mensagem);
        setSimulacao(null);
        form.resetFields();
        void cliente.invalidateQueries({ queryKey: ['sistema'] });
      }
    },
    onError: (e) => notificarErro(e, 'Não foi possível clonar'),
  });
  return (
    <Card title="Clonar estrutura para uma empresa nova" style={{ height: '100%' }}>
      <Typography.Paragraph type="secondary">Copia a estrutura (plano de contas, diários, tabelas, artigos, configurações) sem movimentos para uma nova empresa.</Typography.Paragraph>
      {!gerirEmpresas && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Clonar exige também a permissão de gerir empresas." />}
      <Form form={form} layout="vertical" disabled={!gerirEmpresas} initialValues={{ empresa_origem_id: empresa?.id }} onValuesChange={() => setSimulacao(null)}>
        <Form.Item name="empresa_origem_id" label="Empresa de origem" rules={[{ required: true }]}>
          <Select showSearch optionFilterProp="label" options={empresas.map((e) => ({ value: e.id, label: e.nome }))} />
        </Form.Item>
        <Row gutter={8}>
          <Col span={14}>
            <Form.Item name="nome" label="Nome da nova empresa" rules={[{ required: true, message: 'Indique o nome.' }]}>
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col span={10}>
            <Form.Item name="nif" label="NIF" rules={[{ required: true, message: 'Indique o NIF.' }]}>
              <Input maxLength={30} />
            </Form.Item>
          </Col>
        </Row>
        <Space>
          <Button icon={<CopyOutlined />} loading={executar.isPending} onClick={() => executar.mutate(true)}>
            Simular
          </Button>
          <Button
            type="primary"
            disabled={!simulacao}
            loading={executar.isPending}
            onClick={() =>
              Modal.confirm({
                title: 'Criar a empresa e clonar a estrutura?',
                content: `Serão copiadas ${formatarNumero(simulacao?.linhas)} linha(s).`,
                okText: 'Clonar',
                cancelText: 'Cancelar',
                onOk: () => executar.mutateAsync(false),
              })
            }
          >
            Clonar
          </Button>
        </Space>
      </Form>
      {simulacao && (
        <div style={{ marginTop: 12 }}>
          <ResumoDaCopia r={simulacao} />
        </div>
      )}
    </Card>
  );
}

function LogotipoEntrada() {
  const cliente = useQueryClient();
  const actual = useQuery({ queryKey: ['sistema', 'logotipo-login'], queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login') });
  const gravar = useMutation({
    mutationFn: (logotipo: string | null) => enviar('put', '/sistema/configuracoes/logotipo-login', { logotipo }),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['sistema', 'logotipo-login'] });
    },
    onError: (e) => notificarErro(e),
  });
  return (
    <Card title={<Space>Logótipo do ecrã de entrada <Tag color="purple">Super-administrador</Tag></Space>} style={{ height: '100%' }}>
      <Typography.Paragraph type="secondary">Imagem PNG ou JPEG até 1 MB, mostrada a todos antes de iniciarem sessão.</Typography.Paragraph>
      <Flex gap={16} align="center" wrap>
        {actual.data?.logotipo ? <Image src={actual.data.logotipo} alt="Logótipo do ecrã de entrada" height={72} /> : <Tag>Sem logótipo</Tag>}
        <Upload
          accept="image/png,image/jpeg"
          showUploadList={false}
          beforeUpload={async (f) => {
            if (f.size > 1_000_000) message.error('A imagem deve ter no máximo 1 MB.');
            else gravar.mutate(await lerComoDataUrl(f));
            return false;
          }}
        >
          <Button icon={<UploadOutlined />} loading={gravar.isPending}>
            Carregar imagem
          </Button>
        </Upload>
        {actual.data?.logotipo && (
          <Button danger icon={<DeleteOutlined />} loading={gravar.isPending} onClick={() => gravar.mutate(null)}>
            Remover
          </Button>
        )}
      </Flex>
    </Card>
  );
}
