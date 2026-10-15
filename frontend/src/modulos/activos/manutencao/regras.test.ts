import { describe, expect, it } from 'vitest';
import { custoTotal, destinoTexto, ordemAberta, quantidade, resumoRequisicao, textoPeriodicidade, unidadesDoModo } from './regras';
import type { LinhaPlanoRequisicao } from './tipos';

const linha = (o: Partial<LinhaPlanoRequisicao>): LinhaPlanoRequisicao => ({
  item_id: 1, nome: 'Óleo', tipo: 'MATERIAL', produto_id: 1, quantidade: '6.000', stock: '10.000', para_armazem: '0.000', para_compra: '0.000', bloqueada: false, porque: '', ...o,
});

describe('Manutenção — regras (js/activos_manutencao.js)', () => {
  it('custo total = materiais + serviços + mão-de-obra, ao cêntimo', () => {
    expect(custoTotal({ custo_materiais: '3000.10', custo_servicos: '4000.20', custo_mao_obra: '1500.05' })).toBe('8500.35');
    expect(custoTotal({})).toBe('0.00');
  });

  it('periodicidade e unidades por modo', () => {
    expect(textoPeriodicidade({ modo: 'TEMPO', intervalo: 3, unidade: 'MESES' })).toBe('Cada 3 meses');
    expect(textoPeriodicidade({ modo: 'CONTADOR', intervalo: 250, unidade: 'HORAS' })).toBe('Cada 250 horas de trabalho');
    expect(unidadesDoModo('CONTADOR')).toEqual(['HORAS', 'KM']);
    expect(unidadesDoModo('TEMPO')).toContain('SEMANAS');
  });

  it('estados abertos, destino e resumo da repartição armazém/compras', () => {
    expect(ordemAberta('AGUARDA_MATERIAL')).toBe(true);
    expect(ordemAberta('CONCLUIDA')).toBe(false);
    expect(destinoTexto('AMBOS')).toBe('Armazém + compras');
    expect(resumoRequisicao([
      linha({ para_armazem: '6.000' }),
      linha({ item_id: 2, para_armazem: '4.000', para_compra: '2.000' }),
      linha({ item_id: 3, tipo: 'SERVICO', para_compra: '1.000' }),
      linha({ item_id: 4, produto_id: null, bloqueada: true }),
    ])).toEqual({ armazem: 2, compra: 2, bloqueadas: 1 });
    expect(quantidade('6.000')).toBe('6');
    expect(quantidade('1.500')).toBe('1,5');
  });
});
