import { describe, expect, it } from 'vitest';
import type { ResultadoSalarial } from '../api';
import { folhaSalariosHtml, seccaoHtml } from './impressao';

const r = (id: number, nome: string, liquido: string, extra: Partial<ResultadoSalarial> = {}): ResultadoSalarial => ({
  colaborador_id: id, tipo_organizacao_id: 1, unidade_negocio_id: null, centro_custo_id: null, avencado: false, reformado: false,
  dias_contrato: '22.00', dias_trabalhados: '22.00', bruto: '100000.00', base_inss: '100000.00', inss_trabalhador: '3000.00', inss_patronal: '8000.00',
  isencoes: '0.00', base_irt: '97000.00', irt: '5000.00', descontos: '0.00', liquido, avisos: [], modo_calculo: 'ATUAL', rubricas: [], nome, nif: `NIF${id}`, ...extra,
});

describe('impressão do RH', () => {
  it('folha de salários: ordenada por nome, com totais e marca de avençado', () => {
    const html = folhaSalariosHtml([r(2, 'Zeferino Teste', '92000.00'), r(1, 'Ana Teste', '92000.00', { avencado: true })], (x) => x.nome ?? '');
    expect(html.indexOf('Ana Teste (avençado)')).toBeLessThan(html.indexOf('Zeferino Teste'));
    expect(html).toContain('Totais (2)');
    expect(html).toMatch(/184.000,00/);
    expect(html).toContain('<tfoot>');
  });

  it('secção escapa o título', () => {
    expect(seccaoHtml('A & <B>', '<p>x</p>')).toContain('A &amp; &lt;B&gt;');
  });
});
