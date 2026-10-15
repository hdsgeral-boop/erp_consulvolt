import { Alert, Button, Card, Checkbox, Col, DatePicker, Form, Input, Modal, Popconfirm, Row, Select, Space, Table, Tag, Typography, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVE_CRITERIOS, useCriterios } from './api';
import { FORMAS, type CriterioReparticao, type FormaReparticao } from './regras';

interface ValoresCriterio {
  codigo: string;
  nome: string;
  criterio: FormaReparticao;
  indicador?: string;
  periodo_de?: Dayjs | null;
  periodo_ate?: Dayjs | null;
  notas?: string;
  ativo: boolean;
}

/** Corpo do pedido do critério (datas em AAAA-MM-DD; indicador só no personalizado, período só no volume de negócios). */
export function corpoCriterio(v: ValoresCriterio): Record<string, unknown> {
  return {
    codigo: v.codigo.trim().toUpperCase(), nome: v.nome.trim(), criterio: v.criterio, ativo: v.ativo !== false, notas: v.notas?.trim() || null,
    indicador: v.criterio === 'PERSONALIZADO' ? v.indicador?.trim() || null : null,
    periodo_de: v.criterio === 'VOLUME_NEGOCIOS' && v.periodo_de ? v.periodo_de.format('YYYY-MM-DD') : null,
    periodo_ate: v.criterio === 'VOLUME_NEGOCIOS' && v.periodo_ate ? v.periodo_ate.format('YYYY-MM-DD') : null,
  };
}

/**
 * Configurações › Unidades de negócio › «Critérios de repartição» (reparticao_ui.js do legado novo). O critério define só a
 * FORMA de repartir; as unidades e os valores de cada uma indicam-se depois, em cada registo. Gravação com `aux_gerir`,
 * eliminação com `aux_eliminar` (um critério em uso não se elimina: desactiva-se).
 */
export function CriteriosReparticao() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const q = useCriterios(false);
  const [edicao, setEdicao] = useState<CriterioReparticao | 'novo' | null>(null);
  const [form] = Form.useForm<ValoresCriterio>();
  const forma = (Form.useWatch('criterio', form) ?? 'PERCENTAGEM') as FormaReparticao;
  const actualizar = () => void cliente.invalidateQueries({ queryKey: CHAVE_CRITERIOS });

  const gravar = useMutation({
    mutationFn: (v: ValoresCriterio) => enviar(edicao && edicao !== 'novo' ? 'put' : 'post', edicao && edicao !== 'novo' ? `/reparticao/criterios/${edicao.id}` : '/reparticao/criterios', corpoCriterio(v)),
    onSuccess: ({ mensagem }) => { message.success(mensagem); setEdicao(null); actualizar(); },
    onError: (e) => notificarErro(e, 'Não foi possível gravar o critério'),
  });
  const eliminar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/reparticao/criterios/${id}`),
    onSuccess: ({ mensagem }) => { message.success(mensagem); actualizar(); },
    onError: (e) => notificarErro(e, 'Não foi possível eliminar o critério'),
  });
  const abrir = (c: CriterioReparticao | 'novo') => {
    form.resetFields();
    form.setFieldsValue(c === 'novo' ? { criterio: 'PERCENTAGEM', ativo: true } : {
      codigo: c.codigo, nome: c.nome, criterio: c.criterio, indicador: c.indicador ?? undefined, notas: c.notas ?? undefined, ativo: c.ativo !== false,
      periodo_de: c.periodo_de ? dayjs(c.periodo_de) : null, periodo_ate: c.periodo_ate ? dayjs(c.periodo_ate) : null,
    });
    setEdicao(c);
  };
  const gerir = pode('aux_gerir');

  return (
    <Card title="Critérios de repartição" size="small" style={{ marginTop: 16 }}
      extra={gerir && <Button type="primary" size="small" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Novo critério</Button>}>
      <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>
        O critério diz só a forma de repartir um custo ou proveito pelas unidades de negócio. As unidades e o valor de cada uma alocam-se em cada registo
        (colaborador, pedido, factura, venda) ou na hora, ao repartir uma linha do Diário ou da Tesouraria.
      </Typography.Paragraph>
      <Table<CriterioReparticao> size="small" rowKey="id" loading={q.isFetching} dataSource={q.data ?? []} pagination={false}
        locale={{ emptyText: 'Ainda não há critérios de repartição.' }}
        columns={[
          { title: 'Código', dataIndex: 'codigo', render: (v: string) => <strong>{v}</strong> },
          { title: 'Nome', dataIndex: 'nome', render: (v: string, c) => <>{v} {c.ativo === false && <Tag>inactivo</Tag>}</> },
          { title: 'Forma de repartir', dataIndex: 'criterio', render: (f: FormaReparticao) => FORMAS[f]?.nome ?? f },
          { title: 'Como obtém o peso', responsive: ['md'], render: (_, c) => {
            const f = FORMAS[c.criterio];
            return f?.automatico ? 'calculado a partir dos dados' : `${f?.rotulo_peso ?? 'Peso'} indicado em cada registo`;
          } },
          { title: '', key: 'a', align: 'right', render: (_, c) => gerir && (
            <Space size={4}>
              <Button size="small" type="text" icon={<EditOutlined />} aria-label={`Editar ${c.codigo}`} onClick={() => abrir(c)} />
              {pode('aux_eliminar') && (
                <Popconfirm title={`Eliminar ${c.codigo} — ${c.nome}?`} description="Um critério em uso não se elimina: desactive-o." okText="Eliminar" okButtonProps={{ danger: true }}
                  cancelText="Cancelar" onConfirm={() => eliminar.mutateAsync(c.id)}>
                  <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label={`Eliminar ${c.codigo}`} />
                </Popconfirm>
              )}
            </Space>
          ) },
        ]} />
      <Modal title={edicao === 'novo' ? 'Novo critério de repartição' : `Critério ${edicao && typeof edicao === 'object' ? edicao.codigo : ''}`} open={edicao !== null}
        onCancel={() => setEdicao(null)} okText={edicao === 'novo' ? 'Criar critério' : 'Gravar alterações'} cancelText="Cancelar" confirmLoading={gravar.isPending}
        onOk={() => form.submit()} destroyOnHidden width={680}>
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)}>
          <Row gutter={12}>
            <Col xs={24} sm={8}>
              <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código do critério.' },
                { pattern: /^[A-Za-z0-9._-]{1,20}$/, message: 'Só letras, números, ponto, hífen ou sublinhado (até 20).' }]}>
                <Input maxLength={20} placeholder="Ex.: AREA" />
              </Form.Item>
            </Col>
            <Col xs={24} sm={16}>
              <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome do critério.' }, { max: 255 }]}>
                <Input placeholder="Ex.: Por área ocupada" />
              </Form.Item>
            </Col>
          </Row>
          <Form.Item name="criterio" label="Forma de repartir" rules={[{ required: true }]}>
            <Select options={Object.entries(FORMAS).map(([k, f]) => ({ value: k, label: f.nome }))} />
          </Form.Item>
          <Alert type="info" style={{ marginBottom: 12 }} message={FORMAS[forma]?.ajuda} description={`Exemplo: ${FORMAS[forma]?.exemplo ?? ''}`} />
          {forma === 'PERSONALIZADO' && (
            <Form.Item name="indicador" label="Nome do indicador" rules={[{ max: 100 }]}>
              <Input placeholder="Ex.: horas-máquina" />
            </Form.Item>
          )}
          {forma === 'VOLUME_NEGOCIOS' && (
            <Row gutter={12}>
              <Col span={12}><Form.Item name="periodo_de" label="Vendas desde"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
              <Col span={12}>
                <Form.Item name="periodo_ate" label="Vendas até" dependencies={['periodo_de']} rules={[({ getFieldValue }) => ({
                  validator: async (_, v?: Dayjs | null) => (!v || !getFieldValue('periodo_de') || !v.isBefore(getFieldValue('periodo_de'), 'day') ? undefined : Promise.reject(new Error('Anterior ao início.'))),
                })]}>
                  <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
                </Form.Item>
              </Col>
            </Row>
          )}
          <Form.Item name="notas" label="Notas" rules={[{ max: 4000 }]}><Input.TextArea rows={2} /></Form.Item>
          <Form.Item name="ativo" valuePropName="checked"><Checkbox>Activo</Checkbox></Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
