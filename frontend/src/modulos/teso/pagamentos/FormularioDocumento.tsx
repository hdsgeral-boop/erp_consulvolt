import { Alert, Button, Card, Col, DatePicker, Form, Input, InputNumber, Modal, Row, Segmented, Select, Skeleton, Space, Statistic, Table, Typography, message } from 'antd';
import { ArrowLeftOutlined, FileSearchOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ValorKz } from '../../contab/comum/Componentes';
import { EditorLinhasDC, linhasParaApi, type LinhaEditor } from '../../contab/comum/EditorLinhasDC';
import { SeletorTerceiro } from '../../contab/comum/Seletores';
import { ROTULO_TIPO, type DocumentoTesouraria, type Pendente, type TipoDocumento } from '../api';
import { SeletorContaFinanceira } from '../comum';
import { sentidoPorOmissao, totalDocumento } from '../regras';

type Linha = LinhaEditor & { venda_id?: number; fatura_compra_id?: number };

interface ValoresForm {
  tipo: TipoDocumento;
  data_documento: Dayjs;
  conta_financeira?: string;
  descricao?: string;
  referencia?: string;
  taxa_cambio?: number;
  linhas: Linha[];
}

/**
 * Emissão e edição de pagamentos/recebimentos (POST/PUT /tesouraria/documentos). O documento fica PENDENTE (por integrar);
 * o servidor numera (PAG/REC), valida o saldo em aberto dos documentos liquidados, a moeda da conta e o controlo orçamental.
 */
export function FormularioDocumento() {
  const { id } = useParams();
  const [procura] = useSearchParams();
  const navegar = useNavigate();
  const cliente = useQueryClient();
  const [form] = Form.useForm<ValoresForm>();
  const tipo = (Form.useWatch('tipo', form) ?? 'PAGAMENTO') as TipoDocumento;
  const linhas = Form.useWatch('linhas', form) ?? [];
  const [pendentes, setPendentes] = useState(false);
  const total = totalDocumento(tipo, linhas);

  const existente = useQuery({ queryKey: ['teso', 'documento', id], queryFn: () => obter<DocumentoTesouraria>(`/tesouraria/documentos/${id}`), enabled: !!id });
  useEffect(() => {
    const d = existente.data;
    if (!d) return;
    form.setFieldsValue({
      tipo: d.tipo,
      data_documento: dayjs(d.data_documento),
      conta_financeira: d.conta_financeira,
      descricao: d.descricao ?? undefined,
      referencia: d.referencia ?? undefined,
      taxa_cambio: d.taxa_cambio ? Number(d.taxa_cambio) : undefined,
      linhas: (d.linhas ?? []).map((l) => ({
        codigo_conta: l.codigo_conta,
        tipo_dc: l.tipo_dc,
        valor: Number(l.valor_introduzido ?? l.valor_moeda ?? l.valor),
        descricao: l.descricao ?? undefined,
        terceiro_id: l.terceiro_id ?? undefined,
        numero_documento: l.numero_documento ?? undefined,
        centro_custo_id: l.centro_custo_id ?? undefined,
        unidade_negocio_id: l.unidade_negocio_id ?? undefined,
        venda_id: l.venda_id ?? undefined,
        fatura_compra_id: l.fatura_compra_id ?? undefined,
        _terceiro: l.terceiro?.nome?.trim() ?? (l.terceiro_id ? `Terceiro #${l.terceiro_id}` : undefined),
      })),
    });
  }, [existente.data, form]);

  const gravar = useMutation({
    mutationFn: (v: ValoresForm) => {
      const corpo = {
        tipo: v.tipo,
        data_documento: dataApi(v.data_documento),
        conta_financeira: v.conta_financeira,
        descricao: v.descricao,
        referencia: v.referencia || undefined,
        taxa_cambio: v.taxa_cambio || undefined,
        linhas: linhasParaApi(v.linhas),
      };
      return id ? enviar<DocumentoTesouraria>('put', `/tesouraria/documentos/${id}`, corpo) : enviar<DocumentoTesouraria>('post', '/tesouraria/documentos', corpo);
    },
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['teso'] });
      navegar(id ? `../${id}` : `../${dados.id}`);
    },
    onError: (e) => notificarErro(e, 'Não foi possível gravar o documento'),
  });

  const acrescentarPendentes = (escolhidos: Pendente[]) => {
    const actuais = ((form.getFieldValue('linhas') as Linha[]) ?? []).filter((l) => l?.codigo_conta || l?.valor);
    const novas: Linha[] = escolhidos.map((p) => ({
      codigo_conta: p.codigo_conta,
      tipo_dc: p.liquidar_a,
      valor: Number(p.codigo_moeda && p.codigo_moeda !== 'AOA' && p.saldo_moeda ? p.saldo_moeda : p.saldo),
      terceiro_id: p.terceiro_id,
      numero_documento: p.numero_documento,
      descricao: `Liquid. Doc: ${p.numero_documento}`,
      venda_id: p.venda_id ?? undefined,
      fatura_compra_id: p.fatura_compra_id ?? undefined,
      _terceiro: p.terceiro?.trim() ?? undefined,
    }));
    form.setFieldsValue({ linhas: [...actuais, ...novas] });
    setPendentes(false);
  };

  if (id && existente.isLoading) return <Skeleton active />;
  const tipoInicial = (procura.get('tipo') === 'RECEBIMENTO' ? 'RECEBIMENTO' : 'PAGAMENTO') as TipoDocumento;

  return (
    <>
      <CabecalhoPagina
        titulo={id ? `Editar ${existente.data?.numero_documento ?? 'documento'}` : `Novo ${ROTULO_TIPO[tipo].toLowerCase()}`}
        accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar(id ? `../${id}` : '..')}>Voltar</Button>}
      />
      {existente.data && existente.data.estado !== 'PENDENTE' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Um documento ${existente.data.estado} não pode ser alterado.`} />}
      <Form<ValoresForm>
        form={form}
        layout="vertical"
        initialValues={{ tipo: tipoInicial, data_documento: dayjs(), linhas: [{ tipo_dc: sentidoPorOmissao(tipoInicial) }] }}
        onFinish={(v) => gravar.mutate(v)}
      >
        <Card title="Documento" style={{ marginBottom: 16 }}>
          <Row gutter={16}>
            <Col xs={24} md={6}>
              <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
                <Segmented block disabled={!!id} options={[{ value: 'PAGAMENTO', label: 'Pagamento' }, { value: 'RECEBIMENTO', label: 'Recebimento' }]} />
              </Form.Item>
            </Col>
            <Col xs={24} md={4}>
              <Form.Item name="data_documento" label="Data" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="conta_financeira" label="Conta de banco/caixa" rules={[{ required: true, message: 'Indique a conta (43 bancos, 45 caixa).' }]}>
                <SeletorContaFinanceira style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item name="referencia" label="Referência">
                <Input maxLength={100} />
              </Form.Item>
            </Col>
          </Row>
          <Row gutter={16}>
            <Col xs={24} md={18}>
              <Form.Item name="descricao" label="Descrição" rules={[{ required: true, min: 5, message: 'Descreva o documento (pelo menos 5 caracteres).' }]}>
                <Input maxLength={1000} />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item name="taxa_cambio" label="Câmbio (só contas em moeda)" tooltip="Vazio = câmbio do dia registado no sistema">
                <InputNumber min={0.000001} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          </Row>
        </Card>
        <Card
          title="Linhas (contrapartidas)"
          extra={<Button icon={<FileSearchOutlined />} onClick={() => setPendentes(true)}>Documentos em aberto</Button>}
          style={{ marginBottom: 16 }}
        >
          <Typography.Paragraph type="secondary">
            {tipo === 'PAGAMENTO'
              ? 'Num pagamento os débitos das linhas têm de exceder os créditos; a diferença sai da conta de banco/caixa.'
              : 'Num recebimento os créditos das linhas têm de exceder os débitos; a diferença entra na conta de banco/caixa.'}
          </Typography.Paragraph>
          <EditorLinhasDC campos={{ terceiro: true, numeroDocumento: true, centroCusto: true, unidade: true }} />
          <Space size={32} style={{ marginTop: 16 }}>
            <Statistic title={`Valor do ${ROTULO_TIPO[tipo].toLowerCase()} (Kz)`} value={formatarKz(total.total)} valueStyle={{ color: total.valido ? undefined : '#cf1322' }} />
          </Space>
          {!total.valido && linhas.length > 0 && <Alert style={{ marginTop: 12 }} type="warning" showIcon message="O valor do documento tem de ser positivo (veja o sentido D/C das linhas)." />}
        </Card>
        <Space>
          <Button type="primary" htmlType="submit" loading={gravar.isPending} disabled={!total.valido || (!!existente.data && existente.data.estado !== 'PENDENTE')}>
            Gravar (por integrar)
          </Button>
          <Button onClick={() => navegar(id ? `../${id}` : '..')}>Cancelar</Button>
        </Space>
      </Form>
      {pendentes && <ModalPendentes tipo={tipo} aoFechar={() => setPendentes(false)} aoEscolher={acrescentarPendentes} />}
    </>
  );
}

function ModalPendentes({ tipo, aoFechar, aoEscolher }: { tipo: TipoDocumento; aoFechar: () => void; aoEscolher: (p: Pendente[]) => void }) {
  const [terceiro, setTerceiro] = useState<number>();
  const [natureza, setNatureza] = useState<'A_RECEBER' | 'A_PAGAR'>(tipo === 'PAGAMENTO' ? 'A_PAGAR' : 'A_RECEBER');
  const [pesquisa, setPesquisa] = useState('');
  const [seleccao, setSeleccao] = useState<string[]>([]);
  const consulta = useQuery({
    queryKey: ['teso', 'pendentes', terceiro, natureza, pesquisa],
    queryFn: () => obter<Pendente[]>('/tesouraria/pendentes', { terceiro_id: terceiro, natureza, pesquisa }),
  });
  const chave = (p: Pendente) => `${p.terceiro_id}|${p.codigo_conta}|${p.numero_documento}`;
  const escolhidos = (consulta.data ?? []).filter((p) => seleccao.includes(chave(p)));
  return (
    <Modal open title="Documentos em aberto" width={1000} onCancel={aoFechar} okText={`Acrescentar ${escolhidos.length} linha(s)`} okButtonProps={{ disabled: !escolhidos.length }} onOk={() => aoEscolher(escolhidos)}>
      <Space wrap style={{ marginBottom: 12 }}>
        <Select value={natureza} onChange={setNatureza} style={{ width: 160 }} options={[{ value: 'A_PAGAR', label: 'A pagar' }, { value: 'A_RECEBER', label: 'A receber' }]} />
        <SeletorTerceiro value={terceiro} onChange={setTerceiro} />
        <Input.Search placeholder="N.º do documento" allowClear onSearch={setPesquisa} style={{ width: 200 }} />
      </Space>
      <Table<Pendente>
        rowKey={chave}
        size="small"
        loading={consulta.isFetching}
        dataSource={consulta.data}
        pagination={{ pageSize: 10 }}
        scroll={{ x: 'max-content' }}
        rowSelection={{ selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as string[]) }}
        columns={[
          { title: 'Terceiro', dataIndex: 'terceiro', render: (v: string | null) => v?.trim() ?? '—' },
          { title: 'Conta', dataIndex: 'codigo_conta' },
          { title: 'Documento', dataIndex: 'numero_documento' },
          { title: 'Data', dataIndex: 'data_documento', render: formatarData },
          { title: 'Total', dataIndex: 'total', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
          { title: 'Liquidado', dataIndex: 'liquidado', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero /> },
          { title: 'Em liquidação', dataIndex: 'em_liquidacao', align: 'right', render: (v: string) => <ValorKz valor={v} discretoSeZero /> },
          { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v: string, p) => <><ValorKz valor={v} forte />{p.codigo_moeda && p.codigo_moeda !== 'AOA' && p.saldo_moeda ? <div style={{ fontSize: 12 }}>{p.saldo_moeda} {p.codigo_moeda}</div> : null}</> },
        ]}
      />
    </Modal>
  );
}
