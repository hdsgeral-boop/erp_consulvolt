import type { ReactNode } from 'react';

/**
 * Definição de um campo dos formulários com as vistas «Grade | Linhas» (legado: js/shared/formulario_linhas.js e
 * formularios_linhas_config.js — `campos: [{ id, rotulo, obrigatorio, largura, aliases }]`).
 *
 * Os valores das linhas são simples (serializáveis): texto → `string`, número → `number | null`, data → `'AAAA-MM-DD'`,
 * lista → o `value` da opção. A conversão para/dos valores do `Form` da grade (ex.: `dayjs`) está em `conversoes.ts`.
 */
export type TipoCampo = 'texto' | 'textoLongo' | 'numero' | 'data' | 'select' | 'personalizado';

export interface OpcaoCampo {
  value: string | number;
  label: string;
}

/** Propriedades dadas a uma célula personalizada (ex.: `SeletorMembro`, `SeletorTarefa`). */
export interface PropsCelula {
  valor: unknown;
  aoMudar: (v: unknown) => void;
  /** Rótulo acessível da célula («Responsável, linha 3»). */
  rotulo: string;
  /** Com erro (obrigatório em falta, valor colado não reconhecido). */
  erro: boolean;
}

export interface CampoFormulario {
  /** Nome do campo (o mesmo do `Form.Item name` da grade). */
  nome: string;
  rotulo: string;
  tipo?: TipoCampo;
  obrigatorio?: boolean;
  /** Valor por omissão das linhas novas (ou função, avaliada em cada linha nova). */
  omissao?: unknown | (() => unknown);
  /**
   * Presença na vista Linhas: `true` (omissão) = coluna sempre visível; `'opcional'` = coluna que o utilizador mostra
   * ou esconde no botão «Colunas» (escondida por omissão); `false` = só na grade.
   */
  emLinhas?: boolean | 'opcional';
  /** Secção do formulário a que pertence (agrupa as colunas opcionais no menu «Colunas»). */
  seccao?: string;
  /** Largura mínima da coluna, em píxeis (legado: `largura`). */
  largura?: number;
  /** Nomes alternativos reconhecidos no cabeçalho colado do Excel (legado: `aliases`). */
  aliases?: string[];
  /** Opções (tipo `select`). */
  opcoes?: OpcaoCampo[];
  /** Números: limites e casas decimais. */
  min?: number;
  max?: number;
  precisao?: number;
  /** Comprimento máximo (texto). */
  maxLength?: number;
  placeholder?: string;
  /** Validação própria: devolve a mensagem de erro ou nada. */
  validar?: (valor: unknown, registo: Record<string, unknown>) => string | null | undefined;
  /** Célula personalizada (tipo `personalizado`). */
  celula?: (p: PropsCelula) => ReactNode;
  /** Conversão de um valor colado (tipo `personalizado`): devolve `undefined` se não for reconhecido. */
  converter?: (texto: string) => unknown;
}

export interface LinhaFormulario {
  id: number;
  valores: Record<string, unknown>;
  /** Mensagem de erro da linha (validação ou resposta do servidor). */
  erro?: string;
  /** Células com valor colado não reconhecido: campo → texto original. */
  invalidos?: Record<string, string>;
}

/** Resultado da gravação em linhas. */
export interface ResumoLinhas {
  total: number;
  gravadas: number;
  falhadas: number;
  /** Avisos devolvidos pelas gravações bem-sucedidas (ex.: `{ avisos: [...] }` da API de projectos). */
  avisos: string[];
  cancelado: boolean;
}
