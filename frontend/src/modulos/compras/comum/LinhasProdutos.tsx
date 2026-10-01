import { Button, Col, Form, Input, InputNumber, Row, type FormInstance } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useMapaProdutos } from './referencias';
import { SeletorProduto } from './Seletores';

export interface LinhaProdutoForm {
  produto_id?: number;
  quantidade?: number;
  preco_unitario?: number;
  taxa_imposto?: number;
  descricao?: string;
}

/**
 * Linhas de produtos de um formulário (Form.List `nome`). As colunas de preço, IVA e descrição são opcionais;
 * ao escolher o produto, o preço e o IVA são sugeridos pelo catálogo (podem ser alterados).
 */
export function LinhasProdutos({
  form,
  nome = 'linhas',
  preco = false,
  precoObrigatorio = false,
  iva = false,
  descricao = false,
  apenasStock = false,
  rotuloPreco = 'Preço unit.',
}: {
  form: FormInstance;
  nome?: string;
  preco?: boolean;
  precoObrigatorio?: boolean;
  iva?: boolean;
  descricao?: boolean;
  apenasStock?: boolean;
  rotuloPreco?: string;
}) {
  const mapa = useMapaProdutos();
  const escolher = (indice: number, id: number) => {
    const p = mapa.get(id);
    const actuais = [...((form.getFieldValue(nome) as LinhaProdutoForm[]) ?? [])];
    actuais[indice] = {
      ...actuais[indice],
      produto_id: id,
      ...(preco && p?.preco_unitario != null ? { preco_unitario: Number(p.preco_unitario) } : {}),
      ...(iva && p?.taxa_imposto != null ? { taxa_imposto: Number(p.taxa_imposto) } : {}),
    };
    form.setFieldValue(nome, actuais);
  };
  const largProduto = 24 - 3 - 1 - (preco ? 4 : 0) - (iva ? 2 : 0) - (descricao ? 6 : 0);

  return (
    <Form.List name={nome} rules={[{ validator: async (_, v) => (v && v.length ? undefined : Promise.reject(new Error('Acrescente pelo menos uma linha.'))) }]}>
      {(campos, { add, remove }, { errors }) => (
        <>
          {campos.map(({ key, name }) => (
            <Row key={key} gutter={8} align="top">
              <Col xs={24} md={largProduto}>
                <Form.Item name={[name, 'produto_id']} rules={[{ required: true, message: 'Escolha o produto' }]}>
                  <SeletorProduto apenasStock={apenasStock} onChange={(id: number) => escolher(name, id)} />
                </Form.Item>
              </Col>
              {descricao && (
                <Col xs={24} md={6}>
                  <Form.Item name={[name, 'descricao']}>
                    <Input placeholder="Descrição (opcional)" maxLength={1000} />
                  </Form.Item>
                </Col>
              )}
              <Col xs={8} md={3}>
                <Form.Item name={[name, 'quantidade']} rules={[{ required: true, message: 'Qtd.' }]}>
                  <InputNumber min={0.001} step={1} placeholder="Qtd." style={{ width: '100%' }} />
                </Form.Item>
              </Col>
              {preco && (
                <Col xs={10} md={4}>
                  <Form.Item name={[name, 'preco_unitario']} rules={precoObrigatorio ? [{ required: true, message: 'Preço' }] : []}>
                    <InputNumber min={0} precision={2} placeholder={rotuloPreco} style={{ width: '100%' }} />
                  </Form.Item>
                </Col>
              )}
              {iva && (
                <Col xs={4} md={2}>
                  <Form.Item name={[name, 'taxa_imposto']}>
                    <InputNumber min={0} max={100} placeholder="IVA %" style={{ width: '100%' }} />
                  </Form.Item>
                </Col>
              )}
              <Col xs={2} md={1}>
                <Button danger type="text" icon={<DeleteOutlined />} onClick={() => remove(name)} aria-label="Remover linha" />
              </Col>
            </Row>
          ))}
          <Form.ErrorList errors={errors} />
          <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ quantidade: 1 })}>
            Acrescentar linha
          </Button>
        </>
      )}
    </Form.List>
  );
}
