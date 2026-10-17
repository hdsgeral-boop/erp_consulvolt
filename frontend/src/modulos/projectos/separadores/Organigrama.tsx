import { Avatar, Button, Card, Checkbox, Col, Descriptions, Drawer, Dropdown, Empty, Flex, Form, Input, InputNumber, Modal, Row, Select, Space, Tag, Typography, type MenuProps } from 'antd';
import {
  ApartmentOutlined, ArrowDownOutlined, ArrowUpOutlined, ColumnHeightOutlined, DeleteOutlined, DollarOutlined, DownOutlined, EditOutlined, OrderedListOutlined,
  MailOutlined, MoreOutlined, PhoneOutlined, PlusOutlined, UnorderedListOutlined, UserAddOutlined,
} from '@ant-design/icons';
import { ModalAssociarTarefas, ModalMapearOrcamento, ModalPosicoesLote, type PosicaoCompleta } from './OrganigramaAccoes';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState, useRef } from 'react';
import { enviar, obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Organigrama, Posicao } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { larguraModal } from '@/componentes/responsivo';
import { ocupantes, QuadroOrganigrama, textoVagas, type PosicaoQuadro } from './organigrama/QuadroOrganigrama';
import { iniciais } from './organigrama/layout';
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
  const [detalheId, setDetalheId] = useState<number | null>(null);
  const accao = useAccao({ invalidar: [['projectos']] });
  const o = q.data;
  const refOrganigrama = useRef<HTMLDivElement>(null);

  const porId = useMemo(() => new Map((o?.posicoes ?? []).map((p) => [p.id, p as PosicaoQuadro])), [o]);
  const detalhe = detalheId !== null ? porId.get(detalheId) ?? null : null;
  const superior = detalhe?.no_pai_id ? porId.get(detalhe.no_pai_id) : undefined;
  const subordinadas = detalhe ? (o?.posicoes ?? []).filter((x) => x.no_pai_id === detalhe.id) : [];

  /** Menu «⋯» do cartão (acções do legado na caixa: .porg-accoes, projectos_organigrama.js:285-293). */
  const menuPosicao = (p: PosicaoQuadro): MenuProps['items'] => [
    { key: 'alocar', icon: <UserAddOutlined />, label: 'Alocar membros' },
    { key: 'tarefas', icon: <OrderedListOutlined />, label: 'Associar tarefas' },
    { key: 'sub', icon: <PlusOutlined />, label: 'Nova subposição' },
    { key: 'editar', icon: <EditOutlined />, label: 'Editar posição' },
    { type: 'divider' },
    { key: 'subir', icon: <ArrowUpOutlined />, label: 'Subir na ordem' },
    { key: 'descer', icon: <ArrowDownOutlined />, label: 'Descer na ordem' },
    { key: 'disposicao', icon: <ColumnHeightOutlined />, label: p.disposicao === 'COLUNA' ? 'Subposições lado a lado' : 'Subposições em coluna' },
    { type: 'divider' },
    { key: 'eliminar', icon: <DeleteOutlined />, label: 'Eliminar posição', danger: true },
  ];
  const executar = (p: PosicaoQuadro, chave: string) => {
    const url = `/projetos/${projecto.id}/organigrama`;
    switch (chave) {
      case 'alocar': setAlocar(p); break;
      case 'tarefas': setTarefas(p as PosicaoCompleta); break;
      case 'sub': setPosicao({ no_pai_id: p.id, cor: p.cor }); break;
      case 'editar': setPosicao(p); break;
      case 'subir': accao.mutate({ url: `${url}/posicoes/${p.id}/arrumar`, dados: { acao: 'SUBIR' } }); break;
      case 'descer': accao.mutate({ url: `${url}/posicoes/${p.id}/arrumar`, dados: { acao: 'DESCER' } }); break;
      case 'disposicao': accao.mutate({ url: `${url}/disposicao`, dados: { posicoes: [p.id], disposicao: p.disposicao === 'COLUNA' ? null : 'COLUNA' } }); break;
      case 'eliminar':
        Modal.confirm({ title: `Eliminar a posição «${p.titulo}»?`, content: 'As subposições passam a reportar à posição superior e os ocupantes ficam sem posição.', okText: 'Eliminar',
          okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => { setDetalheId(null); return accao.mutateAsync({ metodo: 'delete', url: `${url}/posicoes/${p.id}` }); } });
        break;
      default:
    }
  };

  return (
    <Card loading={q.isLoading} ref={refOrganigrama} title="Organigrama">
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
          {/* legado (.porg-grid): esquema à esquerda e, à direita, a equipa sem posição */}
          <Row gutter={[16, 16]}>
            <Col xs={24} xl={o.sem_posicao.length > 0 ? 18 : 24}>
              {o.posicoes.length
                ? <QuadroOrganigrama posicoes={o.posicoes as PosicaoQuadro[]} titulo={`Organigrama — ${projecto.codigo ? `${projecto.codigo} ` : ''}${projecto.nome}`}
                    aoAbrir={(p) => setDetalheId(p.id)} accoes={acc.gerir ? menuPosicao : undefined} aoAccao={executar} />
                : <Empty description="Organigrama vazio" />}
            </Col>
            {o.sem_posicao.length > 0 && (
              <Col xs={24} xl={6}>
                <Card size="small" title={`Equipa sem posição (${o.sem_posicao.length})`}>
                  <Space direction="vertical" size={6} style={{ width: '100%' }}>
                    {o.sem_posicao.map((m) => (
                      <Flex key={m.id} align="center" gap={8}>
                        <Avatar size={24} style={{ flex: 'none', fontSize: 11 }}>{iniciais(m.nome)}</Avatar>
                        <div style={{ minWidth: 0 }}>
                          <Typography.Text ellipsis style={{ display: 'block' }}>{m.nome}</Typography.Text>
                          {m.papel && <Typography.Text type="secondary" style={{ fontSize: 11 }}>{m.papel}</Typography.Text>}
                        </div>
                      </Flex>
                    ))}
                  </Space>
                  {acc.gerir && <Typography.Text type="secondary" style={{ fontSize: 11, display: 'block', marginTop: 8 }}>Use «Alocar membros» no menu ⋯ da posição.</Typography.Text>}
                </Card>
              </Col>
            )}
          </Row>
          <Drawer open={!!detalhe} onClose={() => setDetalheId(null)} width={larguraModal(460)} title={detalhe?.titulo}
            extra={detalhe && acc.gerir && (
              <Dropdown trigger={['click']} menu={{ items: menuPosicao(detalhe), onClick: ({ key }) => executar(detalhe, String(key)) }}>
                <Button icon={<MoreOutlined />}>Acções</Button>
              </Dropdown>
            )}>
            {detalhe && <DetalhePosicao p={detalhe} superior={superior?.titulo} subordinadas={subordinadas.map((x) => x.titulo)} aoIr={(id) => setDetalheId(id)}
              idsSubordinadas={subordinadas.map((x) => x.id)} idSuperior={superior?.id} />}
          </Drawer>
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

/** Detalhe da posição (projOrgDetalhe do legado): ocupantes com contactos, vagas, hierarquia, tarefas e valores. */
function DetalhePosicao({ p, superior, idSuperior, subordinadas, idsSubordinadas, aoIr }: {
  p: PosicaoQuadro; superior?: string; idSuperior?: number; subordinadas: string[]; idsSubordinadas: number[]; aoIr: (id: number) => void;
}) {
  const os = ocupantes(p);
  const v = textoVagas(os.length, p.vagas);
  const c = consumo(p.valores.executado, p.valores.orcamento);
  return (
    <Space direction="vertical" size="middle" style={{ width: '100%' }}>
      <Descriptions size="small" column={1} bordered items={[
        { key: 'a', label: 'Área', children: p.area || '—' },
        { key: 'v', label: 'Vagas', children: <Typography.Text type={v.excesso ? 'danger' : v.falta ? 'warning' : undefined}>{v.texto}{v.excesso ? ' — excedido' : ''}</Typography.Text> },
        { key: 's', label: 'Reporta a', children: superior && idSuperior ? <Button type="link" size="small" style={{ padding: 0 }} onClick={() => aoIr(idSuperior)}>{superior}</Button> : '— (topo)' },
        { key: 'f', label: 'Subposições', children: subordinadas.length ? <Space wrap size={4}>{subordinadas.map((t, i) => <Tag key={idsSubordinadas[i]} style={{ cursor: 'pointer' }} onClick={() => aoIr(idsSubordinadas[i])}>{t}</Tag>)}</Space> : '—' },
        { key: 't', label: 'Tarefas associadas', children: p.tarefas?.length ?? 0 },
        ...(p.apoio ? [{ key: 'ap', label: 'Tipo', children: <Tag color="purple">Apoio (staff)</Tag> }] : []),
        ...(p.descricao ? [{ key: 'd', label: 'Descrição', children: p.descricao }] : []),
      ]} />
      <div>
        <Typography.Text strong>Ocupantes ({os.length})</Typography.Text>
        {os.length ? os.map((m) => (
          <Flex key={m.id} gap={10} align="center" style={{ marginTop: 8 }}>
            <Avatar size={36}>{iniciais(m.nome)}</Avatar>
            <div style={{ minWidth: 0 }}>
              <Typography.Text strong={m.id === p.membro_responsavel_id} style={{ display: 'block' }}>{m.nome}{m.id === p.membro_responsavel_id && <Tag color="gold" style={{ marginLeft: 6 }}>Responsável</Tag>}</Typography.Text>
              <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{[m.cargo, m.papel].filter(Boolean).join(' · ') || '—'}</Typography.Text>
              <Space size={12} wrap style={{ fontSize: 12 }}>
                {m.telefone && <a href={`tel:${m.telefone.replace(/[^\d+]/g, '')}`}><PhoneOutlined /> {m.telefone}</a>}
                {m.email && <a href={`mailto:${m.email}`}><MailOutlined /> {m.email}</a>}
              </Space>
            </div>
          </Flex>
        )) : <Typography.Paragraph type="secondary" italic style={{ marginTop: 4 }}>Sem ocupante.</Typography.Paragraph>}
      </div>
      {(Number(p.valores.orcamento) > 0 || Number(p.valores.executado) > 0 || Number(p.valores_ramo?.orcamento ?? 0) > 0) && (
        <Descriptions size="small" column={1} title="Valores" items={[
          { key: 'o', label: 'Orçamentado', children: formatarKz(p.valores.orcamento) },
          { key: 'e', label: 'Executado', children: <Typography.Text type={corConsumo(c)}>{formatarKz(p.valores.executado)}{c !== null ? ` (${c.toLocaleString('pt-PT')}%)` : ''}</Typography.Text> },
          ...(Number(p.valores.orcamento_qtd ?? 0) > 0 ? [{ key: 'q', label: 'Mapeado por qtd.', children: formatarKz(p.valores.orcamento_qtd) }] : []),
          ...((p.valores_ramo?.subposicoes ?? 0) > 0 ? [{ key: 'r', label: 'Com as subposições', children: `orç. ${formatarKz(p.valores_ramo?.orcamento)} · exec. ${formatarKz(p.valores_ramo?.executado)}` }] : []),
        ]} />
      )}
    </Space>
  );
}
