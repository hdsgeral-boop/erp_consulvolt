import { describe, expect, it } from 'vitest';
import type { LinhaFiscal, LinhaPeriodo } from '../comum/tipos';
import { destinoSeccao, SECCOES_ACTIVOS, seccoesVisiveis } from '../comum/NavActivos';
import { eRotaFiscal } from './RotaMapa';
import { anosFiscais, anosUtilidade, celulasFiscal, filtrarFiscal, htmlMapaFiscal, percentagem, taxaCorrigida, totaisFiscal } from './regras';
import { acumuladaLinha, estadoLegado, linhaComAccoes, liquidoLinha, maisRecentesPrimeiro, quotaManual, seleccaoInicial, textoCalcular, totalMeses } from './amortizacoes';

const linha = (x: Partial<LinhaFiscal> = {}): LinhaFiscal => ({
  ativo_imobilizado_id: 1, codigo: 'AF-001', descricao: 'Viatura de teste', conta: '11.4.1', mes_aquisicao: 3, ano_aquisicao: 2024,
  mes_inicio_utilizacao: 3, ano_inicio_utilizacao: 2024, valor_aquisicao: '1200000.00', anos_vida: '4.0', taxa_categoria: '25.00', taxa_efectiva: '25.00',
  anteriores: '250000.00', exercicio: '300000.00', acumuladas: '550000.00', liquido: '650000.00', ...x,
});

describe('agregador dos Activos', () => {
  it('tem os 8 separadores do legado pela mesma ordem, com o Mapa Fiscal', () => {
    expect(SECCOES_ACTIVOS.map((s) => s.rotulo)).toEqual([
      'Inventário de Activos', 'Categorias e Taxas', 'Manutenções', 'Amortizações', 'Mapa de Amortizações', 'Mapa Fiscal', 'Abates e Vendas', 'Aquisições Pendentes',
    ]);
    expect(SECCOES_ACTIVOS.map((s) => s.icone)).toEqual(['list', 'tags', 'tools', 'percent', 'table', 'file-invoice', 'trash-alt', 'hourglass-start']);
  });

  it('o Mapa Fiscal usa a permissão do mapa e a sub-rota /fiscal', () => {
    const fiscal = SECCOES_ACTIVOS.find((s) => s.id === 'activos_mapa_fiscal')!;
    expect(destinoSeccao(fiscal)).toBe('/m/activos/activos_mapa/fiscal');
    expect(seccoesVisiveis(['activos', 'activos_mapa']).map((s) => s.id)).toEqual(['activos', 'activos_mapa', 'activos_mapa_fiscal']);
    expect(seccoesVisiveis(['activos']).map((s) => s.id)).toEqual(['activos']);
    expect(eRotaFiscal('/m/activos/activos_mapa/fiscal')).toBe(true);
    expect(eRotaFiscal('/m/activos/activos_mapa')).toBe(false);
  });
});

describe('Mapa Fiscal', () => {
  it('formata as 18 colunas do modelo (importado «Não», reavaliação «—»)', () => {
    const c = celulasFiscal(linha());
    expect(c).toHaveLength(18);
    expect(c.slice(0, 7)).toEqual(['11.4.1', 'Viatura de teste', '3', '2024', '3', '2024', 'Não']);
    expect(c[8]).toBe('4,0');
    expect(c.slice(9, 12)).toEqual(['—', '—', '—']);
    expect(c[13]).toBe('25,00%');
    expect(c[14]).toBe('—');
  });

  it('taxa corrigida só quando a vida útil não é a da categoria', () => {
    expect(taxaCorrigida({ taxa_categoria: '25.00', taxa_efectiva: '25.00' })).toBe('—');
    expect(taxaCorrigida({ taxa_categoria: '25.00', taxa_efectiva: '20.00' })).toBe('20,00%');
    expect(taxaCorrigida({ taxa_categoria: '25.00', taxa_efectiva: null })).toBe('—');
    expect(percentagem(null)).toBe('—');
    expect(anosUtilidade(null)).toBe('—');
  });

  it('filtra e soma ao cêntimo', () => {
    const ls = [linha(), linha({ ativo_imobilizado_id: 2, codigo: 'AF-002', descricao: 'Computador', conta: '11.5', valor_aquisicao: '0.10', anteriores: '0.20', exercicio: '0.10', acumuladas: '0.30', liquido: '-0.20' })];
    expect(filtrarFiscal(ls, 'comput').map((l) => l.codigo)).toEqual(['AF-002']);
    expect(filtrarFiscal(ls, '11.4').map((l) => l.codigo)).toEqual(['AF-001']);
    expect(totaisFiscal(ls)).toEqual({ valor_aquisicao: '1200000.10', anteriores: '250000.20', exercicio: '300000.10', acumuladas: '550000.30', liquido: '649999.80' });
  });

  it('HTML com o cabeçalho agrupado em 3 linhas e os totais', () => {
    const html = htmlMapaFiscal([linha({ descricao: '<b>x</b>' })], totaisFiscal([linha()]));
    expect(html.match(/<tr>/g)?.length).toBeGreaterThanOrEqual(4);
    expect(html).toContain('colspan="6"');
    expect(html).toContain('Reintegrações e Amortizações');
    expect(html).toContain('TOTAIS:');
    expect(html).toContain('&lt;b&gt;x&lt;/b&gt;');
    expect(htmlMapaFiscal([], totaisFiscal([]))).toContain('Sem activos para o ano.');
  });

  it('anos do selector incluem o escolhido', () => {
    expect(anosFiscais(2026, 2026)).toEqual([2023, 2024, 2025, 2026, 2027]);
    expect(anosFiscais(2026, 2019)[0]).toBe(2019);
  });
});

describe('Amortizações (apresentação do legado)', () => {
  const l = (x: Partial<LinhaPeriodo> = {}): LinhaPeriodo => ({
    ativo_imobilizado_id: 1, codigo: 'AF-001', descricao: 'Teste', categoria: null, centro_custo: null, unidade_negocio: null, valor_aquisicao: '1000.00',
    acumulado_anterior: '100.00', quota: '10.00', quota_calculada: '10.00', estado: 'RASCUNHO', amortizacao_id: 5, amortizacao_acumulada: '90.00', ...x,
  });

  it('estado do período: INTEGRADO, Cálculos PENDENTES ou ABERTO', () => {
    expect(estadoLegado({ estado: 'INTEGRADO', contagens: { INTEGRADO: 3 } }).rotulo).toBe('INTEGRADO');
    expect(estadoLegado({ estado: 'PARCIAL', contagens: { INTEGRADO: 1, RASCUNHO: 1 } }).chave).toBe('INTEGRADO');
    expect(estadoLegado({ estado: 'CALCULADO', contagens: { RASCUNHO: 2 } }).rotulo).toBe('Cálculos PENDENTES');
    expect(estadoLegado({ estado: 'ABERTO', contagens: { POR_CALCULAR: 2 } }).rotulo).toBe('ABERTO');
    expect(textoCalcular({ contagens: { RASCUNHO: 1 } })).toBe('Recalcular Período');
    expect(textoCalcular({ contagens: {} })).toBe('Calcular Período');
  });

  it('acumulada, líquido, quota manual e acções por linha', () => {
    expect(acumuladaLinha(l())).toBe('90.00');
    expect(acumuladaLinha(l({ amortizacao_acumulada: undefined }))).toBe('100.00');
    expect(liquidoLinha(l())).toBe('910.00');
    expect(quotaManual(l())).toBe(false);
    expect(quotaManual(l({ quota: '12.00' }))).toBe(true);
    expect(quotaManual(l({ estado: 'INTEGRADO', quota: '12.00' }))).toBe(false);
    expect(linhaComAccoes(l())).toBe(true);
    expect(linhaComAccoes(l({ estado: 'INTEGRADO' }))).toBe(false);
  });

  it('painéis em massa: ordem, selecção inicial e total', () => {
    const ps = [{ periodo: '01-2026', valor: '1.10' }, { periodo: '12-2025', valor: '2.20' }, { periodo: '03-2026', valor: '0.05' }];
    expect(maisRecentesPrimeiro(ps).map((p) => p.periodo)).toEqual(['03-2026', '01-2026', '12-2025']);
    expect(seleccaoInicial(ps)).toEqual(['01-2026', '12-2025', '03-2026']);
    expect(totalMeses(ps)).toBe('3.35');
  });
});
