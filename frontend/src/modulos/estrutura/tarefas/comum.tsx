import { Form, Input, Modal, Tag, Tooltip, message } from 'antd';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVE_ATRASOS, CHAVE_EXECUCOES, CHAVE_IMPEDIMENTOS, CHAVE_PORTAL_APROVACOES, CHAVE_PORTAL_ENTREGAVEIS, CHAVE_TAREFAS } from './api';
import { IMPEDIMENTO, VALIDACAO } from './regras';
import type { EstadoImpedimento, EstadoValidacao } from './tipos';

/** Tudo o que mexe em tarefas, entregas ou impedimentos invalida as listas da estrutura, do portal e do resumo do portal. */
export const CHAVES_ENTREGAVEIS: unknown[][] = [CHAVE_TAREFAS, CHAVE_EXECUCOES, CHAVE_IMPEDIMENTOS, CHAVE_ATRASOS, CHAVE_PORTAL_ENTREGAVEIS, CHAVE_PORTAL_APROVACOES, ['rh', 'portal', 'resumo'], ['rh', 'produtividade']];

/**
 * Mutação das acções da Fase J: chama a função tipada do cliente de API, mostra a mensagem do servidor, invalida as
 * consultas e notifica o erro (como useAccao, mas com as funções de `api.ts` em vez de url/método soltos).
 */
export function useMutacao<A, R>(fn: (a: A) => Promise<{ dados: R; mensagem: string }>, opcoes: { invalidar?: unknown[][]; aoSucesso?: (dados: R, a: A) => void; silenciosa?: boolean } = {}) {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: ({ dados, mensagem }, a) => {
      if (!opcoes.silenciosa) message.success(mensagem);
      (opcoes.invalidar ?? CHAVES_ENTREGAVEIS).forEach((chave) => void cliente.invalidateQueries({ queryKey: chave }));
      opcoes.aoSucesso?.(dados, a);
    },
    onError: (e) => notificarErro(e),
  });
}

/** Selo do estado de validação de uma entrega (SELO_VAL do legado). */
export function TagValidacao({ estado }: { estado: EstadoValidacao }) {
  const v = VALIDACAO[estado] ?? { rotulo: estado, cor: 'default', dica: '' };
  return <Tooltip title={v.dica}><Tag color={v.cor}>{v.rotulo}</Tag></Tooltip>;
}

export function TagImpedimento({ estado }: { estado: EstadoImpedimento }) {
  const v = IMPEDIMENTO[estado] ?? { rotulo: estado, cor: 'default' };
  return <Tag color={v.cor}>{v.rotulo}</Tag>;
}

/** Anexos são ligações com nome, não ícones mudos (portal_ui.js:1107): quem analisa tem de chegar lá num clique. */
export function LigacaoAnexo({ url, rotulo = 'Abrir anexo', icone = 'paperclip', semAnexo = 'sem anexo' }: { url: string | null | undefined; rotulo?: string; icone?: string; semAnexo?: string | null }) {
  if (!url || !/^https?:\/\//i.test(url)) return semAnexo ? <span style={{ color: '#b45309', fontSize: '0.8rem' }}><IconeFa nome="exclamation-triangle" /> {semAnexo}</span> : null;
  return <a href={url} target="_blank" rel="noopener noreferrer" style={{ whiteSpace: 'nowrap' }}><IconeFa nome={icone} /> {rotulo}</a>;
}

/**
 * Motivo de uma devolução, recusa ou nota de decisão (o servidor exige pelo menos 3 caracteres nas devoluções/recusas — §3.4).
 * `mensagem` explica a quem decide o que a outra pessoa vai ler.
 */
export function ModalMotivoCurto({ aberto, titulo, mensagem, rotulo = 'Motivo', textoOk = 'Confirmar', perigo, carregando, aoConfirmar, aoFechar }: {
  aberto: boolean; titulo: string; mensagem?: string; rotulo?: string; textoOk?: string; perigo?: boolean; carregando?: boolean; aoConfirmar: (motivo: string) => void; aoFechar: () => void;
}) {
  const [form] = Form.useForm<{ motivo: string }>();
  useEffect(() => { if (aberto) form.resetFields(); }, [aberto, form]);
  return (
    <Modal title={titulo} open={aberto} onCancel={aoFechar} okText={textoOk} cancelText="Cancelar" okButtonProps={{ danger: perigo }} confirmLoading={carregando} onOk={() => form.submit()} destroyOnHidden>
      {mensagem && <p style={{ color: '#475569', marginTop: 0 }}>{mensagem}</p>}
      <Form form={form} layout="vertical" onFinish={(v) => aoConfirmar(v.motivo.trim())}>
        <Form.Item name="motivo" label={rotulo} rules={[{ required: true, message: 'Indique o motivo.' }, { min: 3, message: 'Pelo menos 3 caracteres.' }]}>
          <Input.TextArea rows={3} maxLength={1000} showCount autoFocus />
        </Form.Item>
      </Form>
    </Modal>
  );
}
