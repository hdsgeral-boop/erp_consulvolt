import { Button, Card, DatePicker, Form, InputNumber, Popconfirm, Space, Table, Typography } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState, useRef } from 'react';
import { enviar, obter, obterPagina } from '@/api/cliente';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { dadosEquipamento, dadosHoras } from '../comum/linhas';
import { achatarWbs } from '../comum/regras';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { useAccao } from '@/componentes/Accoes';
import { SeletorActivo } from '@/modulos/activos/comum/componentes';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { EtiquetaProjectos, SeletorMembro, SeletorTarefa, useEquipa, useWbs } from '../comum/componentes';
import type { EquipamentosProjecto, FolhaHoras, UsoEquipamento } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { ImpressaoSeparador } from '../comum/ImpressaoSeparador';
import { scrollTabela } from '@/componentes/responsivo';

import { TabelaComModos } from '@/componentes/vistas';
/** Folhas de horas (só colaboradores internos da equipa) e imputação do uso de equipamentos (activos) às tarefas. */
export function SeparadorHoras({ projecto, acc }: PropsSeparador) {
  const horas = useQuery({ queryKey: ['projectos', 'horas', projecto.id], queryFn: () => obter<FolhaHoras[]>(`/projetos/${projecto.id}/horas`) });
  const equip = useQuery({ queryKey: ['projectos', 'equipamentos', projecto.id], queryFn: () => obter<EquipamentosProjecto>(`/projetos/${projecto.id}/equipamentos`) });
  const [novaHora, setNovaHora] = useState(false);
  const [novoEquip, setNovoEquip] = useState(false);
  const accao = useAccao({ invalidar: [['projectos']] });
  const usos = equip.data?.usos ?? [];
  const totalHoras = (horas.data ?? []).reduce((t, h) => t + Number(h.horas), 0);
  const tarefa = (codigo: string | null | undefined, nome: string | null | undefined, id: number | null) => (nome ? [codigo, nome].filter(Boolean).join(' — ') : id ? `#${id}` : '—');
  const refSeparador = useRef<HTMLDivElement>(null);

  return (
    <div ref={refSeparador}>
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <div className="imp-nao-imprimir" style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <ImpressaoSeparador alvo={refSeparador} titulo="Horas e equipamentos" projecto={projecto} />
      </div>
      <Card size="small" title={`Folhas de horas (${formatarNumero(totalHoras)} h)`} extra={acc.execucao && <Button type="primary" size="small" icon={<PlusOutlined />} onClick={() => setNovaHora(true)}>Registar horas</Button>}>
        <TabelaComModos<FolhaHoras> idVista="horas" scroll={scrollTabela()}
          rowKey="id"
          size="small"
          loading={horas.isFetching}
          dataSource={horas.data}
          pagination={{ pageSize: 20 }}
          columns={[
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Colaborador / membro', dataIndex: 'colaborador_nome', render: (v: string | null | undefined, h) => v ?? (h.colaborador_id ? `#${h.colaborador_id}` : '—') },
            { title: 'Tarefa', key: 't', render: (_, h) => tarefa(h.tarefa_codigo, h.tarefa_nome, h.tarefa_projeto_id) },
            { title: 'Horas', dataIndex: 'horas', align: 'right', render: formatarNumero },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaProjectos valor={v} /> },
            {
              title: '', key: 'acc', align: 'right',
              render: (_, h) => acc.eliminar && h.estado === 'REGISTADO' && (
                <Popconfirm title="Eliminar este registo de horas?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                  onConfirm={() => accao.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/horas/${h.id}` })}>
                  <Button size="small" danger icon={<DeleteOutlined />} />
                </Popconfirm>
              ),
            },
          ]}
        />
      </Card>
      <Card size="small" title={`Uso de equipamentos (${formatarKz(equip.data?.total ?? '0.00')} Kz)`} extra={acc.execucao && <Button size="small" icon={<PlusOutlined />} onClick={() => setNovoEquip(true)}>Imputar equipamento</Button>}>
        <TabelaComModos<UsoEquipamento> idVista="equipamentos" scroll={scrollTabela()}
          rowKey="id"
          size="small"
          loading={equip.isFetching}
          dataSource={usos}
          pagination={{ pageSize: 20 }}
          columns={[
            { title: 'Data', dataIndex: 'data', render: formatarData },
            { title: 'Equipamento', key: 'e', render: (_, m) => (m.ativo_codigo ? `${m.ativo_codigo}${m.ativo_descricao ? ` — ${m.ativo_descricao}` : ''}` : '—') },
            { title: 'Tarefa', key: 't', render: (_, m) => tarefa(m.tarefa_codigo, m.tarefa_nome, m.tarefa_projeto_id) },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
            { title: 'Custo (Kz)', dataIndex: 'montante', align: 'right', render: (v) => <ValorKz valor={v} /> },
            {
              title: '', key: 'acc', align: 'right',
              render: (_, m) => acc.eliminar && (
                <Popconfirm title="Eliminar este uso de equipamento?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                  onConfirm={() => accao.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/equipamentos/${m.id}` })}>
                  <Button size="small" danger icon={<DeleteOutlined />} />
                </Popconfirm>
              ),
            },
          ]}
        />
        {(equip.data?.por_equipamento ?? []).length > 0 && (
          <>
            <Typography.Text strong style={{ display: 'block', marginTop: 12 }}>Equipamentos alocados ao projecto</Typography.Text>
            <Table scroll={scrollTabela()} size="small" rowKey={(x) => x.ativo_codigo ?? '—'} pagination={false} dataSource={equip.data?.por_equipamento ?? []} style={{ marginTop: 8 }}
              columns={[
                { title: 'Equipamento', key: 'e', render: (_, x) => `${x.ativo_codigo ?? '—'}${x.ativo_descricao ? ` — ${x.ativo_descricao}` : ''}` },
                { title: 'Usos', dataIndex: 'usos', align: 'right' },
                { title: 'Horas', dataIndex: 'horas', align: 'right', render: formatarNumero },
                { title: 'Custo/hora médio', dataIndex: 'custo_hora_medio', align: 'right', render: (v: number | null) => (v === null ? '—' : formatarKz(v)) },
                { title: 'Custo (Kz)', dataIndex: 'custo', align: 'right', render: (v) => <ValorKz valor={v} /> },
                { title: 'Período', key: 'p', render: (_, x) => `${formatarData(x.de)} a ${formatarData(x.ate)}` },
                { title: 'Peso no custo', dataIndex: 'peso_pct', align: 'right', render: (v: number | null) => (v === null ? '—' : `${v.toLocaleString('pt-PT')}%`) },
              ]} />
          </>
        )}
        {(equip.data?.afetacoes ?? []).length > 0 && (
          <>
            <Typography.Text strong style={{ display: 'block', marginTop: 12 }}>Activos afectos ao projecto</Typography.Text>
            <Table scroll={scrollTabela()} size="small" rowKey="id" pagination={false} dataSource={equip.data?.afetacoes ?? []} style={{ marginTop: 8 }}
              columns={[
                { title: 'Activo', key: 'a', render: (_, f) => `${f.ativo_codigo ?? `#${f.ativo_imobilizado_id}`}${f.ativo_descricao ? ` — ${f.ativo_descricao}` : ''}` },
                { title: 'De', dataIndex: 'data_inicio', render: formatarData },
                { title: 'Até', dataIndex: 'data_fim', render: (v: string | null) => (v ? formatarData(v) : 'em curso') },
              ]} />
          </>
        )}
      </Card>
      <ModalHoras projectoId={projecto.id} aberto={novaHora} aoFechar={() => setNovaHora(false)} />
      <ModalEquipamento projectoId={projecto.id} aberto={novoEquip} aoFechar={() => setNovoEquip(false)} />
    </Space>
    </div>
  );
}

/** Opções das colunas Tarefa e Membro da vista Linhas (as mesmas listas dos selectores da grade). */
function useOpcoesProjecto(projectoId: number) {
  const w = useWbs(projectoId);
  const e = useEquipa(projectoId);
  return useMemo(() => ({
    tarefas: achatarWbs(w.data).map((t) => ({ value: t.id, label: `${'— '.repeat(t.nivel)}${t.codigo ? `${t.codigo} ` : ''}${t.nome}` })),
    membros: (e.data?.membros ?? []).map((m) => ({ value: m.id, label: `${m.nome}${m.papel ? ` (${m.papel})` : ''}` })),
  }), [w.data, e.data]);
}

const hoje = () => dayjs().format('YYYY-MM-DD');

/** Registar horas (saveTimesheet). «Grade | Linhas» (legado: projecto_horas): uma linha por dia/colaborador. */
function ModalHoras({ projectoId, aberto, aoFechar }: { projectoId: number; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  const opcoes = useOpcoesProjecto(projectoId);
  const campos = useMemo<CampoFormulario[]>(() => [
    { nome: 'membro_equipa_projeto_id', rotulo: 'Colaborador', tipo: 'select', obrigatorio: true, opcoes: opcoes.membros, largura: 230, aliases: ['Membro da equipa', 'Membro'] },
    { nome: 'tarefa_projeto_id', rotulo: 'Tarefa', tipo: 'select', obrigatorio: true, opcoes: opcoes.tarefas, largura: 230 },
    { nome: 'data', rotulo: 'Data', tipo: 'data', obrigatorio: true, omissao: hoje, largura: 150 },
    { nome: 'horas', rotulo: 'Horas', tipo: 'numero', obrigatorio: true, min: 0.25, max: 24, omissao: 8, largura: 90 },
  ], [opcoes]);
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ data: dayjs(), horas: 8 }); } }, [aberto, form]);
  return (
    <ModalFormulario chave="projectos.horas" titulo="Registar horas" aberto={aberto} aoFechar={aoFechar} criacao form={form} campos={campos}
      nomeRegistos="registos de horas" invalidar={[['projectos']]} gravarLinha={(r) => enviar('post', `/projetos/${projectoId}/horas`, dadosHoras(r))}
      nota="Uma linha por dia/colaborador (a tarefa escolhida na grade fica como omissão). Horas de externos ou subempreitadas não entram na imputação salarial nem no subsídio de produtividade."
      onOk={() => form.submit()} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/horas`, dados: dadosHoras(v) })}>
        {/* Versão Actual (ui_projects.js:5068-5125): grava o membro da equipa; externos e entidades contam para a carga e o mapa de horas, nunca para o subsídio */}
        <Form.Item name="membro_equipa_projeto_id" label="Membro da equipa" extra="Horas de externos ou subempreitadas não entram na imputação salarial nem no subsídio de produtividade." rules={[{ required: true, message: 'Escolha o membro da equipa.' }]}>
          <SeletorMembro projectoId={projectoId} />
        </Form.Item>
        <Form.Item name="tarefa_projeto_id" label="Tarefa" rules={[{ required: true, message: 'Escolha a tarefa.' }]}><SeletorTarefa projectoId={projectoId} /></Form.Item>
        <Space wrap>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="horas" label="Horas" rules={[{ required: true }]}><InputNumber min={0.25} max={24} step={0.5} style={{ width: 120 }} /></Form.Item>
        </Space>
      </Form>
    </ModalFormulario>
  );
}

/** Uso de equipamento (saveEquipmentLog). «Grade | Linhas» (legado: projecto_equipamento): custo = horas × custo/hora. */
function ModalEquipamento({ projectoId, aberto, aoFechar }: { projectoId: number; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  const h = Form.useWatch('horas', form);
  const c = Form.useWatch('custo_hora', form);
  const opcoes = useOpcoesProjecto(projectoId);
  const activos = useQuery({
    queryKey: ['activos', 'lista', 'ACTIVO'],
    queryFn: () => obterPagina<{ id: number; codigo: string | null; descricao: string }>('/ativos/bens', { estado: 'ACTIVO', por_pagina: 500 }),
    enabled: aberto,
    staleTime: 60_000,
  });
  const campos = useMemo<CampoFormulario[]>(() => [
    { nome: 'ativo_imobilizado_id', rotulo: 'Equipamento', tipo: 'select', obrigatorio: true, largura: 230, aliases: ['Activo', 'Equipamento (activo)'],
      opcoes: (activos.data?.itens ?? []).map((a) => ({ value: a.id, label: `${a.codigo ?? '—'} — ${a.descricao}` })) },
    { nome: 'tarefa_projeto_id', rotulo: 'Tarefa', tipo: 'select', obrigatorio: true, opcoes: opcoes.tarefas, largura: 230 },
    { nome: 'data', rotulo: 'Data', tipo: 'data', obrigatorio: true, omissao: hoje, largura: 150 },
    { nome: 'horas', rotulo: 'Horas', tipo: 'numero', obrigatorio: true, min: 0.25, omissao: 8, largura: 90 },
    { nome: 'custo_hora', rotulo: 'Custo/hora', tipo: 'numero', obrigatorio: true, min: 0, precisao: 2, largura: 110, aliases: ['Custo/hora (Kz)'] },
  ], [opcoes, activos.data]);
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ data: dayjs(), horas: 8 }); } }, [aberto, form]);
  return (
    <ModalFormulario chave="projectos.equipamento" titulo="Imputar uso de equipamento" aberto={aberto} aoFechar={aoFechar} criacao form={form} campos={campos}
      nomeRegistos="usos de equipamento" invalidar={[['projectos']]} gravarLinha={(r) => enviar('post', `/projetos/${projectoId}/equipamentos`, dadosEquipamento(r))}
      nota="Uma linha por utilização de equipamento numa tarefa (custo = horas × custo/hora). Linhas deixadas em branco são ignoradas."
      onOk={() => form.submit()} okText="Imputar" cancelText="Cancelar" confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/projetos/${projectoId}/equipamentos`, dados: dadosEquipamento(v) })}>
        <Form.Item name="ativo_imobilizado_id" label="Equipamento (activo)" rules={[{ required: true, message: 'Escolha o equipamento.' }]}><SeletorActivo apenasActivos /></Form.Item>
        <Form.Item name="tarefa_projeto_id" label="Tarefa" rules={[{ required: true, message: 'Escolha a tarefa.' }]}><SeletorTarefa projectoId={projectoId} /></Form.Item>
        <Space wrap>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="horas" label="Horas" rules={[{ required: true }]}><InputNumber min={0.25} step={0.5} style={{ width: 110 }} /></Form.Item>
          <Form.Item name="custo_hora" label="Custo/hora (Kz)" rules={[{ required: true, message: 'Indique o custo.' }]}><InputNumber min={0} precision={2} style={{ width: 150 }} /></Form.Item>
        </Space>
        <Typography.Text type="secondary">Custo a imputar: {formatarKz((Number(h ?? 0) * Number(c ?? 0)).toFixed(2), true)}</Typography.Text>
      </Form>
    </ModalFormulario>
  );
}
