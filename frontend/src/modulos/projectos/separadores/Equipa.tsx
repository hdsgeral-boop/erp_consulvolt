import { Button, Card, Flex, Form, Input, InputNumber, Modal, Popconfirm, Radio, Space, Tag, Typography, theme } from 'antd';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { DeleteOutlined, EditOutlined, PlusOutlined, ScheduleOutlined, UsergroupAddOutlined } from '@ant-design/icons';
import { useEffect, useMemo, useState, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { enviar, obterPagina } from '@/api/cliente';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { useColaboradores } from '@/modulos/rh/comum/consultas';
import { dadosMembro, TIPOS_MEMBRO, validarLinhaMembro } from '../comum/linhas';
import { SeletorTerceiro } from '@/modulos/contab/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { formatarNumero } from '@/utilitarios/formatacao';
import { EtiquetaProjectos, rotuloProjectos, SeletorColaborador, useEquipa } from '../comum/componentes';
import type { Membro } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { ImpressaoSeparador } from '../comum/ImpressaoSeparador';
import { ModalCargaTrabalho } from './CargaTrabalho';
import { scrollTabela } from '@/componentes/responsivo';

import { TabelaComModos } from '@/componentes/vistas';
/** Equipa do projecto: internos (colaboradores), terceiros e nomes livres, com papel e horas/dia alocadas (1 a 8). */
export function SeparadorEquipa({ projecto, acc }: PropsSeparador) {
  const e = useEquipa(projecto.id);
  const { token } = theme.useToken();
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const [membro, setMembro] = useState<Partial<Membro> | null>(null);
  const [massa, setMassa] = useState(false);
  const [alterar, setAlterar] = useState(false);
  const [carga, setCarga] = useState(false);
  const remover = useAccao({ invalidar: [['projectos']], aoSucesso: () => setSeleccao([]) });
  const membros = e.data?.membros ?? [];
  const horas = membros.reduce((t, m) => t + Number(m.horas_alocadas ?? 0), 0);
  const posicao = (p: Membro['posicao']) => (!p ? '—' : typeof p === 'string' ? p : p.titulo);
  const refSeparador = useRef<HTMLDivElement>(null);

  return (
    <Card
      ref={refSeparador}
      title={`Equipa alocada (${membros.length})`}
      extra={
        <Space wrap>
          <Typography.Text type="secondary">{e.data?.equipa?.nome ? `${e.data.equipa.nome} · ` : ''}{formatarNumero(horas)} h/dia alocadas</Typography.Text>
          {/* Versão Actual (ui_projects.js:2637-2673): carga de trabalho por responsável e ordem de trabalho */}
          <Button size="small" icon={<ScheduleOutlined />} onClick={() => setCarga(true)} disabled={!membros.length}>Carga de trabalho</Button>
          <ImpressaoSeparador alvo={refSeparador} titulo="Equipa do projecto" projecto={projecto} desactivado={!membros.length} />
        </Space>
      }
    >
      {acc.gerir && (
        <Flex gap={8} wrap style={{ marginBottom: 12 }} className="imp-nao-imprimir">
          <Button type="primary" icon={<PlusOutlined />} onClick={() => setMembro({ tipo: 'INTERNO' })}>Adicionar membro</Button>
          <Button icon={<UsergroupAddOutlined />} onClick={() => setMassa(true)}>Adicionar vários colaboradores</Button>
          {seleccao.length > 0 && <Button icon={<EditOutlined />} onClick={() => setAlterar(true)}>Alterar {seleccao.length}</Button>}
          {seleccao.length > 0 && (
            <Popconfirm title={`Remover ${seleccao.length} membro(s) da equipa?`} okText="Remover" cancelText="Cancelar" okButtonProps={{ danger: true }}
              onConfirm={() => remover.mutate({ url: `/projetos/${projecto.id}/equipa/membros/remover`, dados: { ids: seleccao } })}>
              <Button danger icon={<DeleteOutlined />} loading={remover.isPending}>Remover</Button>
            </Popconfirm>
          )}
        </Flex>
      )}
      <TabelaComModos<Membro> scroll={scrollTabela()}
        rowKey="id"
        size="middle"
        loading={e.isFetching}
        dataSource={membros}
        pagination={false}
        rowSelection={acc.gerir ? { selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as number[]) } : undefined}
        columns={[
          // colunas do legado (ui_projects.js:2567-2654): ícone pelo tipo, NIF / BI, papel (e a posição por baixo), alocação
          { title: 'Colaborador / Entidade', dataIndex: 'nome', render: (v, m) => (
            <span title={rotuloProjectos(m.tipo)}>
              <IconeFa nome={m.tipo === 'INTERNO' ? 'user-tie' : m.tipo === 'TERCEIRO' ? 'building' : 'user'}
                style={{ color: m.tipo === 'INTERNO' ? token.colorPrimary : m.tipo === 'TERCEIRO' ? token.colorWarning : token.colorTextSecondary, marginRight: 6 }} />
              {v}
            </span>
          ) },
          { title: 'NIF / BI', dataIndex: 'nif', render: (v) => v ?? '—' },
          { title: 'Papel / Função', dataIndex: 'papel', render: (v, m) => (
            <>{v ? <Tag color="blue">{v}</Tag> : '—'}{m.posicao && <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{posicao(m.posicao)}</Typography.Text>}</>
          ) },
          { title: 'Alocação (tempo)', dataIndex: 'horas_alocadas', align: 'right', render: (v) => (v ? `${formatarNumero(v)} h/dia` : '—') },
          { title: 'Tipo', dataIndex: 'tipo', responsive: ['lg'], render: (v) => <EtiquetaProjectos valor={v} /> },
          { title: '', key: 'acc', align: 'right', render: (_, m) => acc.gerir && <Button size="small" icon={<EditOutlined />} aria-label={`Editar ${m.nome}`} onClick={() => setMembro(m)} /> },
        ]}
      />
      <ModalMembro projectoId={projecto.id} membro={membro} aoFechar={() => setMembro(null)} />
      <ModalMassa projectoId={projecto.id} aberto={massa} aoFechar={() => setMassa(false)} />
      <ModalAlterar projectoId={projecto.id} ids={seleccao} aberto={alterar} aoFechar={() => { setAlterar(false); setSeleccao([]); }} />
      <ModalCargaTrabalho projectoId={projecto.id} aberto={carga} aoFechar={() => setCarga(false)} />
    </Card>
  );
}

/**
 * Membro da equipa (saveTeamMember). Na criação há «Grade | Linhas» (legado: formularios_linhas_config.js ›
 * projecto_equipa): uma linha por membro, escolhendo o tipo e preenchendo a coluna correspondente.
 */
function ModalMembro({ projectoId, membro, aoFechar }: { projectoId: number; membro: Partial<Membro> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const tipo = Form.useWatch('tipo', form);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  const criacao = !!membro && !membro.id;
  const colaboradores = useColaboradores();
  const terceiros = useQuery({
    queryKey: ['terceiros', 'lista', 'todos'],
    queryFn: () => obterPagina<{ id: number; nome: string; nif: string | null }>('/terceiros', { por_pagina: 500 }),
    enabled: criacao,
    retry: false,
  });
  const campos = useMemo<CampoFormulario[]>(() => [
    { nome: 'tipo', rotulo: 'Tipo de recurso', tipo: 'select', opcoes: TIPOS_MEMBRO, omissao: 'INTERNO', obrigatorio: true, largura: 150, aliases: ['Tipo'] },
    { nome: 'colaborador_id', rotulo: 'Colaborador (interno)', tipo: 'select', largura: 200, aliases: ['Colaborador'],
      opcoes: colaboradores.lista.filter((x) => x.estado !== 'INACTIVO').map((x) => ({ value: x.id, label: `${x.nome_completo}${x.nif ? ` (${x.nif})` : ''}` })),
      validar: (v, r) => validarLinhaMembro('colaborador_id', v, r) },
    { nome: 'terceiro_id', rotulo: 'Entidade externa', tipo: 'select', largura: 200, aliases: ['Terceiro', 'Subempreiteiro'],
      opcoes: (terceiros.data?.itens ?? []).map((t) => ({ value: t.id, label: `${t.nome.trim()}${t.nif ? ` (NIF ${t.nif})` : ''}` })),
      validar: (v, r) => validarLinhaMembro('terceiro_id', v, r) },
    { nome: 'nome_externo', rotulo: 'Nome livre', largura: 170, maxLength: 255, aliases: ['Nome'], validar: (v, r) => validarLinhaMembro('nome_externo', v, r) },
    { nome: 'papel', rotulo: 'Papel no projecto', largura: 150, maxLength: 100, aliases: ['Papel'] },
    { nome: 'horas_alocadas', rotulo: 'Horas/dia (interno)', tipo: 'numero', min: 1, max: 8, omissao: 8, largura: 100, aliases: ['Horas/dia'] },
  ], [colaboradores.lista, terceiros.data]);
  useEffect(() => {
    if (!membro) return;
    form.resetFields();
    form.setFieldsValue({ ...membro, horas_alocadas: membro.horas_alocadas ? Number(membro.horas_alocadas) : 8 });
  }, [membro, form]);
  return (
    <ModalFormulario chave="projectos.equipa" titulo={membro?.id ? `Editar ${membro.nome}` : 'Adicionar membro'} aberto={!!membro} aoFechar={aoFechar} criacao={criacao}
      form={form} campos={campos} nomeRegistos="membros da equipa" invalidar={[['projectos']]}
      gravarLinha={(r) => enviar('post', `/projetos/${projectoId}/equipa/membros`, dadosMembro(r))}
      nota="Uma linha por membro: escolha o tipo e preencha a coluna correspondente (colaborador, entidade externa ou nome livre). Linhas deixadas em branco são ignoradas."
      onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = dadosMembro(v);
        accao.mutate(membro?.id ? { metodo: 'put', url: `/projetos/${projectoId}/equipa/membros/${membro.id}`, dados } : { url: `/projetos/${projectoId}/equipa/membros`, dados });
      }}>
        <Form.Item name="tipo" label="Tipo">
          <Radio.Group optionType="button" disabled={!!membro?.id} options={TIPOS_MEMBRO} />
        </Form.Item>
        {tipo === 'INTERNO' && <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}><SeletorColaborador disabled={!!membro?.id} /></Form.Item>}
        {tipo === 'TERCEIRO' && <Form.Item name="terceiro_id" label="Terceiro (subempreiteiro)" rules={[{ required: true, message: 'Escolha o terceiro.' }]}><SeletorTerceiro style={{ width: '100%' }} disabled={!!membro?.id} /></Form.Item>}
        {tipo === 'LIVRE' && <Form.Item name="nome_externo" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>}
        <Space wrap>
          <Form.Item name="papel" label="Papel"><Input maxLength={100} style={{ width: 260, maxWidth: '100%' }} /></Form.Item>
          <Form.Item name="horas_alocadas" label="Horas/dia"><InputNumber min={1} max={8} step={0.5} style={{ width: 120 }} /></Form.Item>
        </Space>
      </Form>
    </ModalFormulario>
  );
}

function ModalMassa({ projectoId, aberto, aoFechar }: { projectoId: number; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ ids: number[]; papel?: string; horas_alocadas?: number }>();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => { if (aberto) form.setFieldsValue({ ids: [], papel: undefined, horas_alocadas: 8 }); }, [aberto, form]);
  return (
    <Modal title="Adicionar vários colaboradores" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Adicionar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/equipa/membros/massa`, dados: { tipo: 'INTERNO', ids: v.ids, papel: v.papel || null, horas_alocadas: v.horas_alocadas ?? null } })}>
        <Form.Item name="ids" label="Colaboradores" rules={[{ required: true, message: 'Escolha pelo menos um.' }]}><SeletorColaborador mode="multiple" /></Form.Item>
        <Space wrap>
          <Form.Item name="papel" label="Papel"><Input maxLength={100} style={{ width: 260, maxWidth: '100%' }} /></Form.Item>
          <Form.Item name="horas_alocadas" label="Horas/dia"><InputNumber min={1} max={8} step={0.5} style={{ width: 120 }} /></Form.Item>
        </Space>
      </Form>
    </Modal>
  );
}

function ModalAlterar({ projectoId, ids, aberto, aoFechar }: { projectoId: number; ids: number[]; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ papel?: string; horas_alocadas?: number }>();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => { if (aberto) form.resetFields(); }, [aberto, form]);
  return (
    <Modal title={`Alterar ${ids.length} membro(s)`} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Aplicar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
      <Typography.Paragraph type="secondary">Campos vazios ficam como estão.</Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/equipa/membros/alterar`, dados: { ids, papel: v.papel || null, horas_alocadas: v.horas_alocadas ?? null } })}>
        <Space wrap>
          <Form.Item name="papel" label="Papel"><Input maxLength={100} style={{ width: 260, maxWidth: '100%' }} /></Form.Item>
          <Form.Item name="horas_alocadas" label="Horas/dia"><InputNumber min={1} max={8} step={0.5} style={{ width: 120 }} /></Form.Item>
        </Space>
      </Form>
    </Modal>
  );
}
