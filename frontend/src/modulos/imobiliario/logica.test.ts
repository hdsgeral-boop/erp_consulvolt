import { describe, expect, it } from 'vitest';
import type { Fracao } from './api';
import { agruparPorPiso, ESTADOS_FRACAO, exigeMotivo, formatarArea, formatarKz, GRUPOS_TAXAS, inserirVariavel, ipEfectivo, principal, rotuloDe, taxaOcupacao, transicoesDe } from './logica';

const TRANSICOES: Record<string, string[]> = {
  DISPONIVEL: ['RESERVADA', 'ARRENDADA', 'VENDIDA', 'EM_OBRA', 'INDISPONIVEL'], VENDIDA: ['DISPONIVEL', 'INDISPONIVEL'],
  ARRENDADA: ['DISPONIVEL', 'RESERVADA', 'VENDIDA', 'EM_OBRA', 'INDISPONIVEL'],
};

const fr = (o: Partial<Fracao>): Fracao => ({
  id: 1, imovel_id: 1, codigo: 'PR0001-01', designacao: '101', natureza: 'HABITACIONAL', porta_id: null, bloco: null, andar: '1.º andar', numero: '101', piso: 1, tipologia: 'T2',
  finalidade: 'HABITACAO', area_bruta: 80, area_util: 70, quartos: 2, casas_banho: 1, mobilada: 'NAO', estado: 'DISPONIVEL', estado_desde: null, estado_motivo: null, matriz_predial: null,
  valor_patrimonial: null, renda_referencia: null, venda_referencia: null, documentos: [], ordem: 1, notas: null, ...o,
});

describe('Gestão Imobiliária — lógica do cadastro', () => {
  it('só ARRENDADA e VENDIDA contam como ocupadas', () => {
    expect(Object.entries(ESTADOS_FRACAO).filter(([, e]) => e.ocupa).map(([k]) => k)).toEqual(['ARRENDADA', 'VENDIDA']);
  });

  it('exige motivo ao sair de arrendada ou vendida e ao ficar indisponível, como o servidor', () => {
    expect(exigeMotivo('DISPONIVEL', 'RESERVADA')).toBe(false);
    expect(exigeMotivo('DISPONIVEL', 'INDISPONIVEL')).toBe(true);
    expect(exigeMotivo('ARRENDADA', 'DISPONIVEL')).toBe(true);
    expect(exigeMotivo('VENDIDA', 'DISPONIVEL')).toBe(true);
    expect(exigeMotivo('EM_OBRA', 'DISPONIVEL')).toBe(false);
  });

  it('uma fracção vendida só pode voltar a disponível ou indisponível', () => {
    expect(transicoesDe('VENDIDA', TRANSICOES)).toEqual(['DISPONIVEL', 'INDISPONIVEL']);
    expect(transicoesDe('DISPONIVEL', TRANSICOES)).not.toContain('DISPONIVEL');
    expect(transicoesDe('EM_OBRA', TRANSICOES)).toEqual([]);
  });

  it('taxa de ocupação: 3 em 4 fracções principais dá 75; as arrecadações não contam; sem fracções dá null', () => {
    const lista = [fr({ estado: 'ARRENDADA' }), fr({ id: 2, estado: 'ARRENDADA' }), fr({ id: 3, estado: 'VENDIDA' }), fr({ id: 4 }), fr({ id: 5, natureza: 'ARRECADACAO', estado: 'ARRENDADA' })];
    expect(taxaOcupacao(lista)).toBe(75);
    expect(taxaOcupacao([fr({ estado: 'ARRENDADA' }), fr({ id: 2 }), fr({ id: 3 }), fr({ id: 4 }), fr({ id: 5 }), fr({ id: 6 }), fr({ id: 7 })])).toBe(14);
    expect(taxaOcupacao([])).toBeNull();
    expect(principal(fr({ natureza: 'ARRECADACAO' }))).toBe(false);
  });

  it('agrupa por piso do mais baixo para o mais alto, soma as áreas das principais e conta as ocupadas', () => {
    const g = agruparPorPiso([
      fr({ id: 1, designacao: '201', andar: '2.º andar', piso: 2, ordem: 1, estado: 'ARRENDADA', area_bruta: 50.5 }),
      fr({ id: 2, designacao: '102', andar: '1.º andar', piso: 1, ordem: 2, area_bruta: 40 }),
      fr({ id: 3, designacao: '101', andar: '1.º andar', piso: 1, ordem: 1, estado: 'VENDIDA', area_bruta: 60.25 }),
      fr({ id: 4, designacao: 'Cave 1', andar: 'Cave', piso: -1, ordem: 1, area_bruta: 10, natureza: 'ARRECADACAO' }),
      fr({ id: 5, designacao: 'Sem piso', andar: null, piso: null, ordem: 1 }),
    ]);
    expect(g.map((x) => x.andar)).toEqual(['Cave', '1.º andar', '2.º andar', '—']);
    expect(g[1].fracoes.map((f) => f.designacao)).toEqual(['101', '102']);
    expect([g[1].ocupadas, g[1].area]).toEqual([1, 100.25]);
    expect(g[0].area).toBe(0);   // a arrecadação não conta para a área das fracções
  });

  it('insere a variável na posição do cursor e no fim quando não há cursor', () => {
    expect(inserirVariavel('Olá , bom dia', 'inquilino', 4, 4)).toEqual({ texto: 'Olá {{inquilino}}, bom dia', cursor: 17 });
    expect(inserirVariavel('Renda: ', 'renda', null, null).texto).toBe('Renda: {{renda}}');
    expect(inserirVariavel('abcXYZdef', 'x', 3, 6).texto).toBe('abc{{x}}def');
  });

  it('a taxa efectiva do Imposto Predial é taxa × base ÷ 100', () => {
    expect(ipEfectivo(25, 60)).toBe(15);
    expect(ipEfectivo(20, 60)).toBe(12);
    expect(ipEfectivo(10.5, 40)).toBe(4.2);
  });

  it('formata kwanzas e áreas em português e traços para vazio', () => {
    expect(formatarKz(1234567.5).replace(/\s/g, ' ')).toBe('1 234 567,50 Kz');
    expect(formatarKz(null)).toBe('—');
    expect(formatarArea(80.5).replace(/\s/g, ' ')).toBe('80,5 m²');
    expect(formatarArea(0)).toBe('—');
    expect(rotuloDe([{ valor: 'A', rotulo: 'Alfa' }], 'A')).toBe('Alfa');
    expect(rotuloDe([], 'B')).toBe('B');
    expect(rotuloDe([], null)).toBe('—');
  });

  it('os campos das taxas do ecrã são os 21 do servidor, sem repetições', () => {
    const chaves = GRUPOS_TAXAS.flatMap((g) => g.campos.map((c) => c.chave));
    expect(chaves).toHaveLength(21);
    expect(new Set(chaves).size).toBe(21);
    expect(chaves).toEqual(expect.arrayContaining(['ip_taxa', 'ip_base', 'det_escalao2_fixo', 'det_agravamento_meses', 'escritura_fixo']));
  });
});
