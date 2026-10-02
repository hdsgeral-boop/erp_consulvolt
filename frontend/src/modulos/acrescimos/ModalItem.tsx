import { Alert, Button, Checkbox, Col, DatePicker, Form, Input, InputNumber, Modal, Radio, Row, Select, Table, Typography } from 'antd';
import { larguraModal } from '@/componentes/responsivo';
import { CalculatorOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { enviar } from '@/api/cliente';
import { SeletorAux, SeletorTerceiro, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { SeletorProjecto } from '@/modulos/activos/comum/componentes';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi } from '@/utilitarios/formatacao';
import { useDefinicoes } from './comum/componentes';
import { contaBalancoPadrao, diferencaPlano, fimPorMeses, somaQuotas } from './comum/regras';
import type { ItemAD, Origem, Quota } from './comum/tipos';

export interface ValoresIniciais extends Partial<Omit<ItemAD, 'origem'>> {
  origem?: Origem | null;
}

/**
 * Registo de acréscimo/diferimento (criar/editar). Com lançamentos, o servidor só aceita alterar as notas e a data limite.
 * «Ver plano» simula a repartição (POST /acrescimos/quotas, sem gravar).
 */
export function ModalItem({ aberto, item, parcial, aoFechar, aoGravar }: { aberto: boolean; item?: ValoresIniciais | null; parcial?: boolean; aoFechar: () => void; aoGravar?: (it: ItemAD) => void }) {
  const [form] = Form.useForm();
  const def = useDefinicoes();
  const [quotas, setQuotas] = useState<Quota[] | null>(null);
  const [aSimular, setASimular] = useState(false);
  const tipo = Form.useWatch('tipo', form);
  const natureza = Form.useWatch('natureza', form);
  const accao = useAccao<{ item: ItemAD; parcial: boolean }>({ invalidar: [['acrescimos']], aoSucesso: (r) => { aoGravar?.(r.item); aoFechar(); } });
  const editar = !!item?.id;

  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    setQuotas(null);
    const d = (v?: string | null) => (v ? dayjs(v) : undefined);
    form.setFieldsValue({
      tipo: 'DIFERIMENTO', natureza: 'CUSTO', reparticao: 'MESES',
      ...item,
      valor: item?.valor ? Number(item.valor) : undefined,
      data_inicio: d(item?.data_inicio), data_fim: d(item?.data_fim), data_documento: d(item?.data_documento), data_limite: d(item?.data_limite),
      documento_em_balanco: !!item?.documento_em_balanco,
    });
  }, [aberto, item, form]);

  // conta 37 por omissão quando muda o tipo/natureza (só em registos novos)
  useEffect(() => {
    if (!aberto || editar) return;
    const c = contaBalancoPadrao(def.data, tipo, natureza);
    if (c) form.setFieldValue('conta_balanco', c);
  }, [aberto, editar, tipo, natureza, def.data, form]);

  const aplicarModelo = (id: string) => {
    const m = def.data?.modelos.find((x) => x.id === id);
    if (!m) return;
    const inicio: dayjs.Dayjs = form.getFieldValue('data_inicio') ?? dayjs().startOf('month');
    form.setFieldsValue({ tipo: m.tipo, natureza: m.natureza, descricao: form.getFieldValue('descricao') || m.rotulo, data_inicio: inicio, data_fim: fimPorMeses(inicio, m.meses) });
  };

  const simular = async () => {
    const v = form.getFieldsValue(['valor', 'data_inicio', 'data_fim', 'reparticao']);
    if (!v.valor || !v.data_inicio || !v.data_fim) return;
    setASimular(true);
    try {
      const r = await enviar<Quota[]>('post', '/acrescimos/quotas', { valor: v.valor, data_inicio: dataApi(v.data_inicio), data_fim: dataApi(v.data_fim), reparticao: v.reparticao });
      setQuotas(r.dados);
    } catch (e) {
      notificarErro(e, 'Não foi possível calcular o plano');
    } finally {
      setASimular(false);
    }
  };

  const gravar = (v: Record<string, unknown>) => {
    const dados: Record<string, unknown> = { ...v };
    for (const k of ['data_inicio', 'data_fim', 'data_documento', 'data_limite']) dados[k] = dataApi(v[k] as dayjs.Dayjs) ?? null;
    for (const k of ['terceiro_id', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'notas']) if (dados[k] === undefined || dados[k] === '') dados[k] = null;
    delete dados.modelo;
    dados.origem = item?.origem ?? null;
    accao.mutate(editar ? { metodo: 'put', url: `/acrescimos/itens/${item?.id}`, dados } : { url: '/acrescimos/itens', dados });
  };

  const prefixoResultado = natureza === 'PROVEITO' ? '6' : '7';
  const bloq = !!parcial;

  return (
    <Modal
      title={editar ? `Editar registo #${item?.id}` : 'Novo registo de acréscimo / diferimento'}
      open={aberto}
      onCancel={aoFechar}
      width={larguraModal(920)}
      destroyOnHidden
      footer={[
        <Button key="p" icon={<CalculatorOutlined />} loading={aSimular} onClick={simular}>Ver plano</Button>,
        <Button key="c" onClick={aoFechar}>Cancelar</Button>,
        <Button key="g" type="primary" loading={accao.isPending} onClick={() => form.submit()}>Gravar</Button>,
      ]}
    >
      {bloq && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="O registo já tem lançamentos: só as notas e a data limite podem ser alteradas." />}
      {item?.origem?.doc && <Alert type="success" showIcon style={{ marginBottom: 12 }} message={`Documento de origem: ${item.origem.fonte ?? ''} ${item.origem.doc}${item.origem.data ? ` de ${dayjs(item.origem.data).format('DD/MM/YYYY')}` : ''}`} />}
      <Form form={form} layout="vertical" onFinish={gravar} onValuesChange={() => setQuotas(null)}>
        <Row gutter={[12, 0]}>
          {!editar && (
            <Col xs={24}>
              <Form.Item name="modelo" label="Modelo (opcional)">
                <Select allowClear placeholder="Seguro anual, renda adiantada, férias a pagar…" onChange={aplicarModelo} options={(def.data?.modelos ?? []).map((m) => ({ value: m.id, label: m.rotulo }))} />
              </Form.Item>
            </Col>
          )}
          <Col xs={24} md={8}>
            <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
              <Radio.Group optionType="button" disabled={bloq} options={[{ value: 'ACRESCIMO', label: 'Acréscimo' }, { value: 'DIFERIMENTO', label: 'Diferimento' }]} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="natureza" label="Natureza" rules={[{ required: true }]}>
              <Radio.Group optionType="button" disabled={bloq} options={[{ value: 'CUSTO', label: 'Gasto' }, { value: 'PROVEITO', label: 'Rendimento' }]} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="valor" label="Valor (Kz, sem IVA)" rules={[{ required: true, message: 'Indique o valor.' }]}>
              <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={bloq} />
            </Form.Item>
          </Col>
          <Col xs={24}>
            <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Indique a descrição.' }]}><Input maxLength={1000} disabled={bloq} /></Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="conta_resultado" label={`Conta de resultados (${prefixoResultado})`} rules={[{ required: true, message: 'Indique a conta.' }]}>
              <SeletorConta prefixo={prefixoResultado} disabled={bloq} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="conta_balanco" label="Conta de balanço (37)" rules={[{ required: true, message: 'Indique a conta 37.' }]}>
              <SeletorConta prefixo="37" disabled={bloq} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}><Form.Item name="data_inicio" label="Início" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabled={bloq} /></Form.Item></Col>
          <Col xs={12} md={6}>
            <Form.Item name="data_fim" label="Fim" dependencies={['data_inicio']} rules={[{ required: true }, ({ getFieldValue }) => ({
              validator: (_, v) => (!v || !getFieldValue('data_inicio') || !v.isBefore(getFieldValue('data_inicio'), 'day') ? Promise.resolve() : Promise.reject(new Error('Fim anterior ao início.'))),
            })]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabled={bloq} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="reparticao" label="Repartição">
              <Radio.Group disabled={bloq} options={[{ value: 'MESES', label: 'Por meses (cada mês vale 1)' }, { value: 'DIAS', label: 'Por dias' }]} />
            </Form.Item>
          </Col>
          {tipo === 'DIFERIMENTO' ? (
            <>
              <Col xs={12} md={6}><Form.Item name="data_documento" label="Data do documento" rules={[{ required: true, message: 'Obrigatória num diferimento.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabled={bloq} /></Form.Item></Col>
              <Col xs={24} md={18}>
                <Form.Item name="documento_em_balanco" valuePropName="checked" label=" " tooltip="O documento já foi lançado directamente na conta 37 (não gera o lançamento inicial)">
                  <Checkbox disabled={bloq}>Documento já lançado na conta de balanço</Checkbox>
                </Form.Item>
              </Col>
            </>
          ) : (
            <Col xs={12} md={6}>
              <Form.Item name="data_limite" label="Data limite do documento" tooltip={`Vazio = fim do período + ${def.data?.prazo_documento_dias ?? 60} dias`}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          )}
          <Col xs={24} md={12}><Form.Item name="terceiro_id" label="Terceiro"><SeletorTerceiro style={{ width: '100%' }} disabled={bloq} rotuloInicial={item?.terceiro?.nome} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="projeto_id" label="Projecto"><SeletorProjecto allowClear disabled={bloq} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="unidade_negocio_id" label="Unidade de negócio"><SeletorUnidade style={{ width: '100%' }} disabled={bloq} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="centro_custo_id" label="Centro de custo"><SeletorAux tabela="centros-custo" placeholder="Centro de custo" style={{ width: '100%' }} disabled={bloq} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={4000} /></Form.Item></Col>
        </Row>
      </Form>
      {quotas && (
        <>
          <Typography.Title level={5}>Plano de repartição ({quotas.length} período(s))</Typography.Title>
          <Table size="small" rowKey="periodo" pagination={false} dataSource={quotas} scroll={{ y: 240 }}
            columns={[{ title: 'Período', dataIndex: 'periodo' }, { title: 'Peso', dataIndex: 'peso', align: 'right' }, { title: 'Quota (Kz)', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> }]}
            summary={() => (
              <Table.Summary.Row>
                <Table.Summary.Cell index={0} colSpan={2}><strong>Total</strong></Table.Summary.Cell>
                <Table.Summary.Cell index={2} align="right"><ValorKz valor={somaQuotas(quotas)} forte /></Table.Summary.Cell>
              </Table.Summary.Row>
            )} />
          {Number(diferencaPlano(form.getFieldValue('valor'), quotas)) !== 0 && <Alert type="warning" showIcon message="A soma das quotas difere do valor." />}
        </>
      )}
    </Modal>
  );
}
