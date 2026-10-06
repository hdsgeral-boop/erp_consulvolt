import { Alert, Button, Card, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Row, Select, Space, Table, Tooltip, Typography } from 'antd';
import { AppstoreAddOutlined, CalculatorOutlined, DeleteOutlined, DownloadOutlined, EditOutlined, ImportOutlined, MinusCircleOutlined, PlusOutlined, StopOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { descarregar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { ContratosMassa } from './comum/ContratosMassa';
import { SimularMassa } from './comum/SimularMassa';
import { ImportarExcel } from './comum/ImportarExcel';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ESTADOS_COLABORADOR, type ContratoTrabalho, type ItemProdutividade } from './api';
import { contem, EstadoTag, PesquisaLocal, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores, useInfotipos } from './comum/consultas';
import { contratoVigente, normalizarRemuneracoes, semFim, somar, totalContrato } from './comum/regras';
import { BarraFiltros, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { pedidoTabela } from './comum/impressao';

interface ValoresContrato {
  colaborador_id: number;
  data_inicio: Dayjs;
  data_fim?: Dayjs | null;
  dias_contrato_mes: number;
  horas_por_dia: number;
  estado: string;
  codigo_moeda: string;
  remuneracoes: { infotipo_salarial_id: number; valor_mes: number }[];
  produtividade?: { item_id: number; preco_unitario?: number | null }[];
}

/** RH › Contratos (ecrã contratos): histórico de contratos por colaborador, com remunerações e itens de produtividade. */
export default function Contratos() {
  const { pode } = useSessao();
  const colaboradores = useColaboradores();
  const infotipos = useInfotipos();
  const itensProd = useQuery({ queryKey: ['rh', 'produtividade', 'itens'], queryFn: () => obter<ItemProdutividade[]>('/rh/produtividade/itens'), staleTime: 300_000 });
  const [colaborador, setColaborador] = useState<number>();
  const [estado, setEstado] = useState<string>();
  const [termo, setTermo] = useState('');
  const [edicao, setEdicao] = useState<ContratoTrabalho | 'novo' | null>(null);
  const [terminar, setTerminar] = useState<ContratoTrabalho | null>(null);
  const [importarCt, setImportarCt] = useState(false);
  const [massa, setMassa] = useState(false);
  const [simular, setSimular] = useState(false);
  const [form] = Form.useForm<ValoresContrato>();
  const [formFim] = Form.useForm<{ data_fim: Dayjs }>();
  const contratos = useQuery({
    queryKey: ['rh', 'contratos', { colaborador_id: colaborador, estado }],
    queryFn: () => obter<ContratoTrabalho[]>('/rh/contratos', { colaborador_id: colaborador, estado }),
  });
  useAvisarErro(contratos.error);
  const accao = useAccaoRh(() => {
    setEdicao(null);
    setTerminar(null);
  });
  const remuneracoesForm = Form.useWatch('remuneracoes', form);
  const hoje = dayjs().format('YYYY-MM-DD');

  const abrir = (c: ContratoTrabalho | 'novo') => {
    form.resetFields();
    if (c === 'novo') {
      form.setFieldsValue({ colaborador_id: colaborador, data_inicio: dayjs(), dias_contrato_mes: 22, horas_por_dia: 8, estado: 'ACTIVO', codigo_moeda: 'AOA', remuneracoes: [{} as ValoresContrato['remuneracoes'][number]], produtividade: [] });
    } else {
      form.setFieldsValue({
        colaborador_id: c.colaborador_id,
        data_inicio: c.data_inicio ? dayjs(c.data_inicio) : undefined,
        data_fim: semFim(c.data_fim) ? null : dayjs(c.data_fim),
        dias_contrato_mes: c.dias_contrato_mes ?? 22,
        horas_por_dia: Number(c.horas_por_dia ?? 8),
        estado: c.estado ?? 'ACTIVO',
        codigo_moeda: c.codigo_moeda ?? 'AOA',
        remuneracoes: normalizarRemuneracoes(c.remuneracoes, c.dias_contrato_mes ?? 22),
        produtividade: (c.produtividade ?? []).map((p) => ({ item_id: p.item_id, preco_unitario: p.preco_unitario })),
      });
    }
    setEdicao(c);
  };

  const gravar = (v: ValoresContrato) => {
    const dados = {
      ...v,
      data_inicio: dataApi(v.data_inicio),
      data_fim: dataApi(v.data_fim ?? null) ?? null,
      produtividade: (v.produtividade ?? []).map((p) => ({ item_id: p.item_id, preco_unitario: p.preco_unitario ?? null })),
    };
    if (edicao === 'novo') accao.mutate({ metodo: 'post', url: '/rh/contratos', dados });
    else if (edicao) accao.mutate({ metodo: 'put', url: `/rh/contratos/${edicao.id}`, dados });
  };

  const linhas = (contratos.data ?? []).filter((c) => contem(colaboradores.nome(c.colaborador_id), termo));
  const vencimentos = infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO');

  const pequeno = useEcraPequeno();
  // colunas do legado (renderContratos): colaborador, rubricas, valor/mês, valor/dia, valor/hora, dias · horas, início, fim, estado
  const rem = (c: ContratoTrabalho) => normalizarRemuneracoes(c.remuneracoes, c.dias_contrato_mes ?? 22);
  const moeda = (c: ContratoTrabalho) => (c.codigo_moeda && c.codigo_moeda !== 'AOA' ? ` ${c.codigo_moeda}` : '');
  const porDia = (c: ContratoTrabalho) => Number(totalContrato(c)) / (Number(c.dias_contrato_mes) || 22);
  const colunas: ColunaApi<ContratoTrabalho>[] = [
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => <strong>{colaboradores.nome(v)}</strong> },
    { title: 'Rubricas (infotipos)', responsive: ['lg'], ellipsis: true, valorImpressao: (c) => rem(c).map((r) => infotipos.nome(r.infotipo_salarial_id)).join(', '),
      render: (_, c) => <Tooltip title={rem(c).map((r) => `${infotipos.nome(r.infotipo_salarial_id)}: ${formatarKz(r.valor_mes)}`).join(' · ')}>{rem(c).map((r) => infotipos.nome(r.infotipo_salarial_id)).join(', ')}</Tooltip> },
    { title: 'Valor/mês (Kz)', align: 'right', valorImpressao: (c) => `${formatarKz(totalContrato(c))}${moeda(c)}`, render: (_, c) => `${formatarKz(totalContrato(c))}${moeda(c)}` },
    { title: 'Valor/dia (Kz)', align: 'right', responsive: ['md'], valorImpressao: (c) => formatarKz(porDia(c)), render: (_, c) => formatarKz(porDia(c)) },
    { title: 'Valor/hora (Kz)', align: 'right', responsive: ['lg'], valorImpressao: (c) => formatarKz(porDia(c) / (Number(c.horas_por_dia) || 8)), render: (_, c) => formatarKz(porDia(c) / (Number(c.horas_por_dia) || 8)) },
    { title: 'Dias · horas', align: 'center', responsive: ['md'], valorImpressao: (c) => `${c.dias_contrato_mes ?? 22} · ${Number(c.horas_por_dia ?? 8)}`, render: (_, c) => `${c.dias_contrato_mes ?? 22} · ${Number(c.horas_por_dia ?? 8)}` },
    { title: 'Início', dataIndex: 'data_inicio', render: formatarData },
    { title: 'Fim', dataIndex: 'data_fim', render: (v: string | null) => (semFim(v) ? 'Sem fim' : formatarData(v)) },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null, c) => <Space size={4} wrap><EstadoTag estado={e} />{contratoVigente(c, hoje) && <Typography.Text type="success">vigente</Typography.Text>}</Space> },
    {
      title: '',
      key: 'accoes',
      align: 'right',
      render: (_, c) => (
        <Space size={4}>
          {pode('contratos_new') && <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => abrir(c)} />}
          {pode('contratos_terminate') && semFim(c.data_fim) && (
            <Tooltip title="Terminar contrato"><Button size="small" type="text" icon={<StopOutlined />} aria-label="Terminar" onClick={() => { formFim.setFieldsValue({ data_fim: dayjs() }); setTerminar(c); }} /></Tooltip>
          )}
          {pode('contratos_terminate') && (
            <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" onClick={() => Modal.confirm({
              title: 'Eliminar este contrato?',
              content: 'Os processamentos encerrados guardam a fotografia: nada muda para trás.',
              okText: 'Eliminar', okButtonProps: { danger: true }, cancelText: 'Cancelar',
              onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/rh/contratos/${c.id}` }),
            })} />
          )}
        </Space>
      ),
    },
  ];

  return (
    <>
      <ImportarExcel aberto={importarCt} titulo="Importar contratos (Excel)" url="/rh/importacoes/contratos" modelo="contratos"
        ajuda="Modelo vertical: uma linha por rubrica; as linhas com o mesmo NIF formam um contrato (início, dias/mês, horas/dia e estado na 1.ª linha). Quem já tiver contrato nesse período é recusado."
        aoFechar={() => setImportarCt(false)} />
      <ContratosMassa aberto={massa} aoFechar={() => setMassa(false)} />
      <SimularMassa aberto={simular} aoFechar={() => setSimular(false)} />
      <CabecalhoPagina
        titulo="Gestão de Contratos de Trabalho"
        subtitulo="Contratos de trabalho e remunerações (vários contratos por colaborador, sem sobreposição de datas)"
        accoes={
          <>
            <Button icon={<CalculatorOutlined />} onClick={() => setSimular(true)}>Simular massa salarial</Button>
            {pode('contratos_import') && (
              <>
                <Button icon={<DownloadOutlined />} onClick={() => void descarregar('/rh/importacoes/modelos/contratos', undefined, 'Template_Contratos_Vertical.xlsx').catch(notificarErro)}>Template</Button>
                <Button icon={<ImportOutlined />} onClick={() => setImportarCt(true)}>Importar Excel</Button>
              </>
            )}
            {pode('contratos_new') && <Button icon={<AppstoreAddOutlined />} onClick={() => setMassa(true)}>Aplicar em massa</Button>}
            {pode('contratos_new') && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Novo contrato</Button>}
          </>
        }
        impressaoDesactivada={!linhas.length}
        impressao={() => pedidoTabela({
          titulo: 'Lista de contratos de trabalho',
          filtros: [colaborador !== undefined && `Colaborador: ${colaboradores.nome(colaborador)}`, estado && `Estado: ${ESTADOS_COLABORADOR.find((e) => e.value === estado)?.label ?? estado}`, termo && `Pesquisa: ${termo}`],
          colunas,
          linhas,
        })}
      />
      <Card>
        <BarraFiltros>
          <SeletorColaborador value={colaborador} onChange={setColaborador} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={ESTADOS_COLABORADOR} />
          <PesquisaLocal aoMudar={setTermo} placeholder="Nome do colaborador" />
        </BarraFiltros>
        <Table<ContratoTrabalho> rowKey="id" size={pequeno ? 'small' : 'middle'} loading={contratos.isFetching} columns={colunas} dataSource={linhas} scroll={scrollTabela()}
          pagination={{ pageSize: 25, showSizeChanger: true, showTotal: (t) => `${t} contrato(s)` }} />
      </Card>

      <Modal title={edicao === 'novo' ? 'Novo contrato' : 'Editar contrato'} open={edicao !== null} width={larguraModal(820)} onCancel={() => setEdicao(null)}
        okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={gravar}>
          <Row gutter={12}>
            <Col xs={24} md={12}>
              <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}>
                <SeletorColaborador style={{ width: '100%' }} disabled={edicao !== 'novo'} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}><Form.Item name="data_inicio" label="Início" rules={[{ required: true, message: 'Data de início.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} md={6}>
              <Form.Item name="data_fim" label="Fim" extra="Vazio = sem fim." dependencies={['data_inicio']} rules={[({ getFieldValue }) => ({
                validator: (_, v: Dayjs | null) => (!v || !getFieldValue('data_inicio') || !v.isBefore(getFieldValue('data_inicio'), 'day') ? Promise.resolve() : Promise.reject(new Error('Anterior ao início.'))),
              })]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}><Form.Item name="dias_contrato_mes" label="Dias do contrato/mês"><InputNumber min={1} max={31} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item name="horas_por_dia" label="Horas por dia"><InputNumber min={0.5} max={24} step={0.5} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item name="estado" label="Estado"><Select options={ESTADOS_COLABORADOR} /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item name="codigo_moeda" label="Moeda" rules={[{ len: 3, message: '3 letras.' }]} extra="O processamento é só em Kz (AOA)."><Input maxLength={3} style={{ textTransform: 'uppercase' }} /></Form.Item></Col>
          </Row>
          {!pode('contratos_terminate') && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Terminar um contrato (data de fim ou estado Inactivo) exige a permissão de rescisão." />}
          <Typography.Title level={5}>Remunerações mensais</Typography.Title>
          <Form.List name="remuneracoes" rules={[{ validator: (_, v: unknown[]) => (v && v.length > 0 ? Promise.resolve() : Promise.reject(new Error('Pelo menos uma remuneração.'))) }]}>
            {(campos, { add, remove }, { errors }) => (
              <>
                {campos.map(({ key, name }) => (
                  <Row gutter={8} key={key} align="middle">
                    <Col xs={24} sm="auto" flex="auto">
                      <Form.Item name={[name, 'infotipo_salarial_id']} rules={[{ required: true, message: 'Rubrica.' }]}>
                        <Select showSearch optionFilterProp="label" placeholder="Rubrica (vencimento)" options={vencimentos.map((i) => ({ value: i.id, label: i.nome }))} />
                      </Form.Item>
                    </Col>
                    <Col xs={20} sm={8} style={{ maxWidth: 200 }}>
                      <Form.Item name={[name, 'valor_mes']} rules={[{ required: true, message: 'Valor.' }]}>
                        <InputNumber min={0} step={1000} precision={2} decimalSeparator="," style={{ width: '100%' }} suffix="Kz" />
                      </Form.Item>
                    </Col>
                    <Col><Form.Item><MinusCircleOutlined onClick={() => remove(name)} aria-label="Retirar" /></Form.Item></Col>
                  </Row>
                ))}
                <Form.ErrorList errors={errors} />
                <Flex justify="space-between" align="center" wrap gap={8}>
                  <Button type="dashed" icon={<PlusOutlined />} onClick={() => add()}>Acrescentar rubrica</Button>
                  <Typography.Text strong>Total mensal: {formatarKz(somar((remuneracoesForm ?? []).map((r) => r?.valor_mes)), true)}</Typography.Text>
                </Flex>
              </>
            )}
          </Form.List>
          {(itensProd.data ?? []).length > 0 && (
            <>
              <Typography.Title level={5} style={{ marginTop: 16 }}>Itens de produtividade</Typography.Title>
              <Form.List name="produtividade">
                {(campos, { add, remove }) => (
                  <>
                    {campos.map(({ key, name }) => (
                      <Row gutter={8} key={key} align="middle">
                        <Col xs={24} sm="auto" flex="auto">
                          <Form.Item name={[name, 'item_id']} rules={[{ required: true, message: 'Item.' }]}>
                            <Select placeholder="Item" options={(itensProd.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} — ${i.descricao}${i.ativo ? '' : ' (inactivo)'}` }))} />
                          </Form.Item>
                        </Col>
                        <Col xs={20} sm={8} style={{ maxWidth: 220 }}>
                          <Form.Item name={[name, 'preco_unitario']} extra="Vazio = preço do item.">
                            <InputNumber min={0} precision={4} decimalSeparator="," placeholder="Preço próprio" style={{ width: '100%' }} />
                          </Form.Item>
                        </Col>
                        <Col><Form.Item><MinusCircleOutlined onClick={() => remove(name)} aria-label="Retirar" /></Form.Item></Col>
                      </Row>
                    ))}
                    <Button type="dashed" icon={<PlusOutlined />} onClick={() => add()}>Acrescentar item</Button>
                  </>
                )}
              </Form.List>
            </>
          )}
        </Form>
      </Modal>

      <Modal title="Terminar contrato" open={terminar !== null} onCancel={() => setTerminar(null)} okText="Terminar" okButtonProps={{ danger: true }} cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => formFim.submit()} destroyOnHidden>
        <Typography.Paragraph>{terminar ? colaboradores.nome(terminar.colaborador_id) : ''} — contrato iniciado em {formatarData(terminar?.data_inicio)}.</Typography.Paragraph>
        <Form form={formFim} layout="vertical" onFinish={(v) => terminar && accao.mutate({ metodo: 'post', url: `/rh/contratos/${terminar.id}/terminar`, dados: { data_fim: dataApi(v.data_fim) } })}>
          <Form.Item name="data_fim" label="Data de fim" rules={[{ required: true, message: 'Indique a data.' }]} extra="Se a data já passou, o contrato fica Inactivo.">
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
