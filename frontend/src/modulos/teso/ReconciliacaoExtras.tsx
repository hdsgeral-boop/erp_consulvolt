import { Button, Card, DatePicker, Descriptions, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Table, Tag, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, FolderOpenOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../contab/comum/Componentes';
import type { LinhaExtratoBancario } from './api';
import { SeletorContaFinanceira } from './comum';

export interface GrupoReconciliacao { extrato: number[]; lancamentos: number[] }
export interface RascunhoReconciliacao {
  id: number;
  codigo_conta: string;
  periodo_inicio: string | null;
  periodo_fim: string | null;
  grupos: GrupoReconciliacao[];
  observacoes: string | null;
  criado_por: string | null;
  atualizado_em: string | null;
}
interface ItemHistorico { id: number; reconciliacao_codigo: string; data: string; valor_total: string | null; estado: string; conta: string | null; tipo: string | null; grupos: number; motivo_anulacao: string | null }
interface DetalheReconciliacao {
  reconciliacao_codigo: string; data: string; estado: string; valor_total: string | null; conta: string | null; tipo: string | null;
  anulacao: { motivo: string; em: string } | null;
  grupos: { extrato: { id: number; data: string; referencia: string | null; descricao: string | null; tipo_dc: string; valor: string }[];
    lancamentos: { id: number; data_documento: string; numero_lan: string; numero_documento: string | null; descricao: string | null; tipo_dc: string; valor: string }[] }[];
}

/** Junta a selecção manual actual aos grupos de um rascunho (cada «gravar» acrescenta um grupo). */
export function juntarGrupo(grupos: GrupoReconciliacao[], extrato: number[], lancamentos: number[]): GrupoReconciliacao[] {
  if (!extrato.length && !lancamentos.length) return grupos;
  const usados = new Set(grupos.flatMap((g) => [...g.extrato.map((e) => `e${e}`), ...g.lancamentos.map((l) => `l${l}`)]));
  const novo = { extrato: extrato.filter((e) => !usados.has(`e${e}`)), lancamentos: lancamentos.filter((l) => !usados.has(`l${l}`)) };
  return novo.extrato.length || novo.lancamentos.length ? [...grupos, novo] : grupos;
}

/**
 * M-08: rascunhos da correspondência manual (o legado guardava o trabalho no localStorage). Grava a selecção actual como
 * grupo de um rascunho no servidor, abre um rascunho (repõe a selecção) e elimina.
 */
export function PainelRascunhos({ conta, extrato, lancamentos, aoAbrir }: {
  conta: string; extrato: number[]; lancamentos: number[]; aoAbrir: (r: RascunhoReconciliacao) => void;
}) {
  const { pode } = useSessao();
  const [escolhido, setEscolhido] = useState<number>();
  const rascunhos = useQuery({ queryKey: ['teso', 'reconciliacao', 'rascunhos', conta], queryFn: () => obter<RascunhoReconciliacao[]>('/tesouraria/reconciliacao/rascunhos', { codigo_conta: conta }) });
  const accao = useAccao<RascunhoReconciliacao>({ invalidar: [['teso', 'reconciliacao', 'rascunhos']], aoSucesso: (r) => r?.id && setEscolhido(r.id) });
  const actual = (rascunhos.data ?? []).find((r) => r.id === escolhido);
  if (!pode('teso_conc_confirmar')) return null;
  return (
    <Space wrap>
      <Select
        placeholder="Rascunhos"
        allowClear
        style={{ width: 230 }}
        value={escolhido}
        onChange={setEscolhido}
        loading={rascunhos.isFetching}
        options={(rascunhos.data ?? []).map((r) => ({ value: r.id, label: `#${r.id} · ${r.grupos.length} grupo(s) · ${formatarData(r.atualizado_em)}${r.criado_por ? ` · ${r.criado_por}` : ''}` }))}
        aria-label="Rascunhos de reconciliação"
      />
      <Button icon={<FolderOpenOutlined />} disabled={!actual} onClick={() => actual && aoAbrir(actual)}>Abrir</Button>
      <Button
        icon={<SaveOutlined />}
        disabled={!extrato.length && !lancamentos.length}
        loading={accao.isPending}
        onClick={() => accao.mutate(actual
          ? { metodo: 'put', url: `/tesouraria/reconciliacao/rascunhos/${actual.id}`, dados: { codigo_conta: conta, grupos: juntarGrupo(actual.grupos, extrato, lancamentos), observacoes: actual.observacoes } }
          : { url: '/tesouraria/reconciliacao/rascunhos', dados: { codigo_conta: conta, grupos: juntarGrupo([], extrato, lancamentos) } })}
      >
        Gravar rascunho
      </Button>
      {actual && (
        <Popconfirm title="Eliminar este rascunho?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
          onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/tesouraria/reconciliacao/rascunhos/${actual.id}` }).then(() => setEscolhido(undefined))}>
          <Button danger icon={<DeleteOutlined />} aria-label="Eliminar rascunho" />
        </Popconfirm>
      )}
    </Space>
  );
}

/** M-08: corrigir uma linha do extracto ainda por reconciliar (data, referência, descrição, valor, sentido). */
export function ModalEditarLinhaExtrato({ linha, aoFechar }: { linha: LinhaExtratoBancario | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ data: Dayjs; referencia?: string; descricao?: string; valor: number; tipo_dc: 'D' | 'C' }>();
  const accao = useAccao({ invalidar: [['teso']], aoSucesso: aoFechar, tituloErro: 'Não foi possível alterar a linha' });
  return (
    <Modal
      open={!!linha}
      title="Editar linha do extracto"
      onCancel={aoFechar}
      okText="Gravar"
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      destroyOnHidden
      afterOpenChange={(a) => a && linha && form.setFieldsValue({ data: dayjs(linha.data), referencia: linha.referencia ?? undefined, descricao: linha.descricao ?? undefined, valor: Number(linha.valor), tipo_dc: linha.tipo_dc })}
    >
      <Form form={form} layout="vertical" onFinish={(v) => linha && accao.mutate({ metodo: 'put', url: `/tesouraria/extrato/${linha.id}`, dados: { ...v, data: dataApi(v.data), referencia: v.referencia ?? null, descricao: v.descricao ?? null } })}>
        <Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="referencia" label="Referência"><Input maxLength={50} /></Form.Item>
        <Form.Item name="descricao" label="Descrição"><Input maxLength={1000} /></Form.Item>
        <Form.Item name="valor" label="Valor (Kz)" rules={[{ required: true }]}><InputNumber min={0.01} precision={2} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="tipo_dc" label="Sentido (óptica do banco)">
          <Select options={[{ value: 'C', label: 'Entrada (C)' }, { value: 'D', label: 'Saída (D)' }]} />
        </Form.Item>
        <Typography.Text type="secondary">Só as linhas por reconciliar se alteram; a alteração fica na auditoria.</Typography.Text>
      </Form>
    </Modal>
  );
}

/** Botão «Editar» de uma linha do extracto (só pendentes e com permissão de importar extractos). */
export function BotaoEditarLinha({ linha, aoEditar }: { linha: LinhaExtratoBancario; aoEditar: (l: LinhaExtratoBancario) => void }) {
  const { pode } = useSessao();
  return pode('teso_conc_importar') && linha.estado === 'PENDENTE'
    ? <Button size="small" type="link" icon={<EditOutlined />} onClick={() => aoEditar(linha)}>Editar</Button>
    : null;
}

/** M-08: histórico com filtros (conta, estado, período), incluindo as anuladas, e o detalhe dos grupos emparelhados. */
export function HistoricoFiltrado({ contaInicial }: { contaInicial?: string }) {
  const { pode } = useSessao();
  const [conta, setConta] = useState<string | undefined>(contaInicial);
  const [estado, setEstado] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [detalhe, setDetalhe] = useState<string | null>(null);
  const [anular, setAnular] = useState<string | null>(null);
  const [form] = Form.useForm<{ motivo: string }>();
  const lista = useQuery({
    queryKey: ['teso', 'reconciliacoes', 'historico', conta, estado, dataApi(periodo?.[0]), dataApi(periodo?.[1])],
    queryFn: () => obter<ItemHistorico[]>('/tesouraria/reconciliacao/historico', { codigo_conta: conta, estado, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }),
  });
  const det = useQuery({ queryKey: ['teso', 'reconciliacao', 'detalhe', detalhe], queryFn: () => obter<DetalheReconciliacao>(`/tesouraria/reconciliacao/${detalhe}/detalhe`), enabled: !!detalhe });
  const accao = useAccao({ invalidar: [['teso']], aoSucesso: () => { setAnular(null); form.resetFields(); } });

  return (
    <Card>
      <BarraFiltros
        accoes={
          <BotoesExportar
            desactivado={!lista.data?.length}
            obterPedido={() => ({
              titulo: 'Histórico de reconciliações bancárias',
              filtros: [conta && `Conta: ${conta}`, estado && `Estado: ${estado === 'ANULADA' ? 'Anuladas' : 'Conciliadas'}`],
              conteudo: tabelaHtml({
                colunas: [
                  { titulo: 'Código', valor: (r: ItemHistorico) => r.reconciliacao_codigo },
                  { titulo: 'Data', valor: (r) => formatarDataHora(r.data) },
                  { titulo: 'Conta', valor: (r) => r.conta ?? '' },
                  { titulo: 'Tipo', valor: (r) => r.tipo ?? '' },
                  { titulo: 'Grupos', valor: (r) => r.grupos, formato: 'inteiro' },
                  { titulo: 'Valor (Kz)', valor: (r) => r.valor_total, formato: 'moeda' },
                  { titulo: 'Estado', valor: (r) => r.estado },
                  { titulo: 'Motivo da anulação', valor: (r) => r.motivo_anulacao ?? '', quebrar: true },
                ],
                linhas: lista.data ?? [],
              }),
            })}
          />
        }
      >
        <SeletorContaFinanceira value={conta} onChange={setConta} prefixos={['43']} placeholder="Conta bancária" allowClear />
        <Select placeholder="Estado" allowClear style={{ width: 160 }} value={estado} onChange={setEstado} options={[{ value: 'CONCILIADO_BANCO', label: 'Conciliadas' }, { value: 'ANULADA', label: 'Anuladas' }]} />
        <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
      </BarraFiltros>
      <Table<ItemHistorico>
        rowKey="id"
        size="small"
        loading={lista.isFetching}
        dataSource={lista.data}
        scroll={scrollTabela()}
        pagination={{ pageSize: 25 }}
        columns={[
          { title: 'Código', dataIndex: 'reconciliacao_codigo', render: (v: string) => <Button type="link" size="small" onClick={() => setDetalhe(v)}>{v}</Button> },
          { title: 'Data', dataIndex: 'data', render: formatarDataHora },
          { title: 'Conta', dataIndex: 'conta', responsive: ['md'] },
          { title: 'Tipo', dataIndex: 'tipo', responsive: ['lg'], render: (v: string | null) => (v ? <Tag>{v === 'AUTOMATICA' ? 'Automática' : 'Manual'}</Tag> : '—') },
          { title: 'Grupos', dataIndex: 'grupos', align: 'right', responsive: ['lg'] },
          { title: 'Valor (Kz)', dataIndex: 'valor_total', align: 'right', render: (v: string | null) => <ValorKz valor={v} /> },
          { title: 'Estado', dataIndex: 'estado', render: (v: string, r) => <span title={r.motivo_anulacao ?? undefined}><EtiquetaEstado estado={v} /></span> },
          {
            title: '', key: 'a',
            render: (_, r) => pode('teso_conc_anular') && r.estado === 'CONCILIADO_BANCO' ? <Button size="small" danger type="link" onClick={() => setAnular(r.reconciliacao_codigo)}>Anular</Button> : null,
          },
        ]}
      />
      <Modal open={!!detalhe} title={`Reconciliação ${detalhe ?? ''}`} footer={<Button onClick={() => setDetalhe(null)}>Fechar</Button>} onCancel={() => setDetalhe(null)} width={larguraModal(980)}>
        {det.data && (
          <>
            <Descriptions size="small" column={{ xs: 1, sm: 2, md: 3 }} style={{ marginBottom: 12 }}>
              <Descriptions.Item label="Conta">{det.data.conta ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Data">{formatarDataHora(det.data.data)}</Descriptions.Item>
              <Descriptions.Item label="Estado"><EtiquetaEstado estado={det.data.estado} /></Descriptions.Item>
              <Descriptions.Item label="Valor">{formatarKz(det.data.valor_total)} Kz</Descriptions.Item>
              {det.data.anulacao && <Descriptions.Item label="Anulação" span={2}>{det.data.anulacao.motivo} ({formatarDataHora(det.data.anulacao.em)})</Descriptions.Item>}
            </Descriptions>
            {det.data.grupos.map((g, i) => (
              <Card key={i} size="small" title={`Grupo ${i + 1}`} style={{ marginBottom: 8 }}>
                <Table size="small" pagination={false} rowKey="id" scroll={scrollTabela()} dataSource={g.extrato} title={() => 'Extracto'}
                  columns={[{ title: 'Data', dataIndex: 'data', render: formatarData }, { title: 'Referência', dataIndex: 'referencia' }, { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
                    { title: 'D/C', dataIndex: 'tipo_dc' }, { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} /> }]} />
                <Table size="small" pagination={false} rowKey="id" scroll={scrollTabela()} dataSource={g.lancamentos} title={() => 'Diário'}
                  columns={[{ title: 'Data', dataIndex: 'data_documento', render: formatarData }, { title: 'Lançamento', dataIndex: 'numero_lan' }, { title: 'Documento', dataIndex: 'numero_documento' },
                    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true }, { title: 'D/C', dataIndex: 'tipo_dc' }, { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} /> }]} />
              </Card>
            ))}
          </>
        )}
      </Modal>
      <Modal open={!!anular} title={`Anular a reconciliação ${anular ?? ''}`} okText="Anular" okButtonProps={{ danger: true }} confirmLoading={accao.isPending} onCancel={() => setAnular(null)} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={(v) => anular && accao.mutate({ url: `/tesouraria/reconciliacao/${anular}/anular`, dados: v })}>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, min: 5, message: 'Indique o motivo (pelo menos 5 caracteres).' }]}><Input.TextArea rows={3} maxLength={500} /></Form.Item>
          <Typography.Text type="secondary">As linhas do extracto e do diário voltam a ficar por reconciliar.</Typography.Text>
        </Form>
      </Modal>
    </Card>
  );
}
