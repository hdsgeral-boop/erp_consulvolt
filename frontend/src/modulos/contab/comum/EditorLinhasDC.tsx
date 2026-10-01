import { Button, Col, Form, Input, InputNumber, Row, Select, Space, Typography } from 'antd';
import { CopyOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { SeletorAux, SeletorConta, SeletorTerceiro, SeletorUnidade } from './Seletores';

export interface LinhaEditor {
  codigo_conta?: string;
  tipo_dc?: 'D' | 'C';
  valor?: number;
  descricao?: string;
  terceiro_id?: number;
  numero_documento?: string;
  centro_custo_id?: number;
  unidade_negocio_id?: number;
  nota_demonstracao_id?: number;
  nota_fluxo_caixa_id?: number;
  /** Só para mostrar o terceiro pré-preenchido (não se envia). */
  _terceiro?: string;
}

export interface CamposEditor {
  terceiro?: boolean;
  numeroDocumento?: boolean;
  centroCusto?: boolean;
  unidade?: boolean;
  notas?: boolean;
}

/**
 * Editor de linhas a débito/crédito (Form.List «linhas»). Usa-se dentro de um <Form>; o equilíbrio e os totais
 * mostram-se fora (IndicadorEquilibrio), porque a regra muda: lançamento manual exige D = C, documento de tesouraria não.
 */
export function EditorLinhasDC({ nome = 'linhas', campos = {}, minimo = 1, prefixosConta }: { nome?: string; campos?: CamposEditor; minimo?: number; prefixosConta?: string[] }) {
  const forma = Form.useFormInstance();
  return (
    <Form.List
      name={nome}
      rules={[{ validator: async (_, v: unknown[]) => (v && v.length >= minimo ? undefined : Promise.reject(new Error(`Acrescente pelo menos ${minimo} linha(s).`))) }]}
    >
      {(linhas, { add, remove }, { errors }) => (
        <>
          <Row gutter={8} style={{ marginBottom: 4 }} className="cabecalho-linhas">
            <Col xs={0} md={6}><Typography.Text type="secondary">Conta</Typography.Text></Col>
            <Col xs={0} md={2}><Typography.Text type="secondary">D/C</Typography.Text></Col>
            <Col xs={0} md={4}><Typography.Text type="secondary">Valor (Kz)</Typography.Text></Col>
            <Col xs={0} md={10}><Typography.Text type="secondary">Descrição e analítica</Typography.Text></Col>
          </Row>
          {linhas.map(({ key, name }) => (
            <div key={key} style={{ borderBottom: '1px dashed #f0f0f0', paddingTop: 8, marginBottom: 8 }}>
              <Row gutter={8} align="top">
                <Col xs={24} md={6}>
                  <Form.Item name={[name, 'codigo_conta']} rules={[{ required: true, message: 'Conta' }]}>
                    <SeletorConta style={{ width: '100%' }} prefixos={prefixosConta} />
                  </Form.Item>
                </Col>
                <Col xs={8} md={2}>
                  <Form.Item name={[name, 'tipo_dc']} rules={[{ required: true, message: 'D/C' }]}>
                    <Select options={[{ value: 'D', label: 'D' }, { value: 'C', label: 'C' }]} aria-label="Débito ou crédito" />
                  </Form.Item>
                </Col>
                <Col xs={16} md={4}>
                  <Form.Item name={[name, 'valor']} rules={[{ required: true, message: 'Valor' }]}>
                    <InputNumber min={0.01} precision={2} style={{ width: '100%' }} placeholder="0,00" decimalSeparator="," />
                  </Form.Item>
                </Col>
                <Col xs={24} md={10}>
                  <Form.Item name={[name, 'descricao']}>
                    <Input placeholder="Descrição da linha (opcional)" maxLength={1000} />
                  </Form.Item>
                </Col>
                <Col xs={24} md={2}>
                  <Space>
                    <Button
                      type="text"
                      icon={<CopyOutlined />}
                      aria-label="Duplicar linha"
                      title="Duplicar linha"
                      onClick={() => {
                        const l = forma.getFieldValue([nome, name]) as LinhaEditor;
                        add({ ...l, tipo_dc: l?.tipo_dc === 'D' ? 'C' : 'D' }, name + 1);
                      }}
                    />
                    <Button danger type="text" icon={<DeleteOutlined />} aria-label="Remover linha" title="Remover linha" onClick={() => remove(name)} />
                  </Space>
                </Col>
              </Row>
              {(campos.terceiro || campos.numeroDocumento || campos.centroCusto || campos.unidade || campos.notas) && (
                <Row gutter={8}>
                  {campos.terceiro && (
                    <Col xs={24} md={6}>
                      <Form.Item name={[name, 'terceiro_id']}>
                        <SeletorTerceiro style={{ width: '100%' }} rotuloInicial={forma.getFieldValue([nome, name, '_terceiro'])} />
                      </Form.Item>
                    </Col>
                  )}
                  {campos.numeroDocumento && (
                    <Col xs={24} md={4}>
                      <Form.Item name={[name, 'numero_documento']}>
                        <Input placeholder="N.º do documento liquidado" maxLength={100} />
                      </Form.Item>
                    </Col>
                  )}
                  {campos.centroCusto && (
                    <Col xs={12} md={4}>
                      <Form.Item name={[name, 'centro_custo_id']}>
                        <SeletorAux tabela="centros-custo" placeholder="Centro de custo" style={{ width: '100%' }} />
                      </Form.Item>
                    </Col>
                  )}
                  {campos.unidade && (
                    <Col xs={12} md={4}>
                      <Form.Item name={[name, 'unidade_negocio_id']}>
                        <SeletorUnidade style={{ width: '100%' }} />
                      </Form.Item>
                    </Col>
                  )}
                  {campos.notas && (
                    <>
                      <Col xs={12} md={3}>
                        <Form.Item name={[name, 'nota_demonstracao_id']}>
                          <SeletorAux tabela="notas-demonstracao" placeholder="Nota DEMO" style={{ width: '100%' }} />
                        </Form.Item>
                      </Col>
                      <Col xs={12} md={3}>
                        <Form.Item name={[name, 'nota_fluxo_caixa_id']}>
                          <SeletorAux tabela="notas-fluxo-caixa" placeholder="Nota fluxo" style={{ width: '100%' }} />
                        </Form.Item>
                      </Col>
                    </>
                  )}
                </Row>
              )}
            </div>
          ))}
          <Form.ErrorList errors={errors} />
          <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ tipo_dc: 'D' })}>
            Acrescentar linha
          </Button>
        </>
      )}
    </Form.List>
  );
}

/** Linhas do editor → corpo do pedido (remove campos internos e vazios). */
export function linhasParaApi(linhas: LinhaEditor[] | undefined): Record<string, unknown>[] {
  return (linhas ?? []).map(({ _terceiro, ...l }) => {
    void _terceiro;
    return Object.fromEntries(Object.entries(l).filter(([, v]) => v !== undefined && v !== null && v !== ''));
  });
}
