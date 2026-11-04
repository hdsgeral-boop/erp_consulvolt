import type { Proposta } from '@/modulos/geral/integracoes/AssistenteIA';

/**
 * Assistente IA › Regras em ficheiro (Versão Actual, js/ia_regras_ficheiro.js): tipos da API
 * /contabilidade/assistente/ficheiros-regras e funções puras do ecrã `ia_assistente`. O cálculo é sempre feito no
 * servidor (o mesmo para o motor interno e para a IA externa); aqui só se montam os pedidos e se mostram os resultados.
 */
export const URL_REGRAS = '/contabilidade/assistente/ficheiros-regras';
export const CHAVE_REGRAS = ['contabilidade', 'assistente', 'ficheiros-regras'];

export type Motor = 'AMBOS' | 'INTERNA' | 'EXTERNA';

export const ROTULO_MOTOR: Record<Motor, string> = { AMBOS: 'Ambos os motores', INTERNA: 'Só motor interno', EXTERNA: 'Só IA externa' };

export interface FicheiroRegra {
  id: number;
  nome: string;
  nome_regra: string;
  ficheiro: string | null;
  ativo: boolean;
  motor: Motor;
  versao: number;
  importado_em: string | null;
  importado_por: string | null;
  problema?: string | null;
  descricao?: string | null;
  unidade?: string | null;
  linhas?: number;
  diario?: string | null;
  contas_em_falta?: string[];
  contas_totalizadoras?: string[];
  avisos?: string[];
}

export interface Entrada {
  nome: string;
  rotulo: string;
  ajuda: string;
}

export interface DetalheRegra extends FicheiroRegra {
  documento: string | null;
  por_dia: boolean;
  colunas: string[];
  entradas: Entrada[];
  calculadas: string[];
  conteudo: Record<string, unknown>;
}

export interface LinhaCalculada {
  codigo_conta: string;
  tipo_dc: 'D' | 'C';
  valor: number;
  descricao: string;
  nota_demonstracao: string | null;
  nota_fluxo: string | null;
}

export interface Validacao {
  texto: string;
  ok: boolean | null;
  detalhe: string;
}

export interface ResultadoDia {
  data: string;
  referencia: string;
  valores: Record<string, number>;
  calculados: Record<string, number>;
  linhas: LinhaCalculada[];
  total_debito: string;
  total_credito: string;
  validacoes: Validacao[];
  erros: string[];
  pontos: string[];
  proposta: (Omit<Proposta, 'origem'> & { origem: string }) | null;
}

export interface ResultadoCalculo {
  regra: FicheiroRegra;
  motor: 'INTERNA' | 'EXTERNA';
  dias: ResultadoDia[];
  propostas_validas: number;
}

export interface EstadoIA {
  ativo: boolean;
  configurado: boolean;
  ia_disponivel: boolean;
}

/** Um dia do formulário do motor interno. */
export interface DiaFormulario {
  data: string;
  valores: Record<string, number | null>;
  outroMetodo: string;
  outroValor: number | null;
  ilegivel: string;
  netImpresso: number | null;
}

export const novoDia = (data: string): DiaFormulario => ({ data, valores: {}, outroMetodo: '', outroValor: null, ilegivel: '', netImpresso: null });

/** Corpo de POST …/{id}/calcular a partir dos dias do formulário (só os valores preenchidos; vazio = 0 no servidor). */
export function pedidoCalculo(dias: DiaFormulario[], entradas: Entrada[]) {
  return {
    dias: dias.map((d) => ({
      data: d.data,
      valores: Object.fromEntries(entradas.filter((e) => typeof d.valores[e.nome] === 'number').map((e) => [e.nome, d.valores[e.nome] as number])),
      outros: d.outroMetodo.trim() && (d.outroValor ?? 0) > 0 ? [{ metodo: d.outroMetodo.trim(), valor: d.outroValor }] : [],
      ilegivel: d.ilegivel.trim() || null,
      net_impresso: d.netImpresso,
    })),
  };
}

/** Motores onde a regra pode ser usada. */
export const usaMotor = (r: Pick<FicheiroRegra, 'motor' | 'ativo'>, motor: 'INTERNA' | 'EXTERNA') => r.ativo && (r.motor === 'AMBOS' || r.motor === motor);

/** N.º de validações da regra que falharam (pedem confirmação antes de abrir o formulário). */
export const validacoesFalhadas = (d: ResultadoDia) => d.validacoes.filter((v) => v.ok === false).length;

/** Descarrega um texto como ficheiro JSON (exportar a regra tal como foi importada, ou o modelo). */
export function descarregarJson(nome: string, conteudo: string): void {
  const blob = new Blob([conteudo], { type: 'application/json;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = /\.json$/i.test(nome) ? nome : `${nome}.json`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

/** Tamanho máximo do ficheiro de regras aceite pelo servidor. */
export const MAX_BYTES_REGRA = 262144;
