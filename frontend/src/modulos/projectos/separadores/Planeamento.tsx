import { Alert, AutoComplete, Button, Card, Col, DatePicker, Divider, Dropdown, Flex, Form, Input, InputNumber, List, Modal, Row, Segmented, Select, Slider, Space, Switch, Table, Tag, Tooltip, Typography, message } from 'antd';
import { BranchesOutlined, CopyOutlined, DeleteOutlined, EditOutlined, FlagOutlined, MoreOutlined, NodeIndexOutlined, PlusOutlined, AimOutlined, TableOutlined, UnorderedListOutlined, AppstoreAddOutlined, LinkOutlined, ShrinkOutlined, ArrowsAltOutlined, ArrowUpOutlined, ArrowDownOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ColumnsType } from 'antd/es/table';
import dayjs from 'dayjs';
import { useEffect, useMemo, useRef, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { rotuloProjectos } from '../comum/componentes';
import { useAccao } from '@/componentes/Accoes';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { BarraExecucao, EtiquetaProjectos, ModalEliminar, SeletorMembro, SeletorPosicao, SeletorTarefa, useEquipa, useWbs } from '../comum/componentes';
import { Gantt, type LigacaoGantt } from '../comum/Gantt';
import { achatarWbs, alternarChave, chavesRecolhiveis, contarTarefasFase, ordenarGruposWbs, datasParaDataMinima, escalaDasQuantidades, escalaGantt, ligacaoPorArrasto, ligacoesWbs, linhasGanttWbs, quantidadesPorPeriodo, tarefaAtrasada } from '../comum/regras';
import { ESCALAS, formatarQtd, pctDaMeta, ritmoExigido, rotuloDependencia, textoRitmo, TIPOS_DEP, UNIDADES, type Escala } from '../comum/regrasMetas';
import type { MapaQuantidades, Marco, TarefaWbs } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { descricaoMover, descricaoNivelFase, pedidoMover, pedidoNivelFase } from '../comum/arrastar';
import { SeletorFlag } from '../comum/FlagTarefa';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { ModalFormulario, SeccaoFormulario, type CampoFormulario } from '@/componentes/formularios';
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
  // Gantt (Versão Actual, ui_projects.js:1800-1812): árvore recolhível, quantidades por período e ligações por arrasto
  const [fechadas, setFechadas] = useState<Set<string>>(() => new Set());
  const [comQuantidades, setComQuantidades] = useState(false);
  const [ligacao, setLigacao] = useState<{ predecessora?: number; tarefa?: number; substitui?: boolean } | null>(null);
  const [eliminar, setEliminar] = useState<{ tipo: 'tarefa' | 'marco'; id: number; nome: string } | null>(null);
  const accEliminar = useAccao({ invalidar: [['projectos']], aoSucesso: () => setEliminar(null) });
  // M-20: arrastar tarefas (reordenar, passar a subtarefa ou mudar de milestone) — POST /tarefas/{t}/mover
  // #10: arrastar fases (converter a fase em tarefa principal de outra fase ou em subtarefa) — POST /marcos/{id}/nivel
  const [arrastada, setArrastada] = useState<{ tipo: 'tarefa' | 'fase'; id: number; nome: string } | null>(null);
  const [sobre, setSobre] = useState<string | null>(null);
  const accMover = useAccao<{ avisos?: string[] }>({ invalidar: [['projectos']], tituloErro: 'Não foi possível mover a tarefa', aoSucesso: (d) => mostrarAvisos(d?.avisos) });
  const accNivelFase = useAccao<{ avisos?: string[] }>({ invalidar: [['projectos']], tituloErro: 'Não foi possível mudar a fase de nível', aoSucesso: (d) => mostrarAvisos(d?.avisos) });
  const largar = (l: LinhaArvore, e: React.DragEvent<HTMLElement>) => {
    e.preventDefault();
    setSobre(null);
    if (arrastada === null) return;
    const r = e.currentTarget.getBoundingClientRect();
    const alvo = 'grupo' in l ? { marco: l.marco?.id ?? null } : { tarefa: l.id };
    const nomeAlvo = 'grupo' in l ? l.marco?.nome ?? 'Sem milestone' : l.nome;
    const origem = arrastada;
    setArrastada(null);
    if (origem.tipo === 'fase') {
      const pf = pedidoNivelFase(origem.id, alvo);
      if (!pf) return;
      Modal.confirm({
        title: 'Mudar a fase de nível?', content: `${descricaoNivelFase(pf, origem.nome, nomeAlvo)} As datas são encaixadas no novo nível acima, se não couberem.`,
        okText: 'Mudar de nível', cancelText: 'Cancelar',
        onOk: () => accNivelFase.mutateAsync({ url: `/projetos/${projecto.id}/marcos/${origem.id}/nivel`, dados: pf }),
      });
      return;
    }
    const p = pedidoMover(origem.id, alvo, e.clientY - r.top, r.height, e.shiftKey);
    if (!p) return;
    accMover.mutate({ url: `/projetos/${projecto.id}/tarefas/${origem.id}/mover`, dados: p });
    void message.info(`Tarefa movida ${descricaoMover(p, nomeAlvo)}.`);
  };
  const nomeMembro = useMemo(() => new Map((equipa.data?.membros ?? []).map((m) => [m.id, m.nome])), [equipa.data]);
  const refGantt = useRef<HTMLDivElement>(null);

  const arvore: LinhaArvore[] = ordenarGruposWbs(w.data?.grupos)
    .filter((g) => g.marco || g.tarefas.length)
    .map((g) => ({ chave: `m-${g.marco?.id ?? 'sem'}`, grupo: true as const, marco: g.marco, execucao: g.execucao, meta: g.meta, children: g.tarefas.length ? g.tarefas.map(paraArvore) : undefined }));
  // reordenar marcos (botões ↑/↓): posição de cada marco entre os outros, pela mesma ordem da árvore
  const idsMarcosOrdenados = useMemo(() => arvore.filter((l) => 'grupo' in l && l.marco).map((l) => (l as { marco: Marco }).marco.id), [arvore]);
  const accMoverMarco = useAccao({ invalidar: [['projectos']], tituloErro: 'Não foi possível mover o milestone' });
  const linhasGantt = useMemo(() => linhasGanttWbs(w.data, {
    periodo: periodo ? { de: periodo[0]?.format('YYYY-MM-DD'), ate: periodo[1]?.format('YYYY-MM-DD') } : undefined,
    nivelMaximo: soFases === 'fases' ? 0 : soFases === 'tarefas' ? 1 : undefined,
    fasesFechadas: fechadas,
  }), [w.data, periodo, soFases, fechadas]);
  const ligacoes = useMemo(() => ligacoesWbs(w.data), [w.data]);
  const inicioGantt = periodo?.[0]?.format('YYYY-MM-DD');
  const fimGantt = periodo?.[1]?.format('YYYY-MM-DD');
  // quantidades por período: o mapa de quantidades na escala e na janela do Gantt
  const escalaG = useMemo(() => escalaGantt(linhasGantt, inicioGantt, fimGantt), [linhasGantt, inicioGantt, fimGantt]);
  const escalaQtd = escalaDasQuantidades(escala, escalaG);
  const deQtd = escalaG.inicio.format('YYYY-MM-DD');
  const ateQtd = escalaG.fim.format('YYYY-MM-DD');
  const mapaQtd = useQuery({
    queryKey: ['projectos', 'mapa-quantidades', projecto.id, escalaQtd, deQtd, ateQtd],
    queryFn: () => obter<MapaQuantidades>(`/projetos/${projecto.id}/mapa-quantidades`, { escala: escalaQtd, de: deQtd, ate: ateQtd }),
    enabled: vista === 'gantt' && comQuantidades && linhasGantt.length > 0,
    retry: false,
  });
  const quantidades = useMemo(() => (comQuantidades && mapaQtd.data ? quantidadesPorPeriodo(mapaQtd.data, escalaG) : undefined), [comQuantidades, mapaQtd.data, escalaG]);
  const removerLigacao = (g: LigacaoGantt) => {
    const t = porChaveRef.current.get(g.para);
    const p = porChaveRef.current.get(g.de);
    if (!t) return;
    Modal.confirm({
      title: 'Remover a precedência?', content: `«${t.nome}» deixa de depender de «${p?.nome ?? '?'}» (${rotuloDependencia(g.tipo, g.desf)}). As datas não mudam.`,
      okText: 'Remover', okButtonProps: { danger: true }, cancelText: 'Cancelar',
      onOk: () => accLigacao.mutateAsync({ metodo: 'put', url: `/projetos/${projecto.id}/tarefas/${t.id}`, dados: { tarefa_predecessora_id: null, tipo_dependencia: null, desfasamento_dias: null } }),
    });
  };
  const accLigacao = useAccao<{ avisos?: string[] }>({ invalidar: [['projectos']], tituloErro: 'Não foi possível remover a precedência', aoSucesso: (d) => mostrarAvisos(d?.avisos) });
  const porChave = useMemo(() => {
    const m = new Map<string, TarefaWbs>();
    const visitar = (t: TarefaWbs) => { m.set(`t-${t.id}`, t); t.subtarefas?.forEach(visitar); };
    w.data?.grupos.forEach((g) => g.tarefas.forEach(visitar));
    return m;
  }, [w.data]);
  const tarefasPorFase = useMemo(() => new Map((w.data?.grupos ?? []).map((g) => [`m-${g.marco?.id ?? 'sem'}`, contarTarefasFase(g)])), [w.data]);
  const porChaveRef = useRef(porChave);
  porChaveRef.current = porChave;

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
    ordenarGruposWbs(w.data?.grupos).forEach((g) => g.tarefas.forEach((t) => juntar(t, `${g.marco?.nome ?? 'Sem milestone'}${g.marco?.data ? ` · ${formatarData(g.marco.data)}` : ''} · execução ${Math.round(g.execucao)} %`, 0)));
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
          {acc.gerir && <SeletorFlag projecto={projecto.id} tarefa={l.id} flag={l.flag} />}
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
          const iMarco = idsMarcosOrdenados.indexOf(m.id);
          return (
            <Space wrap>
              {acc.gerir && (
                <Space.Compact>
                  <Tooltip title="Subir milestone">
                    <Button size="small" icon={<ArrowUpOutlined />} disabled={iMarco <= 0}
                      onClick={() => accMoverMarco.mutate({ url: `/projetos/${projecto.id}/marcos/${m.id}/mover`, dados: { direcao: 'CIMA' } })} />
                  </Tooltip>
                  <Tooltip title="Descer milestone">
                    <Button size="small" icon={<ArrowDownOutlined />} disabled={iMarco < 0 || iMarco >= idsMarcosOrdenados.length - 1}
                      onClick={() => accMoverMarco.mutate({ url: `/projetos/${projecto.id}/marcos/${m.id}/mover`, dados: { direcao: 'BAIXO' } })} />
                  </Tooltip>
                </Space.Compact>
              )}
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
            draggable: !('grupo' in l) || !!l.marco,
            onDragStart: (e) => {
              if (!('grupo' in l)) setArrastada({ tipo: 'tarefa', id: l.id, nome: l.nome });
              else if (l.marco) setArrastada({ tipo: 'fase', id: l.marco.id, nome: l.marco.nome });
              else return;
              e.dataTransfer.effectAllowed = 'move';
            },
            onDragEnd: () => { setArrastada(null); setSobre(null); },
            onDragOver: (e) => { if (arrastada !== null) { e.preventDefault(); setSobre(l.chave); } },
            onDrop: (e) => largar(l, e),
            style: { cursor: 'grab', background: sobre === l.chave ? 'rgba(37, 99, 235, 0.08)' : undefined },
          } : {})}
        />
        {acc.gerir && (
          <Typography.Paragraph type="secondary" style={{ marginTop: 8, fontSize: 12 }} className="imp-nao-imprimir">
            Arraste uma tarefa para a reordenar: metade de cima da linha = antes, metade de baixo = depois, meio da linha (ou Shift) = subtarefa; sobre um milestone = mudar de milestone.
            Arraste uma fase para outra fase (passa a tarefa principal dela) ou para uma tarefa (passa a subtarefa); sem rato, use «Mudar de nível» no menu da linha.
            Entre irmãs, as tarefas com datas aparecem por data e nunca antes da sua predecessora.
          </Typography.Paragraph>
        )}
        </>
      ) : (
        <>
          <Flex gap={8} wrap style={{ marginBottom: 8 }} className="imp-nao-imprimir">
            {/* «Ver por» do legado (barraEscala, ui_projects.js:903-912): barra de botões; «Auto» = escala automática */}
            <Segmented aria-label="Escala do Gantt" value={escala ?? 'auto'} onChange={(v) => setEscala(v === 'auto' ? undefined : (v as Escala))}
              options={[{ value: 'auto', label: 'Auto' }, ...ESCALAS]} />
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} allowEmpty={[true, true]} placeholder={['Desde', 'Até']} />
            <Segmented value={soFases} onChange={(v) => setSoFases(v as typeof soFases)} options={[{ value: 'tudo', label: 'Tudo' }, { value: 'tarefas', label: 'Fases e tarefas' }, { value: 'fases', label: 'Só fases' }]} />
            <Space.Compact>
              <Tooltip title="Abrir todos os níveis"><Button icon={<ArrowsAltOutlined />} onClick={() => setFechadas(new Set())}>Abrir árvore</Button></Tooltip>
              <Tooltip title="Fechar tudo e deixar só as fases"><Button icon={<ShrinkOutlined />} onClick={() => setFechadas(chavesRecolhiveis(linhasGanttWbs(w.data)))}>Fechar árvore</Button></Tooltip>
              <Tooltip title="Fases e tarefas principais, sem subtarefas"><Button onClick={() => setFechadas(chavesRecolhiveis(linhasGanttWbs(w.data), true))}>Só tarefas principais</Button></Tooltip>
            </Space.Compact>
            <Tooltip title="Reparte a meta de cada tarefa pelos períodos da escala (dias de trabalho do calendário do projecto)">
              <Space><Switch size="small" checked={comQuantidades} onChange={setComQuantidades} aria-label="Quantidades por período" loading={mapaQtd.isFetching} /> Quantidades por período</Space>
            </Tooltip>
            {acc.gerir && <Button icon={<LinkOutlined />} onClick={() => setLigacao({})}>Ligações</Button>}
          </Flex>
          {comQuantidades && (
            <Alert className="imp-nao-imprimir" type={mapaQtd.error ? 'error' : 'info'} showIcon style={{ marginBottom: 8 }}
              message={mapaQtd.error ? (mapaQtd.error as Error).message
                : <>Em baixo de cada linha, a cinzento o previsto do período; <Typography.Text style={{ color: '#15803d' }} strong>verde</Typography.Text> quando o executado chega ao previsto, <Typography.Text style={{ color: '#b45309' }} strong>âmbar</Typography.Text> quando o período já começou e ficou aquém (executado/previsto).</>} />
          )}
          {acc.gerir && (
            <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginBottom: 8 }} className="imp-nao-imprimir">
              Para ligar duas tarefas, arraste a pega redonda no fim de uma barra até à barra da tarefa que depende dela; clique numa seta para a remover. Sem rato, use o botão «Ligações».
            </Typography.Paragraph>
          )}
          <Gantt ref={refGantt} linhas={linhasGantt} escala={escala} ligacoes={ligacoes}
            inicio={inicioGantt} fim={fimGantt}
            fechadas={fechadas} aoAlternar={(c) => setFechadas((f) => alternarChave(f, c))}
            quantidades={quantidades}
            aoLigar={acc.gerir ? (o, d) => {
              const r = ligacaoPorArrasto(o, d, ligacoes);
              if (!r) return;
              if (!r.ok) { void message.warning(r.erro); return; }
              setLigacao({ predecessora: r.predecessora, tarefa: r.tarefa, substitui: r.substitui });
            } : undefined}
            aoRemoverLigacao={acc.gerir ? removerLigacao : undefined}
            colunasExtra={[
              // Responsável (celulaResp, ui_projects.js:1907-1920): membro atribuído; na linha da fase, «N tarefa(s)»
              { titulo: 'Responsável', largura: 140, valor: (l) => {
                if (l.tipo === 'marco') return <Typography.Text type="secondary" style={{ fontSize: 12 }}>{tarefasPorFase.get(l.chave) ?? 0} tarefa(s)</Typography.Text>;
                const id = porChave.get(l.chave)?.atribuido_a_id;
                const nomeR = id ? nomeMembro.get(id) ?? `#${id}` : undefined;
                return nomeR ? <Tooltip title={nomeR}><Typography.Text ellipsis style={{ fontSize: 12 }}>{nomeR}</Typography.Text></Tooltip> : null;
              } },
              { titulo: 'Precedência', largura: 140, valor: (l) => {
                const t = porChave.get(l.chave);
                return t?.precedencia?.predecessora ? <Tooltip title={`${t.precedencia.predecessora.nome} — ${t.precedencia.predecessora.rotulo}`}><Typography.Text ellipsis style={{ fontSize: 12 }}>← {t.precedencia.predecessora.nome}</Typography.Text></Tooltip> : null;
              } },
              { titulo: 'Estrutura orgânica', largura: 150, valor: (l) => {
                const pos = (porChave.get(l.chave)?.posicoes as string[] | undefined) ?? [];
                return pos.length ? <Tooltip title={pos.join(', ')}><Typography.Text ellipsis style={{ fontSize: 12 }}>{pos.join(', ')}</Typography.Text></Tooltip> : null;
              } },
            ]} />
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
      <ModalLigacao projectoId={projecto.id} inicial={ligacao} porChave={porChave} ligacoes={ligacoes} aoRemover={removerLigacao} aoFechar={() => setLigacao(null)} />
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

/** Data do formulário (dayjs) ou da vista Linhas ('AAAA-MM-DD') → API. */
const dataDe = (d: unknown): string | null => (dayjs.isDayjs(d) ? dataApi(d) ?? null : typeof d === 'string' && d ? d : null);

/** Regras de gravação da tarefa, comuns à grade e à vista Linhas: nulos explícitos, meta por quantidade e dependência. */
function dadosTarefa(v: Record<string, unknown>, doFilhos = false): Record<string, unknown> {
  const metaQ = Number(v.meta_quantidade ?? 0);
  const dados: Record<string, unknown> = { ...v, data_inicio: dataDe(v.data_inicio), data_fim: dataDe(v.data_fim), codigo: v.codigo || null };
  for (const k of ['tarefa_pai_id', 'marco_projeto_id', 'atribuido_a_id', 'valor_contrato', 'percentagem_execucao', 'tarefa_predecessora_id', 'no_organigrama_projeto_id']) if (dados[k] === undefined) dados[k] = null;
  if (!metaQ) { dados.meta_quantidade = null; dados.meta_unidade = null; dados.meta_realizado = null; }
  if (doFilhos) delete dados.meta_realizado;
  if (metaQ > 0) delete dados.percentagem_execucao;
  if (!dados.tarefa_predecessora_id) { dados.tipo_dependencia = null; dados.desfasamento_dias = null; }
  return dados;
}

const ESTADOS_TAREFA = [{ value: 'PENDENTE', label: 'Pendente' }, { value: 'EM_CURSO', label: 'Em curso' }, { value: 'CONCLUIDA', label: 'Concluída' }, { value: 'BLOQUEADA', label: 'Bloqueada' }];
const fimAntesDoInicio = (fim: unknown, r: Record<string, unknown>) => (typeof fim === 'string' && fim && typeof r.data_inicio === 'string' && r.data_inicio && fim < r.data_inicio ? 'O fim é anterior ao início.' : null);
const unidadeDaMeta = (u: unknown, r: Record<string, unknown>) => (Number(r.meta_quantidade ?? 0) > 0 && !String(u ?? '').trim() ? 'Indique a unidade da meta.' : null);

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
            accao.mutate({ ...pedido, dados: { ...d, ...datasParaDataMinima(d.tipo_dependencia as string, min, d.data_inicio as string | null, d.data_fim as string | null) } });
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
  const marcos = useMemo(() => (w.data?.grupos ?? []).filter((g) => g.marco).map((g) => ({ value: g.marco!.id, label: g.marco!.nome })), [w.data]);
  const equipa = useEquipa(projectoId);
  // Vista Linhas (legado: formularios_linhas_config.js › projecto_tarefas)
  const camposLinhas = useMemo<CampoFormulario[]>(() => [
    { nome: 'codigo', rotulo: 'Código WBS', largura: 100, maxLength: 50 },
    { nome: 'nome', rotulo: 'Designação', obrigatorio: true, largura: 230, maxLength: 255, aliases: ['Nome', 'Tarefa'] },
    { nome: 'data_inicio', rotulo: 'Início', tipo: 'data', largura: 140 },
    { nome: 'data_fim', rotulo: 'Fim previsto', tipo: 'data', largura: 140, aliases: ['Fim'], validar: fimAntesDoInicio },
    { nome: 'percentagem_execucao', rotulo: 'Execução %', tipo: 'numero', min: 0, max: 100, precisao: 0, largura: 90 },
    { nome: 'estado', rotulo: 'Estado', tipo: 'select', opcoes: ESTADOS_TAREFA, omissao: 'PENDENTE', largura: 130,
      validar: (e, r) => (e === 'CONCLUIDA' && Number(r.meta_quantidade ?? 0) > 0 ? 'Concluída abaixo da meta: grave esta tarefa na grade para confirmar.' : null) },
    { nome: 'marco_projeto_id', rotulo: 'Fase / milestone', tipo: 'select', opcoes: marcos, largura: 160, aliases: ['Milestone', 'Fase'] },
    { nome: 'atribuido_a_id', rotulo: 'Responsável', tipo: 'select', largura: 170,
      opcoes: (equipa.data?.membros ?? []).map((m) => ({ value: m.id, label: `${m.nome}${m.papel ? ` (${m.papel})` : ''}` })) },
    { nome: 'valor_contrato', rotulo: 'Valor adjudicado (externo)', tipo: 'numero', min: 0, precisao: 2, largura: 140, aliases: ['Valor de contrato'] },
    { nome: 'tarefa_pai_id', rotulo: 'Tarefa-mãe', tipo: 'select', emLinhas: 'opcional', seccao: 'Hierarquia', largura: 200,
      opcoes: achatarWbs(w.data).map((t) => ({ value: t.id, label: `${t.codigo ? `${t.codigo} ` : ''}${t.nome}` })) },
    { nome: 'meta_quantidade', rotulo: 'Meta', tipo: 'numero', min: 0, emLinhas: 'opcional', seccao: 'Meta por quantidade', largura: 100 },
    { nome: 'meta_unidade', rotulo: 'Unidade da meta', emLinhas: 'opcional', seccao: 'Meta por quantidade', largura: 100, validar: unidadeDaMeta },
  ], [marcos, equipa.data, w.data]);
  const metaQ = Number(Form.useWatch('meta_quantidade', form) ?? 0);
  const metaF = Number(Form.useWatch('meta_realizado', form) ?? 0);
  const pred = Form.useWatch('tarefa_predecessora_id', form);
  const tipoDep = (Form.useWatch('tipo_dependencia', form) as string | undefined) ?? 'FS';
  const doFilhos = !!tarefa?.meta?.dos_filhos;
  const pct = pctDaMeta(metaF, metaQ);
  // #2: ritmo exigido pela meta e pelo prazo, no calendário do projecto (RegrasMetasProjetos::ritmo)
  const iniW = Form.useWatch('data_inicio', form) as dayjs.Dayjs | undefined;
  const fimW = Form.useWatch('data_fim', form) as dayjs.Dayjs | undefined;
  const unW = Form.useWatch('meta_unidade', form) as string | undefined;
  const ritmo = ritmoExigido(metaQ, unW, dataApi(iniW) ?? null, dataApi(fimW) ?? null, w.data?.calendario);

  const gravar = (v: Record<string, unknown>) => {
    const dados = dadosTarefa(v, doFilhos);
    const enviarPedido = () => accao.mutate(editar ? { metodo: 'put', url: `/projetos/${projectoId}/tarefas/${tarefa?.id}`, dados } : { url: `/projetos/${projectoId}/tarefas`, dados });
    if (dados.estado === 'CONCLUIDA' && metaQ > 0 && metaF < metaQ && !doFilhos) {
      Modal.confirm({ title: 'Concluir abaixo da meta?', content: `A tarefa está marcada como CONCLUÍDA mas só tem ${formatarQtd(metaF)} de ${formatarQtd(metaQ)} ${String(v.meta_unidade ?? '')}. Gravar mesmo assim?`, okText: 'Gravar', cancelText: 'Cancelar', onOk: enviarPedido });
      return;
    }
    enviarPedido();
  };

  return (
    <ModalFormulario chave="projectos.tarefas" titulo={editar ? `Editar tarefa «${tarefa?.nome}»` : tarefa?.tarefa_pai_id ? 'Nova subtarefa' : 'Nova tarefa'} aberto={!!tarefa} aoFechar={aoFechar}
      criacao={!editar} form={form} campos={camposLinhas} nomeRegistos="tarefas" invalidar={[['projectos']]}
      gravarLinha={(r) => enviar('post', `/projetos/${projectoId}/tarefas`, dadosTarefa(r))}
      nota="Uma linha por tarefa (ou subtarefa da tarefa escolhida). O valor adjudicado só conta para responsáveis externos. Linhas deixadas em branco são ignoradas. Cada linha é gravada com as mesmas regras do formulário."
      onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(820)}>
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
        <SeccaoFormulario titulo="Meta por quantidade">
        <Row gutter={12}>
          <Col xs={24} md={6}><Form.Item name="meta_unidade" label="Unidade" rules={[{ required: metaQ > 0, message: 'Indique a unidade da meta.' }]}><AutoComplete options={UNIDADES.map((u) => ({ value: u }))} placeholder="m², m, un…" /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="meta_quantidade" label="Meta"><InputNumber min={0} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="meta_realizado" label="Já realizado" tooltip={doFilhos ? 'Sobe das subtarefas com meta' : 'Para o dia a dia use «Registar execução» (fica no histórico)'}><InputNumber min={0} disabled={doFilhos} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
          <Col xs={24} md={6}>
            {metaQ > 0 ? <Form.Item label="% executada"><Typography.Text strong>{pct ?? 0} %</Typography.Text><Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>Calculada pela meta</Typography.Text></Form.Item>
              : <Form.Item name="percentagem_execucao" label="% executada"><InputNumber min={0} max={100} precision={0} style={{ width: '100%' }} /></Form.Item>}
          </Col>
        </Row>
        {metaQ > 0 && (
          <Alert type="info" showIcon style={{ marginBottom: 12 }} data-testid="ritmo-exigido"
            message={ritmo ? <>Ritmo exigido: <strong>{textoRitmo(ritmo)}</strong></> : 'Indique a data de início para ver o ritmo exigido pela meta.'} />
        )}
        </SeccaoFormulario>
        <SeccaoFormulario titulo="Dependência" recolhida={!editar && !tarefa?.tarefa_predecessora_id}>
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
        </SeccaoFormulario>
      </Form>
    </ModalFormulario>
  );
}

/** Fase / milestone: designação, data de início (Versão Actual), data alvo e meta (saveMilestone, ui_projects.js:2481-2560). */
/** Regras de gravação da fase, comuns à grade e à vista Linhas. */
function dadosMarco(v: Record<string, unknown>): Record<string, unknown> {
  const metaQ = Number(v.meta_quantidade ?? 0);
  return { nome: v.nome, data: dataDe(v.data), data_inicio: dataDe(v.data_inicio), meta_unidade: metaQ > 0 ? v.meta_unidade ?? null : null, meta_quantidade: metaQ > 0 ? metaQ : null };
}

/** Vista Linhas das fases (legado: formularios_linhas_config.js › projecto_fases — designação e data alvo). */
const CAMPOS_MARCO: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Designação', obrigatorio: true, largura: 260, maxLength: 255, aliases: ['Nome', 'Fase', 'Milestone'] },
  { nome: 'data_inicio', rotulo: 'Data de início', tipo: 'data', largura: 150, aliases: ['Início'] },
  { nome: 'data', rotulo: 'Data alvo', tipo: 'data', largura: 150,
    validar: (d, r) => (typeof d === 'string' && d && typeof r.data_inicio === 'string' && r.data_inicio && d < r.data_inicio ? 'A data alvo é anterior ao início.' : null) },
  { nome: 'meta_quantidade', rotulo: 'Meta da fase', tipo: 'numero', min: 0, emLinhas: 'opcional', seccao: 'Meta', largura: 120 },
  { nome: 'meta_unidade', rotulo: 'Unidade', emLinhas: 'opcional', seccao: 'Meta', largura: 100, validar: (u, r) => (Number(r.meta_quantidade ?? 0) > 0 && !String(u ?? '').trim() ? 'Indique a unidade.' : null) },
];

function ModalMarco({ projectoId, marco, aoFechar }: { projectoId: number; marco: Partial<Marco> | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ nome: string; data_inicio?: dayjs.Dayjs; data?: dayjs.Dayjs; meta_unidade?: string; meta_quantidade?: number }>();
  const accao = useAccao<{ avisos?: string[] }>({ invalidar: [['projectos']], aoSucesso: (d) => { aoFechar(); mostrarAvisos(d?.avisos); } });
  const metaQ = Number(Form.useWatch('meta_quantidade', form) ?? 0);
  useEffect(() => {
    if (marco) form.setFieldsValue({ nome: marco.nome ?? '', data: marco.data ? dayjs(marco.data) : undefined, data_inicio: marco.data_inicio ? dayjs(marco.data_inicio) : undefined,
      meta_unidade: marco.meta_unidade ?? undefined, meta_quantidade: marco.meta_quantidade ? Number(marco.meta_quantidade) : undefined });
  }, [marco, form]);
  return (
    <ModalFormulario chave="projectos.fases" titulo={marco?.id ? 'Editar milestone' : 'Novo milestone'} aberto={!!marco} aoFechar={aoFechar} criacao={!marco?.id} form={form} campos={CAMPOS_MARCO}
      nomeRegistos="fases / milestones" invalidar={[['projectos']]} gravarLinha={(r) => enviar('post', `/projetos/${projectoId}/marcos`, dadosMarco(r))}
      onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = dadosMarco(v);
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
    </ModalFormulario>
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

/**
 * Ligação de duas tarefas (precedência) a partir do Gantt — por arrasto entre barras ou pelo botão «Ligações», que é
 * a alternativa por teclado: escolhe-se a predecessora e a tarefa que depende dela, o tipo (FS/SS/FF) e o desfasamento.
 * Grava com PUT /projetos/{p}/tarefas/{t} (só os campos da dependência); o servidor recusa ciclos e, quando o início não
 * cumpre a precedência, devolve a data mínima e o ecrã oferece ajustar as datas. Lista também as ligações existentes,
 * cada uma com «Remover».
 */
function ModalLigacao({ projectoId, inicial, porChave, ligacoes, aoRemover, aoFechar }: {
  projectoId: number;
  inicial: { predecessora?: number; tarefa?: number; substitui?: boolean } | null;
  porChave: Map<string, TarefaWbs>;
  ligacoes: LigacaoGantt[];
  aoRemover: (g: LigacaoGantt) => void;
  aoFechar: () => void;
}) {
  const [form] = Form.useForm<{ predecessora: number; tarefa: number; tipo: string; desf: number }>();
  const w = useWbs(projectoId);
  const accao = useAccao<{ avisos?: string[] }>({
    invalidar: [['projectos']],
    tituloErro: 'Não foi possível ligar as tarefas',
    aoSucesso: (d) => { void message.success('Precedência gravada.'); aoFechar(); mostrarAvisos(d?.avisos); },
    aoErro: (e, pedido) => {
      if (!(e instanceof ErroApi)) return false;
      const d = pedido.dados as Record<string, unknown>;
      const t = porChave.get(`t-${String(pedido.url.split('/').pop())}`);
      if (e.codigo === 'DEPENDENCIA_DATA' && typeof e.erros?.data_minima === 'string' && t) {
        const min = e.erros.data_minima;
        Modal.confirm({ title: 'Precedência', content: `${e.message} Passar «${t.nome}» para começar a ${formatarData(min)}?`, okText: 'Ajustar as datas', cancelText: 'Corrigir à mão',
          onOk: () => accao.mutate({ ...pedido, dados: { ...d, ...datasParaDataMinima(d.tipo_dependencia as string, min, t.data_inicio, t.data_fim) } }) });
        return true;
      }
      if (e.codigo === 'DATAS_FORA_DO_NIVEL') {
        Modal.confirm({ title: 'Datas fora do nível acima', content: `${e.message} Encaixar as datas automaticamente?`, okText: 'Encaixar e gravar', cancelText: 'Cancelar',
          onOk: () => accao.mutate({ ...pedido, dados: { ...d, encaixar_datas: true } }) });
        return true;
      }
      return false;
    },
  });
  useEffect(() => {
    if (!inicial) return;
    form.resetFields();
    form.setFieldsValue({ predecessora: inicial.predecessora, tarefa: inicial.tarefa, tipo: 'FS', desf: 0 });
  }, [inicial, form]);
  const tarefa = Form.useWatch('tarefa', form);
  const pred = Form.useWatch('predecessora', form);
  const tipo = (Form.useWatch('tipo', form) as string | undefined) ?? 'FS';
  const desf = Number(Form.useWatch('desf', form) ?? 0);
  const actual = tarefa ? porChave.get(`t-${tarefa}`)?.precedencia?.predecessora : null;
  const opcoes = achatarWbs(w.data).map((t) => ({ value: t.id, label: `${'— '.repeat(t.nivel)}${t.codigo ? `${t.codigo} ` : ''}${t.nome}` }));
  const nome = (chave: string) => porChave.get(chave)?.nome ?? chave;
  return (
    <Modal title="Ligar tarefas (precedência)" open={!!inicial} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar a ligação" cancelText="Fechar"
      confirmLoading={accao.isPending} width={larguraModal(640)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => {
        if (v.predecessora === v.tarefa) { void message.warning('Uma tarefa não pode depender dela própria.'); return; }
        accao.mutate({ metodo: 'put', url: `/projetos/${projectoId}/tarefas/${v.tarefa}`, dados: { tarefa_predecessora_id: v.predecessora, tipo_dependencia: v.tipo, desfasamento_dias: v.desf ?? 0 } });
      }}>
        <Row gutter={12}>
          <Col xs={24} md={12}><Form.Item name="predecessora" label="Predecessora (vem primeiro)" rules={[{ required: true, message: 'Escolha a predecessora.' }]}><Select showSearch optionFilterProp="label" options={opcoes} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="tarefa" label="Tarefa que depende dela" rules={[{ required: true, message: 'Escolha a tarefa.' }]}><Select showSearch optionFilterProp="label" options={opcoes} /></Form.Item></Col>
          <Col xs={24} md={14}><Form.Item name="tipo" label="Tipo de ligação"><Select options={Object.entries(TIPOS_DEP).map(([k, t]) => ({ value: k, label: `${k} — ${t.nome}`, title: t.ajuda }))} /></Form.Item></Col>
          <Col xs={24} md={10}><Form.Item name="desf" label="Desfasamento (dias)"><InputNumber min={-3650} max={3650} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
      </Form>
      {pred && tarefa && <Alert type="info" showIcon style={{ marginBottom: 8 }} message={`${rotuloDependencia(tipo, desf)}: «${nome(`t-${tarefa}`)}» ${TIPOS_DEP[tipo]?.ajuda ?? ''} («${nome(`t-${pred}`)}»).`} />}
      {actual && actual.id !== pred && <Alert type="warning" showIcon style={{ marginBottom: 8 }} message={`Cada tarefa tem uma só predecessora: a ligação actual a «${actual.nome}» é substituída.`} />}
      <Divider orientation="left" plain style={{ margin: '8px 0' }}>Ligações existentes ({ligacoes.length})</Divider>
      <List size="small" locale={{ emptyText: 'Sem precedências.' }} dataSource={ligacoes} style={{ maxHeight: 220, overflowY: 'auto' }}
        renderItem={(g) => (
          <List.Item actions={[<Button key="r" size="small" danger icon={<DeleteOutlined />} aria-label={`Remover a ligação de «${nome(g.de)}» para «${nome(g.para)}»`} onClick={() => aoRemover(g)}>Remover</Button>]}>
            <Typography.Text>«{nome(g.de)}» → «{nome(g.para)}» <Typography.Text type="secondary">({rotuloDependencia(g.tipo, g.desf)})</Typography.Text></Typography.Text>
          </List.Item>
        )} />
    </Modal>
  );
}
