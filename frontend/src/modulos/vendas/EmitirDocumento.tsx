import { Alert, AutoComplete, Button, Card, Checkbox, Col, DatePicker, Flex, Form, Input, InputNumber, Row, Segmented, Select, Space, Statistic, Table, Tag, Tooltip, Typography, message } from 'antd';
import {
  ArrowLeftOutlined, CalendarOutlined, CheckOutlined, DeleteOutlined, FlagOutlined, PlusOutlined, ThunderboltOutlined,
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { enviar, obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useEcra } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { MEIOS_PAGAMENTO, TIPOS_DOCUMENTO, TIPOS_EMITIVEIS, type DocumentoVenda, type ProdutoCatalogo, type Terceiro } from './api';
import {
  DIAS_PRAZO, DIAS_VALIDADE, MODELOS_MARCOS, desvioCambio, erroPlano, estimarTotais, planoMarcos, planoPrazo, somaPercentagens, somarDias,
  valoresPrestacoes, type ModoPagamento, type Prestacao,
} from './condicoes';

/** Espaço entre os totais estimados (menor em telemóvel, onde quebram linha). */
const pequenoGap = (telemovel: boolean) => (telemovel ? 16 : 32);

interface LinhaForm {
  produto_id?: number;
  quantidade?: number;
  preco_unitario?: number;
  /** preço da ficha na moeda do documento — o preço só é enviado quando difere deste (decisão 8) */
  preco_ficha?: number;
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
  dias_validade?: number | 'data';
  valido_ate?: Dayjs | null;
  observacoes?: string;
  condicoes_pagamento?: string;
  codigo_moeda?: string;
  taxa_cambio?: number | null;
  unidade_negocio_id?: number;
  centro_custo_id?: number;
  projeto_id?: number;
}

interface Moeda { codigo: string; nome?: string | null }
interface Cambio { taxa: string; data: string; exata: boolean }
interface Tolerancia { tolerancia_pct: string; pode_exceder: boolean }

const num = (v: unknown): number => (v == null || v === '' ? 0 : Number(v));
const DOIS = (v: number) => Math.round(v * 100) / 100;

/**
 * Emissão de documentos de venda (POST /vendas/documentos), com a organização do formulário do legado
 * (showSaleForm / formulario_linhas.js): cabeçalho (cliente, tipo, UN, CC, projecto, data, moeda e câmbio), itens da
 * venda, observações e condições, validade e modalidade de pagamento (A-03: pronto, a prazo ou por marcos) e o quadro
 * de totais. Os totais são uma estimativa: o servidor calcula as regras AGT, numera, sela e movimenta o stock.
 * O preço da ficha só é enviado quando alterado (decisão 8 — exige a permissão «Alterar o preço de venda»).
 */
export function EmitirDocumento() {
  const navegar = useNavigate();
  const clienteConsulta = useQueryClient();
  const { pode } = useSessao();
  const podeAlterarPreco = pode('vendas_alterar_preco');
  const [form] = Form.useForm<ValoresForm>();
  const [procura] = useSearchParams();
  const tipo = Form.useWatch('tipo_documento', form) ?? 'FT';
  const clienteId = Form.useWatch('cliente_id', form);
  const linhas = Form.useWatch('linhas', form) ?? [];
  const moeda = Form.useWatch('codigo_moeda', form) ?? 'AOA';
  const taxaManual = Form.useWatch('taxa_cambio', form);
  const dataEmissao = Form.useWatch('data_emissao', form);
  const origemId = Form.useWatch('venda_origem_id', form);
  const [pesquisaCliente, setPesquisaCliente] = useState('');
  const [modo, setModo] = useState<ModoPagamento>('PRONTO');
  const [plano, setPlano] = useState<Prestacao[]>([]);
  const { telemovel } = useEcra();
  const estado = useLocation().state as { conversaoCrm?: ConversaoCrm; copia?: DocumentoVenda } | null;
  const conversao = estado?.conversaoCrm;
  const copia = estado?.copia;
  const dataIso = dataApi(dataEmissao) ?? dayjs().format('YYYY-MM-DD');
  const estrangeira = moeda !== 'AOA' && tipo !== 'NC';
  const comModalidade = !['FR', 'NC'].includes(tipo);

  const valoresIniciais = useMemo<Partial<ValoresForm>>(() => {
    const tipoPedido = procura.get('tipo');
    const base: Partial<ValoresForm> = {
      tipo_documento: tipoPedido && (TIPOS_EMITIVEIS as readonly string[]).includes(tipoPedido) ? tipoPedido : 'FT',
      data_emissao: dayjs(), linhas: [{ quantidade: 1 }], meio_pagamento: 'NUMERARIO', codigo_moeda: 'AOA', dias_validade: 30,
    };
    if (copia) {   // M-06: copiar documento (copySale do legado) — nova data e novo número; preços da cópia
      return {
        ...base,
        tipo_documento: (TIPOS_EMITIVEIS as readonly string[]).includes(copia.tipo_documento) && copia.tipo_documento !== 'NC' ? copia.tipo_documento : 'FT',
        cliente_id: copia.cliente_id, codigo_moeda: copia.codigo_moeda || 'AOA', observacoes: copia.observacoes ?? undefined,
        condicoes_pagamento: (copia.condicoes_pagamento as string | null) ?? undefined,
        unidade_negocio_id: (copia.unidade_negocio_id as number | null) ?? undefined, centro_custo_id: (copia.centro_custo_id as number | null) ?? undefined,
        projeto_id: (copia.projeto_id as number | null) ?? undefined,
        linhas: (copia.linhas ?? []).map((l) => ({
          produto_id: l.produto_id, quantidade: Number(l.quantidade), descricao: l.descricao ?? undefined,
          preco_unitario: Number(copia.codigo_moeda && copia.codigo_moeda !== 'AOA' && l.preco_unitario_moeda ? l.preco_unitario_moeda : l.preco_unitario),
        })),
      };
    }
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
  }, [conversao, copia, procura]);
  const clienteInicialId = conversao?.cliente_id ?? copia?.cliente_id;
  // o cliente vindo do CRM/da cópia pode não estar na primeira página da pesquisa: carrega-se para o selector mostrar o nome
  const clienteInicial = useQuery({
    queryKey: ['terceiros', 'um', clienteInicialId],
    queryFn: () => obter<Terceiro>(`/terceiros/${clienteInicialId}`),
    enabled: !!clienteInicialId,
  });

  const produtos = useQuery({ queryKey: ['logistica', 'catalogo'], queryFn: () => obter<ProdutoCatalogo[]>('/logistica/produtos/catalogo'), staleTime: 300_000 });
  const clientes = useQuery({
    queryKey: ['terceiros', 'clientes', pesquisaCliente],
    queryFn: () => obterPagina<Terceiro>('/terceiros', { papel: 'CLIENTE', pesquisa: pesquisaCliente, por_pagina: 30 }),
  });
  const moedas = useQuery({ queryKey: ['sistema', 'moedas'], queryFn: () => obter<Moeda[]>('/sistema/moedas'), staleTime: 600_000, retry: false });
  const cambio = useQuery({
    queryKey: ['sistema', 'cambio', moeda, dataIso],
    queryFn: () => obter<Cambio | null>('/sistema/cambios/consultar', { codigo_moeda: moeda, data: dataIso }),
    enabled: estrangeira,
  });
  const tolerancia = useQuery({ queryKey: ['sistema', 'cambios-manuais', 'tolerancia'], queryFn: () => obter<Tolerancia>('/sistema/cambios-manuais/tolerancia'), enabled: estrangeira, retry: false });
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
  const todosClientes = useMemo(
    () => [...(clienteInicial.data && !(clientes.data?.itens ?? []).some((c) => c.id === clienteInicial.data?.id) ? [clienteInicial.data] : []), ...(clientes.data?.itens ?? [])],
    [clienteInicial.data, clientes.data],
  );
  const taxaRef = cambio.data ? Number(cambio.data.taxa) : 0;
  const taxaEfectiva = estrangeira ? (taxaManual ? Number(taxaManual) : taxaRef) : 1;
  const desvio = estrangeira && taxaManual && taxaRef ? desvioCambio(Number(taxaManual), taxaRef) : 0;
  const foraTolerancia = tolerancia.data ? Math.abs(desvio) > Number(tolerancia.data.tolerancia_pct) : false;
  const estimativa = useMemo(
    () => estimarTotais(linhas.map((l) => ({ quantidade: l?.quantidade, preco: l?.preco_unitario, taxa: num(l?.produto_id ? porId.get(l.produto_id)?.taxa_imposto : 0) })), taxaEfectiva || 1),
    [linhas, porId, taxaEfectiva],
  );
  const origemEscolhida = (origens.data ?? []).find((d) => d.id === origemId);
  // decisão 27 (como o legado): NC sobre factura paga — incluindo a factura-recibo — fica bloqueada até se anular o recibo
  const origemPaga = !!origemEscolhida && (origemEscolhida.tipo_documento === 'FR' || (origemEscolhida.estado === 'PAGO' && num(origemEscolhida.valor_pago) > 0.005) || num(origemEscolhida.valor_pago) > 0.01);

  // moeda do cliente por omissão (moedas_vendas.js: sugerir a moeda do cliente)
  useEffect(() => {
    if (!clienteId || copia) return;
    const c = todosClientes.find((x) => x.id === clienteId);
    if (c?.codigo_moeda) form.setFieldsValue({ codigo_moeda: c.codigo_moeda });
  }, [clienteId]); // eslint-disable-line react-hooks/exhaustive-deps

  // preço da ficha (em Kz) convertido ao câmbio do documento; as linhas com o preço da ficha acompanham a mudança de câmbio
  const precoFicha = (id?: number) => {
    const p = id ? porId.get(id) : undefined;
    const kz = num(p?.preco_unitario);
    return estrangeira && taxaEfectiva > 0 ? DOIS(kz / taxaEfectiva) : kz;
  };
  useEffect(() => {
    const actuais = (form.getFieldValue('linhas') as LinhaForm[] | undefined) ?? [];
    let mudou = false;
    const novas = actuais.map((l) => {
      if (!l?.produto_id || !porId.size) return l;
      const ficha = precoFicha(l.produto_id);
      if (ficha === l.preco_ficha) return l;
      const seguia = l.preco_ficha === undefined ? l.preco_unitario === undefined : l.preco_unitario === l.preco_ficha;
      mudou = true;
      return { ...l, preco_ficha: ficha, preco_unitario: seguia ? ficha : l.preco_unitario };
    });
    if (mudou) form.setFieldsValue({ linhas: novas });
  }, [taxaEfectiva, estrangeira, porId]); // eslint-disable-line react-hooks/exhaustive-deps

  // datas das prestações acompanham a data do documento
  useEffect(() => {
    setPlano((p) => p.map((x) => (x.dias == null ? x : { ...x, data: somarDias(dataIso, x.dias) })));
  }, [dataIso]);

  const emitir = useMutation({
    mutationFn: (v: ValoresForm) => {
      const validadeData = v.dias_validade === 'data' ? dataApi(v.valido_ate) : v.dias_validade ? somarDias(dataIso, Number(v.dias_validade)) : undefined;
      return enviar<DocumentoVenda>('post', '/vendas/documentos', {
        tipo_documento: v.tipo_documento, cliente_id: v.cliente_id, data_emissao: dataApi(v.data_emissao),
        venda_origem_id: v.venda_origem_id, motivo_nota_credito: v.motivo_nota_credito, devolucao_mercadoria: v.devolucao_mercadoria,
        conta_disponibilidade: v.conta_disponibilidade, meio_pagamento: v.meio_pagamento, referencia_pagamento: v.referencia_pagamento,
        observacoes: v.observacoes, condicoes_pagamento: v.condicoes_pagamento,
        unidade_negocio_id: v.unidade_negocio_id, centro_custo_id: v.centro_custo_id, projeto_id: v.projeto_id,
        ...(['OR', 'PF'].includes(v.tipo_documento) ? { dias_validade: v.dias_validade === 'data' ? undefined : v.dias_validade, valido_ate: validadeData } : {}),
        ...(comModalidade ? { modo_pagamento: modo, plano_pagamentos: modo === 'PRONTO' ? undefined : plano.map((p) => ({ percentagem: p.percentagem, data: p.data || undefined, descricao: p.descricao || undefined })) } : {}),
        ...(v.tipo_documento !== 'NC' ? { codigo_moeda: v.codigo_moeda || 'AOA', taxa_cambio: estrangeira && v.taxa_cambio ? v.taxa_cambio : undefined } : {}),
        linhas: v.linhas.map((l) => ({
          produto_id: l.produto_id, quantidade: l.quantidade, descricao: l.descricao || undefined,
          // decisão 8: o preço só segue quando difere do da ficha (o servidor usa o da ficha, convertido ao câmbio)
          preco_unitario: l.preco_unitario != null && l.preco_unitario !== (l.preco_ficha ?? precoFicha(l.produto_id)) ? l.preco_unitario : undefined,
        })),
        oportunidade_crm_id: conversao?.oportunidade_crm_id,   // o servidor liga o documento à oportunidade (FT/FR/NE ganham-na)
      });
    },
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
    const ficha = precoFicha(id);
    actuais[indice] = { ...actuais[indice], produto_id: id, preco_unitario: ficha, preco_ficha: ficha, descricao: p?.nome };
    form.setFieldsValue({ linhas: [...actuais] });
  };

  const submeter = (v: ValoresForm) => {
    const erro = comModalidade ? erroPlano(modo, plano) : null;
    if (erro) return void message.error(erro);
    if (tipo === 'NC' && origemPaga) return void message.error('A factura de origem está paga: anule primeiro o recibo/pagamento associado (como no sistema anterior).');
    emitir.mutate(v);
  };

  const editarPrestacao = (i: number, campo: keyof Prestacao, valor: unknown) =>
    setPlano((p) => p.map((x, k) => {
      if (k !== i) return x;
      if (campo === 'dias') return { ...x, dias: valor == null ? null : Number(valor), data: somarDias(dataIso, valor == null ? null : Number(valor)) };
      if (campo === 'data') return { ...x, data: (valor as string) || null, dias: valor ? dayjs(valor as string).diff(dayjs(dataIso), 'day') : null };
      return { ...x, [campo]: valor };
    }));
  const valoresPlano = valoresPrestacoes(plano, estimativa.total);
  const simbolo = estrangeira ? moeda : 'Kz';

  return (
    <>
      <CabecalhoPagina
        titulo="Novo documento de venda"
        subtitulo={conversao?.oportunidade_crm_id ? `A partir da oportunidade #${conversao.oportunidade_crm_id} do CRM` : copia ? `Cópia de ${copia.numero_documento}` : undefined}
        accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>}
      />
      <Form<ValoresForm> form={form} layout="vertical" initialValues={valoresIniciais} onFinish={submeter}>
        <Card title="Documento" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={12} lg={8}>
              <Form.Item name="cliente_id" label="Cliente" rules={[{ required: true, message: 'Escolha o cliente.' }]}>
                <Select
                  showSearch
                  filterOption={false}
                  onSearch={setPesquisaCliente}
                  loading={clientes.isFetching}
                  placeholder="Pesquisar por nome ou NIF"
                  options={todosClientes.map((c) => ({ value: c.id, label: `${c.nome}${c.nif ? ` (NIF ${c.nif})` : ''}` }))}
                />
              </Form.Item>
            </Col>
            <Col xs={24} sm={12} lg={4}>
              <Form.Item name="tipo_documento" label="Tipo de documento" rules={[{ required: true }]}>
                <Select options={TIPOS_EMITIVEIS.map((t) => ({ value: t, label: `${t} — ${TIPOS_DOCUMENTO[t]}` }))} popupMatchSelectWidth={false} />
              </Form.Item>
            </Col>
            <Col xs={24} sm={12} lg={4}>
              <Form.Item name="unidade_negocio_id" label="Unidade de negócio">
                <SeletorUnidade style={{ width: '100%' }} placeholder="N/A" />
              </Form.Item>
            </Col>
            <Col xs={24} sm={12} lg={4}>
              <Form.Item name="centro_custo_id" label="Centro de custo">
                <SeletorAux tabela="centros-custo" style={{ width: '100%' }} placeholder="N/A" />
              </Form.Item>
            </Col>
            <Col xs={24} sm={12} lg={4}>
              <Form.Item name="projeto_id" label="Projecto/Obra">
                <SeletorProjeto />
              </Form.Item>
            </Col>
          </Row>
          <Row gutter={16}>
            <Col xs={24} sm={12} lg={6}>
              <Form.Item name="data_emissao" label="Data do documento" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            {tipo !== 'NC' && (
              <>
                <Col xs={12} sm={6} lg={4}>
                  <Form.Item name="codigo_moeda" label="Moeda">
                    <Select
                      showSearch
                      options={[{ codigo: 'AOA' }, ...(moedas.data ?? []).filter((m) => m.codigo !== 'AOA')].map((m) => ({ value: m.codigo, label: m.codigo }))}
                      onChange={() => form.setFieldsValue({ taxa_cambio: null })}
                    />
                  </Form.Item>
                </Col>
                <Col xs={12} sm={6} lg={5}>
                  <Form.Item
                    name="taxa_cambio"
                    label="Câmbio (Kz)"
                    extra={estrangeira ? (cambio.data ? `Câmbio do dia: ${cambio.data.taxa}${cambio.data.exata ? '' : ` (de ${formatarData(cambio.data.data)})`}` : cambio.isFetching ? 'A obter o câmbio…' : 'Sem câmbio registado: indique-o.') : undefined}
                  >
                    <InputNumber min={0} step={0.01} precision={6} disabled={!estrangeira} placeholder={estrangeira ? (taxaRef ? String(taxaRef) : 'Câmbio') : '—'} style={{ width: '100%' }} />
                  </Form.Item>
                </Col>
              </>
            )}
            {estrangeira && !!taxaManual && taxaRef > 0 && foraTolerancia && (
              <Col xs={24} lg={9}>
                <Alert
                  type={tolerancia.data?.pode_exceder ? 'warning' : 'error'}
                  showIcon
                  message={`Câmbio manual ${desvio > 0 ? '+' : ''}${desvio} % face ao câmbio do dia (tolerância ${tolerancia.data?.tolerancia_pct} %).`}
                  description={tolerancia.data?.pode_exceder ? 'Fica registado na auditoria.' : 'Só quem tem a permissão «Câmbio manual fora da tolerância» o pode usar.'}
                />
              </Col>
            )}
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
            <>
              <Row gutter={16}>
                <Col xs={24} md={8}>
                  <Form.Item name="venda_origem_id" label="Factura de origem" rules={[{ required: true, message: 'Escolha a factura que a nota de crédito corrige.' }]}>
                    <Select
                      loading={origens.isFetching}
                      disabled={!clienteId}
                      placeholder={clienteId ? 'Escolha a factura' : 'Escolha primeiro o cliente'}
                      options={(origens.data ?? []).map((d) => ({ value: d.id, label: `${d.numero_documento} — ${formatarKz(d.total_bruto)} Kz${d.tipo_documento === 'FR' || d.estado === 'PAGO' ? ' (paga)' : ''}` }))}
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
              {origemPaga && (
                <Alert
                  type="warning"
                  showIcon
                  style={{ marginBottom: 16 }}
                  message={`A ${origemEscolhida?.tipo_documento === 'FR' ? 'factura-recibo' : 'factura'} ${origemEscolhida?.numero_documento} está paga: não admite nota de crédito.`}
                  description="Como no sistema anterior: anule primeiro o recibo/pagamento associado (Vendas › Recibos ou Tesouraria) e emita depois a nota de crédito. Numa factura-recibo, o recibo não se anula sozinho: corrija com uma nova factura e regularize o recebimento."
                />
              )}
            </>
          )}
        </Card>

        <Card title="Itens da venda" style={{ marginBottom: 16 }}>
          {!telemovel && (
            <Row gutter={8} style={{ fontSize: 12, fontWeight: 600, textTransform: 'uppercase', color: '#64748b', marginBottom: 6 }}>
              <Col md={8}>Produto/Serviço</Col>
              <Col md={6}>Descrição</Col>
              <Col md={2}>Qtd.</Col>
              <Col md={3}>Preço ({simbolo})</Col>
              <Col md={1}>IVA %</Col>
              <Col md={3} style={{ textAlign: 'right' }}>Total</Col>
              <Col md={1} />
            </Row>
          )}
          <Form.List name="linhas" rules={[{ validator: async (_, v) => (v && v.length ? undefined : Promise.reject(new Error('Acrescente pelo menos uma linha.'))) }]}>
            {(campos, { add, remove }, { errors }) => (
              <>
                {campos.map(({ key, name }, n) => {
                  const l = linhas[name] ?? {};
                  const p = l.produto_id ? porId.get(l.produto_id) : undefined;
                  const alterado = l.preco_ficha !== undefined && l.preco_unitario != null && l.preco_unitario !== l.preco_ficha;
                  return (
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
                      <Col xs={24} md={8}>
                        <Form.Item name={[name, 'produto_id']} rules={[{ required: true, message: 'Produto' }]}>
                          <Select
                            showSearch
                            placeholder="-- Seleccione --"
                            loading={produtos.isLoading}
                            optionFilterProp="label"
                            onChange={(id: number) => escolherProduto(name, id)}
                            options={(produtos.data ?? []).map((x) => ({ value: x.id, label: `${x.codigo ? `${x.codigo} — ` : ''}${x.nome}` }))}
                          />
                        </Form.Item>
                      </Col>
                      <Col xs={24} md={6}>
                        <Form.Item name={[name, 'descricao']}>
                          <Input placeholder="Descrição" maxLength={1000} />
                        </Form.Item>
                      </Col>
                      <Col xs={8} md={2}>
                        <Form.Item name={[name, 'quantidade']} rules={[{ required: true, message: 'Qtd.' }]}>
                          <InputNumber min={0.001} step={1} placeholder="Qtd." style={{ width: '100%' }} />
                        </Form.Item>
                      </Col>
                      <Col xs={10} md={3}>
                        <Form.Item name={[name, 'preco_ficha']} hidden><InputNumber /></Form.Item>
                        <Tooltip title={podeAlterarPreco ? (alterado ? `Preço da ficha: ${formatarKz(l.preco_ficha)}` : undefined) : 'Preço da ficha do produto (alterar exige a permissão «Alterar o preço de venda»).'}>
                          <Form.Item name={[name, 'preco_unitario']}>
                            <InputNumber min={0} precision={2} placeholder="Preço s/ IVA" style={{ width: '100%' }} disabled={!podeAlterarPreco} status={alterado ? 'warning' : undefined} aria-label={`Preço da linha ${n + 1}`} />
                          </Form.Item>
                        </Tooltip>
                      </Col>
                      <Col xs={6} md={1}>
                        <Typography.Text style={{ lineHeight: '32px' }}>{p?.taxa_imposto != null ? Number(p.taxa_imposto) : '—'}</Typography.Text>
                      </Col>
                      <Col xs={20} md={3} style={{ textAlign: 'right' }}>
                        <Typography.Text strong style={{ lineHeight: '32px' }}>{formatarKz(DOIS(num(l.quantidade) * num(l.preco_unitario)))}</Typography.Text>
                      </Col>
                      <Col xs={4} md={1}>
                        <Button danger type="text" icon={<DeleteOutlined />} onClick={() => remove(name)} aria-label="Remover linha" />
                      </Col>
                    </Row>
                  );
                })}
                <Form.ErrorList errors={errors} />
                <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ quantidade: 1 })}>
                  Acrescentar linha
                </Button>
              </>
            )}
          </Form.List>
        </Card>

        <Card style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={12}>
              <Form.Item name="observacoes" label="Observações (opcional)">
                <Input.TextArea rows={2} maxLength={4000} placeholder="Notas para o cliente, referências internas…" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="condicoes_pagamento" label="Condições de pagamento (opcional)">
                <Input.TextArea rows={2} maxLength={2000} placeholder="Ex.: pagamento a pronto, 30 dias a prazo, transferência bancária…" />
              </Form.Item>
            </Col>
          </Row>
        </Card>

        {comModalidade && (
          <Card title={<><CalendarOutlined /> Validade e modalidade de pagamento</>} style={{ marginBottom: 16 }}>
            <Row gutter={16} align="bottom">
              {(tipo === 'OR' || tipo === 'PF') && (
                <>
                  <Col xs={12} md={5}>
                    <Form.Item name="dias_validade" label="Validade">
                      <Select options={[...DIAS_VALIDADE.map((d) => ({ value: d, label: `${d} dias` })), { value: 'data', label: 'Outra data' }]} />
                    </Form.Item>
                  </Col>
                  <Col xs={12} md={5}>
                    <Form.Item noStyle shouldUpdate={(a, b) => a.dias_validade !== b.dias_validade || a.data_emissao !== b.data_emissao}>
                      {({ getFieldValue }) => (getFieldValue('dias_validade') === 'data'
                        ? <Form.Item name="valido_ate" label="Válido até" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
                        : <Form.Item label="Válido até"><Input disabled value={formatarData(somarDias(dataIso, Number(getFieldValue('dias_validade') ?? 30)))} /></Form.Item>)}
                    </Form.Item>
                  </Col>
                </>
              )}
              <Col xs={24} md={14}>
                <Form.Item label="Modalidade de pagamento">
                  <Segmented<ModoPagamento>
                    value={modo}
                    onChange={(m) => { setModo(m); setPlano(m === 'PRAZO' ? planoPrazo(dataIso, [30]) : m === 'MARCOS' ? planoMarcos(dataIso, 0) : []); }}
                    options={[
                      { value: 'PRONTO', label: 'Pronto pagamento', icon: <ThunderboltOutlined /> },
                      { value: 'PRAZO', label: 'A prazo (datas)', icon: <CalendarOutlined /> },
                      { value: 'MARCOS', label: 'Por marcos (%)', icon: <FlagOutlined /> },
                    ]}
                  />
                </Form.Item>
              </Col>
            </Row>
            {modo === 'PRONTO' && <Typography.Text type="secondary">Pagamento integral na data do documento.</Typography.Text>}
            {modo !== 'PRONTO' && (
              <>
                <Space wrap style={{ marginBottom: 12 }}>
                  {modo === 'PRAZO'
                    ? DIAS_PRAZO.map((d) => {
                        const activo = plano.some((p) => p.dias === d);
                        return (
                          <Button key={d} size="small" type={activo ? 'primary' : 'default'} aria-pressed={activo}
                            onClick={() => setPlano(planoPrazo(dataIso, activo ? plano.filter((p) => p.dias !== d).map((p) => p.dias ?? 0) : [...plano.map((p) => p.dias ?? 0), d]))}>
                            {d} dias
                          </Button>
                        );
                      })
                    : MODELOS_MARCOS.map((m, i) => <Button key={m.rotulo} size="small" onClick={() => setPlano(planoMarcos(dataIso, i))}>{m.rotulo}</Button>)}
                  <Button size="small" icon={<PlusOutlined />} onClick={() => setPlano((p) => [...p, { descricao: modo === 'MARCOS' ? `Marco ${p.length + 1}` : `${p.length + 1}ª prestação`, percentagem: Math.max(0, DOIS(100 - somaPercentagens(p))), dias: null, data: null }])}>
                    {modo === 'MARCOS' ? 'Marco' : 'Prestação'}
                  </Button>
                </Space>
                <Table<Prestacao>
                  size="small"
                  pagination={false}
                  rowKey={(p) => `${plano.indexOf(p)}`}
                  dataSource={plano}
                  scroll={{ x: 'max-content' }}
                  columns={[
                    { title: 'Descrição', render: (_, p, i) => <Input size="small" value={p.descricao} maxLength={200} onChange={(e) => editarPrestacao(i, 'descricao', e.target.value)} aria-label={`Descrição da prestação ${i + 1}`} /> },
                    { title: '%', width: 90, render: (_, p, i) => <InputNumber size="small" min={0} max={100} value={p.percentagem} onChange={(v) => editarPrestacao(i, 'percentagem', v ?? 0)} aria-label={`Percentagem da prestação ${i + 1}`} /> },
                    { title: 'Dias', width: 90, render: (_, p, i) => <InputNumber size="small" min={0} value={p.dias ?? undefined} onChange={(v) => editarPrestacao(i, 'dias', v)} aria-label={`Dias da prestação ${i + 1}`} /> },
                    { title: 'Data', width: 150, render: (_, p, i) => <DatePicker size="small" format="DD/MM/YYYY" value={p.data ? dayjs(p.data) : null} onChange={(d) => editarPrestacao(i, 'data', dataApi(d) ?? null)} aria-label={`Data da prestação ${i + 1}`} /> },
                    { title: `Valor (${simbolo})`, align: 'right', render: (_, __, i) => formatarKz(valoresPlano[i]) },
                    { title: '', width: 40, render: (_, __, i) => <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Remover prestação" onClick={() => setPlano((p) => p.filter((_, k) => k !== i))} /> },
                  ]}
                  summary={() => (
                    <Table.Summary.Row>
                      <Table.Summary.Cell index={0}><strong>Total</strong></Table.Summary.Cell>
                      <Table.Summary.Cell index={1}><Tag color={Math.abs(somaPercentagens(plano) - 100) > 0.005 ? 'red' : 'green'}>{somaPercentagens(plano)} %</Tag></Table.Summary.Cell>
                      <Table.Summary.Cell index={2} colSpan={4} />
                    </Table.Summary.Row>
                  )}
                />
              </>
            )}
          </Card>
        )}

        <Flex justify="space-between" align="end" gap={16} wrap style={{ marginBottom: 16 }}>
          <Space wrap>
            <Button type="primary" htmlType="submit" icon={<CheckOutlined />} loading={emitir.isPending}>
              Emitir {TIPOS_DOCUMENTO[tipo]?.toLowerCase()}
            </Button>
            <Button onClick={() => navegar('..')}>Cancelar</Button>
          </Space>
          <Card size="small" style={{ width: telemovel ? '100%' : undefined, maxWidth: '100%' }}>
            <Flex justify="end" gap={pequenoGap(telemovel)} wrap>
              <Statistic title="Líquido (estimativa)" value={formatarKz(estimativa.liquido)} suffix={estrangeira ? moeda : undefined} />
              <Statistic title="IVA (estimativa)" value={formatarKz(estimativa.imposto)} suffix={estrangeira ? moeda : undefined} />
              <Statistic title="Total (estimativa)" value={formatarKz(estimativa.total)} suffix={estrangeira ? moeda : undefined} valueStyle={{ color: '#2563eb', fontWeight: 700 }} />
            </Flex>
            {estrangeira && (
              <Typography.Paragraph type="secondary" style={{ margin: '8px 0 0', textAlign: 'right' }}>
                Contravalor: <strong>{formatarKz(estimativa.totalKz)} Kz</strong> ao câmbio {taxaEfectiva || '—'}
              </Typography.Paragraph>
            )}
            <Typography.Paragraph type="secondary" style={{ margin: '8px 0 0', fontSize: 12 }}>
              O valor final é calculado pelo servidor com as regras da facturação electrónica (AGT).
            </Typography.Paragraph>
          </Card>
        </Flex>
      </Form>
    </>
  );
}

/** Projecto/obra (opcional). */
function SeletorProjeto({ value, onChange }: { value?: number; onChange?: (v?: number) => void }) {
  const projetos = useQuery({
    queryKey: ['projetos', 'lista-curta'],
    queryFn: () => obterPagina<{ id: number; codigo: string | null; nome: string }>('/projetos', { por_pagina: 200 }),
    staleTime: 300_000,
    retry: false,
  });
  return (
    <Select
      allowClear
      showSearch
      optionFilterProp="label"
      value={value}
      onChange={onChange}
      placeholder="-- Sem projecto --"
      loading={projetos.isLoading}
      popupMatchSelectWidth={false}
      style={{ width: '100%' }}
      options={(projetos.data?.itens ?? []).map((p) => ({ value: p.id, label: `${p.codigo ? `${p.codigo} — ` : ''}${p.nome}` }))}
    />
  );
}
