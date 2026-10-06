import { Alert, Form, Input, Modal, Select, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { formatarKz } from '@/utilitarios/formatacao';
import { useWbs } from '../comum/componentes';
import type { LinhaOrcamento, Organigrama, Posicao, TarefaWbs } from '../comum/tipos';

/** Posição com os campos extra devolvidos pelo organigrama (tarefas associadas e disposição dos filhos). */
export type PosicaoCompleta = Posicao & { tarefas?: number[] | null; disposicao?: string | null };

/** «Título;Área;Vagas» por linha → linhas do lote (projOrgGravarPosicoes do legado). Linhas vazias são ignoradas. */
export function lerLinhasPosicoes(texto: string): { titulo: string; area: string | null; vagas: number }[] {
  return texto.split(/\r?\n/).map((l) => l.trim()).filter(Boolean).map((l) => {
    const [titulo, area, vagas] = l.split(/[;\t]/).map((x) => x?.trim() ?? '');
    const n = Number.parseInt(vagas ?? '', 10);
    return { titulo, area: area || null, vagas: Number.isFinite(n) && n >= 0 ? n : 1 };
  }).filter((l) => l.titulo);
}

/** M-20: várias posições de uma vez sob a mesma posição superior. */
export function ModalPosicoesLote({ aberto, projectoId, posicoes, aoFechar }: { aberto: boolean; projectoId: number; posicoes: Posicao[]; aoFechar: () => void }) {
  const [form] = Form.useForm<{ no_pai_id?: number; cor?: string; texto: string }>();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => { form.resetFields(); aoFechar(); } });
  const texto = Form.useWatch('texto', form) ?? '';
  const linhas = lerLinhasPosicoes(texto);
  return (
    <Modal open={aberto} title="Várias posições" okText={`Criar ${linhas.length || ''} posição(ões)`} cancelText="Cancelar" okButtonProps={{ disabled: !linhas.length }}
      confirmLoading={accao.isPending} onCancel={aoFechar} onOk={() => form.submit()} destroyOnHidden width={larguraModal(620)}>
      <Form form={form} layout="vertical" initialValues={{ cor: 'azul' }}
        onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/organigrama/posicoes/lote`, dados: { no_pai_id: v.no_pai_id ?? null, cor: v.cor, linhas } })}>
        <Form.Item name="no_pai_id" label="Posição superior">
          <Select allowClear placeholder="Nenhuma (topo)" options={posicoes.map((p) => ({ value: p.id, label: p.titulo }))} />
        </Form.Item>
        <Form.Item name="cor" label="Cor"><Select options={['azul', 'verde', 'laranja', 'roxo', 'cinza', 'vermelho', 'ciano'].map((c) => ({ value: c, label: c }))} /></Form.Item>
        <Form.Item name="texto" label="Posições (uma por linha: Título;Área;Vagas)" rules={[{ required: true, message: 'Indique pelo menos uma posição.' }]}>
          <Input.TextArea rows={6} placeholder={'Encarregado geral;Produção;1\nPedreiro;Produção;4\nTopógrafo;Técnica;1'} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Lista plana das tarefas da WBS (com subtarefas), para escolha. */
function tarefasPlanas(grupos: { tarefas: TarefaWbs[] }[] | undefined): TarefaWbs[] {
  const r: TarefaWbs[] = [];
  const juntar = (t: TarefaWbs) => { r.push(t); (t.subtarefas ?? []).forEach(juntar); };
  (grupos ?? []).forEach((g) => g.tarefas.forEach(juntar));
  return r;
}

/** M-20: tarefas pelas quais a posição responde (projOrgAssociarTarefas). */
export function ModalAssociarTarefas({ projectoId, posicao, aoFechar }: { projectoId: number; posicao: PosicaoCompleta | null; aoFechar: () => void }) {
  const wbs = useWbs(posicao ? projectoId : undefined);
  const [escolhidas, setEscolhidas] = useState<number[]>([]);
  useEffect(() => setEscolhidas(posicao?.tarefas ?? []), [posicao]);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: aoFechar });
  const tarefas = useMemo(() => tarefasPlanas(wbs.data?.grupos), [wbs.data]);
  return (
    <Modal open={!!posicao} title={`Tarefas da posição «${posicao?.titulo ?? ''}»`} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onCancel={aoFechar}
      onOk={() => posicao && accao.mutate({ url: `/projetos/${projectoId}/organigrama/posicoes/${posicao.id}/tarefas`, dados: { tarefas: escolhidas } })} destroyOnHidden>
      <Select mode="multiple" style={{ width: '100%' }} value={escolhidas} onChange={setEscolhidas} loading={wbs.isLoading} optionFilterProp="label" placeholder="Escolha as tarefas"
        options={tarefas.map((t) => ({ value: t.id, label: `${' '.repeat(t.nivel ?? 0)}${t.codigo ? `${t.codigo} ` : ''}${t.nome}` }))} aria-label="Tarefas associadas" />
    </Modal>
  );
}

/** M-20: cada linha de orçamento a uma posição e ao membro responsável (projOrgMapearOrcamento). */
export function ModalMapearOrcamento({ aberto, projectoId, organigrama, aoFechar }: { aberto: boolean; projectoId: number; organigrama: Organigrama; aoFechar: () => void }) {
  const orc = useQuery({ queryKey: ['projectos', 'orcamento', projectoId], queryFn: () => obter<{ total: string; linhas: LinhaOrcamento[] }>(`/projetos/${projectoId}/orcamento`), enabled: aberto });
  const [alt, setAlt] = useState<Record<number, { no?: number | null; membro?: number | null }>>({});
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => { setAlt({}); aoFechar(); } });
  const membros = [...organigrama.sem_posicao, ...organigrama.posicoes.flatMap((p) => p.membros)];
  const alteracoes = Object.entries(alt).map(([id, a]) => {
    const l = (orc.data?.linhas ?? []).find((x) => x.id === Number(id));
    return { linha_id: Number(id), no_organigrama_projeto_id: a.no !== undefined ? a.no : l?.no_organigrama_projeto_id ?? null, membro_equipa_projeto_id: a.membro !== undefined ? a.membro : l?.membro_equipa_projeto_id ?? null };
  });
  return (
    <Modal open={aberto} title="Mapear o orçamento ao organigrama" width={larguraModal(980)} okText="Gravar" cancelText="Cancelar" okButtonProps={{ disabled: !alteracoes.length }}
      confirmLoading={accao.isPending} onCancel={() => { setAlt({}); aoFechar(); }} onOk={() => accao.mutate({ url: `/projetos/${projectoId}/organigrama/orcamento`, dados: { alteracoes } })}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Ao escolher a posição, o membro responsável fica o da posição, salvo se escolher outro." />
      <Table<LinhaOrcamento>
        rowKey="id" size="small" loading={orc.isLoading} dataSource={orc.data?.linhas} pagination={{ pageSize: 20 }} scroll={scrollTabela()}
        columns={[
          { title: 'Tarefa', render: (_, l) => (l.tarefa_codigo || l.tarefa_nome ? `${l.tarefa_codigo ?? ''} ${l.tarefa_nome ?? ''}`.trim() : '—') },
          { title: 'Rubrica', dataIndex: 'rubrica' },
          { title: 'Montante (Kz)', dataIndex: 'montante', align: 'right', render: (v: string) => formatarKz(v) },
          {
            title: 'Posição', width: 220,
            render: (_, l) => (
              <Select size="small" allowClear style={{ width: 200 }} placeholder="—" value={alt[l.id]?.no !== undefined ? alt[l.id]?.no ?? undefined : l.no_organigrama_projeto_id ?? undefined}
                onChange={(v) => setAlt((a) => ({ ...a, [l.id]: { ...a[l.id], no: v ?? null } }))} options={organigrama.posicoes.map((p) => ({ value: p.id, label: p.titulo }))} aria-label={`Posição da linha ${l.rubrica}`} />
            ),
          },
          {
            title: 'Responsável', width: 220,
            render: (_, l) => (
              <Select size="small" allowClear style={{ width: 200 }} placeholder="—" value={alt[l.id]?.membro !== undefined ? alt[l.id]?.membro ?? undefined : l.membro_equipa_projeto_id ?? undefined}
                onChange={(v) => setAlt((a) => ({ ...a, [l.id]: { ...a[l.id], membro: v ?? null } }))} options={[...new Map(membros.map((m) => [m.id, m])).values()].map((m) => ({ value: m.id, label: m.nome }))}
                aria-label={`Responsável da linha ${l.rubrica}`} />
            ),
          },
        ]}
      />
      <Typography.Text type="secondary">{alteracoes.length} linha(s) alterada(s).</Typography.Text>
    </Modal>
  );
}
