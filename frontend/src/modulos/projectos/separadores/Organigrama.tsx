import { Button, Card, Checkbox, Col, Dropdown, Empty, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Tag, Tree, Typography } from 'antd';
import {
  ApartmentOutlined, ArrowDownOutlined, ArrowUpOutlined, ColumnHeightOutlined, DeleteOutlined, DollarOutlined, DownOutlined, EditOutlined, OrderedListOutlined,
  PlusOutlined, UnorderedListOutlined, UserAddOutlined,
} from '@ant-design/icons';
import { ModalAssociarTarefas, ModalMapearOrcamento, ModalPosicoesLote, type PosicaoCompleta } from './OrganigramaAccoes';
import type { DataNode } from 'antd/es/tree';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState, useRef } from 'react';
import { enviar, obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Organigrama, Posicao } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { ImpressaoSeparador } from '../comum/ImpressaoSeparador';
import { CORES_POSICAO as CORES, dadosPosicao, simNao } from '../comum/linhas';
import { consumo } from '../comum/regras';

/** Cor do executado face ao orçamento (legado: > 100 % vermelho, ≥ 85 % âmbar, abaixo verde). */
const corConsumo = (c: number | null): 'danger' | 'warning' | 'success' | 'secondary' => (c === null ? 'secondary' : c > 100 ? 'danger' : c >= 85 ? 'warning' : 'success');

/** Organigrama do projecto em árvore: posições com vagas, membros alocados e orçamento/executado por posição. */
export function SeparadorOrganigrama({ projecto, acc }: PropsSeparador) {
  const q = useQuery({ queryKey: ['projectos', 'organigrama', projecto.id], queryFn: () => obter<Organigrama>(`/projetos/${projecto.id}/organigrama`) });
  const [posicao, setPosicao] = useState<Partial<Posicao> | null>(null);
  const [alocar, setAlocar] = useState<Posicao | null>(null);
  // M-20: acções do organigrama do legado (posições em lote, tarefas da posição, mapear orçamento, disposição)
  const [lote, setLote] = useState(false);
  const [tarefas, setTarefas] = useState<PosicaoCompleta | null>(null);
  const [mapear, setMapear] = useState(false);
  const accao = useAccao({ invalidar: [['projectos']] });
  const o = q.data;
  const refOrganigrama = useRef<HTMLDivElement>(null);

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
        {((p as PosicaoCompleta).tarefas?.length ?? 0) > 0 && <Tag icon={<OrderedListOutlined />}>{(p as PosicaoCompleta).tarefas?.length} tarefa(s)</Tag>}
        {(p as PosicaoCompleta).disposicao === 'COLUNA' && <Tag>Filhos em coluna</Tag>}
        <Typography.Text type={p.vagas && ocupadas < p.vagas ? 'warning' : 'secondary'}>{ocupadas}/{p.vagas ?? 0} vaga(s)</Typography.Text>
        {p.membros.length > 0 && <Typography.Text type="secondary">{p.membros.map((m) => m.nome).join(', ')}</Typography.Text>}
        {Number(p.valores.orcamento) > 0 && <Typography.Text type="secondary">Orç. {formatarKz(p.valores.orcamento)} · exec. {formatarKz(p.valores.executado)}</Typography.Text>}
        {/* Versão Actual (projectos_organigrama.js:134-162): a chefia responde pelo que está abaixo; orçamento mapeado pelas quantidades */}
        {Number(p.valores.orcamento_qtd ?? 0) > 0 && <Typography.Text type="secondary" style={{ display: 'block' }}>Mapeado por qtd. {formatarKz(p.valores.orcamento_qtd)}</Typography.Text>}
        {(p.valores_ramo?.subposicoes ?? 0) > 0 && Number(p.valores_ramo?.orcamento ?? 0) + Number(p.valores_ramo?.orcamento_qtd ?? 0) > 0 && (
          <Typography.Text type="secondary" style={{ display: 'block' }}>Com as subposições: orç. {formatarKz(p.valores_ramo?.orcamento)} · exec. {formatarKz(p.valores_ramo?.executado)}{Number(p.valores_ramo?.orcamento_qtd ?? 0) > 0 ? ` · qtd. ${formatarKz(p.valores_ramo?.orcamento_qtd)}` : ''}</Typography.Text>
        )}
        {acc.gerir && (
          <Space wrap size={4} onClick={(e) => e.stopPropagation()}>
            <Button size="small" icon={<UserAddOutlined />} title="Alocar membros" onClick={() => setAlocar(p)} />
            <Button size="small" icon={<OrderedListOutlined />} title="Associar tarefas" aria-label={`Associar tarefas a ${p.titulo}`} onClick={() => setTarefas(p as PosicaoCompleta)} />
            <Button size="small" icon={<ColumnHeightOutlined />} title={(p as PosicaoCompleta).disposicao === 'COLUNA' ? 'Filhos em linha' : 'Filhos em coluna'}
              aria-label={`Disposição dos filhos de ${p.titulo}`}
              onClick={() => accao.mutate({ url: `/projetos/${projecto.id}/organigrama/disposicao`, dados: { posicoes: [p.id], disposicao: (p as PosicaoCompleta).disposicao === 'COLUNA' ? null : 'COLUNA' } })} />
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
    <Card loading={q.isLoading} ref={refOrganigrama} title="Organigrama" extra={<ImpressaoSeparador alvo={refOrganigrama} titulo="Organigrama" projecto={projecto} desactivado={!o} />}>
      {o && (
        <>
          {/* Indicadores em linha, como no legado (.porg-kpis, projectos_organigrama.js:249-352) */}
          <Flex gap={16} wrap style={{ marginBottom: 12, fontSize: 13 }} data-testid="kpis-organigrama">
            <Typography.Text type="secondary">Posições: <Typography.Text strong>{o.posicoes.length}</Typography.Text></Typography.Text>
            <Typography.Text type="secondary">Membros alocados: <Typography.Text strong>{o.totais.alocados} de {o.totais.membros}</Typography.Text></Typography.Text>
            {o.totais.vagas > 0 && <Typography.Text type="secondary">Vagas previstas: <Typography.Text strong>{o.totais.vagas}</Typography.Text></Typography.Text>}
            {o.sem_posicao.length > 0 && <Typography.Text type="warning">Sem posição: <strong>{o.sem_posicao.length}</strong></Typography.Text>}
            <Typography.Text type="secondary">
              Mapeado: orçamentado <Typography.Text strong>{formatarKz(o.totais.orcamento)}</Typography.Text> · executado{' '}
              <Typography.Text strong type={corConsumo(consumo(o.totais.executado, o.totais.orcamento))}>{formatarKz(o.totais.executado)}</Typography.Text>
              {consumo(o.totais.executado, o.totais.orcamento) !== null && ` (${consumo(o.totais.executado, o.totais.orcamento)?.toLocaleString('pt-PT')}%)`}
            </Typography.Text>
            {o.totais.tarefas_sem_posicao > 0 && <Typography.Text type="warning">Tarefas sem posição: <strong>{o.totais.tarefas_sem_posicao}</strong></Typography.Text>}
            {o.totais.orcamento_sem_responsavel > 0 && <Typography.Text type="warning">Orçamento sem responsável: <strong>{o.totais.orcamento_sem_responsavel}</strong> linha(s)</Typography.Text>}
          </Flex>
          {acc.gerir && (
            <Flex gap={8} wrap style={{ marginBottom: 12 }} className="imp-nao-imprimir">
              <Button type="primary" icon={<PlusOutlined />} onClick={() => setPosicao({ cor: 'azul' })}>Nova posição</Button>
              <Button icon={<UnorderedListOutlined />} onClick={() => setLote(true)}>Várias posições</Button>
              {o.posicoes.length > 0 && <Button icon={<DollarOutlined />} onClick={() => setMapear(true)}>Mapear orçamento</Button>}
              {o.posicoes.length > 0 && (
                <Dropdown menu={{
                  items: [{ key: 'FINAIS', label: 'Posições finais em coluna' }, { key: 'LINHA', label: 'Tudo em linha' }],
                  onClick: ({ key }) => accao.mutate({ url: `/projetos/${projecto.id}/organigrama/disposicao`, dados: { global: key } }),
                }}>
                  <Button>Disposição <DownOutlined /></Button>
                </Dropdown>
              )}
              {o.posicoes.length === 0 && (
                <Button icon={<ApartmentOutlined />} loading={accao.isPending} onClick={() => accao.mutate({ url: `/projetos/${projecto.id}/organigrama/modelo` })}>Criar a partir do modelo de obra</Button>
              )}
            </Flex>
          )}
          {/* legado (.porg-grid): árvore à esquerda e, à direita, a equipa sem posição */}
          <Row gutter={[16, 16]}>
            <Col xs={24} xl={o.sem_posicao.length > 0 ? 18 : 24}>
              {arvore.length ? <Tree treeData={arvore} defaultExpandAll blockNode selectable={false} titleRender={titulo} key={arvore.length} /> : <Empty description="Organigrama vazio" />}
            </Col>
            {o.sem_posicao.length > 0 && (
              <Col xs={24} xl={6}>
                <Card size="small" title={`Equipa sem posição (${o.sem_posicao.length})`}>
                  <Space wrap>{o.sem_posicao.map((m) => <Tag key={m.id}>{m.nome}{m.papel ? ` · ${m.papel}` : ''}</Tag>)}</Space>
                </Card>
              </Col>
            )}
          </Row>
          <ModalPosicao projectoId={projecto.id} posicao={posicao} posicoes={o.posicoes} aoFechar={() => setPosicao(null)} />
          <ModalAlocar projectoId={projecto.id} posicao={alocar} organigrama={o} aoFechar={() => setAlocar(null)} />
          <ModalPosicoesLote aberto={lote} projectoId={projecto.id} posicoes={o.posicoes} aoFechar={() => setLote(false)} />
          <ModalAssociarTarefas projectoId={projecto.id} posicao={tarefas} aoFechar={() => setTarefas(null)} />
          <ModalMapearOrcamento aberto={mapear} projectoId={projecto.id} organigrama={o} aoFechar={() => setMapear(false)} />
        </>
      )}
    </Card>
  );
}

/**
 * Posição do organigrama. Na criação há «Grade | Linhas»: uma posição por linha, pelo mesmo POST e com as mesmas regras
 * (`dadosPosicao`); a posição superior escolhida na grade (ex.: «Nova posição subordinada») fica como omissão.
 */
function ModalPosicao({ projectoId, posicao, posicoes, aoFechar }: { projectoId: number; posicao: Partial<Posicao> | null; posicoes: Posicao[]; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  const campos = useMemo<CampoFormulario[]>(() => [
    { nome: 'titulo', rotulo: 'Título', obrigatorio: true, largura: 220, maxLength: 255, aliases: ['Posição', 'Cargo'] },
    { nome: 'area', rotulo: 'Área', largura: 140, maxLength: 30 },
    { nome: 'vagas', rotulo: 'Vagas', tipo: 'numero', min: 0, precisao: 0, omissao: 1, largura: 80 },
    { nome: 'no_pai_id', rotulo: 'Posição superior', tipo: 'select', largura: 200, aliases: ['Superior'], placeholder: 'Nenhuma (topo)',
      opcoes: posicoes.map((p) => ({ value: p.id, label: p.titulo })) },
    { nome: 'cor', rotulo: 'Cor', tipo: 'select', omissao: 'azul', largura: 110, opcoes: Object.keys(CORES).map((c) => ({ value: c, label: c })) },
    { nome: 'descricao', rotulo: 'Descrição', emLinhas: 'opcional', largura: 220, maxLength: 5000 },
    { nome: 'apoio', rotulo: 'Apoio (staff)', tipo: 'personalizado', emLinhas: 'opcional', omissao: false, largura: 90, aliases: ['Apoio'], converter: simNao,
      celula: ({ valor, aoMudar, rotulo }) => <Checkbox aria-label={rotulo} checked={!!valor} onChange={(e) => aoMudar(e.target.checked)} /> },
  ], [posicoes]);
  useEffect(() => {
    if (posicao) { form.resetFields(); form.setFieldsValue({ vagas: 1, ...posicao, apoio: !!posicao.apoio }); }
  }, [posicao, form]);
  return (
    <ModalFormulario chave="projectos.organigrama" titulo={posicao?.id ? `Editar «${posicao.titulo}»` : 'Nova posição'} aberto={!!posicao} aoFechar={aoFechar}
      criacao={!!posicao && !posicao.id} form={form} campos={campos} nomeRegistos="posições do organigrama" invalidar={[['projectos']]}
      gravarLinha={(r) => enviar('post', `/projetos/${projectoId}/organigrama/posicoes`, dadosPosicao(r))}
      nota="Uma linha por posição. A posição superior tem de existir já (para criar uma árvore nova de uma vez use «Várias posições»). Linhas deixadas em branco são ignoradas."
      onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = dadosPosicao(v);
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
    </ModalFormulario>
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
