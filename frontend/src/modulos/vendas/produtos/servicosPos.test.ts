import { describe, expect, it } from 'vitest';
import { corpoServicosPOS } from './ServicosPOS';

describe('serviços do POS do produto', () => {
  it('«todos» grava null (aparece em todos os POS) e a escolha grava a lista, mesmo vazia', () => {
    expect(corpoServicosPOS(true, ['LOJA'])).toEqual({ servicos_pos: null });
    expect(corpoServicosPOS(false, ['LOJA', 'RESTAURANTE'])).toEqual({ servicos_pos: ['LOJA', 'RESTAURANTE'] });
    expect(corpoServicosPOS(false, [])).toEqual({ servicos_pos: [] });
  });
});
