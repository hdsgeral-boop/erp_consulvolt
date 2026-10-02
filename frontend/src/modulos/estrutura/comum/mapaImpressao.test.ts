import { describe, expect, it } from 'vitest';
import { documentoMapaPessoal, type MapaPessoalApi } from './mapa';

const c = (previstos: number, ocupados: number) => ({ previstos, ocupados, em_aberto: Math.max(0, previstos - ocupados), acima: Math.max(0, ocupados - previstos), colaboradores: ocupados, postos: 1, massa_salarial: '1000.00' });

const d = {
  totais: c(5, 4),
  sem_unidade: c(0, 1),
  por_unidade: [],
  por_cargo: [{ cargo_funcao_id: 1, nome: 'Técnico', ...c(5, 4) }],
  ver_salarios: false,
  periodo_salarial: null,
} as unknown as MapaPessoalApi;

describe('impressão do mapa de pessoal', () => {
  it('tem indicadores e as três vistas, sem massa salarial quando não autorizada', () => {
    const html = documentoMapaPessoal(d, [], d.por_cargo, [], { ramo: true, verSalarios: false });
    expect(html).toContain('Por unidade orgânica (com as subunidades)');
    expect(html).toContain('Por cargo');
    expect(html).toContain('Por posto de trabalho');
    expect(html).not.toContain('Massa salarial');
  });

  it('acrescenta a massa salarial (coluna e indicador) quando autorizada', () => {
    const html = documentoMapaPessoal(d, [], d.por_cargo, [], { ramo: false, verSalarios: true });
    expect(html).toContain('Massa salarial (Kz)');
    expect(html).toContain('Massa salarial mensal');
  });
});
