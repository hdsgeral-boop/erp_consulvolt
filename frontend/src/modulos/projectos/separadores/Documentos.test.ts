import { describe, expect, it } from 'vitest';
import { erroDoFicheiro, tamanhoLegivel } from './Documentos';

describe('Documentos do projecto — verificações do ficheiro', () => {
  it('só aceita PDF até 300 KB', () => {
    expect(erroDoFicheiro({ name: 'contrato.PDF', size: 1000 })).toBeNull();
    expect(erroDoFicheiro({ name: 'folha.xlsx', size: 1000 })).toMatch(/PDF/);
    expect(erroDoFicheiro({ name: 'grande.pdf', size: 301 * 1024 })).toMatch(/300 KB/);
  });

  it('formata o tamanho', () => {
    expect(tamanhoLegivel(null)).toBe('—');
    expect(tamanhoLegivel(512)).toBe('512 B');
    expect(tamanhoLegivel(2048)).toBe('2 KB');
    expect(tamanhoLegivel(5 * 1024 * 1024)).toBe('5.0 MB');
  });
});
