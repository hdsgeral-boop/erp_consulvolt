import { AutoComplete, Button, Card, Checkbox, Col, DatePicker, Divider, Flex, Form, Input, InputNumber, Row, Select, Space, Statistic, Typography, message } from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { enviar, obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useEcra } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarKz } from '@/utilitarios/formatacao';
import { MEIOS_PAGAMENTO, TIPOS_DOCUMENTO, TIPOS_EMITIVEIS, type DocumentoVenda, type ProdutoCatalogo, type Terceiro } from './api';

/** Espaço entre os totais estimados (menor em telemóvel, onde quebram linha). */
const pequenoGap = (telemovel: boolean) => (telemovel ? 16 : 32);

interface LinhaForm {
  produto_id?: number;
  quantidade?: number;
  preco_unitario?: number;
  descricao?: string;
}

/** Dados enviados pelo CRM ao converter uma oportunidade (GET /crm/oportunidades/{id}/conversao, ADR-054). */
interface ConversaoCrm {
  tipo_documento?: string;
  cliente_id?: number;
  data_emissao?: string;
  oportunidade_crm_id?: number;
  linhas?: { produto_id: number; quantidade: number | string; preco_unitario?: number | string | null; descricao?: string | null }[];
}

interface ValoresForm {
  tipo_documento: string;
  cliente_id?: number;
  data_emissao: Dayjs;
  linhas: LinhaForm[];
  venda_origem_id?: number;
  motivo_nota_credito?: string;
  devolucao_mercadoria?: boolean;
  conta_disponibilidade?: string;
  meio_pagamento?: string;
  referencia_pagamento?: string;
  dias_validade?: number;
  observacoes?: string;
}

/**
 * Emissão de documentos de venda (POST /vendas/documentos). Os totais mostrados são uma estimativa: o servidor
 * calcula as regras AGT (arredondamentos, isenções, câmbio) e é quem numera, sela e movimenta o stock.
 */
export function EmitirDocumento() {
  const navegar = useNavigate();
  const clienteConsulta = useQueryClient();
  const [form] = Form.useForm<ValoresForm>();
  const tipo = Form.useWatch('tipo_documento', form) ?? 'FT';
  const clienteId = Form.useWatch('cliente_id', form);
  const linhas = Form.useWatch('linhas', form) ?? [];
  const [pesquisaCliente, setPesquisaCliente] = useState('');
  // em telemóvel cada linha do documento fica num cartão próprio (campos empilhados, numerados)
  const { telemovel } = useEcra();
  const conversao = (useLocation().state as { conversaoCrm?: ConversaoCrm } | null)?.conversaoCrm;
  const valoresIniciais = useMemo<Partial<ValoresForm>>(() => {
    const base = { tipo_documento: 'FT', data_emissao: dayjs(), linhas: [{ quantidade: 1 }] as LinhaForm[], meio_pagamento: 'NUMERARIO' };
    if (!conversao) return base;
    return {
      ...base,
      tipo_documento: conversao.tipo_documento && (TIPOS_EMITIVEIS as readonly string[]).includes(conversao.tipo_documento) ? conversao.tipo_documento : base.tipo_documento,
      cliente_id: conversao.cliente_id,
      data_emissao: conversao.data_emissao ? dayjs(conversao.data_emissao) : base.data_emissao,
      linhas: conversao.linhas?.length
        ? conversao.linhas.map((l) => ({ produto_id: l.produto_id, quantidade: Number(l.quantidade), preco_unitario: l.preco_unitario == null ? undefined : Number(l.preco_unitario), descricao: l.descricao ?? undefined }))
        : base.linhas,
    };
  }, [conversao]);
  // o cliente vindo do CRM pode não estar na primeira página da pesquisa: carrega-se para o selector mostrar o nome
  const clienteInicial = useQuery({
    queryKey: ['terceiros', 'um', conversao?.cliente_id],
    queryFn: () => obter<Terceiro>(`/terceiros/${conversao?.cliente_id}`),
    enabled: !!conversao?.cliente_id,
  });

  const produtos = useQuery({ queryKey: ['logistica', 'catalogo'], queryFn: () => obter<ProdutoCatalogo[]>('/logistica/produtos/catalogo'), staleTime: 300_000 });
  const clientes = useQuery({
    queryKey: ['terceiros', 'clientes', pesquisaCliente],
    queryFn: () => obterPagina<Terceiro>('/terceiros', { papel: 'CLIENTE', pesquisa: pesquisaCliente, por_pagina: 30 }),
  });
  const contas = useQuery({
    queryKey: ['plano', 'disponibilidades'],
    queryFn: () => obter<{ codigo: string; descricao: string }[]>('/contabilidade/plano-contas', { prefixo: '4', tipo: 'M' }),
    enabled: tipo === 'FR',
    retry: false,
  });
  const origens = useQuery({
    queryKey: ['vendas', 'origens-nc', clienteId],
    queryFn: async () => {
      const [ft, fr] = await Promise.all(['FT', 'FR'].map((t) => obterPagina<DocumentoVenda>('/vendas/documentos', { cliente_id: clienteId, tipo_documento: t, por_pagina: 100 })));
      return [...ft.itens, ...fr.itens].filter((d) => d.estado !== 'ANULADO');
    },
    enabled: tipo === 'NC' && !!clienteId,
  });

  const porId = useMemo(() => new Map((produtos.data ?? []).map((p) => [p.id, p])), [produtos.data]);
  const estimativa = useMemo(
    () =>
      linhas.reduce(
        (t, l) => {
          const p = l?.produto_id ? porId.get(l.produto_id) : undefined;
          const base = (l?.quantidade ?? 0) * (l?.preco_unitario ?? 0);
          const iva = (base * Number(p?.taxa_imposto ?? 0)) / 100;
          return { liquido: t.liquido + base, imposto: t.imposto + iva };
        },
        { liquido: 0, imposto: 0 },
      ),
    [linhas, porId],
  );

  const emitir = useMutation({
    mutationFn: (v: ValoresForm) =>
      enviar<DocumentoVenda>('post', '/vendas/documentos', {
        ...v,
        data_emissao: dataApi(v.data_emissao),
        linhas: v.linhas.map((l) => ({ produto_id: l.produto_id, quantidade: l.quantidade, preco_unitario: l.preco_unitario, descricao: l.descricao || undefined })),
        oportunidade_crm_id: conversao?.oportunidade_crm_id,   // o servidor liga o documento à oportunidade (FT/FR/NE ganham-na)
      }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      void clienteConsulta.invalidateQueries({ queryKey: ['vendas'] });
      navegar(`../${dados.id}`);
    },
    onError: (e) => notificarErro(e, 'Não foi possível emitir o documento'),
  });

  const escolherProduto = (indice: number, id: number) => {
    const p = porId.get(id);
    const actuais = form.getFieldValue('linhas') as LinhaForm[];
    actuais[indice] = { ...actuais[indice], produto_id: id, preco_unitario: p?.preco_unitario ? Number(p.preco_unitario) : 0, descricao: p?.nome };
    form.setFieldsValue({ linhas: [...actuais] });
  };

  return (
    <>
      <CabecalhoPagina titulo="Novo documento de venda" subtitulo={conversao?.oportunidade_crm_id ? `A partir da oportunidade #${conversao.oportunidade_crm_id} do CRM` : undefined} accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>} />
      <Form<ValoresForm>
        form={form}
        layout="vertical"
        initialValues={valoresIniciais}
        onFinish={(v) => emitir.mutate(v)}
      >
        <Card title="Documento" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={6}>
              <Form.Item name="tipo_documento" label="Tipo" rules={[{ required: true }]}>
                <Select options={TIPOS_EMITIVEIS.map((t) => ({ value: t, label: `${t} — ${TIPOS_DOCUMENTO[t]}` }))} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="cliente_id" label="Cliente" rules={[{ required: true, message: 'Escolha o cliente.' }]}>
                <Select
                  showSearch
                  filterOption={false}
                  onSearch={setPesquisaCliente}
                  loading={clientes.isFetching}
                  placeholder="Pesquisar por nome ou NIF"
                  options={[...(clienteInicial.data && !(clientes.data?.itens ?? []).some((c) => c.id === clienteInicial.data?.id) ? [clienteInicial.data] : []), ...(clientes.data?.itens ?? [])].map((c) => ({
                    value: c.id,
                    label: `${c.nome}${c.nif ? ` (NIF ${c.nif})` : ''}`,
                  }))}
                />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item name="data_emissao" label="Data" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          </Row>

          {tipo === 'FR' && (
            <Row gutter={16}>
              <Col xs={24} md={8}>
                <Form.Item name="conta_disponibilidade" label="Conta de caixa/banco" rules={[{ required: true, message: 'Indique a conta (45 caixa, 43 bancos).' }]}>
                  <AutoComplete
                    options={(contas.data ?? []).filter((c) => /^4[35]/.test(c.codigo)).map((c) => ({ value: c.codigo, label: `${c.codigo} — ${c.descricao}` }))}
                    placeholder="Ex.: 4511"
                    filterOption={(i, o) => String(o?.label ?? '').toLowerCase().includes(i.toLowerCase())}
                  />
                </Form.Item>
              </Col>
              <Col xs={24} md={8}>
                <Form.Item name="meio_pagamento" label="Meio de pagamento">
                  <Select options={MEIOS_PAGAMENTO} />
                </Form.Item>
              </Col>
              <Col xs={24} md={8}>
                <Form.Item name="referencia_pagamento" label="Referência do pagamento">
                  <Input maxLength={50} />
                </Form.Item>
              </Col>
            </Row>
          )}

          {tipo === 'NC' && (
            <Row gutter={16}>
              <Col xs={24} md={8}>
                <Form.Item name="venda_origem_id" label="Factura de origem" rules={[{ required: true, message: 'Escolha a factura que a nota de crédito corrige.' }]}>
                  <Select
                    loading={origens.isFetching}
                    disabled={!clienteId}
                    placeholder={clienteId ? 'Escolha a factura' : 'Escolha primeiro o cliente'}
                    options={(origens.data ?? []).map((d) => ({ value: d.id, label: `${d.numero_documento} — ${formatarKz(d.total_bruto)} Kz` }))}
                  />
                </Form.Item>
              </Col>
              <Col xs={24} md={12}>
                <Form.Item name="motivo_nota_credito" label="Motivo" rules={[{ required: true, message: 'Indique o motivo (obrigatório na AGT).' }]}>
                  <Input maxLength={200} />
                </Form.Item>
              </Col>
              <Col xs={24} md={4}>
                <Form.Item name="devolucao_mercadoria" valuePropName="checked" label=" ">
                  <Checkbox>Mercadoria devolvida</Checkbox>
                </Form.Item>
              </Col>
            </Row>
          )}

          {(tipo === 'OR' || tipo === 'PF') && (
            <Form.Item name="dias_validade" label="Validade (dias)" style={{ maxWidth: 200 }}>
              <InputNumber min={1} max={3650} style={{ width: '100%' }} />
            </Form.Item>
          )}
        </Card>

        <Card title="Linhas" style={{ marginBottom: 16 }}>
          <Form.List name="linhas" rules={[{ validator: async (_, v) => (v && v.length ? undefined : Promise.reject(new Error('Acrescente pelo menos uma linha.'))) }]}>
            {(campos, { add, remove }, { errors }) => (
              <>
                {campos.map(({ key, name }, n) => (
                  <Row
                    key={key}
                    gutter={8}
                    align="top"
                    style={telemovel ? { border: '1px solid rgba(5, 5, 5, 0.12)', borderRadius: 8, padding: '8px 4px 0', margin: '0 0 12px' } : undefined}
                  >
                    {telemovel && (
                      <Col span={24} style={{ marginBottom: 4 }}>
                        <Typography.Text type="secondary">Linha {n + 1}</Typography.Text>
                      </Col>
                    )}
                    <Col xs={24} md={9}>
                      <Form.Item name={[name, 'produto_id']} rules={[{ required: true, message: 'Produto' }]}>
                        <Select
                          showSearch
                          placeholder="Produto ou serviço"
                          loading={produtos.isLoading}
                          optionFilterProp="label"
                          onChange={(id: number) => escolherProduto(name, id)}
                          options={(produtos.data ?? []).map((p) => ({ value: p.id, label: `${p.codigo ? `${p.codigo} — ` : ''}${p.nome}` }))}
                        />
                      </Form.Item>
                    </Col>
                    <Col xs={24} md={7}>
                      <Form.Item name={[name, 'descricao']}>
                        <Input placeholder="Descrição" maxLength={1000} />
                      </Form.Item>
                    </Col>
                    <Col xs={9} md={3}>
                      <Form.Item name={[name, 'quantidade']} rules={[{ required: true, message: 'Qtd.' }]}>
                        <InputNumber min={0.001} step={1} placeholder="Qtd." style={{ width: '100%' }} />
                      </Form.Item>
                    </Col>
                    <Col xs={11} md={4}>
                      <Form.Item name={[name, 'preco_unitario']}>
                        <InputNumber min={0} precision={2} placeholder="Preço s/ IVA" style={{ width: '100%' }} />
                      </Form.Item>
                    </Col>
                    <Col xs={4} md={1}>
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
          <Divider />
          <Flex justify="end" gap={pequenoGap(telemovel)} wrap>
            <Statistic title="Líquido (estimativa)" value={formatarKz(estimativa.liquido)} />
            <Statistic title="IVA (estimativa)" value={formatarKz(estimativa.imposto)} />
            <Statistic title="Total (estimativa)" value={formatarKz(estimativa.liquido + estimativa.imposto)} />
          </Flex>
          <Typography.Text type="secondary">O valor final é calculado pelo servidor com as regras da facturação electrónica (AGT).</Typography.Text>
        </Card>

        <Card style={{ marginBottom: 16 }}>
          <Form.Item name="observacoes" label="Observações">
            <Input.TextArea rows={2} maxLength={4000} />
          </Form.Item>
        </Card>

        <Space wrap>
          <Button type="primary" htmlType="submit" loading={emitir.isPending}>
            Emitir {TIPOS_DOCUMENTO[tipo]?.toLowerCase()}
          </Button>
          <Button onClick={() => navegar('..')}>Cancelar</Button>
        </Space>
      </Form>
    </>
  );
}
