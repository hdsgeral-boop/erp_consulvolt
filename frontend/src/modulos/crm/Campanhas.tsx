import { Alert, Button, Card, Col, Flex, List, Modal, Radio, Row, Select, Space, Table, Tag, Typography } from 'antd';
import { MailOutlined, SendOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type ReactNode } from 'react';
import { obter, enviar } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { BotaoCsv } from '@/modulos/contab/comum/Componentes';
import { CHAVE_CRM, useConfigCRM, useFunis, useModelosEmail } from './comum/dados';
import { abrirMailto, type Envio, type Funil, type MensagemEmail } from './comum/tipos';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';

type Destinatario = MensagemEmail & { conta: { id: number; nome: string; tipo: string }; tem_email: boolean };

interface ResultadoCampanha {
  registadas: number;
  envio?: Envio;
  envios?: { conta_crm_id: number; para: string; envio: Envio }[];
  sem_email?: number[];
}

/** Subtítulo do antigo (renderCrmCampanhas, crm_ui_gestao.js:315). */
export const SUBTITULO_CAMPANHAS_CRM =
  'Mensagem personalizada para cada destinatário a partir de um modelo. Abre-se no seu programa de email (uma a uma ou em lote) ou exporta-se para uma ferramenta de email marketing. Fica registado no histórico de cada conta.';
/** «Contas» (crm_ui_gestao.js:318). */
export const OPCOES_TIPO_CAMPANHA = [{ value: '', label: 'Todas' }, { value: 'PROSPECT', label: 'Prospects' }, { value: 'CLIENTE', label: 'Clientes' }];

/** «Com oportunidade aberta na etapa»: «Qualquer / sem oportunidade» e as etapas em aberto agrupadas por pipeline (crm_ui_gestao.js:319). */
export const opcoesEtapaCampanha = (funis: Pick<Funil, 'id' | 'nome' | 'etapas'>[]) => [
  { value: '', label: 'Qualquer / sem oportunidade' },
  ...funis.map((f) => ({ label: f.nome, title: f.nome, options: f.etapas.filter((e) => e.tipo === 'ABERTA').map((e) => ({ value: `${f.id}|${e.id}`, label: e.nome })) })),
];

/** Campo com o rótulo por cima, como os filtros do antigo (.crm-filtros .form-group). */
function Campo({ rotulo, largura, children }: { rotulo: string; largura: number; children: ReactNode }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 2, width: largura, maxWidth: '100%' }}>
      <span style={{ fontSize: '0.75rem', fontWeight: 700, color: '#475569' }}>{rotulo}</span>
      {children}
    </label>
  );
}

/**
 * CRM › Campanhas de email (crm_campanhas, renderCrmCampanhas, crm_ui_gestao.js:310-384): escolher modelo e público, rever destinatários e registar o envio. O servidor não
 * envia emails: regista a actividade em cada conta e devolve ligações mailto para abrir no programa de email.
 */
export default function Campanhas() {
  const { pode } = useSessao();
  const modelos = useModelosEmail();
  const funis = useFunis();
  const config = useConfigCRM();
  const cliente = useQueryClient();
  const [modelo, setModelo] = useState<number | undefined>();
  const [tipo, setTipo] = useState('');
  const [etapa, setEtapa] = useState(''); // «funil|etapa» (as etapas repetem-se entre pipelines)
  const [origem, setOrigem] = useState('');
  const etapaCodigo = etapa.split('|')[1] || undefined;
  const [escolhidos, setEscolhidos] = useState<number[]>([]);
  const [modo, setModo] = useState<'INDIVIDUAL' | 'BCC'>('INDIVIDUAL');
  const [ver, setVer] = useState<Destinatario | null>(null);
  const [resultado, setResultado] = useState<ResultadoCampanha | null>(null);

  const destinatarios = useQuery({
    queryKey: ['crm', 'campanhas', modelo, tipo, etapaCodigo, origem],
    queryFn: () => obter<Destinatario[]>('/crm/campanhas/destinatarios', { modelo_email_crm_id: modelo, tipo: tipo || undefined, etapa_codigo: etapaCodigo, origem: origem || undefined }),
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

  const lista = destinatarios.data ?? [];
  const semEmail = lista.filter((d) => !d.tem_email).length;

  return (
    <>
      <CabecalhoPagina titulo="Campanhas de email" subtitulo={SUBTITULO_CAMPANHAS_CRM} />
      {/* filtros pela ordem do antigo (crm_ui_gestao.js:317-320): Modelo *, Contas, etapa, Origem */}
      <Card size="small" style={{ marginBottom: 12 }}>
        <Flex gap={12} wrap align="end">
          <Campo rotulo="Modelo *" largura={240}>
            <Select placeholder="—" value={modelo} onChange={setModelo} loading={modelos.isLoading} options={(modelos.data ?? []).map((m) => ({ value: m.id, label: m.nome }))} />
          </Campo>
          <Campo rotulo="Contas" largura={150}>
            <Select value={tipo} onChange={setTipo} options={OPCOES_TIPO_CAMPANHA} />
          </Campo>
          <Campo rotulo="Com oportunidade aberta na etapa" largura={240}>
            <Select value={etapa} onChange={setEtapa} loading={funis.isLoading} options={opcoesEtapaCampanha(funis.data ?? [])} />
          </Campo>
          <Campo rotulo="Origem" largura={180}>
            <Select value={origem} onChange={setOrigem} options={[{ value: '', label: 'Todas' }, ...(config.data?.origens ?? []).map((o) => ({ value: o, label: o }))]} />
          </Campo>
        </Flex>
      </Card>
      {!modelo ? (
        <Alert type="info" message="Escolha o modelo de email (crie-os em CRM › Configuração)." />
      ) : (
        <Row gutter={[12, 12]}>
          <Col xs={24} xl={16}>
            <Card
              size="small"
              title={`Destinatários (${escolhidos.length} de ${lista.length})`}
              extra={
                <Space wrap>
                <BotoesExportar
                  tamanho="small"
                  desactivado={!lista.length}
                  obterPedido={() => ({
                    titulo: 'Destinatários da campanha de email',
                    filtros: [modelos.data?.find((m) => m.id === modelo)?.nome && `Modelo: ${modelos.data?.find((m) => m.id === modelo)?.nome}`, `${lista.length} conta(s) · ${escolhidos.length} escolhida(s)`],
                    conteudo: tabelaHtml({
                      colunas: [
                        { titulo: 'Conta', valor: (d: Destinatario) => d.conta.nome, quebrar: true },
                        { titulo: 'Tipo', valor: (d) => (d.conta.tipo === 'CLIENTE' ? 'Cliente' : 'Prospect') },
                        { titulo: 'Email', valor: (d) => d.para || 'sem email' },
                        { titulo: 'Assunto', valor: (d) => d.assunto, quebrar: true },
                        { titulo: 'Escolhido', valor: (d) => (escolhidos.includes(d.conta.id) ? 'Sim' : '') },
                      ],
                      linhas: lista,
                    }),
                  })}
                />
                <BotaoCsv<Destinatario>
                  nome="destinatarios_campanha"
                  linhas={lista}
                  colunas={[{ titulo: 'Conta', valor: (d) => d.conta.nome }, { titulo: 'Tipo', valor: (d) => d.conta.tipo }, { titulo: 'Email', valor: (d) => d.para }, { titulo: 'Assunto', valor: (d) => d.assunto }]}
                />
                </Space>
              }
            >
              {/* resumo do antigo (crm_ui_gestao.js:344) */}
              <Flex gap={18} wrap style={{ fontSize: '0.8rem', color: '#475569', marginBottom: 8 }}>
                <span>Destinatários: <b>{lista.length}</b></span>
                <span>Com email: <b>{lista.length - semEmail}</b></span>
                {semEmail > 0 && <span style={{ color: '#b45309' }}>Sem email: <b>{semEmail}</b></span>}
              </Flex>
              <Table<Destinatario> scroll={scrollTabela()}
                size="small"
                rowKey={(d) => d.conta.id}
                loading={destinatarios.isFetching}
                dataSource={lista}
                pagination={{ pageSize: 50, hideOnSinglePage: true }}
                rowSelection={{ selectedRowKeys: escolhidos, onChange: (k) => setEscolhidos(k as number[]), getCheckboxProps: (d) => ({ disabled: !d.tem_email }) }}
                columns={[
                  { title: 'Conta', key: 'c', render: (_, d) => <><strong>{d.conta.nome}</strong> {d.conta.tipo === 'PROSPECT' && <Tag>prospect</Tag>}</> },
                  { title: 'Para', dataIndex: 'para', render: (v: string) => v || <Tag color="warning">sem email</Tag> },
                  { title: 'Assunto', dataIndex: 'assunto', ellipsis: true },
                  { title: 'Pré-visualização', dataIndex: 'corpo', responsive: ['lg'], render: (c: string) => <span style={{ fontSize: '0.75rem', color: '#475569' }}>{(c ?? '').slice(0, 140)}{(c ?? '').length > 140 ? '…' : ''}</span> },
                  { title: '', key: 'v', width: 80, render: (_, d) => <Button size="small" type="link" onClick={() => setVer(d)}>Ver</Button> },
                ]}
              />
            </Card>
          </Col>
          <Col xs={24} xl={8}>
            <Card size="small" title="Envio">
              <Radio.Group value={modo} onChange={(e) => setModo(e.target.value)} style={{ marginBottom: 12 }}>
                <Space direction="vertical">
                  {/* botões do antigo (crm_ui_gestao.js:346-347) */}
                  <Radio value="INDIVIDUAL">Abrir seleccionados um a um (personalizado)</Radio>
                  <Radio value="BCC">Uma mensagem em Bcc (sem personalização)</Radio>
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
      <Modal open={!!ver} title={ver?.assunto} onCancel={() => setVer(null)} footer={null} width={larguraModal(640)}>
        <Typography.Paragraph type="secondary">Para: {ver?.para || '—'}</Typography.Paragraph>
        <div style={{ whiteSpace: 'pre-wrap', border: '1px solid #f0f0f0', borderRadius: 6, padding: 12 }}>{ver?.corpo}</div>
      </Modal>
    </>
  );
}
