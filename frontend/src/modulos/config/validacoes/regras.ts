/**
 * Regras do ecrã «Validações de dados» (lacuna A-01, ADR-015): resumo por gravidade, colunas e formatação do
 * detalhe (as linhas vêm de consultas SQL diferentes por validação, com colunas variáveis) e a ligação de cada
 * linha ao ecrã onde o registo se corrige, quando esse ecrã existe e o utilizador o pode abrir.
 */
import { formatarData, formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';

export type Gravidade = 'ERRO' | 'AVISO' | 'INFO' | string;

/** GET /api/sistema/validacoes (ServicoValidacoesDados::resumo). */
export interface ResumoValidacao {
  codigo: string;
  titulo: string;
  modulo: string;
  gravidade: Gravidade;
  descricao: string;
  legado: string;
  ocorrencias: number;
}

/** GET /api/sistema/validacoes/{codigo} (até 500 linhas). */
export interface DetalheValidacao extends Omit<ResumoValidacao, 'ocorrencias'> {
  linhas: Record<string, unknown>[];
  total_mostrado: number;
}

export const LIMITE_DETALHE = 500;

export const ROTULO_GRAVIDADE: Record<string, string> = { ERRO: 'Erro', AVISO: 'Aviso', INFO: 'Informação' };
export const COR_GRAVIDADE: Record<string, string> = { ERRO: 'red', AVISO: 'orange', INFO: 'blue' };
const ORDEM_GRAVIDADE: Record<string, number> = { ERRO: 0, AVISO: 1, INFO: 2 };

/** Erros primeiro, depois avisos e informações; dentro de cada, as com mais ocorrências. */
export function ordenarValidacoes(lista: ResumoValidacao[]): ResumoValidacao[] {
  return [...lista].sort(
    (a, b) => (ORDEM_GRAVIDADE[a.gravidade] ?? 9) - (ORDEM_GRAVIDADE[b.gravidade] ?? 9) || b.ocorrencias - a.ocorrencias || a.titulo.localeCompare(b.titulo, 'pt'),
  );
}

export interface ResumoGravidades {
  /** Validações com ocorrências, por gravidade. */
  porGravidade: Record<string, number>;
  /** Total de ocorrências (linhas problemáticas). */
  ocorrencias: number;
  comOcorrencias: number;
  total: number;
}

export function resumirGravidades(lista: ResumoValidacao[]): ResumoGravidades {
  const porGravidade: Record<string, number> = { ERRO: 0, AVISO: 0, INFO: 0 };
  let ocorrencias = 0;
  let comOcorrencias = 0;
  for (const v of lista) {
    if (v.ocorrencias > 0) {
      porGravidade[v.gravidade] = (porGravidade[v.gravidade] ?? 0) + 1;
      comOcorrencias++;
      ocorrencias += v.ocorrencias;
    }
  }
  return { porGravidade, ocorrencias, comOcorrencias, total: lista.length };
}

export function filtrarValidacoes(lista: ResumoValidacao[], f: { gravidade?: string; modulo?: string; soComOcorrencias?: boolean; pesquisa?: string }): ResumoValidacao[] {
  const termo = normalizar(f.pesquisa ?? '');
  return lista.filter(
    (v) =>
      (!f.gravidade || v.gravidade === f.gravidade) &&
      (!f.modulo || v.modulo === f.modulo) &&
      (!f.soComOcorrencias || v.ocorrencias > 0) &&
      (!termo || normalizar(`${v.titulo} ${v.descricao} ${v.codigo} ${v.legado}`).includes(termo)),
  );
}

const normalizar = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

/** União das colunas das linhas, pela ordem em que aparecem (as consultas devolvem colunas diferentes). */
export function colunasDetalhe(linhas: Record<string, unknown>[]): string[] {
  const vistas: string[] = [];
  for (const l of linhas) for (const k of Object.keys(l)) if (!vistas.includes(k)) vistas.push(k);
  return vistas;
}

const ROTULOS: Record<string, string> = {
  codigo_conta: 'Conta', numero_lan: 'N.º lançamento', numero_documento: 'N.º documento', data_documento: 'Data', tipo_dc: 'D/C',
  diario: 'Diário', lancamento: 'Lançamento', diferenca: 'Diferença', linha_id: 'Linha', mes_ano: 'Período', calculado: 'Calculado',
  quantidade_stock: 'Quantidade', codigo_sessao: 'Sessão', codigo_terminal: 'Terminal', nome_operador: 'Operador', aberto_em: 'Aberta em',
  fechado_em: 'Fechada em', total_vendas: 'Total de vendas', numero_z: 'N.º Z', nif: 'NIF', iban: 'IBAN', descricao: 'Descrição',
  saida_prevista_em: 'Saída prevista', entrada_em: 'Entrada', nome_quarto: 'Quarto', valor_sistema: 'Valor do sistema', valor_talao: 'Valor do talão',
};

export function rotuloColuna(chave: string): string {
  if (ROTULOS[chave]) return ROTULOS[chave];
  const t = chave.replace(/_id$/, '').replace(/_/g, ' ');
  return t.charAt(0).toUpperCase() + t.slice(1);
}

const MONETARIAS = /(valor|saldo|diferenca|total|calculado|diario|custo|preco|desvio|montante|debito|credito)/;

/** Texto de uma célula do detalhe (valores monetários em Kz, datas no formato português, booleanos em Sim/Não). */
export function formatarCelula(chave: string, valor: unknown): string {
  if (valor === null || valor === undefined || valor === '') return '—';
  if (typeof valor === 'boolean') return valor ? 'Sim' : 'Não';
  if (typeof valor === 'object') return JSON.stringify(valor);
  const s = String(valor);
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return formatarData(s);
  if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(s)) return formatarDataHora(s);
  if (/^-?\d+(\.\d+)?$/.test(s) && !CHAVE_NAO_NUMERICA.test(chave)) {
    return MONETARIAS.test(chave) || /^-?\d+\.\d{2}$/.test(s) ? formatarKz(s) : formatarNumero(s);
  }
  return s;
}

/** Colunas numéricas (quantias e quantidades) alinham à direita; ids, códigos, números de documento e NIF não. */
export function alinharDireita(chave: string, valor: unknown): boolean {
  return /^-?\d+(\.\d+)?$/.test(String(valor ?? '')) && !CHAVE_NAO_NUMERICA.test(chave);
}

/** «diario» fica de fora: quando é numérico é uma quantia (folhas de salários vs diário); quando é o código do diário não é número. */
const CHAVE_NAO_NUMERICA = /_id$|^id$|^numero|codigo|^ano$|nif|mes|lancamento|terminal|sessao/;

/** Destino de uma ligação: ecrã do catálogo (/m/{modulo}/{ecra}/{id}). */
export interface Ligacao {
  modulo: string;
  ecra: string;
  coluna: string;
  rotulo: string;
}

/** Validações cuja coluna `linha_id` é uma linha de lançamento contabilístico (noutras é outra tabela). */
const LINHA_E_LANCAMENTO = new Set([
  'lancamentos_desequilibrados', 'lancamentos_em_contas_totalizadoras', 'lancamentos_conta_inexistente', 'lancamentos_valor_zero',
  'linhas_sem_nota_demonstracao', 'notas_demonstracao_fora_da_estrutura', 'texto_corrompido_lancamentos',
]);

/**
 * Ecrã onde o registo de uma validação se abre: só os que têm detalhe por id (rota «:id») e só quando a coluna
 * identifica mesmo esse registo.
 */
export function ligacaoDaValidacao(codigo: string, colunas: string[]): Ligacao | null {
  const tem = (c: string) => colunas.includes(c);
  if (tem('linha_id') && LINHA_E_LANCAMENTO.has(codigo)) return { modulo: 'contab', ecra: 'lancamentos', coluna: 'linha_id', rotulo: 'Abrir lançamento' };
  if (tem('venda_id')) return { modulo: 'vendas', ecra: 'vendas_faturacao', coluna: 'venda_id', rotulo: 'Abrir documento' };
  if (tem('colaborador_id')) return { modulo: 'rh', ecra: 'colaboradores', coluna: 'colaborador_id', rotulo: 'Abrir colaborador' };
  if (tem('ativo_id')) return { modulo: 'activos', ecra: 'activos', coluna: 'ativo_id', rotulo: 'Abrir activo' };
  if (tem('periodo_id') && codigo === 'folhas_salariais_vs_diario') return { modulo: 'rh', ecra: 'processamento', coluna: 'periodo_id', rotulo: 'Abrir processamento' };
  if (tem('item_id') && codigo.startsWith('ad_')) return { modulo: 'acrescimos', ecra: 'ad_registos', coluna: 'item_id', rotulo: 'Abrir registo' };
  return null;
}

/** Rota da ligação para uma linha (null se a linha não tiver o id). */
export function rotaDaLinha(ligacao: Ligacao | null, linha: Record<string, unknown>): string | null {
  if (!ligacao) return null;
  const id = linha[ligacao.coluna];
  if (id === null || id === undefined || id === '' || !/^\d+$/.test(String(id))) return null;
  return `/m/${ligacao.modulo}/${ligacao.ecra}/${id}`;
}
