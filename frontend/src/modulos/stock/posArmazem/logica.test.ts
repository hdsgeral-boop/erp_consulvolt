import { describe, expect, it } from 'vitest';
import { chamadaNova, chaveChamada, corStock, etiquetaStock, filtrarArtigos, fraseChamada, formatarUnidades, totalPicking, type ArtigoPos, type SenhaPos } from './logica';

const artigo = (id: number, codigo: string, nome: string, quantidade: string): ArtigoPos => ({ produto_id: id, codigo, nome, quantidade, preco_unitario: '100.00', bloqueado: false });
const artigos = [artigo(1, 'CAB-01', 'Cabo eléctrico', '12.000'), artigo(2, 'DIS-02', 'Disjuntor', '0.000'), artigo(3, 'FIT-03', 'Fita isoladora', '3.000')];

describe('POS de armazém — lógica', () => {
  it('filtra Todos / Com Stock / Sem Stock e pesquisa por nome ou código', () => {
    expect(filtrarArtigos(artigos, 'todos', '').map((a) => a.produto_id)).toEqual([1, 2, 3]);
    expect(filtrarArtigos(artigos, 'com', '').map((a) => a.produto_id)).toEqual([1, 3]);
    expect(filtrarArtigos(artigos, 'sem', '').map((a) => a.produto_id)).toEqual([2]);
    expect(filtrarArtigos(artigos, 'todos', 'ELÉC').map((a) => a.produto_id)).toEqual([1]);
    expect(filtrarArtigos(artigos, 'com', 'fit-').map((a) => a.produto_id)).toEqual([3]);
    expect(filtrarArtigos(artigos, 'sem', 'cabo')).toEqual([]);
  });

  it('cores e etiquetas do stock como no legado', () => {
    expect([corStock(11), corStock(5), corStock(0)]).toEqual(['#059669', '#d97706', '#dc2626']);
    expect([etiquetaStock(11).texto, etiquetaStock(1).texto, etiquetaStock(0).texto]).toEqual(['Disponível', 'Baixo', 'Esgotado']);
    expect(etiquetaStock(0).tipo).toBe('perigo');
    expect(formatarUnidades(10)).toBe('10 U');
    expect(formatarUnidades(2.5)).toBe('2,5 U');
  });

  it('total do picking = quantidade × preço', () => {
    const precos = new Map([[1, 1500.5], [3, 250]]);
    expect(totalPicking([{ produto_id: 1, quantidade: 2 }, { produto_id: 3, quantidade: 3 }, { produto_id: 9, quantidade: 1 }], precos)).toBe(3751);
    expect(totalPicking([], precos)).toBe(0);
  });

  it('frase da chamada e detecção de nova chamada (incluindo repetir)', () => {
    expect(fraseChamada('P-007')).toBe('Senha número P 007. Por favor, dirigir-se ao Balcão de Logística.');
    const s: SenhaPos = { id: 4, codigo: 'P-004', numero: 4, estado: 'CHAMADA', chamadas: 1, chamada_em: '2026-10-08T10:00:00+01:00', criado_em: null };
    const k1 = chaveChamada(s);
    expect(chamadaNova(undefined, k1)).toBe(false);   // primeira leitura: não anuncia o que já estava
    expect(chamadaNova(null, k1)).toBe(true);
    expect(chamadaNova(k1, k1)).toBe(false);
    expect(chamadaNova(k1, chaveChamada({ ...s, chamadas: 2, chamada_em: '2026-10-08T10:01:00+01:00' }))).toBe(true);
    expect(chamadaNova(k1, null)).toBe(false);
  });
});
