/**
 * Dados bancários das contas 42/43 do plano (Fase A, legado novo app_v2.js:9050-9163: CONTAS_BANCARIAS_PREFIXOS,
 * _accEhContaBancaria, _accCamposBancarios). Regras puras do cliente — o servidor (ServicoContasBancarias) valida sempre.
 */
import { ibanValido } from '@/modulos/rh/comum/regras';

/** 42 depósitos a prazo, 43 depósitos à ordem. A caixa (45) não tem banco nem IBAN — paga-se em numerário. */
export const PREFIXOS_BANCARIOS = ['42', '43'] as const;

export const CAMPOS_BANCARIOS = ['banco_nome', 'banco_balcao', 'banco_numero_conta', 'banco_iban', 'banco_swift', 'banco_titular'] as const;

export type CampoBancario = (typeof CAMPOS_BANCARIOS)[number];

export type DadosBancarios = Partial<Record<CampoBancario, string | null>>;

/** GET /tesouraria/contas-bancarias[/{codigo}] */
export interface ContaBancaria extends DadosBancarios {
  id: number;
  codigo: string;
  descricao: string | null;
  codigo_moeda: string;
  /** Titular, ou o nome da empresa quando o titular está vazio. */
  titular_efectivo: string | null;
  /** Tem IBAN: pode emitir cartas de ordem de pagamento. */
  completa: boolean;
}

/** Código de conta bancária (42/43). */
export function eCodigoBancario(codigo: string | null | undefined): boolean {
  const c = String(codigo ?? '').trim();
  return PREFIXOS_BANCARIOS.some((p) => c.startsWith(p));
}

/** Só as contas 42/43 de movimento guardam dados bancários (as totalizadoras e as outras classes não). */
export function eContaBancaria(codigo: string | null | undefined, tipo: string | null | undefined): boolean {
  return tipo !== 'T' && eCodigoBancario(codigo);
}

/** BIC (ISO 9362): 8 ou 11 caracteres — banco (4 letras) + país (2 letras) + localidade (2) + balcão opcional (3). */
export function swiftValido(swift: string): boolean {
  return /^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(swift.replace(/\s+/g, '').toUpperCase());
}

/** IBAN da conta: o mesmo validador dos colaboradores (ISO 13616, mod 97; NIB de 21 dígitos aceite — o servidor converte). */
export function ibanContaValido(iban: string): boolean {
  return ibanValido(iban);
}

/**
 * Campos bancários a enviar na gravação da conta: só nas contas bancárias de movimento (texto vazio = null);
 * nas outras vão todos a null, para os limpar se a conta deixou de ser bancária (saveAccount do legado novo).
 */
export function dadosBancariosParaApi(codigo: string, tipo: string, valores: DadosBancarios): Record<CampoBancario, string | null> {
  const bancaria = eContaBancaria(codigo, tipo);
  return Object.fromEntries(
    CAMPOS_BANCARIOS.map((c) => {
      const v = bancaria ? String(valores[c] ?? '').trim() : '';
      return [c, v === '' ? null : v];
    }),
  ) as Record<CampoBancario, string | null>;
}
