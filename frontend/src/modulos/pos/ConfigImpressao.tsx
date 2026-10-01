import { Alert, Button, Card, Col, Form, Input, InputNumber, Radio, Row, Skeleton, Space, Switch, Typography, message } from 'antd';
import { PrinterOutlined, SaveOutlined } from '@ant-design/icons';
import { useEffect } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { useDefinicoesPOS } from './comum/dados';
import { gravarPreferencias, htmlTalaoVenda, imprimirHtml, lerPreferencias, type PreferenciasImpressao } from './comum/impressao';
import type { DefinicoesPOS } from './comum/tipos';

/**
 * POS › Configuração (ecrã pos_config_print): definições de caixa do servidor (contas dos desvios, tolerância, diário —
 * GET/PUT /pos/definicoes) e preferências de impressão deste posto de trabalho (guardadas no navegador, como no legado).
 */
export default function ConfigImpressao() {
  return (
    <>
      <CabecalhoPagina titulo="Configuração do POS" subtitulo="Definições de caixa e impressão de talões" />
      <Row gutter={16}>
        <Col xs={24} xl={12}>
          <DefinicoesCaixa />
        </Col>
        <Col xs={24} xl={12}>
          <PreferenciasPosto />
        </Col>
      </Row>
    </>
  );
}

interface FormDefinicoes {
  conta_sobra?: string | null;
  conta_quebra?: string | null;
  conta_operador?: string | null;
  tolerancia_desvio?: number | null;
  codigo_diario?: string;
}

function DefinicoesCaixa() {
  const { pode } = useSessao();
  const definicoes = useDefinicoesPOS();
  const [form] = Form.useForm<FormDefinicoes>();
  const gerir = pode('pos_terminais_gerir');
  const gravar = useAccao<DefinicoesPOS>({ invalidar: [['pos', 'definicoes']], tituloErro: 'Não foi possível gravar as definições' });
  useEffect(() => {
    if (definicoes.data) form.setFieldsValue({ ...definicoes.data, tolerancia_desvio: Number(definicoes.data.tolerancia_desvio) });
  }, [definicoes.data, form]);

  return (
    <Card title="Definições de caixa" style={{ marginBottom: 16 }}>
      {definicoes.isError ? (
        <Alert type="warning" showIcon message="Não tem acesso às definições de caixa do POS." />
      ) : !definicoes.data ? (
        <Skeleton active />
      ) : (
        <Form
          form={form}
          layout="vertical"
          disabled={!gerir}
          onFinish={(v) =>
            gravar.mutate({
              metodo: 'put',
              url: '/pos/definicoes',
              dados: {
                conta_sobra: v.conta_sobra?.trim() || null,
                conta_quebra: v.conta_quebra?.trim() || null,
                conta_operador: v.conta_operador?.trim() || null,
                tolerancia_desvio: v.tolerancia_desvio ?? 0,
                codigo_diario: v.codigo_diario?.trim().toUpperCase() || undefined,
              },
            })
          }
        >
          <Form.Item name="conta_sobra" label="Conta de sobras de caixa (proveito, classe 6)">
            <SeletorConta prefixo="6" />
          </Form.Item>
          <Form.Item name="conta_quebra" label="Conta de quebras de caixa (custo, classe 7)">
            <SeletorConta prefixo="7" />
          </Form.Item>
          <Form.Item name="conta_operador" label="Conta de responsabilidade do operador (classe 3)">
            <SeletorConta prefixo="3" />
          </Form.Item>
          <Row gutter={16}>
            <Col span={12}>
              <Form.Item name="tolerancia_desvio" label="Tolerância de desvio" extra="Desvios até este valor ficam deliberados automaticamente.">
                <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} addonAfter="Kz" />
              </Form.Item>
            </Col>
            <Col span={12}>
              <Form.Item name="codigo_diario" label="Diário da integração">
                <Input maxLength={10} style={{ textTransform: 'uppercase' }} />
              </Form.Item>
            </Col>
          </Row>
          {gerir ? (
            <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={gravar.isPending}>
              Gravar definições
            </Button>
          ) : (
            <Typography.Text type="secondary">Só consulta: alterar exige a permissão de configurar terminais.</Typography.Text>
          )}
        </Form>
      )}
    </Card>
  );
}

function PreferenciasPosto() {
  const { pode, empresa } = useSessao();
  const [form] = Form.useForm<PreferenciasImpressao>();
  const podeGravar = pode('pos_print_config');
  useEffect(() => {
    form.setFieldsValue(lerPreferencias(empresa?.id));
  }, [empresa?.id, form]);

  const amostra = () => {
    const p = { ...lerPreferencias(empresa?.id), ...form.getFieldsValue() };
    imprimirHtml(
      htmlTalaoVenda(
        {
          id: 0,
          numero_documento: 'FR T01/2026/0 (exemplo)',
          data_emissao: new Date().toISOString(),
          total_liquido: '877.19',
          total_imposto: '122.81',
          total_bruto: '1000.00',
          desconto: '0.00',
          pos_troco: '0.00',
          pos_operador: 'operador',
          pos_pagamentos: [{ tipo: 'NUMERARIO', nome: 'Numerário', valor: '1000.00' }],
          itens_venda: [{ id: 1, produto_id: 0, descricao: 'Artigo de exemplo', quantidade: '1', preco_unitario: '1000.00', taxa_imposto: '14', total: '1000.00' }],
        },
        { empresa: empresa?.nome ?? '', nif: (empresa?.nif as string | null) ?? null, terminal: 'T01' },
        p,
      ),
    );
  };

  return (
    <Card title="Impressão neste posto de trabalho">
      <Typography.Paragraph type="secondary">
        As preferências ficam guardadas neste navegador (cada caixa tem a sua impressora), por empresa.
      </Typography.Paragraph>
      <Form
        form={form}
        layout="vertical"
        disabled={!podeGravar}
        onFinish={(v) => {
          gravarPreferencias(empresa?.id, { ...lerPreferencias(empresa?.id), ...v });
          void message.success('Preferências de impressão gravadas neste posto.');
        }}
      >
        <Form.Item name="formato" label="Formato">
          <Radio.Group>
            <Radio.Button value="TERMICO">Talão térmico</Radio.Button>
            <Radio.Button value="A4">Documento A4</Radio.Button>
          </Radio.Group>
        </Form.Item>
        <Form.Item name="largura" label="Largura do rolo térmico">
          <Radio.Group>
            <Radio value={80}>80 mm</Radio>
            <Radio value={58}>58 mm</Radio>
          </Radio.Group>
        </Form.Item>
        <Form.Item name="automatico" label="Imprimir automaticamente depois de cada venda" valuePropName="checked">
          <Switch />
        </Form.Item>
        <Form.Item name="consulta" label="Ao reimprimir">
          <Radio.Group>
            <Radio value="PREVISUALIZAR">Mostrar a pré-visualização</Radio>
            <Radio value="DIRECTO">Imprimir directamente</Radio>
          </Radio.Group>
        </Form.Item>
        <Form.Item name="rodape" label="Mensagem de rodapé">
          <Input maxLength={200} />
        </Form.Item>
        <Space>
          {podeGravar && (
            <Button type="primary" htmlType="submit" icon={<SaveOutlined />}>
              Gravar preferências
            </Button>
          )}
          <Button icon={<PrinterOutlined />} disabled={false} onClick={amostra}>
            Imprimir talão de teste
          </Button>
        </Space>
      </Form>
    </Card>
  );
}
