import { rotuloTerceiro } from './terceiro';
import type { Balanco, DemonstracaoResultados, EstadoExercicio, ValidacaoExercicio } from '../api';
import { gerarCsv } from '@/utilitarios/csv';
import { deCentimos, equilibrio, paraCentimos, somar, somarColunas } from '@/utilitarios/decimal';
import { linhasBalanco, linhasDR } from './demonstracoes';
import { alteracoes, corpoGravacao, grelhaRubricas } from './mapeamento';
import { accoesEncerramento, accoesLancamento, accoesRelatorioContas } from './regras';
import { lerLinhasActualizacao } from './rotinas';

const todas = () => true;
const nenhuma = () => false;
const so = (...permitidas: string[]) => (...c: string[]) => c.some((x) => permitidas.includes(x));

describe('aritmética em cêntimos', () => {
  it('converte sem erros de vírgula flutuante', () => {
    expect(paraCentimos('0.1')).toBe(10);
    expect(paraCentimos(0.29)).toBe(29);
    expect(paraCentimos('1234,56')).toBe(123456);
    expect(paraCentimos('')).toBe(0);
    expect(paraCentimos('abc')).toBe(0);
    expect(deCentimos(-5)).toBe('-0.05');
    expect(somar(['0.10', '0.20', 0.3])).toBe('0.60');
    expect(somar(['98765432100.00', '0.01'])).toBe('98765432100.01');
  });

  it('soma colunas dos mapas', () => {
    const t = somarColunas([{ d: '10.10', c: '1.00' }, { d: '0.20', c: null as unknown as string }], ['d', 'c']);
    expect(t).toEqual({ d: '10.30', c: '1.00' });
  });
});

describe('equilíbrio D = C', () => {
  it('aceita lançamento equilibrado com débitos e créditos', () => {
    const e = equilibrio([{ tipo_dc: 'D', valor: 100.1 }, { tipo_dc: 'C', valor: 60 }, { tipo_dc: 'C', valor: '40.10' }]);
    expect(e).toEqual({ debito: '100.10', credito: '100.10', diferenca: '0.00', equilibrado: true, valido: true });
  });

  it('recusa desequilíbrio e indica a diferença', () => {
    const e = equilibrio([{ tipo_dc: 'D', valor: 100 }, { tipo_dc: 'C', valor: 99.99 }]);
    expect(e.equilibrado).toBe(false);
    expect(e.valido).toBe(false);
    expect(e.diferenca).toBe('0.01');
  });

  it('não considera válido sem valores ou com uma só linha', () => {
    expect(equilibrio([]).valido).toBe(false);
    expect(equilibrio([{ tipo_dc: 'D', valor: 0 }, { tipo_dc: 'C', valor: 0 }]).valido).toBe(false);
    expect(equilibrio([null, undefined, { tipo_dc: 'D' }]).valido).toBe(false);
  });
});

describe('CSV', () => {
  it('usa «;», vírgula decimal e escapa aspas', () => {
    const csv = gerarCsv([{ titulo: 'Conta', valor: (l: { c: string; v: string }) => l.c }, { titulo: 'Valor', valor: (l) => l.v, numerico: true }], [{ c: 'A;"B"', v: '12.50' }]);
    expect(csv).toBe('Conta;Valor\r\n"A;""B""";12,50');
  });
});

describe('acções dos lançamentos', () => {
  const normal = [{ estorno_de_id: null, estornado_por_id: null }];
  it('só estorna lançamentos normais com permissão', () => {
    expect(accoesLancamento(normal, so('contab_lanc_transferir')).podeEstornar).toBe(true);
    expect(accoesLancamento(normal, nenhuma).podeEstornar).toBe(false);
    expect(accoesLancamento([{ estorno_de_id: null, estornado_por_id: 9 }], todas)).toEqual({ situacao: 'ESTORNADO', podeEstornar: false });
    expect(accoesLancamento([{ estorno_de_id: 3, estornado_por_id: null }], todas)).toEqual({ situacao: 'ESTORNO', podeEstornar: false });
  });
});

describe('acções do encerramento', () => {
  const passo = (numero_lan: string | null) => ({ passo: 1, titulo: '', diario: '', documento: '', numero_lan, linhas: 0, resultado: null });
  const aberto: EstadoExercicio = { ano: 2026, encerrado: false, passos: [passo(null)], resumo_classe_8: [] };
  const validado = (pode_encerrar: boolean) => ({ ano: 2026, encerrado: false, pode_encerrar, verificacoes: [], divergencias: [], avisos: [] }) as ValidacaoExercicio;

  it('encerra só depois de validar sem divergências', () => {
    expect(accoesEncerramento(aberto, undefined, todas).podeEncerrar).toBe(false);
    expect(accoesEncerramento(aberto, validado(false), todas).podeEncerrar).toBe(false);
    expect(accoesEncerramento(aberto, validado(true), todas).podeEncerrar).toBe(true);
    expect(accoesEncerramento(aberto, validado(true), so('contab_exercicio_reabrir')).podeEncerrar).toBe(false);
  });

  it('reabre só exercícios encerrados e cancela só apuramentos existentes', () => {
    expect(accoesEncerramento(aberto, undefined, todas).podeReabrir).toBe(false);
    expect(accoesEncerramento({ ...aberto, encerrado: true }, undefined, todas)).toMatchObject({ podeReabrir: true, podeExecutarPassos: false, podeCancelarApuramento: false });
    expect(accoesEncerramento(aberto, undefined, todas).podeCancelarApuramento).toBe(false);
    expect(accoesEncerramento({ ...aberto, passos: [passo('AP2026000001')] }, undefined, so('contab_exercicio_reabrir')).podeCancelarApuramento).toBe(true);
  });
});

describe('acções do Relatório e Contas', () => {
  it('conclui só com o exercício encerrado e reabre só concluídos', () => {
    expect(accoesRelatorioContas('RASCUNHO', false, todas).podeConcluir).toBe(false);
    expect(accoesRelatorioContas('RASCUNHO', true, todas)).toMatchObject({ podeConcluir: true, podeEditar: true, podeReabrir: false });
    expect(accoesRelatorioContas('APROVADO', true, todas)).toMatchObject({ concluido: true, podeEditar: false, podeConcluir: false, podeReabrir: true });
  });
});

describe('demonstrações financeiras', () => {
  const seccao = (atual: string) => ({ linhas: [{ nota: '4', descricao: ' Imobilizado ', atual, anterior: '0.00' }], total: { atual, anterior: '0.00' } });
  it('achata o balanço com totais do activo e do capital próprio + passivo', () => {
    const b = {
      seccoes: { activo_nao_corrente: seccao('10.00'), activo_corrente: seccao('5.00'), capital_proprio: seccao('7.00'), passivo_nao_corrente: seccao('0.00'), passivo_corrente: seccao('8.00') },
      totais: { atual: { activo: '15.00', passivo: '8.00', capital_proprio_passivo: '15.00' }, anterior: { activo: '0.00', passivo: '0.00', capital_proprio_passivo: '0.00' } },
    } as unknown as Balanco;
    const l = linhasBalanco(b);
    expect(l.find((x) => x.chave === 'total-activo')).toMatchObject({ tipo: 'total', atual: '15.00' });
    expect(l.find((x) => x.chave === 'total-cpp')).toMatchObject({ atual: '15.00' });
    expect(l.filter((x) => x.tipo === 'linha')).toHaveLength(5);
    expect(l.find((x) => x.chave === 'anc-0')).toMatchObject({ descricao: 'Imobilizado', nota: '4' });
  });

  it('achata a DR terminando no resultado líquido', () => {
    const t = (descricao: string, atual: string) => ({ descricao, atual, anterior: '0.00' });
    const d = {
      proveitos_operacionais: seccao('100.00'), custos_operacionais: seccao('60.00'), resultados_operacionais: t('RO', '40.00'), outros_resultados: [t('Financeiros', '-5.00')],
      resultados_antes_impostos: t('RAI', '35.00'), imposto: t('Imposto', '0.00'), resultado_actividades_correntes: t('RAC', '35.00'), resultados_extraordinarios: t('Extra', '0.00'), resultado_liquido: t('RL', '35.00'),
    } as unknown as DemonstracaoResultados;
    const l = linhasDR(d);
    expect(l[l.length - 1]).toMatchObject({ chave: 'rl', tipo: 'total', atual: '35.00' });
    expect(l.some((x) => x.descricao === 'Financeiros')).toBe(true);
  });
});

describe('actualização em massa', () => {
  it('lê linhas coladas e ignora o cabeçalho', () => {
    expect(lerLinhasActualizacao('id;campo;valor\n10;descricao;Texto; com ponto e vírgula\n\n11\tcodigo_conta\t6211\n12,nota_demonstracao_id,')).toEqual([
      { lancamento_id: 10, campo_a_modificar: 'descricao', novo_valor: 'Texto; com ponto e vírgula' },
      { lancamento_id: 11, campo_a_modificar: 'codigo_conta', novo_valor: '6211' },
      { lancamento_id: 12, campo_a_modificar: 'nota_demonstracao_id', novo_valor: null },
    ]);
  });
});

describe('mapeamento de salários', () => {
  it('envia só as células alteradas (as limpas seguem com conta nula)', () => {
    const original = grelhaRubricas([
      { infotipo_salarial_id: 1, tipo_organizacao_id: 42, avencado: false, numero_conta: '7212' },
      { infotipo_salarial_id: 2, tipo_organizacao_id: null, avencado: true, numero_conta: '7213' },
    ]);
    const editado = { ...original, '1|42': '7214', '2|AV': '', '3|42': '7220' };
    const corpo = corpoGravacao(alteracoes(original, editado), []);
    expect(corpo.rubricas).toEqual(
      expect.arrayContaining([
        { infotipo_salarial_id: 1, tipo_organizacao_id: 42, avencado: false, numero_conta: '7214' },
        { infotipo_salarial_id: 2, tipo_organizacao_id: null, avencado: true, numero_conta: null },
        { infotipo_salarial_id: 3, tipo_organizacao_id: 42, avencado: false, numero_conta: '7220' },
      ]),
    );
    expect(corpo.rubricas).toHaveLength(3);
    expect(alteracoes(original, { ...original })).toEqual([]);
  });
});

describe('terceiro nas linhas de lançamentos e no razão', () => {
  it('mostra o nome (e o NIF quando pedido) em vez do #id', () => {
    expect(rotuloTerceiro({ id: 7, nome: ' Cliente A ', nif: '5000000001' }, 7)).toBe('Cliente A');
    expect(rotuloTerceiro({ id: 7, nome: 'Cliente A', nif: '5000000001' }, 7, true)).toBe('Cliente A (NIF 5000000001)');
    expect(rotuloTerceiro({ id: 7, nome: null, nif: null }, 7)).toBe('#7');
    expect(rotuloTerceiro(undefined, 9)).toBe('#9');
    expect(rotuloTerceiro(null, null)).toBe('—');
  });
});
