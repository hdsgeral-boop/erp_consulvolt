import { describe, expect, it } from 'vitest';
import type { Balanco, DemonstracaoResultados, FluxoCaixa } from '../api';
import { parametrosComposicao, somaParcelas } from './ComposicaoRubrica';
import { linhasBalanco, linhasDR, linhasFluxo } from './demonstracoes';
import { documentoActualizacao, lerLinhasActualizacao, resumoActualizacao, type ResultadoActualizacao } from './rotinas';

const v = (atual: string) => ({ atual, anterior: '0.00' });

describe('cálculo das rubricas das demonstrações', () => {
  it('parâmetros: os do mapa sem «comparativo», com a rubrica, o período e a conta', () => {
    expect(parametrosComposicao({ data_inicio: '2026-01-01', data_fim: '2026-12-31', comparativo: true, diario_id: 3 }, '23', 'anterior', '621')).toEqual({
      data_inicio: '2026-01-01', data_fim: '2026-12-31', diario_id: 3, rubrica: '23', periodo: 'anterior', codigo_conta: '621',
    });
  });

  it('soma das parcelas: contas + ajustes, ou rubricas com sinal', () => {
    expect(somaParcelas({ componentes: [], contas: [{ valor: '-444.00' } as never], ajustes: [{ descricao: 'x', valor: '444.00' }] })).toBe('0.00');
    expect(
      somaParcelas({ componentes: [{ rubrica: 'a', descricao: '', sinal: 1, valor: '100.00' }, { rubrica: 'b', descricao: '', sinal: -1, valor: '-30.00' }, { rubrica: 'c', descricao: '', sinal: -1, valor: '20.00' }], contas: [], ajustes: [] }),
    ).toBe('110.00');
  });

  it('cada linha com valor das demonstrações leva a rubrica do cálculo', () => {
    const seccao = (linhas: { nota?: string | null; codigo?: string }[]) => ({ linhas: linhas.map((l) => ({ descricao: 'x', ...l, ...v('1.00') })), total: { descricao: 't', ...v('1.00') } });
    const b = {
      seccoes: { activo_nao_corrente: seccao([{ nota: '4', codigo: '4' }]), activo_corrente: seccao([]), capital_proprio: seccao([{ nota: null, codigo: 'res_liq' }]), passivo_nao_corrente: seccao([]), passivo_corrente: seccao([]) },
      totais: { atual: { activo: '1', passivo: '0', capital_proprio_passivo: '1' }, anterior: {} },
    } as unknown as Balanco;
    const rub = (ls: { tipo: string; rubrica?: string }[]) => ls.filter((x) => x.tipo !== 'seccao').map((x) => x.rubrica);
    expect(rub(linhasBalanco(b))).toEqual(['4', 'activo_nao_corrente', 'activo_corrente', 'activo', 'res_liq', 'capital_proprio', 'passivo_nao_corrente', 'passivo_corrente', 'passivo', 'capital_proprio_passivo']);
    const d = {
      proveitos_operacionais: seccao([{ nota: '23' }]), custos_operacionais: seccao([{ nota: '28' }]), resultados_operacionais: { descricao: 'r', ...v('1') },
      outros_resultados: [{ nota: '31', descricao: 'f', ...v('1') }], resultados_antes_impostos: { descricao: 'r', ...v('1') }, imposto: { nota: '35', descricao: 'i', ...v('1') },
      resultado_actividades_correntes: { descricao: 'r', ...v('1') }, resultados_extraordinarios: { nota: '34', descricao: 'e', ...v('1') }, resultado_liquido: { descricao: 'r', ...v('1') },
    } as unknown as DemonstracaoResultados;
    expect(rub(linhasDR(d))).toEqual(['23', 'total_proveitos', '28', 'total_custos', 'resultados_operacionais', '31', 'resultados_antes_impostos', '35', 'resultado_actividades_correntes', '34', 'resultado_liquido']);
    const f = {
      seccoes: { operacionais: seccao([{ codigo: '111' }, { codigo: '11' }]), investimento: seccao([]), financiamento: seccao([]) },
      variacao_caixa: v('1'), caixa_inicial: v('1'), caixa_final: v('1'),
    } as unknown as FluxoCaixa;
    expect(rub(linhasFluxo(f))).toEqual(['111', '11', 'operacionais', 'investimento', 'financiamento', 'variacao', 'caixa_inicial', 'caixa_final']);
  });
});

describe('actualização em massa', () => {
  const r: ResultadoActualizacao = {
    codigo: null, actualizadas: 0, validas: 2, simulacao: true, avisos: [],
    erros: [{ linha: 4, lancamento_id: 9, motivo: 'O campo "<x>" não existe.' }],
    previa: [
      { linha: 2, lancamento_id: 1, numero_lan: 'OD1', codigo_conta: '62', campo: 'unidade_negocio_id', anterior: 'UN1 — Norte (id 3)', novo: null, estado: 'OK', motivo: null },
      { linha: 3, lancamento_id: 2, numero_lan: 'OD1', codigo_conta: '31', campo: 'descricao', anterior: 'a', novo: 'a', estado: 'SEM_ALTERACAO', motivo: null },
      { linha: 4, lancamento_id: 9, numero_lan: null, codigo_conta: null, campo: '<x>', anterior: null, novo: 'y', estado: 'ERRO', motivo: 'O campo "<x>" não existe.' },
    ],
  };

  it('resumo da pré-visualização', () => {
    expect(resumoActualizacao(r)).toEqual({ validas: 2, erros: 1, alterar: 1, semAlteracao: 1 });
  });

  it('relatório: pré-visualização com rótulos dos campos e texto escapado; depois de aplicar sem pré-visualização, só os erros', () => {
    const html = documentoActualizacao(r);
    expect(html).toContain('Unidade de negócio (id)');
    expect(html).toContain('(vazio)');
    expect(html).toContain('&lt;x&gt;');
    expect(html).not.toContain('<x>');
    expect(documentoActualizacao({ ...r, simulacao: false, previa: [] })).toContain('<th>Motivo</th>');
  });

  it('linhas coladas: id; campo; valor (vazio = limpar)', () => {
    expect(lerLinhasActualizacao('lancamento_id;campo;valor\n5;unidade_negocio_id;\n6\tdescricao\ta;b')).toEqual([
      { lancamento_id: 5, campo_a_modificar: 'unidade_negocio_id', novo_valor: null },
      { lancamento_id: 6, campo_a_modificar: 'descricao', novo_valor: 'a;b' },
    ]);
  });
});
