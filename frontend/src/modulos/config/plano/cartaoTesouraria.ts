/**
 * Conta-cartão de tesouraria: uma conta da classe 3 (dívida a terceiros) marcada pelo utilizador como cartão de
 * crédito/débito da empresa — não é dinheiro disponível, é uma dívida ao emissor, liquidada pelo banco. Ao
 * contrário de 42/43/45, não é reconhecida por prefixo: qualquer conta de movimento da classe 3 PODE ser marcada,
 * mas só está marcada quem o utilizador escolheu (ServicoContasCartao valida sempre no servidor).
 */
export { type ContaCartao, useContasCartaoMapa } from '@/modulos/teso/contaFinanceira';

export const PREFIXO_CARTAO_ELEGIVEL = '3';

export const CAMPOS_CARTAO = ['cartao_numero', 'cartao_banco'] as const;

export type CampoCartao = (typeof CAMPOS_CARTAO)[number];

export type DadosCartao = Partial<Record<CampoCartao, string | null>> & { cartao_tesouraria?: boolean };

/** Código elegível para ser marcado como cartão: classe 3, de movimento (a marcação em si é uma escolha, não um prefixo). */
export function eCodigoElegivelParaCartao(codigo: string | null | undefined, tipo: string | null | undefined): boolean {
  return tipo !== 'T' && String(codigo ?? '').trim().startsWith(PREFIXO_CARTAO_ELEGIVEL);
}

/**
 * Campos do cartão a enviar na gravação da conta: só quando a flag está ligada (texto vazio = null); desligada,
 * tudo vai a null (incluindo a própria flag), para limpar se a conta deixou de ser cartão (ServicoContasCartao::normalizar).
 */
export function dadosCartaoParaApi(valores: DadosCartao): Record<CampoCartao, string | null> & { cartao_tesouraria: boolean } {
  const marcada = !!valores.cartao_tesouraria;
  return {
    cartao_tesouraria: marcada,
    ...(Object.fromEntries(CAMPOS_CARTAO.map((c) => {
      const v = marcada ? String(valores[c] ?? '').trim() : '';
      return [c, v === '' ? null : v];
    })) as Record<CampoCartao, string | null>),
  };
}
