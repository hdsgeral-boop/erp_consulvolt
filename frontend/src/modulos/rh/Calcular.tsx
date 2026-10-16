import {
  Alert, Button, Card, Checkbox, Col, Descriptions, Dropdown, Empty, Form, Input, InputNumber, List, Modal, Popconfirm, Row, Select, Skeleton, Space, Switch, Tabs,
  Tag, Typography, message, theme,
} from 'antd';
import {
  ArrowLeftOutlined, CheckCircleOutlined, CopyOutlined, DeleteOutlined, DownOutlined, EditOutlined, FileExcelOutlined, FolderOpenOutlined, ImportOutlined, PlusOutlined,
  SaveOutlined, SearchOutlined,
} from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { descarregar, enviar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { TabelaComModos } from '@/componentes/vistas';
import { notificarErro } from '@/utilitarios/erros';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { numeroDe, opcoesColaboradores } from './comum/linhasRh';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, larguraModal } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { DetalhePeriodo, Lancamento, PeriodoSalarial, ResultadoSalarial } from './api';
import { EstadoTag, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores, useInfotipos, usePeriodosSalariais } from './comum/consultas';
import { ImportarExcel } from './comum/ImportarExcel';
import { ListaPeriodos } from './comum/ListaPeriodos';
import { ResumoTotais, TabelaResultados } from './comum/TabelaResultados';
import { BotaoFolhaDetalhada, ModalSimulacaoColaborador } from './comum/SimulacaoColaborador';
import { normalizarMesAno } from './calcular/mesAno';
import { accoesPeriodo } from './comum/regras';
import { folhaSalariosHtml, pedidoTabela } from './comum/impressao';

/**
 * RH › Calcular (ecrã calcular do legado «Lançamentos e Cálculos», js/app_v2.js:4943-5115): seleccionar ou abrir o período,
 * copiar de um mês anterior, importar dados do RH (contratos, efectividade, produtividade, Excel), lançamento em lote com
 * várias rubricas, resumo dos lançamentos com edição/eliminação dos seleccionados e encerramento do cálculo.
 */
export default function Calcular() {
  return (
    <Routes>
      <Route index element={<ListaCalculo />} />
      <Route path=":id" element={<PeriodoCalculo />} />
    </Routes>
  );
}

/** Passo 1 do legado (renderCalcular): «Digitar Mês/Ano» ou consultar existente, «Acessar Lançamentos», Template e Importar Excel. */
function ListaCalculo() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const periodos = usePeriodosSalariais();
  const [mesAno, setMesAno] = useState('');
  const [importar, setImportar] = useState<{ id: number; mes_ano: string } | null>(null);
  const [aAbrir, setAAbrir] = useState(false);
  const podeEditar = pode('calcular_folha');

  /** Período do mês escrito: o existente ou, se não houver e o perfil puder, abre-o (como o antigo). */
  const resolverPeriodo = async (): Promise<{ id: number; mes_ano: string } | null> => {
    const m = normalizarMesAno(mesAno);
    if (!m) {
      message.warning('Formato inválido. Use MM/AAAA (ex.: 01/2026).');
      return null;
    }
    const existente = (periodos.data ?? []).find((p) => p.mes_ano === m);
    if (existente) return existente;
    if (!podeEditar) {
      message.warning(`Não existe processamento para ${m}.`);
      return null;
    }
    setAAbrir(true);
    try {
      const { dados } = await enviar<PeriodoSalarial>('post', '/rh/salarios/periodos', { mes_ano: m });
      void periodos.refetch();
      return dados;
    } catch (e) {
      notificarErro(e, 'Não foi possível abrir o período');
      return null;
    } finally {
      setAAbrir(false);
    }
  };

  return (
    <ListaPeriodos titulo="Lançamentos e Cálculos" subtitulo="Lançamentos e cálculos do processamento salarial" permitirAbrir
      antes={
        <Card size="small" title="1. Selecionar Período ou Importar Dados" style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', gap: 16, alignItems: 'flex-end', flexWrap: 'wrap' }}>
            <div>
              <Typography.Text style={{ display: 'block', marginBottom: 4 }}>Digitar Mês/Ano (ex: 01/2026)</Typography.Text>
              <Input placeholder="MM/AAAA" style={{ width: 150 }} value={mesAno} maxLength={7} aria-label="Mês/Ano"
                onChange={(e) => setMesAno(e.target.value)} onPressEnter={() => void resolverPeriodo().then((p) => p && navegar(String(p.id)))} />
            </div>
            <div>
              <Typography.Text style={{ display: 'block', marginBottom: 4 }}>Ou consultar existente:</Typography.Text>
              <Select<string> placeholder="-- Selecione --" style={{ width: 220 }} allowClear aria-label="Período existente"
                value={(periodos.data ?? []).some((p) => p.mes_ano === mesAno) ? mesAno : undefined} onChange={(v) => setMesAno(v ?? '')}
                options={(periodos.data ?? []).map((p) => ({ value: p.mes_ano, label: `${p.mes_ano} (${p.estado})` }))} />
            </div>
            <Button type="primary" icon={<FolderOpenOutlined />} loading={aAbrir} onClick={() => void resolverPeriodo().then((p) => p && navegar(String(p.id)))}>Acessar Lançamentos</Button>
            {podeEditar && (
              <Space wrap style={{ marginLeft: 'auto' }}>
                <Button icon={<IconeFa nome="file-download" />} onClick={() => void descarregar('/rh/importacoes/modelos/calculo', undefined, 'Template_Calculo_RH.xlsx').catch(notificarErro)}>Template</Button>
                <Button icon={<IconeFa nome="file-import" />} loading={aAbrir} onClick={() => {
                  if (!normalizarMesAno(mesAno)) { message.info('Indique primeiro o Mês/Ano (MM/AAAA) para onde importar os lançamentos.'); return; }
                  void resolverPeriodo().then((p) => p && setImportar(p));
                }}>Importar Excel</Button>
              </Space>
            )}
          </div>
          <ImportarExcel aberto={importar !== null} titulo={`Importar lançamentos (Excel) — ${importar?.mes_ano ?? ''}`} url={`/rh/salarios/periodos/${importar?.id ?? 0}/importar-excel`} modelo="calculo"
            ajuda="Uma linha por colaborador e rubrica: NIF (ou nome completo), rubrica, valor, horas (rubricas por hora) e dias trabalhados. Os lançamentos existentes do mesmo colaborador e rubrica são substituídos."
            aoFechar={() => { const p = importar; setImportar(null); if (p) navegar(String(p.id)); }} />
        </Card>
      } />
  );
}

interface ValoresLancamento {
  colaborador_id: number;
  infotipo_salarial_id: number;
  valor?: number | null;
  dias_trabalhados?: number | null;
  horas?: number | null;
}

/** Corpo do POST /rh/salarios/periodos/{id}/lancamentos (grade e linhas): rubricas por hora levam as horas, as outras o valor. */
export function dadosLancamento(v: Record<string, unknown>, porHoras: boolean) {
  return {
    colaborador_id: v.colaborador_id,
    infotipo_salarial_id: v.infotipo_salarial_id,
    valor: numeroDe(v.valor),
    dias_trabalhados: numeroDe(v.dias_trabalhados),
    horas: porHoras ? numeroDe(v.horas) : null,
  };
}

/** Vista Linhas do «Novo lançamento» (um lançamento por colaborador e rubrica; gravar substitui o existente). */
export function camposLancamento(colaboradores: CampoFormulario['opcoes'], rubricas: CampoFormulario['opcoes'], porHoras: (rubrica: unknown) => boolean): CampoFormulario[] {
  return [
    { nome: 'colaborador_id', rotulo: 'Colaborador', tipo: 'select', obrigatorio: true, largura: 240, opcoes: colaboradores, aliases: ['NIF', 'Nome'] },
    { nome: 'infotipo_salarial_id', rotulo: 'Rubrica', tipo: 'select', obrigatorio: true, largura: 220, opcoes: rubricas, aliases: ['Infotipo'] },
    { nome: 'valor', rotulo: 'Valor (Kz)', tipo: 'numero', largura: 130, min: 0, precisao: 2, aliases: ['Valor'],
      validar: (v, r) => (!porHoras(r.infotipo_salarial_id) && (v === null || v === undefined || v === '') ? 'Indique o valor.' : null) },
    { nome: 'horas', rotulo: 'Horas (faltas / horas extra)', tipo: 'numero', largura: 110, min: 0, max: 744, precisao: 2, aliases: ['Horas'],
      validar: (h, r) => (porHoras(r.infotipo_salarial_id) && (h === null || h === undefined || h === '') ? 'Indique as horas (rubrica por hora).' : null) },
    { nome: 'dias_trabalhados', rotulo: 'Dias de trabalho', tipo: 'numero', largura: 110, min: 0, max: 31, aliases: ['Dias trabalhados', 'Dias'] },
  ];
}

interface LinhaLote {
  marcada: boolean;
  valor?: number | null;
  dias?: number | null;
  horas?: number | null;
}

interface AvisoEncerrar {
  colaborador_id: number;
  nome: string | null;
  avisos: string[];
}

function PeriodoCalculo() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const { token } = theme.useToken();
  const colaboradores = useColaboradores();
  const infotipos = useInfotipos();
  const periodos = usePeriodosSalariais();
  const periodo = useQuery({ queryKey: ['rh', 'salarios', 'periodo', id], queryFn: () => obter<DetalhePeriodo>(`/rh/salarios/periodos/${id}`) });
  const lancamentos = useQuery({ queryKey: ['rh', 'salarios', 'lancamentos', id], queryFn: () => obter<Lancamento[]>(`/rh/salarios/periodos/${id}/lancamentos`) });
  useAvisarErro(periodo.error, 'Erro ao carregar o período');
  const [filtroColab, setFiltroColab] = useState<number>();
  const [lancar, setLancar] = useState<'novo' | Lancamento | null>(null);
  const [efectividade, setEfectividade] = useState(false);
  const [produtividade, setProdutividade] = useState(false);
  const [importarExcel, setImportarExcel] = useState(false);
  const [resultadoImport, setResultadoImport] = useState<{ titulo: string; linhas: string[] } | null>(null);
  const [selecionados, setSelecionados] = useState<number[]>([]);
  const [editarLote, setEditarLote] = useState(false);
  const [avisos, setAvisos] = useState<AvisoEncerrar[] | null>(null);
  const [origemCopia, setOrigemCopia] = useState<number>();
  const [substituirCopia, setSubstituirCopia] = useState(false);
  // lançamento em lote (painel do legado: colaboradores à esquerda, rubricas à direita)
  const [buscaLote, setBuscaLote] = useState('');
  const [colabsLote, setColabsLote] = useState<number[]>([]);
  const [rubricasLote, setRubricasLote] = useState<Record<number, LinhaLote>>({});
  const [formL] = Form.useForm<ValoresLancamento>();
  const [formEf] = Form.useForm<{ infotipo_extra_id: number; infotipo_falta_id: number }>();
  const [formEd] = Form.useForm<{ usar_rubrica?: boolean; infotipo_salarial_id?: number; usar_dias?: boolean; dias_trabalhados?: number | null; usar_valor?: boolean; valor?: number | null }>();
  const [substituir, setSubstituir] = useState(true);
  const [simular, setSimular] = useState<ResultadoSalarial | null>(null);
  const rubricaL = Form.useWatch('infotipo_salarial_id', formL);

  const accao = useAccaoRh<Record<string, unknown>>((dados, pedido) => {
    setLancar(null);
    setEfectividade(false);
    setProdutividade(false);
    setEditarLote(false);
    setSelecionados([]);
    if (pedido.url.endsWith('importar-contratos') && dados) {
      // Fase B: avisos de contratos suspensos em parte do mês ou cessados a meio (ajustar os dias trabalhados)
      const ign = [...((dados.avisos as string[] | undefined) ?? []).map((x) => `Aviso — ${x}`), ...((dados.ignorados as string[] | undefined) ?? [])];
      setResultadoImport({ titulo: `Importação dos contratos: ${dados.criados ?? 0} lançamento(s) criado(s), ${dados.ja_existentes ?? 0} já existente(s).`, linhas: ign });
    }
    if (pedido.url.endsWith('/copiar') && dados) {
      setResultadoImport({ titulo: `Cópia: ${dados.copiados ?? 0} lançamento(s) copiado(s), ${dados.substituidos ?? 0} substituído(s), ${dados.ja_existentes ?? 0} já existente(s).`,
        linhas: (dados.ignorados as string[] | undefined) ?? [] });
    }
    if (pedido.url.endsWith('/lancamentos/lote') && pedido.metodo === 'post') {
      setColabsLote([]);
      setRubricasLote({});
    }
    if (pedido.metodo === 'delete' && pedido.url === `/rh/salarios/periodos/${id}`) navegar('..');
  });

  const activos = useMemo(() => colaboradores.lista.filter((c) => c.estado === 'ACTIVO').sort((a, b) => a.nome_completo.localeCompare(b.nome_completo, 'pt')), [colaboradores.lista]);
  const visiveisLote = activos.filter((c) => `${c.nome_completo} ${c.nif ?? ''} ${c.numero_inss ?? ''}`.toLowerCase().includes(buscaLote.toLowerCase()));

  if (periodo.isLoading) return <Skeleton active />;
  const p = periodo.data;
  if (!p) return <Alert type="error" message="Período não encontrado." />;
  const ac = accoesPeriodo(p, pode);
  const tipoHoras = (idRubrica?: number) => {
    const i = idRubrica ? infotipos.mapa.get(idRubrica) : undefined;
    return i?.calculo_horas === 'EXTRA' || i?.calculo_horas === 'FALTA' ? i.calculo_horas : null;
  };

  const camposLinhas = camposLancamento(
    opcoesColaboradores(colaboradores.lista),
    infotipos.lista.map((i) => ({ value: i.id, label: `${i.nome} (${i.tipo})` })),
    (r) => Boolean(tipoHoras(Number(r) || undefined)),
  );

  const abrirLancamento = (l: Lancamento | 'novo') => {
    formL.resetFields();
    if (l === 'novo') formL.setFieldsValue({ colaborador_id: filtroColab });
    else formL.setFieldsValue({ colaborador_id: l.colaborador_id, infotipo_salarial_id: l.infotipo_salarial_id, valor: Number(l.valor), dias_trabalhados: l.dias_trabalhados ? Number(l.dias_trabalhados) : null, horas: l.horas ? Number(l.horas) : null });
    setLancar(l);
  };

  /** Encerrar: com avisos no cálculo o servidor pede confirmação explícita (decisão 3). */
  const encerrar = async (confirmar = false) => {
    try {
      await accao.mutateAsync({ metodo: 'post', url: `/rh/salarios/periodos/${id}/encerrar`, dados: confirmar ? { confirmar_avisos: true } : {} });
      setAvisos(null);
    } catch (e) {
      if (e instanceof ErroApi && e.codigo === 'AVISOS_POR_CONFIRMAR') setAvisos(((e.erros?.avisos as AvisoEncerrar[] | undefined) ?? []));
    }
  };

  // Importações do RH (menu «Importar ▾» do cabeçalho e coluna «Dados do RH:» do antigo)
  const importarContratos = () => Modal.confirm({ title: 'Importar lançamentos dos contratos?', content: 'Cria os lançamentos em falta a partir do contrato activo no mês (colaboradores activos, em Kz). Os existentes mantêm-se.', okText: 'Importar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/salarios/periodos/${id}/importar-contratos` }) });
  const abrirEfectividade = () => { formEf.resetFields(); setEfectividade(true); };
  const abrirProdutividade = () => { setSubstituir(true); setProdutividade(true); };
  const importarEmprestimos = () => Modal.confirm({ title: `Importar as prestações dos empréstimos de ${p.mes_ano}?`, content: 'Cria um desconto por colaborador na rubrica configurada (RH › Empréstimos), com o valor da prestação do mês. As prestações passam a descontadas; o empréstimo fica liquidado quando já não houver prestações. Um desconto já existente na mesma rubrica não é alterado.', okText: 'Importar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/salarios/periodos/${id}/importar-emprestimos`, dados: { substituir: false } }) });
  const anularEmprestimos = () => Modal.confirm({ title: 'Anular os descontos de empréstimos deste período?', content: 'Retira os lançamentos criados por «Importar empréstimos» e repõe as prestações por descontar.', okText: 'Anular', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/salarios/periodos/${id}/anular-emprestimos` }) });
  const encerrarComConfirmacao = () => Modal.confirm({
    title: `Encerrar o cálculo de ${p.mes_ano}?`,
    content: 'Os resultados ficam fotografados (imutáveis) e o período passa a Fechado, para validação no ecrã Processamentos.',
    okText: 'Encerrar', cancelText: 'Cancelar',
    onOk: () => encerrar(),
  });

  const gravarLote = () => {
    const rubricas = Object.entries(rubricasLote).filter(([, l]) => l.marcada).map(([k, l]) => {
      const horas = tipoHoras(Number(k));
      return horas ? { infotipo_salarial_id: Number(k), horas: l.horas ?? null } : { infotipo_salarial_id: Number(k), valor: l.valor ?? 0, dias_trabalhados: l.dias ?? null };
    });
    if (!colabsLote.length || !rubricas.length) {
      message.warning('Seleccione os colaboradores e pelo menos uma rubrica com valor ou horas.');
      return;
    }
    accao.mutate({ metodo: 'post', url: `/rh/salarios/periodos/${id}/lancamentos/lote`, dados: { colaboradores: colabsLote, rubricas } });
  };

  const linhas = (lancamentos.data ?? [])
    .filter((l) => !filtroColab || l.colaborador_id === filtroColab)
    .sort((a, b) => colaboradores.nome(a.colaborador_id).localeCompare(colaboradores.nome(b.colaborador_id), 'pt') || a.id - b.id);

  // Colunas do «Resumo de Lançamentos do Período» do antigo (refreshEntriesTable): COLABORADOR, DIAS CONT., DIAS TRAB.,
  // RUBRICA, VALOR (KZ), AÇÕES; as do novo (tipo, horas, origem) ficam disponíveis em «Colunas ▾».
  const colunas: ColunaApi<Lancamento>[] = [
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => colaboradores.nome(v), valorFiltro: (l) => colaboradores.nome(l.colaborador_id) },
    { title: 'Dias Cont.', key: 'dias_cont', align: 'center', render: (_, l) => colaboradores.mapa.get(l.colaborador_id)?.dias_uteis_mes ?? '-' },
    { title: 'Dias Trab.', dataIndex: 'dias_trabalhados', align: 'center', render: (v: string | null) => (v !== null && v !== undefined && v !== '' ? <strong style={{ color: token.colorPrimary }}>{formatarNumero(v)}</strong> : '-') },
    {
      title: 'Rubrica', dataIndex: 'infotipo_salarial_id', valorFiltro: (l) => infotipos.nome(l.infotipo_salarial_id),
      render: (v: number) => { const t = infotipos.mapa.get(v)?.tipo; return <span>{t && <span style={{ color: t === 'VENCIMENTO' ? token.colorSuccess : token.colorError }}>[{t.slice(0, 1)}] </span>}{infotipos.nome(v)}</span>; },
    },
    {
      title: 'Valor (Kz)', dataIndex: 'valor', align: 'right',
      render: (v: string, l) => (l.horas && Number(l.horas) ? <span><strong>{formatarNumero(l.horas)} h</strong><br /><Typography.Text type="secondary" style={{ fontSize: 12 }}>valor apurado no cálculo</Typography.Text></span> : formatarKz(v)),
    },
    { title: 'Tipo', key: 'tipo', ocultaPorOmissao: true, render: (_, l) => { const t = infotipos.mapa.get(l.infotipo_salarial_id)?.tipo; return t ? <Tag color={t === 'VENCIMENTO' ? 'green' : t === 'DESCONTO' ? 'red' : 'default'}>{t}</Tag> : '—'; } },
    { title: 'Horas', dataIndex: 'horas', align: 'right', ocultaPorOmissao: true, render: (v: string | null) => (v ? formatarNumero(v) : '—') },
    { title: 'Origem', dataIndex: 'origem', ocultaPorOmissao: true, render: (o: string | null, l) => (l.bonificacao_avaliacao_id ? <Tag color="purple">Bonificação</Tag> : o ? <Tag>{o}</Tag> : 'Manual') },
  ];
  const resultadoDe = (colaboradorId: number) => p.resultados.find((r) => r.colaborador_id === colaboradorId) ?? null;

  const rubricasHoras = (tipo: 'EXTRA' | 'FALTA') => infotipos.lista.filter((i) => i.calculo_horas === tipo).map((i) => ({ value: i.id, label: i.nome }));
  const outrosPeriodos = (periodos.data ?? []).filter((x) => x.id !== p.id);
  const ordemRubricas = [...infotipos.lista].sort((a, b) => (a.tipo === b.tipo ? a.nome.localeCompare(b.nome, 'pt') : a.tipo === 'VENCIMENTO' ? -1 : 1));
  const editarSel = ac.lancar && pode('calcular_bulk');

  return (
    <>
      <CabecalhoPagina
        titulo={`Processamento ${p.mes_ano}`}
        subtitulo={<Space wrap><EstadoTag estado={p.estado} />{p.contabilizado && <Tag color="green">Contabilizado</Tag>}{p.fotografia ? 'Resultados fotografados' : 'Cálculo ao vivo'}</Space>}
        impressaoDesactivada={!p.resultados.length}
        impressao={() => ({
          titulo: 'Folha de salários',
          periodo: p.mes_ano,
          filtros: [p.fotografia ? 'Resultados fotografados' : 'Simulação: cálculo ao vivo (valores provisórios)'],
          conteudo: folhaSalariosHtml(p.resultados, (r) => r.nome ?? colaboradores.nome(r.colaborador_id)),
        })}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {ac.importar && (
              <Dropdown menu={{
                items: [
                  { key: 'contratos', label: 'Importar dos contratos' },
                  { key: 'efectividade', label: 'Importar efectividade (horas extra e faltas)' },
                  { key: 'produtividade', label: 'Importar produtividade' },
                  // Fase B (Versão Actual, app_v2.js:5406): prestações do mês dos empréstimos aprovados
                  ...(pode('rh_emp_lancar') ? [{ key: 'emprestimos', label: 'Importar empréstimos (prestações do mês)' }, { key: 'anular-emprestimos', label: 'Anular empréstimos importados', danger: true }] : []),
                  ...(ac.lancar ? [{ type: 'divider' as const }, { key: 'excel', label: 'Importar Excel (lançamentos)', icon: <FileExcelOutlined /> }] : []),
                ],
                onClick: ({ key }) => {
                  if (key === 'contratos') importarContratos();
                  if (key === 'efectividade') abrirEfectividade();
                  if (key === 'produtividade') abrirProdutividade();
                  if (key === 'emprestimos') importarEmprestimos();
                  if (key === 'anular-emprestimos') anularEmprestimos();
                  if (key === 'excel') setImportarExcel(true);
                },
              }}>
                <Button icon={<ImportOutlined />}>Importar <DownOutlined /></Button>
              </Dropdown>
            )}
            {ac.lancar && <Button icon={<PlusOutlined />} onClick={() => abrirLancamento('novo')}>Lançamento</Button>}
          </>
        }
      />
      {/* antigo (renderPeriodWorkspace, app_v2.js:5398): título do período e «Eliminar Lançamentos em Aberto» à direita */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8, marginBottom: 16 }}>
        <Typography.Title level={3} style={{ margin: 0, fontSize: '1.1rem', color: token.colorTextSecondary }}>Lançamentos de {p.mes_ano}</Typography.Title>
        {ac.importar && pode('rh_lanc_del') && (
          <Popconfirm title="Eliminar este período em aberto e todos os seus lançamentos?" description="Esta acção não se desfaz." okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar"
            onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/salarios/periodos/${id}` })}>
            <Button danger icon={<DeleteOutlined />}>Eliminar Lançamentos em Aberto</Button>
          </Popconfirm>
        )}
      </div>
      {p.estado !== 'ABERTO' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message="Este período já foi encerrado. Vá ao ecrã Processamentos para ver os relatórios e os recibos (para corrigir, reabra-o lá)." />}

      {ac.importar && (
        <Card size="small" style={{ marginBottom: 16, background: token.colorPrimaryBg, borderStyle: 'dashed', borderColor: token.colorPrimary }}>
          <Row gutter={[16, 12]}>
            <Col xs={24} lg={14} style={{ alignSelf: 'flex-end' }}>
              <Typography.Text strong style={{ color: token.colorPrimary, display: 'block', marginBottom: 6 }}>Copiar de Mês Anterior:</Typography.Text>
              <Space wrap>
                <Select<number> placeholder="-- Selecione o mês anterior --" style={{ width: 250 }} value={origemCopia} onChange={setOrigemCopia} allowClear aria-label="Mês a copiar"
                  options={outrosPeriodos.map((x) => ({ value: x.id, label: `${x.mes_ano} (${x.estado})` }))} />
                <Checkbox checked={substituirCopia} onChange={(e) => setSubstituirCopia(e.target.checked)}>Substituir os existentes</Checkbox>
                <Button icon={<CopyOutlined />} disabled={!origemCopia} onClick={() => Modal.confirm({
                  title: 'Copiar os lançamentos do mês seleccionado?',
                  content: 'Copia todos os lançamentos (salários, subsídios, descontos, horas) para este mês. Não se duplica colaborador × rubrica; as bonificações e os colaboradores inactivos ficam de fora.',
                  okText: 'Copiar', cancelText: 'Cancelar',
                  onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/salarios/periodos/${id}/copiar`, dados: { origem_id: origemCopia, substituir: substituirCopia } }),
                })}>Importar Mês Anterior</Button>
              </Space>
            </Col>
            {/* coluna «Dados do RH:» do antigo (app_v2.js:5384): botões empilhados com a explicação de cada importação */}
            <Col xs={24} lg={10} style={{ borderLeft: `1px solid ${token.colorPrimary}`, display: 'flex', flexDirection: 'column', gap: 6 }}>
              <Typography.Text strong style={{ color: token.colorPrimary }}>Dados do RH:</Typography.Text>
              <Button type="primary" block icon={<IconeFa nome="file-contract" />} onClick={importarContratos}>Importar Lançamentos de Contratos</Button>
              <Typography.Text type="secondary" style={{ fontSize: '0.75rem' }}>Gera lançamentos automáticos com base nos contratos activos.</Typography.Text>
              <Button block icon={<IconeFa nome="fingerprint" />} onClick={abrirEfectividade}>Importar Efectividade</Button>
              <Typography.Text type="secondary" style={{ fontSize: '0.75rem' }}>Horas extra e horas de falta apuradas na biometria (RH ▸ Efectividade), já sem férias e ausências justificadas.</Typography.Text>
              <Button block icon={<IconeFa nome="chart-line" />} onClick={abrirProdutividade}>Importar Produtividade</Button>
              {pode('rh_emp_lancar') && <Button block icon={<IconeFa nome="hand-holding-usd" />} onClick={importarEmprestimos}>Importar Empréstimos</Button>}
              <Typography.Text type="secondary" style={{ fontSize: '0.75rem' }}>Subsídio de produtividade do período fechado (RH ▸ Produtividade): quantidade × preço de cada item do contrato.</Typography.Text>
            </Col>
          </Row>
        </Card>
      )}

      {editarSel && (
        <Card size="small" title={<>2. Lançamento em Lote para: <strong>{p.mes_ano}</strong></>} style={{ marginBottom: 16, borderLeft: `4px solid ${token.colorPrimary}` }}>
          <Row gutter={[24, 16]}>
            <Col xs={24} md={12}>
              <Typography.Text strong style={{ display: 'block', marginBottom: 8 }}>
                Selecione os Colaboradores: <Typography.Text type="secondary">{colabsLote.length} de {activos.length}</Typography.Text>
              </Typography.Text>
              <Input allowClear prefix={<SearchOutlined />} placeholder="Procurar por nome, NIF ou n.º INSS…" value={buscaLote} onChange={(e) => setBuscaLote(e.target.value)} style={{ marginBottom: 8 }} aria-label="Procurar colaborador" />
              <div style={{ maxHeight: 280, overflowY: 'auto', border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadius, padding: 8 }}>
                {visiveisLote.length ? (
                  <Checkbox.Group value={colabsLote} onChange={(v) => setColabsLote(v as number[])} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    {visiveisLote.map((c) => (
                      <Checkbox key={c.id} value={c.id}>{c.nome_completo}{c.avencado && <Tag color="gold" style={{ marginLeft: 6 }}>Avençado</Tag>}</Checkbox>
                    ))}
                  </Checkbox.Group>
                ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nenhum colaborador corresponde à pesquisa." />}
              </div>
              <Space style={{ marginTop: 8 }}>
                <Button size="small" onClick={() => setColabsLote([...new Set([...colabsLote, ...visiveisLote.map((c) => c.id)])])}>Selecionar Visíveis</Button>
                <Button size="small" onClick={() => setColabsLote([])}>Limpar</Button>
              </Space>
            </Col>
            <Col xs={24} md={12}>
              <Typography.Text strong style={{ display: 'block', marginBottom: 8 }}>Selecione as Rubricas (Infotipos):</Typography.Text>
              <div style={{ maxHeight: 320, overflowY: 'auto', border: `1px solid ${token.colorBorderSecondary}`, borderRadius: token.borderRadius, padding: 8 }}>
                <List size="small" dataSource={ordemRubricas} renderItem={(i) => {
                  const l = rubricasLote[i.id] ?? { marcada: false };
                  const horas = tipoHoras(i.id);
                  const mudar = (x: Partial<LinhaLote>) => setRubricasLote({ ...rubricasLote, [i.id]: { ...l, ...x, marcada: x.marcada ?? true } });
                  return (
                    <List.Item style={{ paddingInline: 0 }}>
                      <Space wrap style={{ width: '100%', justifyContent: 'space-between' }}>
                        <Checkbox checked={l.marcada} onChange={(e) => mudar({ marcada: e.target.checked })}>
                          <Typography.Text strong style={{ color: i.tipo === 'VENCIMENTO' ? token.colorSuccess : token.colorError }}>[{i.tipo.slice(0, 3)}]</Typography.Text> {i.nome}
                          {horas && <Tag color="blue" style={{ marginLeft: 6 }}>por hora</Tag>}
                        </Checkbox>
                        {horas ? (
                          <InputNumber size="small" min={0} max={744} step={0.25} placeholder="Horas" value={l.horas ?? null} onChange={(v) => mudar({ horas: v })} style={{ width: 110 }} aria-label={`Horas de ${i.nome}`} />
                        ) : (
                          <Space size={4}>
                            {i.tipo === 'VENCIMENTO' && <InputNumber size="small" min={0} max={31} placeholder="Dias trab." value={l.dias ?? null} onChange={(v) => mudar({ dias: v })} style={{ width: 90 }} aria-label={`Dias de ${i.nome}`} />}
                            <InputNumber size="small" min={0} precision={2} decimalSeparator="," placeholder="Valor (Kz)" value={l.valor ?? null} onChange={(v) => mudar({ valor: v })} style={{ width: 130 }} aria-label={`Valor de ${i.nome}`} />
                          </Space>
                        )}
                      </Space>
                    </List.Item>
                  );
                }} />
              </div>
            </Col>
          </Row>
          <Button type="primary" icon={<SaveOutlined />} style={{ marginTop: 16 }} loading={accao.isPending} onClick={gravarLote}>Gravar Lançamentos Selecionados</Button>
        </Card>
      )}

      <ResumoTotais periodo={p} />
      <Card title="Resumo de Lançamentos do Período"
        extra={
          // ordem do antigo (app_v2.js:5466): Imprimir Simulação · Editar Selecionados · Eliminar Selecionados · Encerrar Cálculo
          <Space wrap>
            <BotaoFolhaDetalhada periodo={p} nome={(r) => r.nome ?? colaboradores.nome(r.colaborador_id)} texto="Imprimir Simulação" />
            {editarSel && <Button icon={<EditOutlined />} disabled={!selecionados.length} onClick={() => { formEd.resetFields(); setEditarLote(true); }}>Editar Selecionados</Button>}
            {ac.removerLancamento && (
              <Popconfirm title={`Eliminar os ${selecionados.length} lançamento(s) seleccionado(s)?`} okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar" disabled={!selecionados.length}
                onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/salarios/periodos/${id}/lancamentos/lote`, dados: { ids: selecionados } })}>
                <Button danger icon={<DeleteOutlined />} disabled={!selecionados.length}>Eliminar Selecionados</Button>
              </Popconfirm>
            )}
            {ac.encerrar && <Button type="primary" danger icon={<CheckCircleOutlined />} loading={accao.isPending} onClick={encerrarComConfirmacao}>Encerrar Cálculo</Button>}
          </Space>
        }>
        <Tabs
          items={[
            {
              key: 'lancamentos',
              label: `Lançamentos (${lancamentos.data?.length ?? 0})`,
              children: (
                <>
                  <BarraFiltros style={{ marginBottom: 12 }} accoes={
                    <Space wrap>
                      <BotoesExportar tamanho="small" desactivado={!linhas.length} textoImprimir="Imprimir lançamentos"
                        obterPedido={() => pedidoTabela({ titulo: 'Lançamentos do período salarial', periodo: p.mes_ano, filtros: filtroColab ? [`Colaborador: ${colaboradores.nome(filtroColab)}`] : undefined, colunas, linhas })} />
                    </Space>
                  }><SeletorColaborador value={filtroColab} onChange={setFiltroColab} /></BarraFiltros>
                  <TabelaComModos<Lancamento> rowKey="id" size="small" loading={lancamentos.isFetching} columns={colunas} dataSource={linhas} scroll={{ x: 'max-content' }} modos={false}
                    rowSelection={ac.lancar || ac.removerLancamento ? { selectedRowKeys: selecionados, onChange: (k) => setSelecionados(k as number[]), getCheckboxProps: (l) => ({ disabled: Boolean(l.bonificacao_avaliacao_id) }) } : undefined}
                    vazio={{ icone: 'calculator', titulo: 'Nenhum lançamento', texto: 'Nenhum lançamento corresponde aos filtros ou o período está vazio.' }}
                    accoes={(l) => [
                      { icone: 'calculator', titulo: 'Simular resultado salarial deste colaborador', texto: 'Simular', cor: '#6366f1',
                        desactivado: resultadoDe(l.colaborador_id) ? false : 'Sem resultado calculado para este colaborador',
                        onClick: () => setSimular(resultadoDe(l.colaborador_id)) },
                      ac.lancar && { icone: 'edit', titulo: 'Editar', onClick: () => abrirLancamento(l) },
                      ac.removerLancamento && !l.bonificacao_avaliacao_id && { icone: 'trash', titulo: 'Remover', variante: 'perigo', cheio: true, confirmar: 'Remover o lançamento?',
                        onClick: () => accao.mutateAsync({ metodo: 'delete', url: `/rh/salarios/periodos/${id}/lancamentos/${l.id}` }) },
                      !ac.lancar && !ac.removerLancamento && { icone: 'lock', titulo: 'Período encerrado', desactivado: 'Período encerrado' },
                    ]} />
                </>
              ),
            },
            {
              key: 'resultados',
              label: `Resultados (${p.resultados.length})`,
              children: (
                <>
                  {p.resultados.length > 0 && (
                    <div style={{ marginBottom: 12, display: 'flex', justifyContent: 'flex-end' }}>
                      <BotaoFolhaDetalhada periodo={p} nome={(r) => r.nome ?? colaboradores.nome(r.colaborador_id)} texto="Simulação da folha" />
                    </div>
                  )}
                  <TabelaResultados periodo={p} carregando={periodo.isFetching} />
                </>
              ),
            },
          ]}
        />
      </Card>

      <ModalSimulacaoColaborador resultado={simular} mesAno={p.mes_ano} nome={simular ? simular.nome ?? colaboradores.nome(simular.colaborador_id) : ''}
        simulacao={p.estado !== 'VALIDADO'} aoFechar={() => setSimular(null)} />

      <ModalFormulario chave="rh.calcular.lancamentos" titulo={lancar === 'novo' ? 'Novo lançamento' : 'Editar lançamento'} aberto={lancar !== null} aoFechar={() => setLancar(null)}
        criacao={lancar === 'novo'} form={formL} campos={camposLinhas} nomeRegistos="lançamentos" invalidar={[['rh', 'salarios']]}
        nota="Uma linha por colaborador e rubrica (gravar substitui o lançamento existente). Nas rubricas por hora indique as horas; nas outras, o valor. Linhas deixadas em branco são ignoradas."
        gravarLinha={(r) => enviar('post', `/rh/salarios/periodos/${id}/lancamentos`, dadosLancamento(r, Boolean(tipoHoras(Number(r.infotipo_salarial_id) || undefined))))}
        okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formL.submit()}>
        <Typography.Paragraph type="secondary">Há um lançamento por colaborador e rubrica: gravar substitui o existente.</Typography.Paragraph>
        <Form form={formL} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/salarios/periodos/${id}/lancamentos`,
          dados: dadosLancamento(v as unknown as Record<string, unknown>, Boolean(tipoHoras(v.infotipo_salarial_id))) })}>
          <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}>
            <SeletorColaborador style={{ width: '100%' }} disabled={lancar !== 'novo'} />
          </Form.Item>
          <Form.Item name="infotipo_salarial_id" label="Rubrica" rules={[{ required: true, message: 'Escolha a rubrica.' }]}>
            <Select showSearch optionFilterProp="label" disabled={lancar !== 'novo'} options={infotipos.lista.map((i) => ({ value: i.id, label: `${i.nome} (${i.tipo})` }))} />
          </Form.Item>
          {tipoHoras(rubricaL) ? (
            <Form.Item name="horas" label="Horas (faltas / horas extra)" rules={[{ required: true, message: 'Indique as horas.' }]} extra="Valorizadas pelo valor hora do contrato no cálculo.">
              <InputNumber min={0} max={744} step={0.25} style={{ width: '100%' }} />
            </Form.Item>
          ) : (
            <Form.Item name="valor" label="Valor (Kz)" rules={[{ required: true, message: 'Indique o valor.' }]}>
              <InputNumber min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} />
            </Form.Item>
          )}
          <Form.Item name="dias_trabalhados" label="Dias de trabalho" extra="Pro rata face aos dias do contrato (acima dos dias do contrato gera horas extra automáticas, com aviso).">
            <InputNumber min={0} max={31} step={0.5} style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </ModalFormulario>

      <Modal title={`Edição em massa (${selecionados.length} lançamento(s))`} open={editarLote} width={larguraModal(480)} onCancel={() => setEditarLote(false)} okText="Aplicar em massa" cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => formEd.submit()} destroyOnHidden>
        <Typography.Paragraph type="secondary">Active os campos a actualizar em todos os lançamentos seleccionados:</Typography.Paragraph>
        <Form form={formEd} layout="vertical" onFinish={(v) => {
          const campos: Record<string, unknown> = {};
          if (v.usar_rubrica) campos.infotipo_salarial_id = v.infotipo_salarial_id;
          if (v.usar_dias) campos.dias_trabalhados = v.dias_trabalhados ?? null;
          if (v.usar_valor) campos.valor = v.valor ?? 0;
          if (!Object.keys(campos).length) { message.warning('Nenhum campo foi seleccionado para alteração.'); return; }
          accao.mutate({ metodo: 'put', url: `/rh/salarios/periodos/${id}/lancamentos/lote`, dados: { ids: selecionados, campos } });
        }}>
          <Form.Item name="usar_rubrica" valuePropName="checked" style={{ marginBottom: 4 }}><Checkbox>Alterar rubrica</Checkbox></Form.Item>
          <Form.Item noStyle shouldUpdate>{() => (
            <Form.Item name="infotipo_salarial_id" rules={[{ required: Boolean(formEd.getFieldValue('usar_rubrica')), message: 'Escolha a rubrica.' }]}>
              <Select disabled={!formEd.getFieldValue('usar_rubrica')} showSearch optionFilterProp="label" options={infotipos.lista.map((i) => ({ value: i.id, label: i.nome }))} />
            </Form.Item>
          )}</Form.Item>
          <Form.Item name="usar_dias" valuePropName="checked" style={{ marginBottom: 4 }}><Checkbox>Alterar dias de trabalho</Checkbox></Form.Item>
          <Form.Item noStyle shouldUpdate>{() => <Form.Item name="dias_trabalhados"><InputNumber disabled={!formEd.getFieldValue('usar_dias')} min={0} max={31} placeholder="Ex.: 22" style={{ width: '100%' }} /></Form.Item>}</Form.Item>
          <Form.Item name="usar_valor" valuePropName="checked" style={{ marginBottom: 4 }}><Checkbox>Alterar valor (Kz)</Checkbox></Form.Item>
          <Form.Item noStyle shouldUpdate>{() => <Form.Item name="valor"><InputNumber disabled={!formEd.getFieldValue('usar_valor')} min={0} precision={2} decimalSeparator="," placeholder="Ex.: 50 000" style={{ width: '100%' }} /></Form.Item>}</Form.Item>
        </Form>
      </Modal>

      <Modal title="O cálculo tem avisos" open={avisos !== null} width={larguraModal(720)} onCancel={() => setAvisos(null)} destroyOnHidden
        footer={<Space wrap><Button onClick={() => setAvisos(null)}>Rever os lançamentos</Button><Button type="primary" danger loading={accao.isPending} onClick={() => void encerrar(true)}>Encerrar mesmo assim</Button></Space>}>
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Confirme antes de encerrar"
          description="Há horas extra automáticas, faltas ou horas extra que não puderam ser valorizadas (por exemplo, sem contrato válido no mês). Depois de encerrado, o cálculo fica fotografado." />
        <List size="small" bordered dataSource={avisos ?? []} renderItem={(a) => (
          <List.Item><div><Typography.Text strong>{a.nome ?? colaboradores.nome(a.colaborador_id)}</Typography.Text><ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>{a.avisos.map((x, i) => <li key={i}>{x}</li>)}</ul></div></List.Item>
        )} />
      </Modal>

      <Modal title="Importar efectividade" open={efectividade} onCancel={() => setEfectividade(false)} okText="Importar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formEf.submit()} destroyOnHidden>
        <Typography.Paragraph type="secondary">Horas extra e horas de falta apuradas na biometria (RH › Efectividade), já sem férias e ausências justificadas. Os lançamentos que deixaram de ter horas são retirados.</Typography.Paragraph>
        <Form form={formEf} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/salarios/periodos/${id}/importar-efectividade`, dados: v })}>
          <Form.Item name="infotipo_extra_id" label="Rubrica das horas extra" rules={[{ required: true, message: 'Escolha a rubrica.' }]}><Select options={rubricasHoras('EXTRA')} /></Form.Item>
          <Form.Item name="infotipo_falta_id" label="Rubrica das faltas" rules={[{ required: true, message: 'Escolha a rubrica.' }]}><Select options={rubricasHoras('FALTA')} /></Form.Item>
        </Form>
      </Modal>

      <Modal title="Importar produtividade" open={produtividade} onCancel={() => setProdutividade(false)} okText="Importar" cancelText="Cancelar" confirmLoading={accao.isPending}
        onOk={() => accao.mutate({ metodo: 'post', url: `/rh/salarios/periodos/${id}/importar-produtividade`, dados: { substituir } })}>
        <Typography.Paragraph type="secondary">Subsídio de produtividade do período fechado (RH › Produtividade): quantidade × preço de cada item do contrato.</Typography.Paragraph>
        <Space><Switch checked={substituir} onChange={setSubstituir} />Substituir os lançamentos de produtividade existentes</Space>
      </Modal>

      <ImportarExcel aberto={importarExcel} titulo={`Importar lançamentos (Excel) — ${p.mes_ano}`} url={`/rh/salarios/periodos/${id}/importar-excel`} modelo="calculo"
        ajuda="Uma linha por colaborador e rubrica: NIF (ou nome completo), rubrica, valor, horas (rubricas por hora) e dias trabalhados. Os lançamentos existentes do mesmo colaborador e rubrica são substituídos."
        aoFechar={() => setImportarExcel(false)} />

      <Modal title="Resultado" open={resultadoImport !== null} onCancel={() => setResultadoImport(null)} footer={<Button type="primary" onClick={() => setResultadoImport(null)}>Fechar</Button>}>
        <Typography.Paragraph strong>{resultadoImport?.titulo}</Typography.Paragraph>
        {resultadoImport && resultadoImport.linhas.length > 0 && (
          <Descriptions size="small" column={1} bordered>
            {resultadoImport.linhas.slice(0, 50).map((l, i) => <Descriptions.Item key={i} label={i + 1}>{l}</Descriptions.Item>)}
          </Descriptions>
        )}
      </Modal>
    </>
  );
}
