import { Flex, Table, type TableProps } from 'antd';
import type { ColumnGroupType, ColumnType, ColumnsType } from 'antd/es/table';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useState, type ReactNode } from 'react';
import { obterPagina } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { BotoesExportar } from './impressao/BotoesExportar';
import { lerNumeroPt } from './impressao/excel';
import { useOperacoes } from './operacoes/Operacoes';
import { tabelaHtml, type ColunaImpressao, type ValorCelula } from './impressao/tabela';
import { prepararTexto, textoDeNo } from './impressao/texto';
import type { Orientacao, Papel } from './impressao/tipos';
import { useMensagem, type PedidoImpressao } from './impressao/useImpressao';

/** Extras por coluna para a impressão/PDF (opcionais; o resto é o ColumnType do Ant Design). */
export interface ExtrasColunaImpressao<T> {
  /** `false` = não vai para o papel (por omissão saem todas as colunas com título). */
  exportar?: boolean;
  /** Texto/valor a imprimir (por omissão: o texto do `render`, ou o valor de `dataIndex`). */
  valorImpressao?: (linha: T, indice: number) => ValorCelula;
  /** Total da coluna no rodapé do documento impresso (recebe todas as linhas impressas). */
  totalImpressao?: (linhas: T[]) => string;
}

export type ColunaApi<T> = ColumnsType<T>[number] & ExtrasColunaImpressao<T>;

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

interface Props<T> extends Omit<TableProps<T>, 'dataSource' | 'pagination' | 'loading' | 'columns'> {
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

function folhas<T>(colunas: ColunaApi<T>[]): ColunaApi<T>[] {
  return colunas.flatMap((c) => {
    const filhos = (c as ColumnGroupType<T>).children;
    return filhos?.length ? folhas(filhos as ColunaApi<T>[]) : [c];
  });
}

function valorDe<T>(linha: T, dataIndex: ColumnType<T>['dataIndex']): unknown {
  if (dataIndex === undefined || dataIndex === null) return undefined;
  const caminho = Array.isArray(dataIndex) ? dataIndex : [dataIndex];
  return caminho.reduce<unknown>((v, k) => (v && typeof v === 'object' ? (v as Record<string | number, unknown>)[k as string | number] : undefined), linha);
}

/**
 * Colunas visíveis do Ant Design → colunas de impressão (texto simples dos `render`). Saem as colunas com título
 * (as de acções, sem título, ficam de fora) e as marcadas `exportar: true`; `linhas` calcula os `totalImpressao`.
 */
export function colunasParaImpressao<T>(colunas: ColunaApi<T>[], linhas?: T[]): ColunaImpressao<T>[] {
  return folhas(colunas)
    .map((c) => ({ c: c as ColumnType<T> & ExtrasColunaImpressao<T>, titulo: textoDeNo(typeof c.title === 'function' ? null : (c.title as ReactNode)) }))
    .filter(({ c, titulo }) => c.exportar !== false && !c.hidden && (titulo || c.exportar === true))
    .map(({ c, titulo }) => ({
      titulo,
      alinhamento: c.align === 'right' ? 'direita' : c.align === 'center' ? 'centro' : 'esquerda',
      total: c.totalImpressao && linhas ? c.totalImpressao(linhas) : undefined,
      valor: (linha: T, i: number): ValorCelula => textoColuna(c, linha, i),
      // Excel: o valor bruto (precisão total) só quando o texto impresso o representa (nunca um id por trás de um nome)
      bruto: (linha: T, i: number): ValorCelula => brutoConsistente(valorDe(linha, c.dataIndex), textoColuna(c, linha, i)),
    }));
}

function textoColuna<T>(c: ColumnType<T> & ExtrasColunaImpressao<T>, linha: T, i: number): ValorCelula {
  if (c.valorImpressao) return c.valorImpressao(linha, i);
  const bruto = valorDe(linha, c.dataIndex);
  if (!c.render) return bruto === null || bruto === undefined ? '' : typeof bruto === 'object' ? JSON.stringify(bruto) : (bruto as ValorCelula);
  const r = c.render(bruto, linha, i) as ReactNode | { children?: ReactNode };
  // render pode devolver { children, props } (RenderedCell).
  const no = r && typeof r === 'object' && !Array.isArray(r) && !('$$typeof' in r) && 'children' in r ? r.children : (r as ReactNode);
  return textoDeNo(no);
}

/**
 * Valor bruto de uma célula para o Excel, se for coerente com o texto mostrado: número (ou texto numérico da API, ex.
 * «1234.50») cujo texto formatado lê o mesmo valor, ou data ISO mostrada como dd/mm/aaaa. Caso contrário `undefined`
 * (o Excel lê o texto).
 */
export function brutoConsistente(bruto: unknown, texto: ValorCelula): ValorCelula {
  if (texto === null || texto === undefined || typeof texto === 'boolean') return undefined;
  const t = String(texto).trim();
  if ((typeof bruto === 'number' && Number.isFinite(bruto)) || (typeof bruto === 'string' && /^-?\d+(\.\d+)?$/.test(bruto.trim()))) {
    if (/%\s*$/.test(t)) return undefined;
    // inteiros sem separadores (NIF, códigos, n.º de documento) ficam com a regra do alinhamento do excel.ts
    const lido = lerNumeroPt(t, false);
    if (!lido) return undefined;
    const n = Number(bruto);
    return Math.abs(lido.valor - n) <= 0.5 * 10 ** -lido.casas + 1e-9 ? (typeof bruto === 'string' ? bruto.trim() : n) : undefined;
  }
  if (typeof bruto === 'string' && /^\d{4}-\d{2}-\d{2}/.test(bruto)) {
    const [a, m, d] = bruto.slice(0, 10).split('-');
    return t.startsWith(`${d}/${m}/${a}`) ? bruto : undefined;
  }
  return undefined;
}

/** Tabela ligada a uma listagem paginada do servidor (pagina/por_pagina), com a paginação da API. */
export function TabelaApi<T extends object>({ url, filtros = {}, chaveConsulta, porPagina = 25, impressao, barra, columns, scroll, ...props }: Props<T>) {
  const [pagina, setPagina] = useState(1);
  const [tamanho, setTamanho] = useState(porPagina);
  const mensagem = useMensagem();
  const operacoes = useOperacoes();
  const consulta = useQuery({
    queryKey: [...chaveConsulta, filtros, pagina, tamanho],
    queryFn: () => obterPagina<T>(url, { ...filtros, pagina, por_pagina: tamanho }),
    placeholderData: keepPreviousData,
  });
  const chaveFiltros = JSON.stringify(filtros);
  useEffect(() => setPagina(1), [chaveFiltros]);
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar a listagem');
  }, [consulta.error]);

  const obterPedido = async (): Promise<PedidoImpressao | null> => {
    if (!impressao) return null;
    const limite = impressao.limite ?? LIMITE_IMPRESSAO;
    let dados: { itens: T[]; total: number; truncado: boolean };
    try {
      // listagens com várias páginas: a recolha aparece no gestor de operações (progresso «Página X de Y»)
      const recolher =
        (consulta.data?.paginacao.total ?? 0) > POR_PAGINA_IMPRESSAO
          ? operacoes.executar(`A recolher «${impressao.titulo}»`, (progresso) => obterTodasAsPaginas<T>(url, filtros, limite, progresso))
          : obterTodasAsPaginas<T>(url, filtros, limite);
      [dados] = await Promise.all([recolher, prepararTexto()]);
    } catch (e) {
      notificarErro(e, 'Não foi possível obter os dados para imprimir');
      return null;
    }
    const fmt = (n: number) => n.toLocaleString('pt-PT');
    if (dados.truncado) mensagem.warning({ content: `A impressão inclui só os primeiros ${fmt(limite)} de ${fmt(dados.total)} registos. Use os filtros para reduzir a listagem.`, duration: 8 });
    const colunas = colunasParaImpressao(columns ?? [], dados.itens);
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

  const tabela = (
    <Table<T>
      rowKey={(r) => String((r as { id?: number | string }).id ?? JSON.stringify(r))}
      size="middle"
      {...props}
      // Largura natural das colunas com deslocação horizontal dentro da tabela: nunca alarga a página.
      scroll={{ x: 'max-content', ...scroll }}
      columns={columns as ColumnsType<T> | undefined}
      loading={consulta.isFetching}
      dataSource={consulta.data?.itens}
      pagination={{
        current: consulta.data?.paginacao.pagina_atual ?? pagina,
        pageSize: tamanho,
        total: consulta.data?.paginacao.total ?? 0,
        showSizeChanger: true,
        pageSizeOptions: [10, 25, 50, 100],
        showTotal: (t) => `${t} registo(s)`,
        onChange: (p, s) => {
          setPagina(s !== tamanho ? 1 : p);
          setTamanho(s);
        },
      }}
    />
  );

  if (!impressao && !barra) return tabela;
  return (
    <>
      <Flex justify="flex-end" align="center" gap={8} wrap style={{ marginBottom: 8 }}>
        {barra}
        {impressao && <BotoesExportar tamanho="small" obterPedido={obterPedido} desactivado={!consulta.data?.paginacao.total} excel={impressao.excel ?? true} />}
      </Flex>
      {tabela}
    </>
  );
}
