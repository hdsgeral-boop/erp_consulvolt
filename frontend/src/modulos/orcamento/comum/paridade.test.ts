import { describe, expect, it } from 'vitest';
import { resultadoPorCenario, variacaoCenario } from './cenarios';
import { leitura, mesesDaVista, pct, principaisDesvios, resultado, rotuloPeriodo, sentidoDesvio, seriesMensais, totaisPorNatureza } from './controlo';
import { chipsPreparacao, textoPreparacao, textoUltimaAccao } from './lista';
import type { CalculoCenario, Controlo, LinhaControlo, OrcamentoLista } from './tipos';

const doze = (v: number) => Array(12).fill(v.toFixed(2));

function linha(p: Partial<LinhaControlo> & Pick<LinhaControlo, 'rubrica_id' | 'natureza' | 'orcado' | 'realizado' | 'desvio' | 'favoravel'>): LinhaControlo {
  return { codigo: `R${p.rubrica_id}`, nome: `Rubrica ${p.rubrica_id}`, grupo: null, orcado_inicial: p.orcado, execucao_pct: null, desvio_pct: null, desvio_significativo: false,
    mensal: { orcado: doze(0), realizado: doze(0) }, ...p };
}

const controlo = (linhas: LinhaControlo[], tipo: 'EXPLORACAO' | 'TESOURARIA' = 'EXPLORACAO'): Pick<Controlo, 'linhas' | 'orcamento'> => ({
  linhas, orcamento: { id: 1, ano: 2026, tipo, nome: 'X', versao: 1, estado: 'RASCUNHO', unidade_negocio_id: null, centro_custo_id: null, projeto_id: null },
});

describe('Controlo Orçamental (paridade com o legado)', () => {
  it('período e meses da vista', () => {
    expect(mesesDaVista('ACUMULADO', 10)).toEqual([1, 10]);
    expect(mesesDaVista('MES', 10)).toEqual([10, 10]);
    expect(mesesDaVista('ANO', 3)).toEqual([1, 12]);
    expect(rotuloPeriodo('ACUMULADO', 10, 2026)).toBe('Jan–Out 2026');
    expect(rotuloPeriodo('MES', 10, 2026)).toBe('Out 2026');
  });

  it('cartões Proveitos, Custos e Resultado (negativo quando os custos excedem)', () => {
    const c = controlo([
      linha({ rubrica_id: 1, natureza: 'PROVEITO', orcado: '1000.00', realizado: '129.26', desvio: '-870.74', favoravel: false }),
      linha({ rubrica_id: 2, natureza: 'CUSTO', orcado: '500.00', realizado: '11665.77', desvio: '11165.77', favoravel: false }),
    ]);
    const [p, k] = totaisPorNatureza(c);
    expect([p.natureza, p.realizado, p.orcado]).toEqual(['PROVEITO', 129.26, 1000]);
    expect(p.execucao).toBeCloseTo(12.926);
    expect([k.natureza, k.realizado]).toEqual(['CUSTO', 11665.77]);
    expect(resultado(c)).toEqual({ orcado: 500, realizado: -11536.51, desvio: -12036.51 });
  });

  it('leitura e principais desvios desfavoráveis (até 5, maior primeiro)', () => {
    const ls = [
      linha({ rubrica_id: 1, natureza: 'CUSTO', orcado: '100.00', realizado: '105.00', desvio: '5.00', favoravel: false }),
      linha({ rubrica_id: 2, natureza: 'CUSTO', orcado: '100.00', realizado: '300.00', desvio: '200.00', favoravel: false, desvio_significativo: true }),
      linha({ rubrica_id: 3, natureza: 'PROVEITO', orcado: '100.00', realizado: '150.00', desvio: '50.00', favoravel: true }),
    ];
    expect(ls.map(leitura)).toEqual(['atencao', 'ma', 'boa']);
    expect(principaisDesvios(ls).map((l) => l.rubrica_id)).toEqual([2, 1]);
    expect([sentidoDesvio('PROVEITO'), sentidoDesvio('CUSTO')]).toEqual(['abaixo', 'acima']);
    expect(pct(12.345, true)).toBe('+12,3 %');
    expect(pct(null)).toBe('—');
  });

  it('séries do gráfico mensal: resultado orçado e realizado só até ao mês da vista', () => {
    const c = controlo([
      linha({ rubrica_id: 1, natureza: 'PROVEITO', orcado: '0', realizado: '0', desvio: '0', favoravel: true, mensal: { orcado: doze(100), realizado: doze(80) } }),
      linha({ rubrica_id: 2, natureza: 'CUSTO', orcado: '0', realizado: '0', desvio: '0', favoravel: true, mensal: { orcado: doze(40), realizado: doze(50) } }),
    ]);
    const s = seriesMensais(c, 'ACUMULADO', 2);
    expect(s.orcado).toEqual(Array(12).fill(60));
    expect(s.realizado.slice(0, 3)).toEqual([30, 30, null]);
    expect(seriesMensais(c, 'ANO', 2).realizado[11]).toBe(30);
  });
});

describe('Lista de orçamentos (paridade com o legado)', () => {
  const o = { metodo: 'BASE_ZERO', abordagem: 'BOTTOM_UP', nivel: 1, responsavel: 'maria', prazo_contributo: '2026-11-30',
    ultima_accao: { accao: 'APROVADO', por: 'joao', em: null } } as unknown as OrcamentoLista;
  it('preparação e última acção', () => {
    expect(chipsPreparacao(o).map((c) => c.texto)).toEqual(['Base zero', 'Contributo']);
    expect(chipsPreparacao({ ...o, nivel: 0, abordagem: 'TOP_DOWN', metodo: 'HISTORICO' }).map((c) => c.texto)).toEqual(['Top-down']);
    expect(textoPreparacao(o)).toBe('Base zero · Contributo · maria · até 30/11/2026');
    expect(textoUltimaAccao(o)).toBe('Aprovado por joao');
    expect(textoUltimaAccao({ ultima_accao: { accao: 'CRIADO', por: 'admin', em: null } })).toBe('Criado por admin');
  });
});

describe('Resultado anual por cenário', () => {
  const calc = (id: number, nome: string, prov: string, custo: string): CalculoCenario => ({
    cenario: { id, orcamento_anual_id: 1, nome, tipo: 'REALISTA', variaveis: null, ajustes: null, notas: null },
    orcamento_id: 1,
    linhas: [
      { rubrica_id: 1, codigo: 'P01', nome: 'Vendas', natureza: 'PROVEITO', indutor: null, base: '1000.00', cenario: prov, variacao: '0', valores: [] },
      { rubrica_id: 2, codigo: 'C01', nome: 'Pessoal', natureza: 'CUSTO', indutor: null, base: '600.00', cenario: custo, variacao: '0', valores: [] },
    ],
    resultado_base: '400.00',
    resultado_cenario: '0',
  });

  it('totais, resultado e margem com a base e cada cenário (4 linhas como no legado)', () => {
    const ls = resultadoPorCenario([calc(1, 'Otimista', '1200.00', '600.00'), calc(2, 'Pessimista', '800.00', '650.00')], 'EXPLORACAO');
    expect(ls.map((l) => l.rotulo)).toEqual(['Total proveitos', 'Total custos', 'Resultado', 'Margem (resultado / proveitos)']);
    expect(ls[2].valores).toEqual([400, 600, 150]);
    expect(ls[3].valores[0]).toBeCloseTo(40);
    expect(variacaoCenario(600, 400, true)).toEqual({ d: 200, pct: 50, favoravel: true });
    expect(variacaoCenario(400, 400, true)).toBeNull();
  });

  it('com «Mostrar todas as rubricas» e na tesouraria (fluxo de caixa, sem margem)', () => {
    const ls = resultadoPorCenario([calc(1, 'A', '1000.00', '600.00')], 'EXPLORACAO', true);
    expect(ls.map((l) => l.tipo)).toEqual(['grupo', 'rubrica', 'total', 'grupo', 'rubrica', 'total', 'resultado', 'margem']);
    const t = resultadoPorCenario([{ ...calc(1, 'A', '1.00', '1.00'), linhas: [] }], 'TESOURARIA');
    expect(t.map((l) => l.rotulo)).toEqual(['Total recebimentos', 'Total pagamentos', 'Fluxo de caixa']);
  });
});
