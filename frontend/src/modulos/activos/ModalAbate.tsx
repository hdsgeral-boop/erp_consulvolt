import { Alert, Button, Checkbox, Col, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Row, Select, Table, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { enviar } from '@/api/cliente';
import { SeletorTerceiro } from '@/modulos/contab/comum/Seletores';
import { IndicadorEquilibrio, ValorKz } from '@/modulos/contab/comum/Componentes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi } from '@/utilitarios/formatacao';
import { SeletorActivo } from './comum/componentes';
import { resultadoAbate } from './comum/regras';
import type { Activo, SimulacaoAbate } from './comum/tipos';
import { COLUNAS_DESCRICOES, larguraModal } from '@/componentes/responsivo';

interface Valores {
  ativo_imobilizado_id: number;
  tipo: 'SINISTRO' | 'VENDA' | 'FIM_VIDA';
  data: dayjs.Dayjs;
  valor?: number;
  terceiro_id?: number;
  conta_terceiro?: string;
  taxa_iva?: number;
  conta_iva?: string;
  conta_ativo?: string;
  descricao?: string;
  contabilizar: boolean;
}

const TIPOS = [
  { value: 'FIM_VIDA', label: 'Fim de vida (abate sem valor)' },
  { value: 'VENDA', label: 'Venda' },
  { value: 'SINISTRO', label: 'Sinistro (com ou sem indemnização)' },
];

/**
 * Abate ou venda de um activo: simula o lançamento (D 18 / C 11-12 / D terceiro, C 6 mais-valia ou D 7 menos-valia)
 * e regista. Sem valor não há terceiro; com venda/indemnização o servidor exige o terceiro e a respectiva conta.
 * Na venda liquida-se IVA (decisão 18): D terceiro = valor + IVA, C IVA liquidado (conta indicada ou a das contas de vendas).
 */
export function ModalAbate({ aberto, activo, aoFechar }: { aberto: boolean; activo?: Activo | null; aoFechar: () => void }) {
  const [form] = Form.useForm<Valores>();
  const [simulacao, setSimulacao] = useState<SimulacaoAbate | null>(null);
  const [aSimular, setASimular] = useState(false);
  const accao = useAccao<{ avisos?: string[] }>({
    invalidar: [['activos']],
    aoSucesso: (r) => {
      if (r?.avisos?.length) Modal.warning({ title: 'Abate registado com avisos', content: <ul>{r.avisos.map((a, i) => <li key={i}>{a}</li>)}</ul> });
      aoFechar();
    },
  });
  const tipo = Form.useWatch('tipo', form);
  const valor = Form.useWatch('valor', form);
  const taxaIva = Form.useWatch('taxa_iva', form);
  const comTerceiro = tipo === 'VENDA' || (valor ?? 0) > 0;

  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue({ ativo_imobilizado_id: activo?.id, tipo: 'FIM_VIDA', data: dayjs(), contabilizar: true, taxa_iva: 14 });
    setSimulacao(null);
  }, [aberto, activo, form]);

  const dados = (v: Valores) => ({
    ...v,
    data: dataApi(v.data),
    valor: v.valor ?? 0,
    terceiro_id: comTerceiro ? v.terceiro_id ?? null : null,
    conta_terceiro: comTerceiro ? v.conta_terceiro ?? null : null,
    conta_ativo: v.conta_ativo || null,
    // decisão 18: a venda liquida IVA sobre o valor (base tributável)
    taxa_iva: v.tipo === 'VENDA' ? v.taxa_iva ?? 0 : null,
    conta_iva: v.tipo === 'VENDA' ? v.conta_iva || null : null,
  });

  const simular = async () => {
    try {
      const v = await form.validateFields();
      setASimular(true);
      const r = await enviar<SimulacaoAbate>('post', '/ativos/abates/simulacao', dados(v));
      setSimulacao(r.dados);
    } catch (e) {
      if (e && typeof e === 'object' && 'errorFields' in e) return;
      notificarErro(e, 'A simulação falhou');
    } finally {
      setASimular(false);
    }
  };

  const estimativa = activo ? resultadoAbate(valor ?? 0, activo.valor_aquisicao, activo.amortizacao_acumulada) : null;

  return (
    <Modal
      title={activo ? `Abate / venda — ${activo.codigo} ${activo.descricao}` : 'Abate / venda de activo'}
      open={aberto}
      onCancel={aoFechar}
      width={larguraModal(900)}
      destroyOnHidden
      footer={[
        <Button key="c" onClick={aoFechar}>Cancelar</Button>,
        <Button key="s" loading={aSimular} onClick={simular}>Simular lançamento</Button>,
        <Button key="a" type="primary" danger loading={accao.isPending} onClick={() => form.submit()}>Registar abate</Button>,
      ]}
    >
      <Form
        form={form}
        layout="vertical"
        onValuesChange={() => setSimulacao(null)}
        onFinish={(v) => Modal.confirm({
          title: 'Confirmar o abate?',
          content: 'O activo passa a «Abatido»' + (v.contabilizar ? ' e é gerado o lançamento no diário AM.' : '.') + ' A anulação faz-se por estorno.',
          okText: 'Registar', cancelText: 'Cancelar', okButtonProps: { danger: true },
          onOk: () => accao.mutateAsync({ url: '/ativos/abates', dados: dados(v) }),
        })}
      >
        <Row gutter={12}>
          <Col xs={24} md={12}>
            <Form.Item name="ativo_imobilizado_id" label="Activo" rules={[{ required: true, message: 'Escolha o activo.' }]}>
              <SeletorActivo apenasActivos disabled={!!activo} rotuloInicial={activo ? `${activo.codigo} — ${activo.descricao}` : undefined} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
              <Select options={TIPOS} popupMatchSelectWidth={false} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="data" label="Data" rules={[{ required: true }]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="valor" label={tipo === 'VENDA' ? 'Valor da venda (Kz)' : 'Indemnização (Kz)'} rules={[{ required: tipo === 'VENDA', message: 'Indique o valor da venda.' }]}>
              <InputNumber min={0} precision={2} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          {comTerceiro && (
            <>
              <Col xs={24} md={10}>
                <Form.Item name="terceiro_id" label="Terceiro" rules={[{ required: true, message: 'Indique o terceiro.' }]}>
                  <SeletorTerceiro style={{ width: '100%' }} />
                </Form.Item>
              </Col>
              <Col xs={24} md={8}>
                <Form.Item name="conta_terceiro" label="Conta do terceiro" rules={[{ required: true, message: 'Indique a conta.' }]}>
                  <SeletorConta placeholder="Ex.: 31… / 37…" />
                </Form.Item>
              </Col>
            </>
          )}
          {tipo === 'VENDA' && (
            <>
              <Col xs={12} md={4}>
                <Form.Item name="taxa_iva" label="IVA (%)" rules={[{ required: true }]}>
                  <Select options={[0, 5, 7, 14].map((t) => ({ value: t, label: `${t} %` }))} />
                </Form.Item>
              </Col>
              <Col xs={12} md={8}>
                <Form.Item name="conta_iva" label="Conta do IVA liquidado" tooltip="Vazio = a conta «IVA liquidado» das contas de vendas">
                  <SeletorConta prefixo="34" placeholder="Automática" />
                </Form.Item>
              </Col>
              {(valor ?? 0) > 0 && (
                <Col xs={24} md={12} style={{ alignSelf: 'center' }}>
                  <Typography.Text type="secondary">IVA: {(Math.round((valor ?? 0) * (taxaIva ?? 0)) / 100).toLocaleString('pt-PT', { minimumFractionDigits: 2 })} Kz</Typography.Text>
                </Col>
              )}
            </>
          )}
          <Col xs={24} md={8}>
            <Form.Item name="conta_ativo" label="Conta do activo (11–14)" tooltip="Vazio = conta da categoria ou do lançamento de compra">
              <SeletorConta prefixo="1" placeholder="Automática" />
            </Form.Item>
          </Col>
          <Col xs={24} md={16}>
            <Form.Item name="descricao" label="Descrição">
              <Input maxLength={2000} />
            </Form.Item>
          </Col>
          <Col xs={24}>
            <Form.Item name="contabilizar" valuePropName="checked">
              <Checkbox>Contabilizar o abate (lançamento no diário AM)</Checkbox>
            </Form.Item>
          </Col>
        </Row>
      </Form>
      {estimativa && !simulacao && (
        <Typography.Text type="secondary">
          Estimativa: valor líquido {Number(estimativa.liquido).toLocaleString('pt-PT', { minimumFractionDigits: 2 })} Kz; resultado{' '}
          {estimativa.tipo === 'MAIS_VALIA' ? 'mais-valia' : estimativa.tipo === 'MENOS_VALIA' ? 'menos-valia' : 'nulo'}{' '}
          {Math.abs(Number(estimativa.resultado)).toLocaleString('pt-PT', { minimumFractionDigits: 2 })} Kz. Simule para ver o lançamento exacto.
        </Typography.Text>
      )}
      {simulacao && (
        <>
          <Descriptions size="small" column={COLUNAS_DESCRICOES} bordered style={{ marginBottom: 12 }}>
            <Descriptions.Item label="Aquisição"><ValorKz valor={simulacao.valor_aquisicao} /></Descriptions.Item>
            <Descriptions.Item label="Amort. acumulada"><ValorKz valor={simulacao.amortizacao_acumulada} /></Descriptions.Item>
            <Descriptions.Item label="Valor líquido"><ValorKz valor={simulacao.valor_liquido} /></Descriptions.Item>
            {Number(simulacao.valor_iva ?? 0) > 0 && <Descriptions.Item label="IVA liquidado"><ValorKz valor={simulacao.valor_iva} /></Descriptions.Item>}
            <Descriptions.Item label="Resultado"><ValorKz valor={simulacao.resultado} forte /></Descriptions.Item>
          </Descriptions>
          <Table
            size="small"
            rowKey={(_, i) => String(i)}
            pagination={false}
            dataSource={simulacao.linhas}
            columns={[
              { title: 'Conta', dataIndex: 'codigo_conta' },
              { title: 'Descrição', dataIndex: 'descricao' },
              { title: 'Débito', key: 'd', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? <ValorKz valor={l.valor} /> : '') },
              { title: 'Crédito', key: 'c', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? <ValorKz valor={l.valor} /> : '') },
            ]}
          />
          <div style={{ marginTop: 12 }}><IndicadorEquilibrio linhas={simulacao.linhas} /></div>
        </>
      )}
      {!activo && <Alert style={{ marginTop: 12 }} type="info" showIcon message="Só aparecem activos no estado «Activo». Integre ou anule as amortizações em rascunho antes do abate." />}
    </Modal>
  );
}
