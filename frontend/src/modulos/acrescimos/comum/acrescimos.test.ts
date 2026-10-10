import dayjs from 'dayjs';
import { accoesItem, contaBalancoPadrao, diferencaPlano, faixaSaldosAD, fimPorMeses, mesApi, somaQuotas, totaisSeleccao } from './regras';
import type { LinhaProposta } from './tipos';
import { colunasExcelRegistos, porReconhecer } from './excelRegistos';

const todas = () => true;
const nenhuma = () => false;

describe('acções dos registos', () => {
  it('acréscimo activo: regularizar e terminar; sem lançamentos pode eliminar', () => {
    expect(accoesItem({ tipo: 'ACRESCIMO', estado: 'ACTIVO', tem_lancamentos: false }, todas)).toEqual({
      editar: true, edicaoParcial: false, eliminar: true, regularizar: true, terminar: true, desfazer: false,
    });
  });

  it('diferimento não se regulariza; com lançamentos a edição é parcial e não se elimina', () => {
    expect(accoesItem({ tipo: 'DIFERIMENTO', estado: 'ACTIVO', tem_lancamentos: true }, todas)).toMatchObject({ regularizar: false, edicaoParcial: true, eliminar: false, terminar: true });
  });

  it('pedidos por contabilizar podem ser desfeitos; fechados não se editam', () => {
    expect(accoesItem({ tipo: 'ACRESCIMO', estado: 'A_REGULARIZAR' }, todas)).toMatchObject({ desfazer: true, regularizar: true, terminar: false });
    expect(accoesItem({ tipo: 'DIFERIMENTO', estado: 'A_TERMINAR' }, todas)).toMatchObject({ desfazer: true, terminar: false });
    expect(accoesItem({ tipo: 'ACRESCIMO', estado: 'CONCLUIDO', tem_lancamentos: true }, todas)).toMatchObject({ editar: false, eliminar: false, regularizar: false });
    expect(accoesItem({ tipo: 'ACRESCIMO', estado: 'ACTIVO' }, nenhuma)).toEqual({ editar: false, edicaoParcial: false, eliminar: false, regularizar: false, terminar: false, desfazer: false });
  });
});

describe('plano e proposta', () => {
  it('conta 37 por omissão segundo o tipo e a natureza', () => {
    const def = { contas: { ACRESCIMO_CUSTO: '3754', ACRESCIMO_PROVEITO: '3731', DIFERIMENTO_CUSTO: '3743', DIFERIMENTO_PROVEITO: null } };
    expect(contaBalancoPadrao(def, 'DIFERIMENTO', 'CUSTO')).toBe('3743');
    expect(contaBalancoPadrao(def, 'ACRESCIMO', 'PROVEITO')).toBe('3731');
    expect(contaBalancoPadrao(def, 'DIFERIMENTO', 'PROVEITO')).toBeUndefined();
    expect(contaBalancoPadrao(undefined, 'DIFERIMENTO', 'CUSTO')).toBeUndefined();
  });

  it('soma das quotas e diferença face ao valor, ao cêntimo', () => {
    const quotas = [{ valor: '33.33' }, { valor: '33.33' }, { valor: '33.34' }];
    expect(somaQuotas(quotas)).toBe('100.00');
    expect(diferencaPlano('100.00', quotas)).toBe('0.00');
    expect(diferencaPlano(100.05, quotas)).toBe('0.05');
  });

  it('totais da selecção da proposta', () => {
    const l = (chave: string, valor: string) => ({ chave, valor } as LinhaProposta);
    const linhas = [l('1|REC|2026-09', '0.10'), l('1|REC|2026-10', '0.20'), l('2|INI|2026-09', '5.00')];
    expect(totaisSeleccao(linhas, ['1|REC|2026-09', '1|REC|2026-10'])).toEqual({ n: 2, total: '0.30' });
    expect(totaisSeleccao(linhas, [])).toEqual({ n: 0, total: '0.00' });
  });

  it('datas dos modelos e mês da API', () => {
    expect(fimPorMeses(dayjs('2026-01-01'), 12).format('YYYY-MM-DD')).toBe('2026-12-31');
    expect(fimPorMeses(dayjs('2026-09-14'), 3).format('YYYY-MM-DD')).toBe('2026-12-13');
    expect(fimPorMeses(dayjs('2026-03-01'), 0).format('YYYY-MM-DD')).toBe('2026-03-31');
    expect(mesApi(dayjs('2026-09-30'))).toBe('2026-09');
  });
});

describe('cartões da lista (indicadoresAD)', () => {
  it('conta os em curso e soma o reconhecido e o por reconhecer', async () => {
    const { indicadoresAD } = await import('./regras');
    expect(
      indicadoresAD([
        { tipo: 'ACRESCIMO', valor: '1000.00', reconhecido: '250.50' },
        { tipo: 'DIFERIMENTO', valor: '1200.00', reconhecido: '200.00' },
        { tipo: 'DIFERIMENTO', valor: '300.00' },
      ]),
    ).toEqual({ acrescimos: 1, diferimentos: 2, acrescimosReconhecido: '250.50', diferimentosPorReconhecer: '1300.00' });
  });
});

describe('paridade com o legado (8.7.1–8.7.3)', () => {
  it('mês curto como o mesPT do legado', async () => {
    const { mesCurto } = await import('./regras');
    expect(mesCurto('2026-10')).toBe('Out/2026');
    expect(mesCurto('2026-01-31')).toBe('Jan/2026');
    expect(mesCurto('')).toBe('');
    expect(mesCurto('2026-13')).toBe('2026-13');
  });

  it('efeito por conta das linhas seleccionadas, ordenado pelo código', async () => {
    const { efeitoPorConta } = await import('./regras');
    const l = (chave: string, debito: string, credito: string, valor: string) => ({ chave, debito, credito, valor } as LinhaProposta);
    const linhas = [l('a', '752331', '3743', '300.10'), l('b', '3743', '752331', '1200.00'), l('c', '7521', '3754', '50.00')];
    expect(efeitoPorConta(linhas, ['a', 'b'])).toEqual([
      { conta: '3743', debito: '1200.00', credito: '300.10', saldo: '899.90' },
      { conta: '752331', debito: '300.10', credito: '1200.00', saldo: '-899.90' },
    ]);
    expect(efeitoPorConta(linhas, [])).toEqual([]);
  });

  it('recolha: os documentos já usados ficam escondidos por omissão (6 de 7 como no legado)', async () => {
    const { candidatosVisiveis, textoSemCandidatos } = await import('./regras');
    const lista = [{ id: 1, ligado: true }, ...[2, 3, 4, 5, 6, 7].map((id) => ({ id, ligado: false }))];
    expect(candidatosVisiveis(lista, false)).toHaveLength(6);
    expect(candidatosVisiveis(lista, true)).toHaveLength(7);
    expect(textoSemCandidatos('COMPRA', true)).toBe('Sem documentos com contas de gastos neste período (os restantes já foram usados).');
    expect(textoSemCandidatos('VENDA', false)).toBe('Sem documentos com contas de rendimentos neste período.');
    expect(textoSemCandidatos('DIARIO', false)).toBe('Sem documentos com contas de gastos ou rendimentos neste período.');
  });

  it('«Diferir» a partir da linha do documento (12 meses; documento na 37 sem lançamento inicial)', async () => {
    const { valoresDiferir, valorRegularizacao } = await import('./regras');
    const c = {
      fonte: 'COMPRA', id: 9, doc: 'FT 1/2026', data: '2026-09-14', terceiro_id: 4, terceiro: ' Fornecedor Exemplo ', natureza: 'CUSTO', contabilizado: true,
      unidade_negocio_id: null, centro_custo_id: 2, projeto_id: null, linhas: [{ conta: '752331', valor: '1200.00' }, { conta: '7521', valor: '300.00' }], total: '1500.00', ligado: false,
    } as const;
    const v = valoresDiferir({ ...c, linhas: [...c.linhas] }, c.linhas[1], '3743');
    expect(v).toMatchObject({
      tipo: 'DIFERIMENTO', natureza: 'CUSTO', descricao: 'FT 1/2026 — Fornecedor Exemplo', valor: '300.00', conta_resultado: '7521', conta_balanco: '3743',
      data_documento: '2026-09-14', data_inicio: '2026-09-14', data_fim: '2027-09-13', documento_em_balanco: false, reparticao: 'DIAS', centro_custo_id: 2,
      origem: { fonte: 'COMPRA', id: 9, doc: 'FT 1/2026', data: '2026-09-14', conta: '7521' },
    });
    const b = valoresDiferir({ ...c, linhas: [{ conta: '3743', valor: '900.00' }], em_balanco: true }, { conta: '3743', valor: '900.00' }, '3743');
    expect(b).toMatchObject({ conta_resultado: '', conta_balanco: '3743', documento_em_balanco: true });
    expect(valorRegularizacao({ linhas: [...c.linhas], total: c.total }, '7521')).toBe('300.00');
    expect(valorRegularizacao({ linhas: [...c.linhas], total: c.total }, '7999')).toBe('1500.00');
  });

  it('Excel dos registos com as colunas do legado e o «por reconhecer»', () => {
    expect(colunasExcelRegistos().map((c) => c.titulo)).toEqual([
      'Nº', 'Tipo', 'Natureza', 'Descrição', 'Terceiro', 'Conta resultados', 'Conta balanço', 'Início', 'Fim', 'Repartição', 'Valor', 'Reconhecido',
      'Por reconhecer', 'Estado', 'Documento origem', 'Documento regularização', 'Data limite doc.',
    ]);
    expect(porReconhecer({ valor: '1200.00', reconhecido: '300.10' })).toBe('899.90');
    expect(porReconhecer({ valor: '50.00' })).toBe('50.00');
  });
});

describe('faixa de saldos das contas 37 (legado ad_ui.js:153)', () => {
  const nbsp = (t: string) => t.replace(/[  ]/g, ' ');

  it('conta reconciliada: saldo do módulo em destaque e «Diário … · reconciliado»', () => {
    const [f] = faixaSaldosAD([
      { conta: '3743', descricao: 'Fornecimento de serviços de terceiros', modulo: '17286079500.00', diario: '17286079500.00', diferenca: '0.00' },
    ]);
    expect(f.titulo).toBe('Saldo 3743 Fornecimento de serviços de terceiros');
    expect(f.reconciliado).toBe(true);
    expect(nbsp(f.texto)).toBe('Diário 17 286 079 500,00 Kz · reconciliado');
  });

  it('diferença: lançamentos fora do módulo; contas sem saldo nem movimento ficam de fora', () => {
    const r = faixaSaldosAD([
      { conta: '3731', descricao: 'Proveitos a facturar', modulo: '0.00', diario: '0.00', diferenca: '0.00' },
      { conta: '3754', modulo: '0.00', diario: '-50.00', diferenca: '-50.00' },
    ]);
    expect(r).toHaveLength(1);
    expect(r[0]).toMatchObject({ conta: '3754', titulo: 'Saldo 3754', reconciliado: false });
    expect(nbsp(r[0].texto)).toBe('Diário -50,00 Kz · diferença -50,00 Kz (lançamentos fora do módulo)');
    expect(faixaSaldosAD(undefined)).toEqual([]);
  });
});
