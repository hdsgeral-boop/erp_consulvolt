import { describe, expect, it } from 'vitest';
import { lerCsvImportacao, lerLinhasFolha } from '../comum/regras';
import { haDiferenca } from './AlertaReconciliacao';
import {
  anosMapa, anosTexto, anosVida, bloqueioEliminar, corEstadoActivo, corTipoManutencao, destinoActivo, dicaEditar, estadoManutencao, iconeEditar,
  manutencaoPlaneada, NOME_TEMPLATE_ACTIVOS, ouTracos, percentagem, taxaMapa, taxaPorVidaUtil, valorOuHifen, vidaUtilMeses,
} from './regrasInventario';

const nbsp = (s: string) => s.replace(/[  ]/g, ' ');

describe('Inventário de Activos (13.1.1)', () => {
  it('editar: cadeado e dica «Edição Bloqueada (Processado)» com amortizações', () => {
    expect(dicaEditar({ bloqueado: true })).toBe('Edição Bloqueada (Processado)');
    expect(iconeEditar({ bloqueado: true })).toBe('lock');
    expect(dicaEditar({ bloqueado: false })).toBe('Editar');
    expect(iconeEditar({})).toBe('edit');
  });

  it('eliminar bloqueado quando processado', () => {
    expect(bloqueioEliminar({ bloqueado: true })).toBe('Bloqueado: Amortizações Processadas');
    expect(bloqueioEliminar({ bloqueado: false })).toBe(false);
  });

  it('vida útil «48 m» e taxa 100/(vida/12) com 2 casas', () => {
    expect(vidaUtilMeses(48)).toBe('48 m');
    expect(vidaUtilMeses(null)).toBe('---');
    expect(taxaPorVidaUtil(48)).toBe('25,00%');
    expect(taxaPorVidaUtil(96)).toBe('12,50%');
    expect(taxaPorVidaUtil(0)).toBe('---');
  });

  it('textos vazios «---» e estado em verde só quando ACTIVO', () => {
    expect(ouTracos(null)).toBe('---');
    expect(ouTracos('')).toBe('---');
    expect(ouTracos('UN01')).toBe('UN01');
    expect(corEstadoActivo('ACTIVO')).toBe('success');
    expect(corEstadoActivo('ABATIDO')).toBe('error');
    expect(destinoActivo(7)).toBe('/m/activos/activos/7');
  });

  it('aviso de reconciliação só acima de 1 cêntimo', () => {
    expect(haDiferenca('0.01')).toBe(false);
    expect(haDiferenca('0.02')).toBe(true);
    expect(haDiferenca(null)).toBe(false);
  });

  it('o importador aceita os cabeçalhos do modelo do sistema anterior', () => {
    const r = lerLinhasFolha([{
      'Cd. Inventário': 'AST-001', 'Descrição': 'Computador Fixo', 'Valor Aquisição (Kz)': 250000, 'Categoria (Nome Exacto ou Novo)': 'Equipamento Informatico',
      'Meses de Vida Total': 48, 'Anos Já Amortizados': 2, 'Amortização Acumulada (Kz)': 125000, 'Ano Findo da Amort. Acumulada (Ex: 2025)': 2025,
      'Data Aquisição (AAAA-MM-DD)': '2023-01-15',
    }]);
    expect(r.ignoradas).toEqual([]);
    expect(r.linhas[0]).toEqual({
      codigo: 'AST-001', descricao: 'Computador Fixo', valor_aquisicao: 250000, categoria: 'Equipamento Informatico', vida_util: 48,
      anos_amortizados: 2, amortizacao_acumulada: 125000, ano_amortizacao_acumulada: 2025, data_aquisicao: '2023-01-15',
    });
  });

  it('o modelo do sistema novo continua a ser lido (CSV)', () => {
    const r = lerCsvImportacao('Código;Descrição;Valor de aquisição;Categoria;Vida útil (meses)\n;Viatura;4500000;Transporte;48');
    expect(r.linhas[0]).toEqual({ descricao: 'Viatura', valor_aquisicao: 4500000, categoria: 'Transporte', vida_util: 48 });
    expect(NOME_TEMPLATE_ACTIVOS).toMatch(/\.xlsx$/);
  });
});

describe('Categorias e Taxas (13.2.1)', () => {
  it('«Taxa (%)» com 2 casas', () => {
    expect(percentagem('25')).toBe('25,00%');
    expect(percentagem(null)).toBe('0,00%');
    expect(percentagem('12.5')).toBe('12,50%');
  });
});

describe('Manutenções (13.3.1)', () => {
  it('sem estado = PLANEADA (botão «Executar»); concluída mostra ✓✓', () => {
    expect(manutencaoPlaneada(null)).toBe(true);
    expect(manutencaoPlaneada('PLANEADA')).toBe(true);
    expect(manutencaoPlaneada('CONCLUIDA')).toBe(false);
    expect(manutencaoPlaneada('concluida')).toBe(false);
    expect(estadoManutencao(undefined)).toBe('PLANEADA');
    expect(estadoManutencao('concluida')).toBe('CONCLUIDA');
  });

  it('cores do tipo: preventiva azul, correctiva laranja', () => {
    expect(corTipoManutencao('PREVENTIVA')).toBe('processing');
    expect(corTipoManutencao('CORRECTIVA')).toBe('warning');
  });
});

describe('Mapa de Amortizações (13.5.1)', () => {
  it('«Anos» com 1 casa e «Taxa %» FIXA', () => {
    expect(anosTexto('4.0')).toBe('4,0');
    expect(anosTexto(null)).toBe('0');
    expect(anosVida(48)).toBe('4,0');
    expect(anosVida(30)).toBe('2,5');
    expect(taxaMapa('FIXA')).toBe('FIXA');
    expect(taxaMapa('25.00')).toBe('25,00%');
  });

  it('valores zero aparecem como «-»', () => {
    const fmt = (v: string) => `${v} Kz`;
    expect(valorOuHifen('0.00', fmt)).toBe('-');
    expect(valorOuHifen(null, fmt)).toBe('-');
    expect(valorOuHifen('10.00', fmt)).toBe('10.00 Kz');
  });

  it('anos do filtro: de 2023 ao ano seguinte, incluindo o seleccionado', () => {
    expect(anosMapa(2026)).toEqual([2023, 2024, 2025, 2026, 2027]);
    expect(anosMapa(2028)).toEqual([2023, 2024, 2025, 2026, 2027, 2028, 2029]);
    expect(anosMapa(2026, 2020)[0]).toBe(2020);
    expect(nbsp(percentagem(12345.5))).toBe('12 345,50%');
  });
});
