import { Alert, Form, Modal, Skeleton } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { SeletorConta } from './Seletores';
import { larguraModal } from '@/componentes/responsivo';

export type ConfigContas = Record<string, { descricao: string; codigo_conta: string | null }>;

/** Corpo do PUT de configuração de contas: chaves sem conta vão como null (limpa a configuração). */
export function corpoContas(valores: Record<string, string | null | undefined>): { contas: Record<string, string | null> } {
  return { contas: Object.fromEntries(Object.entries(valores).map(([k, v]) => [k, v && v.trim() ? v.trim() : null])) };
}

/**
 * Configuração das contas por omissão de um módulo (GET/PUT `{url}` com `{ chave: { descricao, codigo_conta } }`):
 * /vendas/configuracao/contas, /compras/configuracao/contas e /logistica/configuracao/contas.
 */
export function ModalContas({ url, titulo, aberto, aoFechar, podeEditar, chaveConsulta }: { url: string; titulo: string; aberto: boolean; aoFechar: () => void; podeEditar: boolean; chaveConsulta: unknown[] }) {
  const [form] = Form.useForm<Record<string, string | null>>();
  const consulta = useQuery({ queryKey: chaveConsulta, queryFn: () => obter<ConfigContas>(url), enabled: aberto });
  const gravar = useAccao({ invalidar: [chaveConsulta], aoSucesso: aoFechar });

  useEffect(() => {
    if (consulta.data) form.setFieldsValue(Object.fromEntries(Object.entries(consulta.data).map(([k, v]) => [k, v.codigo_conta])));
  }, [consulta.data, form]);

  return (
    <Modal
      title={titulo}
      open={aberto}
      onCancel={aoFechar}
      width={larguraModal(640)}
      okText="Gravar"
      cancelText="Fechar"
      okButtonProps={{ style: podeEditar ? undefined : { display: 'none' } }}
      confirmLoading={gravar.isPending}
      onOk={() => form.submit()}
    >
      <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Contas usadas quando o produto ou a entidade não têm conta própria. Só contas de movimento." />
      {consulta.isLoading ? (
        <Skeleton active />
      ) : (
        <Form form={form} layout="vertical" disabled={!podeEditar} onFinish={(v) => gravar.mutate({ metodo: 'put', url, dados: corpoContas(v) })}>
          {Object.entries(consulta.data ?? {}).map(([chave, c]) => (
            <Form.Item key={chave} name={chave} label={c.descricao}>
              <SeletorConta />
            </Form.Item>
          ))}
        </Form>
      )}
    </Modal>
  );
}
