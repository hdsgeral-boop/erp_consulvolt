import { useQuery } from '@tanstack/react-query';
import { Form, Input, Modal } from 'antd';
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router';
import { larguraModal } from '@/componentes/responsivo';
import { CHAVE_PLANOS, listarPlanos, type ResumoPlano } from './api';

/**
 * Plano escolhido, na query string (`?plano=`): sobrevive a recargas e é partilhável. Sem escolha explícita (ou com um id que já não
 * existe) fica o plano em vigor; senão o mais recente.
 */
export function usePlanoEscolhido(): { planos: ResumoPlano[]; plano: ResumoPlano | undefined; escolher: (id: number) => void; carregando: boolean } {
  const [params, setParams] = useSearchParams();
  const { data, isLoading } = useQuery({ queryKey: CHAVE_PLANOS, queryFn: listarPlanos });
  const planos = useMemo(() => data ?? [], [data]);
  const pedido = Number(params.get('plano')) || null;
  const plano = planos.find((p) => p.id === pedido) ?? planos.find((p) => p.em_vigor) ?? planos[0];
  const escolher = useCallback((id: number) => setParams((q) => { const n = new URLSearchParams(q); n.set('plano', String(id)); return n; }, { replace: true }), [setParams]);
  return { planos, plano, escolher, carregando: isLoading };
}

/**
 * Pede um motivo por escrito antes de uma acção. Num plano aprovado (congelado) toda a alteração de estrutura exige o motivo
 * (guardado na auditoria); `pedir(accao)` executa já se não for preciso, ou abre o diálogo e executa com o motivo.
 */
export function usePedirMotivo(obrigatorio: boolean, titulo = 'Alterar um plano aprovado', texto: ReactNode = 'O plano está aprovado e congelado. Indique o motivo desta alteração (mínimo 10 caracteres): fica registado na auditoria.') {
  const [pendente, setPendente] = useState<((motivo?: string) => void) | null>(null);
  const [form] = Form.useForm<{ motivo: string }>();
  useEffect(() => { if (pendente) form.resetFields(); }, [pendente, form]);
  const pedir = useCallback((accao: (motivo?: string) => void) => (obrigatorio ? setPendente(() => accao) : accao(undefined)), [obrigatorio]);
  const dialogo = (
    <Modal open={!!pendente} title={titulo} okText="Continuar" cancelText="Cancelar" width={larguraModal(520)} destroyOnHidden onCancel={() => setPendente(null)}
      onOk={() => form.validateFields().then(({ motivo }) => { const f = pendente; setPendente(null); f?.(motivo.trim()); }).catch(() => undefined)}>
      <p>{texto}</p>
      <Form form={form} layout="vertical">
        <Form.Item name="motivo" label="Motivo" rules={[{ required: true, whitespace: true, min: 10, message: 'Indique o motivo (mínimo 10 caracteres).' }]}>
          <Input.TextArea rows={3} maxLength={300} showCount />
        </Form.Item>
      </Form>
    </Modal>
  );
  return { pedir, dialogo };
}
