import { Alert, Button, Form, Input, Modal, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { useMensagem } from '@/componentes/impressao';
import { larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import {
  COR_ESTADO_PEDIDO,
  MOTIVO_MAXIMO,
  PERMISSOES_PEDIDO,
  ROTULO_ESTADO_PEDIDO,
  corpoPedido,
  opcoesAprovacaoNoActo,
  podePrepararPedido,
  validarMotivo,
  type AlertaOrcamental,
  type ContextoExcesso,
  type DadosExcesso,
  type OpcoesOrcamento,
} from './excesso';

/** Pedido de excesso devolvido pelo servidor (subconjunto do modelo PedidoExtrapolacaoOrcamento). */
interface PedidoCriado {
  id: number;
  estado: string;
  documento: string | null;
  chave_documento?: string | null;
  valor: string | null;
  valor_excesso: string | null;
  pedido_em?: string | null;
  decidido_por?: string | null;
  nota_decisao?: string | null;
  rubrica?: { codigo?: string | null; nome?: string | null } | null;
  rubrica_orcamental_id?: number | null;
}

export interface PropsDialogoExcesso {
  dados: DadosExcesso;
  /** Tipo, data e linhas do documento, quando o ecrã os conhece (usados se o servidor não os devolver). */
  contexto?: ContextoExcesso;
  /** Repete a gravação; com opções = «aprovar no acto», sem opções = gravar de novo depois de aprovado. */
  repetir?: (opcoes?: OpcoesOrcamento) => void;
  aoFechar: () => void;
}

const formatarPct = (v: number | null) => (v === null ? '—' : `${v.toLocaleString('pt-PT', { maximumFractionDigits: 2 })} %`);

/**
 * Diálogo comum do excesso orçamental (A-02): mostra as rubricas em excesso, pede a justificação e permite pedir a
 * aprovação (POST /orcamento/pedidos-excesso) ou, a quem tem «Aprovar excesso», aprovar no acto e gravar.
 */
export function DialogoExcesso({ dados, contexto, repetir, aoFechar }: PropsDialogoExcesso) {
  const { pode } = useSessao();
  const pequeno = useEcraPequeno();
  const message = useMensagem();
  const [form] = Form.useForm<{ motivo: string }>();
  const [aEnviar, setAEnviar] = useState(false);
  const [pedidos, setPedidos] = useState<PedidoCriado[] | null>(null);
  const [aVerificar, setAVerificar] = useState(false);

  const podeAprovar = !!repetir && pode('orc_aprovar_excesso');
  const podePedir = pode(...PERMISSOES_PEDIDO);
  const podeVerEstado = pode('orc_alertas_view', 'orc_aprovar_excesso');
  const temDadosPedido = podePrepararPedido(dados, contexto);
  const linhas = dados.pendentes.length ? dados.pendentes : dados.alertas;

  const motivoValido = async (): Promise<string | null> => {
    try {
      const v = await form.validateFields();
      return v.motivo.trim();
    } catch {
      return null;
    }
  };

  const pedir = async () => {
    const motivo = await motivoValido();
    if (motivo === null) return;
    const corpo = corpoPedido(dados, contexto, motivo);
    if (!corpo) return;
    setAEnviar(true);
    try {
      const r = await enviar<PedidoCriado[]>('post', '/orcamento/pedidos-excesso', corpo);
      message.success(r.mensagem);
      setPedidos(Array.isArray(r.dados) ? r.dados : []);
    } catch (e) {
      notificarErro(e, 'Não foi possível pedir a aprovação do excesso');
    } finally {
      setAEnviar(false);
    }
  };

  const aprovarNoActo = async () => {
    const motivo = await motivoValido();
    if (motivo === null || !repetir) return;
    aoFechar();
    repetir(opcoesAprovacaoNoActo(motivo));
  };

  const verificarEstado = async () => {
    if (!dados.chave) return;
    setAVerificar(true);
    try {
      const todos = await obter<PedidoCriado[]>('/orcamento/pedidos-excesso');
      const meus = todos.filter((p) => p.chave_documento === dados.chave);
      setPedidos(meus.length ? meus.slice(0, 20) : pedidos);
    } catch (e) {
      notificarErro(e, 'Não foi possível consultar o estado do pedido');
    } finally {
      setAVerificar(false);
    }
  };

  const aprovados = (pedidos ?? []).length > 0 && (pedidos ?? []).every((p) => p.estado === 'APROVADO');

  const rodape = pedidos ? (
    <Space wrap>
      {podeVerEstado && dados.chave && <Button loading={aVerificar} onClick={() => void verificarEstado()}>Verificar estado</Button>}
      {repetir && (
        <Button type={aprovados ? 'primary' : 'default'} onClick={() => { aoFechar(); repetir(); }}>
          Gravar de novo
        </Button>
      )}
      <Button type={aprovados || !repetir ? 'default' : 'primary'} onClick={aoFechar}>Fechar</Button>
    </Space>
  ) : (
    <Space wrap>
      <Button onClick={aoFechar}>Cancelar</Button>
      {podePedir && temDadosPedido && (
        <Button type={podeAprovar ? 'default' : 'primary'} loading={aEnviar} onClick={() => void pedir()}>
          Pedir aprovação
        </Button>
      )}
      {podeAprovar && (
        <Button type="primary" danger onClick={() => void aprovarNoActo()}>
          Aprovar excesso e gravar
        </Button>
      )}
    </Space>
  );

  return (
    <Modal open title="Excesso orçamental — aprovação necessária" width={larguraModal(860)} onCancel={aoFechar} footer={rodape} destroyOnHidden maskClosable={false}>
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={dados.mensagem} description={dados.documento ? `Documento: ${dados.documento}${dados.origem ? ` (${dados.origem.toLowerCase().replace(/_/g, ' ')})` : ''}` : undefined} />
      <Table<AlertaOrcamental>
        rowKey={(a) => `${a.orcamento_anual_id}-${a.rubrica_orcamental_id}`}
        size={pequeno ? 'small' : 'middle'}
        pagination={false}
        scroll={scrollTabela()}
        dataSource={linhas}
        locale={{ emptyText: 'O servidor não indicou as rubricas em excesso.' }}
        columns={[
          { title: 'Rubrica', dataIndex: 'rubrica', render: (v: string, a) => <><strong>{v}</strong><div style={{ fontSize: 12, opacity: 0.7 }}>{a.orcamento}</div></> },
          { title: 'Orçado', dataIndex: 'orcado', align: 'right', responsive: ['md'], render: (v: number) => formatarKz(v) },
          { title: 'Consumido', dataIndex: 'consumido', align: 'right', responsive: ['md'], render: (v: number) => formatarKz(v) },
          { title: 'Este documento', dataIndex: 'documento', align: 'right', render: (v: number) => formatarKz(v) },
          { title: 'Execução', dataIndex: 'percentagem', align: 'right', responsive: ['sm'], render: (v: number | null) => formatarPct(v) },
          { title: 'Excesso', dataIndex: 'excesso', align: 'right', render: (v: number) => <Typography.Text type="danger" strong>{formatarKz(v)}</Typography.Text> },
        ]}
      />

      {pedidos ? (
        <div style={{ marginTop: 16 }}>
          <Alert
            type={aprovados ? 'success' : 'info'}
            showIcon
            message={aprovados ? 'Excesso aprovado: já pode gravar o documento.' : 'Pedido de aprovação enviado.'}
            description={
              aprovados
                ? 'Carregue em «Gravar de novo»: a gravação usa a aprovação (o formulário mantém os dados).'
                : 'Outro utilizador com a permissão «Aprovar excesso» decide em Orçamento › Alertas e aprovações. Depois de aprovado, volte a gravar este documento sem alterar os valores.'
            }
          />
          <Table<PedidoCriado>
            style={{ marginTop: 12 }}
            rowKey="id"
            size="small"
            pagination={false}
            scroll={scrollTabela()}
            dataSource={pedidos}
            columns={[
              { title: 'Pedido', dataIndex: 'id', render: (v: number) => `#${v}` },
              { title: 'Rubrica', render: (_, p) => (p.rubrica ? `${p.rubrica.codigo ?? ''} ${p.rubrica.nome ?? ''}`.trim() : p.rubrica_orcamental_id ? `#${p.rubrica_orcamental_id}` : '—') },
              { title: 'Excesso', dataIndex: 'valor_excesso', align: 'right', render: (v: string | null) => formatarKz(v) },
              { title: 'Pedido em', dataIndex: 'pedido_em', responsive: ['md'], render: (v: string | null) => formatarDataHora(v ?? null) },
              {
                title: 'Estado',
                dataIndex: 'estado',
                render: (v: string, p) => (
                  <>
                    <Tag color={COR_ESTADO_PEDIDO[v] ?? 'default'}>{ROTULO_ESTADO_PEDIDO[v] ?? v}</Tag>
                    {p.nota_decisao && <div style={{ fontSize: 12 }}>{p.decidido_por ? `${p.decidido_por}: ` : ''}{p.nota_decisao}</div>}
                  </>
                ),
              },
            ]}
          />
        </div>
      ) : (
        <Form form={form} layout="vertical" style={{ marginTop: 16 }}>
          <Form.Item
            name="motivo"
            label="Justificação do excesso"
            rules={[{ validator: (_, v: string | undefined) => { const erro = validarMotivo(v); return erro ? Promise.reject(new Error(erro)) : Promise.resolve(); } }]}
          >
            <Input.TextArea rows={3} maxLength={MOTIVO_MAXIMO} showCount autoFocus placeholder="Porque é que esta despesa tem de ser feita acima do orçamento?" />
          </Form.Item>
          {!temDadosPedido && !podeAprovar && (
            <Alert
              type="info"
              showIcon
              message="O pedido não pode ser preparado a partir deste ecrã."
              description="Peça a um utilizador com a permissão «Aprovar excesso orçamental» que grave o documento (aprovação no acto), ou registe o pedido em Orçamento › Alertas e aprovações."
            />
          )}
          {!podePedir && temDadosPedido && !podeAprovar && <Alert type="info" showIcon message="Não tem permissão para pedir a aprovação do excesso." />}
          {podeAprovar && (
            <Typography.Paragraph type="secondary" style={{ marginBottom: 0 }}>
              Tem a permissão «Aprovar excesso»: pode aprovar já e gravar (fica registado como aprovado no acto, com esta justificação).
            </Typography.Paragraph>
          )}
        </Form>
      )}
    </Modal>
  );
}
