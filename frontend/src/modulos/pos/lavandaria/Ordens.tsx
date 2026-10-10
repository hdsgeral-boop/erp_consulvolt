import { Alert, App, Button, Checkbox, DatePicker, Form, Input, InputNumber, Modal, Radio, Select, Skeleton, Space, Table, Typography } from 'antd';
import { useQueries, useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useEffect, useMemo, useState, type Key } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { type ColunaApi } from '@/componentes/TabelaApi';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { IconeLegado, TabelaComModos } from '@/componentes/vistas';
import { EstadoPOS } from '../comum/estados';
import { SeletorTerminal, useFiltroTerminal } from '../comum/Filtros';
import type { Terminal } from '../comum/tipos';
import { useColaboradoresLav } from './dados';
import { DetalheOrdem } from './DetalheOrdem';
import { atrasada, cartoesOrdens, corSituacao, detalheIndicadores, emCurso, ESTADOS_FILTRO } from './indicadores';
import type { CargaResponsavel, DetalheOrdem as Detalhe, ListaOrdens, OrdemResumo } from './tipos';
import { Seccao } from '../contas/Seccao';
import '../comum/pos.css';

/**
 * Separador «Ordens de serviço» (renderListaOrdens, lavandaria.js:697-841): cartões KPI clicáveis, pesquisa, estado,
 * responsável e terminal, barra «Seleccionadas: N» com «Atribuir responsável», «Alterar estado» e «Aprovar / recusar
 * orçamentos», a lista com as colunas do antigo e a carga por responsável. «+ Nova ordem de serviço» abre a recepção.
 */
export function Ordens({ terminal, aoNovaOrdem }: { terminal: Terminal | undefined; aoNovaOrdem?: () => void }) {
  const { pode } = useSessao();
  const [estado, setEstado] = useState('ACTIVAS');
  const [texto, setTexto] = useState('');
  const [terminalFiltro, setTerminalFiltro] = useState<number>();
  const [seleccao, setSeleccao] = useState<Key[]>([]);
  const [aberta, setAberta] = useState<number | null>(null);
  const [responsavel, setResponsavel] = useState<string>();
  const [modal, setModal] = useState<'atribuir' | 'estado' | 'orcamentos' | null>(null);
  const colaboradores = useColaboradoresLav(true);
  const filtros = { estado, texto: texto.trim() || undefined, terminal_pos_id: terminalFiltro, responsavel };
  const consulta = useQuery({ queryKey: ['pos', 'lavandaria', 'ordens', filtros], queryFn: () => obter<ListaOrdens>('/pos/lavandaria/ordens', filtros) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar as ordens');
  }, [consulta.error]);
  const contadores = consulta.data?.contadores ?? {};
  const ordens = useMemo(() => consulta.data?.ordens ?? [], [consulta.data]);
  const filtroTerminal = useFiltroTerminal(terminalFiltro);
  const gerir = pode('lav_ordens');
  // a selecção só guarda ordens em curso que continuam na lista (como lavandaria.js:747)
  useEffect(() => {
    setSeleccao((s) => s.filter((k) => ordens.some((o) => o.id === Number(k) && emCurso(o))));
  }, [ordens]);
  const seleccionadas = seleccao.map(Number);
  const fechar = (limpar = false) => {
    setModal(null);
    if (limpar) setSeleccao([]);
  };

  const colunas: ColunaApi<OrdemResumo>[] = [
    {
      title: 'Ordem',
      dataIndex: 'numero_encomenda',
      render: (v, o) => (
        <div style={{ whiteSpace: 'nowrap' }}>
          <Button type="link" size="small" style={{ padding: 0, fontWeight: 700 }} onClick={() => setAberta(o.id)}>
            {v}
          </Button>
          <div>
            <small style={{ color: '#64748b' }}>
              <IconeLegado icone="cash-register" /> {o.codigo_terminal}
            </small>
          </div>
          {o.urgente && <div style={{ color: '#b91c1c', fontSize: '0.7rem' }}>URGENTE</div>}
        </div>
      ),
      valorFiltro: (o) => `${o.numero_encomenda} ${o.codigo_terminal}`,
      valorImpressao: (o) => `${o.numero_encomenda} · ${o.codigo_terminal}${o.urgente ? ' · URGENTE' : ''}`,
    },
    {
      title: 'Cliente',
      key: 'cliente',
      render: (_, o) => (
        <>
          {o.cliente?.nome ?? `#${o.cliente_id}`}
          <div>
            <small style={{ color: '#64748b' }}>{o.cliente?.telefone || o.cliente?.nif || ''}</small>
          </div>
        </>
      ),
      valorFiltro: (o) => `${o.cliente?.nome ?? ''} ${o.cliente?.nif ?? ''} ${o.cliente?.telefone ?? ''}`,
      valorImpressao: (o) => [o.cliente?.nome, o.cliente?.telefone || o.cliente?.nif].filter(Boolean).join(' · '),
    },
    { title: 'Recebida', dataIndex: 'recebido_em', render: (v) => <span style={{ whiteSpace: 'nowrap' }}>{formatarDataHora(v)}</span>, valorImpressao: (o) => formatarDataHora(o.recebido_em) },
    {
      title: 'Responsável',
      dataIndex: 'nome_atribuido',
      render: (v, o) => (v ? <strong>{v}</strong> : emCurso(o) ? <span style={{ color: '#db2777', fontWeight: 700 }}>por atribuir</span> : '—'),
      valorImpressao: (o) => o.nome_atribuido ?? (emCurso(o) ? 'por atribuir' : '—'),
    },
    { title: 'Atribuída em', key: 'atribuida', render: (_, o) => formatarData(o.indicadores.atribuida_em ?? o.atribuido_em ?? null) || '—', valorImpressao: (o) => formatarData(o.indicadores.atribuida_em ?? o.atribuido_em ?? null) || '—' },
    {
      title: 'Prazo',
      dataIndex: 'data_prometida',
      render: (v, o) => (
        <span style={{ whiteSpace: 'nowrap', color: atrasada(o) ? '#b91c1c' : undefined, fontWeight: atrasada(o) ? 700 : 400 }}>{formatarData(v) || '—'}</span>
      ),
      valorImpressao: (o) => formatarData(o.data_prometida) || '—',
    },
    { title: 'Pronta em', key: 'pronta', render: (_, o) => formatarData(o.indicadores.pronta_em) || '—', valorImpressao: (o) => formatarData(o.indicadores.pronta_em) || '—' },
    {
      title: 'Entrega',
      key: 'entrega',
      render: (_, o) =>
        o.indicadores.entregue_em ? formatarData(o.indicadores.entregue_em) : o.entrega?.ativa && o.entrega.data ? <small>prevista {formatarData(o.entrega.data)}</small> : '—',
      valorImpressao: (o) => (o.indicadores.entregue_em ? formatarData(o.indicadores.entregue_em) : o.entrega?.ativa && o.entrega.data ? `prevista ${formatarData(o.entrega.data)}` : '—'),
    },
    {
      title: 'Indicadores',
      key: 'indicadores',
      render: (_, o) => (
        <>
          <span style={{ color: corSituacao(o.indicadores.situacao), fontWeight: 700 }}>{o.indicadores.situacao ?? '—'}</span>
          <div>
            <small style={{ color: '#64748b' }}>{detalheIndicadores(o.indicadores)}</small>
          </div>
        </>
      ),
      valorFiltro: (o) => `${o.indicadores.situacao ?? ''} ${detalheIndicadores(o.indicadores)}`,
      valorImpressao: (o) => `${o.indicadores.situacao ?? ''} · ${detalheIndicadores(o.indicadores)}`,
    },
    {
      title: 'Estado',
      dataIndex: 'estado',
      render: (v, o) => (
        <>
          <EstadoPOS estado={v} />
          {o.nao_levantada && (
            <div>
              <small style={{ color: '#9a3412' }}>não levantada</small>
            </div>
          )}
        </>
      ),
    },
    { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v), total: true },
    {
      title: 'Saldo',
      dataIndex: 'saldo',
      align: 'right',
      render: (v) => <span style={{ fontWeight: 700, color: Number(v) > 0.005 ? '#b91c1c' : '#059669' }}>{formatarKz(v)}</span>,
      total: true,
    },
  ];

  return (
    <>
      {/* cartões compactos do legado (kpi, lavandaria.js:773-779): rótulo 0.7rem, valor 1.3rem, barra de cor por baixo */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(135px, 1fr))', gap: '0.5rem', marginBottom: '0.7rem' }}>
        {cartoesOrdens(consulta.data?.dias_armazenagem_gratis).map((c) => (
          <button
            key={c.chave}
            type="button"
            className="lav-kpi"
            onClick={() => setEstado(c.filtro)}
            aria-pressed={estado === c.filtro && c.chave !== 'hoje'}
            style={{ textAlign: 'left', background: '#fff', border: '1px solid #e2e8f0', borderBottom: `3px solid ${c.cor}`, borderRadius: 4, padding: '0.55rem 0.8rem', cursor: 'pointer', font: 'inherit' }}
          >
            <div style={{ fontSize: '0.7rem', fontWeight: 700, color: '#64748b', textTransform: 'uppercase' }}>{c.rotulo}</div>
            <div style={{ fontSize: '1.3rem', fontWeight: 800, color: '#0f172a' }}>{contadores[c.contador] ?? 0}</div>
          </button>
        ))}
      </div>
      <div className="lav-ordens-cartao" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 4, padding: '0.9rem' }}>
      <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginBottom: '0.6rem' }}>
        <Input
          allowClear
          placeholder="Pesquisar OS, etiqueta, peça, cliente, NIF, telefone ou responsável"
          style={{ flex: 1, minWidth: 220 }}
          onPressEnter={(e) => setTexto(e.currentTarget.value)}
          onChange={(e) => !e.target.value && setTexto('')}
        />
        <Select value={estado} onChange={setEstado} style={{ width: 210 }} options={ESTADOS_FILTRO.map((e) => ({ value: e.valor, label: e.rotulo }))} aria-label="Estado" />
        <Select
          placeholder="Todos os responsáveis"
          allowClear
          showSearch
          optionFilterProp="label"
          value={responsavel}
          onChange={setResponsavel}
          style={{ width: 210 }}
          loading={colaboradores.isLoading}
          options={[{ value: 'SEM', label: 'Sem responsável' }, ...(colaboradores.data ?? []).map((c) => ({ value: String(c.id), label: c.activo ? c.nome : `${c.nome} (inactivo)` }))]}
        />
        <SeletorTerminal
          tipo="LAVANDARIA"
          placeholder="Todos os terminais"
          style={{ width: 210 }}
          value={terminalFiltro}
          onChange={(v) => {
            setTerminalFiltro(v);
            setSeleccao([]);
          }}
        />
        {gerir && aoNovaOrdem && (
          <Button type="primary" icon={<IconeLegado icone="plus" />} onClick={aoNovaOrdem}>
            Nova ordem de serviço
          </Button>
        )}
      </div>
      <TabelaComModos<OrdemResumo>
        idVista="lav-ordens"
        rowKey="id"
        size="small"
        loading={consulta.isFetching}
        dataSource={ordens}
        scroll={scrollTabela()}
        onRow={(o) => ({ onDoubleClick: () => setAberta(o.id), style: { verticalAlign: 'top' } })}
        columns={colunas}
        impressao={{ titulo: 'Ordens de serviço da lavandaria', filtros: [`Estado: ${ESTADOS_FILTRO.find((f) => f.valor === estado)?.rotulo ?? estado}`, filtroTerminal, texto.trim() && `Pesquisa: ${texto.trim()}`], orientacao: 'paisagem' }}
        accoes={(o) => [{ icone: 'folder-open', titulo: 'Abrir a ordem', onClick: () => setAberta(o.id) }]}
        seleccao={
          gerir
            ? {
                chaves: seleccao,
                aoMudar: (k) => setSeleccao(k),
                bloquearLinha: (o) => (emCurso(o) ? false : 'Ordem entregue ou anulada'),
                ajuda: 'Marque as ordens em curso para acções em massa, ou abra uma ordem para agir individualmente.',
                accoes: [
                  { chave: 'atribuir', texto: 'Atribuir responsável', icone: 'user-tag', variante: 'primario', onClick: () => setModal('atribuir') },
                  { chave: 'estado', texto: 'Alterar estado', icone: 'exchange-alt', onClick: () => setModal('estado') },
                  { chave: 'orcamentos', texto: 'Aprovar / recusar orçamentos', icone: 'file-signature', onClick: () => setModal('orcamentos') },
                ],
              }
            : undefined
        }
        vazio={{ icone: 'clipboard-list', titulo: 'Sem ordens.' }}
        className="lav-ordens-tabela"
      />
      </div>
      <Seccao
        nivel={4}
        icone="users"
        cor="#334155"
        titulo={
          <>
            Carga e desempenho por responsável <small style={{ fontWeight: 400, color: '#64748b' }}>· indicadores de conclusão dos últimos 30 dias</small>
          </>
        }
        style={{ marginTop: '0.7rem' }}
      >
        <Table<CargaResponsavel>
          size="small"
          rowKey={(g) => g.responsavel ?? 'SEM'}
          pagination={false}
          scroll={scrollTabela()}
          dataSource={consulta.data?.por_responsavel ?? []}
          locale={{ emptyText: 'Sem dados.' }}
          columns={[
            { title: 'Responsável', dataIndex: 'responsavel', render: (v) => <b style={{ color: v === 'Sem responsável' ? '#db2777' : undefined }}>{v ?? '—'}</b> },
            { title: 'Em execução', dataIndex: 'em_execucao', align: 'right' },
            { title: 'Atrasadas', dataIndex: 'atrasadas', align: 'right', render: (v: number) => <span style={{ color: v ? '#b91c1c' : undefined, fontWeight: v ? 700 : 400 }}>{v}</span> },
            { title: 'Prontas por levantar', dataIndex: 'por_levantar', align: 'right' },
            { title: 'Concluídas', dataIndex: 'concluidas', align: 'right' },
            { title: '% no prazo', dataIndex: 'percentagem_no_prazo', align: 'right', render: (v) => (v === null || v === undefined ? '—' : `${v}%`) },
            { title: 'Execução média (dias)', dataIndex: 'execucao_media', align: 'right', render: (v) => v ?? '—' },
            { title: 'Ciclo médio até à entrega (dias)', dataIndex: 'ciclo_medio', align: 'right', render: (v) => v ?? '—' },
          ]}
        />
      </Seccao>
      <DetalheOrdem id={aberta} terminal={terminal} aoFechar={() => setAberta(null)} />
      <ModalAtribuir ids={modal === 'atribuir' ? seleccionadas : []} aoFechar={() => fechar()} aoConcluir={() => fechar(true)} />
      <ModalAlterarEstado ordens={modal === 'estado' ? ordens.filter((o) => seleccionadas.includes(o.id)) : []} aoFechar={() => fechar()} aoConcluir={() => fechar(true)} />
      <ModalOrcamentosMassa ids={modal === 'orcamentos' ? seleccionadas : []} aoFechar={() => fechar()} aoConcluir={() => fechar(true)} />
    </>
  );
}

/** Mostra o resultado de uma acção em massa com as ordens/linhas não tratadas (como os alertas do antigo). */
function useResultadoMassa() {
  const { modal } = App.useApp();
  return (titulo: string, n: number, ignorados: string[] | undefined) => {
    if (!ignorados?.length) return;
    modal.warning({
      title: `${titulo}: ${n}`,
      width: larguraModal(560),
      content: (
        <div style={{ whiteSpace: 'pre-line' }}>
          {`Não tratados (${ignorados.length}):\n${ignorados.slice(0, 15).join('\n')}`}
        </div>
      ),
    });
  };
}

/** Atribuir (ou retirar) o responsável de várias ordens (POST /pos/lavandaria/ordens/atribuir, lav_ordens). */
function ModalAtribuir({ ids, aoFechar, aoConcluir }: { ids: number[]; aoFechar: () => void; aoConcluir: () => void }) {
  const colaboradores = useColaboradoresLav();
  const [form] = Form.useForm<{ modo: 'ATRIBUIR' | 'RETIRAR'; colaborador_id?: number; data?: Dayjs | null; aplicar: 'PENDENTES' | 'NENHUM'; nota?: string }>();
  const modo = Form.useWatch('modo', form);
  const accao = useAccao({ invalidar: [['pos']], aoSucesso: aoConcluir });
  useEffect(() => {
    if (ids.length) form.setFieldsValue({ modo: 'ATRIBUIR', aplicar: 'PENDENTES', colaborador_id: undefined, data: null, nota: undefined });
  }, [ids.length, form]);
  return (
    <Modal
      title={
        <Space>
          <IconeLegado icone="user-tag" /> Atribuir responsável · {ids.length} ordem(ns)
        </Space>
      }
      open={ids.length > 0}
      onCancel={aoFechar}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      width={larguraModal(520)}
      destroyOnHidden
    >
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) =>
          accao.mutate({
            url: '/pos/lavandaria/ordens/atribuir',
            dados: {
              ids,
              retirar: v.modo === 'RETIRAR',
              colaborador_id: v.modo === 'RETIRAR' ? null : v.colaborador_id,
              data: dataApi(v.data ?? null) ?? null,
              aplicar: v.aplicar,
              nota: v.nota?.trim() || null,
            },
          })
        }
      >
        <Form.Item name="modo">
          <Radio.Group options={[{ value: 'ATRIBUIR', label: 'Atribuir' }, { value: 'RETIRAR', label: 'Retirar o responsável' }]} />
        </Form.Item>
        {modo !== 'RETIRAR' && (
          <>
            <Form.Item name="colaborador_id" label="Colaborador responsável" rules={[{ required: true, message: 'Escolha o colaborador.' }]}>
              <Select showSearch optionFilterProp="label" loading={colaboradores.isLoading} options={(colaboradores.data ?? []).map((c) => ({ value: c.id, label: c.nome }))} />
            </Form.Item>
            <Form.Item name="data" label="Data da atribuição" extra="Vazio = hoje; não pode ser futura.">
              <DatePicker format="DD/MM/YYYY" />
            </Form.Item>
            <Form.Item name="aplicar" label="Linhas da ordem">
              <Radio.Group options={[{ value: 'PENDENTES', label: 'Atribuir também as linhas pendentes' }, { value: 'NENHUM', label: 'Só a ordem' }]} />
            </Form.Item>
          </>
        )}
        <Form.Item name="nota" label="Nota">
          <Input maxLength={500} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Estados que se podem aplicar em massa (ESTADOS_ALTERAVEIS, lavandaria.js:1791). */
export const ESTADOS_ALTERAVEIS: { valor: string; rotulo: string }[] = [
  { valor: 'EM_EXECUCAO', rotulo: 'Em execução' },
  { valor: 'PRONTA', rotulo: 'Pronta' },
  { valor: 'RECEBIDA', rotulo: 'Recebida (reverter execução)' },
  { valor: 'ANULADA', rotulo: 'Anulada' },
];

/** «Alterar estado» das ordens seleccionadas (lavAlterarEstado, lavandaria.js:1793-1816; POST /pos/lavandaria/ordens/estado). */
function ModalAlterarEstado({ ordens, aoFechar, aoConcluir }: { ordens: OrdemResumo[]; aoFechar: () => void; aoConcluir: () => void }) {
  const { pode } = useSessao();
  const colaboradores = useColaboradoresLav();
  const resultado = useResultadoMassa();
  const [form] = Form.useForm<{ estado?: string; colaborador_id?: number; motivo?: string }>();
  const novo = Form.useWatch('estado', form);
  const accao = useAccao<{ alteradas: number; ignoradas?: string[] }>({
    invalidar: [['pos']],
    aoSucesso: (r) => {
      resultado('Ordens alteradas', r.alteradas, r.ignoradas);
      aoConcluir();
    },
  });
  useEffect(() => {
    if (ordens.length) form.resetFields();
  }, [ordens.length, form]);
  const contagem = ordens.reduce<Record<string, number>>((acc, o) => ({ ...acc, [o.estado]: (acc[o.estado] ?? 0) + 1 }), {});
  const obrigatorio = novo === 'RECEBIDA' || novo === 'ANULADA';
  return (
    <Modal
      title={
        <Space>
          <IconeLegado icone="exchange-alt" /> Alterar estado
        </Space>
      }
      open={ordens.length > 0}
      onCancel={aoFechar}
      okText={`Aplicar${ordens.length > 1 ? ` (${ordens.length})` : ''}`}
      cancelText="Cancelar"
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      width={larguraModal(560)}
      destroyOnHidden
    >
      <div style={{ fontSize: '0.84rem', marginBottom: '0.7rem' }}>
        <strong>{ordens.length === 1 ? ordens[0]?.numero_encomenda : `${ordens.length} ordens seleccionadas`}</strong> ·{' '}
        {Object.entries(contagem).map(([e, n]) => (
          <span key={e} style={{ marginRight: 6 }}>
            <EstadoPOS estado={e} /> {n}
          </span>
        ))}
      </div>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => accao.mutate({ url: '/pos/lavandaria/ordens/estado', dados: { ids: ordens.map((o) => o.id), estado: v.estado, colaborador_id: v.colaborador_id ?? null, motivo: v.motivo?.trim() || null } })}
      >
        <Form.Item name="estado" label="Novo estado" rules={[{ required: true, message: 'Escolha o novo estado.' }]}>
          <Select placeholder="— seleccionar —" options={ESTADOS_ALTERAVEIS.filter((e) => e.valor !== 'ANULADA' || pode('lav_anular')).map((e) => ({ value: e.valor, label: e.rotulo }))} />
        </Form.Item>
        {(novo === 'EM_EXECUCAO' || novo === 'PRONTA') && (
          <Form.Item name="colaborador_id" label="Executado por">
            <Select allowClear placeholder="Responsável da ordem (ou operador)" showSearch optionFilterProp="label" options={(colaboradores.data ?? []).map((c) => ({ value: c.id, label: c.nome }))} />
          </Form.Item>
        )}
        <Form.Item name="motivo" label="Motivo / nota" rules={obrigatorio ? [{ required: true, whitespace: true, min: 3, message: 'Obrigatório para reverter ou anular.' }] : []}>
          <Input maxLength={500} placeholder="Obrigatório para reverter ou anular" />
        </Form.Item>
      </Form>
      <ul style={{ fontSize: '0.76rem', color: '#64748b', margin: '0.7rem 0 0', paddingLeft: '1.1rem', lineHeight: 1.6 }}>
        <li>Só são alteradas as peças em condições: Em execução ← Recebida; Pronta ← Recebida / Em execução; Reverter ← Em execução / Pronta.</li>
        <li>Peças com orçamento por aprovar, entregues ou anuladas não são alteradas.</li>
        <li>Anular só é possível em ordens sem documentos de venda nem recebimentos.</li>
        <li>A entrega faz-se em «Entregar ao cliente» (com pagamento e facturação).</li>
      </ul>
    </Modal>
  );
}

interface LinhaOrcamento {
  chave: string;
  pedido_id: number;
  linha_id: number;
  ordem: string;
  terminal: string;
  recebida: string;
  cliente: string;
  telefone: string;
  peca: string;
  detalhe: string;
  estado_entrada: string | null;
  quantidade: string;
  estimativa: number;
}

/** Linhas com orçamento por aprovar das ordens em curso (lavOrcamentosMassa, lavandaria.js:1674-1676). */
export function linhasOrcamento(detalhes: Detalhe[]): LinhaOrcamento[] {
  return detalhes
    .filter((d) => d.pedido.estado !== 'ENTREGUE' && d.pedido.estado !== 'ANULADA')
    .flatMap((d) =>
      d.pedido.itens
        .filter((i) => i.estado === 'ORCAMENTO')
        .map((i) => ({
          chave: `${d.pedido.id}:${i.linha_id}`,
          pedido_id: d.pedido.id,
          linha_id: i.linha_id,
          ordem: d.pedido.numero_encomenda,
          terminal: d.pedido.codigo_terminal,
          recebida: d.pedido.recebido_em,
          cliente: d.cliente?.nome ?? '',
          telefone: d.cliente?.telefone ?? '',
          peca: `${i.nome_peca ?? ''} · ${i.nome}`,
          detalhe: [i.cor, i.tecido].filter(Boolean).join(', '),
          estado_entrada: i.estado_entrada ?? null,
          quantidade: `${Number(i.quantidade)} ${i.unidade === 'KG' ? 'kg' : ''}`.trim(),
          estimativa: Number(i.valor_orcamento ?? 0) || Number(i.valor ?? 0),
        })),
    );
}

/** «Aprovar / recusar orçamentos» das ordens seleccionadas (lavandaria.js:1671-1785; POST /pos/lavandaria/orcamentos). */
function ModalOrcamentosMassa({ ids, aoFechar, aoConcluir }: { ids: number[]; aoFechar: () => void; aoConcluir: () => void }) {
  const resultado = useResultadoMassa();
  const detalhes = useQueries({
    queries: ids.map((id) => ({ queryKey: ['pos', 'lavandaria', 'ordem', id], queryFn: () => obter<Detalhe>(`/pos/lavandaria/ordens/${id}`) })),
  });
  const carregando = detalhes.some((d) => d.isLoading);
  const linhas = useMemo(() => (carregando ? [] : linhasOrcamento(detalhes.map((d) => d.data).filter((d): d is Detalhe => !!d))), [carregando, detalhes]);
  const [decisao, setDecisao] = useState<'APROVAR' | 'RECUSAR'>('APROVAR');
  const [canal, setCanal] = useState('Presencial');
  const [nota, setNota] = useState('');
  const [marcadas, setMarcadas] = useState<string[]>([]);
  const [valores, setValores] = useState<Record<string, number | null>>({});
  const chavesLinhas = linhas.map((l) => l.chave).join('|');
  useEffect(() => {
    setMarcadas(linhas.map((l) => l.chave));
    setValores(Object.fromEntries(linhas.map((l) => [l.chave, Math.round(l.estimativa * 100) / 100])));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chavesLinhas]);
  useEffect(() => {
    if (ids.length) {
      setDecisao('APROVAR');
      setCanal('Presencial');
      setNota('');
    }
  }, [ids.length]);
  const accao = useAccao<{ tratados: number; ignorados?: string[] }>({
    invalidar: [['pos']],
    aoSucesso: (r) => {
      resultado(decisao === 'APROVAR' ? 'Orçamentos aprovados' : 'Orçamentos recusados', r.tratados, r.ignorados);
      aoConcluir();
    },
  });
  const aprovar = decisao === 'APROVAR';
  const sel = linhas.filter((l) => marcadas.includes(l.chave));
  const total = sel.reduce((s, l) => s + Number(valores[l.chave] ?? 0), 0);
  const semValor = aprovar ? sel.filter((l) => !(Number(valores[l.chave] ?? 0) > 0)).length : 0;
  const nOrdens = new Set(linhas.map((l) => l.pedido_id)).size;
  const invalido = !sel.length || (!aprovar && nota.trim().length < 3) || semValor > 0;
  const confirmar = () =>
    Modal.confirm({
      title: aprovar ? `Aprovar ${sel.length} orçamento(s) no total de ${formatarKz(total)} (${canal})?` : `Recusar ${sel.length} orçamento(s)? As peças são devolvidas sem custo.`,
      okText: aprovar ? 'Aprovar' : 'Recusar',
      cancelText: 'Cancelar',
      okButtonProps: { danger: !aprovar },
      onOk: () =>
        accao.mutate({
          url: '/pos/lavandaria/orcamentos',
          dados: { decisao, canal: aprovar ? canal : undefined, nota: nota.trim() || undefined, linhas: sel.map((l) => ({ pedido_id: l.pedido_id, linha_id: l.linha_id, valor: aprovar ? Number(valores[l.chave] ?? 0) : undefined })) },
        }),
    });

  return (
    <Modal
      title={
        <Space>
          <IconeLegado icone="file-signature" /> Aprovar / recusar orçamentos
        </Space>
      }
      open={ids.length > 0}
      onCancel={aoFechar}
      width={larguraModal(1000)}
      destroyOnHidden
      footer={
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap' }}>
          <div style={{ fontSize: '0.88rem' }}>
            {aprovar ? (
              <>
                A aprovar: <strong>{sel.length}</strong> orçamento(s) · total <strong>{formatarKz(total)}</strong>
              </>
            ) : (
              <>
                A recusar: <strong>{sel.length}</strong> orçamento(s) <Typography.Text type="secondary">(as peças são devolvidas sem custo)</Typography.Text>
              </>
            )}
          </div>
          <Space>
            <Button onClick={aoFechar}>Cancelar</Button>
            <Button type="primary" danger={!aprovar} disabled={invalido} loading={accao.isPending} icon={<IconeLegado icone={aprovar ? 'thumbs-up' : 'thumbs-down'} />} onClick={confirmar}>
              {aprovar ? 'Aprovar' : 'Recusar'} ({sel.length})
            </Button>
          </Space>
        </div>
      }
    >
      {carregando ? (
        <Skeleton active />
      ) : !linhas.length ? (
        <Alert type="info" showIcon message="As ordens seleccionadas não têm orçamentos por aprovar." />
      ) : (
        <>
          <div style={{ fontSize: '0.84rem', marginBottom: '0.6rem' }}>
            <strong>{linhas.length}</strong> orçamento(s) por aprovar em <strong>{nOrdens}</strong> ordem(ns)
            {ids.length > nOrdens ? ` · ${ids.length - nOrdens} ordem(ns) seleccionada(s) sem orçamentos pendentes` : ''}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.5rem', marginBottom: '0.7rem' }}>
            <label>
              <div style={{ fontSize: '0.78rem', fontWeight: 700 }}>Decisão *</div>
              <Select
                value={decisao}
                onChange={setDecisao}
                style={{ width: '100%' }}
                options={[
                  { value: 'APROVAR', label: 'Aprovar' },
                  { value: 'RECUSAR', label: 'Recusar' },
                ]}
              />
            </label>
            {aprovar && (
              <label>
                <div style={{ fontSize: '0.78rem', fontWeight: 700 }}>Forma de aprovação</div>
                <Select value={canal} onChange={setCanal} style={{ width: '100%' }} options={['Presencial', 'Telefone', 'WhatsApp', 'E-mail'].map((c) => ({ value: c, label: c }))} />
              </label>
            )}
            <label style={{ gridColumn: 'span 2' }}>
              <div style={{ fontSize: '0.78rem', fontWeight: 700 }}>{aprovar ? 'Nota (trabalho acordado)' : 'Motivo da recusa *'}</div>
              <Input value={nota} onChange={(e) => setNota(e.target.value)} maxLength={500} />
            </label>
          </div>
          <Table<LinhaOrcamento>
            size="small"
            rowKey="chave"
            pagination={false}
            scroll={scrollTabela(360)}
            dataSource={linhas}
            columns={[
              {
                title: <Checkbox checked={marcadas.length === linhas.length} indeterminate={marcadas.length > 0 && marcadas.length < linhas.length} onChange={(e) => setMarcadas(e.target.checked ? linhas.map((l) => l.chave) : [])} />,
                key: 'sel',
                width: 36,
                render: (_, l) => <Checkbox checked={marcadas.includes(l.chave)} onChange={(e) => setMarcadas((m) => (e.target.checked ? [...m, l.chave] : m.filter((x) => x !== l.chave)))} />,
              },
              {
                title: 'Ordem',
                key: 'ordem',
                render: (_, l) => (
                  <>
                    <b>{l.ordem}</b>
                    <div>
                      <small style={{ color: '#64748b' }}>
                        {l.terminal} · {formatarData(l.recebida)}
                      </small>
                    </div>
                  </>
                ),
              },
              {
                title: 'Cliente',
                key: 'cliente',
                render: (_, l) => (
                  <>
                    {l.cliente}
                    <div>
                      <small style={{ color: '#64748b' }}>{l.telefone}</small>
                    </div>
                  </>
                ),
              },
              {
                title: 'Peça · serviço',
                key: 'peca',
                render: (_, l) => (
                  <>
                    <strong>{l.peca}</strong>
                    {l.detalhe && (
                      <div>
                        <small>{l.detalhe}</small>
                      </div>
                    )}
                  </>
                ),
              },
              { title: 'Estado à entrada', dataIndex: 'estado_entrada', render: (v: string | null) => <span style={{ color: v && v !== 'Bom estado' ? '#b45309' : '#475569' }}>{v || '—'}</span> },
              { title: 'Qtd.', dataIndex: 'quantidade', align: 'right' },
              { title: 'Estimativa', dataIndex: 'estimativa', align: 'right', render: (v) => formatarKz(v) },
              {
                title: 'Valor aprovado (IVA incl.)',
                key: 'valor',
                width: 160,
                render: (_, l) => (
                  <InputNumber<number>
                    min={0}
                    precision={2}
                    decimalSeparator=","
                    disabled={!aprovar}
                    value={valores[l.chave] ?? null}
                    onChange={(v) => setValores((x) => ({ ...x, [l.chave]: v }))}
                    style={{ width: '100%' }}
                    aria-label={`Valor aprovado ${l.ordem}`}
                  />
                ),
              },
            ]}
          />
          {semValor > 0 && <Alert style={{ marginTop: 8 }} type="warning" showIcon message={`Indique o valor aprovado em todas as linhas seleccionadas (${semValor} sem valor).`} />}
        </>
      )}
    </Modal>
  );
}
