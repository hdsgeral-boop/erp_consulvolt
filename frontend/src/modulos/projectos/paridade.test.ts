import { describe, expect, it } from 'vitest';
import { accoesLinhaCarteira } from './comum/regras';

describe('projectos — paridade com o antigo (12.1.1)', () => {
  const tudo = () => true;
  const nada = () => false;

  it('a linha da Carteira tem só ver detalhes e editar (como no antigo)', () => {
    expect(accoesLinhaCarteira({ id: 7, estado: 'ACTIVO' }, tudo)).toEqual([
      { icone: 'eye', titulo: 'Ver detalhes', destino: '7' },
      { icone: 'edit', titulo: 'Editar projecto', destino: '7?editar=1' },
    ]);
  });

  it('sem permissão ou com o projecto fechado não mostra «Editar»', () => {
    expect(accoesLinhaCarteira({ id: 7, estado: 'ACTIVO' }, nada).map((a) => a.icone)).toEqual(['eye']);
    expect(accoesLinhaCarteira({ id: 7, estado: 'ENCERRADO' }, tudo).map((a) => a.icone)).toEqual(['eye']);
  });
});
