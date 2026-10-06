import { Alert, Form, Input, Modal, Select, Typography, message } from 'antd';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { enviar } from '@/api/cliente';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import type { LinhaLancamento } from '../api';
import { SeletorAux, SeletorTerceiro, SeletorUnidade } from '../comum/Seletores';
import { rotuloTerceiro } from '../comum/terceiro';

/** Campos da classificação (A-05). Chave ausente = manter; null = remover. */
export interface CamposClassificacao {
  nota_demonstracao_id?: number | null;
  nota_fluxo_caixa_id?: number | null;
  unidade_negocio_id?: number | null;
  centro_custo_id?: number | null;
  descricao?: string | null;
  terceiro_id?: number | null;
}

export interface ResultadoClassificacao {
  actualizadas: number;
  propagadas: number;
}

/** POST /contabilidade/lancamentos/classificacao — linhas seleccionadas (`ids`) ou todas as filtradas (`filtros`). */
export function pedidoClassificacao(alvo: { ids: number[] } | { filtros: Record<string, unknown> }, campos: CamposClassificacao) {
  return enviar<ResultadoClassificacao>('post', '/contabilidade/lancamentos/classificacao', { ...alvo, campos });
}

/**
 * «Editar classificação» de uma linha (legado: editJournalLine/saveJournalLine). A conta, o valor, o D/C e a data não
 * se alteram (correcção por estorno, ADR-016). Descrição e terceiro exigem `lancamentos_editar`.
 */
export function ModalClassificacao({ linha, aoFechar }: { linha: LinhaLancamento | null; aoFechar: () => void }) {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [form] = Form.useForm<CamposClassificacao>();
  const ficha = pode('lancamentos_editar');
  useEffect(() => {
    if (linha)
      form.setFieldsValue({
        descricao: linha.descricao ?? undefined,
        terceiro_id: linha.terceiro_id ?? undefined,
        nota_demonstracao_id: linha.nota_demonstracao_id ?? undefined,
        nota_fluxo_caixa_id: linha.nota_fluxo_caixa_id ?? undefined,
        unidade_negocio_id: linha.unidade_negocio_id ?? undefined,
        centro_custo_id: linha.centro_custo_id ?? undefined,
      });
  }, [linha, form]);
  const gravar = useMutation({
    mutationFn: (v: CamposClassificacao) => {
      const campos: CamposClassificacao = {
        nota_demonstracao_id: v.nota_demonstracao_id ?? null,
        nota_fluxo_caixa_id: v.nota_fluxo_caixa_id ?? null,
        unidade_negocio_id: v.unidade_negocio_id ?? null,
        centro_custo_id: v.centro_custo_id ?? null,
        ...(ficha ? { descricao: v.descricao?.trim() || null, terceiro_id: v.terceiro_id ?? null } : {}),
      };
      return pedidoClassificacao({ ids: [linha!.id] }, campos);
    },
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      aoFechar();
    },
    onError: (e) => notificarErro(e, 'Não foi possível gravar a classificação'),
  });
  return (
    <Modal
      title="Editar classificação"
      open={!!linha}
      onCancel={aoFechar}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={gravar.isPending}
      onOk={() => form.submit()}
      width={larguraModal(520)}
      destroyOnHidden
    >
      {linha && (
        <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)}>
          <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
            Lançamento <strong>{linha.numero_lan}</strong> · conta <strong>{linha.codigo_conta}</strong> · {linha.tipo_dc === 'D' ? 'débito' : 'crédito'} de{' '}
            <strong>{Number(linha.valor).toLocaleString('pt-PT', { minimumFractionDigits: 2 })} Kz</strong>. A conta, o valor e a data não se alteram aqui
            (use o estorno).
          </Typography.Paragraph>
          {ficha && (
            <>
              <Form.Item name="terceiro_id" label="Terceiro (cliente/fornecedor)">
                <SeletorTerceiro style={{ width: '100%' }} rotuloInicial={linha.terceiro_id ? rotuloTerceiro(linha.terceiro, linha.terceiro_id, true) : undefined} />
              </Form.Item>
              <Form.Item name="descricao" label="Descrição">
                <Input maxLength={1000} />
              </Form.Item>
            </>
          )}
          <Form.Item name="nota_demonstracao_id" label="Nota às demonstrações financeiras">
            <SeletorAux tabela="notas-demonstracao" placeholder="— Sem nota —" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="nota_fluxo_caixa_id" label="Nota ao fluxo de caixa">
            <SeletorAux tabela="notas-fluxo-caixa" placeholder="— Sem nota —" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="unidade_negocio_id" label="Unidade de negócio">
            <SeletorUnidade style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="centro_custo_id" label="Centro de custo">
            <SeletorAux tabela="centros-custo" placeholder="— Sem centro de custo —" style={{ width: '100%' }} />
          </Form.Item>
          {(linha.estorno_de_id || linha.estornado_por_id) && (
            <Alert type="info" showIcon message="A classificação também é aplicada à linha correspondente do estorno/original, para os mapas ficarem coerentes." />
          )}
        </Form>
      )}
    </Modal>
  );
}

/**
 * «Transferir lançamento para outra empresa» (M-09; legado: transferJournalEntry). Cria o lançamento na empresa
 * destino e estorna o original (com rasto nos dois lados), numa só operação no servidor.
 */
export function ModalTransferir({ aberto, linhaId, numeroLan, aoFechar, aoConcluir }: { aberto: boolean; linhaId: number; numeroLan: string; aoFechar: () => void; aoConcluir?: (estornoId: number) => void }) {
  const { empresas, empresa } = useSessao();
  const cliente = useQueryClient();
  const [form] = Form.useForm<{ empresa_destino_id: number; motivo: string }>();
  const destinos = empresas.filter((e) => e.id !== empresa?.id);
  const transferir = useMutation({
    mutationFn: (v: { empresa_destino_id: number; motivo: string }) =>
      enviar<{ destino: { numero_lan: string }; estorno: { primeira_linha_id: number }; avisos: string[] }>('post', `/contabilidade/lancamentos/${linhaId}/transferir`, v),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      if (dados.avisos.length) message.warning({ content: dados.avisos.join(' '), duration: 10 });
      void cliente.invalidateQueries({ queryKey: ['contab'] });
      form.resetFields();
      aoFechar();
      aoConcluir?.(dados.estorno.primeira_linha_id);
    },
    onError: (e) => notificarErro(e, 'Não foi possível transferir o lançamento'),
  });
  return (
    <Modal
      title="Transferir lançamento"
      open={aberto}
      onCancel={aoFechar}
      okText="Transferir"
      cancelText="Cancelar"
      confirmLoading={transferir.isPending}
      onOk={() => form.submit()}
      okButtonProps={{ disabled: !destinos.length }}
      width={larguraModal(480)}
    >
      <Typography.Paragraph>
        Documento: <strong>{numeroLan}</strong>
      </Typography.Paragraph>
      <Typography.Paragraph type="secondary">
        O lançamento é criado na empresa seleccionada com as mesmas contas, valores e datas (diário pelo código, terceiros pelo NIF) e o original é
        estornado nesta empresa, com referência cruzada.
      </Typography.Paragraph>
      {!destinos.length ? (
        <Alert type="warning" showIcon message="Não tem acesso a outras empresas para realizar a transferência." />
      ) : (
        <Form form={form} layout="vertical" onFinish={(v) => transferir.mutate(v)}>
          <Form.Item name="empresa_destino_id" label="Empresa destino" rules={[{ required: true, message: 'Escolha a empresa destino.' }]}>
            <Select showSearch optionFilterProp="label" options={destinos.map((e) => ({ value: e.id, label: e.nome }))} />
          </Form.Item>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}>
            <Input.TextArea rows={2} maxLength={300} />
          </Form.Item>
        </Form>
      )}
    </Modal>
  );
}
