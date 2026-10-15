import { Alert, Checkbox, Form, Input, InputNumber, Modal, Select, Typography } from 'antd';
import { useEffect } from 'react';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal } from '@/componentes/responsivo';
import { useWbs } from '../../comum/componentes';
import { achatarWbs } from '../../comum/regras';

/**
 * Duplicar camada e mudar de nível (Versão Actual, js/projectos_hierarquia.js:275-405 e 682-838). O servidor faz a
 * cópia (0 %, PENDENTE, sem horas nem medições; precedências internas ligadas às cópias) e a conversão fase ↔ tarefa,
 * e devolve os avisos sobre datas e dependências.
 */

export interface AlvoHierarquia {
  tipo: 'tarefas' | 'marcos';
  id: number;
  nome: string;
  /** Para uma tarefa: o pai e o milestone actuais (valores por omissão do «Passar a»). */
  paiId?: number | null;
  marcoId?: number | null;
  nSub?: number;
}

const mostrarAvisos = (avisos: string[] | undefined) => {
  if (avisos?.length) Modal.warning({ title: 'Reveja as datas', content: <ul style={{ paddingLeft: 18 }}>{avisos.map((a) => <li key={a}>{a}</li>)}</ul> });
};

export function ModalDuplicar({ projectoId, alvo, aoFechar }: { projectoId: number; alvo: AlvoHierarquia | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ nome: string; deslocar_dias: number }>();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => { if (alvo) form.setFieldsValue({ nome: `${alvo.nome} (cópia)`, deslocar_dias: 0 }); }, [alvo, form]);
  return (
    <Modal title={`Duplicar ${alvo?.tipo === 'marcos' ? 'fase' : 'tarefa'}`} open={!!alvo} onCancel={aoFechar} onOk={() => form.submit()} okText="Duplicar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
      <Typography.Paragraph type="secondary"><strong>{alvo?.nome}</strong>{alvo?.nSub ? ` — leva ${alvo.nSub} ${alvo.tipo === 'marcos' ? 'tarefa(s)' : 'subtarefa(s)'} consigo` : ''}</Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(v) => alvo && accao.mutate({ url: `/projetos/${projectoId}/${alvo.tipo}/${alvo.id}/duplicar`, dados: v })}>
        <Form.Item name="nome" label="Designação da cópia" rules={[{ required: true, message: 'Indique a designação.' }]}><Input maxLength={255} /></Form.Item>
        <Form.Item name="deslocar_dias" label="Deslocar as datas em (dias)" extra="Aplica-se a tudo o que for copiado. Use, por exemplo, 30 para repetir a fase um mês depois.">
          <InputNumber min={-3660} max={3660} precision={0} style={{ width: 160 }} />
        </Form.Item>
      </Form>
      <Alert type="info" showIcon message="A cópia nasce a 0% e pendente. Horas, custos, autos, medições e requisições não são copiados." />
    </Modal>
  );
}

export function ModalNivel({ projectoId, alvo, aoFechar }: { projectoId: number; alvo: AlvoHierarquia | null; aoFechar: () => void }) {
  const w = useWbs(projectoId);
  const [form] = Form.useForm<{ destino: string; encaixar: boolean }>();
  const accao = useAccao<{ avisos?: string[] }>({ invalidar: [['projectos']], aoSucesso: (d) => { aoFechar(); mostrarAvisos(d?.avisos); } });
  const fase = alvo?.tipo === 'marcos';
  useEffect(() => {
    if (!alvo) return;
    form.setFieldsValue({ destino: fase ? undefined : alvo.paiId ? `SUB:${alvo.paiId}` : `RAIZ:${alvo.marcoId ?? ''}`, encaixar: true });
  }, [alvo, fase, form]);
  // não pode passar a subtarefa dela própria nem de uma subtarefa sua
  const proibidas = new Set<number>();
  if (alvo && !fase) {
    const todas = achatarWbs(w.data);
    const idx = todas.findIndex((t) => t.id === alvo.id);
    if (idx >= 0) {
      proibidas.add(alvo.id);
      for (let i = idx + 1; i < todas.length && todas[i].nivel > todas[idx].nivel; i++) proibidas.add(todas[i].id);
    }
  }
  const fases = (w.data?.grupos ?? []).filter((g) => g.marco && (!fase || g.marco.id !== alvo?.id));
  const opcoes = [
    { label: 'Tarefa principal', options: [{ value: 'RAIZ:', label: 'Tarefa principal — sem fase' }, ...fases.map((g) => ({ value: `RAIZ:${g.marco!.id}`, label: `Tarefa principal da fase «${g.marco!.nome}»` }))] },
    { label: 'Subtarefa de', options: achatarWbs(w.data).filter((t) => !proibidas.has(t.id)).map((t) => ({ value: `SUB:${t.id}`, label: `${'— '.repeat(t.nivel)}${t.codigo ? `${t.codigo} ` : ''}${t.nome}` })) },
    ...(fase ? [] : [{ label: 'Outro nível', options: [{ value: 'FASE:', label: 'Converter em Fase / Milestone' }] }]),
  ];
  return (
    <Modal title="Mudar de nível" open={!!alvo} onCancel={aoFechar} onOk={() => form.submit()} okText="Mudar de nível" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(620)} destroyOnHidden>
      <Typography.Paragraph type="secondary"><strong>{alvo?.nome}</strong> — {fase ? 'fase' : 'tarefa'}. Tudo o que está por baixo acompanha; as datas são verificadas contra o novo nível acima.</Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(v) => {
        if (!alvo) return;
        const [destino, ref] = v.destino.split(':');
        accao.mutate({ url: `/projetos/${projectoId}/${alvo.tipo}/${alvo.id}/nivel`, dados: {
          destino, alvo_id: destino === 'SUB' ? Number(ref) : null, marco_projeto_id: destino === 'RAIZ' && ref ? Number(ref) : null, encaixar: v.encaixar,
        } });
      }}>
        <Form.Item name="destino" label="Passar a" rules={[{ required: true, message: 'Escolha o novo nível.' }]}><Select showSearch optionFilterProp="label" options={opcoes} /></Form.Item>
        <Form.Item name="encaixar" valuePropName="checked"><Checkbox>Encaixar as datas no novo nível acima, se não couberem</Checkbox></Form.Item>
      </Form>
      {!fase && <Alert type="warning" showIcon message="Converter em fase só é possível numa tarefa sem horas, custos, autos, orçamento, requisições nem medições." />}
    </Modal>
  );
}
