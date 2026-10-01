import { Alert, Button, Card, Col, Empty, Flex, List, Modal, Radio, Row, Select, Space, Table, Tag, Typography } from 'antd';
import { MailOutlined, SendOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter, enviar } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { BotaoCsv } from '@/modulos/contab/comum/Componentes';
import { CHAVE_CRM, useConfigCRM, useFunis, useModelosEmail } from './comum/dados';
import { abrirMailto, type Envio, type MensagemEmail } from './comum/tipos';

type Destinatario = MensagemEmail & { conta: { id: number; nome: string; tipo: string }; tem_email: boolean };

interface ResultadoCampanha {
  registadas: number;
  envio?: Envio;
  envios?: { conta_crm_id: number; para: string; envio: Envio }[];
  sem_email?: number[];
}

/**
 * CRM › Campanhas de email (crm_campanhas): escolher modelo e público, rever destinatários e registar o envio. O servidor não
 * envia emails: regista a actividade em cada conta e devolve ligações mailto para abrir no programa de email.
 */
export default function Campanhas() {
  const { pode } = useSessao();
  const modelos = useModelosEmail();
  const funis = useFunis();
  const config = useConfigCRM();
  const cliente = useQueryClient();
  const [modelo, setModelo] = useState<number | undefined>();
  const [tipo, setTipo] = useState<string | undefined>();
  const [funil, setFunil] = useState<number | undefined>();
  const [etapa, setEtapa] = useState<string | undefined>();
  const [origem, setOrigem] = useState<string | undefined>();
  const [escolhidos, setEscolhidos] = useState<number[]>([]);
  const [modo, setModo] = useState<'INDIVIDUAL' | 'BCC'>('INDIVIDUAL');
  const [ver, setVer] = useState<Destinatario | null>(null);
  const [resultado, setResultado] = useState<ResultadoCampanha | null>(null);

  const destinatarios = useQuery({
    queryKey: ['crm', 'campanhas', modelo, tipo, etapa, origem],
    queryFn: () => obter<Destinatario[]>('/crm/campanhas/destinatarios', { modelo_email_crm_id: modelo, tipo, etapa_codigo: etapa, origem }),
    enabled: !!modelo,
  });
  useEffect(() => {
    setEscolhidos((destinatarios.data ?? []).filter((d) => d.tem_email).map((d) => d.conta.id));
  }, [destinatarios.data]);

  const envio = useMutation({
    mutationFn: () => {
      const lista = (destinatarios.data ?? []).filter((d) => escolhidos.includes(d.conta.id));
      return enviar<ResultadoCampanha>('post', '/crm/campanhas/enviar', {
        modelo_email_crm_id: modelo,
        modo,
        destinatarios: lista.map((d) => ({ conta_crm_id: d.conta.id, contacto_crm_id: d.contacto_crm_id, oportunidade_crm_id: d.oportunidade_crm_id })),
      });
    },
    onSuccess: ({ dados }) => {
      setResultado(dados);
      if (dados.envio) abrirMailto(dados.envio);
      void cliente.invalidateQueries({ queryKey: CHAVE_CRM });
    },
    onError: (e) => notificarErro(e, 'Não foi possível registar a campanha'),
  });

  const etapas = funis.data?.find((f) => f.id === funil)?.etapas.filter((e) => e.tipo === 'ABERTA') ?? [];
  const lista = destinatarios.data ?? [];
  const semEmail = lista.filter((d) => !d.tem_email).length;

  return (
    <>
      <CabecalhoPagina titulo="Campanhas de email" subtitulo="Envio de um modelo a um conjunto de contas, com registo no histórico de cada uma" />
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 12 }}
        message="O sistema não envia emails directamente"
        description="Cada envio fica registado como actividade na conta e abre-se no seu programa de email (mailto). No modo Bcc é aberta uma única mensagem com todos os destinatários em cópia oculta."
      />
      <Card size="small" style={{ marginBottom: 12 }}>
        <Flex gap={8} wrap align="center">
          <Select placeholder="Modelo de email" style={{ width: 260 }} value={modelo} onChange={setModelo} loading={modelos.isLoading} options={(modelos.data ?? []).map((m) => ({ value: m.id, label: m.nome }))} />
          <Select allowClear placeholder="Tipo de conta" style={{ width: 150 }} value={tipo} onChange={setTipo} options={[{ value: 'PROSPECT', label: 'Prospects' }, { value: 'CLIENTE', label: 'Clientes' }]} />
          <Select allowClear placeholder="Funil" style={{ width: 160 }} value={funil} onChange={(f) => { setFunil(f); setEtapa(undefined); }} options={(funis.data ?? []).map((f) => ({ value: f.id, label: f.nome }))} />
          <Select allowClear placeholder="Com oportunidade na etapa" style={{ width: 220 }} disabled={!funil} value={etapa} onChange={setEtapa} options={etapas.map((e) => ({ value: e.id, label: e.nome }))} />
          <Select allowClear placeholder="Origem" style={{ width: 160 }} value={origem} onChange={setOrigem} options={(config.data?.origens ?? []).map((o) => ({ value: o, label: o }))} />
        </Flex>
      </Card>
      {!modelo ? (
        <Empty description="Escolha o modelo de email para ver os destinatários." />
      ) : (
        <Row gutter={12}>
          <Col xs={24} xl={16}>
            <Card
              size="small"
              title={`Destinatários (${escolhidos.length} de ${lista.length})`}
              extra={
                <BotaoCsv<Destinatario>
                  nome="destinatarios_campanha"
                  linhas={lista}
                  colunas={[{ titulo: 'Conta', valor: (d) => d.conta.nome }, { titulo: 'Tipo', valor: (d) => d.conta.tipo }, { titulo: 'Email', valor: (d) => d.para }, { titulo: 'Assunto', valor: (d) => d.assunto }]}
                />
              }
            >
              {semEmail > 0 && <Alert type="warning" showIcon style={{ marginBottom: 8 }} message={`${semEmail} conta(s) sem email não podem receber a campanha.`} />}
              <Table<Destinatario>
                size="small"
                rowKey={(d) => d.conta.id}
                loading={destinatarios.isFetching}
                dataSource={lista}
                pagination={{ pageSize: 50, hideOnSinglePage: true }}
                rowSelection={{ selectedRowKeys: escolhidos, onChange: (k) => setEscolhidos(k as number[]), getCheckboxProps: (d) => ({ disabled: !d.tem_email }) }}
                columns={[
                  { title: 'Conta', key: 'c', render: (_, d) => <>{d.conta.nome} {d.conta.tipo === 'PROSPECT' && <Tag>prospect</Tag>}</> },
                  { title: 'Email', dataIndex: 'para', render: (v: string) => v || <Typography.Text type="danger">sem email</Typography.Text> },
                  { title: 'Assunto', dataIndex: 'assunto', ellipsis: true },
                  { title: '', key: 'v', width: 80, render: (_, d) => <Button size="small" type="link" onClick={() => setVer(d)}>Ver</Button> },
                ]}
              />
            </Card>
          </Col>
          <Col xs={24} xl={8}>
            <Card size="small" title="Envio">
              <Radio.Group value={modo} onChange={(e) => setModo(e.target.value)} style={{ marginBottom: 12 }}>
                <Space direction="vertical">
                  <Radio value="INDIVIDUAL">Um email por destinatário (personalizado)</Radio>
                  <Radio value="BCC">Uma mensagem em Bcc (marcadores pessoais retirados)</Radio>
                </Space>
              </Radio.Group>
              {pode('crm_campanhas_enviar') ? (
                <Button type="primary" icon={<SendOutlined />} block disabled={!escolhidos.length} loading={envio.isPending} onClick={() => Modal.confirm({ title: `Registar a campanha para ${escolhidos.length} conta(s)?`, okText: 'Registar e enviar', cancelText: 'Cancelar', onOk: () => envio.mutateAsync() })}>
                  Registar e enviar
                </Button>
              ) : (
                <Alert type="info" showIcon message="Não tem permissão para enviar campanhas." />
              )}
              {resultado && (
                <Alert
                  style={{ marginTop: 12 }}
                  type="success"
                  showIcon
                  message={`${resultado.registadas} email(s) registado(s) no histórico.`}
                  description={
                    resultado.envios?.length ? (
                      <>
                        <div>Abra cada mensagem no seu programa de email:</div>
                        <List
                          size="small"
                          dataSource={resultado.envios}
                          renderItem={(x) => (
                            <List.Item style={{ padding: '2px 0' }}>
                              <a href={x.envio.mailto} rel="noreferrer"><MailOutlined /> {x.para}</a>
                            </List.Item>
                          )}
                        />
                      </>
                    ) : resultado.envio?.mailto ? (
                      <a href={resultado.envio.mailto}><MailOutlined /> Abrir a mensagem em Bcc</a>
                    ) : undefined
                  }
                />
              )}
            </Card>
          </Col>
        </Row>
      )}
      <Modal open={!!ver} title={ver?.assunto} onCancel={() => setVer(null)} footer={null} width={640}>
        <Typography.Paragraph type="secondary">Para: {ver?.para || '—'}</Typography.Paragraph>
        <div style={{ whiteSpace: 'pre-wrap', border: '1px solid #f0f0f0', borderRadius: 6, padding: 12 }}>{ver?.corpo}</div>
      </Modal>
    </>
  );
}
