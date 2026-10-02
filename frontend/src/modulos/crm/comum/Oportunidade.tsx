import { Alert, Button, Card, Col, DatePicker, Descriptions, Drawer, Dropdown, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Skeleton, Space, Table, Tabs, Tag, Timeline, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, FileAddOutlined, LinkOutlined, MailOutlined, PlusOutlined, SwapOutlined } from '@ant-design/icons';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { useMapaProdutos } from '@/modulos/compras/comum/referencias';
import { CHAVE_CRM, useConfigCRM, useFunis } from './dados';
import { ListaActividades, ModalActividade, ModalEmail, ModalMoverEtapa, SeletorContaCRM, TagSaude } from './componentes';
import { DOCUMENTOS_CONVERSAO, totalItens, type Actividade, type ContaCRM, type Contacto, type Etapa, type ItemOportunidade, type Oportunidade, type Saude } from './tipos';
import { larguraGaveta, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { BotoesExportar } from '@/componentes/impressao';

interface DetalheOportunidade {
  oportunidade: Oportunidade;
  etapa: Etapa | null;
  funil: { id: number; nome: string };
  probabilidade_efectiva: string;
  valor_ponderado: string;
  saude: Saude;
  conta: ContaCRM | null;
  contactos: Contacto[];
  atividades: Actividade[];
  documentos: { id: number; tipo_documento: string; numero_documento: string; data_emissao: string; total_bruto: string; estado: string }[];
}

interface DadosConversao {
  tipo_documento: string;
  cliente_id: number | null;
  data_emissao: string;
  oportunidade_crm_id: number;
  linhas: { produto_id: number; quantidade: number | string; preco_unitario: number | string; descricao: string | null }[];
  aviso_atraso: { em_atraso: string; n_atrasadas: number; max_dias_atraso: number } | null;
}

/** Ficha da oportunidade: dados, linhas, actividades, histórico de etapas, documentos e conversão em documento de venda. */
export function FichaOportunidade({ id, aoFechar }: { id: number | null; aoFechar: () => void }) {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const funis = useFunis();
  const mapaProdutos = useMapaProdutos();
  const q = useQuery({ queryKey: ['crm', 'oportunidade', id], queryFn: () => obter<DetalheOportunidade>(`/crm/oportunidades/${id}`), enabled: id !== null });
  const [edicao, setEdicao] = useState(false);
  const [mover, setMover] = useState<Etapa | undefined>();
  const [actividade, setActividade] = useState<Actividade | 'nova' | null>(null);
  const [email, setEmail] = useState(false);
  const [conversao, setConversao] = useState<DadosConversao | null>(null);
  const [vendaId, setVendaId] = useState<number | null>(null);
  const editar = pode('crm_editar');
  const converter = pode('crm_converter');
  const eliminar = useAccao({ invalidar: [CHAVE_CRM], aoSucesso: () => aoFechar() });
  const ligar = useAccao({ invalidar: [CHAVE_CRM], aoSucesso: () => { setConversao(null); setVendaId(null); } });
  const obterConversao = useMutation({
    mutationFn: (tipo: string) => obter<DadosConversao>(`/crm/oportunidades/${id}/conversao`, { tipo_documento: tipo }),
    onSuccess: setConversao,
    onError: (e) => notificarErro(e, 'Não foi possível preparar a conversão'),
  });

  const d = q.data;
  const o = d?.oportunidade;
  const etapas = funis.data?.find((f) => f.id === o?.funil_vendas_crm_id)?.etapas ?? [];
  const nomeEtapa = (codigo: string) => etapas.find((e) => e.id === codigo)?.nome ?? codigo;

  return (
    <Drawer
      open={id !== null}
      onClose={aoFechar}
      width={larguraGaveta(880)}
      destroyOnHidden
      rootClassName="crm-ficha-oportunidade"
      title={o ? <Space wrap>{o.titulo}<TagSaude saude={d?.saude} /></Space> : 'Oportunidade'}
      extra={
        o && (
          <Space wrap>
            <BotoesExportar
              tamanho="small"
              obterPedido={() => {
                const corpo = document.querySelector('.crm-ficha-oportunidade .ant-drawer-body');
                return corpo ? { titulo: `Oportunidade: ${o.titulo}`, subtitulo: o.conta_crm?.nome ?? null, conteudo: corpo } : null;
              }}
            />
            {editar && (
              <Dropdown
                menu={{ items: etapas.filter((e) => e.id !== o.etapa_codigo).map((e) => ({ key: e.id, label: e.nome })), onClick: ({ key }) => setMover(etapas.find((e) => e.id === key)) }}
              >
                <Button icon={<SwapOutlined />}>Mover etapa</Button>
              </Dropdown>
            )}
            {converter && (
              <Dropdown menu={{ items: DOCUMENTOS_CONVERSAO.map((x) => ({ key: x.tipo, label: x.rotulo })), onClick: ({ key }) => obterConversao.mutate(key) }}>
                <Button icon={<FileAddOutlined />} loading={obterConversao.isPending}>Converter</Button>
              </Dropdown>
            )}
            {editar && <Button icon={<EditOutlined />} onClick={() => setEdicao(true)}>Editar</Button>}
            {editar && (
              <Popconfirm title="Eliminar a oportunidade?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => eliminar.mutateAsync({ metodo: 'delete', url: `/crm/oportunidades/${o.id}` })}>
                <Button danger icon={<DeleteOutlined />} aria-label="Eliminar" />
              </Popconfirm>
            )}
          </Space>
        )
      }
    >
      {!d || !o ? (
        <Skeleton active />
      ) : (
        <>
          {d.saude.motivos.length > 0 && <Alert type={d.saude.nivel === 'RISCO' ? 'error' : 'warning'} showIcon style={{ marginBottom: 12 }} message={d.saude.motivos.join(' · ')} />}
          <Descriptions size="small" column={{ xs: 1, sm: 2 }} bordered>
            <Descriptions.Item label="Conta">{d.conta?.nome ?? '—'} {d.conta?.tipo === 'PROSPECT' && <Tag>prospect</Tag>}</Descriptions.Item>
            <Descriptions.Item label="Contacto">{d.contactos.find((c) => c.id === o.contacto_crm_id)?.nome ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Funil / etapa">{d.funil.nome} · <Tag color={d.etapa?.cor ?? undefined}>{d.etapa?.nome ?? o.etapa_codigo}</Tag></Descriptions.Item>
            <Descriptions.Item label="Estado">{o.estado === 'GANHA' ? <Tag color="green">Ganha</Tag> : o.estado === 'PERDIDA' ? <Tag>Perdida</Tag> : <Tag color="blue">Aberta</Tag>}</Descriptions.Item>
            <Descriptions.Item label="Valor">{formatarKz(o.valor)} Kz</Descriptions.Item>
            <Descriptions.Item label="Ponderado">{formatarKz(d.valor_ponderado)} Kz ({formatarNumero(d.probabilidade_efectiva)}%)</Descriptions.Item>
            <Descriptions.Item label="Fecho previsto">{formatarData(o.data_fecho_prevista)}</Descriptions.Item>
            <Descriptions.Item label="Responsável">{o.responsavel ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Origem">{o.origem_original ?? o.origem ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Na etapa há">{d.saude.dias_etapa ?? '—'} dia(s)</Descriptions.Item>
            {o.estado === 'PERDIDA' && <Descriptions.Item label="Perda" span={2}>{o.motivo_perda}{o.concorrente ? ` · para ${o.concorrente}` : ''}{o.notas_perda ? ` — ${o.notas_perda}` : ''}</Descriptions.Item>}
            {o.notas && <Descriptions.Item label="Notas" span={2}>{o.notas}</Descriptions.Item>}
          </Descriptions>
          <Tabs
            style={{ marginTop: 12 }}
            items={[
              {
                key: 'actividades',
                label: `Actividades (${d.atividades.filter((a) => !a.concluida).length})`,
                children: (
                  <>
                    {editar && (
                      <Space wrap style={{ marginBottom: 8 }}>
                        <Button size="small" icon={<PlusOutlined />} onClick={() => setActividade('nova')}>Actividade</Button>
                        <Button size="small" icon={<MailOutlined />} onClick={() => setEmail(true)}>Email</Button>
                      </Space>
                    )}
                    <ListaActividades actividades={d.atividades} podeEditar={editar} aoEditar={setActividade} />
                  </>
                ),
              },
              {
                key: 'linhas',
                label: `Linhas (${o.itens?.length ?? 0})`,
                children: (
                  <Table<ItemOportunidade> scroll={scrollTabela()}
                    size="small"
                    rowKey={(l) => JSON.stringify(l)}
                    pagination={false}
                    dataSource={o.itens ?? []}
                    locale={{ emptyText: 'Sem linhas: o valor é o indicado na oportunidade.' }}
                    columns={[
                      { title: 'Produto / descrição', key: 'p', render: (_, i) => i.descricao || (i.produto_id ? mapaProdutos.get(i.produto_id)?.nome : null) || '—' },
                      { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: (v) => formatarNumero(v as string) },
                      { title: 'Preço', dataIndex: 'preco', align: 'right', render: (v) => formatarKz(v as string) },
                      { title: 'IVA %', dataIndex: 'taxa', align: 'right', render: (v) => formatarNumero(v as string) },
                      { title: 'Total s/ IVA', key: 't', align: 'right', render: (_, i) => formatarKz(totalItens([i])) },
                    ]}
                    summary={() => (o.itens?.length ? <Table.Summary.Row><Table.Summary.Cell index={0} colSpan={4}><strong>Total</strong></Table.Summary.Cell><Table.Summary.Cell index={1} align="right"><strong>{formatarKz(totalItens(o.itens))}</strong></Table.Summary.Cell></Table.Summary.Row> : null)}
                  />
                ),
              },
              {
                key: 'historico',
                label: 'Histórico',
                children: (
                  <Timeline
                    items={[...(o.historico ?? [])].reverse().map((h) => ({ children: <><strong>{nomeEtapa(h.etapa_codigo)}</strong> <Typography.Text type="secondary">{formatarDataHora(h.entrou_em)} · {h.por}</Typography.Text>{h.motivo && <div>{h.motivo}</div>}</> }))}
                  />
                ),
              },
              {
                key: 'documentos',
                label: `Documentos (${d.documentos.length})`,
                children: (
                  <Table scroll={scrollTabela()}
                    size="small"
                    rowKey="id"
                    pagination={false}
                    dataSource={d.documentos}
                    locale={{ emptyText: 'Sem documentos de venda ligados.' }}
                    onRow={(v) => ({ onClick: () => navegar(`/m/vendas/vendas_faturacao/${v.id}`), style: { cursor: 'pointer' } })}
                    columns={[
                      { title: 'Documento', dataIndex: 'numero_documento' },
                      { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
                      { title: 'Total', dataIndex: 'total_bruto', align: 'right', render: (v: string) => formatarKz(v) },
                      { title: 'Estado', dataIndex: 'estado' },
                    ]}
                  />
                ),
              },
            ]}
          />
        </>
      )}
      {o && <FormOportunidade oportunidade={edicao ? o : null} aberto={edicao} aoFechar={() => setEdicao(false)} />}
      <ModalMoverEtapa oportunidade={mover && o ? o : null} etapa={mover} aoFechar={() => setMover(undefined)} />
      <ModalActividade actividade={actividade} contexto={{ oportunidade_crm_id: o?.id, conta_crm_id: o?.conta_crm_id, contacto_crm_id: o?.contacto_crm_id }} aoFechar={() => setActividade(null)} />
      <ModalEmail contexto={email && o ? { oportunidade_crm_id: o.id, conta_crm_id: o.conta_crm_id, contacto_crm_id: o.contacto_crm_id } : null} aoFechar={() => setEmail(false)} />
      <Modal
        open={!!conversao}
        title={`Converter em ${DOCUMENTOS_CONVERSAO.find((x) => x.tipo === conversao?.tipo_documento)?.rotulo ?? ''}`}
        onCancel={() => setConversao(null)}
        width={larguraModal(720)}
        footer={
          <Space wrap>
            <Button onClick={() => setConversao(null)}>Fechar</Button>
            {pode('vendas_faturacao_view') && (
              <Button type="primary" icon={<FileAddOutlined />} onClick={() => navegar('/m/vendas/vendas_faturacao/novo', { state: { conversaoCrm: conversao } })}>
                Abrir emissão de documento
              </Button>
            )}
          </Space>
        }
      >
        {conversao && (
          <Space direction="vertical" style={{ width: '100%' }}>
            {conversao.aviso_atraso && (
              <Alert type="warning" showIcon message={`O cliente tem ${formatarKz(conversao.aviso_atraso.em_atraso)} Kz em atraso (${conversao.aviso_atraso.n_atrasadas} factura(s), até ${conversao.aviso_atraso.max_dias_atraso} dias).`} />
            )}
            <Descriptions size="small" column={{ xs: 1, sm: 2 }} bordered>
              <Descriptions.Item label="Tipo">{conversao.tipo_documento}</Descriptions.Item>
              <Descriptions.Item label="Data">{formatarData(conversao.data_emissao)}</Descriptions.Item>
              <Descriptions.Item label="Cliente" span={2}>{d?.conta?.nome} {conversao.cliente_id ? <Tag>cliente #{conversao.cliente_id}</Tag> : <Tag color="red">sem cliente</Tag>}</Descriptions.Item>
            </Descriptions>
            <Table scroll={scrollTabela()}
              size="small"
              rowKey={(l) => JSON.stringify(l)}
              pagination={false}
              dataSource={conversao.linhas}
              locale={{ emptyText: 'Sem linhas com produto: acrescente-as na emissão.' }}
              columns={[
                { title: 'Produto', dataIndex: 'produto_id', render: (p: number, l) => l.descricao || mapaProdutos.get(p)?.nome || `#${p}` },
                { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: (v) => formatarNumero(v as string) },
                { title: 'Preço', dataIndex: 'preco_unitario', align: 'right', render: (v) => formatarKz(v as string) },
              ]}
            />
            <Typography.Text type="secondary">
              O documento é emitido em Vendas › Facturação com estes dados (o servidor numera e sela). Depois de emitido, ligue-o aqui à oportunidade:
            </Typography.Text>
            <Flex gap={8} wrap>
              <InputNumber placeholder="Id do documento emitido" min={1} value={vendaId} onChange={(v) => setVendaId(v)} style={{ width: 220, maxWidth: '100%' }} />
              <Button icon={<LinkOutlined />} disabled={!vendaId} loading={ligar.isPending} onClick={() => ligar.mutate({ url: `/crm/oportunidades/${o!.id}/documentos`, dados: { venda_id: vendaId } })}>
                Ligar documento
              </Button>
            </Flex>
          </Space>
        )}
      </Modal>
    </Drawer>
  );
}

interface FormValores {
  titulo: string;
  conta_crm_id: number;
  contacto_crm_id?: number | null;
  funil_vendas_crm_id: number;
  etapa_codigo?: string;
  valor?: number | null;
  probabilidade?: number | null;
  data_fecho_prevista?: Dayjs | null;
  responsavel?: string;
  origem?: string;
  notas?: string;
  itens?: ItemOportunidade[];
}

/** Criar/editar oportunidade, com linhas opcionais (produto, quantidade, preço, IVA); sem linhas usa-se o valor indicado. */
export function FormOportunidade({ oportunidade, aberto, aoFechar, inicial }: { oportunidade: Oportunidade | null; aberto: boolean; aoFechar: () => void; inicial?: Partial<FormValores> }) {
  const { utilizador } = useSessao();
  const funis = useFunis();
  const config = useConfigCRM();
  const mapaProdutos = useMapaProdutos();
  const [form] = Form.useForm<FormValores>();
  const funilId = Form.useWatch('funil_vendas_crm_id', form);
  const contaId = Form.useWatch('conta_crm_id', form);
  const itens = Form.useWatch('itens', form);
  const contactos = useQuery({ queryKey: ['crm', 'contactos', contaId], queryFn: () => obter<Contacto[]>(`/crm/contas/${contaId}/contactos`), enabled: !!contaId && aberto });
  const accao = useAccao({ invalidar: [CHAVE_CRM], aoSucesso: () => aoFechar() });
  const etapas = funis.data?.find((f) => f.id === funilId)?.etapas ?? [];

  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    if (oportunidade)
      form.setFieldsValue({
        ...oportunidade,
        valor: Number(oportunidade.valor),
        probabilidade: oportunidade.probabilidade === null ? null : Number(oportunidade.probabilidade),
        data_fecho_prevista: oportunidade.data_fecho_prevista ? dayjs(oportunidade.data_fecho_prevista) : null,
        responsavel: oportunidade.responsavel ?? undefined,
        origem: oportunidade.origem_original ?? oportunidade.origem ?? undefined,
        notas: oportunidade.notas ?? undefined,
        itens: (oportunidade.itens ?? []).map((i) => ({ ...i, quantidade: Number(i.quantidade ?? 0), preco: Number(i.preco ?? 0), taxa: Number(i.taxa ?? 0) })),
      });
    else form.setFieldsValue({ responsavel: utilizador?.nome_utilizador, funil_vendas_crm_id: funis.data?.find((f) => f.ativo)?.id, itens: [], ...inicial });
  }, [aberto, oportunidade, form, utilizador, funis.data, inicial]);

  const totalLinhas = totalItens(itens);
  const temLinhas = (itens ?? []).length > 0;

  return (
    <Modal open={aberto} title={oportunidade ? 'Editar oportunidade' : 'Nova oportunidade'} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(860)} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) =>
          accao.mutate({
            metodo: oportunidade ? 'put' : 'post',
            url: oportunidade ? `/crm/oportunidades/${oportunidade.id}` : '/crm/oportunidades',
            dados: { ...v, valor: temLinhas ? totalLinhas : (v.valor ?? 0), data_fecho_prevista: dataApi(v.data_fecho_prevista) ?? null, itens: v.itens ?? [] },
          })
        }
      >
        <Form.Item name="titulo" label="Título" rules={[{ required: true, message: 'Indique o título.' }]}><Input maxLength={255} /></Form.Item>
        <Row gutter={16}>
          <Col xs={24} sm={12}>
            <Form.Item name="conta_crm_id" label="Conta" rules={[{ required: true, message: 'Escolha a conta.' }]}>
              <SeletorContaCRM rotuloInicial={oportunidade?.conta_crm?.nome} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="contacto_crm_id" label="Contacto">
              <Select allowClear loading={contactos.isFetching} options={(contactos.data ?? []).map((c) => ({ value: c.id, label: `${c.nome}${c.cargo ? ` — ${c.cargo}` : ''}` }))} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="funil_vendas_crm_id" label="Funil" rules={[{ required: true }]}>
              <Select options={(funis.data ?? []).filter((f) => f.ativo || f.id === oportunidade?.funil_vendas_crm_id).map((f) => ({ value: f.id, label: f.nome }))} onChange={() => form.setFieldValue('etapa_codigo', undefined)} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="etapa_codigo" label="Etapa" extra={oportunidade ? 'Para mudar de etapa use «Mover etapa».' : 'Por omissão, a primeira.'}>
              <Select allowClear disabled={!!oportunidade} options={etapas.filter((e) => e.tipo === 'ABERTA').map((e) => ({ value: e.id, label: e.nome }))} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="data_fecho_prevista" label="Fecho previsto"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="valor" label="Valor (Kz)" extra={temLinhas ? 'Calculado pelas linhas.' : undefined}>
              <InputNumber min={0} decimalSeparator="," style={{ width: '100%' }} disabled={temLinhas} placeholder={temLinhas ? formatarKz(totalLinhas) : undefined} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="probabilidade" label="Probabilidade (%)" extra="Vazio: a da etapa."><InputNumber min={0} max={100} style={{ width: '100%' }} /></Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="responsavel" label="Responsável"><Input maxLength={100} /></Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="origem" label="Origem"><Select allowClear options={(config.data?.origens ?? []).map((o) => ({ value: o, label: o }))} /></Form.Item>
          </Col>
          <Col xs={24} sm={16}>
            <Form.Item name="notas" label="Notas"><Input.TextArea rows={1} autoSize={{ minRows: 1, maxRows: 4 }} maxLength={4000} /></Form.Item>
          </Col>
        </Row>
        <Card size="small" title="Linhas (opcional)" extra={temLinhas && <strong>Total s/ IVA: {formatarKz(totalLinhas)} Kz</strong>}>
          <Form.List name="itens">
            {(campos, { add, remove }) => (
              <>
                {campos.map((c) => (
                  <Flex key={c.key} gap={8} align="start" wrap>
                    <Form.Item name={[c.name, 'produto_id']} style={{ flex: 2, marginBottom: 8 }}>
                      <SeletorProduto
                        allowClear
                        onChange={(p?: number) => {
                          const prod = p ? mapaProdutos.get(p) : undefined;
                          if (prod) form.setFieldValue(['itens', c.name], { ...form.getFieldValue(['itens', c.name]), produto_id: p, preco: Number(prod.preco_unitario ?? 0), taxa: Number(prod.taxa_imposto ?? 0) });
                          else form.setFieldValue(['itens', c.name, 'produto_id'], p ?? null);
                        }}
                      />
                    </Form.Item>
                    <Form.Item name={[c.name, 'descricao']} style={{ flex: 2, marginBottom: 8 }}><Input placeholder="Descrição" maxLength={1000} /></Form.Item>
                    <Form.Item name={[c.name, 'quantidade']} style={{ width: 90, marginBottom: 8 }}><InputNumber min={0} placeholder="Qtd." decimalSeparator="," style={{ width: '100%' }} /></Form.Item>
                    <Form.Item name={[c.name, 'preco']} style={{ width: 130, marginBottom: 8 }}><InputNumber min={0} placeholder="Preço" decimalSeparator="," style={{ width: '100%' }} /></Form.Item>
                    <Form.Item name={[c.name, 'taxa']} style={{ width: 80, marginBottom: 8 }}><InputNumber min={0} max={100} placeholder="IVA" style={{ width: '100%' }} /></Form.Item>
                    <Button type="text" danger icon={<DeleteOutlined />} aria-label="Remover linha" onClick={() => remove(c.name)} />
                  </Flex>
                ))}
                <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ quantidade: 1, preco: 0, taxa: 14 })}>Linha</Button>
              </>
            )}
          </Form.List>
        </Card>
      </Form>
    </Modal>
  );
}
