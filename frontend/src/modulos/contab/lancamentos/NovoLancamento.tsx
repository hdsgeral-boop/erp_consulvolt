import { Alert, Button, Card, Col, DatePicker, Flex, Form, Input, InputNumber, Row, Select, Space, Typography, message, theme } from 'antd';
import { ArrowLeftOutlined, SaveOutlined, ScissorOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useLocation, useNavigate } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { linhasDeMovimentos, useExcessoOrcamental, type OpcoesOrcamento } from '@/componentes/orcamento';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { DocumentoLancamento } from '../api';
import { IndicadorEquilibrio } from '../comum/Componentes';
import { equilibrio } from '@/utilitarios/decimal';
import { EditorLinhasDC, linhasParaApi, type LinhaEditor } from '../comum/EditorLinhasDC';
import { SeletorDiario } from '../comum/Seletores';
import { rotuloTerceiro } from '../comum/terceiro';
import { MOEDA_BASE, contravalorKz, emMoeda, equilibrarLinhas, linhasComMoeda } from './moeda';

interface ValoresForm {
  diario_id?: number;
  data_documento: Dayjs;
  codigo_moeda?: string;
  taxa_cambio?: number | null;
  numero_documento?: string;
  referencia?: string;
  descricao?: string;
  linhas: LinhaEditor[];
}

/** Estado de navegação para «copiar» um lançamento existente. */
export interface EstadoCopia {
  copia?: DocumentoLancamento;
  /** «assistente_ia»: proposta do assistente IA (M-03) — usa a data proposta em vez da de hoje. */
  origem?: 'assistente_ia';
}

interface MoedaApi {
  codigo: string;
  nome: string;
  ativo: boolean;
}

interface CambioApi {
  taxa: number | string;
  data?: string;
  exata?: boolean;
}

/**
 * Lançamento manual (POST /contabilidade/lancamentos) — «Registar novo lançamento» do legado (js/ui_lancamentos.js:617-686).
 * Só se grava equilibrado (Σ D = Σ C); o servidor numera, valida o exercício aberto, as contas de movimento e o controlo
 * orçamental. Em moeda estrangeira (A-07) os valores das linhas são na moeda e o equilíbrio é verificado na moeda;
 * o câmbio é o indicado ou o da tabela na data fiscal, e o Kz calcula-se no servidor (acerto de arredondamento ≤ 1 Kz).
 */
export function NovoLancamento() {
  const navegar = useNavigate();
  const { token } = theme.useToken();
  const local = useLocation();
  const cliente = useQueryClient();
  const [form] = Form.useForm<ValoresForm>();
  const linhas = Form.useWatch('linhas', form) ?? [];
  const moeda = Form.useWatch('codigo_moeda', form) ?? MOEDA_BASE;
  const taxaManual = Form.useWatch('taxa_cambio', form);
  const data = Form.useWatch('data_documento', form) as Dayjs | undefined;
  const e = equilibrio(linhas);
  const estadoNav = local.state as EstadoCopia | null;
  const copia = estadoNav?.copia;
  const doAssistente = estadoNav?.origem === 'assistente_ia';
  const excesso = useExcessoOrcamental();
  const estrangeira = emMoeda(moeda);

  const moedas = useQuery({ queryKey: ['sistema', 'moedas', 'activas'], queryFn: () => obter<MoedaApi[]>('/sistema/moedas', { ativas: 1 }), staleTime: 300_000, retry: false });
  const cambio = useQuery({
    queryKey: ['sistema', 'cambio', moeda, dataApi(data)],
    queryFn: () => obter<CambioApi | null>('/sistema/cambios/consultar', { codigo_moeda: moeda, data: dataApi(data) }),
    enabled: estrangeira && !!data,
    retry: false,
  });
  const taxaEfectiva = estrangeira ? (taxaManual ? Number(taxaManual) : cambio.data ? Number(cambio.data.taxa) : null) : 1;

  const valoresIniciais: Partial<ValoresForm> = copia
    ? {
        diario_id: copia.diario_id,
        data_documento: doAssistente && copia.data_documento ? dayjs(copia.data_documento) : dayjs(),
        codigo_moeda: copia.linhas[0]?.codigo_moeda ?? MOEDA_BASE,
        numero_documento: copia.numero_documento ?? undefined,
        descricao: copia.linhas[0]?.descricao ?? undefined,
        linhas: copia.linhas.map((l) => ({
          codigo_conta: l.codigo_conta,
          tipo_dc: l.tipo_dc,
          valor: Number(l.codigo_moeda && l.valor_moeda ? l.valor_moeda : l.valor),
          descricao: l.descricao ?? undefined,
          terceiro_id: l.terceiro_id ?? undefined,
          centro_custo_id: l.centro_custo_id ?? undefined,
          unidade_negocio_id: l.unidade_negocio_id ?? undefined,
          nota_demonstracao_id: l.nota_demonstracao_id ?? undefined,
          nota_fluxo_caixa_id: l.nota_fluxo_caixa_id ?? undefined,
          _terceiro: l.terceiro_id ? rotuloTerceiro(l.terceiro, l.terceiro_id, true) : undefined,
        })),
      }
    : { data_documento: dayjs(), codigo_moeda: MOEDA_BASE, linhas: [{ tipo_dc: 'D' }, { tipo_dc: 'C' }] };

  const gravar = useMutation({
    mutationFn: (v: ValoresForm & { orcamento?: OpcoesOrcamento }) =>
      enviar<DocumentoLancamento>('post', '/contabilidade/lancamentos', {
        diario_id: v.diario_id,
        data_documento: dataApi(v.data_documento),
        numero_documento: v.numero_documento || undefined,
        referencia: v.referencia || undefined,
        descricao: v.descricao || undefined,
        codigo_moeda: emMoeda(v.codigo_moeda) ? v.codigo_moeda : undefined,
        taxa_cambio: emMoeda(v.codigo_moeda) && v.taxa_cambio ? v.taxa_cambio : undefined,
        linhas: linhasComMoeda(linhasParaApi(v.linhas.map((l) => ({ ...l, descricao: l.descricao || v.descricao }))), v.codigo_moeda),
        orcamento: v.orcamento,
      }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      const primeira = dados.linhas[0];
      navegar(primeira ? `../${primeira.id}` : '..');
    },
    onError: (erro, v) => {
      // excesso orçamental: pedir a aprovação ou aprovar no acto sem perder o formulário (A-02)
      const tratado = excesso.tratar(erro, {
        contexto: () => ({ tipo: 'EXPLORACAO', data: dataApi(v.data_documento) ?? '', linhas: linhasDeMovimentos(v.linhas) }),
        repetir: (orcamento) => gravar.mutate({ ...v, orcamento }),
      });
      if (!tratado) notificarErro(erro, 'Não foi possível gravar o lançamento');
    },
  });

  const equilibrar = () => {
    const novas = equilibrarLinhas(form.getFieldValue('linhas') ?? []);
    if (novas) form.setFieldValue('linhas', novas);
  };

  return (
    <>
      <CabecalhoPagina
        titulo={doAssistente ? 'Novo lançamento (proposta do assistente IA)' : copia ? `Novo lançamento (cópia de ${copia.numero_lan})` : 'Novo lançamento'}
        subtitulo="Registar novo lançamento no Diário"
        accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>}
      />
      <Form<ValoresForm> form={form} layout="vertical" initialValues={valoresIniciais} onFinish={(v) => gravar.mutate(v)}>
        <Card title="Registar novo lançamento" style={{ marginBottom: 16, borderTop: `4px solid ${token.colorPrimary}` }}>
          <Row gutter={[16, 0]}>
            <Col xs={24} md={12} lg={6}>
              <Form.Item name="diario_id" label="Diário" rules={[{ required: true, message: 'Escolha o diário.' }]}>
                <SeletorDiario style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6} lg={4}>
              <Form.Item name="data_documento" label="Data fiscal" tooltip="Data que alimenta os relatórios contabilísticos" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6} lg={3}>
              <Form.Item name="codigo_moeda" label="Moeda">
                <Select
                  showSearch
                  aria-label="Moeda"
                  options={[{ value: MOEDA_BASE, label: MOEDA_BASE }, ...(moedas.data ?? []).filter((m) => m.codigo !== MOEDA_BASE).map((m) => ({ value: m.codigo, label: m.codigo, title: m.nome }))]}
                  onChange={() => form.setFieldValue('taxa_cambio', null)}
                />
              </Form.Item>
            </Col>
            <Col xs={24} md={12} lg={5}>
              <Form.Item
                name="taxa_cambio"
                label="Câmbio (Kz)"
                tooltip="Quantos Kz vale 1 unidade da moeda. Vazio = câmbio da tabela para a data fiscal."
                extra={
                  estrangeira ? (
                    cambio.data ? (
                      <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        Tabela: {Number(cambio.data.taxa).toLocaleString('pt-PT', { maximumFractionDigits: 6 })}
                        {cambio.data.data ? ` (${formatarData(cambio.data.data)}${cambio.data.exata === false ? ', último anterior' : ''})` : ''}
                      </Typography.Text>
                    ) : cambio.isFetched ? (
                      <Typography.Text type="danger" style={{ fontSize: 12 }}>Sem câmbio registado: indique-o.</Typography.Text>
                    ) : null
                  ) : null
                }
              >
                <InputNumber min={0.000001} precision={6} decimalSeparator="," style={{ width: '100%' }} disabled={!estrangeira} placeholder="—" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12} lg={3}>
              <Form.Item name="numero_documento" label="N.º do documento">
                <Input maxLength={100} placeholder="N.º do doc. original…" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12} lg={3}>
              <Form.Item name="referencia" label="Referência">
                <Input maxLength={100} placeholder="Texto de referência…" />
              </Form.Item>
            </Col>
          </Row>
          <Form.Item name="descricao" label="Descrição comum (aplica-se às linhas sem descrição)">
            <Input maxLength={1000} placeholder="Descrição que se aplica a todas as linhas…" />
          </Form.Item>
        </Card>
        <Card title="Linhas do documento" style={{ marginBottom: 16 }}>
          <EditorLinhasDC minimo={2} campos={{ terceiro: true, centroCusto: true, unidade: true, notas: true }} rotuloValor={estrangeira ? `Valor (${moeda})` : 'Valor (Kz)'} />
          <Flex wrap gap={16} justify="space-between" align="center" style={{ marginTop: 16 }}>
            <Button icon={<ScissorOutlined />} onClick={equilibrar} title="Preencher o valor em falta para equilibrar D = C" disabled={e.equilibrado}>
              Equilibrar
            </Button>
            <IndicadorEquilibrio linhas={linhas} />
          </Flex>
          {estrangeira && (
            <Typography.Paragraph type="secondary" style={{ marginTop: 8, textAlign: 'right' }}>
              Contravalor estimado: {formatarKz(contravalorKz(e.debito, taxaEfectiva))} Kz a débito / {formatarKz(contravalorKz(e.credito, taxaEfectiva))} Kz a crédito
              {!taxaEfectiva && ' (sem câmbio)'} — o servidor calcula o Kz linha a linha e acerta até 1 Kz de arredondamento.
            </Typography.Paragraph>
          )}
          {!e.equilibrado && (
            <Alert style={{ marginTop: 12 }} type="warning" showIcon message="O lançamento só pode ser gravado com o total a débito igual ao total a crédito." />
          )}
        </Card>
        <Space wrap>
          <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={gravar.isPending} disabled={!e.valido || (estrangeira && !taxaEfectiva)}>
            Gravar lançamento
          </Button>
          <Button onClick={() => navegar('..')}>Cancelar</Button>
        </Space>
      </Form>
      {excesso.dialogo}
    </>
  );
}
