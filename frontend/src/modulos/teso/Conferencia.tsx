import { Alert, Button, Card, Checkbox, Col, DatePicker, Form, Input, InputNumber, Modal, Row, Space, Statistic, Table, Typography, message } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../contab/comum/Componentes';
import { DENOMINACOES, type ConferenciaCaixa } from './api';
import { SeletorContaFinanceira } from './comum';
import { accoesConferencia, lerDenominacoes, totalContado } from './regras';

interface ValoresConferencia {
  codigo_conta: string;
  data_conferencia: Dayjs;
  denominacoes: Record<string, number | null>;
  saldo_externo?: number;
  justificacao?: string;
}

/** Tesouraria › Conferência de caixa (ecrã teso_gestao_conferencia): contagem por denominação, finalizar (com regularização), assinatura do gerente e reabertura. */
export default function Conferencia() {
  const { pode, utilizador } = useSessao();
  const cliente = useQueryClient();
  const lista = useQuery({ queryKey: ['teso', 'conferencias'], queryFn: () => obter<ConferenciaCaixa[]>('/tesouraria/conferencias') });
  const [edicao, setEdicao] = useState<ConferenciaCaixa | 'nova' | null>(null);
  const [finalizar, setFinalizar] = useState<ConferenciaCaixa | null>(null);
  const [regularizar, setRegularizar] = useState(false);
  const [reabrir, setReabrir] = useState<ConferenciaCaixa | null>(null);
  const [form] = Form.useForm<ValoresConferencia>();
  const [formMotivo] = Form.useForm<{ motivo: string }>();
  const quantidades = Form.useWatch('denominacoes', form) ?? {};
  const nome = utilizador?.nome_utilizador;

  const mutacao = useMutation({
    mutationFn: ({ metodo, url, corpo }: { metodo: 'post' | 'put'; url: string; corpo?: unknown }) => enviar<ConferenciaCaixa>(metodo, url, corpo),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      setFinalizar(null);
      setReabrir(null);
      formMotivo.resetFields();
      void cliente.invalidateQueries({ queryKey: ['teso', 'conferencias'] });
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e),
  });

  const abrir = (c: ConferenciaCaixa | 'nova') => {
    form.resetFields();
    if (c === 'nova') form.setFieldsValue({ data_conferencia: dayjs(), denominacoes: {} });
    else
      form.setFieldsValue({
        codigo_conta: c.codigo_conta,
        data_conferencia: dayjs(c.data_conferencia),
        denominacoes: lerDenominacoes(c.denominacoes),
        saldo_externo: c.saldo_externo ? Number(c.saldo_externo) : undefined,
        justificacao: c.justificacao ?? undefined,
      });
    setEdicao(c);
  };

  const gravar = (v: ValoresConferencia) => {
    const corpo = {
      codigo_conta: v.codigo_conta,
      data_conferencia: dataApi(v.data_conferencia),
      denominacoes: Object.fromEntries(DENOMINACOES.map((d) => [d.chave, Math.max(0, Math.trunc(v.denominacoes?.[d.chave] ?? 0))])),
      saldo_externo: v.saldo_externo ?? undefined,
      justificacao: v.justificacao || undefined,
    };
    mutacao.mutate(edicao && edicao !== 'nova' ? { metodo: 'put', url: `/tesouraria/conferencias/${edicao.id}`, corpo } : { metodo: 'post', url: '/tesouraria/conferencias', corpo });
  };

  return (
    <>
      <CabecalhoPagina
        titulo="Conferência de caixa"
        subtitulo="Contagem física por notas e moedas, comparada com o saldo contabilístico"
        accoes={pode('teso_conf_registar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('nova')}>Nova conferência</Button>}
      />
      <Card>
        <Table<ConferenciaCaixa>
          rowKey="id"
          loading={lista.isLoading}
          dataSource={lista.data}
          pagination={{ pageSize: 25 }}
          scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Data', dataIndex: 'data_conferencia', render: formatarData },
            { title: 'Conta', dataIndex: 'codigo_conta', render: (v: string, c) => `${v}${c.nome_conta ? ` — ${c.nome_conta}` : ''}` },
            { title: 'Operador', dataIndex: 'nome_operador' },
            { title: 'Contado', dataIndex: 'total_fisico', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
            { title: 'Sistema', dataIndex: 'total_sistema', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
            { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (v: string) => <ValorKz valor={v} forte discretoSeZero /> },
            { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
            { title: 'Gerente', render: (_, c) => (c.nome_gerente ? `${c.nome_gerente} · ${formatarDataHora(c.assinado_gerente_em)}` : '—') },
            {
              title: '',
              render: (_, c) => {
                const a = accoesConferencia(c, pode, nome);
                return (
                  <Space>
                    {a.podeEditar && <Button size="small" onClick={() => abrir(c)}>Editar</Button>}
                    {a.podeFinalizar && <Button size="small" type="primary" onClick={() => { setRegularizar(false); setFinalizar(c); }}>Finalizar</Button>}
                    {a.podeAssinar && (
                      <Button size="small" onClick={() => Modal.confirm({ title: 'Assinar a conferência como gerente?', okText: 'Assinar', cancelText: 'Cancelar', onOk: () => mutacao.mutateAsync({ metodo: 'post', url: `/tesouraria/conferencias/${c.id}/assinar` }) })}>
                        Assinar
                      </Button>
                    )}
                    {a.podeReabrir && <Button size="small" danger onClick={() => setReabrir(c)}>Reabrir</Button>}
                  </Space>
                );
              },
            },
          ]}
        />
      </Card>

      <Modal title={edicao === 'nova' ? 'Nova conferência de caixa' : 'Editar conferência'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar rascunho" confirmLoading={mutacao.isPending} onOk={() => form.submit()} width={760}>
        <Form form={form} layout="vertical" onFinish={gravar}>
          <Row gutter={12}>
            <Col span={14}>
              <Form.Item name="codigo_conta" label="Conta de caixa" rules={[{ required: true }]}>
                <SeletorContaFinanceira prefixos={['45']} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col span={10}>
              <Form.Item name="data_conferencia" label="Data" rules={[{ required: true }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          </Row>
          <Typography.Text strong>Contagem</Typography.Text>
          <Row gutter={12} style={{ marginTop: 8 }}>
            {DENOMINACOES.map((d) => (
              <Col key={d.chave} xs={12} md={6}>
                <Form.Item name={['denominacoes', d.chave]} label={d.rotulo}>
                  <InputNumber min={0} precision={0} style={{ width: '100%' }} />
                </Form.Item>
              </Col>
            ))}
          </Row>
          <Statistic title="Total contado (Kz)" value={formatarKz(totalContado(quantidades))} style={{ marginBottom: 12 }} />
          <Row gutter={12}>
            <Col span={10}>
              <Form.Item name="saldo_externo" label="Saldo externo (opcional)">
                <InputNumber precision={2} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col span={14}>
              <Form.Item name="justificacao" label="Justificação de diferenças">
                <Input.TextArea rows={2} maxLength={4000} />
              </Form.Item>
            </Col>
          </Row>
          <Typography.Text type="secondary">O saldo do sistema e a diferença são calculados pelo servidor na data da conferência.</Typography.Text>
        </Form>
      </Modal>

      <Modal
        title="Finalizar conferência"
        open={!!finalizar}
        onCancel={() => setFinalizar(null)}
        okText="Finalizar"
        confirmLoading={mutacao.isPending}
        onOk={() => finalizar && mutacao.mutate({ metodo: 'post', url: `/tesouraria/conferencias/${finalizar.id}/finalizar`, corpo: { regularizar } })}
      >
        {finalizar && (
          <>
            <Typography.Paragraph>Diferença registada: <strong>{formatarKz(finalizar.diferenca, true)}</strong></Typography.Paragraph>
            <Checkbox checked={regularizar} onChange={(e) => setRegularizar(e.target.checked)}>
              Lançar a regularização da diferença (sobras/quebras de caixa)
            </Checkbox>
            <Alert style={{ marginTop: 12 }} type="info" showIcon message="As contas de sobras e quebras definem-se em Integração no razão › Contas de tesouraria." />
          </>
        )}
      </Modal>

      <Modal title="Reabrir conferência" open={!!reabrir} onCancel={() => setReabrir(null)} okText="Reabrir" okButtonProps={{ danger: true }} confirmLoading={mutacao.isPending} onOk={() => formMotivo.submit()}>
        <Form form={formMotivo} layout="vertical" onFinish={(v) => reabrir && mutacao.mutate({ metodo: 'post', url: `/tesouraria/conferencias/${reabrir.id}/reabrir`, corpo: v })}>
          <Typography.Paragraph type="secondary">A regularização lançada (se houver) é estornada e a assinatura do gerente é retirada.</Typography.Paragraph>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
