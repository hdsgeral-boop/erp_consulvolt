import { Button, Col, Flex, Form, Input, InputNumber, Row, Space, Typography, message, theme, type FormInstance } from 'antd';
import { type ClipboardEvent, useMemo } from 'react';
import { useEcra } from '@/componentes/responsivo';
import { CamposLinhaUNCC } from '@/componentes/reparticao';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { ColarExcelBotao } from '@/componentes/formularios/ColarExcelBotao';
import { colagemParaLinhas, pareceColagemDeGrade, posicaoColagem, type ResultadoColagemGrade } from '@/componentes/formularios/colagemGrade';
import { normTxt } from '@/componentes/formularios/conversoes';
import type { CampoFormulario } from '@/componentes/formularios/tipos';
import { rotuloProduto, useMapaProdutos, type ProdutoCatalogo } from './referencias';
import { SeletorProduto } from './Seletores';

/** Produto do catálogo pelo código ou nome colado (correspondência exacta, sem acentos/maiúsculas); undefined = não reconhecido. */
function produtoPorTexto(mapa: Map<number, ProdutoCatalogo>, texto: string): number | undefined {
  const alvo = normTxt(texto);
  if (!alvo) return undefined;
  const produtos = Array.from(mapa.values());
  return (
    produtos.find((p) => p.codigo && normTxt(p.codigo) === alvo)?.id ??
    produtos.find((p) => normTxt(p.nome) === alvo)?.id ??
    produtos.find((p) => normTxt(rotuloProduto(p)) === alvo)?.id
  );
}

export interface LinhaProdutoForm {
  produto_id?: number;
  quantidade?: number;
  preco_unitario?: number;
  taxa_imposto?: number;
  descricao?: string;
  /** Fase C (linha_un_cc.js): unidade/centro de custo da linha (vazio = cabeçalho) */
  unidade_negocio_id?: number;
  centro_custo_id?: number;
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
  unidadeLinha = false,
}: {
  form: FormInstance;
  nome?: string;
  preco?: boolean;
  precoObrigatorio?: boolean;
  iva?: boolean;
  descricao?: boolean;
  apenasStock?: boolean;
  rotuloPreco?: string;
  /** Mostra a unidade de negócio e o centro de custo em cada linha (pedido e factura directa). */
  unidadeLinha?: boolean;
}) {
  const { token } = theme.useToken();
  const mapa = useMapaProdutos();
  // em telemóvel cada linha fica num cartão próprio: produto e descrição na largura toda, quantidade/preço/IVA lado a lado
  const { telemovel } = useEcra();
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

  // Colagem do Excel: produto (pelo código ou nome exacto no catálogo), descrição, quantidade, preço e IVA — pela
  // mesma ordem das colunas visíveis. Unidade de negócio/centro de custo (unidadeLinha) ficam de fora: pedem escolher
  // um registo da lista, sem uma correspondência local fiável de texto → id.
  const camposColaveis = useMemo<CampoFormulario[]>(() => {
    const campos: CampoFormulario[] = [{ nome: 'produto_id', rotulo: 'Produto', tipo: 'personalizado', converter: (t) => produtoPorTexto(mapa, t) }];
    if (descricao) campos.push({ nome: 'descricao', rotulo: 'Descrição', maxLength: 1000 });
    campos.push({ nome: 'quantidade', rotulo: 'Qtd.', tipo: 'numero', min: 0.001 });
    if (preco) campos.push({ nome: 'preco_unitario', rotulo: rotuloPreco, tipo: 'numero', min: 0, precisao: 2 });
    if (iva) campos.push({ nome: 'taxa_imposto', rotulo: 'IVA %', tipo: 'numero', min: 0, max: 100 });
    return campos;
  }, [mapa, descricao, preco, iva, rotuloPreco]);
  const indiceColagem = Object.fromEntries(camposColaveis.map((c, i) => [c.nome, i]));

  const avisoColagem = (r: ResultadoColagemGrade, n: number) => {
    if (!n) return;
    if (r.naoReconhecidos) {
      const det = Object.entries(r.exemplos).map(([k, s]) => `${k}: ${s.join(', ')}`).join('; ');
      void message.warning(`${n} linha(s) acrescentada(s)${r.comCabecalho ? ' (colunas pelo cabeçalho)' : ''}. ${r.naoReconhecidos} valor(es) não reconhecido(s) — corrija-os antes de gravar: ${det}`);
    } else {
      void message.success(`${n} linha(s) acrescentada(s)${r.comCabecalho ? ' (colunas reconhecidas pelo cabeçalho)' : ''}.`);
    }
  };

  // Aplica as linhas coladas a partir de `aPartirDoIndice` (colagem numa célula) ou no fim (botão «Colar do Excel»).
  const aplicarColagem = (linhasColadas: Record<string, unknown>[], aPartirDoIndice?: number) => {
    const actuais = [...((form.getFieldValue(nome) as LinhaProdutoForm[] | undefined) ?? [])];
    const inicio = aPartirDoIndice ?? actuais.length;
    linhasColadas.forEach((valores, i) => {
      const idx = inicio + i;
      if (idx < actuais.length) actuais[idx] = { ...actuais[idx], ...valores };
      else actuais.push({ quantidade: 1, ...valores });
    });
    form.setFieldValue(nome, actuais);
  };

  const colarBloco = (texto: string) => {
    const r = colagemParaLinhas(texto, camposColaveis);
    aplicarColagem(r.linhas);
    avisoColagem(r, r.linhas.length);
  };

  // Colagem directa numa célula (Ctrl+V): preenche a partir dessa linha/coluna, criando linhas se for preciso.
  const aoColarCelula = (e: ClipboardEvent<HTMLDivElement>) => {
    const texto = e.clipboardData?.getData('text') ?? '';
    if (!pareceColagemDeGrade(texto)) return;
    const pos = posicaoColagem(e.target);
    if (!pos) return;
    e.preventDefault();
    const r = colagemParaLinhas(texto, camposColaveis, pos.coluna);
    aplicarColagem(r.linhas, pos.linha);
    avisoColagem(r, r.linhas.length);
  };

  return (
    <Form.List name={nome} rules={[{ validator: async (_, v) => (v && v.length ? undefined : Promise.reject(new Error('Acrescente pelo menos uma linha.'))) }]}>
      {(campos, { add, remove }, { errors }) => (
        <>
          <div onPaste={aoColarCelula}>
            {campos.map(({ key, name }, n) => (
              <Row
                key={key}
                gutter={8}
                align="top"
                data-colar-linha={name}
                style={telemovel ? { border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4, padding: '8px 4px 0', margin: '0 0 12px' } : undefined}
              >
                {telemovel && (
                  <Col span={24}>
                    <Flex justify="space-between" align="center" style={{ marginBottom: 4 }}>
                      <Typography.Text type="secondary">Linha {n + 1}</Typography.Text>
                      <Button danger type="text" size="small" icon={<DeleteOutlined />} onClick={() => remove(name)} aria-label="Remover linha" />
                    </Flex>
                  </Col>
                )}
                <Col xs={24} md={largProduto} data-colar-coluna={indiceColagem.produto_id}>
                  <Form.Item name={[name, 'produto_id']} rules={[{ required: true, message: 'Escolha o produto' }]}>
                    <SeletorProduto apenasStock={apenasStock} onChange={(id: number) => escolher(name, id)} />
                  </Form.Item>
                </Col>
                {descricao && (
                  <Col xs={24} md={6} data-colar-coluna={indiceColagem.descricao}>
                    <Form.Item name={[name, 'descricao']}>
                      <Input placeholder="Descrição (opcional)" maxLength={1000} />
                    </Form.Item>
                  </Col>
                )}
                <Col xs={preco || iva ? 12 : 24} md={3} data-colar-coluna={indiceColagem.quantidade}>
                  <Form.Item name={[name, 'quantidade']} rules={[{ required: true, message: 'Qtd.' }]}>
                    <InputNumber min={0.001} step={1} placeholder="Qtd." style={{ width: '100%' }} />
                  </Form.Item>
                </Col>
                {preco && (
                  <Col xs={12} md={4} data-colar-coluna={indiceColagem.preco_unitario}>
                    <Form.Item name={[name, 'preco_unitario']} rules={precoObrigatorio ? [{ required: true, message: 'Preço' }] : []}>
                      <InputNumber min={0} precision={2} placeholder={rotuloPreco} style={{ width: '100%' }} />
                    </Form.Item>
                  </Col>
                )}
                {iva && (
                  <Col xs={12} md={2} data-colar-coluna={indiceColagem.taxa_imposto}>
                    <Form.Item name={[name, 'taxa_imposto']}>
                      <InputNumber min={0} max={100} placeholder="IVA %" style={{ width: '100%' }} />
                    </Form.Item>
                  </Col>
                )}
                {!telemovel && (
                  <Col xs={2} md={1}>
                    <Button danger type="text" icon={<DeleteOutlined />} onClick={() => remove(name)} aria-label="Remover linha" />
                  </Col>
                )}
                {unidadeLinha && (
                  <Col xs={24}>
                    <CamposLinhaUNCC nome={name} />
                  </Col>
                )}
              </Row>
            ))}
          </div>
          <Form.ErrorList errors={errors} />
          <Space size={8} wrap>
            <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ quantidade: 1 })}>
              Acrescentar linha
            </Button>
            <ColarExcelBotao colunas={camposColaveis.map((c) => c.rotulo)} aoColar={colarBloco} />
          </Space>
        </>
      )}
    </Form.List>
  );
}
