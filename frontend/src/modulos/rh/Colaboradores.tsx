import {
  Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Divider, Flex, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Space, Table, Tabs, Tag,
} from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, EditOutlined, MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import {
  ESTADOS_CIVIS, ESTADOS_COLABORADOR, NIVEIS_HABILITACAO, PARENTESCOS, type Colaborador, type ContratoTrabalho, type FichaColaborador, type ReferenciaNome,
} from './api';
import { EstadoTag } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useCargos, useColaboradores, useTiposOrganizacao } from './comum/consultas';
import { formatarIban, semFim, totalContrato } from './comum/regras';

/** RH › Colaboradores (ecrã colaboradores): listagem, ficha, criação/edição e eliminação. */
export default function Colaboradores() {
  return (
    <Routes>
      <Route index element={<ListaColaboradores />} />
      <Route path="novo" element={<FormularioColaborador />} />
      <Route path=":id" element={<FichaDoColaborador />} />
      <Route path=":id/editar" element={<FormularioColaborador />} />
    </Routes>
  );
}

function ListaColaboradores() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const tipos = useTiposOrganizacao();
  const cargos = useCargos();
  const [estado, setEstado] = useState<string | undefined>('ACTIVO');
  const [tipo, setTipo] = useState<number>();
  const [pesquisa, setPesquisa] = useState('');

  const colunas: ColumnsType<Colaborador> = [
    { title: 'Nome', dataIndex: 'nome_completo', fixed: 'left', render: (v: string) => <strong>{v}</strong> },
    { title: 'NIF', dataIndex: 'nif' },
    { title: 'N.º INSS', dataIndex: 'numero_inss', render: (v: string | null) => v ?? '—' },
    { title: 'Função', dataIndex: 'cargo_funcao_id', render: (v: number | null) => cargos.nome(v) },
    { title: 'Tipo de organização', dataIndex: 'tipo_organizacao_id', render: (v: number | null) => tipos.nome(v) },
    { title: 'Admissão', dataIndex: 'data_admissao', render: formatarData },
    { title: 'Regime', render: (_, r) => (r.avencado ? <Tag color="purple">Avençado</Tag> : r.reformado ? <Tag>Reformado</Tag> : null) },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => <EstadoTag estado={e} /> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Colaboradores"
        subtitulo="Ficha dos colaboradores da empresa"
        accoes={pode('colaboradores_detail') && <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>Novo colaborador</Button>}
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Input.Search placeholder="Nome, NIF ou n.º INSS" allowClear style={{ width: 260 }} onSearch={setPesquisa} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={ESTADOS_COLABORADOR} />
          <Select placeholder="Tipo de organização" allowClear style={{ width: 220 }} value={tipo} onChange={setTipo} options={tipos.lista.map((t) => ({ value: t.id, label: t.nome }))} />
        </Flex>
        <TabelaApi<Colaborador>
          url="/rh/colaboradores"
          chaveConsulta={['rh', 'colaboradores', 'lista']}
          filtros={{ estado, tipo_organizacao_id: tipo, pesquisa }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}

function FichaDoColaborador() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cargos = useCargos();
  const tipos = useTiposOrganizacao();
  const colaboradores = useColaboradores();
  const ficha = useQuery({ queryKey: ['rh', 'colaborador', id], queryFn: () => obter<FichaColaborador>(`/rh/colaboradores/${id}`) });
  const contratos = useQuery({ queryKey: ['rh', 'contratos', { colaborador_id: id }], queryFn: () => obter<ContratoTrabalho[]>('/rh/contratos', { colaborador_id: id }) });
  useAvisarErro(ficha.error, 'Erro ao carregar o colaborador');
  const eliminar = useAccaoRh(() => navegar('..'));

  if (ficha.isLoading) return <Skeleton active />;
  const c = ficha.data;
  if (!c) return <Alert type="error" message="Colaborador não encontrado." />;

  const colContratos: ColumnsType<ContratoTrabalho> = [
    { title: 'Início', dataIndex: 'data_inicio', render: formatarData },
    { title: 'Fim', dataIndex: 'data_fim', render: (v: string | null) => (semFim(v) ? 'Sem fim' : formatarData(v)) },
    { title: 'Dias/mês', dataIndex: 'dias_contrato_mes' },
    { title: 'Horas/dia', dataIndex: 'horas_por_dia' },
    { title: 'Remuneração mensal', align: 'right', render: (_, r) => `${formatarKz(totalContrato(r))} ${r.codigo_moeda ?? 'AOA'}` },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => <EstadoTag estado={e} /> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={c.nome_completo}
        subtitulo={<Space>NIF {c.nif}<EstadoTag estado={c.estado} />{c.avencado && <Tag color="purple">Avençado</Tag>}{c.reformado && <Tag>Reformado</Tag>}</Space>}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {pode('colaboradores_detail') && <Button type="primary" icon={<EditOutlined />} onClick={() => navegar('editar')}>Editar</Button>}
            {pode('rh_colab_del') && (
              <Button danger icon={<DeleteOutlined />} loading={eliminar.isPending} onClick={() => Modal.confirm({
                title: `Eliminar ${c.nome_completo}?`,
                content: 'Só é possível sem utilizações (contratos, processamentos…). Para quem saiu da empresa use o estado Inactivo.',
                okText: 'Eliminar', okButtonProps: { danger: true }, cancelText: 'Cancelar',
                onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/rh/colaboradores/${c.id}` }),
              })}>Eliminar</Button>
            )}
          </>
        }
      />
      <Card>
        <Tabs
          items={[
            {
              key: 'geral',
              label: 'Dados profissionais',
              children: (
                <Descriptions column={{ xs: 1, md: 3 }} size="small">
                  <Descriptions.Item label="N.º INSS">{c.numero_inss ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Função">{cargos.nome(c.cargo_funcao_id)}</Descriptions.Item>
                  <Descriptions.Item label="Tipo de organização">{tipos.nome(c.tipo_organizacao_id)}</Descriptions.Item>
                  <Descriptions.Item label="Admissão">{formatarData(c.data_admissao)}</Descriptions.Item>
                  <Descriptions.Item label="Dias úteis/mês">{c.dias_uteis_mes ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Gestor directo">{colaboradores.nome(c.colaborador_gestor_id)}</Descriptions.Item>
                  <Descriptions.Item label="Habilitação máxima">{c.habilitacao_maxima ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Unidade orgânica">{refNome(c.unidade_organica, c.unidade_organica_id)}</Descriptions.Item>
                  <Descriptions.Item label="UN / CC">{refNome(c.unidade_negocio, c.unidade_negocio_id)} / {refNome(c.centro_custo, c.centro_custo_id)}</Descriptions.Item>
                </Descriptions>
              ),
            },
            {
              key: 'pessoal',
              label: 'Dados pessoais e contactos',
              children: (
                <Descriptions column={{ xs: 1, md: 3 }} size="small">
                  <Descriptions.Item label="Sexo">{c.sexo === 'M' ? 'Masculino' : c.sexo === 'F' ? 'Feminino' : '—'}</Descriptions.Item>
                  <Descriptions.Item label="Nascimento">{formatarData(c.data_nascimento)}</Descriptions.Item>
                  <Descriptions.Item label="Estado civil">{ESTADOS_CIVIS.find((e) => e.value === c.estado_civil)?.label ?? c.estado_civil ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Nacionalidade">{c.nacionalidade ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Naturalidade">{[c.naturalidade, c.provincia_naturalidade].filter(Boolean).join(', ') || '—'}</Descriptions.Item>
                  <Descriptions.Item label="Documento">{c.documento_identificacao ?? '—'}{c.documento_validade ? ` (válido até ${formatarData(c.documento_validade)})` : ''}</Descriptions.Item>
                  <Descriptions.Item label="Morada" span={3}>{[c.endereco, c.bairro, c.municipio, c.provincia].filter(Boolean).join(', ') || '—'}</Descriptions.Item>
                  <Descriptions.Item label="Telefone">{[c.telefone, c.telefone_alternativo].filter(Boolean).join(' / ') || '—'}</Descriptions.Item>
                  <Descriptions.Item label="E-mail">{c.email ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Emergência">{c.emergencia_nome ? `${c.emergencia_nome} (${c.emergencia_parentesco ?? '—'}) ${c.emergencia_telefone ?? ''}` : '—'}</Descriptions.Item>
                </Descriptions>
              ),
            },
            {
              key: 'agregado',
              label: `Agregado (${c.dependentes.length})`,
              children: (
                <Table rowKey={(d) => String(d.id ?? d.nome)} size="small" pagination={false} dataSource={c.dependentes} columns={[
                  { title: 'Nome', dataIndex: 'nome' },
                  { title: 'Parentesco', dataIndex: 'parentesco', render: (v: string | null) => PARENTESCOS.find((p) => p.value === v)?.label ?? v ?? '—' },
                  { title: 'Nascimento', dataIndex: 'data_nascimento', render: formatarData },
                  { title: 'Sexo', dataIndex: 'sexo', render: (v: string | null) => v ?? '—' },
                  { title: 'Dependente fiscal', dataIndex: 'dependente_fiscal', render: (v: boolean | null) => (v ? 'Sim' : 'Não') },
                ]} />
              ),
            },
            {
              key: 'habilitacoes',
              label: `Habilitações (${c.habilitacoes.length})`,
              children: (
                <Table rowKey={(h) => String(h.id ?? `${h.nivel}${h.curso}`)} size="small" pagination={false} dataSource={c.habilitacoes} columns={[
                  { title: 'Nível', dataIndex: 'nivel' },
                  { title: 'Curso', dataIndex: 'curso', render: (v: string | null) => v ?? '—' },
                  { title: 'Instituição', dataIndex: 'instituicao', render: (v: string | null) => v ?? '—' },
                  { title: 'Conclusão', dataIndex: 'ano_conclusao', render: (v: string | null) => v ?? '—' },
                  { title: 'Estado', dataIndex: 'estado', render: (v: string | null) => v ?? '—' },
                ]} />
              ),
            },
            {
              key: 'contratos',
              label: `Contratos (${contratos.data?.length ?? 0})`,
              children: <Table rowKey="id" size="small" pagination={false} loading={contratos.isFetching} dataSource={contratos.data ?? []} columns={colContratos} />,
            },
            {
              key: 'banco',
              label: 'Coordenadas bancárias',
              children: c.coordenada_bancaria ? (
                <Descriptions size="small" column={1}>
                  <Descriptions.Item label="Banco">{c.coordenada_bancaria.banco?.nome ?? `#${c.coordenada_bancaria.banco_id}`}</Descriptions.Item>
                  <Descriptions.Item label="IBAN"><code>{formatarIban(c.coordenada_bancaria.iban)}</code></Descriptions.Item>
                </Descriptions>
              ) : (
                <Alert type="warning" showIcon message="Sem IBAN registado (gere-se no ecrã Coordenadas bancárias)." />
              ),
            },
          ]}
        />
      </Card>
    </>
  );
}

type LinhaLista = Record<string, unknown>;
type ValoresFormulario = Record<string, unknown> & { dependentes?: LinhaLista[]; habilitacoes?: LinhaLista[] };

const CAMPOS_DATA = ['data_nascimento', 'data_admissao', 'documento_validade'] as const;

/** Criação e edição (PUT substitui o agregado e as habilitações, como no legado). */
function FormularioColaborador() {
  const { id } = useParams();
  const navegar = useNavigate();
  const [form] = Form.useForm();
  const tipos = useTiposOrganizacao();
  const cargos = useCargos();
  const colaboradores = useColaboradores();
  const ficha = useQuery({ queryKey: ['rh', 'colaborador', id], queryFn: () => obter<FichaColaborador>(`/rh/colaboradores/${id}`), enabled: Boolean(id) });
  const gravar = useAccaoRh<FichaColaborador>((d) => navegar(id ? '..' : `../${d.id}`, { relative: 'path' }));

  useEffect(() => {
    const d = ficha.data;
    if (!id || !d) return;
    const valores = {
      ...d,
      ...Object.fromEntries(CAMPOS_DATA.map((k) => [k, d[k] ? dayjs(d[k]) : null])),
      dependentes: d.dependentes.map((x) => ({ ...x, data_nascimento: x.data_nascimento ? dayjs(x.data_nascimento) : null }) as LinhaLista),
    };
    form.setFieldsValue(valores);
  }, [id, ficha.data, form]);

  if (id && ficha.isLoading) return <Skeleton active />;

  const enviarFormulario = (v: ValoresFormulario) => {
    const dados = {
      ...v,
      ...Object.fromEntries(CAMPOS_DATA.map((k) => [k, dataApi((v[k] as Dayjs | null | undefined) ?? null) ?? null])),
      dependentes: (v.dependentes ?? []).map((x) => ({ ...x, data_nascimento: dataApi((x.data_nascimento as Dayjs | null | undefined) ?? null) ?? null })),
      habilitacoes: (v.habilitacoes ?? []).map((h) => ({ ...h, ano_conclusao: h.ano_conclusao ? String(h.ano_conclusao) : null })),
    };
    delete (dados as Record<string, unknown>).coordenada_bancaria;
    gravar.mutate(id ? { metodo: 'put', url: `/rh/colaboradores/${id}`, dados } : { metodo: 'post', url: '/rh/colaboradores', dados });
  };

  return (
    <>
      <CabecalhoPagina
        titulo={id ? `Editar — ${ficha.data?.nome_completo ?? ''}` : 'Novo colaborador'}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..', { relative: 'path' })}>Cancelar</Button>
            <Button type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Gravar</Button>
          </>
        }
      />
      <Form form={form} layout="vertical" onFinish={enviarFormulario} initialValues={{ estado: 'ACTIVO', dias_uteis_mes: 22, reformado: false, avencado: false, dependentes: [], habilitacoes: [] }}>
        <Card title="Dados profissionais" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={12}><Form.Item name="nome_completo" label="Nome completo" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}><Input /></Form.Item></Col>
            <Col xs={24} md={6}><Form.Item name="nif" label="NIF" rules={[{ required: true, message: 'Indique o NIF.' }, { max: 30 }]}><Input /></Form.Item></Col>
            <Col xs={24} md={6}><Form.Item name="numero_inss" label="N.º INSS"><Input maxLength={50} /></Form.Item></Col>
            <Col xs={24} md={6}>
              <Form.Item name="tipo_organizacao_id" label="Tipo de organização" rules={[{ required: true, message: 'Escolha o tipo de organização.' }]}>
                <Select options={tipos.lista.map((t) => ({ value: t.id, label: t.nome }))} loading={tipos.isLoading} />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}><Form.Item name="cargo_funcao_id" label="Função"><Select allowClear showSearch optionFilterProp="label" options={cargos.lista.map((t) => ({ value: t.id, label: t.nome }))} /></Form.Item></Col>
            <Col xs={24} md={4}><Form.Item name="estado" label="Estado"><Select options={ESTADOS_COLABORADOR} /></Form.Item></Col>
            <Col xs={24} md={4}><Form.Item name="dias_uteis_mes" label="Dias úteis/mês"><InputNumber min={1} max={31} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={4}><Form.Item name="data_admissao" label="Admissão"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={8}>
              <Form.Item name="colaborador_gestor_id" label="Gestor directo">
                <Select allowClear showSearch optionFilterProp="label" options={colaboradores.lista.filter((x) => String(x.id) !== id).map((x) => ({ value: x.id, label: x.nome_completo }))} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}><Form.Item name="habilitacao_maxima" label="Habilitação máxima" extra="Vazia: calculada pelas habilitações concluídas."><Select allowClear options={NIVEIS_HABILITACAO.map((n) => ({ value: n, label: n }))} /></Form.Item></Col>
            <Col xs={24} md={8}>
              <Form.Item label="Regime">
                <Space>
                  <Form.Item name="avencado" valuePropName="checked" noStyle><Checkbox>Avençado</Checkbox></Form.Item>
                  <Form.Item name="reformado" valuePropName="checked" noStyle><Checkbox>Reformado</Checkbox></Form.Item>
                </Space>
              </Form.Item>
            </Col>
          </Row>
        </Card>
        <Card title="Dados pessoais e contactos" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={4}><Form.Item name="sexo" label="Sexo"><Select allowClear options={[{ value: 'M', label: 'Masculino' }, { value: 'F', label: 'Feminino' }]} /></Form.Item></Col>
            <Col xs={24} md={5}><Form.Item name="data_nascimento" label="Nascimento"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isAfter(dayjs())} /></Form.Item></Col>
            <Col xs={24} md={5}><Form.Item name="estado_civil" label="Estado civil"><Select allowClear options={ESTADOS_CIVIS} /></Form.Item></Col>
            <Col xs={24} md={5}><Form.Item name="nacionalidade" label="Nacionalidade"><Input maxLength={20} /></Form.Item></Col>
            <Col xs={24} md={5}><Form.Item name="naturalidade" label="Naturalidade"><Input maxLength={20} /></Form.Item></Col>
            <Col xs={24} md={6}><Form.Item name="provincia_naturalidade" label="Província (naturalidade)"><Input maxLength={20} /></Form.Item></Col>
            <Col xs={24} md={6}><Form.Item name="documento_identificacao" label="BI / passaporte"><Input maxLength={30} /></Form.Item></Col>
            <Col xs={24} md={6}><Form.Item name="documento_validade" label="Validade do documento"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="endereco" label="Endereço"><Input maxLength={1000} /></Form.Item></Col>
            <Col xs={24} md={4}><Form.Item name="bairro" label="Bairro"><Input maxLength={150} /></Form.Item></Col>
            <Col xs={24} md={4}><Form.Item name="municipio" label="Município"><Input maxLength={150} /></Form.Item></Col>
            <Col xs={24} md={4}><Form.Item name="provincia" label="Província"><Input maxLength={150} /></Form.Item></Col>
            <Col xs={24} md={6}><Form.Item name="telefone" label="Telefone"><Input maxLength={50} /></Form.Item></Col>
            <Col xs={24} md={6}><Form.Item name="telefone_alternativo" label="Telefone alternativo"><Input maxLength={50} /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="email" label="E-mail" rules={[{ type: 'email', message: 'E-mail inválido.' }]}><Input maxLength={255} /></Form.Item></Col>
            <Col xs={24} md={10}><Form.Item name="emergencia_nome" label="Contacto de emergência"><Input maxLength={255} /></Form.Item></Col>
            <Col xs={24} md={7}><Form.Item name="emergencia_parentesco" label="Parentesco"><Input maxLength={100} /></Form.Item></Col>
            <Col xs={24} md={7}><Form.Item name="emergencia_telefone" label="Telefone de emergência"><Input maxLength={50} /></Form.Item></Col>
          </Row>
        </Card>
        <Card title="Agregado familiar" style={{ marginBottom: 16 }}>
          <Form.List name="dependentes">
            {(campos, { add, remove }) => (
              <>
                {campos.map(({ key, name }) => (
                  <Row gutter={8} key={key} align="middle">
                    <Col xs={24} md={7}><Form.Item name={[name, 'nome']} rules={[{ required: true, message: 'Nome.' }]}><Input placeholder="Nome" /></Form.Item></Col>
                    <Col xs={12} md={4}><Form.Item name={[name, 'parentesco']}><Select allowClear placeholder="Parentesco" options={PARENTESCOS} /></Form.Item></Col>
                    <Col xs={12} md={4}><Form.Item name={[name, 'data_nascimento']}><DatePicker format="DD/MM/YYYY" placeholder="Nascimento" style={{ width: '100%' }} /></Form.Item></Col>
                    <Col xs={12} md={3}><Form.Item name={[name, 'sexo']}><Select allowClear placeholder="Sexo" options={[{ value: 'M', label: 'M' }, { value: 'F', label: 'F' }]} /></Form.Item></Col>
                    <Col xs={10} md={5}><Form.Item name={[name, 'dependente_fiscal']} valuePropName="checked"><Checkbox>Dependente fiscal</Checkbox></Form.Item></Col>
                    <Col xs={2} md={1}><Form.Item><MinusCircleOutlined onClick={() => remove(name)} aria-label="Retirar" /></Form.Item></Col>
                  </Row>
                ))}
                <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ dependente_fiscal: false })}>Acrescentar dependente</Button>
              </>
            )}
          </Form.List>
        </Card>
        <Card title="Habilitações literárias">
          <Form.List name="habilitacoes">
            {(campos, { add, remove }) => (
              <>
                {campos.map(({ key, name }) => (
                  <Row gutter={8} key={key} align="middle">
                    <Col xs={24} md={5}><Form.Item name={[name, 'nivel']} rules={[{ required: true, message: 'Nível.' }]}><Select placeholder="Nível" options={NIVEIS_HABILITACAO.map((n) => ({ value: n, label: n }))} /></Form.Item></Col>
                    <Col xs={24} md={6}><Form.Item name={[name, 'curso']}><Input placeholder="Curso" maxLength={255} /></Form.Item></Col>
                    <Col xs={24} md={6}><Form.Item name={[name, 'instituicao']}><Input placeholder="Instituição" maxLength={255} /></Form.Item></Col>
                    <Col xs={12} md={3}><Form.Item name={[name, 'ano_conclusao']} rules={[{ pattern: /^\d{4}$/, message: 'AAAA' }]}><Input placeholder="Ano" maxLength={4} /></Form.Item></Col>
                    <Col xs={10} md={3}><Form.Item name={[name, 'estado']}><Select allowClear placeholder="Estado" options={['Concluído', 'Em curso', 'Interrompido'].map((e) => ({ value: e, label: e }))} /></Form.Item></Col>
                    <Col xs={2} md={1}><Form.Item><MinusCircleOutlined onClick={() => remove(name)} aria-label="Retirar" /></Form.Item></Col>
                  </Row>
                ))}
                <Button type="dashed" icon={<PlusOutlined />} onClick={() => add()}>Acrescentar habilitação</Button>
              </>
            )}
          </Form.List>
          <Divider />
          <Alert type="info" showIcon message="Ao gravar, o agregado e as habilitações substituem os registados (na mesma transacção)." />
        </Card>
      </Form>
    </>
  );
}

/** Unidade orgânica, unidade de negócio ou centro de custo por código e nome (ficha, ADR-064). */
function refNome(r: ReferenciaNome | null | undefined, id: number | null | undefined): string {
  if (r) return [r.codigo, r.nome].filter(Boolean).join(' — ') || `#${r.id}`;
  return id ? `#${id}` : '—';
}
