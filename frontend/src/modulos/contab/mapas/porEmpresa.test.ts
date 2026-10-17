import { describe, expect, it } from 'vitest';
import { linhaVisivelPorEmpresa, parametrosPorEmpresa, type DadosPorEmpresa } from './PorEmpresa';

const dados: DadosPorEmpresa = {
  mapa: 'dr',
  execucao: { id: 1, data_fim: '2026-12-31', moeda: 'AOA' },
  colunas: [
    { chave: 'E1', nome: 'Empresa A', empresa_id: 1, tipo: 'empresa' },
    { chave: 'TOTAL', nome: 'Consolidado', tipo: 'consolidado' },
  ],
  valores: { E1: { '22': '100.00', '23': '0.00' }, TOTAL: { '22': '100.00', '23': '0.00' } },
};

describe('mapas da holding por empresa', () => {
  it('pede o mapa sem comparativo nem quebra por terceiro', () => {
    expect(parametrosPorEmpresa({ data_inicio: '2026-01-01', data_fim: '2026-12-31', comparativo: true, modo_comparativo: 'homologo', por_terceiro: true, diario_id: 2 })).toEqual({
      data_inicio: '2026-01-01', data_fim: '2026-12-31', diario_id: 2,
    });
  });

  it('esconde as rubricas sem valores em nenhuma coluna (títulos e totais ficam)', () => {
    expect(linhaVisivelPorEmpresa({ chave: 'a', tipo: 'linha', descricao: 'Vendas', rubrica: '22' }, dados)).toBe(true);
    expect(linhaVisivelPorEmpresa({ chave: 'b', tipo: 'linha', descricao: 'Serviços', rubrica: '23' }, dados)).toBe(false);
    expect(linhaVisivelPorEmpresa({ chave: 'c', tipo: 'total', descricao: 'Total', rubrica: 'x' }, dados)).toBe(true);
  });
});
