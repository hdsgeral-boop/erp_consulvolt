import { describe, expect, it } from 'vitest';
import { problemaPalavraPasse, sugerirNomeUtilizador } from './palavraPasse';

describe('palavras-passe e nomes de utilizador', () => {
  it('recusa as fracas e aceita as fortes (mesma regra do servidor)', () => {
    for (const fraca of ['abc', 'abcdefgh', '12345678', 'abcd1234', 'ABCD1234']) expect(problemaPalavraPasse(fraca)).not.toBeNull();
    expect(problemaPalavraPasse('Luanda#2026')).toBeNull();
  });

  it('sugere o nome de utilizador a partir do nome completo, sem acentos', () => {
    expect(sugerirNomeUtilizador('Ana Maria Simão')).toBe('ana.simao');
    expect(sugerirNomeUtilizador('  Délio  ')).toBe('delio');
    expect(sugerirNomeUtilizador('')).toBe('');
  });
});
