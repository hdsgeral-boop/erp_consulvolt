/**
 * Apoio à impressão/PDF das fichas e folhas do RH com o motor comum (`@/componentes/impressao`).
 * O logótipo e o nome da empresa são postos pelo motor — aqui só se monta o conteúdo.
 */
import { esc, prepararTexto, tabelaHtml, type ColunaImpressao, type PedidoImpressao } from '@/componentes/impressao';
import { colunasParaImpressao, type ColunaApi, type ExtrasColunaImpressao } from '@/componentes/TabelaApi';
import { formatarNumero } from '@/utilitarios/formatacao';
import type { ResultadoSalarial } from '../api';

/** Secção com título (fichas com várias partes: dados, agregado, contratos…). */
export function seccaoHtml(titulo: string, html: string): string {
  return `<section><h3 style="font-size:10.5pt;margin:4mm 0 1.5mm">${esc(titulo)}</h3>${html}</section>`;
}

/** Colunas da folha de salários (resultados por colaborador). */
export function colunasFolhaSalarios(nome: (r: ResultadoSalarial) => string): ColunaImpressao<ResultadoSalarial>[] {
  return [
    { titulo: '#', valor: (_, i) => i + 1, formato: 'inteiro', alinhamento: 'direita' },
    { titulo: 'Colaborador', valor: (r) => `${nome(r)}${r.avencado ? ' (avençado)' : ''}${r.reformado ? ' (reformado)' : ''}` },
    { titulo: 'NIF', valor: (r) => r.nif ?? '' },
    { titulo: 'Dias', valor: (r) => `${formatarNumero(r.dias_trabalhados)}/${formatarNumero(r.dias_contrato)}`, alinhamento: 'centro' },
    { titulo: 'Bruto', valor: (r) => r.bruto, formato: 'moeda', somar: true },
    { titulo: 'INSS (trab.)', valor: (r) => r.inss_trabalhador, formato: 'moeda', somar: true },
    { titulo: 'INSS (empresa)', valor: (r) => r.inss_patronal, formato: 'moeda', somar: true },
    { titulo: 'IRT', valor: (r) => r.irt, formato: 'moeda', somar: true },
    { titulo: 'Descontos', valor: (r) => r.descontos, formato: 'moeda', somar: true },
    { titulo: 'Líquido', valor: (r) => r.liquido, formato: 'moeda', somar: true },
  ];
}

/** Folha de salários do período (ordenada por nome, com totais): Calcular e Processamento. */
export function folhaSalariosHtml(resultados: ResultadoSalarial[], nome: (r: ResultadoSalarial) => string): string {
  const linhas = [...resultados].sort((a, b) => nome(a).localeCompare(nome(b), 'pt'));
  return tabelaHtml({ colunas: colunasFolhaSalarios(nome), linhas, totais: `Totais (${linhas.length})` });
}

/**
 * Pedido de impressão de uma tabela já carregada no ecrã (listas não paginadas pelo servidor): as colunas do
 * Ant Design passam a texto com `colunasParaImpressao` (o texto do `render`; colunas sem título ficam de fora).
 */
export async function pedidoTabela<T>(o: Omit<PedidoImpressao, 'conteudo'> & { colunas: ColunaApi<T>[]; linhas: T[]; totais?: boolean | string; antes?: string }): Promise<PedidoImpressao> {
  const { colunas, linhas, totais, antes, ...resto } = o;
  await prepararTexto();
  const temTotais = colunas.some((c) => (c as ExtrasColunaImpressao<T>).totalImpressao);
  return { ...resto, conteudo: (antes ?? '') + tabelaHtml({ colunas: colunasParaImpressao(colunas, linhas), linhas, totais: temTotais ? totais ?? 'Total' : false }) };
}

/** HTML de uma tabela do ecrã (colunas Ant Design → texto), para documentos com várias secções. */
export async function htmlTabela<T>(colunas: ColunaApi<T>[], linhas: T[], totais?: boolean | string): Promise<string> {
  await prepararTexto();
  return tabelaHtml({ colunas: colunasParaImpressao(colunas, linhas), linhas, totais: totais ?? false });
}
