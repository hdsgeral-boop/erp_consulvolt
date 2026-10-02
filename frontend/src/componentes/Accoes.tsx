import { Form, Input, Modal, message } from 'antd';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { enviar } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';

export interface PedidoAccao {
  metodo?: 'post' | 'put' | 'delete';
  url: string;
  dados?: unknown;
}

/**
 * Mutação padrão dos ecrãs: envia o pedido, mostra a mensagem do servidor, invalida as consultas indicadas
 * (chaves de prefixo) e notifica o erro com os detalhes devolvidos pela API.
 */
export function useAccao<T = unknown>(opcoes: {
  invalidar: unknown[][];
  aoSucesso?: (dados: T, pedido: PedidoAccao) => void;
  tituloErro?: string;
  /** Tratamento próprio de certos erros (ex.: excesso orçamental); devolve true se tratou e não há notificação. */
  aoErro?: (e: unknown, pedido: PedidoAccao) => boolean;
}) {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: ({ metodo = 'post', url, dados }: PedidoAccao) => enviar<T>(metodo, url, dados),
    onSuccess: ({ dados, mensagem }, pedido) => {
      message.success(mensagem);
      opcoes.invalidar.forEach((chave) => void cliente.invalidateQueries({ queryKey: chave }));
      opcoes.aoSucesso?.(dados, pedido);
    },
    onError: (e, pedido) => {
      if (opcoes.aoErro?.(e, pedido)) return;
      notificarErro(e, opcoes.tituloErro);
    },
  });
}

/** Modal que pede um motivo (o servidor exige 5 a 500 caracteres em anulações, estornos e cancelamentos). */
export function ModalMotivo({
  aberto,
  titulo,
  textoOk = 'Confirmar',
  aviso,
  carregando,
  aoConfirmar,
  aoFechar,
}: {
  aberto: boolean;
  titulo: string;
  textoOk?: string;
  aviso?: string;
  carregando?: boolean;
  aoConfirmar: (motivo: string) => void;
  aoFechar: () => void;
}) {
  const [form] = Form.useForm<{ motivo: string }>();
  useEffect(() => {
    if (aberto) form.resetFields();
  }, [aberto, form]);
  return (
    <Modal
      title={titulo}
      open={aberto}
      onCancel={aoFechar}
      okText={textoOk}
      cancelText="Cancelar"
      okButtonProps={{ danger: true }}
      confirmLoading={carregando}
      onOk={() => form.submit()}
      destroyOnHidden
    >
      <Form form={form} layout="vertical" onFinish={(v) => aoConfirmar(v.motivo.trim())}>
        <Form.Item
          name="motivo"
          label="Motivo"
          rules={[
            { required: true, message: 'Indique o motivo.' },
            { min: 5, message: 'O motivo deve ter pelo menos 5 caracteres.' },
          ]}
        >
          <Input.TextArea rows={3} maxLength={500} showCount autoFocus />
        </Form.Item>
        {aviso && <div style={{ color: 'rgba(0,0,0,0.55)' }}>{aviso}</div>}
      </Form>
    </Modal>
  );
}
