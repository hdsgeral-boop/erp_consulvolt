import { Button, Checkbox, Popover, Space, Table, Tag, type TableProps } from 'antd';
import { CopyOutlined } from '@ant-design/icons';
import type { ColumnGroupType, ColumnsType, SorterResult, TablePaginationConfig } from 'antd/es/table/interface';
import { createContext, isValidElement, useCallback, useContext, useEffect, useMemo, useRef, useState, type HTMLAttributes, type Key, type MouseEvent as EventoRato, type ReactNode } from 'react';
import { IconeFa } from '../fluxos/IconeFa';
import { BotoesExportarLegado } from '../impressao/BotoesExportar';
import { colunasDoConteudo, copiarTexto, tabelaParaTexto } from '../impressao/copiar';
import { tabelaHtml } from '../impressao/tabela';
import { prepararTexto } from '../impressao/texto';
import type { Orientacao, Papel } from '../impressao/tipos';
import { useMensagem, type PedidoImpressao } from '../impressao/useImpressao';
import { AccoesLinha, type AccaoLinha } from './AccoesLinha';
import { BarraSeleccao, type AccaoMassa, type OpcoesEditarEmMassa } from './BarraSeleccao';
import { chaveColuna, colunasParaImpressao, conteudoTotal, eColunaAccoes, folhas, numeroCelula, textoColuna, tituloTexto, type ColunaLegado, type FolhaLegado } from './colunasTabela';
import { EstadoVazio, type PropsEstadoVazio } from './EstadoVazio';
import { BotaoFiltroPersonalizado, FiltroColuna } from './FiltrosColuna';
import { aplicarFiltros, colunaFiltravel, filtrosActivos, FILTRO_PERSONALIZADO_VAZIO, OPERADORES_FILTRO, type CondicaoFiltro, type EstadoFiltros, type FiltroPersonalizado } from './filtrosLista';
import { useColunasEscolhidas } from './preferenciaColunas';
import './padroesLegado.css';

/**
 * Tabela com os padrões das listas do sistema anterior (DataTables): caixa «N registos encontrados» + Excel · PDF ·
 * Imprimir · Colunas ▾ · Filtro personalizado (G.1), linha de filtros por coluna (G.2), linha «TOTAL:» (G.3),
 * paginação «Mostrar [N] registos» / «Anterior · 1 · Próximo» (G.4), coluna «AÇÕES» com botões-ícone, selecção
 * múltipla com barra «Seleccionadas: N» e estado vazio com ícone (G.7).
 *
 * É a base de `TabelaComModos` (listas locais) e de `TabelaApi` (listas paginadas no servidor); os ecrãs não a usam
 * directamente — usam aquelas duas, com as props de `PropsPadroesLista`.
 */

/** Opções de impressão/exportação de uma lista (Excel · PDF · Imprimir). */
export interface ImpressaoLista {
  titulo: string;
  subtitulo?: string;
  periodo?: string;
  /** Filtros a mostrar no cabeçalho do documento (ex.: ['Estado: Activo']). */
  filtros?: string | (string | null | undefined | false)[];
  orientacao?: Orientacao;
  papel?: Papel;
  nomeFicheiro?: string;
  /** Rótulo da linha de totais no documento (quando há colunas com `total`/`totalImpressao`). */
  rotuloTotal?: string;
  /** Mostra «Excel» (por omissão: sim). */
  excel?: boolean;
}

export interface OpcoesSeleccao<T> {
  /** Acções em massa (botões da barra «Seleccionadas: N»). */
  accoes?: AccaoMassa<T>[];
  /** Botão «Editar em massa (N)». */
  editarEmMassa?: OpcoesEditarEmMassa<T>;
  /** Texto de ajuda na barra. */
  ajuda?: ReactNode;
  /** Chaves seleccionadas (controlado); sem isto a tabela gere a selecção. */
  chaves?: Key[];
  aoMudar?: (chaves: Key[], linhas: T[]) => void;
  /** Linha que não se pode seleccionar (`true` ou texto da razão). */
  bloquearLinha?: (linha: T) => boolean | string;
  /** Sem a barra «Seleccionadas: N» (o ecrã mostra as acções noutro sítio, ex.: «Editar em massa» no cabeçalho do cartão). */
  semBarra?: boolean;
}

/** Props do padrão das listas do antigo — aceites por TabelaComModos e TabelaApi. */
export interface PropsPadroesLista<T> {
  /** «N registos encontrados» à esquerda da caixa por cima da tabela (por omissão: sim). */
  contador?: boolean;
  /** Menu «Colunas ▾» (mostrar/esconder colunas, lembrado por ecrã e utilizador; por omissão: sim). */
  menuColunas?: boolean;
  /** Linha de filtros por coluna por baixo dos cabeçalhos (por omissão: sim; por coluna: `filtravel: false`). */
  filtrosColuna?: boolean;
  /** Botão «Filtro personalizado» (construtor de condições; por omissão: sim). */
  filtroPersonalizado?: boolean;
  /** Paginação com o texto e a posição do antigo (por omissão: sim). */
  paginacaoLegado?: boolean;
  /** Rótulo da linha de totais (por omissão «TOTAL:»); `false` = sem linha mesmo com colunas `total`. */
  rotuloTotal?: ReactNode | false;
  /** Acções por linha → coluna «AÇÕES» automática no fim (botões-ícone do antigo). */
  accoes?: (linha: T, indice: number) => (AccaoLinha | null | undefined | false)[];
  /** Título da coluna de acções (por omissão «Ações»; o cabeçalho mostra-o em maiúsculas). */
  tituloAccoes?: ReactNode;
  /** Selecção múltipla com a barra «Seleccionadas: N» e acções em massa. */
  seleccao?: OpcoesSeleccao<T>;
  /** Estado vazio (ícone grande, título e frase) ou um nó próprio. */
  vazio?: PropsEstadoVazio | ReactNode;
  /** Excel · PDF · Imprimir das linhas filtradas (listas locais; na TabelaApi usa-se a `impressao` da própria). */
  impressao?: ImpressaoLista;
}

export interface ServidorLista {
  total: number;
  pagina: number;
  tamanho: number;
  /** «Ver Todos» activo (as linhas recebidas são todas). */
  verTodos: boolean;
  aoMudarPagina: (pagina: number, tamanho: number | 'todos') => void;
  /** Filtros por coluna que vão para o servidor (colunas com `filtroServidor`). */
  aoMudarFiltrosServidor?: (parametros: Record<string, string>) => void;
}

export interface PropsTabelaLegado<T> extends Omit<TableProps<T>, 'columns'>, PropsPadroesLista<T> {
  columns?: ColunaLegado<T>[];
  /** Chave da preferência das colunas (ecrã + lista). */
  chaveLista: string;
  /** Acções extra na caixa (à esquerda de Excel/PDF/Imprimir). */
  barra?: ReactNode;
  /** Conteúdo no fim da caixa (ex.: alternância Linhas/Grade). */
  extraDireita?: ReactNode;
  /** Paginação feita pelo servidor (TabelaApi). */
  servidor?: ServidorLista;
  /** Exportação própria (TabelaApi: todas as páginas); recebe as colunas visíveis. */
  obterPedidoExportacao?: (colunasVisiveis: ColunaLegado<T>[]) => Promise<PedidoImpressao | null>;
  /** Mostra os botões de exportação mesmo sem `impressao` (quando há `obterPedidoExportacao`). */
  exportacao?: { excel?: boolean } | false;
  /** Vista alternativa (grade): recebe as linhas já filtradas, as colunas visíveis e a paginação. */
  renderAlternativo?: (contexto: { linhas: readonly T[]; colunas: ColunaLegado<T>[]; paginacao: TablePaginationConfig | false; rowSelection?: TableProps<T>['rowSelection']; vazio: ReactNode }) => ReactNode;
}

export const OPCOES_TAMANHO_PAGINA = [10, 25, 50, 100] as const;
export const TAMANHO_PAGINA_LEGADO = 50;

/** Configuração da paginação do antigo: «Mostrar [N] registos» à esquerda (com «Ver Todos») e «Anterior · 1 · Próximo». */
export function paginacaoLegado(o: { current: number; pageSize: number; total: number; verTodos: boolean; aoMudar: (pagina: number, tamanho: number | 'todos') => void; extra?: TablePaginationConfig }): TablePaginationConfig {
  const { extra = {} } = o;
  return {
    ...extra,
    current: o.verTodos ? 1 : o.current,
    pageSize: o.verTodos ? Math.max(1, o.total) : o.pageSize,
    total: o.total,
    showSizeChanger: false,
    showLessItems: true,
    className: ['erp-paginacao-legado', extra.className].filter(Boolean).join(' '),
    showTotal: () => (
      <span className="erp-paginacao-mostrar">
        Mostrar
        {/* lista nativa, como o «Mostrar _MENU_ registos» das DataTables */}
        <select
          className="erp-select-pagina"
          value={o.verTodos ? -1 : o.pageSize}
          aria-label="Registos por página"
          onChange={(e) => {
            const v = Number(e.target.value);
            o.aoMudar(1, v === -1 ? 'todos' : v);
          }}
        >
          {OPCOES_TAMANHO_PAGINA.map((n) => (
            <option key={n} value={n}>
              {n}
            </option>
          ))}
          <option value={-1}>Ver Todos</option>
        </select>
        registos
      </span>
    ),
    itemRender: (_pagina, tipo, original) => {
      if (tipo === 'prev') return <span className="erp-pag-texto">Anterior</span>;
      if (tipo === 'next') return <span className="erp-pag-texto">Próximo</span>;
      return original;
    },
    onChange: (p, s) => o.aoMudar(p, s),
  };
}

type Coluna<T> = ColunaLegado<T>;

/** Aplica `fn` a cada folha (respeita grupos de colunas). */
function mapearFolhas<T>(colunas: readonly Coluna<T>[], fn: (c: FolhaLegado<T>) => Coluna<T>): Coluna<T>[] {
  return colunas.map((c) => {
    const filhos = (c as ColumnGroupType<T>).children;
    if (filhos?.length) return { ...c, children: mapearFolhas(filhos as Coluna<T>[], fn) } as Coluna<T>;
    return fn(c as FolhaLegado<T>);
  });
}

/**
 * Comparador por omissão de uma coluna sem `sorter` próprio: numérico quando as duas células lêem como número
 * (`numeroCelula`, já usado pelos totais — entende «1 234,50 Kz» em pt-PT), senão texto (`localeCompare`, já com o
 * texto mostrado via `textoColuna`, nunca o valor bruto de `dataIndex`).
 */
function compararColunaPorOmissao<T>(c: FolhaLegado<T>): (a: T, b: T) => number {
  return (a, b) => {
    const na = numeroCelula(c, a, 0);
    const nb = numeroCelula(c, b, 0);
    if (na !== null && nb !== null) return na - nb;
    const ta = String(textoColuna(c, a, 0) ?? '');
    const tb = String(textoColuna(c, b, 0) ?? '');
    return ta.localeCompare(tb, 'pt');
  };
}

function chaveLinhaDe<T>(linha: T, indice: number, rowKey: TableProps<T>['rowKey']): Key {
  if (typeof rowKey === 'function') return rowKey(linha, indice) as Key;
  const campo = (rowKey ?? 'key') as string;
  const v = (linha as Record<string, unknown>)[campo];
  if (v !== undefined && v !== null) return v as Key;
  return ((linha as { id?: Key }).id ?? indice) as Key;
}

interface EstadoAntd<T> {
  filtros: Record<string, (Key | boolean)[] | null>;
  ordem: SorterResult<T> | SorterResult<T>[] | null;
}

/** Aplica os filtros (`onFilter`) e a ordenação (`sorter`) das colunas do Ant Design, para contar/imprimir o que se vê. */
function aplicarEstadoAntd<T>(linhas: readonly T[], colunas: readonly Coluna<T>[], estado: EstadoAntd<T>): readonly T[] {
  const fs = folhas(colunas);
  let r = linhas;
  fs.forEach((c, i) => {
    const valores = c.filteredValue ?? estado.filtros[String(c.key ?? chaveColuna(c, i))] ?? null;
    if (c.onFilter && valores && valores.length) r = r.filter((l) => valores.some((v) => c.onFilter!(v as Key, l)));
  });
  const ordens = (Array.isArray(estado.ordem) ? estado.ordem : estado.ordem ? [estado.ordem] : []).filter((o) => o.order && o.column);
  const controlada = fs.find((c) => c.sortOrder);
  const lista = controlada ? [{ column: controlada, order: controlada.sortOrder }] : ordens;
  for (const o of [...lista].reverse()) {
    const s = (o.column as FolhaLegado<T>).sorter;
    const cmp = typeof s === 'function' ? s : typeof s === 'object' && s && typeof (s as { compare?: unknown }).compare === 'function' ? (s as { compare: (a: T, b: T) => number }).compare : null;
    if (!cmp) continue;
    const sinal = o.order === 'descend' ? -1 : 1;
    r = [...r].sort((a, b) => sinal * cmp(a, b, o.order ?? undefined));
  }
  return r;
}

const CtxLinhaFiltros = createContext<ReactNode>(null);

/** Cabeçalho da tabela com a linha de filtros por baixo (identidade estável: não perde o foco ao escrever). */
function CabecalhoComFiltros(props: HTMLAttributes<HTMLTableSectionElement>) {
  const linha = useContext(CtxLinhaFiltros);
  return (
    <thead {...props}>
      {props.children}
      {linha}
    </thead>
  );
}

/** Menu «Colunas ▾» (como o colvis das DataTables): caixas de selecção por coluna, Mostrar todas, Repor. */
function MenuColunas({ colunas, ocultas, aoMudar, aoRepor, tamanho }: { colunas: { chave: string; titulo: string }[]; ocultas: Set<string>; aoMudar: (chave: string, visivel: boolean) => void; aoRepor: () => void; tamanho: 'small' | 'middle' }) {
  const conteudo = (
    <div style={{ maxHeight: 360, overflowY: 'auto', minWidth: 200 }} role="group" aria-label="Colunas visíveis">
      {colunas.map((c) => (
        <div key={c.chave} style={{ padding: '3px 2px' }}>
          <Checkbox checked={!ocultas.has(c.chave)} onChange={(e) => aoMudar(c.chave, e.target.checked)}>
            {c.titulo}
          </Checkbox>
        </div>
      ))}
      <div style={{ borderTop: '1px solid #f1f5f9', marginTop: 6, paddingTop: 6 }}>
        <Button size="small" type="link" onClick={aoRepor} style={{ paddingInline: 0 }}>
          Repor colunas
        </Button>
      </div>
    </div>
  );
  return (
    <Popover trigger="click" placement="bottomRight" content={conteudo}>
      <Button size={tamanho} icon={<IconeFa nome="columns" />} aria-label="Colunas visíveis" disabled={!colunas.length}>
        Colunas <IconeFa nome="chevron-down" style={{ fontSize: 9, marginLeft: 2 }} />
      </Button>
    </Popover>
  );
}

const ESTADO_FILTROS_VAZIO: EstadoFiltros = { colunas: {}, personalizado: FILTRO_PERSONALIZADO_VAZIO };

function descreverFiltros<T>(estado: EstadoFiltros, fs: FolhaLegado<T>[]): string[] {
  const titulo = new Map(fs.map((c, i) => [chaveColuna(c, i), tituloTexto(c)] as const));
  const linhas = Object.entries(estado.colunas)
    .filter(([, c]) => c.valor.trim())
    .map(([k, c]) => `${titulo.get(k) ?? k}: ${OPERADORES_FILTRO[c.operador].toLowerCase()} «${c.valor}»`);
  const pers = estado.personalizado.condicoes.filter((c) => c.valor.trim()).map((c) => `${titulo.get(c.coluna) ?? c.coluna} ${OPERADORES_FILTRO[c.operador].toLowerCase()} «${c.valor}»`);
  if (pers.length) linhas.push(`Filtro personalizado (${estado.personalizado.juncao === 'qualquer' ? 'qualquer' : 'todas'}): ${pers.join('; ')}`);
  return linhas;
}

export function TabelaLegado<T extends object>(props: PropsTabelaLegado<T>) {
  const {
    columns = [],
    chaveLista,
    barra,
    extraDireita,
    servidor,
    obterPedidoExportacao,
    exportacao,
    renderAlternativo,
    contador = true,
    menuColunas = true,
    filtrosColuna = true,
    filtroPersonalizado = true,
    paginacaoLegado: comPaginacaoLegado = true,
    rotuloTotal = 'TOTAL:',
    accoes,
    tituloAccoes = 'Ações',
    seleccao,
    vazio,
    impressao,
    dataSource,
    pagination,
    onChange,
    rowSelection: rowSelectionExterna,
    summary,
    components,
    locale,
    ...resto
  } = props;
  const linhas = useMemo(() => (dataSource ?? []) as T[], [dataSource]);
  const tamanhoBotoes = 'small' as const;

  // ── colunas (acções automáticas + escolha «Colunas ▾» + ordenação por omissão) ──────────────────────────────
  const colunasBase = useMemo<Coluna<T>[]>(() => {
    const base = accoes
      ? [
          ...columns,
          {
            key: '__accoes',
            title: tituloAccoes,
            accoes: true,
            exportar: false,
            filtravel: false,
            ocultavel: false,
            align: 'left',
            render: (_: unknown, linha: T, i: number) => <AccoesLinha accoes={accoes(linha, i)} />,
          } as Coluna<T>,
        ]
      : columns;
    // Botão de ordenar por omissão: qualquer coluna com dataIndex que não defina o seu próprio `sorter` (as colunas
    // de acções e sem dataIndex ficam sem seta, como até agora).
    return mapearFolhas(base, (c) =>
      c.sorter === undefined && !eColunaAccoes(c) && c.dataIndex !== undefined && c.dataIndex !== null
        ? { ...c, sorter: compararColunaPorOmissao(c) }
        : c,
    );
  }, [columns, accoes, tituloAccoes]);

  const { escolha, definir } = useColunasEscolhidas(chaveLista);
  const folhasBase = useMemo(() => folhas(colunasBase), [colunasBase]);
  const chavesFolhas = useMemo(() => new Map(folhasBase.map((c, i) => [c, chaveColuna(c, i)] as const)), [folhasBase]);
  const ocultas = useMemo(() => {
    const s = new Set<string>();
    folhasBase.forEach((c, i) => {
      const k = chaveColuna(c, i);
      if (c.ocultavel === false) return;
      if (escolha.ocultas.includes(k) || (c.ocultaPorOmissao && !escolha.mostradas.includes(k))) s.add(k);
    });
    return s;
  }, [folhasBase, escolha]);
  const colunasVisiveis = useMemo(() => (ocultas.size ? mapearFolhas(colunasBase, (c) => (ocultas.has(chavesFolhas.get(c) ?? '') ? { ...c, hidden: true } : c)) : colunasBase), [colunasBase, ocultas, chavesFolhas]);
  const folhasVisiveis = useMemo(() => folhas(colunasVisiveis).filter((c) => !c.hidden), [colunasVisiveis]);
  const opcoesMenuColunas = useMemo(
    () =>
      folhasBase
        .map((c, i) => ({ c, chave: chaveColuna(c, i), titulo: tituloTexto(c) }))
        .filter(({ c, titulo }) => c.ocultavel !== false && !!titulo && !eColunaAccoes(c))
        .map(({ chave, titulo }) => ({ chave, titulo })),
    [folhasBase],
  );
  const mudarColuna = (chave: string, visivel: boolean) => {
    const col = folhasBase.find((c, i) => chaveColuna(c, i) === chave);
    const porOmissao = !!col?.ocultaPorOmissao;
    const ocultasN = escolha.ocultas.filter((k) => k !== chave);
    const mostradasN = escolha.mostradas.filter((k) => k !== chave);
    if (!visivel && !porOmissao) ocultasN.push(chave);
    if (visivel && porOmissao) mostradasN.push(chave);
    definir({ ocultas: ocultasN, mostradas: mostradasN });
  };

  // ── filtros ────────────────────────────────────────────────────────────────────────────────────────────────
  const [estadoFiltros, setEstadoFiltros] = useState<EstadoFiltros>(ESTADO_FILTROS_VAZIO);
  const modoServidor = !!servidor;
  const colunasServidor = useMemo(() => {
    const m = new Map<string, string>();
    if (modoServidor) folhasBase.forEach((c, i) => c.filtroServidor && m.set(chaveColuna(c, i), c.filtroServidor));
    return m;
  }, [modoServidor, folhasBase]);
  const excluir = useMemo(() => new Set(colunasServidor.keys()), [colunasServidor]);
  const filtradas = useMemo(() => aplicarFiltros(linhas, colunasBase, estadoFiltros, excluir), [linhas, colunasBase, estadoFiltros, excluir]);
  const comFiltroCliente = filtrosActivos(estadoFiltros, excluir);

  // filtros por coluna para o servidor (TabelaApi), com atraso de 400 ms
  const aoFiltrosServidor = servidor?.aoMudarFiltrosServidor;
  const chaveServidor = JSON.stringify([...colunasServidor].map(([k, p]) => [p, estadoFiltros.colunas[k]?.valor ?? '']));
  const primeiraVez = useRef(true);
  useEffect(() => {
    if (!aoFiltrosServidor || !colunasServidor.size) return;
    if (primeiraVez.current) {
      primeiraVez.current = false;
      return;
    }
    const t = setTimeout(() => {
      const params: Record<string, string> = {};
      colunasServidor.forEach((p, k) => {
        const v = estadoFiltros.colunas[k]?.valor.trim();
        if (v) params[p] = v;
      });
      aoFiltrosServidor(params);
    }, 400);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chaveServidor]);

  const mudarFiltroColuna = useCallback((chave: string, c: CondicaoFiltro | undefined) => {
    setEstadoFiltros((e) => {
      const colunas = { ...e.colunas };
      if (c) colunas[chave] = c;
      else delete colunas[chave];
      return { ...e, colunas };
    });
  }, []);
  const mudarPersonalizado = (f: FiltroPersonalizado) => setEstadoFiltros((e) => ({ ...e, personalizado: f }));

  // ── estado do Ant Design (onFilter/sorter das colunas) para contar, totalizar e imprimir o que se vê ─────────
  const [estadoAntd, setEstadoAntd] = useState<EstadoAntd<T>>({ filtros: {}, ordem: null });
  const actuais = useMemo(() => aplicarEstadoAntd(filtradas, colunasVisiveis, estadoAntd), [filtradas, colunasVisiveis, estadoAntd]);
  const aoMudarTabela: TableProps<T>['onChange'] = (pag, filtros, ordem, extra) => {
    setEstadoAntd({ filtros: filtros as EstadoAntd<T>['filtros'], ordem: ordem as EstadoAntd<T>['ordem'] });
    onChange?.(pag, filtros, ordem, extra);
  };

  // ── paginação ──────────────────────────────────────────────────────────────────────────────────────────────
  const confExterna = pagination === false ? false : (pagination ?? {});
  const [paginaLocal, setPaginaLocal] = useState(1);
  const [tamanhoLocal, setTamanhoLocal] = useState<number>((confExterna && (confExterna.pageSize ?? confExterna.defaultPageSize)) || TAMANHO_PAGINA_LEGADO);
  const [verTodosLocal, setVerTodosLocal] = useState(false);
  const totalLocal = actuais.length;
  // filtros novos → 1.ª página
  const chaveFiltros = JSON.stringify(estadoFiltros);
  useEffect(() => setPaginaLocal(1), [chaveFiltros]);
  useEffect(() => {
    if (!servidor && (paginaLocal - 1) * tamanhoLocal >= totalLocal && paginaLocal > 1) setPaginaLocal(1);
  }, [servidor, paginaLocal, tamanhoLocal, totalLocal]);

  let paginacao: TablePaginationConfig | false;
  if (servidor) {
    paginacao = comPaginacaoLegado
      ? paginacaoLegado({ current: servidor.pagina, pageSize: servidor.tamanho, total: servidor.verTodos ? filtradas.length : servidor.total, verTodos: servidor.verTodos, aoMudar: servidor.aoMudarPagina })
      : {
          current: servidor.pagina,
          pageSize: servidor.tamanho,
          total: servidor.total,
          showSizeChanger: true,
          pageSizeOptions: [10, 25, 50, 100],
          showTotal: (t: number) => `${t} registo(s)`,
          onChange: (p: number, s: number) => servidor.aoMudarPagina(p, s),
        };
  } else if (confExterna === false) {
    paginacao = false;
  } else if (!comPaginacaoLegado) {
    paginacao = confExterna;
  } else {
    const actual = confExterna.current ?? paginaLocal;
    const tamanho = confExterna.pageSize ?? tamanhoLocal;
    paginacao = paginacaoLegado({
      current: actual,
      pageSize: tamanho,
      total: totalLocal,
      verTodos: verTodosLocal,
      extra: { ...confExterna, total: undefined, showTotal: undefined },
      aoMudar: (p, s) => {
        if (s === 'todos') {
          setVerTodosLocal(true);
          setPaginaLocal(1);
          confExterna.onChange?.(1, Math.max(1, totalLocal));
          return;
        }
        setVerTodosLocal(false);
        setPaginaLocal(s !== tamanho ? 1 : p);
        setTamanhoLocal(s);
        confExterna.onChange?.(s !== tamanho ? 1 : p, s);
      },
    });
  }

  // ── selecção ───────────────────────────────────────────────────────────────────────────────────────────────
  const [chavesInternas, setChavesInternas] = useState<Key[]>([]);
  const cacheSeleccionadas = useRef(new Map<Key, T>());
  const chavesSel = (seleccao?.chaves ?? (rowSelectionExterna?.selectedRowKeys as Key[] | undefined) ?? chavesInternas) as Key[];
  const rowKey = resto.rowKey;
  const rowSelection: TableProps<T>['rowSelection'] = seleccao
    ? {
        ...rowSelectionExterna,
        preserveSelectedRowKeys: true,
        selectedRowKeys: chavesSel,
        getCheckboxProps: seleccao.bloquearLinha
          ? (l: T) => {
              const b = seleccao.bloquearLinha!(l);
              return { disabled: !!b, title: typeof b === 'string' ? b : undefined };
            }
          : rowSelectionExterna?.getCheckboxProps,
        onChange: (k, ls, info) => {
          ls.forEach((l, i) => l && cacheSeleccionadas.current.set(k[i], l));
          if (!seleccao.chaves) setChavesInternas(k);
          seleccao.aoMudar?.(k, ls.filter(Boolean));
          rowSelectionExterna?.onChange?.(k, ls, info);
        },
      }
    : rowSelectionExterna;
  const linhasSeleccionadas = useMemo(() => {
    const porChave = new Map(linhas.map((l, i) => [chaveLinhaDe(l, i, rowKey), l] as const));
    return chavesSel.map((k) => porChave.get(k) ?? cacheSeleccionadas.current.get(k)).filter((l): l is T => l !== undefined);
  }, [linhas, chavesSel, rowKey]);
  const limparSeleccao = () => {
    if (!seleccao?.chaves) setChavesInternas([]);
    seleccao?.aoMudar?.([], []);
    rowSelectionExterna?.onChange?.([], [], { type: 'none' });
  };

  // ── linha de filtros (por baixo dos cabeçalhos) ───────────────────────────────────────────────────────────
  const extrasInicio = (rowSelection ? 1 : 0) + (resto.expandable?.expandedRowRender && resto.expandable.showExpandColumn !== false ? 1 : 0);
  const algumaFiltravel = filtrosColuna && folhasVisiveis.some(colunaFiltravel);
  const linhaFiltros = algumaFiltravel ? (
    <tr className="erp-linha-filtros">
      {Array.from({ length: extrasInicio }, (_, i) => (
        <th key={`__extra${i}`} className="ant-table-cell" />
      ))}
      {folhasVisiveis.map((c) => {
        const chave = chavesFolhas.get(c) ?? '';
        return (
          <th key={chave} className="ant-table-cell" scope="col">
            {colunaFiltravel(c) ? <FiltroColuna titulo={tituloTexto(c)} valor={estadoFiltros.colunas[chave]} aoMudar={(v) => mudarFiltroColuna(chave, v)} /> : null}
          </th>
        );
      })}
    </tr>
  ) : null;
  const componentesTabela = useMemo<TableProps<T>['components']>(() => {
    if (components?.header?.wrapper) return components;
    return { ...components, header: { ...components?.header, wrapper: CabecalhoComFiltros } };
  }, [components]);

  // ── totais (G.3) ───────────────────────────────────────────────────────────────────────────────────────────
  const temTotais = rotuloTotal !== false && folhasVisiveis.some((c) => c.total);
  // como as DataTables ({ search: 'applied' }): soma das linhas filtradas (todas as páginas; na TabelaApi, a página
  // carregada — ou tudo com «Ver Todos»)
  const linhasTotais = actuais;
  const resumo: TableProps<T>['summary'] =
    temTotais || summary
      ? (dadosPagina) => (
          <>
            {temTotais && (
              <Table.Summary>
                <Table.Summary.Row className="erp-linha-total">
                  {Array.from({ length: extrasInicio }, (_, i) => (
                    <Table.Summary.Cell key={`__e${i}`} index={i}>
                      {i === 0 ? <strong>{rotuloTotal}</strong> : null}
                    </Table.Summary.Cell>
                  ))}
                  {folhasVisiveis.map((c, i) => {
                    const rotuloAqui = extrasInicio === 0 && i === 0 && !c.total;
                    return (
                      <Table.Summary.Cell key={chavesFolhas.get(c) ?? i} index={extrasInicio + i} align={c.align}>
                        {rotuloAqui ? <strong>{rotuloTotal}</strong> : c.total ? conteudoTotal(c, linhasTotais) : null}
                      </Table.Summary.Cell>
                    );
                  })}
                </Table.Summary.Row>
              </Table.Summary>
            )}
            {summary?.(dadosPagina)}
          </>
        )
      : undefined;

  // ── estado vazio ───────────────────────────────────────────────────────────────────────────────────────────
  const vazioNo: ReactNode =
    locale?.emptyText !== undefined && vazio === undefined
      ? (locale.emptyText as ReactNode)
      : vazio !== undefined && (isValidElement(vazio) || typeof vazio !== 'object' || vazio === null)
        ? (vazio as ReactNode)
        : comFiltroCliente && linhas.length > 0
          ? <EstadoVazio icone="filter" titulo="Nenhum registo corresponde aos filtros" texto="Altere ou limpe os filtros das colunas." />
          : <EstadoVazio {...((vazio as PropsEstadoVazio | undefined) ?? {})} />;

  // ── exportação ─────────────────────────────────────────────────────────────────────────────────────────────
  const fsBase = folhasBase;
  const obterPedido = async (): Promise<PedidoImpressao | null> => {
    if (obterPedidoExportacao) return obterPedidoExportacao(colunasVisiveis);
    if (!impressao) return null;
    await prepararTexto();
    const dados = [...actuais];
    const colunasImp = colunasParaImpressao(colunasVisiveis, dados);
    const temTotaisImp = colunasImp.some((c) => c.total !== undefined);
    const filtrosTexto = Array.isArray(impressao.filtros) ? impressao.filtros : [impressao.filtros];
    return {
      titulo: impressao.titulo,
      subtitulo: impressao.subtitulo,
      periodo: impressao.periodo,
      filtros: [...filtrosTexto, ...descreverFiltros(estadoFiltros, fsBase), `${dados.length.toLocaleString('pt-PT')} registo(s)`],
      orientacao: impressao.orientacao,
      papel: impressao.papel,
      nomeFicheiro: impressao.nomeFicheiro,
      conteudo: tabelaHtml({ colunas: colunasImp, linhas: dados, totais: temTotaisImp ? impressao.rotuloTotal ?? 'Total' : false }),
    };
  };
  const comExportacao = exportacao !== false && (!!impressao || !!obterPedidoExportacao);
  const excel = exportacao ? exportacao.excel ?? true : impressao?.excel ?? true;

  // ── selecção de colunas (Ctrl+clique alterna, Alt+clique estende até à última) → «Copiar colunas seleccionadas»,
  // o mesmo mecanismo do link «Copiar só algumas colunas…» de BotoesExportar, só que escolhido no próprio cabeçalho.
  const mensagemColunas = useMensagem();
  const [colunasSeleccionadas, setColunasSeleccionadas] = useState<Set<string>>(new Set());
  const ultimaColunaRef = useRef<string | null>(null);
  const colunasSeleccionaveis = useMemo(
    () => folhasVisiveis.filter((c) => !eColunaAccoes(c) && tituloTexto(c)).map((c, i) => chavesFolhas.get(c) ?? String(i)),
    [folhasVisiveis, chavesFolhas],
  );
  const aoClicarCabecalho = useCallback(
    (chave: string, e: EventoRato<HTMLElement>) => {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        setColunasSeleccionadas((s) => {
          const n = new Set(s);
          if (n.has(chave)) n.delete(chave);
          else n.add(chave);
          return n;
        });
        ultimaColunaRef.current = chave;
      } else if (e.altKey && ultimaColunaRef.current) {
        e.preventDefault();
        const i1 = colunasSeleccionaveis.indexOf(ultimaColunaRef.current);
        const i2 = colunasSeleccionaveis.indexOf(chave);
        if (i1 !== -1 && i2 !== -1) {
          const [de, ate] = i1 < i2 ? [i1, i2] : [i2, i1];
          setColunasSeleccionadas(new Set(colunasSeleccionaveis.slice(de, ate + 1)));
        }
        ultimaColunaRef.current = chave;
      }
    },
    [colunasSeleccionaveis],
  );
  const limparColunasSeleccionadas = () => {
    setColunasSeleccionadas(new Set());
    ultimaColunaRef.current = null;
  };
  const copiarColunasSeleccionadas = async () => {
    const pedido = await obterPedido();
    if (!pedido) return;
    const titulosEscolhidos = new Set(folhasVisiveis.filter((c, i) => colunasSeleccionadas.has(chavesFolhas.get(c) ?? String(i))).map((c) => tituloTexto(c)));
    const titulosTodos = colunasDoConteudo(pedido.conteudo);
    const indices = titulosTodos.map((t, i) => (titulosEscolhidos.has(t) ? i : -1)).filter((i) => i !== -1);
    if (!indices.length) {
      mensagemColunas.warning('Não foi encontrada nenhuma tabela para copiar.');
      return;
    }
    const texto = tabelaParaTexto(pedido.conteudo, indices);
    const ok = await copiarTexto(texto);
    mensagemColunas[ok ? 'success' : 'error'](ok ? `${indices.length} coluna(s) copiada(s). Já pode colar no Excel.` : 'Não foi possível copiar: o navegador bloqueou o acesso à área de transferência.');
  };
  useEffect(() => {
    if (!colunasSeleccionadas.size) return;
    const aoTeclar = (e: KeyboardEvent) => {
      const alvo = document.activeElement;
      const aEditar = alvo instanceof HTMLElement && (alvo.tagName === 'INPUT' || alvo.tagName === 'TEXTAREA' || alvo.isContentEditable);
      if (!aEditar && (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') void copiarColunasSeleccionadas();
    };
    document.addEventListener('keydown', aoTeclar);
    return () => document.removeEventListener('keydown', aoTeclar);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [colunasSeleccionadas]);
  const colunasComSeleccao = useMemo(
    () =>
      mapearFolhas(colunasVisiveis, (c) => {
        const chave = chavesFolhas.get(c) ?? '';
        if (!colunasSeleccionaveis.includes(chave)) return c;
        const activa = colunasSeleccionadas.has(chave);
        return {
          ...c,
          onHeaderCell: () => ({ className: activa ? 'erp-coluna-seleccionada' : undefined, onClick: (e: EventoRato<HTMLElement>) => aoClicarCabecalho(chave, e) }),
          onCell: (linha: T, indice?: number) => {
            const extra = (c.onCell?.(linha, indice) ?? {}) as HTMLAttributes<HTMLElement>;
            return { ...extra, className: [extra.className, activa ? 'erp-coluna-seleccionada' : ''].filter(Boolean).join(' ') || undefined };
          },
        } as Coluna<T>;
      }),
    [colunasVisiveis, chavesFolhas, colunasSeleccionaveis, colunasSeleccionadas, aoClicarCabecalho],
  );

  // ── contador ───────────────────────────────────────────────────────────────────────────────────────────────
  const fmt = (n: number) => n.toLocaleString('pt-PT');
  let textoContador: ReactNode = null;
  if (contador) {
    if (servidor && !servidor.verTodos) {
      textoContador = comFiltroCliente ? (
        <>
          {fmt(actuais.length)} registos encontrados
          <span className="erp-lista-contador-filtro">
            (filtro aplicado aos {fmt(linhas.length)} registos desta página, de {fmt(servidor.total)}; escolha «Ver Todos» para filtrar tudo)
          </span>
        </>
      ) : (
        <>{fmt(servidor.total)} registos encontrados</>
      );
    } else {
      const totalSemFiltros = linhas.length;
      textoContador = (
        <>
          {fmt(actuais.length)} registos encontrados
          {actuais.length !== totalSemFiltros && <span className="erp-lista-contador-filtro">(filtrado de {fmt(totalSemFiltros)} registos totais)</span>}
        </>
      );
    }
  }

  const colunasFiltroPersonalizado = useMemo(
    () => folhasBase.map((c, i) => ({ c, chave: chaveColuna(c, i), titulo: tituloTexto(c) })).filter(({ c }) => colunaFiltravel({ ...c, hidden: false })).map(({ chave, titulo }) => ({ chave, titulo })),
    [folhasBase],
  );

  const comCaixa = !!(textoContador || barra || comExportacao || menuColunas || filtroPersonalizado || extraDireita);
  const caixa = comCaixa ? (
    <div className="erp-lista-topo">
      <div className="erp-lista-contador" aria-live="polite">
        {textoContador}
      </div>
      <div className="erp-lista-botoes">
        {barra}
        {comExportacao && <BotoesExportarLegado tamanho={tamanhoBotoes} obterPedido={obterPedido} desactivado={servidor ? !servidor.total : !actuais.length} excel={excel} />}
        {menuColunas && opcoesMenuColunas.length > 0 && (
          <MenuColunas colunas={opcoesMenuColunas} ocultas={ocultas} aoMudar={mudarColuna} aoRepor={() => definir({ ocultas: [], mostradas: [] })} tamanho={tamanhoBotoes} />
        )}
        {filtroPersonalizado && colunasFiltroPersonalizado.length > 0 && (
          <BotaoFiltroPersonalizado colunas={colunasFiltroPersonalizado} valor={estadoFiltros.personalizado} aoMudar={mudarPersonalizado} tamanho={tamanhoBotoes} />
        )}
        {extraDireita}
      </div>
    </div>
  ) : null;

  const barraSeleccao = seleccao && !seleccao.semBarra ? (
    <BarraSeleccao linhas={linhasSeleccionadas} chaves={chavesSel} accoes={seleccao.accoes} editarEmMassa={seleccao.editarEmMassa} ajuda={seleccao.ajuda} aoLimpar={limparSeleccao} />
  ) : null;

  const barraColunas = colunasSeleccionadas.size > 0 ? (
    <div className="erp-barra-colunas-seleccionadas">
      <Space size={8} wrap>
        <Tag color="blue">{colunasSeleccionadas.size} coluna{colunasSeleccionadas.size > 1 ? 's' : ''} seleccionada{colunasSeleccionadas.size > 1 ? 's' : ''}</Tag>
        <Button size="small" type="primary" icon={<CopyOutlined />} onClick={() => void copiarColunasSeleccionadas()}>
          Copiar (Ctrl+C)
        </Button>
        <Button size="small" type="text" onClick={limparColunasSeleccionadas}>
          Limpar
        </Button>
        <span className="erp-barra-colunas-ajuda">Ctrl+clique no cabeçalho: alterna · Alt+clique: estende até à última</span>
      </Space>
    </div>
  ) : null;

  const conteudo = renderAlternativo ? (
    renderAlternativo({ linhas: servidor ? filtradas : actuais, colunas: colunasVisiveis, paginacao: paginacao, rowSelection, vazio: vazioNo })
  ) : (
    <CtxLinhaFiltros.Provider value={linhaFiltros}>
      <Table<T>
        {...resto}
        className={['erp-tabela-legado', resto.className].filter(Boolean).join(' ')}
        components={componentesTabela}
        columns={colunasComSeleccao as ColumnsType<T>}
        dataSource={filtradas}
        pagination={paginacao}
        onChange={aoMudarTabela}
        rowSelection={rowSelection}
        summary={resumo}
        locale={{ ...locale, emptyText: vazioNo }}
      />
    </CtxLinhaFiltros.Provider>
  );

  return (
    <>
      {caixa}
      {barraSeleccao}
      {barraColunas}
      {conteudo}
    </>
  );
}
