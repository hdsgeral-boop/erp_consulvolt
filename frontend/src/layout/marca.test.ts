import { iniciais } from './marca';

describe('iniciais da empresa', () => {
  it('usa a primeira letra das duas primeiras palavras significativas', () => {
    expect(iniciais('Demo E2E Comércio, Lda')).toBe('DE');
    expect(iniciais('Sociedade de Água e Energia, S.A.')).toBe('SÁ');
    expect(iniciais('óptica luandense')).toBe('ÓL');
  });

  it('com uma só palavra usa as duas primeiras letras', () => {
    expect(iniciais('Consulvolt')).toBe('CO');
    expect(iniciais('Consulvolt, Lda')).toBe('CO');
  });

  it('nome vazio ou só com formas societárias → «?»', () => {
    expect(iniciais('')).toBe('?');
    expect(iniciais(null)).toBe('?');
    expect(iniciais('Lda')).toBe('?');
  });
});
