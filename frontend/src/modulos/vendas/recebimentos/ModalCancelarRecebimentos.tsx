import { Alert, Form, Input, List, Modal, Skeleton, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import { linhasACancelar, outrasFacturasAfectadas, type RecebimentosFatura } from './regrasRecebimentos';

/**
 * «Factura paga — cancelar o recebimento» antes da nota de crédito (AnularDocs.exigirRecebimentoCancelado do legado
 * novo). Mostra os recibos e os documentos de tesouraria que liquidam a factura, explica o que acontece (ficam ANULADOS,
 * com estorno se contabilizados; as outras facturas do mesmo recibo voltam a pendentes), pede o motivo e confirma.
 * Depois do cancelamento chama `aoConcluir` para o ecrã continuar a emissão da NC.
 */
export function ModalCancelarRecebimentos({ vendaId, aoFechar, aoConcluir }: {
  vendaId: number | null;
  aoFechar: () => void;
  aoConcluir?: () => void;
}) {
  const cliente = useQueryClient();
  const [form] = Form.useForm<{ motivo: string }>();
  const consulta = useQuery({
    queryKey: ['vendas', 'documento', String(vendaId), 'recebimentos'],
    queryFn: () => obter<RecebimentosFatura>(`/vendas/documentos/${vendaId}/recebimentos`),
    enabled: vendaId !== null,
    staleTime: 0,
  });
  const cancelar = useMutation({
    mutationFn: (motivo: string) => enviar<unknown>('post', `/vendas/documentos/${vendaId}/cancelar-recebimentos`, { motivo }),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['vendas'] });
      void cliente.invalidateQueries({ queryKey: ['tesouraria'] });
      aoFechar();
      aoConcluir?.();
    },
    onError: (e) => notificarErro(e, 'Não foi possível cancelar o recebimento'),
  });
  useEffect(() => {
    if (vendaId !== null) form.resetFields();
  }, [vendaId, form]);

  const p = consulta.data;
  const outras = p ? outrasFacturasAfectadas(p) : [];
  const podeAvancar = !!p && p.pode_cancelar && p.permissoes_em_falta.length === 0;

  return (
    <Modal
      title={p ? `Factura paga — cancelar o recebimento de ${p.venda.numero_documento}` : 'Factura paga — cancelar o recebimento'}
      open={vendaId !== null}
      onCancel={aoFechar}
      okText="Cancelar recebimento e continuar"
      cancelText="Voltar"
      okButtonProps={{ danger: true, disabled: !podeAvancar }}
      confirmLoading={cancelar.isPending}
      onOk={() => form.submit()}
      destroyOnHidden
    >
      {consulta.isLoading || !p ? <Skeleton active /> : (
        <>
          <Typography.Paragraph>
            A factura <strong>{p.venda.numero_documento}</strong> está liquidada em <strong>{formatarKz(p.venda.valor_pago)} Kz</strong>. A nota de crédito
            não avança sem o recebimento cancelado. Os documentos abaixo ficam <strong>ANULADOS</strong> (não são apagados), com o motivo, o autor e a data
            {p.com_estorno ? '; os que estão contabilizados são estornados no Diário' : ''}.
          </Typography.Paragraph>
          {(p.recibos.length > 0 || p.tesouraria.length > 0) && (
            <List size="small" bordered style={{ marginBottom: 12 }} dataSource={linhasACancelar(p, (v) => `${formatarKz(v)} Kz`)} renderItem={(x) => <List.Item>{x}</List.Item>} />
          )}
          {outras.length > 0 && (
            <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Outras facturas afectadas"
              description={`O recibo/documento é anulado por inteiro: ${outras.join(', ')} também volta(m) a ter o valor por receber.`} />
          )}
          {p.bloqueios.length > 0 && (
            <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Não é possível cancelar daqui"
              description={<ul style={{ margin: 0, paddingLeft: 18 }}>{p.bloqueios.map((b) => <li key={b}>{b}</li>)}</ul>} />
          )}
          {p.permissoes_em_falta.length > 0 && (
            <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Sem permissão" description={`Falta-lhe permissão para: ${p.permissoes_em_falta.join(', ')}.`} />
          )}
          <Form form={form} layout="vertical" disabled={!podeAvancar} onFinish={(v) => cancelar.mutate(v.motivo.trim())}>
            <Form.Item
              name="motivo"
              label="Motivo do cancelamento"
              rules={[{ required: true, message: 'Indique o motivo.' }, { min: 5, message: 'O motivo deve ter pelo menos 5 caracteres.' }]}
            >
              <Input.TextArea rows={3} maxLength={400} showCount placeholder="Ex.: cliente devolveu a mercadoria; o valor é devolvido por transferência" />
            </Form.Item>
          </Form>
        </>
      )}
    </Modal>
  );
}
