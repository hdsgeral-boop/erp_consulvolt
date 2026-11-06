import { describe, expect, it } from 'vitest';
import type { Recolha } from './api';
import { blocosDeTexto, capitulos, excluidosDe, nomeDoProcesso } from './logica';

const forma = { apresentacao: '', objetivo: '', principios: '', notas: '', incluir_legenda: false, incluir_matriz: true, incluir_controlos: true, incluir_cruzamentos: true, incluir_cobertura: false };
const dados = { intervenientes: [{ nome: 'RH', processos: ['rh'], partes: ['RH'], na_funcoes: null }], controlos: [{ processo: 'rh', etapa: 'a', quem: '', controlos: 'x', n: 1 }],
  cruzamentos: { pares: [], externos: [], ligacoes: [{ a: 'rh', b: 'ferias', pontos: [] }], grau: {}, isolados: [], central: null }, cobertura: null } as unknown as Pick<Recolha, 'intervenientes' | 'cruzamentos' | 'cobertura' | 'controlos'>;

describe('Manual de Procedimentos — lógica', () => {
  it('texto livre: parágrafos por linha em branco e lista quando há uma linha por item', () => {
    expect(blocosDeTexto('Olá mundo\n\n- um\n- dois\n\n\n  Fim  ')).toEqual([
      { tipo: 'p', itens: ['Olá mundo'] }, { tipo: 'ul', itens: ['um', 'dois'] }, { tipo: 'p', itens: ['Fim'] },
    ]);
    expect(blocosDeTexto(null)).toEqual([]);
    expect(blocosDeTexto('  \n\n ')).toEqual([]);
  });

  it('B-03: envia-se o que ficou por marcar (exclusões), nunca as inclusões', () => {
    expect(excluidosDe(['a', 'c'], ['a', 'b', 'c', 'd'])).toEqual(['b', 'd']);
    expect(excluidosDe(['a', 'b'], ['a', 'b'])).toEqual([]);
    expect(excluidosDe([], ['a', 'b'])).toEqual(['a', 'b']);
  });

  it('capítulos: ordem e condições do legado, numerados em sequência', () => {
    expect(capitulos(forma as never, dados).map((c) => `${c.n}.${c.id}`)).toEqual(['1.mapa', '2.matriz', '3.cruzamentos', '4.processos', '5.controlos']);
    const completa = { ...forma, apresentacao: 'x', objetivo: 'y', principios: 'z', notas: 'w', incluir_legenda: true, incluir_cobertura: true };
    const cheia = capitulos(completa as never, { ...dados, cobertura: {} as never });
    expect(cheia.map((c) => c.id)).toEqual(['apresentacao', 'objetivo', 'legenda', 'principios', 'mapa', 'matriz', 'cruzamentos', 'cobertura', 'processos', 'controlos', 'notas']);
    expect(cheia.at(-1)?.n).toBe(11);
    // sem intervenientes/ligações/controlos os capítulos condicionais desaparecem
    const vazia = capitulos(forma as never, { ...dados, intervenientes: [], controlos: [], cruzamentos: { ...dados.cruzamentos, ligacoes: [] } });
    expect(vazia.map((c) => c.id)).toEqual(['mapa', 'processos']);
  });

  it('nome de um cruzamento: processo do manual, documento externo ou a chave', () => {
    const mapa = new Map([['rh', { titulo: 'Processamento salarial' }]]) as never;
    expect(nomeDoProcesso('rh', mapa)).toBe('Processamento salarial');
    expect(nomeDoProcesso('estrutura_manual', mapa)).toBe('Estrutura Orgânica — Manual da Organização (fora deste manual)');
    expect(nomeDoProcesso('xyz', mapa)).toBe('xyz');
  });
});
