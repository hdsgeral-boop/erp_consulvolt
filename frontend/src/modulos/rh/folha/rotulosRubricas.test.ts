import { describe, expect, it } from 'vitest';
import type { ResultadoSalarial, RubricaResultado } from '../api';
import { colunasFolha, colunasRemuneracoes, csvFolha, totaisFolha } from './regrasFolha';
import { ordenarRotulos, PRIORIDADE_FOLHA, PRIORIDADE_REMUNERACOES, rotuloRubrica } from './rotulosRubricas';

/** Rótulos das colunas dos mapas salariais iguais ao legado (js/app_v2.js `calculatePeriodData` / `loadReportTabs`). Dados fictícios. */

const rub = (nome: string, valor: string, extra: Partial<RubricaResultado> = {}): RubricaResultado => ({ nome, tipo: 'VENCIMENTO', valor, infotipo_id: 1, ...extra });

const res = (id: number, nome: string, extra: Partial<ResultadoSalarial> = {}): ResultadoSalarial => ({
  colaborador_id: id, nome, nif: `00000000${id}`, numero_inss: `INSS${id}`, tipo_organizacao_id: 1, unidade_negocio_id: 1, centro_custo_id: 2, unidade_negocio: 'UN1', centro_custo: 'CC2',
  avencado: false, reformado: false, dias_contrato: '22.00', dias_trabalhados: '22.00', bruto: '265000.00', base_inss: '200000.00', inss_trabalhador: '6000.00',
  inss_patronal: '16000.00', isencoes: '45000.00', base_irt: '214000.00', irt: '23540.00', descontos: '15000.00', liquido: '220460.00', avisos: [], modo_calculo: 'ATUAL',
  rubricas: [
    rub('Subsídio de produtividade', '10000.00'),
    rub('Subsídio de alimentação', '20000.00'),
    rub('Salário Base', '200000.00'),
    rub('Subsídio de transporte', '25000.00'),
    rub('H. Extras', '10000.00', { horas: '4' }),
    rub('Desc. Falta', '5000.00', { tipo: 'DESCONTO', falta: true }),
    rub('Descontos de Adiantamento', '10000.00', { tipo: 'DESCONTO' }),
    rub('Dias de Trabalho', '22.00', { tipo: 'OUTROS', informativa: true }),
  ],
  ...extra,
});

describe('rotuloRubrica — abreviaturas do legado', () => {
  it('abrevia as rubricas comuns e mantém as outras por extenso', () => {
    expect(rotuloRubrica('Salário Base')).toBe('Sal. Base');
    expect(rotuloRubrica('Subsídio de transporte')).toBe('S. Transp');
    expect(rotuloRubrica('Subsídio de Alimentação')).toBe('S. Alim');
    expect(rotuloRubrica('Subsídio de férias')).toBe('S. Férias');
    expect(rotuloRubrica('Descontos de Adiantamento')).toBe('Adiant.');
    expect(rotuloRubrica('Subsídio de produtividade')).toBe('Subsídio de produtividade');
    expect(rotuloRubrica('H. Extras')).toBe('H. Extras');
  });

  it('ordena pela prioridade de cada mapa e depois alfabeticamente', () => {
    const nomes = ['Subsídio de produtividade', 'S. Alim', 'S. Transp', 'Sal. Base'];
    expect(ordenarRotulos(nomes, PRIORIDADE_REMUNERACOES)).toEqual(['Sal. Base', 'S. Transp', 'S. Alim', 'Subsídio de produtividade']);
    expect(ordenarRotulos(nomes, PRIORIDADE_FOLHA)).toEqual(['Sal. Base', 'S. Alim', 'S. Transp', 'Subsídio de produtividade']);
  });
});

describe('Mapa Detalhado de Remunerações (Colunas)', () => {
  const nome = (r: ResultadoSalarial) => r.nome ?? '';
  const dados = [res(1, 'Ana'), res(2, 'Rui', { rubricas: [rub('Salário Base', '150000.00'), rub('Vales', '2000.00', { tipo: 'DESCONTO' })] })];

  it('colunas visíveis pela ordem do antigo; Nº, Total Descontos e INSS Patronal escondidas por omissão', () => {
    const cols = colunasRemuneracoes(dados, nome);
    expect(cols.filter((c) => !c.ocultaPorOmissao).map((c) => c.titulo)).toEqual([
      'Colaborador', 'NIF', 'UN', 'CC', 'Nº INSS', 'Dias Cont.', 'Dias Trab.',
      'Sal. Base', 'S. Transp', 'S. Alim', 'Subsídio de produtividade', 'Vales',
      'Total Ilíquido', 'H. Extras', 'Faltas', 'INSS (3%)', 'IRT', 'Adiant.', 'Líquido',
    ]);
    expect(cols.filter((c) => c.ocultaPorOmissao).map((c) => c.titulo)).toEqual(['Nº', 'Total Descontos', 'INSS Patronal 8%']);
    expect(cols.find((c) => c.titulo === 'Vales')?.classe).toBe('desc');
  });

  it('H. Extras, Faltas e Adiant. vêm das rubricas; UN, CC e Nº INSS do resultado', () => {
    const cols = colunasRemuneracoes(dados, nome);
    const v = (t: string, i = 0) => String(cols.find((c) => c.titulo === t)?.valor(dados[i], i));
    expect([v('UN'), v('CC'), v('Nº INSS')]).toEqual(['UN1', 'CC2', 'INSS1']);
    expect([v('H. Extras'), v('Faltas'), v('Adiant.')]).toEqual(['10000.00', '5000.00', '10000.00']);
    expect([v('UN', 1), v('H. Extras', 1), v('Adiant.', 1)]).toEqual(['UN1', '0.00', '0.00']);
  });

  it('os totais não mudam (somas exactas das linhas do servidor)', () => {
    const tot = totaisFolha(dados, colunasRemuneracoes(dados, nome));
    expect(tot.bruto).toBe('530000.00');
    expect(tot.inss).toBe('12000.00');
    expect(tot.irt).toBe('47080.00');
    expect(tot.liquido).toBe('440920.00');
    expect(tot['v:Sal. Base']).toBe('350000.00');
  });
});

describe('Folha de Colaboradores (Processamentos) — mesmos rótulos', () => {
  it('cabeçalhos abreviados pela ordem do antigo e CSV com os mesmos rótulos', () => {
    const nome = (r: ResultadoSalarial) => r.nome ?? '';
    const dados = [res(1, 'Ana')];
    const cols = colunasFolha(dados, nome);
    expect(cols.map((c) => c.titulo)).toEqual([
      'Nº', 'Colaborador', 'NIF', 'D.C.', 'D.T.', 'Sal. Base', 'S. Alim', 'S. Transp', 'H. Extras', 'Subsídio de produtividade',
      'Total Ilíquido', 'Desc. Falta', 'Adiant.', 'INSS 3%', 'IRT', 'Total Descontos', 'Líquido a Receber', 'INSS Patronal 8%',
    ]);
    expect(csvFolha(dados, cols).split('\r\n')[0]).toContain('D.T.;Sal. Base;S. Alim;S. Transp');
    expect(totaisFolha(dados, cols).liquido).toBe('220460.00');
  });
});
