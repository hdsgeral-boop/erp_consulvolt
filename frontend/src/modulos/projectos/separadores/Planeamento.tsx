import { Alert, AutoComplete, Button, Card, Col, DatePicker, Divider, Dropdown, Flex, Form, Input, InputNumber, Modal, Row, Segmented, Select, Slider, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import { BranchesOutlined, CopyOutlined, DeleteOutlined, EditOutlined, FlagOutlined, MoreOutlined, NodeIndexOutlined, PlusOutlined, AimOutlined, TableOutlined, UnorderedListOutlined, AppstoreAddOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ColumnsType } from 'antd/es/table';
import dayjs from 'dayjs';
import { useEffect, useMemo, useRef, useState } from 'react';
import { obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { rotuloProjectos } from '../comum/componentes';
import { useAccao } from '@/componentes/Accoes';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { BarraExecucao, EtiquetaProjectos, ModalEliminar, SeletorMembro, SeletorPosicao, SeletorTarefa, useEquipa, useWbs } from '../comum/componentes';
import { Gantt } from '../comum/Gantt';
import { ligacoesWbs, linhasGanttWbs, tarefaAtrasada } from '../comum/regras';
import { ESCALAS, formatarQtd, pctDaMeta, rotuloDependencia, TIPOS_DEP, UNIDADES, type Escala } from '../comum/regrasMetas';
import type { Marco, TarefaWbs } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { descricaoMover, pedidoMover } from '../comum/arrastar';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { ModalFolhaMedicao, ModalMedicao, ModalTarefasQuantidade } from './planeamento/ModaisMetas';
import { ModalDuplicar, ModalNivel, type AlvoHierarquia } from './planeamento/ModaisHierarquia';
import { ModalMapaQuantidades } from './planeamento/MapaQuantidades';

type LinhaArvore = (TarefaWbs & { chave: string; children?: LinhaArvore[] }) | { chave: string; grupo: true; marco: Marco | null; execucao: number; meta?: { aviso: string | null; meta: number | null; feito: number; repartido: number; unidade: string | null } | null; children?: LinhaArvore[] };

function paraArvore(t: TarefaWbs): LinhaArvore {
  return { ...t, chave: `t-${t.id}`, children: t.subtarefas?.length ? t.subtarefas.map(paraArvore) : undefined };
}

const contarSub = (t: TarefaWbs): number => (t.subtarefas ?? []).reduce((s, x) => s + 1 + contarSub(x), 0);

const mostrarAvisos = (avisos: string[] | undefined) => {
  if (avisos?.length) Modal.warning({ title: 'Gravado, mas reveja', content: <ul style={{ paddingLeft: 18 }}>{avisos.map((a) => <li key={a}>{a}</li>)}</ul> });
};

/**
 * WBS: milestones, tarefas e subtarefas (árvore), execução, e vista Gantt do projecto.
 * Versão Actual (Fase E): metas por quantidade e medição, folha de medição, «Tarefas por quantidade», dependências
 * (coluna Precedência e setas no Gantt), estrutura orgânica, duplicar e mudar de nível, Gantt com escala e período e o
 * mapa de quantidades.
 */
export function SeparadorPlaneamento({ projecto, acc }: PropsSeparador) {
  const w = useWbs(projecto.id);
  const equipa = useEquipa(projecto.id);
  const [vista, setVista] = useState<'wbs' | 'gantt'>('wbs');
  const [tarefa, setTarefa] = useState<Partial<TarefaWbs> | null>(null);
  const [marco, setMarco] = useState<Partial<Marco> | null>(null);
  const [execucao, setExecucao] = useState<TarefaWbs | null>(null);
  const [medir, setMedir] = useState<TarefaWbs | null>(null);
  const [folha, setFolha] = useState<{ destacar: number | null } | null>(null);
  const [assistente, setAssistente] = useState<{ marco?: number | null; pai?: number | null } | null>(null);
  const [mapa, setMapa] = useState(false);
  const [duplicar, setDuplicar] = useState<AlvoHierarquia | null>(null);
  const [nivel, setNivel] = useState<AlvoHierarquia | null>(null);
  const [escala, setEscala] = useState<Escala | undefined>(undefined);
  const [periodo, setPeriodo] = useState<[dayjs.Dayjs | null, dayjs.Dayjs | null] | null>(null);
  const [soFases, setSoFases] = useState<'tudo' | 'fases' | 'tarefas'>('tudo');
  const [eliminar, setEliminar] = useState<{ tipo: 'tarefa' | 'marco'; id: number; nome: string } | null>(null);
  const accEliminar = useAccao({ invalidar: [['projectos']], aoSucesso: () => setEliminar(null) });
  // M-20: arrastar tarefas (reordenar, passar a subtarefa ou mudar de milestone) — POST /tarefas/{t}/mover
  const [arrastada, setArrastada] = useState<number | null>(null);
  const [sobre, setSobre] = useState<string | null>(null);
  const accMover = useAccao<{ avisos?: string[] }>({ invalidar: [['projectos']], tituloErro: 'Não foi possível mover a tarefa', aoSucesso: (d) => mostrarAvisos(d?.avisos) });
  const largar = (l: LinhaArvore, e: React.DragEvent<HTMLElement>) => {
    e.preventDefault();
    setSobre(null);
    if (arrastada === null) return;
    const r = e.currentTarget.getBoundingClientRect();
    const alvo = 'grupo' in l ? { marco: l.marco?.id ?? null } : { tarefa: l.id };
    const p = pedidoMover(arrastada, alvo, e.clientY - r.top, r.height, e.shiftKey);
    setArrastada(null);
    if (!p) return;
    accMover.mutate({ url: `/projetos/${projecto.id}/tarefas/${arrastada}/mover`, dados: p });
    void message.info(`Tarefa movida ${descricaoMover(p, 'grupo' in l ? l.marco?.nome ?? 'Sem milestone' : l.nome)}.`);
  };
  const nomeMembro = useMemo(() => new Map((equipa.data?.membros ?? []).map((m) => [m.id, m.nome])), [equipa.data]);
  const refGantt = useRef<HTMLDivElement>(null);

  const arvore: LinhaArvore[] = (w.data?.grupos ?? [])
    .filter((g) => g.marco || g.tarefas.length)
    .map((g) => ({ chave: `m-${g.marco?.id ?? 'sem'}`, grupo: true as const, marco: g.marco, execucao: g.execucao, meta: g.meta, children: g.tarefas.length ? g.tarefas.map(paraArvore) : undefined }));
  const linhasGantt = useMemo(() => linhasGanttWbs(w.data, {
    periodo: periodo ? { de: periodo[0]?.format('YYYY-MM-DD'), ate: periodo[1]?.format('YYYY-MM-DD') } : undefined,
    nivelMaximo: soFases === 'fases' ? 0 : soFases === 'tarefas' ? 1 : undefined,
  }), [w.data, periodo, soFases]);
  const porChave = useMemo(() => {
    const m = new Map<string, TarefaWbs>();
    const visitar = (t: TarefaWbs) => { m.set(`t-${t.id}`, t); t.subtarefas?.forEach(visitar); };
    w.data?.grupos.forEach((g) => g.tarefas.forEach(visitar));
    return m;
  }, [w.data]);

  /** WBS impressa: milestones como grupos e tarefas/subtarefas indentadas; o Gantt imprime-se a partir do ecrã. */
  const pedidoImpressao = () => {
    if (vista === 'gantt') {
      return refGantt.current
        ? { titulo: `Gantt do projecto ${projecto.codigo ?? ''} — ${projecto.nome}`, filtros: ['Barras tracejadas = sem data de fim; linha vermelha = hoje; setas = precedências'], conteudo: refGantt.current, orientacao: 'paisagem' as const }
        : null;
    }
    type Plana = { grupo: string; nivel: number; t: TarefaWbs };
    const planas: Plana[] = [];
    const juntar = (t: TarefaWbs, grupo: string, nivel: number) => {
      planas.push({ grupo, nivel, t });
      (t.subtarefas ?? []).forEach((s) => juntar(s, grupo, nivel + 1));
    };
    (w.data?.grupos ?? []).forEach((g) => g.tarefas.forEach((t) => juntar(t, `${g.marco?.nome ?? 'Sem milestone'}${g.marco?.data ? ` · ${formatarData(g.marco.data)}` : ''} · execução ${Math.round(g.execucao)} %`, 0)));
    return {
      titulo: `Planeamento (WBS) — ${projecto.codigo ?? ''} ${projecto.nome}`,
      filtros: [`Execução global: ${Math.round(w.data?.execucao_global ?? 0)} %`],
      conteudo: tabelaHtml({
        colunas: [
          { titulo: 'Tarefa', valor: (p: Plana) => `${' '.repeat(p.nivel)}${p.t.codigo ? `${p.t.codigo} ` : ''}${p.t.nome}${tarefaAtrasada(p.t) ? ' (atrasada)' : ''}`, quebrar: true },
          { titulo: 'Responsável', valor: (p) => (p.t.atribuido_a_id ? nomeMembro.get(p.t.atribuido_a_id) ?? `#${p.t.atribuido_a_id}` : '') },
          { titulo: 'Estrutura orgânica', valor: (p) => (p.t.posicoes as string[] | undefined)?.join(', ') ?? '' },
          { titulo: 'Precedência', valor: (p) => (p.t.precedencia?.predecessora ? `${p.t.precedencia.predecessora.nome} (${p.t.precedencia.predecessora.rotulo})` : '') },
          { titulo: 'Início', valor: (p) => p.t.data_inicio, formato: 'data' },
          { titulo: 'Fim', valor: (p) => p.t.data_fim, formato: 'data' },
          { titulo: 'Estado', valor: (p) => rotuloProjectos(p.t.estado) },
          { titulo: 'Meta', valor: (p) => (p.t.meta ? `${formatarQtd(p.t.meta.feito)} / ${formatarQtd(p.t.meta.meta)} ${p.t.meta.unidade}` : '') },
          { titulo: 'Execução', valor: (p) => p.t.execucao, formato: 'percentagem' },
          { titulo: 'Horas', valor: (p) => p.t.horas, formato: 'numero' },
          { titulo: 'Valor contrato (Kz)', valor: (p) => p.t.valor_contrato, formato: 'moeda' },
        ],
        linhas: planas,
        agrupar: { chave: (p) => p.grupo },
      }),
    };
  };

  const accoesTarefa = (l: TarefaWbs) => [
    acc.gerir && { key: 'dup', icon: <CopyOutlined />, label: 'Duplicar', onClick: () => setDuplicar({ tipo: 'tarefas', id: l.id, nome: l.nome, nSub: contarSub(l) }) },
    acc.gerir && { key: 'nivel', icon: <NodeIndexOutlined />, label: 'Mudar de nível', onClick: () => setNivel({ tipo: 'tarefas', id: l.id, nome: l.nome, paiId: l.tarefa_pai_id, marcoId: l.marco_projeto_id }) },
    acc.gerir && { key: 'qtd', icon: <AppstoreAddOutlined />, label: 'Subtarefas por quantidade', onClick: () => setAssistente({ pai: l.id }) },
  ].filter(Boolean) as { key: string; icon: React.ReactNode; label: string; onClick: () => void }[];

  const colunas: ColumnsType<LinhaArvore> = [
    {
      title: 'Tarefa', key: 'nome', width: 320,
      render: (_, l) => 'grupo' in l ? (
        <Space wrap size={4}>
          <FlagOutlined style={{ color: '#8b5cf6' }} /><strong>{l.marco?.nome ?? 'Sem milestone'}</strong>
          {(l.marco?.data_inicio || l.marco?.data) && <Typography.Text type="secondary">{l.marco?.data_inicio ? `${formatarData(l.marco.data_inicio)} → ` : ''}{formatarData(l.marco?.data ?? null)}</Typography.Text>}
          {l.meta?.meta != null && <Tag color="purple">{formatarQtd(l.meta.feito)} / {formatarQtd(l.meta.meta)} {l.meta.unidade}</Tag>}
          {l.meta?.aviso && <Typography.Text type="warning" style={{ fontSize: 12 }}>{l.meta.aviso}</Typography.Text>}
        </Space>
      ) : (
        <Space wrap size={4}>
          {l.codigo && <Typography.Text type="secondary">{l.codigo}</Typography.Text>}
          {l.nome}
          {tarefaAtrasada(l) && <Tag color="red">Atrasada</Tag>}
        </Space>
      ),
    },
    { title: 'Responsável', key: 'resp', render: (_, l) => ('grupo' in l ? null : l.atribuido_a_id ? nomeMembro.get(l.atribuido_a_id) ?? `#${l.atribuido_a_id}` : '—') },
    { title: 'Estrutura orgânica', key: 'org', render: (_, l) => ('grupo' in l ? null : (l.posicoes as string[] | undefined)?.length ? (l.posicoes as string[]).map((p) => <Tag key={p}>{p}</Tag>) : '—') },
    {
      title: 'Precedência', key: 'dep',
      render: (_, l) => {
        if ('grupo' in l || !l.precedencia) return null;
        const p = l.precedencia;
        return (
          <Space size={4} wrap>
            {p.predecessora && <Tooltip title={`Depende de «${p.predecessora.nome}» — ${p.predecessora.rotulo}`}><Tag color="gold" icon={<BranchesOutlined />}>{p.predecessora.nome} <small>{p.predecessora.tipo}{p.predecessora.desf ? `${p.predecessora.desf > 0 ? '+' : ''}${p.predecessora.desf}d` : ''}</small></Tag></Tooltip>}
            {p.n_seguintes > 0 && <Tooltip title={`${p.n_seguintes} tarefa(s) dependem desta: ${p.seguintes.join(', ')}`}><Tag color="geekblue">→ {p.n_seguintes}</Tag></Tooltip>}
          </Space>
        );
      },
    },
    { title: 'Início', key: 'ini', render: (_, l) => ('grupo' in l ? null : formatarData(l.data_inicio)) },
    { title: 'Fim', key: 'fim', render: (_, l) => ('grupo' in l ? null : formatarData(l.data_fim)) },
    { title: 'Estado', key: 'est', render: (_, l) => ('grupo' in l ? l.marco ? <EtiquetaProjectos valor={l.marco.estado} /> : null : <EtiquetaProjectos valor={l.estado} />) },
    {
      title: 'Meta', key: 'meta', align: 'right',
      render: (_, l) => ('grupo' in l || !l.meta ? null : (
        <Tooltip title={l.meta.dos_filhos ? 'Soma das subtarefas' : `${l.n_medicoes ?? 0} medição(ões)`}>
          <span style={{ whiteSpace: 'nowrap' }}>{formatarQtd(l.meta.feito)} / {formatarQtd(l.meta.meta)} {l.meta.unidade}{l.meta.dos_filhos && <Typography.Text type="secondary"> ↑</Typography.Text>}</span>
        </Tooltip>
      )),
    },
    { title: 'Execução', key: 'exe', render: (_, l) => <BarraExecucao valor={l.execucao} /> },
    { title: 'Horas', key: 'h', align: 'right', render: (_, l) => ('grupo' in l ? null : <Tooltip title={l.horas_previstas ? `Previstas pelo calendário: ${formatarQtd(l.horas_previstas)} h` : undefined}>{l.horas || '—'}</Tooltip>) },
    { title: 'Valor contrato', key: 'vc', align: 'right', render: (_, l) => ('grupo' in l ? null : <ValorKz valor={l.valor_contrato} />) },
    {
      title: '', key: 'acc', align: 'right', fixed: 'right',
      render: (_, l) => {
        if ('grupo' in l) {
          if (!l.marco) return null;
          const m = l.marco;
          const nTarefas = (l.children ?? []).length;
          return (
            <Space wrap>
              {acc.gerir && <Tooltip title="Nova tarefa neste milestone"><Button size="small" icon={<PlusOutlined />} onClick={() => setTarefa({ marco_projeto_id: m.id })} /></Tooltip>}
              {acc.gerir && <Button size="small" icon={<EditOutlined />} onClick={() => setMarco(m)} />}
              {acc.gerir && (
                <Dropdown menu={{ items: [
                  { key: 'dup', icon: <CopyOutlined />, label: 'Duplicar fase', onClick: () => setDuplicar({ tipo: 'marcos', id: m.id, nome: m.nome, nSub: nTarefas }) },
                  { key: 'nivel', icon: <NodeIndexOutlined />, label: 'Converter em tarefa', onClick: () => setNivel({ tipo: 'marcos', id: m.id, nome: m.nome }) },
                  { key: 'qtd', icon: <AppstoreAddOutlined />, label: 'Tarefas por quantidade', onClick: () => setAssistente({ marco: m.id }) },
                ] }}>
                  <Button size="small" icon={<MoreOutlined />} aria-label="Mais acções da fase" />
                </Dropdown>
              )}
              {acc.eliminar && <Button size="small" danger icon={<DeleteOutlined />} onClick={() => setEliminar({ tipo: 'marco', id: m.id, nome: m.nome })} />}
            </Space>
          );
        }
        const mais = accoesTarefa(l);
        return (
          <Space wrap>
            {acc.execucao && l.mede_se && <Tooltip title="Registar quantidade executada"><Button size="small" icon={<AimOutlined />} onClick={() => setMedir(l)} /></Tooltip>}
            {acc.execucao && !l.subtarefas?.length && !l.meta && <Button size="small" onClick={() => setExecucao(l)}>%</Button>}
            {acc.gerir && <Tooltip title="Nova subtarefa"><Button size="small" icon={<PlusOutlined />} onClick={() => setTarefa({ tarefa_pai_id: l.id, marco_projeto_id: l.marco_projeto_id })} /></Tooltip>}
            {acc.gerir && <Button size="small" icon={<EditOutlined />} onClick={() => setTarefa(l)} />}
            {mais.length > 0 && <Dropdown menu={{ items: mais }}><Button size="small" icon={<MoreOutlined />} aria-label="Mais acções da tarefa" /></Dropdown>}
            {acc.eliminar && <Button size="small" danger icon={<DeleteOutlined />} onClick={() => setEliminar({ tipo: 'tarefa', id: l.id, nome: l.nome })} />}
          </Space>
        );
      },
    },
  ];

  return (
    <Card>
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
        <Space wrap>
          <Segmented value={vista} onChange={(v) => setVista(v as 'wbs' | 'gantt')} options={[{ value: 'wbs', label: 'WBS' }, { value: 'gantt', label: 'Gantt' }]} />
          <Typography.Text>Execução global: </Typography.Text><BarraExecucao valor={w.data?.execucao_global} largura={180} />
        </Space>
        <Space wrap>
          {acc.gerir && <Button icon={<FlagOutlined />} onClick={() => setMarco({})}>Novo milestone</Button>}
          {acc.gerir && <Button icon={<AppstoreAddOutlined />} onClick={() => setAssistente({})}>Tarefas por quantidade</Button>}
          {acc.execucao && <Button icon={<UnorderedListOutlined />} onClick={() => setFolha({ destacar: null })}>Folha de medição</Button>}
          <Button icon={<TableOutlined />} onClick={() => setMapa(true)}>Mapa de quantidades</Button>
          {acc.gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setTarefa({})}>Nova tarefa</Button>}
          <BotoesExportar desactivado={!w.data} obterPedido={pedidoImpressao} />
        </Space>
      </Flex>
      {vista === 'wbs' ? (
        <>
        <Table<LinhaArvore>
          rowKey="chave"
          size="small"
          loading={w.isFetching}
          dataSource={arvore}
          columns={colunas}
          pagination={false}
          scroll={scrollTabela()}
          expandable={{ defaultExpandAllRows: true }}
          key={arvore.length}
          onRow={(l) => (acc.gerir ? {
            draggable: !('grupo' in l),
            onDragStart: (e) => { if (!('grupo' in l)) { setArrastada(l.id); e.dataTransfer.effectAllowed = 'move'; } },
            onDragEnd: () => { setArrastada(null); setSobre(null); },
            onDragOver: (e) => { if (arrastada !== null) { e.preventDefault(); setSobre(l.chave); } },
            onDrop: (e) => largar(l, e),
            style: { cursor: 'grab', background: sobre === l.chave ? 'rgba(37, 99, 235, 0.08)' : undefined },
          } : {})}
        />
        {acc.gerir && (
          <Typography.Paragraph type="secondary" style={{ marginTop: 8, fontSize: 12 }} className="imp-nao-imprimir">
            Arraste uma tarefa para a reordenar: metade de cima da linha = antes, metade de baixo = depois, meio da linha (ou Shift) = subtarefa; sobre um milestone = mudar de milestone.
            Entre irmãs, as tarefas com datas aparecem por data e nunca antes da sua predecessora.
          </Typography.Paragraph>
        )}
        </>
      ) : (
        <>
          <Flex gap={8} wrap style={{ marginBottom: 8 }} className="imp-nao-imprimir">
            <Select allowClear placeholder="Escala automática" value={escala} onChange={setEscala} options={ESCALAS} style={{ width: 170 }} aria-label="Escala do Gantt" />
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} allowEmpty={[true, true]} placeholder={['Desde', 'Até']} />
            <Segmented value={soFases} onChange={(v) => setSoFases(v as typeof soFases)} options={[{ value: 'tudo', label: 'Tudo' }, { value: 'tarefas', label: 'Fases e tarefas' }, { value: 'fases', label: 'Só fases' }]} />
          </Flex>
          <Gantt ref={refGantt} linhas={linhasGantt} escala={escala} ligacoes={ligacoesWbs(w.data)}
            inicio={periodo?.[0]?.format('YYYY-MM-DD')} fim={periodo?.[1]?.format('YYYY-MM-DD')}
            colunaExtra={{ titulo: 'Precedência', largura: 140, valor: (l) => {
              const t = porChave.get(l.chave);
              return t?.precedencia?.predecessora ? <Tooltip title={`${t.precedencia.predecessora.nome} — ${t.precedencia.predecessora.rotulo}`}><Typography.Text ellipsis style={{ fontSize: 12 }}>← {t.precedencia.predecessora.nome}</Typography.Text></Tooltip> : null;
            } }} />
        </>
      )}
      <ModalTarefa projectoId={projecto.id} tarefa={tarefa} aoFechar={() => setTarefa(null)} />
      <ModalMarco projectoId={projecto.id} marco={marco} aoFechar={() => setMarco(null)} />
      <ModalExecucao projectoId={projecto.id} tarefa={execucao} aoFechar={() => setExecucao(null)} />
      <ModalMedicao projectoId={projecto.id} tarefa={medir} aoFechar={() => setMedir(null)} aoLinhas={() => setFolha({ destacar: medir?.id ?? null })} />
      <ModalFolhaMedicao projectoId={projecto.id} aberto={!!folha} destacar={folha?.destacar} aoFechar={() => setFolha(null)} />
      <ModalTarefasQuantidade projectoId={projecto.id} aberto={!!assistente} inicial={assistente ?? undefined} aoFechar={() => setAssistente(null)} />
      <ModalMapaQuantidades projectoId={projecto.id} projectoNome={`${projecto.codigo ?? ''} ${projecto.nome}`.trim()} aberto={mapa} aoFechar={() => setMapa(false)} />
      <ModalDuplicar projectoId={projecto.id} alvo={duplicar} aoFechar={() => setDuplicar(null)} />
      <ModalNivel projectoId={projecto.id} alvo={nivel} aoFechar={() => setNivel(null)} />
      <ModalEliminar
        aberto={!!eliminar}
        titulo={`Eliminar ${eliminar?.tipo === 'marco' ? 'o milestone' : 'a tarefa'} «${eliminar?.nome ?? ''}»`}
        aviso={eliminar?.tipo === 'marco' ? 'As tarefas do milestone passam a «sem milestone».' : 'As subtarefas também são eliminadas. Tarefas com registos (horas, custos, autos) não podem ser eliminadas; as tarefas que dependiam desta ficam sem predecessora.'}
        carregando={accEliminar.isPending}
        aoFechar={() => setEliminar(null)}
        aoConfirmar={(confirmacao) => eliminar && accEliminar.mutate({ metodo: 'delete', url: `/projetos/${projecto.id}/${eliminar.tipo === 'marco' ? 'marcos' : 'tarefas'}/${eliminar.id}`, dados: { confirmacao } })}
      />
    </Card>
  );
}

/**
 * Modal da tarefa (saveTask, ui_projects.js:2188-2475). Versão Actual: meta por quantidade (com meta a % é calculada),
 * dependência (predecessora, tipo, desfasamento) e posição no organigrama. Datas fora do nível acima: o servidor recusa
 * e o ecrã oferece encaixá-las; CONCLUÍDA com o realizado abaixo da meta pede confirmação.
 */
function ModalTarefa({ projectoId, tarefa, aoFechar }: { projectoId: number; tarefa: Partial<TarefaWbs> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const w = useWbs(projectoId);
  const editar = !!tarefa?.id;
  const candidatas = useQuery({
    queryKey: ['projectos', 'candidatas-predecessora', projectoId, tarefa?.id ?? null],
    queryFn: () => obter<{ id: number; codigo: string | null; nome: string }[]>(`/projetos/${projectoId}/tarefas/candidatas-predecessora`, { tarefa_id: tarefa?.id }),
    enabled: !!tarefa,
  });
  const accao = useAccao<{ avisos?: string[] }>({
    invalidar: [['projectos']],
    aoSucesso: (d) => { aoFechar(); mostrarAvisos(d?.avisos); },
    aoErro: (e, pedido) => {
      if (!(e instanceof ErroApi)) return false;
      if (e.codigo === 'DATAS_FORA_DO_NIVEL') {
        Modal.confirm({ title: 'Datas fora do nível acima', content: `${e.message} Encaixar as datas automaticamente?`, okText: 'Encaixar e gravar', cancelText: 'Corrigir à mão',
          onOk: () => accao.mutate({ ...pedido, dados: { ...(pedido.dados as object), encaixar_datas: true } }) });
        return true;
      }
      if (e.codigo === 'DEPENDENCIA_DATA' && typeof e.erros?.data_minima === 'string') {
        const min = e.erros.data_minima;
        Modal.confirm({ title: 'Precedência', content: `${e.message} Passar o início para ${formatarData(min)}?`, okText: 'Ajustar o início', cancelText: 'Corrigir à mão',
          onOk: () => {
            const d = pedido.dados as Record<string, unknown>;
            const dur = d.data_inicio && d.data_fim ? dayjs(d.data_fim as string).diff(dayjs(d.data_inicio as string), 'day') : null;
            const tipo = d.tipo_dependencia;
            const novo = tipo === 'FF' ? { data_fim: min, data_inicio: dur !== null ? dayjs(min).subtract(dur, 'day').format('YYYY-MM-DD') : d.data_inicio }
              : { data_inicio: min, data_fim: dur !== null ? dayjs(min).add(dur, 'day').format('YYYY-MM-DD') : d.data_fim };
            accao.mutate({ ...pedido, dados: { ...d, ...novo } });
          } });
        return true;
      }
      return false;
    },
  });
  useEffect(() => {
    if (!tarefa) return;
    form.resetFields();
    form.setFieldsValue({
      ...tarefa,
      data_inicio: tarefa.data_inicio ? dayjs(tarefa.data_inicio) : undefined,
      data_fim: tarefa.data_fim ? dayjs(tarefa.data_fim) : undefined,
      valor_contrato: tarefa.valor_contrato ? Number(tarefa.valor_contrato) : undefined,
      estado: tarefa.estado ?? 'PENDENTE',
      meta_quantidade: tarefa.meta_quantidade ? Number(tarefa.meta_quantidade) : undefined,
      meta_realizado: tarefa.meta_realizado ? Number(tarefa.meta_realizado) : undefined,
      tipo_dependencia: tarefa.tipo_dependencia || 'FS',
      desfasamento_dias: tarefa.desfasamento_dias ?? 0,
      no_organigrama_projeto_id: tarefa.posicoes_ids?.[0],
    });
  }, [tarefa, form]);
  const marcos = (w.data?.grupos ?? []).filter((g) => g.marco).map((g) => ({ value: g.marco!.id, label: g.marco!.nome }));
  const metaQ = Number(Form.useWatch('meta_quantidade', form) ?? 0);
  const metaF = Number(Form.useWatch('meta_realizado', form) ?? 0);
  const pred = Form.useWatch('tarefa_predecessora_id', form);
  const tipoDep = (Form.useWatch('tipo_dependencia', form) as string | undefined) ?? 'FS';
  const doFilhos = !!tarefa?.meta?.dos_filhos;
  const pct = pctDaMeta(metaF, metaQ);

  const gravar = (v: Record<string, unknown>) => {
    const dados: Record<string, unknown> = { ...v, data_inicio: dataApi(v.data_inicio as dayjs.Dayjs) ?? null, data_fim: dataApi(v.data_fim as dayjs.Dayjs) ?? null, codigo: v.codigo || null };
    for (const k of ['tarefa_pai_id', 'marco_projeto_id', 'atribuido_a_id', 'valor_contrato', 'percentagem_execucao', 'tarefa_predecessora_id', 'no_organigrama_projeto_id']) if (dados[k] === undefined) dados[k] = null;
    if (!metaQ) { dados.meta_quantidade = null; dados.meta_unidade = null; dados.meta_realizado = null; }
    if (doFilhos) delete dados.meta_realizado;
    if (metaQ > 0) delete dados.percentagem_execucao;
    if (!dados.tarefa_predecessora_id) { dados.tipo_dependencia = null; dados.desfasamento_dias = null; }
    const enviarPedido = () => accao.mutate(editar ? { metodo: 'put', url: `/projetos/${projectoId}/tarefas/${tarefa?.id}`, dados } : { url: `/projetos/${projectoId}/tarefas`, dados });
    if (dados.estado === 'CONCLUIDA' && metaQ > 0 && metaF < metaQ && !doFilhos) {
      Modal.confirm({ title: 'Concluir abaixo da meta?', content: `A tarefa está marcada como CONCLUÍDA mas só tem ${formatarQtd(metaF)} de ${formatarQtd(metaQ)} ${String(v.meta_unidade ?? '')}. Gravar mesmo assim?`, okText: 'Gravar', cancelText: 'Cancelar', onOk: enviarPedido });
      return;
    }
    enviarPedido();
  };

  return (
    <Modal title={editar ? `Editar tarefa «${tarefa?.nome}»` : tarefa?.tarefa_pai_id ? 'Nova subtarefa' : 'Nova tarefa'} open={!!tarefa} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(820)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={gravar}>
        <Row gutter={12}>
          <Col xs={24} md={6}><Form.Item name="codigo" label="Código"><Input maxLength={50} /></Form.Item></Col>
          <Col xs={24} md={18}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="marco_projeto_id" label="Milestone"><Select allowClear options={marcos} placeholder="Sem milestone" /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="tarefa_pai_id" label="Tarefa-mãe"><SeletorTarefa projectoId={projectoId} allowClear placeholder="Nenhuma (tarefa principal)" /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="atribuido_a_id" label="Responsável"><SeletorMembro projectoId={projectoId} allowClear /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="no_organigrama_projeto_id" label="Posição no organigrama do projecto"><SeletorPosicao projectoId={projectoId} allowClear /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="data_inicio" label="Início"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={6}>
            <Form.Item name="data_fim" label="Fim" dependencies={['data_inicio']} rules={[({ getFieldValue }) => ({
              validator: (_, v) => (!v || !getFieldValue('data_inicio') || !v.isBefore(getFieldValue('data_inicio'), 'day') ? Promise.resolve() : Promise.reject(new Error('O fim é anterior ao início.'))),
            })]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="estado" label="Estado">
              <Select options={[{ value: 'PENDENTE', label: 'Pendente' }, { value: 'EM_CURSO', label: 'Em curso' }, { value: 'CONCLUIDA', label: 'Concluída' }, { value: 'BLOQUEADA', label: 'Bloqueada' }]} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}><Form.Item name="valor_contrato" label="Valor de contrato (Kz)" tooltip="Subempreitada: base dos autos de medição"><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        <Divider orientation="left" plain style={{ margin: '4px 0 12px' }}>Meta por quantidade</Divider>
        <Row gutter={12}>
          <Col xs={24} md={6}><Form.Item name="meta_unidade" label="Unidade" rules={[{ required: metaQ > 0, message: 'Indique a unidade da meta.' }]}><AutoComplete options={UNIDADES.map((u) => ({ value: u }))} placeholder="m², m, un…" /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="meta_quantidade" label="Meta"><InputNumber min={0} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="meta_realizado" label="Já realizado" tooltip={doFilhos ? 'Sobe das subtarefas com meta' : 'Para o dia a dia use «Registar execução» (fica no histórico)'}><InputNumber min={0} disabled={doFilhos} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
          <Col xs={24} md={6}>
            {metaQ > 0 ? <Form.Item label="% executada"><Typography.Text strong>{pct ?? 0} %</Typography.Text><Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>Calculada pela meta</Typography.Text></Form.Item>
              : <Form.Item name="percentagem_execucao" label="% executada"><InputNumber min={0} max={100} precision={0} style={{ width: '100%' }} /></Form.Item>}
          </Col>
        </Row>
        <Divider orientation="left" plain style={{ margin: '4px 0 12px' }}>Dependência</Divider>
        <Row gutter={12}>
          <Col xs={24} md={12}>
            <Form.Item name="tarefa_predecessora_id" label="Depende de" extra="Não aparecem a própria tarefa, as suas subtarefas nem as que criariam um ciclo.">
              <Select allowClear showSearch optionFilterProp="label" loading={candidatas.isFetching} placeholder="Sem predecessora"
                options={(candidatas.data ?? []).map((c) => ({ value: c.id, label: `${c.codigo ? `${c.codigo} ` : ''}${c.nome}` }))} />
            </Form.Item>
          </Col>
          <Col xs={24} md={7}><Form.Item name="tipo_dependencia" label="Tipo de ligação"><Select disabled={!pred} options={Object.entries(TIPOS_DEP).map(([k, t]) => ({ value: k, label: t.nome, title: t.ajuda }))} /></Form.Item></Col>
          <Col xs={24} md={5}><Form.Item name="desfasamento_dias" label="Desfasamento (dias)"><InputNumber disabled={!pred} min={-3650} max={3650} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        {pred && <Alert type="info" showIcon message={`${rotuloDependencia(tipoDep, Number(form.getFieldValue('desfasamento_dias') ?? 0))}: ${TIPOS_DEP[tipoDep]?.ajuda ?? ''}.`} />}
      </Form>
    </Modal>
  );
}

/** Fase / milestone: designação, data de início (Versão Actual), data alvo e meta (saveMilestone, ui_projects.js:2481-2560). */
function ModalMarco({ projectoId, marco, aoFechar }: { projectoId: number; marco: Partial<Marco> | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ nome: string; data_inicio?: dayjs.Dayjs; data?: dayjs.Dayjs; meta_unidade?: string; meta_quantidade?: number }>();
  const accao = useAccao<{ avisos?: string[] }>({ invalidar: [['projectos']], aoSucesso: (d) => { aoFechar(); mostrarAvisos(d?.avisos); } });
  const metaQ = Number(Form.useWatch('meta_quantidade', form) ?? 0);
  useEffect(() => {
    if (marco) form.setFieldsValue({ nome: marco.nome ?? '', data: marco.data ? dayjs(marco.data) : undefined, data_inicio: marco.data_inicio ? dayjs(marco.data_inicio) : undefined,
      meta_unidade: marco.meta_unidade ?? undefined, meta_quantidade: marco.meta_quantidade ? Number(marco.meta_quantidade) : undefined });
  }, [marco, form]);
  return (
    <Modal title={marco?.id ? 'Editar milestone' : 'Novo milestone'} open={!!marco} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = { nome: v.nome, data: dataApi(v.data) ?? null, data_inicio: dataApi(v.data_inicio) ?? null, meta_unidade: metaQ > 0 ? v.meta_unidade ?? null : null, meta_quantidade: metaQ > 0 ? metaQ : null };
        accao.mutate(marco?.id ? { metodo: 'put', url: `/projetos/${projectoId}/marcos/${marco.id}`, dados } : { url: `/projetos/${projectoId}/marcos`, dados });
      }}>
        <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
        <Space wrap>
          <Form.Item name="data_inicio" label="Data de início"><DatePicker format="DD/MM/YYYY" /></Form.Item>
          <Form.Item name="data" label="Data alvo" dependencies={['data_inicio']} rules={[({ getFieldValue }) => ({
            validator: (_, v) => (!v || !getFieldValue('data_inicio') || !v.isBefore(getFieldValue('data_inicio'), 'day') ? Promise.resolve() : Promise.reject(new Error('A data alvo é anterior ao início.'))),
          })]}><DatePicker format="DD/MM/YYYY" /></Form.Item>
        </Space>
        <Space wrap>
          <Form.Item name="meta_quantidade" label="Meta da fase"><InputNumber min={0} style={{ width: 150 }} decimalSeparator="," /></Form.Item>
          <Form.Item name="meta_unidade" label="Unidade" rules={[{ required: metaQ > 0, message: 'Indique a unidade.' }]}><AutoComplete options={UNIDADES.map((u) => ({ value: u }))} style={{ width: 150 }} placeholder="m²" /></Form.Item>
        </Space>
        <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>O realizado da fase sobe das tarefas; a WBS avisa quando falta repartir a meta ou quando as tarefas a ultrapassam.</Typography.Paragraph>
      </Form>
    </Modal>
  );
}

function ModalExecucao({ projectoId, tarefa, aoFechar }: { projectoId: number; tarefa: TarefaWbs | null; aoFechar: () => void }) {
  const [valor, setValor] = useState(0);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => setValor(tarefa?.percentagem_execucao ?? tarefa?.execucao ?? 0), [tarefa]);
  return (
    <Modal title={`Execução de «${tarefa?.nome ?? ''}»`} open={!!tarefa} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ url: `/projetos/${projectoId}/tarefas/${tarefa?.id}/execucao`, dados: { percentagem_execucao: valor } })}>
      <Flex gap={16} align="center" wrap>
        <Slider style={{ flex: 1, minWidth: 160 }} min={0} max={100} step={5} value={valor} onChange={setValor} />
        <InputNumber min={0} max={100} precision={0} value={valor} onChange={(v) => setValor(v ?? 0)} suffix="%" style={{ width: 110 }} />
      </Flex>
    </Modal>
  );
}
