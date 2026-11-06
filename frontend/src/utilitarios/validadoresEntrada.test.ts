import { describe, expect, it } from 'vitest';
import { nifValido, normalizarNif, tipoNif } from './nifAngolano';
import { normalizarTelefone, telefoneValido } from './telefoneAngolano';
import { problemaNomeUtilizador } from './nomeUtilizador';
import { eTrivial, forcaPalavraPasse, problemaPalavraPasse } from './palavraPasse';

// Os mesmos casos de backend/tests/Unit/Angola/ValidadoresAngolanosTest.php: o espelho nunca pode divergir do servidor.
describe('NIF angolano', () => {
  it.each(['5417012345', ' 5417 012 345 ', '000755848LA037', '000755848la037', '000755848-LA-037', '541701234', '0017012345'])('aceita %s', (v) => {
    expect(nifValido(v)).toBe(true);
  });
  it.each(['', '   ', '12345678', '54170123456', '54170123456789', '54A7012345', '000755848L037', '000755848LAB037', '000755848LA03', '0000000000', '9999999999', '999999999', 'sem nif', "5417012345'; DROP TABLE empresas;--"])('recusa «%s»', (v) => {
    expect(nifValido(v)).toBe(false);
  });
  it('consumidor final só quando permitido; tipos e normalização', () => {
    expect(nifValido('999999999', true)).toBe(true);
    expect(tipoNif('5417012345')).toBe('colectiva');
    expect(tipoNif('000755848LA037')).toBe('singular');
    expect(tipoNif('541701234')).toBe('nove_digitos');
    expect(tipoNif('abc')).toBeNull();
    expect(normalizarNif(' 000 755848-la 037 ')).toBe('000755848LA037');
  });
});

describe('telefone angolano', () => {
  it.each([
    ['923000111', '923000111'],
    ['923 000 111', '923000111'],
    ['+244 923 000 111', '923000111'],
    ['00244923000111', '923000111'],
    ['244923000111', '923000111'],
    ['(244) 923-000-111', '923000111'],
    ['222 123 456', '222123456'],
    ['+351 912 345 678', '+351912345678'],
    ['00351912345678', '+351912345678'],
  ])('aceita e normaliza %s', (entrada, esperado) => {
    expect(telefoneValido(entrada)).toBe(true);
    expect(normalizarTelefone(entrada)).toBe(esperado);
  });
  it.each(['', '92300011', '9230001112', '123456789', '92300011a', '+244', '+24492300011', '+', "923000111'; --"])('recusa «%s»', (v) => {
    expect(telefoneValido(v)).toBe(false);
  });
});

describe('nome de utilizador', () => {
  it.each(['jbaptista', 'joao.silva', 'ana_maria-2', 'Joao.Silva', 'abc', 'a'.repeat(50)])('aceita %s', (v) => {
    expect(problemaNomeUtilizador(v)).toBeNull();
  });
  it.each(['ab', 'a'.repeat(51), 'joao silva', 'joao@empresa.ao', 'joão', '.joao', 'joao.', 'joao..silva', 'joao😀', 'admin', 'ROOT', 'suporte', "x' or '1'='1", '<script>'])('recusa «%s»', (v) => {
    expect(problemaNomeUtilizador(v)).not.toBeNull();
  });
});

describe('política da palavra-passe', () => {
  it('aceita fortes e recusa as outras (mesmos casos do servidor, mínimo 10 no registo)', () => {
    for (const ok of ['Barcaça#2026x', 'NovaSenha2026', 'Óptima Frase 2026']) expect(problemaPalavraPasse(ok, [], 10)).toBeNull();
    for (const mau of ['abcdefghij1', 'Abcdefghij', 'abcdefgh12', 'Ab1', 'Password123', 'Qwerty2026', 'Consulvolt1', ' '.repeat(12), 'Aa1'.repeat(50)]) {
      expect(problemaPalavraPasse(mau, [], 10), mau).not.toBeNull();
    }
  });
  it('não pode conter o utilizador nem a parte local do e-mail', () => {
    expect(problemaPalavraPasse('Joao.silva2026X', ['joao.silva'])).not.toBeNull();
    expect(problemaPalavraPasse('MariaSantos#9', [null, 'maria.santos'])).not.toBeNull();
    expect(problemaPalavraPasse('Barcaça#2026x', ['joao.silva'])).toBeNull();
    expect(problemaPalavraPasse('Abcd#9Xyz', ['ab'])).toBeNull();
  });
  it('reconhece triviais e sequências', () => {
    expect(eTrivial('Password1')).toBe(true);
    expect(eTrivial('12345678')).toBe(true);
    expect(eTrivial('abcdefgh')).toBe(true);
    expect(eTrivial('Barcaça#2026x')).toBe(false);
  });
  it('indicador de força', () => {
    expect(forcaPalavraPasse('').nivel).toBe(0);
    expect(forcaPalavraPasse('abc').rotulo).toBe('Curta');
    expect(forcaPalavraPasse('abcdefghij1').rotulo).toBe('Fraca');
    expect(forcaPalavraPasse('Barcaça#2026x', [], 10).nivel).toBeGreaterThanOrEqual(3);
    expect(forcaPalavraPasse('Barcaça#2026x', [], 10).estado).toBe('success');
  });
});
