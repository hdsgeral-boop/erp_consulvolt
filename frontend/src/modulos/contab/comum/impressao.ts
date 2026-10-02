import { colunasParaImpressao, type ColunaApi } from '@/componentes/TabelaApi';
import { prepararTexto, tabelaHtml, type OpcoesTabela } from '@/componentes/impressao';
import { formatarData } from '@/utilitarios/formatacao';

/**
 * Apoio à impressão/PDF dos mapas contabilísticos com o motor comum (`@/componentes/impressao`):
 * texto do período e dos filtros a partir dos parâmetros do mapa, e HTML de tabelas do Ant Design já carregadas.
 */

const ROTULOS_OPCOES: Record<string, string> = {
  totalizadoras: 'Com totalizadoras',
  por_terceiro: 'Por terceiro',
  so_movimento: 'Só com movimento',
  sem_saldo_zero: 'Sem saldo zero',
  sem_saldo_inicial: 'Sem saldo inicial',
  excluir_estornos: 'Excluir estornos',
  incluir_classe_9: 'Inclui classe 9',
  incluir_apuramento: 'Inclui apuramento (período 13)',
};

const ROTULOS_IDS: Record<string, string> = {
  diario_id: 'Diário',
  centro_custo_id: 'Centro de custo',
  unidade_negocio_id: 'Unidade de negócio',
  terceiro_id: 'Terceiro',
};

/** Período legível dos parâmetros de um mapa (data_inicio/data_fim, só data_fim, ou ano). */
export function periodoDosParametros(p: Record<string, unknown> | null | undefined): string | undefined {
  if (!p) return undefined;
  const ini = p.data_inicio as string | undefined;
  const fim = p.data_fim as string | undefined;
  if (ini && fim) return `${formatarData(ini)} a ${formatarData(fim)}`;
  if (fim) return `Até ${formatarData(fim)}`;
  if (p.ano) return `Ano ${String(p.ano)}`;
  return undefined;
}

/**
 * Filtros legíveis dos parâmetros de um mapa (contas, nível, opções). Os filtros por identificador (diário, centro,
 * terceiro…) são indicados pelo rótulo do campo e, se for dado, pelo nome escolhido em `nomes`.
 */
export function filtrosDosParametros(p: Record<string, unknown> | null | undefined, nomes: Record<string, string | undefined> = {}): string[] {
  if (!p) return [];
  const r: string[] = [];
  if (p.codigo_conta) r.push(`Conta: ${String(p.codigo_conta)}`);
  if (p.filtro_contas) r.push(`Contas: ${String(p.filtro_contas)}`);
  if (p.nivel) r.push(`Nível: ${String(p.nivel)}`);
  for (const [k, rotulo] of Object.entries(ROTULOS_IDS)) if (p[k]) r.push(`${rotulo}: ${nomes[k] ?? `n.º ${String(p[k])}`}`);
  for (const [k, rotulo] of Object.entries(ROTULOS_OPCOES)) if (p[k]) r.push(rotulo);
  return r;
}

/**
 * HTML de impressão de uma tabela do Ant Design com os dados já carregados (texto dos `render`, sem botões nem
 * colunas sem título). Use `totalImpressao` nas colunas para a linha de totais.
 */
export async function tabelaDeColunas<T>(colunas: ColunaApi<T>[], linhas: T[], opcoes: Omit<OpcoesTabela<T>, 'colunas' | 'linhas'> = {}): Promise<string> {
  await prepararTexto();
  const cols = colunasParaImpressao(colunas, linhas);
  const temTotais = cols.some((c) => c.total !== undefined);
  return tabelaHtml({ colunas: cols, linhas, totais: temTotais ? 'Total' : false, ...opcoes });
}
