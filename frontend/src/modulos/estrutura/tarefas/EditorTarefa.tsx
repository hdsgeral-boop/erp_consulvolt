import { Checkbox, Col, Form, Input, Modal, Row, Select } from 'antd';
import { useEffect } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { SeletorColaborador } from '@/modulos/rh/comum/componentes';
import { useCargos } from '@/modulos/rh/comum/consultas';
import { nomePosto } from '../comum/legado';
import type { Unidade } from '../comum/arvore';
import { actualizarTarefa, criarTarefa } from './api';
import { useMutacao } from './comum';
import { normalizarUrl, PERIODICIDADES } from './regras';
import type { DadosTarefa, Periodicidade, TarefaUnidade } from './tipos';

interface Campos {
  nome: string;
  codigo?: string;
  periodicidade: Periodicidade;
  colaborador_responsavel_id?: number | null;
  posto_trabalho_id?: number | null;
  descricao?: string;
  url_instrucoes?: string;
  ativo: boolean;
}

/** Nova tarefa / editar tarefa da unidade (estrutura_tarefas_ui.js editarTarefa): o que a unidade faz. */
export function EditorTarefa({ tarefa, unidade, aoFechar }: { tarefa: TarefaUnidade | 'nova' | null; unidade: Unidade; aoFechar: () => void }) {
  const [form] = Form.useForm<Campos>();
  const cargos = useCargos();
  const nova = tarefa === 'nova';
  const accao = useMutacao((d: DadosTarefa) => (nova ? criarTarefa(d) : actualizarTarefa((tarefa as TarefaUnidade).id, d)), { aoSucesso: aoFechar });
  useEffect(() => {
    if (!tarefa) return;
    form.resetFields();
    form.setFieldsValue(tarefa === 'nova'
      ? { periodicidade: 'MENSAL', ativo: true }
      : { nome: tarefa.nome, codigo: tarefa.codigo ?? undefined, periodicidade: tarefa.periodicidade, colaborador_responsavel_id: tarefa.colaborador_responsavel_id,
        posto_trabalho_id: tarefa.posto_trabalho_id, descricao: tarefa.descricao ?? undefined, url_instrucoes: tarefa.url_instrucoes ?? undefined, ativo: tarefa.ativo });
  }, [tarefa, form]);
  const gravar = (v: Campos) => accao.mutate({
    unidade_organica_id: nova ? unidade.id : (tarefa as TarefaUnidade).unidade_organica_id,
    nome: v.nome.trim(), codigo: v.codigo?.trim() || null, periodicidade: v.periodicidade, colaborador_responsavel_id: v.colaborador_responsavel_id ?? null,
    posto_trabalho_id: v.posto_trabalho_id ?? null, descricao: v.descricao?.trim() || null, url_instrucoes: normalizarUrl(v.url_instrucoes), ativo: !!v.ativo,
  });
  return (
    <Modal open={!!tarefa} title={nova ? `Nova tarefa da unidade — ${unidade.nome}` : 'Editar tarefa'} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar"
      confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(720)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={gravar}>
        <Row gutter={[16, 0]}>
          <Col xs={24}><Form.Item name="nome" label="Nome da tarefa" rules={[{ required: true, message: 'Indique o nome da tarefa.' }]}><Input maxLength={255} placeholder="Ex.: Manutenção preventiva da frota" /></Form.Item></Col>
          <Col xs={12} sm={8}><Form.Item name="codigo" label="Código"><Input maxLength={50} placeholder="Ex.: MAN-01" /></Form.Item></Col>
          <Col xs={12} sm={8}><Form.Item name="periodicidade" label="Periodicidade" rules={[{ required: true }]}><Select options={Object.entries(PERIODICIDADES).map(([value, label]) => ({ value, label }))} /></Form.Item></Col>
          <Col xs={24} sm={8}><Form.Item name="ativo" valuePropName="checked" label=" "><Checkbox>Activa</Checkbox></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="colaborador_responsavel_id" label="Responsável"><SeletorColaborador apenasActivos placeholder="— sem responsável —" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} sm={12}>
            <Form.Item name="posto_trabalho_id" label="Cargo responsável">
              <Select allowClear placeholder="— sem cargo —" options={unidade.postos.map((p) => ({ value: p.id, label: nomePosto(p, cargos.nome) }))} />
            </Form.Item>
          </Col>
          <Col xs={24}><Form.Item name="descricao" label="Descrição"><Input.TextArea rows={3} maxLength={5000} placeholder="O que esta tarefa envolve" /></Form.Item></Col>
          <Col xs={24}>
            <Form.Item name="url_instrucoes" label="Instruções da tarefa (endereço)" extra="Procedimento, manual ou instrução de trabalho; aparece a quem executa, no portal.">
              <Input maxLength={500} placeholder="https://…" />
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </Modal>
  );
}
