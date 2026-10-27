import { describe, expect, it } from 'vitest';
import { dadosCartaoParaApi, eCodigoElegivelParaCartao } from './cartaoTesouraria';

describe('conta-cartão de tesouraria (classe 3)', () => {
  it('só uma conta de movimento da classe 3 é elegível para ser marcada', () => {
    expect(eCodigoElegivelParaCartao('3282', 'M')).toBe(true);
    expect(eCodigoElegivelParaCartao('32', 'T')).toBe(false);
    expect(eCodigoElegivelParaCartao('4511', 'M')).toBe(false);
    expect(eCodigoElegivelParaCartao(undefined, 'M')).toBe(false);
  });

  it('envia os dados do cartão só quando marcada; desmarcada, tudo vai a null (incluindo a flag)', () => {
    expect(dadosCartaoParaApi({ cartao_tesouraria: true, cartao_banco: ' BAI ', cartao_numero: '' })).toEqual({
      cartao_tesouraria: true, cartao_banco: 'BAI', cartao_numero: null,
    });
    expect(dadosCartaoParaApi({ cartao_tesouraria: false, cartao_banco: 'BAI', cartao_numero: '4417' })).toEqual({
      cartao_tesouraria: false, cartao_banco: null, cartao_numero: null,
    });
    expect(dadosCartaoParaApi({})).toEqual({ cartao_tesouraria: false, cartao_banco: null, cartao_numero: null });
  });
});
