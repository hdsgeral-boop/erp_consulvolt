import { Alert, Button, Card, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Row, Select, Space, Tooltip, Typography, message } from 'antd';
import { AppstoreAddOutlined, CalculatorOutlined, ImportOutlined, MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useMemo, useState, type Key } from 'react';
import { descarregar, enviar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { useImpressao } from '@/componentes/impressao';
import { contratoAssinaturaHtml } from './contratos/contratoAssinatura';
import { SubsidiosMinuta } from './contratos/SubsidiosMinuta';
import { notificarErro } from '@/utilitarios/erros';
import { ContratosMassa } from './comum/ContratosMassa';
import { SimularMassa } from './comum/SimularMassa';
import { ImportarExcel } from './comum/ImportarExcel';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { ModalFormulario, SeccaoFormulario, type CampoFormulario } from '@/componentes/formularios';
import { dataDe, numeroDe, opcoesColaboradores } from './comum/linhasRh';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ESTADOS_COLABORADOR, ESTADOS_CONTRATO, type ConfiguracaoRH, type ContratoTrabalho, type ItemProdutividade } from './api';
import { accoesEstadoContrato, ModaisEstadoContrato, type AccaoEstado } from './contratos/EstadoContrato';
import { CamposHorario, horarioParaApi, horarioParaForm, type ValoresHorario } from './contratos/HorarioContrato';
import { contem, EstadoTag, PesquisaLocal, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useCargos, useColaboradores, useInfotipos } from './comum/consultas';
import { contratoVigente, normalizarRemuneracoes, semFim, somar, totalContrato } from './comum/regras';
import { BarraFiltros, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { pedidoTabela } from './comum/impressao';

import { TabelaComModos } from '@/componentes/vistas';
interface ValoresContrato extends ValoresHorario {
  colaborador_id: number;
  data_inicio: Dayjs;
  data_fim?: Dayjs | null;
  dias_contrato_mes: number;
  horas_por_dia: number;
  estado: string;
  codigo_moeda: string;
  remuneracoes: { infotipo_salarial_id: number; valor_mes: number }[];
  produtividade?: { item_id: number; preco_unitario?: number | null }[];
  /** Minuta (Lei 12/23): razões do contrato a termo e período experimental. */
  motivo_termo?: string | null;
  periodo_experimental_dias?: number | null;
}

interface PostoEstrutura { id: number; titulo: string | null; responsabilidades: string | null }

/** Prefixo das colunas «valor mensal por rubrica» da vista Linhas (legado: chave `rub-<id>`). */
const PREFIXO_RUBRICA = 'rub_';

/**
 * Colunas da vista Linhas do «Novo contrato» (legado: formularios_linhas_config.js › contratos): colaborador, uma
 * coluna de VALOR MENSAL por rubrica de vencimento, dias/mês, horas/dia, início, fim, estado e moeda.
 */
export function camposContrato(colaboradores: CampoFormulario['opcoes'], rubricas: { id: number; nome: string }[]): CampoFormulario[] {
  return [
    { nome: 'colaborador_id', rotulo: 'Colaborador', tipo: 'select', obrigatorio: true, largura: 230, opcoes: colaboradores, aliases: ['NIF Colaborador', 'NIF', 'Nome'] },
    ...rubricas.map((r, i): CampoFormulario => ({
      nome: `${PREFIXO_RUBRICA}${r.id}`, rotulo: `${r.nome} (mensal)`, tipo: 'numero', largura: 130, min: 0, precisao: 2, aliases: [r.nome], emLinhas: i < 4 ? true : 'opcional', seccao: 'Rubricas',
      ...(i === 0 ? { validar: (_: unknown, reg: Record<string, unknown>) => (rubricas.some((x) => Number(reg[`${PREFIXO_RUBRICA}${x.id}`]) > 0) ? null : 'Indique o valor mensal de pelo menos uma rubrica.') } : {}),
    })),
    { nome: 'dias_contrato_mes', rotulo: 'Dias/mês', tipo: 'numero', obrigatorio: true, largura: 80, min: 1, max: 31, omissao: 22, aliases: ['Dias_Mes', 'Dias Mes'] },
    { nome: 'horas_por_dia', rotulo: 'Horas/dia', tipo: 'numero', largura: 80, min: 0.5, max: 24, omissao: 8, aliases: ['Horas_Dia', 'Horas Dia'] },
    { nome: 'data_inicio', rotulo: 'Início', tipo: 'data', obrigatorio: true, largura: 140, aliases: ['Data_Inicio', 'Data Início'] },
    { nome: 'data_fim', rotulo: 'Fim', tipo: 'data', largura: 140, aliases: ['Data_Fim', 'Data Fim'],
      validar: (f, r) => (f && r.data_inicio && String(f) < String(r.data_inicio) ? 'O fim é anterior ao início.' : null) },
    { nome: 'estado', rotulo: 'Estado', tipo: 'select', largura: 110, omissao: 'ACTIVO', opcoes: ESTADOS_COLABORADOR.filter((e) => e.value !== 'SUSPENSO') },
    { nome: 'codigo_moeda', rotulo: 'Moeda', largura: 90, maxLength: 3, omissao: 'AOA', validar: (m) => (m && String(m).trim().length !== 3 ? 'Moeda: 3 letras.' : null) },
  ];
}

/**
 * Corpo do POST/PUT /rh/contratos — grade (remunerações em lista, horário, minuta) e linhas (uma coluna por rubrica;
 * sem horário nem itens de produtividade, que se acrescentam depois em «Editar»).
 */
export function dadosContrato(v: Record<string, unknown>) {
  const { usar_horario: _usar, horario: _h, ...resto } = v;
  const rubricas = Object.keys(v).filter((k) => k.startsWith(PREFIXO_RUBRICA));
  const remuneracoes = Array.isArray(v.remuneracoes)
    ? v.remuneracoes
    : rubricas.filter((k) => Number(v[k]) > 0).map((k) => ({ infotipo_salarial_id: Number(k.slice(PREFIXO_RUBRICA.length)), valor_mes: Number(v[k]) }));
  for (const k of rubricas) delete (resto as Record<string, unknown>)[k];
  const fim = dataDe(v.data_fim);
  return {
    ...resto,
    remuneracoes,
    horario: horarioParaApi(v as ValoresHorario),
    data_inicio: dataDe(v.data_inicio),
    data_fim: fim,
    // colunas deixadas em branco na colagem: os mesmos valores por omissão do «Novo contrato» da grade
    dias_contrato_mes: numeroDe(v.dias_contrato_mes) ?? 22,
    horas_por_dia: numeroDe(v.horas_por_dia) ?? 8,
    estado: v.estado || 'ACTIVO',
    codigo_moeda: String(v.codigo_moeda || 'AOA').toUpperCase(),
    produtividade: ((v.produtividade as ValoresContrato['produtividade']) ?? []).map((p) => ({ item_id: p.item_id, preco_unitario: p.preco_unitario ?? null })),
    motivo_termo: fim ? String(v.motivo_termo ?? '').trim() || null : null,
    periodo_experimental_dias: numeroDe(v.periodo_experimental_dias),
  };
}

/** RH › Contratos (ecrã contratos): histórico de contratos por colaborador, com remunerações e itens de produtividade. */
export default function Contratos() {
  const { pode } = useSessao();
  const colaboradores = useColaboradores();
  const infotipos = useInfotipos();
  const itensProd = useQuery({ queryKey: ['rh', 'produtividade', 'itens'], queryFn: () => obter<ItemProdutividade[]>('/rh/produtividade/itens'), staleTime: 300_000 });
  const [colaborador, setColaborador] = useState<number>();
  const [estado, setEstado] = useState<string>();
  const [termo, setTermo] = useState('');
  const [edicao, setEdicao] = useState<ContratoTrabalho | 'novo' | null>(null);
  const [terminar, setTerminar] = useState<ContratoTrabalho | null>(null);
  // Fase B (Versão Actual): suspender, reactivar, cessar, anular a cessação e ver o estado (contrato_estado_ui.js)
  const [accaoEstado, setAccaoEstado] = useState<{ tipo: AccaoEstado; contrato: ContratoTrabalho } | null>(null);
  const [importarCt, setImportarCt] = useState(false);
  const [massa, setMassa] = useState<'RUBRICAS' | 'PRODUTIVIDADE' | null>(null);
  const [simular, setSimular] = useState(false);
  const [subsidios, setSubsidios] = useState(false);
  const [form] = Form.useForm<ValoresContrato>();
  const [formFim] = Form.useForm<{ data_fim: Dayjs }>();
  const contratos = useQuery({
    queryKey: ['rh', 'contratos', { colaborador_id: colaborador, estado }],
    queryFn: () => obter<ContratoTrabalho[]>('/rh/contratos', { colaborador_id: colaborador, estado }),
  });
  useAvisarErro(contratos.error);
  const accao = useAccaoRh(() => {
    setEdicao(null);
    setTerminar(null);
  });
  const remuneracoesForm = Form.useWatch('remuneracoes', form);
  const usarHorario = Boolean(Form.useWatch('usar_horario', form));
  const estadoActo = edicao !== null && edicao !== 'novo' && (edicao.estado === 'SUSPENSO' || edicao.estado === 'CESSADO');
  const cliente = useQueryClient();
  const hoje = dayjs().format('YYYY-MM-DD');

  const abrir = (c: ContratoTrabalho | 'novo') => {
    form.resetFields();
    if (c === 'novo') {
      form.setFieldsValue({ colaborador_id: colaborador, data_inicio: dayjs(), dias_contrato_mes: 22, horas_por_dia: 8, estado: 'ACTIVO', codigo_moeda: 'AOA', remuneracoes: [{} as ValoresContrato['remuneracoes'][number]], produtividade: [], ...horarioParaForm(null) });
    } else {
      form.setFieldsValue({
        colaborador_id: c.colaborador_id,
        data_inicio: c.data_inicio ? dayjs(c.data_inicio) : undefined,
        data_fim: semFim(c.data_fim) ? null : dayjs(c.data_fim),
        dias_contrato_mes: c.dias_contrato_mes ?? 22,
        horas_por_dia: Number(c.horas_por_dia ?? 8),
        estado: c.estado ?? 'ACTIVO',
        codigo_moeda: c.codigo_moeda ?? 'AOA',
        remuneracoes: normalizarRemuneracoes(c.remuneracoes, c.dias_contrato_mes ?? 22),
        produtividade: (c.produtividade ?? []).map((p) => ({ item_id: p.item_id, preco_unitario: p.preco_unitario })),
        motivo_termo: c.motivo_termo ?? '',
        periodo_experimental_dias: c.periodo_experimental_dias ?? null,
        ...horarioParaForm(c.horario),
      });
    }
    setEdicao(c);
  };

  const gravar = (v: ValoresContrato) => {
    const dados = dadosContrato(v as unknown as Record<string, unknown>);
    if (edicao === 'novo') accao.mutate({ metodo: 'post', url: '/rh/contratos', dados });
    else if (edicao) accao.mutate({ metodo: 'put', url: `/rh/contratos/${edicao.id}`, dados });
  };

  const linhas = (contratos.data ?? []).filter((c) => contem(colaboradores.nome(c.colaborador_id), termo));
  const vencimentos = infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO');
  const camposLinhas = useMemo(
    () => camposContrato(opcoesColaboradores(colaboradores.lista), infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO')),
    [colaboradores.lista, infotipos.lista],
  );
  const contratoAberto = edicao !== null && edicao !== 'novo' ? edicao : null;

  const pequeno = useEcraPequeno();
  const { imprimir, obterIdentidade } = useImpressao();
  const cargos = useCargos();
  const [seleccionados, setSeleccionados] = useState<Key[]>([]);
  const [aEliminar, setAEliminar] = useState(false);

  /** «Gerar PDF para Assinatura»: minuta do contrato pela Lei 12/23 (legado novo, app_v2.js:4998), impressa ou guardada em PDF. */
  const gerarPdf = async (c: ContratoTrabalho) => {
    const col = colaboradores.mapa.get(c.colaborador_id);
    const funcao = col?.cargo_funcao_id ? cargos.lista.find((x) => x.id === col.cargo_funcao_id) : undefined;
    const id = await obterIdentidade();
    // percentagens dos subsídios (configuração de RH) e cargo da estrutura orgânica (título e responsabilidades próprias)
    const cfg = await obter<ConfiguracaoRH>('/rh/configuracao').catch(() => null);
    let posto: PostoEstrutura | undefined;
    if (col?.posto_trabalho_id && pode('est_estrutura_view', 'colaboradores_view')) {
      const arvore = await obter<{ unidades: { postos: PostoEstrutura[] }[] }>('/rh/estrutura').catch(() => null);
      posto = arvore?.unidades.flatMap((u) => u.postos).find((p) => p.id === col.posto_trabalho_id);
    }
    await imprimir({
      titulo: 'Contrato de Trabalho',
      subtitulo: colaboradores.nome(c.colaborador_id),
      modo: 'pdf',
      orientacao: 'retrato',
      nomeFicheiro: `Contrato_${colaboradores.nome(c.colaborador_id)}`,
      conteudo: contratoAssinaturaHtml({
        contrato: c,
        empresa: { nome: id?.nome ?? '', nif: id?.nif, morada: id?.morada, crc: id?.registo_comercial },
        colaborador: {
          nome: colaboradores.nome(c.colaborador_id), nif: col?.nif, morada: [col?.endereco, col?.bairro, col?.municipio, col?.provincia].filter(Boolean).join(', '),
          nacionalidade: col?.nacionalidade, data_nascimento: col?.data_nascimento, estado_civil: col?.estado_civil,
          naturalidade: [col?.naturalidade, col?.provincia_naturalidade].filter(Boolean).join(', '), documento: col?.documento_identificacao,
          documento_validade: col?.documento_validade, inss: col?.numero_inss,
        },
        funcao: funcao ? { nome: funcao.nome, objetivo_geral: funcao.objetivo_geral, responsabilidades: funcao.responsabilidades, descricao: funcao.descricao } : null,
        cargo: posto ? { titulo: posto.titulo, responsabilidades: posto.responsabilidades } : null,
        subsidios: { ferias: cfg?.subsidio_ferias_pct, natal: cfg?.subsidio_natal_pct },
        rubrica: (i) => infotipos.nome(i),
      }),
    });
  };

  /** «Eliminar Seleção»: elimina um a um (as mesmas regras do servidor que a eliminação individual). */
  const eliminarSeleccao = async () => {
    setAEliminar(true);
    let ok = 0;
    const falhas: string[] = [];
    for (const k of seleccionados) {
      try {
        await enviar('delete', `/rh/contratos/${k}`);
        ok += 1;
      } catch (e) {
        const c = (contratos.data ?? []).find((x) => x.id === k);
        falhas.push(`${c ? colaboradores.nome(c.colaborador_id) : `#${k}`}: ${e instanceof Error ? e.message : 'erro'}`);
      }
    }
    setAEliminar(false);
    setSeleccionados([]);
    void cliente.invalidateQueries({ queryKey: ['rh'] });
    if (ok) message.success(`${ok} contrato(s) eliminado(s).`);
    if (falhas.length) Modal.warning({ title: `${falhas.length} contrato(s) não eliminado(s)`, content: <ul style={{ paddingLeft: 18 }}>{falhas.slice(0, 20).map((f) => <li key={f}>{f}</li>)}</ul> });
  };
  // colunas do legado (renderContratos): colaborador, rubricas, valor/mês, valor/dia, valor/hora, dias · horas, início, fim, estado
  const rem = (c: ContratoTrabalho) => normalizarRemuneracoes(c.remuneracoes, c.dias_contrato_mes ?? 22);
  const moeda = (c: ContratoTrabalho) => (c.codigo_moeda && c.codigo_moeda !== 'AOA' ? ` ${c.codigo_moeda}` : '');
  const porDia = (c: ContratoTrabalho) => Number(totalContrato(c)) / (Number(c.dias_contrato_mes) || 22);
  const colunas: ColunaApi<ContratoTrabalho>[] = [
    { title: 'Colaborador', dataIndex: 'colaborador_id', render: (v: number) => <strong>{colaboradores.nome(v)}</strong> },
    { title: 'Rubricas (Infotipos)', responsive: ['lg'], ellipsis: true, valorImpressao: (c) => rem(c).map((r) => infotipos.nome(r.infotipo_salarial_id)).join(', '),
      render: (_, c) => <Tooltip title={rem(c).map((r) => `${infotipos.nome(r.infotipo_salarial_id)}: ${formatarKz(r.valor_mes)}`).join(' · ')}>{rem(c).map((r) => infotipos.nome(r.infotipo_salarial_id)).join(', ')}</Tooltip> },
    { title: 'Valor/Mês (Kz)', align: 'right', valorImpressao: (c) => `${formatarKz(totalContrato(c))}${moeda(c)}`, render: (_, c) => `${formatarKz(totalContrato(c))}${moeda(c)}` },
    { title: 'Valor/Dia (Kz)', align: 'right', responsive: ['md'], valorImpressao: (c) => formatarKz(porDia(c)), render: (_, c) => formatarKz(porDia(c)) },
    { title: 'Valor/Hora (Kz)', align: 'right', responsive: ['lg'], valorImpressao: (c) => formatarKz(porDia(c) / (Number(c.horas_por_dia) || 8)), render: (_, c) => formatarKz(porDia(c) / (Number(c.horas_por_dia) || 8)) },
    { title: 'Dias · Horas', align: 'center', responsive: ['md'], valorImpressao: (c) => `${c.dias_contrato_mes ?? 22} d · ${Number(c.horas_por_dia ?? 8)} h`, render: (_, c) => `${c.dias_contrato_mes ?? 22} d · ${Number(c.horas_por_dia ?? 8)} h` },
    { title: 'Início', dataIndex: 'data_inicio', align: 'center', render: formatarData },
    { title: 'Fim', dataIndex: 'data_fim', align: 'center', render: (v: string | null) => (semFim(v) ? '-' : formatarData(v)) },
    { title: 'Estado', dataIndex: 'estado', align: 'center', render: (e: string | null, c) => <Space size={4} wrap><EstadoTag estado={e} />{contratoVigente(c, hoje) && <Typography.Text type="success">vigente</Typography.Text>}</Space> },
  ];

  return (
    <>
      <ImportarExcel aberto={importarCt} titulo="Importar contratos (Excel)" url="/rh/importacoes/contratos" modelo="contratos"
        ajuda="Modelo vertical: uma linha por rubrica; as linhas com o mesmo NIF formam um contrato (início, dias/mês, horas/dia e estado na 1.ª linha). Quem já tiver contrato nesse período é recusado."
        aoFechar={() => setImportarCt(false)} />
      <ContratosMassa aberto={massa !== null} tipoInicial={massa ?? 'RUBRICAS'} aoFechar={() => setMassa(null)} contratos={seleccionados.map(Number)} />
      <SubsidiosMinuta aberto={subsidios} aoFechar={() => setSubsidios(false)} />
      <SimularMassa aberto={simular} aoFechar={() => setSimular(false)} />
      <CabecalhoPagina
        titulo="Gestão de Contratos de Trabalho"
        subtitulo="Contratos de trabalho e remunerações (vários contratos por colaborador, sem sobreposição de datas)"
        accoes={
          <>
            <Button type="primary" style={{ background: '#10b981', borderColor: '#10b981' }} icon={<CalculatorOutlined />} onClick={() => setSimular(true)}>Simular Massa Salarial</Button>
            <Button icon={<IconeFa nome="file-invoice" />} disabled={!linhas.length} onClick={() => void pedidoTabela({ titulo: 'Lista de Contratos de Trabalho', orientacao: 'paisagem', colunas, linhas }).then(imprimir)}>Extrair</Button>
            {pode('contratos_import') && (
              <>
                <Button icon={<IconeFa nome="file-download" />} onClick={() => void descarregar('/rh/importacoes/modelos/contratos', undefined, 'Template_Contratos_Vertical.xlsx').catch(notificarErro)}>Template</Button>
                <Button icon={<ImportOutlined />} onClick={() => setImportarCt(true)}>Importar Excel</Button>
              </>
            )}
            {pode('contratos_new') && <Button type="primary" icon={<PlusOutlined />} onClick={() => abrir('novo')}>Novo Contrato</Button>}
            {pode('contratos_new') && <Button type="primary" icon={<AppstoreAddOutlined />} title="Escolher rubricas com o mesmo valor mensal e aplicá-las a vários colaboradores (actualiza ou cria os contratos)" onClick={() => setMassa('RUBRICAS')}>Aplicar em massa</Button>}
            {pode('contratos_new') && <Button type="primary" icon={<IconeFa nome="chart-line" />} title="Aplicar itens de produtividade a vários contratos de uma vez (parte dos contratos marcados na lista)" onClick={() => setMassa('PRODUTIVIDADE')}>Produtividade em massa</Button>}
            {pode('config_empresas_gerir', 'contratos_new') && <Button icon={<IconeFa nome="percent" />} title="Percentagens dos subsídios de férias e de Natal na minuta (mínimo legal 50 %)" onClick={() => setSubsidios(true)}>Subsídios na minuta</Button>}
            {pode('contratos_terminate') && (
              <>
                <Button icon={<IconeFa nome="check-double" />} disabled={!linhas.length} onClick={() => setSeleccionados(linhas.map((c) => c.id))}>Selecionar Tudo</Button>
                <Button icon={<IconeFa nome="square" />} disabled={!seleccionados.length} onClick={() => setSeleccionados([])}>Desmarcar</Button>
                <Button danger icon={<IconeFa nome="trash-alt" />} disabled={!seleccionados.length} loading={aEliminar} onClick={() => Modal.confirm({
                  title: `Eliminar os ${seleccionados.length} contrato(s) seleccionado(s)?`,
                  content: 'Os processamentos encerrados guardam a fotografia: nada muda para trás.',
                  okText: 'Eliminar', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: eliminarSeleccao,
                })}>Eliminar Seleção{seleccionados.length ? ` (${seleccionados.length})` : ''}</Button>
              </>
            )}
          </>
        }
        impressaoDesactivada={!linhas.length}
        impressao={() => pedidoTabela({
          titulo: 'Lista de contratos de trabalho',
          filtros: [colaborador !== undefined && `Colaborador: ${colaboradores.nome(colaborador)}`, estado && `Estado: ${ESTADOS_COLABORADOR.find((e) => e.value === estado)?.label ?? estado}`, termo && `Pesquisa: ${termo}`],
          colunas,
          linhas,
        })}
      />
      <Card title="Lista de Contratos Registados">
        <BarraFiltros>
          <SeletorColaborador value={colaborador} onChange={setColaborador} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={ESTADOS_CONTRATO} />
          <PesquisaLocal aoMudar={setTermo} placeholder="Nome do colaborador" />
        </BarraFiltros>
        <TabelaComModos<ContratoTrabalho> rowKey="id" size={pequeno ? 'small' : 'middle'} loading={contratos.isFetching} columns={colunas} dataSource={linhas} scroll={scrollTabela()}
          seleccao={pode('contratos_terminate') ? { chaves: seleccionados, aoMudar: (k) => setSeleccionados(k) } : undefined}
          accoes={(c) => [
            { icone: 'file-pdf', titulo: 'Gerar PDF para Assinatura', cor: '#dc2626', onClick: () => gerarPdf(c).catch((e) => notificarErro(e, 'Não foi possível gerar o contrato')) },
            pode('contratos_new') && { icone: 'edit', titulo: 'Editar', onClick: () => abrir(c) },
            // ordem do antigo: PDF · Editar · estado (ver / anular / reactivar / suspender · cessar) · Excluir; «Terminar» é do sistema novo
            ...accoesEstadoContrato(c, pode, (tipo, x) => setAccaoEstado({ tipo, contrato: x })),
            pode('contratos_terminate') && semFim(c.data_fim) && c.estado !== 'CESSADO' && { icone: 'calendar-times', titulo: 'Terminar contrato', variante: 'aviso', onClick: () => { formFim.setFieldsValue({ data_fim: dayjs() }); setTerminar(c); } },
            pode('contratos_terminate') && {
              icone: 'trash', titulo: 'Excluir', variante: 'perigo',
              confirmar: { titulo: 'Eliminar este contrato?', texto: 'Os processamentos encerrados guardam a fotografia: nada muda para trás.', textoOk: 'Eliminar', perigo: true },
              onClick: () => accao.mutateAsync({ metodo: 'delete', url: `/rh/contratos/${c.id}` }),
            },
          ]}
          vazio={{ icone: 'file-contract', titulo: 'Sem contratos', texto: 'Ainda não há contratos com estes filtros.' }} />
      </Card>

      <ModalFormulario chave="rh.contratos" titulo={edicao === 'novo' ? 'Novo contrato' : 'Editar contrato'} aberto={edicao !== null} width={larguraModal(820)} aoFechar={() => setEdicao(null)}
        criacao={edicao === 'novo'} form={form} campos={camposLinhas} nomeRegistos="contratos" invalidar={[['rh']]}
        nota="Uma linha por contrato (um contrato por colaborador). Indique o VALOR MENSAL de cada rubrica; valor dia e hora são apurados pelo sistema. O horário e os itens de produtividade acrescentam-se depois em «Editar»."
        gravarLinha={(r) => enviar('post', '/rh/contratos', dadosContrato(r))}
        okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()}>
        <Form form={form} layout="vertical" onFinish={gravar}>
          <SeccaoFormulario titulo="Dados do contrato">
          <Row gutter={12}>
            <Col xs={24} md={12}>
              <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}>
                <SeletorColaborador style={{ width: '100%' }} disabled={edicao !== 'novo'} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}><Form.Item name="data_inicio" label="Início" rules={[{ required: true, message: 'Data de início.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} md={6}>
              <Form.Item name="data_fim" label="Fim" extra="Vazio = sem fim." dependencies={['data_inicio']} rules={[({ getFieldValue }) => ({
                validator: (_, v: Dayjs | null) => (!v || !getFieldValue('data_inicio') || !v.isBefore(getFieldValue('data_inicio'), 'day') ? Promise.resolve() : Promise.reject(new Error('Anterior ao início.'))),
              })]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}><Form.Item name="dias_contrato_mes" label="Dias do contrato/mês"><InputNumber min={1} max={31} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} md={6}><Form.Item name="horas_por_dia" label="Horas por dia" extra={usarHorario ? 'Acompanha o horário.' : undefined}><InputNumber min={0.5} max={24} step={0.5} style={{ width: '100%' }} disabled={usarHorario} /></Form.Item></Col>
            <Col xs={12} md={6}>
              <Form.Item name="estado" label="Estado" extra={estadoActo ? 'Use Reactivar ou Anular cessação.' : undefined}>
                <Select disabled={estadoActo} options={estadoActo ? ESTADOS_CONTRATO : ESTADOS_COLABORADOR.filter((e) => e.value !== 'SUSPENSO')} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}><Form.Item name="codigo_moeda" label="Moeda" rules={[{ len: 3, message: '3 letras.' }]} extra="O processamento é só em Kz (AOA)."><Input maxLength={3} style={{ textTransform: 'uppercase' }} /></Form.Item></Col>
            {/* Minuta pela Lei 12/23: período experimental (art. 18.º) e, nos contratos a termo, as razões (art. 13.º/2) */}
            <Form.Item noStyle shouldUpdate={(a, b) => a.data_fim !== b.data_fim}>
              {({ getFieldValue }) => {
                const aTermo = Boolean(getFieldValue('data_fim'));
                return (
                  <>
                    <Col xs={12} md={6}>
                      <Form.Item name="periodo_experimental_dias" label="Período experimental (dias)" extra={aTermo ? 'Vazio = 30 dias; máximo 30 (art. 18.º, n.º 3).' : 'Vazio = 60 dias; até 120 por escrito, 180 em direcção (art. 18.º).'}
                        rules={[{ type: 'number', min: 0, max: aTermo ? 30 : 180, message: aTermo ? 'A termo: no máximo 30 dias.' : 'No máximo 180 dias.' }]}>
                        <InputNumber min={0} max={aTermo ? 30 : 180} style={{ width: '100%' }} placeholder={aTermo ? '30' : '60'} />
                      </Form.Item>
                    </Col>
                    {aTermo && (
                      <Col xs={24} md={18}>
                        <Form.Item name="motivo_termo" label="Razões determinantes da contratação a termo (art. 13.º, n.º 2, e art. 15.º da Lei 12/23)"
                          extra="Sem o motivo, a lei considera o contrato celebrado por tempo indeterminado (art. 13.º, n.º 3); a minuta sai com o aviso.">
                          <Input.TextArea rows={2} maxLength={2000} placeholder="Ex.: acréscimo temporário e excepcional da actividade da empresa (art. 15.º)" />
                        </Form.Item>
                      </Col>
                    )}
                  </>
                );
              }}
            </Form.Item>
          </Row>
          {!pode('contratos_terminate') && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Terminar um contrato (data de fim ou estado Inactivo) exige a permissão de rescisão." />}
          </SeccaoFormulario>
          {/* como no legado, o horário só se abre quando o contrato o tem; recolhido continua montado (valores e regras mantêm-se) */}
          <SeccaoFormulario titulo="Horário de trabalho" recolhida={!contratoAberto?.horario?.entrada}>
            <CamposHorario form={form} semTitulo />
          </SeccaoFormulario>
          <SeccaoFormulario titulo="Remunerações mensais">
          <Form.List name="remuneracoes" rules={[{ validator: (_, v: unknown[]) => (v && v.length > 0 ? Promise.resolve() : Promise.reject(new Error('Pelo menos uma remuneração.'))) }]}>
            {(campos, { add, remove }, { errors }) => (
              <>
                {campos.map(({ key, name }) => (
                  <Row gutter={8} key={key} align="middle">
                    <Col xs={24} sm="auto" flex="auto">
                      <Form.Item name={[name, 'infotipo_salarial_id']} rules={[{ required: true, message: 'Rubrica.' }]}>
                        <Select showSearch optionFilterProp="label" placeholder="Rubrica (vencimento)" options={vencimentos.map((i) => ({ value: i.id, label: i.nome }))} />
                      </Form.Item>
                    </Col>
                    <Col xs={20} sm={8} style={{ maxWidth: 200 }}>
                      <Form.Item name={[name, 'valor_mes']} rules={[{ required: true, message: 'Valor.' }]}>
                        <InputNumber min={0} step={1000} precision={2} decimalSeparator="," style={{ width: '100%' }} suffix="Kz" />
                      </Form.Item>
                    </Col>
                    <Col><Form.Item><MinusCircleOutlined onClick={() => remove(name)} aria-label="Retirar" /></Form.Item></Col>
                  </Row>
                ))}
                <Form.ErrorList errors={errors} />
                <Flex justify="space-between" align="center" wrap gap={8}>
                  <Button type="dashed" icon={<PlusOutlined />} onClick={() => add()}>Acrescentar rubrica</Button>
                  <Typography.Text strong>Total mensal: {formatarKz(somar((remuneracoesForm ?? []).map((r) => r?.valor_mes)), true)}</Typography.Text>
                </Flex>
              </>
            )}
          </Form.List>
          </SeccaoFormulario>
          {(itensProd.data ?? []).length > 0 && (
            <SeccaoFormulario titulo="Itens de produtividade" recolhida={!contratoAberto?.produtividade?.length}>
              <Form.List name="produtividade">
                {(campos, { add, remove }) => (
                  <>
                    {campos.map(({ key, name }) => (
                      <Row gutter={8} key={key} align="middle">
                        <Col xs={24} sm="auto" flex="auto">
                          <Form.Item name={[name, 'item_id']} rules={[{ required: true, message: 'Item.' }]}>
                            <Select placeholder="Item" options={(itensProd.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} — ${i.descricao}${i.ativo ? '' : ' (inactivo)'}` }))} />
                          </Form.Item>
                        </Col>
                        <Col xs={20} sm={8} style={{ maxWidth: 220 }}>
                          <Form.Item name={[name, 'preco_unitario']} extra="Vazio = preço do item.">
                            <InputNumber min={0} precision={4} decimalSeparator="," placeholder="Preço próprio" style={{ width: '100%' }} />
                          </Form.Item>
                        </Col>
                        <Col><Form.Item><MinusCircleOutlined onClick={() => remove(name)} aria-label="Retirar" /></Form.Item></Col>
                      </Row>
                    ))}
                    <Button type="dashed" icon={<PlusOutlined />} onClick={() => add()}>Acrescentar item</Button>
                  </>
                )}
              </Form.List>
            </SeccaoFormulario>
          )}
        </Form>
      </ModalFormulario>

      <ModaisEstadoContrato accao={accaoEstado} nome={(id) => colaboradores.nome(id)} aoFechar={() => setAccaoEstado(null)} />

      <Modal title="Terminar contrato" open={terminar !== null} onCancel={() => setTerminar(null)} okText="Terminar" okButtonProps={{ danger: true }} cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => formFim.submit()} destroyOnHidden>
        <Typography.Paragraph>{terminar ? colaboradores.nome(terminar.colaborador_id) : ''} — contrato iniciado em {formatarData(terminar?.data_inicio)}.</Typography.Paragraph>
        <Form form={formFim} layout="vertical" onFinish={(v) => terminar && accao.mutate({ metodo: 'post', url: `/rh/contratos/${terminar.id}/terminar`, dados: { data_fim: dataApi(v.data_fim) } })}>
          <Form.Item name="data_fim" label="Data de fim" rules={[{ required: true, message: 'Indique a data.' }]} extra="Se a data já passou, o contrato fica Inactivo.">
            <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}
