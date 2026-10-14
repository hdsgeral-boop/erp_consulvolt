import { describe, expect, it } from 'vitest';
import { dadosBancariosParaApi, eCodigoBancario, eContaBancaria, ibanContaValido, swiftValido } from './contasBancarias';

/** IBAN angolano fictício com os dígitos de controlo calculados (mod 97). */
function ibanFicticio(bban = '000000000000000000001'): string {
  let resto = 0;
  for (const ch of `${bban}AO00`) {
    const v = /[A-Z]/.test(ch) ? String(ch.charCodeAt(0) - 55) : ch;
    for (const d of v) resto = (resto * 10 + Number(d)) % 97;
  }
  return `AO${String(98 - resto).padStart(2, '0')}${bban}`;
}

describe('dados bancários das contas 42/43 (Fase A)', () => {
  it('só as contas 42 e 43 de movimento são bancárias', () => {
    expect(eCodigoBancario('4311')).toBe(true);
    expect(eCodigoBancario('42.1')).toBe(true);
    expect(eCodigoBancario(' 4311 ')).toBe(true);
    expect(eCodigoBancario('4511')).toBe(false);
    expect(eCodigoBancario('4')).toBe(false);
    expect(eCodigoBancario(undefined)).toBe(false);
    expect(eContaBancaria('4311', 'M')).toBe(true);
    expect(eContaBancaria('43', 'T')).toBe(false);
    expect(eContaBancaria('4511', 'M')).toBe(false);
  });

  it('valida o IBAN (mod 97) e o SWIFT/BIC', () => {
    const iban = ibanFicticio();
    expect(ibanContaValido(iban)).toBe(true);
    expect(ibanContaValido(iban.replace(/(.{4})/g, '$1 '))).toBe(true);
    expect(ibanContaValido(iban.slice(0, -1) + ((Number(iban.slice(-1)) + 1) % 10))).toBe(false);
    expect(ibanContaValido('AO06123')).toBe(false);
    expect(swiftValido('TSTFAOLU')).toBe(true);
    expect(swiftValido('tstf ao lu xxx')).toBe(true);
    expect(swiftValido('ABC')).toBe(false);
    expect(swiftValido('TSTFAOLU1')).toBe(false);
  });

  it('envia os dados só nas contas bancárias; nas outras vão a null para os limpar', () => {
    const dados = { banco_nome: ' Banco Ficticio ', banco_iban: ibanFicticio(), banco_titular: '' };
    expect(dadosBancariosParaApi('4311', 'M', dados)).toEqual({
      banco_nome: 'Banco Ficticio', banco_balcao: null, banco_numero_conta: null, banco_iban: ibanFicticio(), banco_swift: null, banco_titular: null,
    });
    expect(Object.values(dadosBancariosParaApi('4511', 'M', dados)).every((v) => v === null)).toBe(true);
    expect(Object.values(dadosBancariosParaApi('43', 'T', dados)).every((v) => v === null)).toBe(true);
  });
});
