import { describe, expect, it } from 'vitest';
import type { ItemAvaliacao, ResultadoSalarial } from '../api';
import {
  accoesPedidoRh, accoesPeriodo, chaveMesAno, classificar, colunasRubricas, contratoVigente, escalaoIrt, formatarHoras, formatarIban, gerarCsv, horasEntre,
  ibanValido, itensAplicaveis, kzCsv, mesAnoParaMes, mesParaMesAno, mesPorExtenso, normalizarRemuneracoes, numeroRecibo, somar, totalContrato, totaisResultados,
  validarPesos360, valoresRubricas,
} from './regras';

const todas = () => true;
const nenhuma = () => false;
const so = (...permitidas: string[]) => (...chaves: string[]) => chaves.some((c) => permitidas.includes(c));

describe('accoesPeriodo', () => {
  it('período aberto: lançar, importar e encerrar; nada de validar/contabilizar', () => {
    const a = accoesPeriodo({ estado: 'ABERTO', contabilizado: false }, todas);
    expect(a).toMatchObject({ lancar: true, removerLancamento: true, importar: true, encerrar: true, validar: false, reabrir: false, contabilizar: false, recibos: false });
  });
  it('fechado: validar e reabrir', () => {
    const a = accoesPeriodo({ estado: 'FECHADO', contabilizado: false }, todas);
    expect(a).toMatchObject({ lancar: false, validar: true, reabrir: true, contabilizar: false });
  });
  it('validado e contabilizado: não reabre nem volta a contabilizar; pode estornar', () => {
    const a = accoesPeriodo({ estado: 'VALIDADO', contabilizado: true }, todas);
    expect(a).toMatchObject({ reabrir: false, contabilizar: false, descontabilizar: true, emitirCarta: true, recibos: true });
  });
  it('respeita as permissões', () => {
    expect(accoesPeriodo({ estado: 'ABERTO', contabilizado: false }, nenhuma).lancar).toBe(false);
    expect(accoesPeriodo({ estado: 'ABERTO', contabilizado: false }, so('calcular_bulk')).lancar).toBe(true);
    expect(accoesPeriodo({ estado: 'VALIDADO', contabilizado: false }, so('processamento_validate')).contabilizar).toBe(false);
    expect(accoesPeriodo({ estado: 'VALIDADO', contabilizado: true }, so('processamento_integrate')).descontabilizar).toBe(false);
  });
});

describe('meses', () => {
  it('converte e ordena', () => {
    expect(chaveMesAno('09/2026')).toBe('202609');
    expect(['01/2026', '12/2025', '09/2026'].sort((a, b) => chaveMesAno(b).localeCompare(chaveMesAno(a)))).toEqual(['09/2026', '01/2026', '12/2025']);
    expect(mesAnoParaMes('09/2026')).toBe('2026-09');
    expect(mesParaMesAno('2026-09')).toBe('09/2026');
    expect(mesPorExtenso('03/2026')).toBe('Março de 2026');
    expect(mesPorExtenso('2026-12')).toBe('Dezembro de 2026');
    expect(numeroRecibo('09/2026', 7)).toBe('202609-0007');
  });
});

describe('somas em Kz', () => {
  it('soma sem erros de vírgula flutuante', () => {
    expect(somar(['0.10', '0.20', null, ''])).toBe('0.30');
    expect(somar(['1234567.89', '0.11'])).toBe('1234568.00');
  });
  it('totais do período', () => {
    const r = (b: string, l: string) => ({ bruto: b, inss_trabalhador: '3.00', inss_patronal: '8.00', irt: '0.00', descontos: '0.00', liquido: l });
    expect(totaisResultados([r('100.00', '97.00'), r('200.50', '197.50')])).toEqual({ bruto: '300.50', inss_trabalhador: '6.00', inss_patronal: '16.00', irt: '0.00', descontos: '0.00', liquido: '294.50' });
  });
});

describe('mapa de remunerações', () => {
  const res = [
    { rubricas: [{ nome: 'Salário Base', tipo: 'VENCIMENTO', valor: '100000.00', infotipo_id: 1 }, { nome: 'Adiantamento', tipo: 'DESCONTO', valor: '5000.00', infotipo_id: 9 }] },
    { rubricas: [{ nome: 'Alimentação', tipo: 'VENCIMENTO', valor: '20000.00', infotipo_id: 2 }, { nome: 'Dias de trabalho', tipo: 'OUTROS', valor: '22.00', infotipo_id: 5, informativa: true },
      { nome: 'Salário Base', tipo: 'VENCIMENTO', valor: '50000.00', infotipo_id: 1 }, { nome: 'Salário Base', tipo: 'VENCIMENTO', valor: '0.50', infotipo_id: 1 }] },
  ] as Pick<ResultadoSalarial, 'rubricas'>[];
  it('colunas: vencimentos primeiro, sem informativas', () => {
    expect(colunasRubricas(res).map((c) => c.nome)).toEqual(['Alimentação', 'Salário Base', 'Adiantamento']);
  });
  it('soma a mesma rubrica repetida', () => {
    expect(valoresRubricas(res[1])['VENCIMENTO:1:Salário Base']).toBe('50000.50');
  });
});

describe('escalão de IRT', () => {
  it('isento até 150 000', () => {
    expect(escalaoIrt('150000').devido).toBe('0.00');
    expect(escalaoIrt(0).devido).toBe('0.00');
  });
  it('2.º e 3.º escalões', () => {
    expect(escalaoIrt('200000')).toEqual({ fixo: '12500.00', taxa: 16, excesso: '150000.00', devido: '20500.00' });
    expect(escalaoIrt('250000').devido).toBe('40250.00');
  });
  it('último escalão (sem máximo)', () => {
    expect(escalaoIrt('12000000')).toMatchObject({ taxa: 25, devido: '2842250.00' });
  });
});

describe('contratos', () => {
  it('lê o formato do legado e o novo', () => {
    expect(normalizarRemuneracoes([{ infotype_id: 3, value_month: 150000, value_per_day: 6818 }, { infotipo_id: 4, valor_mes: '20000.5' }])).toEqual([
      { infotipo_salarial_id: 3, valor_mes: 150000 }, { infotipo_salarial_id: 4, valor_mes: 20000.5 },
    ]);
  });
  it('sem valor mensal usa o diário × dias', () => {
    expect(normalizarRemuneracoes([{ infotipo_id: 1, valor_dia: 1000 }], 22)).toEqual([{ infotipo_salarial_id: 1, valor_mes: 22000 }]);
  });
  it('total e vigência', () => {
    expect(totalContrato({ remuneracoes: [{ infotipo_id: 1, valor_mes: 100000 }, { infotype_id: 2, value_month: 25000.25 }], dias_contrato_mes: 22 })).toBe('125000.25');
    expect(contratoVigente({ data_inicio: '2026-01-01', data_fim: null, estado: 'ACTIVO' }, '2026-10-01')).toBe(true);
    expect(contratoVigente({ data_inicio: '2026-01-01', data_fim: '9999-12-31', estado: 'ACTIVO' }, '2026-10-01')).toBe(true);
    expect(contratoVigente({ data_inicio: '2026-01-01', data_fim: '2026-06-30', estado: 'ACTIVO' }, '2026-10-01')).toBe(false);
    expect(contratoVigente({ data_inicio: '2026-01-01', data_fim: null, estado: 'INACTIVO' }, '2026-10-01')).toBe(false);
  });
});

describe('IBAN', () => {
  it('valida os dígitos de controlo e aceita o NIB de 21 dígitos', () => {
    expect(ibanValido('AO30 0040 0000 1234 5678 9012 3')).toBe(true);
    expect(ibanValido('AO31004000001234567890123')).toBe(false);
    expect(ibanValido('004000001234567890123')).toBe(true);
    expect(ibanValido('PT50000201231234567890154')).toBe(true);
    expect(ibanValido('AO300040')).toBe(false);
  });
  it('formata em grupos de 4', () => {
    expect(formatarIban('ao30004000001234567890123')).toBe('AO30 0040 0000 1234 5678 9012 3');
    expect(formatarIban(null)).toBe('—');
  });
});

describe('portal', () => {
  const pedido = (estado: string, tipo: 'FERIAS' | 'DOCUMENTO', nivel: 'CHEFIA' | 'RH') => ({ estado, tipo, etapas: [{ nivel, estado: 'PENDENTE' }] });
  it('o RH decide na etapa RH; documentos emitem-se', () => {
    expect(accoesPedidoRh(pedido('PENDENTE_RH', 'FERIAS', 'RH'), todas)).toEqual({ decidir: true, emitir: false });
    expect(accoesPedidoRh(pedido('PENDENTE_RH', 'DOCUMENTO', 'RH'), todas)).toEqual({ decidir: false, emitir: true });
  });
  it('não decide na etapa da chefia, sem permissão ou fora de pendente', () => {
    expect(accoesPedidoRh(pedido('PENDENTE_CHEFIA', 'FERIAS', 'CHEFIA'), todas).decidir).toBe(false);
    expect(accoesPedidoRh(pedido('PENDENTE_RH', 'FERIAS', 'RH'), nenhuma).decidir).toBe(false);
    expect(accoesPedidoRh({ estado: 'APROVADO', tipo: 'FERIAS', etapas: [] }, todas).decidir).toBe(false);
  });
});

describe('assiduidade', () => {
  it('formata horas', () => {
    expect(formatarHoras(7.5)).toBe('7h30');
    expect(formatarHoras('8')).toBe('8h');
    expect(formatarHoras(-1.25)).toBe('−1h15');
    expect(formatarHoras(null)).toBe('—');
  });
  it('horas entre entrada e saída (com meia-noite)', () => {
    expect(horasEntre('08:00', '17:30')).toBe(9.5);
    expect(horasEntre('22:00', '06:00')).toBe(8);
    expect(horasEntre('08:00', null)).toBeNull();
  });
});

describe('avaliação', () => {
  const item = (id: number, tipo: 'CRITERIO' | 'OBJECTIVO', ambito: 'COMUM' | 'ESPECIFICO', colaborador_id: number | null, ativo = true, ordem = 0) =>
    ({ id, tipo, ambito, colaborador_id, ativo, ordem, chave: `k${id}`, nome: `I${id}` }) as ItemAvaliacao;
  it('itens comuns e específicos do colaborador, activos, por ordem', () => {
    const itens = [item(1, 'CRITERIO', 'COMUM', null, true, 2), item(2, 'CRITERIO', 'ESPECIFICO', 7, true, 1), item(3, 'CRITERIO', 'ESPECIFICO', 8), item(4, 'CRITERIO', 'COMUM', null, false), item(5, 'OBJECTIVO', 'COMUM', null)];
    expect(itensAplicaveis(itens, 7, 'CRITERIO').map((i) => i.id)).toEqual([2, 1]);
  });
  it('classificação pela nota', () => {
    expect(classificar(4.5)).toBe('Excelente');
    expect(classificar('3.49')).toBe('Bom');
    expect(classificar(1.2)).toBe('Insuficiente');
    expect(classificar(null)).toBeNull();
  });
  it('pesos 360º', () => {
    expect(validarPesos360({ CHEFIA: 50, AUTO: 10, PARES: 20, SUBORDINADOS: 20 })).toBeNull();
    expect(validarPesos360({ CHEFIA: 50, AUTO: 10, PARES: 20, SUBORDINADOS: 10 })).toMatch(/somam 90/);
    expect(validarPesos360({ CHEFIA: 0, AUTO: 50, PARES: 50 })).toMatch(/chefia/);
  });
});

describe('exportação CSV', () => {
  it('separador «;», aspas quando necessário e Kz com vírgula', () => {
    expect(gerarCsv(['A', 'B'], [['x;y', 'diz "olá"'], [1, null]])).toBe('A;B\r\n"x;y";"diz ""olá"""\r\n1;');
    expect(kzCsv('1234.5')).toBe('1234,50');
  });
});
