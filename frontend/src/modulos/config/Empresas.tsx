import { Alert, Button, Card, Col, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Segmented, Space, Switch, Tabs, Tag, Upload, Image, message } from 'antd';
import { CheckCircleOutlined, DeleteOutlined, EditOutlined, PlusOutlined, StopOutlined, UploadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { lerComoDataUrl } from './comum/ficheiros';
import { larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { TabelaLocalImprimivel } from './comum/impressao';

export interface EmpresaGestao {
  id: number;
  nome: string;
  nif: string;
  endereco: string | null;
  provincia: string | null;
  municipio: string | null;
  comuna: string | null;
  telefone: string | null;
  email: string | null;
  website: string | null;
  numero_registo_comercial: string | null;
  rodape_documento: string | null;
  taxa_inss_patronal: number | null;
  taxa_inss_trabalhador: number | null;
  regras_ia: string | null;
  he_percentagem_1: number | null;
  he_limite_horas: number | null;
  he_percentagem_2: number | null;
  estado: 'ATIVO' | 'INATIVO';
  moeda_funcional: string;
  e_consolidacao: boolean;
  moeda_consolidacao: string | null;
  data_fim_consolidacao: string | null;
  tem_logotipo: boolean;
  logotipo?: string | null;
}

const CHAVE = ['sistema', 'gestao-empresas'];
const LOGO_MAX = 1_000_000; // o servidor aceita até 1,5 MB de texto (base64)

/** Configurações › Gestão de empresas (config_empresas): lista, ficha (identificação, RH, consolidação, documentos) e estado. */
export default function Empresas() {
  const { pode } = useSessao();
  const gerir = pode('config_empresas_gerir');
  const [estado, setEstado] = useState<'ATIVO' | 'INATIVO' | 'todas'>('ATIVO');
  const [filtro, setFiltro] = useState('');
  const [edicao, setEdicao] = useState<number | 'nova' | null>(null);
  const lista = useQuery({ queryKey: [...CHAVE, estado], queryFn: () => obter<EmpresaGestao[]>('/sistema/gestao-empresas', { estado: estado === 'todas' ? undefined : estado }) });
  const accao = useAccao({ invalidar: [CHAVE] });
  const pequeno = useEcraPequeno();
  const linhas = (lista.data ?? []).filter((e) => !filtro || `${e.nome} ${e.nif}`.toLowerCase().includes(filtro.toLowerCase()));

  return (
    <>
      <CabecalhoPagina
        titulo="Gestão de empresas"
        subtitulo="Dados de identificação, parâmetros de RH, consolidação e documentos de cada empresa"
        accoes={gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>Nova empresa</Button>}
      />
      <Card>
        <TabelaLocalImprimivel<EmpresaGestao>
          titulo="Lista de empresas"
          filtros={[`Estado: ${estado === 'ATIVO' ? 'Activas' : estado === 'INATIVO' ? 'Inactivas' : 'Todas'}`, filtro ? `Pesquisa: ${filtro}` : null]}
          filtrosEcra={<>
          <Input.Search placeholder="Nome ou NIF" allowClear style={{ width: 260 }} onChange={(e) => setFiltro(e.target.value)} />
          <Segmented value={estado} onChange={(v) => setEstado(v as typeof estado)} options={[{ value: 'ATIVO', label: 'Activas' }, { value: 'INATIVO', label: 'Inactivas' }, { value: 'todas', label: 'Todas' }]} />
          </>}
          rowKey="id"
          loading={lista.isLoading}
          dataSource={linhas}
          size={pequeno ? 'small' : 'middle'}
          pagination={{ pageSize: 50, hideOnSinglePage: true }}
          scroll={scrollTabela()}
          columns={[
            { title: 'Empresa', dataIndex: 'nome', valorImpressao: (e) => `${e.nome}${e.e_consolidacao ? ' (holding)' : ''}`, render: (v: string, e) => (<><strong>{v}</strong>{e.e_consolidacao && <Tag color="purple" style={{ marginLeft: 6 }}>Holding</Tag>}</>) },
            { title: 'NIF', dataIndex: 'nif' },
            { title: 'Moeda', dataIndex: 'moeda_funcional', width: 90, responsive: ['md'] },
            { title: 'INSS (pat./trab.)', key: 'inss', responsive: ['md'], render: (_, e) => `${e.taxa_inss_patronal ?? '—'}% / ${e.taxa_inss_trabalhador ?? '—'}%` },
            { title: 'Consolidado até', dataIndex: 'data_fim_consolidacao', responsive: ['lg'], render: formatarData },
            { title: 'Estado', dataIndex: 'estado', render: (s: string) => (s === 'ATIVO' ? <Tag color="green">Activa</Tag> : <Tag>Inactiva</Tag>) },
            {
              title: '',
              width: 110,
              render: (_, e) => (
                <Space>
                  <Button size="small" type="text" icon={<EditOutlined />} aria-label={gerir ? 'Editar' : 'Ver'} title={gerir ? 'Editar' : 'Ver'} onClick={() => setEdicao(e.id)} />
                  {gerir && (
                    <Popconfirm
                      title={e.estado === 'ATIVO' ? `Desactivar «${e.nome}»? Os utilizadores deixam de a poder escolher.` : `Activar «${e.nome}»?`}
                      okText={e.estado === 'ATIVO' ? 'Desactivar' : 'Activar'}
                      cancelText="Cancelar"
                      onConfirm={() => accao.mutateAsync({ metodo: 'put', url: `/sistema/empresas/${e.id}/estado`, dados: { estado: e.estado === 'ATIVO' ? 'INATIVO' : 'ATIVO' } })}
                    >
                      <Button size="small" type="text" icon={e.estado === 'ATIVO' ? <StopOutlined /> : <CheckCircleOutlined />} aria-label={e.estado === 'ATIVO' ? 'Desactivar' : 'Activar'} title={e.estado === 'ATIVO' ? 'Desactivar' : 'Activar'} />
                    </Popconfirm>
                  )}
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <FichaEmpresa id={edicao} gerir={gerir} aoFechar={() => setEdicao(null)} />
    </>
  );
}

function FichaEmpresa({ id, gerir, aoFechar }: { id: number | 'nova' | null; gerir: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<EmpresaGestao>();
  const ficha = useQuery({ queryKey: [...CHAVE, 'ficha', id], queryFn: () => obter<EmpresaGestao>(`/sistema/gestao-empresas/${id}`), enabled: typeof id === 'number' });
  const [logotipo, setLogotipo] = useState<string | null | undefined>(undefined);
  const accao = useAccao({ invalidar: [CHAVE, ['sistema', 'empresas']], aoSucesso: () => aoFechar() });
  const consolidacao = Form.useWatch('e_consolidacao', form);

  useEffect(() => {
    if (id === null) return;
    form.resetFields();
    setLogotipo(undefined);
    if (id === 'nova') form.setFieldsValue({ moeda_funcional: 'AOA', taxa_inss_patronal: 8, taxa_inss_trabalhador: 3, e_consolidacao: false } as Partial<EmpresaGestao>);
    else if (ficha.data) form.setFieldsValue(ficha.data);
  }, [id, ficha.data, form]);

  const logoActual = logotipo === undefined ? ficha.data?.logotipo : logotipo;

  const submeter = (v: EmpresaGestao) => {
    const dados: Record<string, unknown> = { ...v };
    if (logotipo !== undefined) dados.logotipo = logotipo;
    for (const k of Object.keys(dados)) if (dados[k] === '') dados[k] = null;
    accao.mutate({ metodo: id === 'nova' ? 'post' : 'put', url: id === 'nova' ? '/sistema/empresas' : `/sistema/empresas/${id}`, dados });
  };

  return (
    <Modal
      title={id === 'nova' ? 'Nova empresa' : `Empresa — ${ficha.data?.nome ?? ''}`}
      open={id !== null}
      onCancel={aoFechar}
      width={larguraModal(820)}
      okText="Gravar"
      cancelText={gerir ? 'Cancelar' : 'Fechar'}
      okButtonProps={{ style: gerir ? undefined : { display: 'none' } }}
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      destroyOnHidden
    >
      <Form form={form} layout="vertical" disabled={!gerir} onFinish={submeter}>
        <Tabs
          items={[
            {
              key: 'id',
              label: 'Identificação',
              forceRender: true,
              children: (
                <Row gutter={16}>
                  <Col xs={24} md={16}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="nif" label="NIF" rules={[{ required: true, message: 'Indique o NIF.' }]}><Input maxLength={30} /></Form.Item></Col>
                  <Col xs={24}><Form.Item name="endereco" label="Endereço"><Input.TextArea rows={2} maxLength={2000} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="provincia" label="Província"><Input maxLength={100} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="municipio" label="Município"><Input maxLength={100} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="comuna" label="Comuna"><Input maxLength={100} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="telefone" label="Telefone"><Input maxLength={50} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="email" label="Email" rules={[{ type: 'email', message: 'Email inválido.' }]}><Input maxLength={150} /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="website" label="Website"><Input maxLength={255} /></Form.Item></Col>
                  <Col xs={24} md={12}><Form.Item name="numero_registo_comercial" label="N.º de registo comercial"><Input maxLength={50} /></Form.Item></Col>
                </Row>
              ),
            },
            {
              key: 'rh',
              label: 'RH e salários',
              forceRender: true,
              children: (
                <Row gutter={16}>
                  <Col xs={24} md={12}><Form.Item name="taxa_inss_patronal" label="INSS entidade patronal (%)"><InputNumber min={0} max={100} step={0.5} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
                  <Col xs={24} md={12}><Form.Item name="taxa_inss_trabalhador" label="INSS trabalhador (%)"><InputNumber min={0} max={100} step={0.5} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="he_percentagem_1" label="Horas extra: % até ao limite"><InputNumber min={0} max={1000} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="he_limite_horas" label="Limite de horas (mês)"><InputNumber min={0} max={744} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
                  <Col xs={24} sm={12} md={8}><Form.Item name="he_percentagem_2" label="% acima do limite"><InputNumber min={0} max={1000} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
                </Row>
              ),
            },
            {
              key: 'consolidacao',
              label: 'Moeda e consolidação',
              forceRender: true,
              children: (
                <Row gutter={16}>
                  <Col xs={24} sm={12} md={8}>
                    <Form.Item name="moeda_funcional" label="Moeda funcional" rules={[{ len: 3, message: 'Código ISO de 3 letras.' }]} normalize={(v: string) => v?.toUpperCase()}>
                      <Input maxLength={3} />
                    </Form.Item>
                  </Col>
                  <Col xs={24} sm={12} md={8}>
                    <Form.Item name="e_consolidacao" label="Empresa de consolidação (holding)" valuePropName="checked">
                      <Switch />
                    </Form.Item>
                  </Col>
                  {consolidacao && (
                    <Col xs={24} sm={12} md={8}>
                      <Form.Item name="moeda_consolidacao" label="Moeda de consolidação" normalize={(v: string) => v?.toUpperCase()}>
                        <Input maxLength={3} />
                      </Form.Item>
                    </Col>
                  )}
                  <Col xs={24}>
                    <Alert type="info" showIcon message="Os membros do grupo e as regras de eliminação definem-se em Contabilidade › Consolidação." />
                  </Col>
                </Row>
              ),
            },
            {
              key: 'documentos',
              label: 'Documentos',
              forceRender: true,
              children: (
                <>
                  <Form.Item label="Logótipo (documentos impressos)">
                    <Flex gap={16} align="center" wrap>
                      {logoActual ? <Image src={logoActual} alt="Logótipo da empresa" height={64} /> : <Tag>{ficha.data?.tem_logotipo && logotipo === undefined ? 'A carregar…' : 'Sem logótipo'}</Tag>}
                      {gerir && (
                        <Upload
                          accept="image/png,image/jpeg,image/svg+xml"
                          showUploadList={false}
                          beforeUpload={async (f) => {
                            if (f.size > LOGO_MAX) {
                              message.error('O logótipo deve ter no máximo 1 MB.');
                              return false;
                            }
                            setLogotipo(await lerComoDataUrl(f));
                            return false;
                          }}
                        >
                          <Button icon={<UploadOutlined />}>Escolher imagem</Button>
                        </Upload>
                      )}
                      {gerir && logoActual && (
                        <Button icon={<DeleteOutlined />} danger onClick={() => setLogotipo(null)}>
                          Remover
                        </Button>
                      )}
                    </Flex>
                  </Form.Item>
                  <Form.Item name="rodape_documento" label="Rodapé dos documentos"><Input.TextArea rows={3} maxLength={2000} showCount /></Form.Item>
                  <Form.Item name="regras_ia" label="Regras para o assistente (IA)" extra="Instruções específicas desta empresa usadas pelo assistente."><Input.TextArea rows={4} maxLength={20000} /></Form.Item>
                </>
              ),
            },
          ]}
        />
      </Form>
    </Modal>
  );
}
