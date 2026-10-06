import { Alert, Button, Card, Checkbox, Col, Descriptions, Drawer, Flex, Form, Input, List, Modal, Popconfirm, Row, Segmented, Select, Skeleton, Space, Statistic, Table, Tabs, Tag, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, MailOutlined, PlusOutlined, StarFilled, UserAddOutlined, UserSwitchOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { BotoesExportar } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { SeletorConta } from '@/modulos/contab/comum/Seletores';
import { CHAVE_CRM, useConfigCRM } from './comum/dados';
import { ListaActividades, ModalActividade, ModalEmail } from './comum/componentes';
import { FichaOportunidade, FormOportunidade } from './comum/Oportunidade';
import type { Actividade, ContaCRM, Contacto, Oportunidade } from './comum/tipos';
import { larguraGaveta, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';

interface Ficha360 {
  conta: ContaCRM;
  contactos: Contacto[];
  oportunidades: Oportunidade[];
  atividades: Actividade[];
  financeiro: {
    facturas: { id: number; doc: string; data: string; total: string; pago: string; saldo: string; vencimento: string; dias_atraso: number }[];
    em_aberto: string;
    em_atraso: string;
    max_dias_atraso: number;
    n_atrasadas: number;
    facturado_12m: string;
    ultima_venda: string | null;
    documentos: { id: number; tipo: string; doc: string; data: string | null; total: string; estado: string; oportunidade_crm_id: number | null }[];
  };
  resumo: { abertas: number; valor_aberto: string; ganhas: number; perdidas: number; taxa_ganho: number | null };
}

/** CRM › Contas (crm_contas): prospects e clientes, contactos, ficha 360º (oportunidades, actividades, financeiro) e passagem a cliente. */
export default function Contas() {
  const { pode } = useSessao();
  const editar = pode('crm_editar');
  const [filtros, setFiltros] = useState<{ tipo?: string; pesquisa?: string; responsavel?: string; em_atraso?: number }>({});
  const [ficha, setFicha] = useState<number | null>(null);
  const [edicao, setEdicao] = useState<ContaCRM | 'nova' | null>(null);
  const [doCliente, setDoCliente] = useState(false);
  const pequeno = useEcraPequeno();

  return (
    <>
      <CabecalhoPagina
        titulo="Contas e contactos"
        subtitulo="Prospects e clientes com a visão 360º da relação comercial"
        accoes={
          editar && (
            <>
              <Button icon={<UserSwitchOutlined />} onClick={() => setDoCliente(true)}>A partir de cliente</Button>
              <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('nova')}>Nova conta</Button>
            </>
          )
        }
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 12 }}>
          <Segmented value={filtros.tipo ?? ''} onChange={(v) => setFiltros({ ...filtros, tipo: (v as string) || undefined })} options={[{ value: '', label: 'Todas' }, { value: 'PROSPECT', label: 'Prospects' }, { value: 'CLIENTE', label: 'Clientes' }]} />
          <Input.Search placeholder="Nome, NIF, email ou sector" allowClear style={{ width: 280, maxWidth: '100%' }} onSearch={(v) => setFiltros({ ...filtros, pesquisa: v || undefined })} />
          <Input.Search placeholder="Responsável" allowClear style={{ width: 160, maxWidth: '100%' }} onSearch={(v) => setFiltros({ ...filtros, responsavel: v || undefined })} />
          <Checkbox checked={!!filtros.em_atraso} onChange={(e) => setFiltros({ ...filtros, em_atraso: e.target.checked ? 1 : undefined })}>Só com facturas em atraso</Checkbox>
        </Flex>
        <TabelaApi<ContaCRM>
          url="/crm/contas"
          chaveConsulta={['crm', 'contas', 'lista']}
          filtros={filtros}
          onRow={(c) => ({ onClick: () => setFicha(c.id), style: { cursor: 'pointer' } })}
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: 'Contas do CRM',
            filtros: [filtros.tipo && `Tipo: ${filtros.tipo === 'CLIENTE' ? 'Clientes' : 'Prospects'}`, filtros.pesquisa && `Pesquisa: ${filtros.pesquisa}`, filtros.responsavel && `Responsável: ${filtros.responsavel}`, !!filtros.em_atraso && 'Só com facturas em atraso'],
          }}
          columns={[
            { title: 'Conta', dataIndex: 'nome', valorImpressao: (c) => [c.nome, c.nif && `NIF ${c.nif}`, c.setor].filter(Boolean).join(' · '), render: (v: string, c) => (<><strong>{v}</strong><div style={{ fontSize: 12, color: 'rgba(0,0,0,0.55)' }}>{[c.nif && `NIF ${c.nif}`, c.setor].filter(Boolean).join(' · ')}</div></>) },
            { title: 'Tipo', dataIndex: 'tipo', responsive: ['sm'], render: (t: string) => (t === 'CLIENTE' ? <Tag color="green">Cliente</Tag> : <Tag color="blue">Prospect</Tag>) },
            { title: 'Contacto', key: 'ct', responsive: ['lg'], render: (_, c) => [c.email, c.telefone].filter(Boolean).join(' · ') || '—' },
            { title: 'Responsável', dataIndex: 'responsavel', responsive: ['md'] },
            { title: 'Abertas', dataIndex: 'oportunidades_abertas', align: 'right', responsive: ['md'] },
            { title: 'Valor aberto', dataIndex: 'valor_aberto', align: 'right', render: (v: string) => formatarKz(v) },
            { title: 'Em atraso', key: 'f', align: 'right', responsive: ['lg'], valorImpressao: (c) => (c.financeiro && Number(c.financeiro.em_atraso) > 0 ? formatarKz(c.financeiro.em_atraso) : ''), render: (_, c) => (c.financeiro && Number(c.financeiro.em_atraso) > 0 ? <Typography.Text type="danger">{formatarKz(c.financeiro.em_atraso)}</Typography.Text> : '—') },
          ]}
        />
      </Card>
      <FichaConta id={ficha} aoFechar={() => setFicha(null)} aoEditar={setEdicao} />
      <FormConta conta={edicao} aoFechar={() => setEdicao(null)} aoCriar={(id) => setFicha(id)} />
      <ModalDoCliente aberto={doCliente} aoFechar={() => setDoCliente(false)} aoAbrir={(id) => setFicha(id)} />
    </>
  );
}

function ModalDoCliente({ aberto, aoFechar, aoAbrir }: { aberto: boolean; aoFechar: () => void; aoAbrir: (id: number) => void }) {
  const [terceiro, setTerceiro] = useState<number | undefined>();
  const accao = useAccao<ContaCRM>({ invalidar: [CHAVE_CRM], aoSucesso: (c) => { aoFechar(); aoAbrir(c.id); } });
  return (
    <Modal open={aberto} title="Conta do CRM a partir de um cliente" onCancel={aoFechar} okText="Abrir conta" cancelText="Cancelar" okButtonProps={{ disabled: !terceiro }} confirmLoading={accao.isPending} onOk={() => accao.mutate({ url: '/crm/contas/do-cliente', dados: { terceiro_id: terceiro } })} destroyOnHidden>
      <Typography.Paragraph type="secondary">Abre a conta do CRM ligada ao cliente; se ainda não existir, é criada com os dados do cliente.</Typography.Paragraph>
      <SeletorTerceiro papel="CLIENTE" value={terceiro} onChange={setTerceiro} style={{ width: '100%' }} />
    </Modal>
  );
}

function FormConta({ conta, aoFechar, aoCriar }: { conta: ContaCRM | 'nova' | null; aoFechar: () => void; aoCriar: (id: number) => void }) {
  const config = useConfigCRM();
  const { utilizador } = useSessao();
  const [form] = Form.useForm();
  const nova = conta === 'nova';
  const accao = useAccao<ContaCRM>({ invalidar: [CHAVE_CRM], aoSucesso: (c) => { aoFechar(); if (nova) aoCriar(c.id); } });
  useEffect(() => {
    if (!conta) return;
    form.resetFields();
    if (conta === 'nova') form.setFieldsValue({ responsavel: utilizador?.nome_utilizador });
    else form.setFieldsValue({ ...conta, origem: conta.origem_original ?? conta.origem });
  }, [conta, form, utilizador]);
  return (
    <Modal open={!!conta} title={nova ? 'Nova conta' : 'Editar conta'} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(720)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: nova ? 'post' : 'put', url: nova ? '/crm/contas' : `/crm/contas/${(conta as ContaCRM).id}`, dados: v })}>
        <Row gutter={16}>
          <Col xs={24} sm={16}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} sm={8}><Form.Item name="nif" label="NIF"><Input maxLength={30} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="email" label="Email"><Input maxLength={150} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="telefone" label="Telefone"><Input maxLength={50} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="setor" label="Sector"><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="website" label="Website"><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="origem" label="Origem"><Select allowClear options={(config.data?.origens ?? []).map((o) => ({ value: o, label: o }))} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="responsavel" label="Responsável"><Input maxLength={100} /></Form.Item></Col>
          <Col span={24}><Form.Item name="morada" label="Morada"><Input.TextArea rows={2} maxLength={1000} /></Form.Item></Col>
          <Col span={24}><Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={4000} /></Form.Item></Col>
        </Row>
      </Form>
    </Modal>
  );
}

function FichaConta({ id, aoFechar, aoEditar }: { id: number | null; aoFechar: () => void; aoEditar: (c: ContaCRM) => void }) {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const editar = pode('crm_editar');
  const q = useQuery({ queryKey: ['crm', 'conta', id], queryFn: () => obter<Ficha360>(`/crm/contas/${id}`), enabled: id !== null });
  const [contacto, setContacto] = useState<Contacto | 'novo' | null>(null);
  const [actividade, setActividade] = useState<Actividade | 'nova' | null>(null);
  const [email, setEmail] = useState(false);
  const [oportunidade, setOportunidade] = useState<number | null>(null);
  const [nova, setNova] = useState(false);
  const [converter, setConverter] = useState(false);
  const [contaCodigo, setContaCodigo] = useState<string | undefined>();
  const accaoContacto = useAccao({ invalidar: [CHAVE_CRM] });
  const accaoConverter = useAccao({ invalidar: [CHAVE_CRM], aoSucesso: () => setConverter(false) });
  const d = q.data;
  const c = d?.conta;

  return (
    <Drawer
      open={id !== null}
      onClose={aoFechar}
      width={larguraGaveta(900)}
      destroyOnHidden
      rootClassName="crm-ficha-conta"
      title={c ? <Space wrap>{c.nome}{c.tipo === 'CLIENTE' ? <Tag color="green">Cliente</Tag> : <Tag color="blue">Prospect</Tag>}</Space> : 'Conta'}
      extra={
        c && (
          <Space wrap>
            <BotoesExportar
              tamanho="small"
              obterPedido={() => {
                const corpo = document.querySelector('.crm-ficha-conta .ant-drawer-body');
                return corpo ? { titulo: `Ficha da conta ${c.nome}`, subtitulo: c.tipo === 'CLIENTE' ? 'Cliente' : 'Prospect', conteudo: corpo } : null;
              }}
            />
            {editar && <>
            <Button icon={<MailOutlined />} onClick={() => setEmail(true)}>Email</Button>
            <Button icon={<PlusOutlined />} onClick={() => setNova(true)}>Oportunidade</Button>
            {c.tipo === 'PROSPECT' && pode('crm_converter') && <Button icon={<UserAddOutlined />} onClick={() => { setContaCodigo(undefined); setConverter(true); }}>Passar a cliente</Button>}
            <Button icon={<EditOutlined />} onClick={() => aoEditar(c)}>Editar</Button>
            </>}
          </Space>
        )
      }
    >
      {!d || !c ? (
        <Skeleton active />
      ) : (
        <>
          <Row gutter={[12, 12]} style={{ marginBottom: 12 }}>
            <Col xs={12} md={6}><Card size="small"><Statistic title="Oportunidades abertas" value={d.resumo.abertas} /></Card></Col>
            <Col xs={12} md={6}><Card size="small"><Statistic title="Valor aberto (Kz)" value={formatarKz(d.resumo.valor_aberto)} /></Card></Col>
            <Col xs={12} md={6}><Card size="small"><Statistic title="Ganhas / perdidas" value={`${d.resumo.ganhas} / ${d.resumo.perdidas}`} suffix={d.resumo.taxa_ganho !== null ? <span style={{ fontSize: 12 }}>({d.resumo.taxa_ganho}%)</span> : undefined} /></Card></Col>
            <Col xs={12} md={6}><Card size="small"><Statistic title="Facturado 12 meses (Kz)" value={formatarKz(d.financeiro.facturado_12m)} /></Card></Col>
          </Row>
          {Number(d.financeiro.em_atraso) > 0 && (
            <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`${formatarKz(d.financeiro.em_atraso)} Kz em atraso em ${d.financeiro.n_atrasadas} factura(s) (até ${d.financeiro.max_dias_atraso} dias).`} />
          )}
          <Descriptions size="small" column={{ xs: 1, sm: 2 }} bordered>
            <Descriptions.Item label="NIF">{c.nif ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Sector">{c.setor ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Email">{c.email ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Telefone">{c.telefone ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Origem">{c.origem_original ?? c.origem ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Responsável">{c.responsavel ?? '—'}</Descriptions.Item>
            {c.morada && <Descriptions.Item label="Morada" span={2}>{c.morada}</Descriptions.Item>}
            {c.notas && <Descriptions.Item label="Notas" span={2}>{c.notas}</Descriptions.Item>}
          </Descriptions>
          <Tabs
            style={{ marginTop: 12 }}
            items={[
              {
                key: 'contactos',
                label: `Contactos (${d.contactos.length})`,
                children: (
                  <>
                    {editar && <Button size="small" icon={<PlusOutlined />} style={{ marginBottom: 8 }} onClick={() => setContacto('novo')}>Contacto</Button>}
                    <List
                      size="small"
                      dataSource={d.contactos}
                      locale={{ emptyText: 'Sem contactos.' }}
                      renderItem={(x) => (
                        <List.Item
                          actions={
                            editar
                              ? [
                                  <Button key="e" size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => setContacto(x)} />,
                                  <Popconfirm key="d" title={`Eliminar ${x.nome}?`} okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accaoContacto.mutateAsync({ metodo: 'delete', url: `/crm/contas/${c.id}/contactos/${x.id}` })}>
                                    <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
                                  </Popconfirm>,
                                ]
                              : []
                          }
                        >
                          <List.Item.Meta title={<Space wrap>{x.nome}{x.principal && <StarFilled style={{ color: '#faad14' }} aria-label="Principal" />}</Space>} description={[x.cargo, x.email, x.telefone].filter(Boolean).join(' · ')} />
                        </List.Item>
                      )}
                    />
                  </>
                ),
              },
              {
                key: 'oportunidades',
                label: `Oportunidades (${d.oportunidades.length})`,
                children: (
                  <Table scroll={scrollTabela()}
                    size="small"
                    rowKey="id"
                    pagination={false}
                    dataSource={d.oportunidades}
                    onRow={(o) => ({ onClick: () => setOportunidade(o.id), style: { cursor: 'pointer' } })}
                    columns={[
                      { title: 'Oportunidade', dataIndex: 'titulo' },
                      { title: 'Estado', dataIndex: 'estado', render: (e: string) => <Tag color={e === 'GANHA' ? 'green' : e === 'PERDIDA' ? 'default' : 'blue'}>{e}</Tag> },
                      { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v) },
                      { title: 'Fecho previsto', dataIndex: 'data_fecho_prevista', render: formatarData },
                    ]}
                  />
                ),
              },
              {
                key: 'actividades',
                label: `Actividades (${d.atividades.filter((a) => !a.concluida).length})`,
                children: (
                  <>
                    {editar && <Button size="small" icon={<PlusOutlined />} style={{ marginBottom: 8 }} onClick={() => setActividade('nova')}>Actividade</Button>}
                    <ListaActividades actividades={d.atividades} podeEditar={editar} aoEditar={setActividade} />
                  </>
                ),
              },
              {
                key: 'financeiro',
                label: 'Financeiro',
                children: c.terceiro_id ? (
                  <>
                    <Typography.Title level={5}>Facturas</Typography.Title>
                    <Table scroll={scrollTabela()}
                      size="small"
                      rowKey="id"
                      dataSource={d.financeiro.facturas}
                      pagination={{ pageSize: 10, size: 'small', hideOnSinglePage: true }}
                      columns={[
                        { title: 'Factura', dataIndex: 'doc' },
                        { title: 'Data', dataIndex: 'data', render: formatarData },
                        { title: 'Vencimento', dataIndex: 'vencimento', render: formatarData },
                        { title: 'Total', dataIndex: 'total', align: 'right', render: (v: string) => formatarKz(v) },
                        { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string) => formatarKz(v) },
                        { title: 'Atraso', dataIndex: 'dias_atraso', align: 'right', render: (n: number) => (n > 0 ? <Tag color="red">{n} dias</Tag> : '—') },
                      ]}
                    />
                    <Typography.Title level={5} style={{ marginTop: 12 }}>Últimos documentos</Typography.Title>
                    <Table scroll={scrollTabela()}
                      size="small"
                      rowKey="id"
                      dataSource={d.financeiro.documentos}
                      pagination={false}
                      onRow={(v) => ({ onClick: () => navegar(`/m/vendas/vendas_faturacao/${v.id}`), style: { cursor: 'pointer' } })}
                      columns={[
                        { title: 'Documento', dataIndex: 'doc' },
                        { title: 'Data', dataIndex: 'data', render: formatarData },
                        { title: 'Total', dataIndex: 'total', align: 'right', render: (v: string) => formatarKz(v) },
                        { title: 'Estado', dataIndex: 'estado' },
                      ]}
                    />
                  </>
                ) : (
                  <Alert type="info" showIcon message="Prospect sem cliente associado: ainda não há movimento financeiro." />
                ),
              },
            ]}
          />
        </>
      )}
      <FormContacto contaId={c?.id} contacto={contacto} aoFechar={() => setContacto(null)} />
      <ModalActividade actividade={actividade} contexto={{ conta_crm_id: c?.id }} aoFechar={() => setActividade(null)} />
      <ModalEmail contexto={email && c ? { conta_crm_id: c.id } : null} aoFechar={() => setEmail(false)} />
      <FichaOportunidade id={oportunidade} aoFechar={() => setOportunidade(null)} />
      <FormOportunidade oportunidade={null} aberto={nova} aoFechar={() => setNova(false)} inicial={c ? { conta_crm_id: c.id } : undefined} />
      <Modal
        open={converter}
        title="Passar o prospect a cliente"
        onCancel={() => setConverter(false)}
        okText="Criar cliente"
        cancelText="Cancelar"
        okButtonProps={{ disabled: !contaCodigo }}
        confirmLoading={accaoConverter.isPending}
        onOk={() => accaoConverter.mutate({ url: `/crm/contas/${c!.id}/converter-em-cliente`, dados: { codigo_conta: contaCodigo } })}
        destroyOnHidden
      >
        <Typography.Paragraph>É criado o cliente (terceiro) com os dados da conta. Escolha a conta contabilística do cliente (classe 31).</Typography.Paragraph>
        <SeletorConta value={contaCodigo} onChange={setContaCodigo} prefixos={['31']} placeholder="Conta do cliente (31…)" style={{ width: '100%' }} />
      </Modal>
    </Drawer>
  );
}

function FormContacto({ contaId, contacto, aoFechar }: { contaId?: number; contacto: Contacto | 'novo' | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [CHAVE_CRM], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!contacto) return;
    form.resetFields();
    if (contacto !== 'novo') form.setFieldsValue({ ...contacto, principal: !!contacto.principal });
  }, [contacto, form]);
  return (
    <Modal open={!!contacto} title={contacto === 'novo' ? 'Novo contacto' : 'Editar contacto'} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => accao.mutate({ metodo: contacto === 'novo' ? 'post' : 'put', url: contacto === 'novo' ? `/crm/contas/${contaId}/contactos` : `/crm/contas/${contaId}/contactos/${(contacto as Contacto).id}`, dados: v })}
      >
        <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
        <Form.Item name="cargo" label="Cargo"><Input maxLength={100} /></Form.Item>
        <Form.Item name="email" label="Email"><Input maxLength={150} /></Form.Item>
        <Form.Item name="telefone" label="Telefone"><Input maxLength={50} /></Form.Item>
        <Form.Item name="principal" valuePropName="checked"><Checkbox>Contacto principal</Checkbox></Form.Item>
      </Form>
    </Modal>
  );
}
