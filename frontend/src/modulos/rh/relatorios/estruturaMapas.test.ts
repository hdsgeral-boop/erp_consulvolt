import { describe, expect, it } from 'vitest';
import type { ResultadoSalarial } from '../api';
import { classificarRubrica, linhaInss, linhaIrtOficial, rotuloPercentagem, subsidiosDe } from './estruturaMapas';
import { colunasInss, colunasIrtOficial } from './TabelasMapasOficiais';

const rub = (nome: string, valor: string, extra = {}) => ({ nome, tipo: 'VENCIMENTO' as const, valor, infotipo_id: 1, ...extra });
const resultado = (x: Partial<ResultadoSalarial>): ResultadoSalarial => ({
  colaborador_id: 1, tipo_organizacao_id: null, unidade_negocio_id: null, centro_custo_id: null, avencado: false, reformado: false, dias_contrato: '22', dias_trabalhados: '22',
  bruto: '0', base_inss: '0', inss_trabalhador: '0', inss_patronal: '0', isencoes: '0', base_irt: '0', irt: '0', descontos: '0', liquido: '0', rubricas: [], avisos: [], modo_calculo: 'ATUAL', ...x,
});

describe('Mapa de INSS na estrutura do modelo', () => {
  it('SAL. BASE + SUBS. ADIC. = TOTAL BASE (a base do servidor); TRAB., EMPRESA e TOTAL vêm do cálculo', () => {
    const r = resultado({ rubricas: [rub('Salário Base', '150000')], base_inss: '221000', inss_trabalhador: '6630', inss_patronal: '17680' });
    expect(linhaInss(r, 'Celso', '797254')).toMatchObject({ sal_base: '150000.00', subs_adic: '71000.00', total_base: '221000', trab: '6630', empresa: '17680', total: '24310.00' });
  });

  it('a diferença pode ser negativa (descontos por faltas) e o Nº INSS em falta mostra «---»', () => {
    const r = resultado({ rubricas: [rub('Salário Base', '67010.40')], base_inss: '45688.91' });
    const l = linhaInss(r, 'Lemba', '');
    expect(l.subs_adic).toBe('-21321.49');
    expect(l.numero_inss).toBe('---');
  });

  it('os cabeçalhos das percentagens vêm da configuração, não do código', () => {
    const titulos = (t?: { trabalhador: number; patronal: number }) => colunasInss(t).map((c) => String(c.title));
    expect(titulos({ trabalhador: 3, patronal: 8 })).toEqual(['Colaborador', 'Nº INSS', 'Sal. Base', 'Subs. Adic.', 'Total Base', 'Trab. (3%)', 'Empresa (8%)', 'Total (11%)']);
    expect(titulos({ trabalhador: 4, patronal: 9.5 })).toContain('Total (13,5%)');
    expect(rotuloPercentagem(undefined)).toBe('');
  });

  it('as colunas de valores somam na linha TOTAL', () => {
    const cols = colunasInss({ trabalhador: 3, patronal: 8 });
    expect(cols.filter((c) => c.total).map((c) => c.key)).toEqual(['sal_base', 'subs_adic', 'total_base', 'trab', 'empresa', 'total']);
  });
});

describe('Mapa Fiscal de IRT (Modelo Oficial — Angola)', () => {
  it('classifica as rubricas pelas colunas do modelo e o resto vai para «Outros»', () => {
    expect(['Horas Extras', 'Subsídio de Alimentação', 'Subsídio de Transporte', 'Subsídio de Férias', 'Salário Base', 'Subsídio de Comunicação', 'Prémio'].map(classificarRubrica))
      .toEqual(['horas_extras', 'subs_alimentacao', 'subs_transporte', 'subs_ferias', 'sal_base', 'subs_comunicacao', null]);
    const s = subsidiosDe(resultado({ bruto: '1200', rubricas: [rub('Salário Base', '1000'), rub('Prémio', '200'), rub('Faltas', '50', { falta: true })] }));
    expect(s).toMatchObject({ sal_base: '1000.00', outros: '200.00' });
  });

  it('a linha tem a identificação da ficha («---» se faltar), o período e o IRT do servidor', () => {
    const r = resultado({ bruto: '171000', base_irt: '150000', irt: '12500', isencoes: '0', inss_trabalhador: '5130',
      rubricas: [rub('Salário Base', '100000'), rub('Subsídio de Alimentação', '30000'), rub('Subsídio de Transporte', '30000'), rub('Subsídio de Comunicação', '11000')],
      irt_escalao: { fixo: '12500', taxa: 16, excesso: '0', devido: '12500' } as unknown as ResultadoSalarial['irt_escalao'] });
    const l = linhaIrtOficial(r, 2, { nome: 'Leonildo', nif: '005998906LA049', inss: '6042878', provincia: '', municipio: '' }, '09/2026');
    expect(l).toMatchObject({ ord: 2, nif: '005998906LA049', numero_ss: '6042878', provincia: '---', municipio: '---', periodo: '09/2026', sal_base: '100000.00', subs_alimentacao: '30000.00',
      subs_comunicacao: '11000.00', outros: '0.00', total_bruto: '171000', irt_retido: '12500', imposto_devido: '12500', taxa: 16 });
  });

  it('as colunas seguem a ordem do modelo da captura', () => {
    expect(colunasIrtOficial().slice(0, 14).map((c) => String(c.title))).toEqual(['Ord.', 'NIF', 'Nº SS', 'Nome Completo', 'Província', 'Município', 'Período', 'H. Extras', 'S. Alim.', 'S. Transp.',
      'S. Férias', 'Sal. Base', 'Subsídio de Comunicação', 'Outros Subsídios']);
  });
});
