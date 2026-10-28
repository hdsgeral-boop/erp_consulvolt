import { Button, Card, Popconfirm, Table, Tag, Typography, message } from 'antd';
import { BankOutlined, CheckCircleOutlined, DownloadOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { descarregar, enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';

interface TransferenciaPendente {
  subscricao_id: number; referencia: string; valor: number; moeda: string; meses: number;
  empresa_id: number; empresa_nome: string; tenant_nome: string | null;
  transacao_id: number; pedido_em: string;
  anexo_id: number | null; anexo_nome: string | null; anexo_em: string | null;
}

const CHAVE = ['plataforma', 'transferencias', 'pendentes'];

/**
 * Secção de PlataformaSaasGestao: comprovativos de transferência bancária da subscrição SaaS (motor de
 * precificação, Módulo C/D) à espera de validação manual pelo super-administrador da plataforma.
 */
export function TransferenciasBancariasPendentes() {
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({ queryKey: CHAVE, queryFn: () => obter<TransferenciaPendente[]>('/plataforma/transferencias/pendentes') });
  const confirmar = useMutation({
    mutationFn: (id: number) => enviar('post', `/plataforma/transferencias/${id}/confirmar`, {}),
    onSuccess: () => { message.success('Pagamento confirmado. Licença prolongada.'); qc.invalidateQueries({ queryKey: CHAVE }); },
    onError: (e) => notificarErro(e),
  });
  const baixarComprovativo = (t: TransferenciaPendente) => {
    if (! t.anexo_id) return;
    descarregar(`/plataforma/transferencias/comprovativo/${t.anexo_id}`, undefined, t.anexo_nome ?? `comprovativo-${t.anexo_id}`).catch((e) => notificarErro(e));
  };

  return (
    <Card title={<><BankOutlined /> Transferências bancárias pendentes de validação</>} style={{ marginTop: 20 }} loading={isLoading}>
      <Table<TransferenciaPendente> rowKey="subscricao_id" dataSource={data ?? []} pagination={{ pageSize: 10 }}
        locale={{ emptyText: 'Nenhuma transferência bancária à espera de validação.' }}
        columns={[
          { title: 'Cliente', render: (_, t) => <>{t.tenant_nome ?? '—'}<br /><Typography.Text type="secondary" style={{ fontSize: 12 }}>{t.empresa_nome}</Typography.Text></> },
          { title: 'Referência', dataIndex: 'referencia', render: (v) => <Tag color="geekblue">{v}</Tag> },
          { title: 'Valor', align: 'right', render: (_, t) => `${formatarNumero(t.valor)} ${t.moeda}` },
          { title: 'Duração', render: (_, t) => `${t.meses} mês(es)` },
          { title: 'Pedido em', render: (_, t) => formatarDataHora(t.pedido_em) },
          {
            title: 'Comprovativo',
            render: (_, t) => t.anexo_id
              ? <Button size="small" icon={<DownloadOutlined />} onClick={() => baixarComprovativo(t)}>{t.anexo_nome ?? 'Descarregar'}</Button>
              : <Tag color="warning">Sem anexo</Tag>,
          },
          {
            title: '', align: 'right',
            render: (_, t) => (
              <Popconfirm title="Confirmar este pagamento?" description="A licença é prolongada de imediato e a factura AGT é emitida automaticamente (se configurada)."
                onConfirm={() => confirmar.mutate(t.subscricao_id)}>
                <Button type="primary" size="small" icon={<CheckCircleOutlined />} loading={confirmar.isPending}>Confirmar pagamento</Button>
              </Popconfirm>
            ),
          },
        ]} />
    </Card>
  );
}
