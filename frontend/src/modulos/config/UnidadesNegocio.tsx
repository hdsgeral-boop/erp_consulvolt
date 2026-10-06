import { Button, Checkbox, Col, DatePicker, Divider, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Table, Tag, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar, obter, obterPagina } from '@/api/cliente';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';

export interface UnidadeNegocio {
  id: number;
  codigo: string;
  nome: string;
  nome_abreviado: string | null;
  descricao: string | null;
  unidade_negocio_pai_id: number | null;
  ordem_sequencia: number | null;
  estado: 'ATIVO' | 'INATIVO' | null;
  valido_de: string | null;
  valido_ate: string | null;
  endereco: string | null;
  cidade: string | null;
  estado_fluxo: string | null;
  codigo_postal: string | null;
  pais: string | null;
  telefone: string | null;
  email: string | null;
  fax: string | null;
  website: string | null;
  codigo_moeda: string | null;
  bolsa_valores: string | null;
  simbolo_bolsa: string | null;
  colaborador_gestor_id: number | null;
  tem_vendas: boolean | null;
  tem_servico: boolean | null;
}

type Valores = Omit<UnidadeNegocio, 'id' | 'valido_de' | 'valido_ate'> & { valido_de?: Dayjs | null; valido_ate?: Dayjs | null };

/** Corpo do pedido: datas em AAAA-MM-DD e textos vazios como nulos. */
export function corpoUnidade(v: Partial<Valores>): Record<string, unknown> {
  const d: Record<string, unknown> = {};
  for (const [k, x] of Object.entries(v)) d[k] = x === '' || x === undefined ? null : x;
  d.valido_de = v.valido_de ? v.valido_de.format('YYYY-MM-DD') : null;
  d.valido_ate = v.valido_ate ? v.valido_ate.format('YYYY-MM-DD') : null;
  return d;
}

/**
 * Unidades de negócio (M-14; legado js/modules/configuracoes/unidades_negocio.js): código, nome, hierarquia (unidade pai e
 * sequência), estado e validade, morada e contactos, moeda e bolsa, gerente e funções (vendas/serviços). Sobre a API
 * existente /api/sistema/unidades-negocio (consulta tabelas_aux, gravação aux_gerir, eliminação aux_eliminar).
 */
export function UnidadesNegocio() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const pequeno = useEcraPequeno();
  const [estado, setEstado] = useState<string>();
  const [edicao, setEdicao] = useState<UnidadeNegocio | 'nova' | null>(null);
  const [form] = Form.useForm<Valores>();
  const q = useQuery({ queryKey: ['sistema', 'unidades-negocio', estado], queryFn: () => obter<UnidadeNegocio[]>('/sistema/unidades-negocio', { estado }) });
  const colaboradores = useQuery({ queryKey: ['rh', 'colaboradores', 'gestores'], enabled: edicao !== null,
    queryFn: async () => (await obterPagina<{ id: number; nome_completo: string }>('/rh/colaboradores', { por_pagina: 500 })).itens });
  const lista = q.data ?? [];
  const nome = (id: number | null) => (id ? lista.find((u) => u.id === id)?.nome ?? `#${id}` : '—');
  const gravar = useMutation({
    mutationFn: (v: Valores) => enviar(edicao && edicao !== 'nova' ? 'put' : 'post', edicao && edicao !== 'nova' ? `/sistema/unidades-negocio/${edicao.id}` : '/sistema/unidades-negocio', corpoUnidade(v)),
    onSuccess: ({ mensagem }) => { message.success(mensagem); setEdicao(null); void cliente.invalidateQueries({ queryKey: ['sistema', 'unidades-negocio'] }); void cliente.invalidateQueries({ queryKey: ['contab'] }); },
    onError: (e) => notificarErro(e),
  });
  const eliminar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/sistema/unidades-negocio/${id}`),
    onSuccess: ({ mensagem }) => { message.success(mensagem); void cliente.invalidateQueries({ queryKey: ['sistema', 'unidades-negocio'] }); },
    onError: (e) => notificarErro(e),
  });
  const abrir = (u: UnidadeNegocio | 'nova') => {
    form.resetFields();
    if (u === 'nova') form.setFieldsValue({ estado: 'ATIVO', ordem_sequencia: 0, tem_vendas: false, tem_servico: false });
    else form.setFieldsValue({ ...u, valido_de: u.valido_de ? dayjs(u.valido_de) : null, valido_ate: u.valido_ate ? dayjs(u.valido_ate) : null });
    setEdicao(u);
  };
  const gerir = pode('aux_gerir');

  return (
    <>
      <BarraFiltros accoes={
        <Space wrap>
          <BotoesExportar desactivado={!lista.length} obterPedido={() => ({ titulo: 'Unidades de negócio', conteudo: tabelaHtml({ linhas: lista, colunas: [
            { titulo: 'Código', valor: (u) => u.codigo }, { titulo: 'Nome', valor: (u) => u.nome }, { titulo: 'Unidade pai', valor: (u) => (u.unidade_negocio_pai_id ? nome(u.unidade_negocio_pai_id) : '') },
            { titulo: 'Estado', valor: (u) => u.estado ?? '' }, { titulo: 'Vendas', valor: (u) => (u.tem_vendas ? 'Sim' : '') }, { titulo: 'Serviços', valor: (u) => (u.tem_servico ? 'Sim' : '') },
          ] }) })} />
          {gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('nova')}>Nova unidade</Button>}
        </Space>
      }>
        <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 160 }} options={[{ value: 'ATIVO', label: 'Activas' }, { value: 'INATIVO', label: 'Inactivas' }]} />
      </BarraFiltros>
      <Table<UnidadeNegocio> rowKey="id" size={pequeno ? 'small' : 'middle'} loading={q.isFetching} dataSource={lista} scroll={scrollTabela()} pagination={{ pageSize: 50 }} columns={[
        { title: 'Código', dataIndex: 'codigo', render: (v: string) => <strong>{v}</strong>, sorter: (a, b) => a.codigo.localeCompare(b.codigo, 'pt') },
        { title: 'Nome', dataIndex: 'nome' },
        { title: 'Abreviado', dataIndex: 'nome_abreviado', responsive: ['lg'] },
        { title: 'Unidade pai', dataIndex: 'unidade_negocio_pai_id', responsive: ['md'], render: (v: number | null) => nome(v) },
        { title: 'Seq.', dataIndex: 'ordem_sequencia', align: 'right', responsive: ['lg'] },
        { title: 'Funções', responsive: ['md'], render: (_, u) => <>{u.tem_vendas && <Tag color="blue">Vendas</Tag>}{u.tem_servico && <Tag color="purple">Serviços</Tag>}</> },
        { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => <Tag color={e === 'INATIVO' ? 'default' : 'green'}>{e === 'INATIVO' ? 'Inactiva' : 'Activa'}</Tag> },
        { title: '', key: 'a', align: 'right', render: (_, u) => gerir && (
          <Space size={4}>
            <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" onClick={() => abrir(u)} />
            {pode('aux_eliminar') && (
              <Popconfirm title="Eliminar a unidade de negócio?" okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar" onConfirm={() => eliminar.mutateAsync(u.id)}>
                <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" />
              </Popconfirm>
            )}
          </Space>
        ) },
      ]} />
      <Modal title={edicao === 'nova' ? 'Nova unidade de negócio' : `Unidade de negócio — ${edicao && typeof edicao === 'object' ? edicao.codigo : ''}`} open={edicao !== null} width={larguraModal(900)}
        onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)}>
          <Row gutter={12}>
            <Col xs={24} sm={8}><Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { max: 50 }]}><Input /></Form.Item></Col>
            <Col xs={24} sm={16}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}><Input /></Form.Item></Col>
            <Col xs={24} sm={8}><Form.Item name="nome_abreviado" label="Nome abreviado"><Input maxLength={100} /></Form.Item></Col>
            <Col xs={24} sm={16}><Form.Item name="descricao" label="Descrição"><Input.TextArea rows={1} maxLength={2000} /></Form.Item></Col>
            <Col xs={24} sm={10}><Form.Item name="unidade_negocio_pai_id" label="Unidade pai">
              <Select allowClear placeholder="— Nenhuma —" options={lista.filter((u) => !(edicao && edicao !== 'nova' && u.id === edicao.id)).map((u) => ({ value: u.id, label: `${u.codigo} — ${u.nome}` }))} />
            </Form.Item></Col>
            <Col xs={12} sm={4}><Form.Item name="ordem_sequencia" label="Sequência"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} sm={4}><Form.Item name="estado" label="Estado"><Select options={[{ value: 'ATIVO', label: 'Activa' }, { value: 'INATIVO', label: 'Inactiva' }]} /></Form.Item></Col>
            <Col xs={12} sm={3}><Form.Item name="valido_de" label="Início"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} sm={3}><Form.Item name="valido_ate" label="Fim"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
          <Divider orientation="left" plain>Morada e contactos</Divider>
          <Row gutter={12}>
            <Col xs={24}><Form.Item name="endereco" label="Morada"><Input maxLength={500} /></Form.Item></Col>
            <Col xs={12} sm={6}><Form.Item name="cidade" label="Cidade"><Input maxLength={100} /></Form.Item></Col>
            <Col xs={12} sm={6}><Form.Item name="estado_fluxo" label="Estado/Província"><Input maxLength={100} /></Form.Item></Col>
            <Col xs={12} sm={6}><Form.Item name="codigo_postal" label="C. Postal"><Input maxLength={30} /></Form.Item></Col>
            <Col xs={12} sm={6}><Form.Item name="pais" label="País"><Input maxLength={100} /></Form.Item></Col>
            <Col xs={12} sm={6}><Form.Item name="telefone" label="Telefone"><Input maxLength={50} /></Form.Item></Col>
            <Col xs={12} sm={6}><Form.Item name="email" label="E-mail" rules={[{ type: 'email', message: 'E-mail inválido.' }]}><Input maxLength={150} /></Form.Item></Col>
            <Col xs={12} sm={6}><Form.Item name="fax" label="Fax"><Input maxLength={50} /></Form.Item></Col>
            <Col xs={12} sm={6}><Form.Item name="website" label="Website"><Input maxLength={255} /></Form.Item></Col>
          </Row>
          <Divider orientation="left" plain>Gestão</Divider>
          <Row gutter={12}>
            <Col xs={8} sm={4}><Form.Item name="codigo_moeda" label="Moeda"><Input maxLength={10} placeholder="AOA" /></Form.Item></Col>
            <Col xs={8} sm={4}><Form.Item name="bolsa_valores" label="Bolsa"><InputNumber style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={8} sm={4}><Form.Item name="simbolo_bolsa" label="Ticker"><Input maxLength={50} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="colaborador_gestor_id" label="Gerente">
              <Select allowClear showSearch optionFilterProp="label" placeholder="— Seleccione —" loading={colaboradores.isLoading}
                options={(colaboradores.data ?? []).map((c) => ({ value: c.id, label: c.nome_completo }))} />
            </Form.Item></Col>
            <Col xs={24}>
              <Space wrap>
                <Form.Item name="tem_vendas" valuePropName="checked" noStyle><Checkbox>Função Vendas</Checkbox></Form.Item>
                <Form.Item name="tem_servico" valuePropName="checked" noStyle><Checkbox>Função Serviços</Checkbox></Form.Item>
              </Space>
            </Col>
          </Row>
        </Form>
      </Modal>
    </>
  );
}
