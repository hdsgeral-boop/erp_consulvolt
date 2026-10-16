import { Card, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Tabs, Tag, theme } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { NavActivos } from './comum/NavActivos';
import { BotoesExportar } from '@/componentes/impressao';
import { BotaoCartao } from './inventario/BotaoCartao';
import { destinoActivo, estadoManutencao, manutencaoPlaneada } from './inventario/regrasInventario';
import { TIPOS } from './manutencao/regras';
import { BarraFiltros } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { BotaoCsv } from '@/modulos/contab/comum/Componentes';
import { useAccao } from '@/componentes/Accoes';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { somar } from '@/utilitarios/decimal';
import { pedidoTodasPaginas } from './comum/impressao';
import { campoActivoLinhas, SeletorActivo } from './comum/componentes';
import { enviar } from '@/api/cliente';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { filtroPeriodo, useListaPaginada } from './comum/paginacao';
import type { Manutencao as RegistoManutencao } from './comum/tipos';

import { TabelaComModos } from '@/componentes/vistas';
import { IconeFa } from '@/componentes/fluxos';
import { Ordens } from './manutencao/Ordens';
import { Planos } from './manutencao/Planos';
import { Requisicoes } from './manutencao/Requisicoes';

const SEPARADORES = ['ordens', 'planos', 'requisicoes', 'historico'] as const;
type Separador = (typeof SEPARADORES)[number];

/**
 * Activos › Manutenção (ecrã activos_manutencao): separadores do legado novo (renderMaintenanceView,
 * js/activos_manutencao_ui.js:78-105) — Ordens de manutenção, Planos, Requisições ao armazém e Histórico.
 */
export default function Manutencao() {
  const [aba, setAba] = useState<Separador>('ordens');
  return (
    <>
      <NavActivos actual="activos_manutencao" />
      <Tabs activeKey={aba} onChange={(k) => setAba(k as Separador)} destroyOnHidden
        items={[
          { key: 'ordens', label: <span><IconeFa nome="clipboard-check" /> Ordens de manutenção</span>, children: <Ordens aoIrPlanos={() => setAba('planos')} /> },
          { key: 'planos', label: <span><IconeFa nome="calendar-check" /> Planos</span>, children: <Planos aoAbrirOrdens={() => setAba('ordens')} /> },
          { key: 'requisicoes', label: <span><IconeFa nome="dolly" /> Requisições ao armazém</span>, children: <Requisicoes /> },
          { key: 'historico', label: <span><IconeFa nome="history" /> Histórico</span>, children: <Historico /> },
        ]} />
    </>
  );
}

/** Histórico de manutenções (areaHistorico, UI :806-840): registo simples e o que as ordens concluídas escrevem. */
function Historico() {
  const { pode } = useSessao();
  const { token } = theme.useToken();
  const navegar = useNavigate();
  const [activo, setActivo] = useState<number>();
  const [estado, setEstado] = useState<string>();
  const [nova, setNova] = useState(false);
  const [concluir, setConcluir] = useState<RegistoManutencao | null>(null);
  const [tipo, setTipo] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  // paginado e filtrado no servidor (ADR-064)
  const q = useListaPaginada<RegistoManutencao>(['activos', 'manutencoes'], '/ativos/manutencoes', { ativo_imobilizado_id: activo, estado, tipo, ...filtroPeriodo(periodo) });
  const eliminar = useAccao({ invalidar: [['activos']] });
  const gerir = pode('activos_manut');

  // Colunas e ordem do legado (areaHistorico, js/activos_manutencao_ui.js:806-840): Data, Activo («código — descrição»),
  // Ordem, Tipo, Trabalho, Resolução, Custo e Acções. Estado e Execução são extras do sistema novo (registo simples
  // planeado → «Executar»); a Execução fica escondida por omissão («Colunas ▾»).
  const nomeActivo = (r: RegistoManutencao) => (r.ativo_imobilizado
    ? `${r.ativo_imobilizado.codigo ? `${r.ativo_imobilizado.codigo} — ` : ''}${r.ativo_imobilizado.descricao ?? ''}`.trim()
    : '—');
  const colunas: ColunaApi<RegistoManutencao>[] = [
    { title: 'Data', dataIndex: 'data', render: formatarData, valorImpressao: (r) => formatarData(r.data) },
    { title: 'Activo', key: 'a', ocultavel: false, valorFiltro: nomeActivo, valorImpressao: nomeActivo, render: (_, r) => nomeActivo(r) },
    { title: 'Ordem', dataIndex: 'numero_ordem', render: (v) => v ?? '—', valorImpressao: (r) => r.numero_ordem ?? '—' },
    { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => TIPOS[v as keyof typeof TIPOS] ?? v, valorImpressao: (r) => TIPOS[r.tipo as keyof typeof TIPOS] ?? r.tipo },
    { title: 'Trabalho', dataIndex: 'descricao', ellipsis: true, width: 260, render: (v) => <small>{v ?? ''}</small>, valorImpressao: (r) => r.descricao ?? '' },
    { title: 'Resolução', dataIndex: 'resolucao', ellipsis: true, width: 220, render: (v) => <small>{v || '—'}</small>, valorImpressao: (r) => r.resolucao || '—' },
    { title: 'Custo', dataIndex: 'custo', align: 'right', total: true, render: (v) => <span style={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>{formatarKz(v ?? '0')}</span>, valorImpressao: (r) => formatarKz(r.custo ?? 0), totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.custo ?? '0'))) },
    {
      title: 'Estado', dataIndex: 'estado', valorImpressao: (r) => estadoManutencao(r.estado),
      render: (v) => <Tag color={manutencaoPlaneada(v) ? 'warning' : 'success'}>{estadoManutencao(v)}</Tag>,
    },
    { title: 'Execução', dataIndex: 'data_execucao', ocultaPorOmissao: true, render: formatarData, valorImpressao: (r) => formatarData(r.data_execucao) },
  ];
  // «Custo acumulado» do cabeçalho do legado: só quando a lista inteira cabe na página (paginada no servidor)
  const completa = q.itens.length >= (q.paginacao.total ?? 0);
  const custoAcumulado = completa ? somar(q.itens.map((l) => l.custo ?? '0')) : null;

  return (
    <>
      <Card
        title={<span><IconeFa nome="history" /> Histórico de manutenções</span>}
        extra={(
          <Space wrap size={16}>
            {custoAcumulado !== null && (
              <span style={{ fontSize: '0.9rem', color: token.colorTextSecondary, fontWeight: 400 }}>Custo acumulado: <strong style={{ color: token.colorText }}>{formatarKz(custoAcumulado)}</strong></span>
            )}
            {gerir && <BotaoCartao icone="plus" variante="primario" onClick={() => setNova(true)}>Registar Manutenção</BotaoCartao>}
          </Space>
        )}
      >
        <BarraFiltros accoes={<>
          <BotoesExportar desactivado={!q.itens.length} obterPedido={() => pedidoTodasPaginas('/ativos/manutencoes', { ativo_imobilizado_id: activo, estado, tipo, ...filtroPeriodo(periodo) }, {
            titulo: 'Manutenções de activos',
            periodo: periodo?.[0] || periodo?.[1] ? `${periodo?.[0]?.format('DD/MM/YYYY') ?? '…'} a ${periodo?.[1]?.format('DD/MM/YYYY') ?? '…'}` : undefined,
            filtros: [estado ? `Estado: ${estado}` : null, tipo ? `Tipo: ${tipo}` : null, activo ? `Activo: #${activo}` : null],
            colunas,
          })} />
          <BotaoCsv nome="manutencoes_pagina" linhas={q.itens} colunas={[
            { titulo: 'Nº Inventário', valor: (l) => l.ativo_imobilizado?.codigo }, { titulo: 'Data', valor: (l) => formatarData(l.data) },
            { titulo: 'Activo', valor: (l) => l.ativo_imobilizado?.descricao }, { titulo: 'Tipo', valor: (l) => l.tipo },
            { titulo: 'Planeado', valor: (l) => l.descricao }, { titulo: 'Executado', valor: (l) => l.resolucao },
            { titulo: 'Custo', valor: (l) => l.custo, numerico: true }, { titulo: 'Estado', valor: (l) => estadoManutencao(l.estado) },
          ]} />
        </>}>
            <SeletorActivo allowClear value={activo} onChange={setActivo} style={{ width: 320 }} />
            <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 160 }}
              options={[{ value: 'PLANEADA', label: 'Planeada' }, { value: 'CONCLUIDA', label: 'Concluída' }]} />
            <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 150 }}
              options={[{ value: 'PREVENTIVA', label: 'Preventiva' }, { value: 'CORRECTIVA', label: 'Correctiva' }]} />
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} allowEmpty={[true, true]} placeholder={['Desde', 'Até']} />
        </BarraFiltros>
        <TabelaComModos<RegistoManutencao>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={q.itens}
          pagination={q.paginacao}
          scroll={{ x: 'max-content' }}
          columns={colunas}
          tituloAccoes="Acções"
          locale={{ emptyText: 'Sem manutenções registadas.' }}
          accoes={(r) => [
            { icone: 'eye', titulo: 'Ficha do activo', onClick: () => navegar(destinoActivo(r.ativo_imobilizado_id)) },
            ...(gerir ? [manutencaoPlaneada(r.estado)
              ? { icone: 'play', titulo: 'Executar', texto: 'Executar', variante: 'sucesso' as const, cheio: true, onClick: () => setConcluir(r) }
              : { icone: 'check-double', titulo: 'Manutenção concluída', cor: '#059669', desactivado: 'Manutenção concluída' },
            {
              icone: 'trash', titulo: 'Eliminar o registo', variante: 'perigo' as const,
              confirmar: { titulo: 'Remover esta manutenção?', textoOk: 'Remover', perigo: true },
              onClick: () => eliminar.mutateAsync({ metodo: 'delete', url: `/ativos/manutencoes/${r.id}` }).catch(() => undefined),
            }] : []),
          ]}
        />
      </Card>
      <ModalManutencao aberto={nova} aoFechar={() => setNova(false)} />
      <ModalConcluir registo={concluir} aoFechar={() => setConcluir(null)} />
    </>
  );
}

/** Data da grade (dayjs) ou da vista Linhas ('AAAA-MM-DD') → API. */
const dataDe = (d: unknown): string | null => (dayjs.isDayjs(d) ? dataApi(d) ?? null : typeof d === 'string' && d ? d : null);

/** Regras de gravação da manutenção, comuns à grade e à vista Linhas (sem custo = 0; com custo o servidor conclui-a). */
export function dadosManutencao(v: Record<string, unknown>): Record<string, unknown> {
  return { ativo_imobilizado_id: v.ativo_imobilizado_id, tipo: v.tipo, data: dataDe(v.data), descricao: v.descricao || null, custo: v.custo ?? 0 };
}

const TIPOS_MANUTENCAO = [{ value: 'PREVENTIVA', label: 'Preventiva' }, { value: 'CORRECTIVA', label: 'Correctiva' }];

const CAMPOS_MANUTENCAO: CampoFormulario[] = [
  campoActivoLinhas(),
  { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', obrigatorio: true, opcoes: TIPOS_MANUTENCAO, omissao: 'PREVENTIVA', largura: 130 },
  { nome: 'data', rotulo: 'Data', tipo: 'data', obrigatorio: true, omissao: () => dayjs().format('YYYY-MM-DD'), largura: 140 },
  { nome: 'custo', rotulo: 'Custo (Kz)', tipo: 'numero', min: 0, precisao: 2, largura: 130, aliases: ['Custo'] },
  { nome: 'descricao', rotulo: 'Descrição', tipo: 'textoLongo', maxLength: 5000, largura: 280, aliases: ['Planeado'] },
];

/** Registo de uma manutenção. Com custo > 0 o servidor grava-a logo como concluída. */
export function ModalManutencao({ aberto, activoId, aoFechar }: { aberto: boolean; activoId?: number; aoFechar: () => void }) {
  const [form] = Form.useForm<{ ativo_imobilizado_id: number; tipo: string; data: dayjs.Dayjs; descricao?: string; custo?: number }>();
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (aberto) {
      form.resetFields();
      form.setFieldsValue({ ativo_imobilizado_id: activoId, tipo: 'PREVENTIVA', data: dayjs() });
    }
  }, [aberto, activoId, form]);
  return (
    <ModalFormulario chave="activos.manutencoes" titulo="Registar manutenção" aberto={aberto} aoFechar={aoFechar} criacao form={form}
      campos={activoId ? CAMPOS_MANUTENCAO.filter((c) => c.nome !== 'ativo_imobilizado_id') : CAMPOS_MANUTENCAO}
      nomeRegistos="manutenções" invalidar={[['activos']]} larguraLinhas={1000}
      gravarLinha={(r) => enviar('post', '/ativos/manutencoes', dadosManutencao(activoId ? { ...r, ativo_imobilizado_id: activoId } : r))}
      nota="Uma linha por manutenção. Com custo, a manutenção fica concluída. Linhas deixadas em branco são ignoradas."
      onOk={() => form.submit()} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: '/ativos/manutencoes', dados: dadosManutencao(v) })}>
        <Form.Item name="ativo_imobilizado_id" label="Activo" rules={[{ required: true, message: 'Escolha o activo.' }]}>
          <SeletorActivo disabled={!!activoId} />
        </Form.Item>
        <Space wrap>
          <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
            <Select style={{ width: 160 }} options={TIPOS_MANUTENCAO} />
          </Form.Item>
          <Form.Item name="data" label="Data" rules={[{ required: true }]}>
            <DatePicker format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item name="custo" label="Custo (Kz)" tooltip="Com custo, a manutenção fica concluída">
            <InputNumber min={0} precision={2} style={{ width: 160 }} />
          </Form.Item>
        </Space>
        <Form.Item name="descricao" label="Descrição">
          <Input.TextArea rows={3} maxLength={5000} />
        </Form.Item>
      </Form>
    </ModalFormulario>
  );
}

function ModalConcluir({ registo, aoFechar }: { registo: RegistoManutencao | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ resolucao: string; custo?: number }>();
  const { token } = theme.useToken();
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (registo) form.setFieldsValue({ resolucao: '', custo: registo.custo ? Number(registo.custo) : undefined });
  }, [registo, form]);
  return (
    <Modal title="Executar Manutenção" open={!!registo} onCancel={aoFechar} onOk={() => form.submit()} okText="Finalizar Manutenção" cancelText="Cancelar"
      okButtonProps={{ style: { background: token.colorSuccess, borderColor: token.colorSuccess } }} confirmLoading={accao.isPending} destroyOnHidden>
      <div style={{ marginBottom: 16, background: token.colorFillAlter, padding: 12, borderRadius: token.borderRadius }}>
        <div style={{ fontSize: '0.9rem', color: token.colorTextSecondary }}>Trabalho Planeado:</div>
        <div style={{ marginTop: 4, fontWeight: 600 }}>{registo?.descricao || '---'}</div>
      </div>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/ativos/manutencoes/${registo?.id}/executar`, dados: { ...v, custo: v.custo ?? 0 } })}>
        <Form.Item name="resolucao" label="Trabalho Efectuado (Resolução)" rules={[{ required: true, message: 'Descreva a resolução.' }]}>
          <Input.TextArea rows={4} maxLength={5000} placeholder="Indique o que foi feito detalhadamente..." />
        </Form.Item>
        <Form.Item name="custo" label="Custo Final (Kz)">
          <InputNumber min={0} precision={2} style={{ width: 200 }} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
