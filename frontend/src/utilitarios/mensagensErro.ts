/**
 * Camada central das mensagens de erro mostradas ao utilizador (docs/qualidade/MENSAGENS_ERRO.md).
 *
 * Regras: (1) nunca mostrar `erro.message` cru de uma excepção que não venha daqui; (2) nunca afirmar uma causa que não
 * foi verificada (sem resposta do servidor ≠ erro do servidor ≠ sem permissão); (3) nunca expor detalhes do backend
 * (SQL, caminhos, classes, nomes de tabelas, versões); (4) 5xx → texto genérico + referência para o suporte.
 * O servidor já sanea as mensagens (MensagemSegura), mas esta camada repete a defesa: só passa texto próprio do servidor
 * para códigos de negócio e só se não parecer técnico.
 */
import { ErroApi } from '@/api/tipos';

export type CategoriaErro =
  | 'validacao'
  | 'sessao'
  | 'permissao'
  | 'inexistente'
  | 'conflito'
  | 'negocio'
  | 'limite'
  | 'grande'
  | 'csrf'
  | 'licenca'
  | 'mfa'
  | 'indisponivel'
  | 'interno'
  | 'rede'
  | 'timeout'
  | 'cancelado'
  | 'desconhecido';

/** Códigos cuja `mensagem` do servidor é substituída pelo texto desta tabela (são respostas de framework/infra, não de negócio). */
const CODIGOS_DO_FRAMEWORK = new Set([
  'NAO_AUTENTICADO',
  'SEM_PERMISSAO',
  'NAO_ENCONTRADO',
  'METODO_NAO_PERMITIDO',
  'ERRO_HTTP',
  'ERRO_INTERNO',
  'INDISPONIVEL',
  'SESSAO_CSRF_EXPIRADA',
  'PEDIDO_DEMASIADO_GRANDE',
  'TIPO_NAO_SUPORTADO',
]);

/** Texto técnico que nunca pode chegar ao ecrã (espelho de App\Support\Api\MensagemSegura). */
const PADROES_TECNICOS: RegExp[] = [
  /SQLSTATE/i,
  /\bSQL\b/,
  /\brelation\s+["“]/i,
  /\b(violates|duplicate key|unique constraint|foreign key constraint|check constraint|not-null constraint|null value in column)\b/i,
  /\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+TABLE)\b[^.]{0,200}\b(FROM|SET|VALUES|WHERE|INTO|TABLE)\b/,
  /(\/var\/www|\/home\/|\/usr\/|\/srv\/|\/opt\/|\/etc\/|vendor\/|storage\/|[A-Za-z]:\\)/,
  /\.php\b/i,
  /\bon line \d+/i,
  /Stack trace/i,
  /\b(Illuminate|Symfony|Doctrine|Predis|Guzzle(Http)?)\\/,
  /\bApp\\/,
  /\b\w*(Exception|Error)\b(?=[:\s(]|$)/,
  /\b(PDOException|QueryException|TypeError|ReferenceError|SyntaxError|ErrorException)\b/,
  /\b(Undefined (variable|index|array key|property)|Call to (undefined|a member)|must be of type|Argument #\d+)/i,
  /\b(Connection refused|ECONN\w+|ENOTFOUND|cURL error|getaddrinfo|Network Error|timeout of \d+ms|Request failed with status code)\b/i,
  /\b(redis|pgsql|postgres(ql)?|mysql|sqlite)\b/i,
  /\b(?:\d{1,3}\.){3}\d{1,3}(:\d+)?\b/,
  /\bat\s+\S+\s+\(.*:\d+:\d+\)/,
  /\bundefined\b|\[object Object\]|\bNaN\b/,
];

/** `true` se o texto parece vir das entranhas do servidor/navegador (SQL, caminhos, classes, stack traces…). */
export function parecePorTecnico(texto: string): boolean {
  return PADROES_TECNICOS.some((p) => p.test(texto));
}

/** Texto genérico por estado HTTP (a categoria, sem causa técnica). */
export function mensagemGenerica(estado: number): string {
  if (estado === 401) return 'A sua sessão terminou. Entre novamente.';
  if (estado === 403) return 'Não tem permissão para executar esta operação.';
  if (estado === 404) return 'O registo pedido não foi encontrado.';
  if (estado === 405) return 'Esta operação não está disponível neste endereço.';
  if (estado === 409) return 'Este registo entra em conflito com outro já existente ou foi alterado entretanto.';
  if (estado === 413) return 'O pedido ou ficheiro enviado é demasiado grande.';
  if (estado === 415) return 'O tipo de ficheiro enviado não é aceite.';
  if (estado === 419) return 'A sua sessão de segurança expirou. Recarregue a página e tente novamente.';
  if (estado === 422) return 'Não foi possível concluir a operação com os dados indicados. Reveja-os e tente novamente.';
  if (estado === 429) return 'Demasiados pedidos em pouco tempo. Aguarde um momento e tente novamente.';
  if (estado === 502 || estado === 503 || estado === 504) return 'Serviço temporariamente indisponível. Tente novamente dentro de instantes.';
  if (estado >= 500) return 'Ocorreu um erro interno. Tente novamente; se persistir, contacte o suporte indicando a referência.';
  return 'Não foi possível processar o pedido.';
}

export const MENSAGEM_REDE = 'Não foi possível contactar o servidor. Verifique a sua ligação e tente novamente.';
export const MENSAGEM_SEM_INTERNET = 'Está sem ligação à internet. Verifique a rede e tente novamente.';
export const MENSAGEM_TIMEOUT = 'O servidor não respondeu a tempo. Confirme se a operação ficou registada antes de a repetir.';
export const MENSAGEM_INESPERADA = 'Ocorreu um erro inesperado. Tente novamente; se persistir, contacte o suporte.';

/** Dados normalizados de um erro de resposta HTTP (usados pelo cliente HTTP para construir o ErroApi). */
export interface RespostaDeErro {
  mensagem: string;
  codigo?: string;
  erros?: Record<string, unknown>;
  referencia?: string;
}

interface CorpoErro {
  mensagem?: unknown;
  codigo?: unknown;
  erros?: unknown;
  referencia?: unknown;
}

function comoCorpo(corpo: unknown): CorpoErro | null {
  return corpo && typeof corpo === 'object' && !Array.isArray(corpo) ? (corpo as CorpoErro) : null;
}

/**
 * Converte a resposta de erro do servidor (envelope, HTML do proxy, vazio…) na mensagem a mostrar. Texto do servidor só
 * passa quando o código é de negócio e a frase não parece técnica; caso contrário usa-se a frase da categoria.
 */
export function normalizarRespostaDeErro(estado: number, corpo: unknown): RespostaDeErro {
  const c = comoCorpo(corpo);
  const codigo = typeof c?.codigo === 'string' ? c.codigo : undefined;
  const erros = c?.erros && typeof c.erros === 'object' ? (c.erros as Record<string, unknown>) : undefined;
  const referenciaBruta = typeof c?.referencia === 'string' && /^E-[A-Z0-9]{4,16}$/.test(c.referencia) ? c.referencia : undefined;
  const referencia = estado >= 500 ? referenciaBruta : undefined;
  const doServidor = typeof c?.mensagem === 'string' ? c.mensagem.trim() : '';

  let mensagem: string;
  if (estado === 429) {
    const espera = Number((erros as { espera_segundos?: unknown } | undefined)?.espera_segundos);
    mensagem = Number.isFinite(espera) && espera > 0 ? `Demasiados pedidos em pouco tempo. Aguarde ${Math.ceil(espera)} segundos e tente novamente.` : mensagemGenerica(429);
  } else if (!codigo || CODIGOS_DO_FRAMEWORK.has(codigo)) {
    mensagem = mensagemGenerica(estado);
  } else if (doServidor && !parecePorTecnico(doServidor)) {
    mensagem = doServidor; // ErroNegocio / validação: texto escrito para o utilizador
  } else {
    mensagem = mensagemGenerica(estado);
  }
  return { mensagem, codigo, erros, referencia };
}

/** Falha sem resposta do servidor (rede, timeout, pedido cancelado): nunca se apresenta como erro do servidor. */
export function normalizarFalhaSemResposta(codigoAxios?: string, aoVivo: boolean = typeof navigator === 'undefined' || navigator.onLine !== false): RespostaDeErro {
  if (codigoAxios === 'ERR_CANCELED') return { mensagem: 'O pedido foi cancelado.', codigo: 'PEDIDO_CANCELADO' };
  if (codigoAxios === 'ECONNABORTED' || codigoAxios === 'ETIMEDOUT') return { mensagem: MENSAGEM_TIMEOUT, codigo: 'SEM_RESPOSTA_A_TEMPO' };
  return { mensagem: aoVivo ? MENSAGEM_REDE : MENSAGEM_SEM_INTERNET, codigo: 'SEM_LIGACAO' };
}

/** Categoria do erro, para escolher o tipo de alerta e o texto de ajuda. */
export function categoriaDoErro(e: unknown): CategoriaErro {
  if (!(e instanceof ErroApi)) return 'desconhecido';
  const { estado, codigo } = e;
  if (estado === 0) {
    if (codigo === 'PEDIDO_CANCELADO') return 'cancelado';
    return codigo === 'SEM_RESPOSTA_A_TEMPO' ? 'timeout' : 'rede';
  }
  if (codigo === 'LICENCA_EXPIRADA') return 'licenca';
  if (codigo === 'MFA_REQUERIDO') return 'mfa';
  if (estado === 401) return 'sessao';
  if (estado === 403) return 'permissao';
  if (estado === 404) return 'inexistente';
  if (estado === 409) return 'conflito';
  if (estado === 413) return 'grande';
  if (estado === 419) return 'csrf';
  if (estado === 429) return 'limite';
  if (estado === 502 || estado === 503 || estado === 504) return 'indisponivel';
  if (estado >= 500) return 'interno';
  if (estado === 422 && codigo === 'VALIDACAO') return 'validacao';
  return 'negocio';
}

/** Falhas do sistema (erro vermelho) vs. situações que o utilizador resolve (aviso). */
export function gravidadeDoErro(e: unknown): 'error' | 'warning' | 'info' {
  switch (categoriaDoErro(e)) {
    case 'interno':
    case 'indisponivel':
    case 'rede':
    case 'timeout':
    case 'desconhecido':
      return 'error';
    case 'cancelado':
      return 'info';
    default:
      return 'warning';
  }
}

/** Referência do erro 5xx (liga ao log do servidor), se existir. */
export function referenciaDoErro(e: unknown): string | undefined {
  return e instanceof ErroApi ? e.referencia : undefined;
}

/**
 * Mensagem segura para o utilizador. Um `ErroApi` já traz a mensagem normalizada; qualquer outra coisa (TypeError do
 * navegador, string, objecto) devolve o texto genérico: o `message` cru NUNCA é devolvido.
 * Com `contexto` («gravar a factura») devolve «Não foi possível gravar a factura. <mensagem>».
 */
export function mensagemParaUtilizador(e: unknown, contexto?: string): string {
  const base = e instanceof ErroApi ? e.message : MENSAGEM_INESPERADA;
  return contexto ? `Não foi possível ${contexto}. ${base}` : base;
}

/** Os detalhes por campo (`erros`) já limpos: só texto sem aspecto técnico. */
export function detalhesDoErro(e: unknown): string[] {
  if (!(e instanceof ErroApi) || !e.erros) return [];
  const saida: string[] = [];
  const juntar = (v: unknown) => {
    if (typeof v === 'string') {
      if (!parecePorTecnico(v)) saida.push(v);
    } else if (Array.isArray(v)) v.forEach(juntar);
  };
  for (const v of Object.values(e.erros)) juntar(v);
  return Array.from(new Set(saida));
}
