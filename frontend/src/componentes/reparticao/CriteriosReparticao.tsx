import { Alert, Button, Card, Checkbox, Col, DatePicker, Form, Input, Popconfirm, Row, Select, Space, Table, Tag, Typography, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVE_CRITERIOS, useCriterios } from './api';
import { FORMAS, type CriterioReparticao, type FormaReparticao } from './regras';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';

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

/** Data da grade (`dayjs`) ou da vista Linhas ('AAAA-MM-DD'). */
const dataDe = (d: unknown): string | null => (dayjs.isDayjs(d) ? d.format('YYYY-MM-DD') : typeof d === 'string' && d ? d : null);

/**
 * Corpo do pedido do critério, comum à grade e à vista Linhas (datas em AAAA-MM-DD; indicador só no personalizado,
 * período só no volume de negócios; «Activo» em booleano ou 1/0).
 */
export function corpoCriterio(v: ValoresCriterio | Record<string, unknown>): Record<string, unknown> {
  const x = v as Record<string, unknown>;
  const t = (k: string) => String(x[k] ?? '').trim();
  const criterio = x.criterio as FormaReparticao;
  return {
    codigo: t('codigo').toUpperCase(), nome: t('nome'), criterio, ativo: x.ativo !== false && x.ativo !== 0, notas: t('notas') || null,
    indicador: criterio === 'PERSONALIZADO' ? t('indicador') || null : null,
    periodo_de: criterio === 'VOLUME_NEGOCIOS' ? dataDe(x.periodo_de) : null,
    periodo_ate: criterio === 'VOLUME_NEGOCIOS' ? dataDe(x.periodo_ate) : null,
  };
}

const SECCAO_FORMA = 'Forma de repartir';

/** Vista Linhas dos critérios de repartição (código, nome, forma; indicador, período e notas opcionais). */
const CAMPOS_CRITERIO: CampoFormulario[] = [
  { nome: 'codigo', rotulo: 'Código', obrigatorio: true, largura: 110, maxLength: 20, aliases: ['Codigo'],
    validar: (c) => (c && !/^[A-Za-z0-9._-]{1,20}$/.test(String(c).trim()) ? 'Só letras, números, ponto, hífen ou sublinhado (até 20).' : null) },
  { nome: 'nome', rotulo: 'Nome', obrigatorio: true, largura: 220, maxLength: 255 },
  { nome: 'criterio', rotulo: 'Forma de repartir', tipo: 'select', obrigatorio: true, largura: 200, omissao: 'PERCENTAGEM', aliases: ['Critério', 'Forma'],
    opcoes: Object.entries(FORMAS).map(([k, f]) => ({ value: k, label: f.nome })) },
  { nome: 'indicador', rotulo: 'Nome do indicador', largura: 160, maxLength: 100, emLinhas: 'opcional', seccao: SECCAO_FORMA },
  { nome: 'periodo_de', rotulo: 'Vendas desde', tipo: 'data', largura: 140, emLinhas: 'opcional', seccao: SECCAO_FORMA },
  { nome: 'periodo_ate', rotulo: 'Vendas até', tipo: 'data', largura: 140, emLinhas: 'opcional', seccao: SECCAO_FORMA,
    validar: (d, r) => (typeof d === 'string' && d && typeof r.periodo_de === 'string' && r.periodo_de && d < r.periodo_de ? 'Anterior ao início.' : null) },
  { nome: 'notas', rotulo: 'Notas', tipo: 'textoLongo', largura: 200, maxLength: 4000, emLinhas: 'opcional', seccao: 'Outros' },
  { nome: 'ativo', rotulo: 'Activo', tipo: 'select', opcoes: [{ value: 1, label: 'Sim' }, { value: 0, label: 'Não' }], omissao: 1, largura: 90, emLinhas: 'opcional', seccao: 'Outros' },
];

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
    form.setFieldsValue(c === 'novo' ? { criterio: 'PERCENTAGEM', ativo: 1 as unknown as boolean } : {
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
      <ModalFormulario chave="reparticao.criterios" titulo={edicao === 'novo' ? 'Novo critério de repartição' : `Critério ${edicao && typeof edicao === 'object' ? edicao.codigo : ''}`} aberto={edicao !== null}
        aoFechar={() => setEdicao(null)} criacao={edicao === 'novo'} form={form} campos={CAMPOS_CRITERIO} nomeRegistos="critérios de repartição" invalidar={[[...CHAVE_CRITERIOS]]}
        gravarLinha={(r) => enviar('post', '/reparticao/criterios', corpoCriterio(r))}
        okText={edicao === 'novo' ? 'Criar critério' : 'Gravar alterações'} cancelText="Cancelar" confirmLoading={gravar.isPending}
        onOk={() => form.submit()} width={680}>
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
          <Form.Item name="ativo" valuePropName="checked" getValueProps={(v: unknown) => ({ checked: v === true || v === 1 })} normalize={(v: unknown) => (v ? 1 : 0)}><Checkbox>Activo</Checkbox></Form.Item>
        </Form>
      </ModalFormulario>
    </Card>
  );
}
