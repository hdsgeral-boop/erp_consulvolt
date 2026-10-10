import type { TableProps } from 'antd';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useState, type ReactNode } from 'react';
import { obterPagina } from '@/api/cliente';
import type { Pagina } from '@/api/tipos';
import { notificarErro } from '@/utilitarios/erros';
import { useOperacoes } from './operacoes/Operacoes';
import { tabelaHtml } from './impressao/tabela';
import { prepararTexto } from './impressao/texto';
import type { Orientacao, Papel } from './impressao/tipos';
import { useMensagem, type PedidoImpressao } from './impressao/useImpressao';
import { AlternarVista } from './vistas/AlternarVista';
import { colunasParaImpressao, type ColunaLegado } from './vistas/colunasTabela';
import { GradeCartoes } from './vistas/GradeCartoes';
import { chaveEcraVista, useModoVista } from './vistas/preferenciaVista';
import type { PropsVistaLista } from './vistas/TabelaComModos';
import { TabelaLegado, type PropsPadroesLista } from './vistas/TabelaLegado';

export { brutoConsistente, colunasParaImpressao, type ExtrasColunaImpressao } from './vistas/colunasTabela';

/** Coluna das listas: ColumnType do Ant Design + impressão + cartão da grade + padrão do antigo (total, filtros…). */
export type ColunaApi<T> = ColunaLegado<T>;

export interface ImpressaoTabelaApi {
  titulo: string;
  subtitulo?: string;
  periodo?: string;
  /** Filtros a mostrar no cabeçalho (ex.: ['Estado: Activo', 'Armazém: Central']). */
  filtros?: string | (string | null | undefined | false)[];
  orientacao?: Orientacao;
  papel?: Papel;
  nomeFicheiro?: string;
  /** Máximo de linhas a imprimir (por omissão 5 000). */
  limite?: number;
  /** Rótulo da linha de totais (quando alguma coluna tem `totalImpressao`). */
  rotuloTotal?: string;
  /** Mostra também «Excel» (por omissão: sim) — o mesmo conteúdo da impressão em .xlsx. */
  excel?: boolean;
}

/**
 * Props da TabelaApi. Os modos de vista («Linhas»/«Grade», `PropsVistaLista`) estão ligados por omissão: a chave da
 * preferência é o caminho do ecrã + `idVista` (por omissão, o `url`); `modos={false}` deixa só as linhas.
 */
interface Props<T> extends Omit<TableProps<T>, 'dataSource' | 'pagination' | 'loading' | 'columns'>, PropsVistaLista<T>, Omit<PropsPadroesLista<T>, 'impressao'> {
  /** Caminho da API paginada (RespostaApi::paginado). */
  url: string;
  /** Filtros enviados como parâmetros; mudam a consulta e voltam à 1.ª página. */
  filtros?: Record<string, unknown>;
  chaveConsulta: unknown[];
  porPagina?: number;
  columns?: ColunaApi<T>[];
  /** Mostra «Imprimir» e «PDF»: imprime TODAS as páginas do endpoint (com os mesmos filtros) com o motor comum. */
  impressao?: ImpressaoTabelaApi;
  /** Acções extra na barra da tabela (à esquerda dos botões de impressão). */
  barra?: ReactNode;
}

export const LIMITE_IMPRESSAO = 5000;
const POR_PAGINA_IMPRESSAO = 200;

/**
 * Lê todas as páginas de uma listagem paginada (até `limite` linhas), com os mesmos filtros. `aoProgresso` (opcional)
 * recebe a percentagem e o texto «Página X de Y» (gestor de operações em segundo plano).
 */
export async function obterTodasAsPaginas<T>(
  url: string,
  filtros: Record<string, unknown>,
  limite = LIMITE_IMPRESSAO,
  aoProgresso?: (percentagem: number, detalhe: string) => void,
): Promise<{ itens: T[]; total: number; truncado: boolean }> {
  const itens: T[] = [];
  let pagina = 1;
  let total = 0;
  for (;;) {
    const r = await obterPagina<T>(url, { ...filtros, pagina, por_pagina: POR_PAGINA_IMPRESSAO });
    itens.push(...r.itens);
    total = r.paginacao.total;
    const ultima = Math.min(r.paginacao.ultima_pagina, Math.ceil(limite / POR_PAGINA_IMPRESSAO));
    aoProgresso?.((pagina / Math.max(1, ultima)) * 100, `Página ${pagina} de ${Math.max(1, ultima)}`);
    if (!r.itens.length || pagina >= r.paginacao.ultima_pagina || itens.length >= limite) break;
    pagina += 1;
  }
  return { itens: itens.slice(0, limite), total: Math.max(total, itens.length), truncado: total > limite || itens.length > limite };
}

/**
 * Tabela ligada a uma listagem paginada do servidor (pagina/por_pagina), com a paginação da API e o padrão das listas
 * do antigo (`PropsPadroesLista`): «N registos encontrados» (total do servidor), Excel · PDF · Imprimir (todas as
 * páginas, quando há `impressao`), «Colunas ▾», «Filtro personalizado», filtros por coluna, «TOTAL:», «Mostrar [N]
 * registos» (com «Ver Todos»), coluna «AÇÕES» e selecção múltipla.
 *
 * Filtros por coluna: o servidor não tem um filtro genérico por coluna, por isso filtram a PÁGINA carregada (o contador
 * avisa-o). Uma coluna com `filtroServidor: 'parametro'` envia o texto ao endpoint (e volta à 1.ª página). Com «Ver
 * Todos» a tabela carrega todas as páginas (até 5 000 registos) e os filtros passam a valer para tudo.
 */
export function TabelaApi<T extends object>({
  url,
  filtros = {},
  chaveConsulta,
  porPagina = 50,
  impressao,
  barra,
  columns,
  scroll,
  idVista,
  modos = true,
  modoOmissao,
  cartao,
  vista: vistaExterna,
  rotulo,
  ...props
}: Props<T>) {
  const chaveVista = idVista ?? url.split('?')[0].replace(/^\/+/, '');
  const vistaInterna = useModoVista(chaveVista, { omissao: modoOmissao });
  const vista = vistaExterna ?? vistaInterna;
  const emGrade = modos && vista.modo === 'grade';
  const [pagina, setPagina] = useState(1);
  const [tamanho, setTamanho] = useState(porPagina);
  const [verTodos, setVerTodos] = useState(false);
  const [filtrosColunas, setFiltrosColunas] = useState<Record<string, string>>({});
  const mensagem = useMensagem();
  const operacoes = useOperacoes();
  const parametros = { ...filtros, ...filtrosColunas };
  const consulta = useQuery({
    queryKey: [...chaveConsulta, parametros, verTodos ? 'todos' : pagina, verTodos ? 'todos' : tamanho],
    queryFn: async (): Promise<Pagina<T>> => {
      if (!verTodos) return obterPagina<T>(url, { ...parametros, pagina, por_pagina: tamanho });
      const r = await obterTodasAsPaginas<T>(url, parametros, LIMITE_IMPRESSAO);
      if (r.truncado) mensagem.warning({ content: `«Ver Todos» mostra só os primeiros ${LIMITE_IMPRESSAO.toLocaleString('pt-PT')} de ${r.total.toLocaleString('pt-PT')} registos. Use os filtros para reduzir a listagem.`, duration: 8 });
      return { itens: r.itens, paginacao: { pagina_atual: 1, por_pagina: Math.max(1, r.itens.length), total: r.total, ultima_pagina: 1 } };
    },
    placeholderData: keepPreviousData,
  });
  const chaveFiltros = JSON.stringify(parametros);
  useEffect(() => setPagina(1), [chaveFiltros]);
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar a listagem');
  }, [consulta.error]);

  const obterPedido = async (colunasVisiveis: ColunaApi<T>[]): Promise<PedidoImpressao | null> => {
    if (!impressao) return null;
    const limite = impressao.limite ?? LIMITE_IMPRESSAO;
    let dados: { itens: T[]; total: number; truncado: boolean };
    try {
      // listagens com várias páginas: a recolha aparece no gestor de operações (progresso «Página X de Y»)
      const recolher =
        (consulta.data?.paginacao.total ?? 0) > POR_PAGINA_IMPRESSAO
          ? operacoes.executar(`A recolher «${impressao.titulo}»`, (progresso) => obterTodasAsPaginas<T>(url, parametros, limite, progresso))
          : obterTodasAsPaginas<T>(url, parametros, limite);
      [dados] = await Promise.all([recolher, prepararTexto()]);
    } catch (e) {
      notificarErro(e, 'Não foi possível obter os dados para imprimir');
      return null;
    }
    const fmt = (n: number) => n.toLocaleString('pt-PT');
    if (dados.truncado) mensagem.warning({ content: `A impressão inclui só os primeiros ${fmt(limite)} de ${fmt(dados.total)} registos. Use os filtros para reduzir a listagem.`, duration: 8 });
    const colunas = colunasParaImpressao(colunasVisiveis, dados.itens);
    const temTotais = colunas.some((c) => c.total !== undefined);
    const filtrosTexto = Array.isArray(impressao.filtros) ? impressao.filtros : [impressao.filtros];
    return {
      titulo: impressao.titulo,
      subtitulo: impressao.subtitulo,
      periodo: impressao.periodo,
      filtros: [...filtrosTexto, `${fmt(dados.itens.length)} registo(s)${dados.truncado ? ` de ${fmt(dados.total)}` : ''}`],
      orientacao: impressao.orientacao,
      papel: impressao.papel,
      nomeFicheiro: impressao.nomeFicheiro,
      conteudo: tabelaHtml({ colunas, linhas: dados.itens, totais: temTotais ? impressao.rotuloTotal ?? 'Total' : false }),
    };
  };

  const rowKey = props.rowKey ?? ((r: T) => String((r as { id?: number | string }).id ?? JSON.stringify(r)));
  const comAlternar = modos && !vistaExterna;
  return (
    <TabelaLegado<T>
      size="middle"
      {...(props as Omit<TableProps<T>, 'columns'> & PropsPadroesLista<T>)}
      rowKey={rowKey}
      // Largura natural das colunas com deslocação horizontal dentro da tabela: nunca alarga a página.
      scroll={{ x: 'max-content', ...scroll }}
      columns={columns}
      loading={consulta.isFetching}
      dataSource={consulta.data?.itens}
      chaveLista={chaveEcraVista(chaveVista)}
      barra={barra}
      extraDireita={comAlternar ? <AlternarVista vista={vista} /> : undefined}
      obterPedidoExportacao={impressao ? obterPedido : undefined}
      exportacao={impressao ? { excel: impressao.excel ?? true } : false}
      servidor={{
        total: consulta.data?.paginacao.total ?? 0,
        pagina: consulta.data?.paginacao.pagina_atual ?? pagina,
        tamanho,
        verTodos,
        aoMudarPagina: (p, s) => {
          if (s === 'todos') {
            setVerTodos(true);
            setPagina(1);
            return;
          }
          setVerTodos(false);
          setPagina(s !== tamanho ? 1 : p);
          setTamanho(s);
        },
        aoMudarFiltrosServidor: setFiltrosColunas,
      }}
      renderAlternativo={
        emGrade
          ? ({ linhas, colunas, paginacao, rowSelection, vazio }) => (
              <GradeCartoes<T>
                linhas={linhas}
                colunas={colunas.filter((c) => !c.hidden)}
                rowKey={rowKey}
                carregando={consulta.isFetching}
                paginacao={paginacao}
                paginacaoServidor
                onRow={props.onRow}
                rowSelection={rowSelection}
                cartao={cartao}
                vazio={vazio}
                rotulo={rotulo ?? impressao?.titulo}
              />
            )
          : undefined
      }
    />
  );
}
