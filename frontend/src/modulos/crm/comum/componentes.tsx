import { Alert, Button, Checkbox, DatePicker, Form, Input, List, Modal, Popconfirm, Select, Space, Tag, Tooltip, Typography, message } from 'antd';
import { CheckOutlined, DeleteOutlined, EditOutlined, MailOutlined, RollbackOutlined } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState, type ReactNode } from 'react';
import { enviar } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { CHAVE_CRM, useConfigCRM, useContasPesquisa, useModelosEmail } from './dados';
import { NIVEIS_SAUDE, abrirMailto, exigeMotivo, type Actividade, type Envio, type Etapa, type MensagemEmail, type Saude } from './tipos';

export function TagSaude({ saude }: { saude: Saude | null | undefined }) {
  if (!saude) return null;
  const n = NIVEIS_SAUDE[saude.nivel] ?? { cor: 'default', rotulo: saude.nivel };
  return (
    <Tooltip title={saude.motivos.length ? saude.motivos.join(' · ') : undefined}>
      <Tag color={n.cor}>{n.rotulo}</Tag>
    </Tooltip>
  );
}

/** Pesquisa de contas do CRM (prospects e clientes). */
export function SeletorContaCRM({ value, onChange, rotuloInicial, disabled }: { value?: number; onChange?: (v?: number) => void; rotuloInicial?: string; disabled?: boolean }) {
  const [pesquisa, setPesquisa] = useState('');
  const q = useContasPesquisa(pesquisa);
  const opcoes = (q.data?.itens ?? []).map((c) => ({ value: c.id, label: `${c.nome}${c.tipo === 'PROSPECT' ? ' (prospect)' : ''}` }));
  if (value && rotuloInicial && !opcoes.some((o) => o.value === value)) opcoes.unshift({ value, label: rotuloInicial });
  return (
    <Select<number>
      showSearch
      allowClear
      filterOption={false}
      onSearch={setPesquisa}
      loading={q.isFetching}
      value={value}
      onChange={onChange}
      disabled={disabled}
      placeholder="Pesquisar conta (nome, NIF, email)"
      options={opcoes}
    />
  );
}

/** Mudar a etapa de uma oportunidade; numa etapa de perda pede o motivo (obrigatório no servidor), o concorrente e notas. */
export function ModalMoverEtapa({
  oportunidade,
  etapa,
  aoFechar,
}: {
  oportunidade: { id: number; titulo: string } | null;
  etapa: Etapa | undefined;
  aoFechar: () => void;
}) {
  const config = useConfigCRM();
  const [form] = Form.useForm<{ motivo_perda?: string; concorrente?: string; notas_perda?: string }>();
  const accao = useAccao<{ tarefas: number }>({ invalidar: [CHAVE_CRM], aoSucesso: () => aoFechar(), tituloErro: 'Não foi possível mudar a etapa' });
  useEffect(() => {
    if (oportunidade) form.resetFields();
  }, [oportunidade, form]);
  const perda = exigeMotivo(etapa);
  return (
    <Modal
      open={!!oportunidade && !!etapa}
      title={`Mover para «${etapa?.nome ?? ''}»`}
      onCancel={aoFechar}
      okText="Mover"
      cancelText="Cancelar"
      okButtonProps={{ danger: perda }}
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      destroyOnClose
    >
      <Typography.Paragraph>{oportunidade?.titulo}</Typography.Paragraph>
      {etapa?.tipo === 'GANHA' && <Alert type="success" showIcon style={{ marginBottom: 12 }} message="A oportunidade fica ganha. Pode depois convertê-la em documento de venda." />}
      {!!etapa?.tarefas?.length && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`Esta etapa cria ${etapa.tarefas.length} tarefa(s) automática(s).`} />}
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/crm/oportunidades/${oportunidade!.id}/etapa`, dados: { etapa_codigo: etapa!.id, ...v } })}>
        {perda && (
          <>
            <Form.Item name="motivo_perda" label="Motivo da perda" rules={[{ required: true, message: 'Indique o motivo da perda.' }]}>
              <Select showSearch options={(config.data?.motivos_perda ?? []).map((m) => ({ value: m, label: m }))} placeholder="Escolha o motivo" />
            </Form.Item>
            <Form.Item name="concorrente" label="Concorrente (se perdida para outro)">
              <Input maxLength={255} />
            </Form.Item>
            <Form.Item name="notas_perda" label="Notas">
              <Input.TextArea rows={3} maxLength={2000} />
            </Form.Item>
          </>
        )}
      </Form>
    </Modal>
  );
}

interface FormActividade {
  tipo: string;
  titulo?: string;
  descricao?: string;
  data_prevista?: Dayjs | null;
  responsavel?: string;
  concluida?: boolean;
}

/** Criar/editar uma actividade comercial (chamada, email, reunião, tarefa, nota). */
export function ModalActividade({
  actividade,
  contexto,
  aoFechar,
}: {
  actividade: Actividade | 'nova' | null;
  contexto?: { oportunidade_crm_id?: number | null; conta_crm_id?: number | null; contacto_crm_id?: number | null };
  aoFechar: () => void;
}) {
  const config = useConfigCRM();
  const { utilizador } = useSessao();
  const [form] = Form.useForm<FormActividade>();
  const accao = useAccao({ invalidar: [CHAVE_CRM], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!actividade) return;
    form.resetFields();
    if (actividade === 'nova') form.setFieldsValue({ tipo: 'TAREFA', data_prevista: dayjs(), responsavel: utilizador?.nome_utilizador });
    else form.setFieldsValue({ ...actividade, titulo: actividade.titulo ?? undefined, descricao: actividade.descricao ?? undefined, responsavel: actividade.responsavel ?? undefined, data_prevista: actividade.data_prevista ? dayjs(actividade.data_prevista) : null, concluida: !!actividade.concluida });
  }, [actividade, form, utilizador]);
  return (
    <Modal open={!!actividade} title={actividade === 'nova' ? 'Nova actividade' : 'Editar actividade'} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnClose>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const base = actividade === 'nova' ? contexto ?? {} : { oportunidade_crm_id: actividade!.oportunidade_crm_id, conta_crm_id: actividade!.conta_crm_id, contacto_crm_id: actividade!.contacto_crm_id };
          accao.mutate({
            metodo: actividade === 'nova' ? 'post' : 'put',
            url: actividade === 'nova' ? '/crm/atividades' : `/crm/atividades/${actividade!.id}`,
            dados: { ...base, ...v, data_prevista: dataApi(v.data_prevista) ?? null },
          });
        }}
      >
        <Space size={16} wrap>
          <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
            <Select style={{ width: 180 }} options={Object.entries(config.data?.tipos_atividade ?? {}).map(([value, label]) => ({ value, label }))} />
          </Form.Item>
          <Form.Item name="data_prevista" label="Data prevista">
            <DatePicker format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item name="responsavel" label="Responsável">
            <Input maxLength={100} style={{ width: 160 }} />
          </Form.Item>
        </Space>
        <Form.Item name="titulo" label="Título">
          <Input maxLength={255} />
        </Form.Item>
        <Form.Item name="descricao" label="Descrição">
          <Input.TextArea rows={3} maxLength={4000} />
        </Form.Item>
        <Form.Item name="concluida" valuePropName="checked">
          <Checkbox>Já concluída</Checkbox>
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Lista de actividades com concluir/reabrir, editar e eliminar. */
export function ListaActividades({ actividades, podeEditar, aoEditar, vazio = 'Sem actividades.' }: { actividades: Actividade[]; podeEditar: boolean; aoEditar: (a: Actividade) => void; vazio?: ReactNode }) {
  const config = useConfigCRM();
  const accao = useAccao({ invalidar: [CHAVE_CRM] });
  const hoje = dayjs().format('YYYY-MM-DD');
  return (
    <List
      size="small"
      dataSource={actividades}
      locale={{ emptyText: vazio }}
      renderItem={(a) => {
        const vencida = !a.concluida && !!a.data_prevista && a.data_prevista.slice(0, 10) < hoje;
        return (
          <List.Item
            actions={
              podeEditar
                ? [
                    a.concluida ? (
                      <Button key="r" size="small" type="text" icon={<RollbackOutlined />} title="Reabrir" aria-label="Reabrir" onClick={() => accao.mutate({ url: `/crm/atividades/${a.id}/reabrir` })} />
                    ) : (
                      <Button key="c" size="small" type="text" icon={<CheckOutlined />} title="Concluir" aria-label="Concluir" onClick={() => accao.mutate({ url: `/crm/atividades/${a.id}/concluir`, dados: {} })} />
                    ),
                    <Button key="e" size="small" type="text" icon={<EditOutlined />} title="Editar" aria-label="Editar" onClick={() => aoEditar(a)} />,
                    <Popconfirm key="d" title="Eliminar a actividade?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/crm/atividades/${a.id}` })}>
                      <Button size="small" type="text" danger icon={<DeleteOutlined />} title="Eliminar" aria-label="Eliminar" />
                    </Popconfirm>,
                  ]
                : []
            }
          >
            <List.Item.Meta
              title={
                <Space size={6} wrap>
                  <Tag>{config.data?.tipos_atividade[a.tipo] ?? a.tipo}</Tag>
                  <Typography.Text delete={!!a.concluida}>{a.titulo ?? '(sem título)'}</Typography.Text>
                  {vencida && <Tag color="red">Em atraso</Tag>}
                  {a.automatica && <Tag color="purple">automática</Tag>}
                </Space>
              }
              description={
                <>
                  {formatarData(a.data_prevista)} · {a.responsavel ?? '—'}
                  {a.conta_crm && ` · ${a.conta_crm.nome}`}
                  {a.oportunidade_crm && ` · ${a.oportunidade_crm.titulo}`}
                  {a.resultado && ` · ${a.resultado}`}
                  {a.descricao && <div>{a.descricao}</div>}
                </>
              }
            />
          </List.Item>
        );
      }}
    />
  );
}

/**
 * Preparar e registar um email a partir de um modelo (POST /crm/emails/preparar e /crm/emails). O envio real não existe no
 * servidor: o email fica no histórico e abre-se no programa de email do utilizador (mailto devolvido pela API).
 */
export function ModalEmail({ contexto, aoFechar }: { contexto: { conta_crm_id?: number | null; contacto_crm_id?: number | null; oportunidade_crm_id?: number | null } | null; aoFechar: () => void }) {
  const modelos = useModelosEmail();
  const cliente = useQueryClient();
  const [form] = Form.useForm<{ modelo_email_crm_id?: number; para: string; assunto: string; corpo: string }>();
  useEffect(() => {
    if (contexto) form.resetFields();
  }, [contexto, form]);
  const preparar = useMutation({
    mutationFn: (modelo?: number) => enviar<MensagemEmail>('post', '/crm/emails/preparar', { ...contexto, modelo_email_crm_id: modelo }),
    onSuccess: ({ dados }) => form.setFieldsValue({ para: dados.para, assunto: dados.assunto, corpo: dados.corpo }),
    onError: (e) => notificarErro(e),
  });
  const registar = useMutation({
    mutationFn: (v: { modelo_email_crm_id?: number; para: string; assunto: string; corpo: string }) => enviar<{ envio: Envio }>('post', '/crm/emails', { ...contexto, ...v }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      abrirMailto(dados.envio);
      void cliente.invalidateQueries({ queryKey: CHAVE_CRM });
      aoFechar();
    },
    onError: (e) => notificarErro(e),
  });
  return (
    <Modal open={!!contexto} title="Enviar email" onCancel={aoFechar} okText="Registar e abrir no email" cancelText="Cancelar" okButtonProps={{ icon: <MailOutlined /> }} confirmLoading={registar.isPending} onOk={() => form.submit()} width={680} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => registar.mutate(v)}>
        <Form.Item name="modelo_email_crm_id" label="Modelo">
          <Select allowClear loading={modelos.isLoading || preparar.isPending} options={(modelos.data ?? []).map((m) => ({ value: m.id, label: m.nome }))} onChange={(m?: number) => preparar.mutate(m)} placeholder="Escolha um modelo para preencher" />
        </Form.Item>
        <Form.Item name="para" label="Para" rules={[{ required: true, message: 'Indique o destinatário.' }]}>
          <Input maxLength={150} />
        </Form.Item>
        <Form.Item name="assunto" label="Assunto" rules={[{ required: true, message: 'Indique o assunto.' }]}>
          <Input maxLength={255} />
        </Form.Item>
        <Form.Item name="corpo" label="Mensagem">
          <Input.TextArea rows={8} maxLength={20000} />
        </Form.Item>
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          O email fica registado no histórico da conta e abre-se no seu programa de email para enviar.
        </Typography.Text>
      </Form>
    </Modal>
  );
}
