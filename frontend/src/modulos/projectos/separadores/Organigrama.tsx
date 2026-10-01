import { Alert, Button, Card, Checkbox, Col, Empty, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Statistic, Tag, Tree, Typography } from 'antd';
import { ApartmentOutlined, ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, EditOutlined, PlusOutlined, UserAddOutlined } from '@ant-design/icons';
import type { DataNode } from 'antd/es/tree';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Organigrama, Posicao } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';

const CORES: Record<string, string> = { azul: 'blue', verde: 'green', laranja: 'orange', roxo: 'purple', cinza: 'default', vermelho: 'red', ciano: 'cyan' };

/** Organigrama do projecto em árvore: posições com vagas, membros alocados e orçamento/executado por posição. */
export function SeparadorOrganigrama({ projecto, acc }: PropsSeparador) {
  const q = useQuery({ queryKey: ['projectos', 'organigrama', projecto.id], queryFn: () => obter<Organigrama>(`/projetos/${projecto.id}/organigrama`) });
  const [posicao, setPosicao] = useState<Partial<Posicao> | null>(null);
  const [alocar, setAlocar] = useState<Posicao | null>(null);
  const accao = useAccao({ invalidar: [['projectos']] });
  const o = q.data;

  const arvore = useMemo<DataNode[]>(() => {
    const filhos = new Map<number | null, Posicao[]>();
    for (const p of o?.posicoes ?? []) {
      const pai = p.no_pai_id && o?.posicoes.some((x) => x.id === p.no_pai_id) ? p.no_pai_id : null;
      filhos.set(pai, [...(filhos.get(pai) ?? []), p]);
    }
    const no = (p: Posicao): DataNode => ({ key: p.id, title: p.titulo, children: (filhos.get(p.id) ?? []).sort((a, b) => (a.ordem ?? 0) - (b.ordem ?? 0)).map(no) });
    return (filhos.get(null) ?? []).sort((a, b) => (a.ordem ?? 0) - (b.ordem ?? 0)).map(no);
  }, [o]);
  const porId = useMemo(() => new Map((o?.posicoes ?? []).map((p) => [p.id, p])), [o]);

  const titulo = (n: DataNode) => {
    const p = porId.get(Number(n.key));
    if (!p) return null;
    const ocupadas = p.membros.length;
    return (
      <Flex gap={12} align="center" wrap style={{ padding: '4px 0' }}>
        <Tag color={CORES[p.cor ?? 'azul'] ?? 'blue'}>{p.area ?? 'Posição'}</Tag>
        <Typography.Text strong>{p.titulo}</Typography.Text>
        {p.apoio && <Tag>Apoio</Tag>}
        <Typography.Text type={p.vagas && ocupadas < p.vagas ? 'warning' : 'secondary'}>{ocupadas}/{p.vagas ?? 0} vaga(s)</Typography.Text>
        {p.membros.length > 0 && <Typography.Text type="secondary">{p.membros.map((m) => m.nome).join(', ')}</Typography.Text>}
        {Number(p.valores.orcamento) > 0 && <Typography.Text type="secondary">Orç. {formatarKz(p.valores.orcamento)} · exec. {formatarKz(p.valores.executado)}</Typography.Text>}
        {acc.gerir && (
          <Space size={4} onClick={(e) => e.stopPropagation()}>
            <Button size="small" icon={<UserAddOutlined />} title="Alocar membros" onClick={() => setAlocar(p)} />
            <Button size="small" icon={<PlusOutlined />} title="Nova posição subordinada" onClick={() => setPosicao({ no_pai_id: p.id, cor: p.cor })} />
            <Button size="small" icon={<EditOutlined />} onClick={() => setPosicao(p)} />
            <Button size="small" icon={<ArrowUpOutlined />} title="Subir" onClick={() => accao.mutate({ url: `/projetos/${projecto.id}/organigrama/posicoes/${p.id}/arrumar`, dados: { acao: 'SUBIR' } })} />
            <Button size="small" icon={<ArrowDownOutlined />} title="Descer" onClick={() => accao.mutate({ url: `/projetos/${projecto.id}/organigrama/posicoes/${p.id}/arrumar`, dados: { acao: 'DESCER' } })} />
            <Popconfirm title={`Eliminar a posição «${p.titulo}»?`} okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
              onConfirm={() => accao.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/organigrama/posicoes/${p.id}` })}>
              <Button size="small" danger icon={<DeleteOutlined />} />
            </Popconfirm>
          </Space>
        )}
      </Flex>
    );
  };

  return (
    <Card loading={q.isLoading}>
      {o && (
        <>
          <Row gutter={16} style={{ marginBottom: 16 }}>
            <Col xs={12} md={4}><Statistic title="Posições" value={o.posicoes.length} /></Col>
            <Col xs={12} md={4}><Statistic title="Vagas" value={o.totais.vagas} /></Col>
            <Col xs={12} md={4}><Statistic title="Membros alocados" value={`${o.totais.alocados}/${o.totais.membros}`} /></Col>
            <Col xs={12} md={6}><Statistic title="Orçamento (Kz)" value={formatarKz(o.totais.orcamento)} /></Col>
            <Col xs={12} md={6}><Statistic title="Executado (Kz)" value={formatarKz(o.totais.executado)} /></Col>
          </Row>
          {(o.totais.tarefas_sem_posicao > 0 || o.totais.orcamento_sem_responsavel > 0) && (
            <Alert type="info" showIcon style={{ marginBottom: 12 }}
              message={`${o.totais.tarefas_sem_posicao} tarefa(s) sem posição · ${o.totais.orcamento_sem_responsavel} linha(s) de orçamento sem responsável`} />
          )}
          {acc.gerir && (
            <Flex gap={8} style={{ marginBottom: 12 }}>
              <Button type="primary" icon={<PlusOutlined />} onClick={() => setPosicao({ cor: 'azul' })}>Nova posição</Button>
              {o.posicoes.length === 0 && (
                <Button icon={<ApartmentOutlined />} loading={accao.isPending} onClick={() => accao.mutate({ url: `/projetos/${projecto.id}/organigrama/modelo` })}>Criar a partir do modelo de obra</Button>
              )}
            </Flex>
          )}
          {arvore.length ? <Tree treeData={arvore} defaultExpandAll blockNode selectable={false} titleRender={titulo} key={arvore.length} /> : <Empty description="Organigrama vazio" />}
          {o.sem_posicao.length > 0 && (
            <Card size="small" title="Membros sem posição" style={{ marginTop: 16 }}>
              <Space wrap>{o.sem_posicao.map((m) => <Tag key={m.id}>{m.nome}{m.papel ? ` · ${m.papel}` : ''}</Tag>)}</Space>
            </Card>
          )}
          <ModalPosicao projectoId={projecto.id} posicao={posicao} posicoes={o.posicoes} aoFechar={() => setPosicao(null)} />
          <ModalAlocar projectoId={projecto.id} posicao={alocar} organigrama={o} aoFechar={() => setAlocar(null)} />
        </>
      )}
    </Card>
  );
}

function ModalPosicao({ projectoId, posicao, posicoes, aoFechar }: { projectoId: number; posicao: Partial<Posicao> | null; posicoes: Posicao[]; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (posicao) { form.resetFields(); form.setFieldsValue({ vagas: 1, ...posicao, apoio: !!posicao.apoio }); }
  }, [posicao, form]);
  return (
    <Modal title={posicao?.id ? `Editar «${posicao.titulo}»` : 'Nova posição'} open={!!posicao} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = { titulo: v.titulo, area: v.area || null, descricao: v.descricao || null, vagas: v.vagas ?? 0, no_pai_id: v.no_pai_id ?? null, cor: v.cor ?? 'azul', apoio: !!v.apoio };
        accao.mutate(posicao?.id ? { metodo: 'put', url: `/projetos/${projectoId}/organigrama/posicoes/${posicao.id}`, dados } : { url: `/projetos/${projectoId}/organigrama/posicoes`, dados });
      }}>
        <Form.Item name="titulo" label="Título" rules={[{ required: true, message: 'Indique o título.' }]}><Input maxLength={255} /></Form.Item>
        <Space wrap>
          <Form.Item name="area" label="Área"><Input maxLength={30} style={{ width: 180 }} /></Form.Item>
          <Form.Item name="vagas" label="Vagas"><InputNumber min={0} style={{ width: 100 }} /></Form.Item>
          <Form.Item name="cor" label="Cor"><Select style={{ width: 130 }} options={Object.keys(CORES).map((c) => ({ value: c, label: c }))} /></Form.Item>
        </Space>
        <Form.Item name="no_pai_id" label="Posição superior">
          <Select allowClear placeholder="Nenhuma (topo)" options={posicoes.filter((p) => p.id !== posicao?.id).map((p) => ({ value: p.id, label: p.titulo }))} />
        </Form.Item>
        <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
        <Form.Item name="apoio" valuePropName="checked"><Checkbox>Posição de apoio (staff)</Checkbox></Form.Item>
      </Form>
    </Modal>
  );
}

function ModalAlocar({ projectoId, posicao, organigrama, aoFechar }: { projectoId: number; posicao: Posicao | null; organigrama: Organigrama; aoFechar: () => void }) {
  const [membros, setMembros] = useState<number[]>([]);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => setMembros(posicao?.membros.map((m) => m.id) ?? []), [posicao]);
  const todos = [...organigrama.sem_posicao, ...(posicao?.membros ?? []), ...organigrama.posicoes.filter((p) => p.id !== posicao?.id).flatMap((p) => p.membros)];
  const enviarAlocacao = (confirmar: boolean) => {
    const novos = membros.filter((id) => !posicao?.membros.some((m) => m.id === id));
    const retirados = (posicao?.membros ?? []).filter((m) => !membros.includes(m.id)).map((m) => m.id);
    if (novos.length) accao.mutate({ url: `/projetos/${projectoId}/organigrama/alocar`, dados: { membros: novos, no_organigrama_projeto_id: posicao?.id, confirmar_excesso: confirmar } });
    if (retirados.length) accao.mutate({ url: `/projetos/${projectoId}/organigrama/alocar`, dados: { membros: retirados, no_organigrama_projeto_id: null } });
    if (!novos.length && !retirados.length) aoFechar();
  };
  return (
    <Modal title={`Alocar membros a «${posicao?.titulo ?? ''}»`} open={!!posicao} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending}
      onOk={() => {
        const excede = posicao?.vagas !== null && posicao?.vagas !== undefined && membros.length > posicao.vagas;
        if (excede) Modal.confirm({ title: 'Excede as vagas da posição', content: `A posição tem ${posicao?.vagas} vaga(s). Alocar mesmo assim?`, okText: 'Alocar', cancelText: 'Cancelar', onOk: () => enviarAlocacao(true) });
        else enviarAlocacao(false);
      }}>
      <Typography.Paragraph type="secondary">Um membro só pode estar numa posição: escolher aqui retira-o da posição anterior.</Typography.Paragraph>
      <Select mode="multiple" style={{ width: '100%' }} value={membros} onChange={setMembros} optionFilterProp="label"
        options={[...new Map(todos.map((m) => [m.id, m])).values()].map((m) => ({ value: m.id, label: `${m.nome}${m.papel ? ` (${m.papel})` : ''}` }))} />
    </Modal>
  );
}
