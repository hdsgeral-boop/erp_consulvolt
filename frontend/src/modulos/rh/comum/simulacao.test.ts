import { describe, expect, it } from 'vitest';
import type { ResultadoSalarial } from '../api';
import { colunasRubricasFolha, pedidoSimulacaoColaborador, pedidoSimulacaoPeriodo, percentagemEfectiva, simulacaoColaboradorHtml, tabelaFolhaDetalhada, totalDescontos } from './simulacao';

/** Resultado fictício (valores calculados pelo servidor, aqui só apresentados). */
const r = (id: number, nome: string, extra: Partial<ResultadoSalarial> = {}): ResultadoSalarial => ({
  colaborador_id: id, nome, nif: `00000000${id}`, tipo_organizacao_id: 1, unidade_negocio_id: null, centro_custo_id: null, avencado: false, reformado: false,
  dias_contrato: '22.00', dias_trabalhados: '22.00', bruto: '220000.00', base_inss: '200000.00', inss_trabalhador: '6000.00', inss_patronal: '16000.00',
  isencoes: '20000.00', base_irt: '194000.00', irt: '19540.00', descontos: '5000.00', liquido: '189460.00', avisos: [], modo_calculo: 'ATUAL',
  rubricas: [
    { nome: 'Salário Base', tipo: 'VENCIMENTO', valor: '200000.00', infotipo_id: 1 },
    { nome: 'Subsídio de Alimentação', tipo: 'VENCIMENTO', valor: '20000.00', infotipo_id: 2 },
    { nome: 'Horas Extraordinárias', tipo: 'VENCIMENTO', valor: '0.00', infotipo_id: 5, horas: '0' },
    { nome: 'Dias de Trabalho', tipo: 'OUTROS', valor: '22.00', infotipo_id: 3, informativa: true },
    { nome: 'Adiantamento', tipo: 'DESCONTO', valor: '5000.00', infotipo_id: 4 },
  ],
  ...extra,
});

const semEspacos = (s: string) => s.replace(/ | /g, ' ');

describe('simulação salarial (apresentação do cálculo do servidor)', () => {
  it('totais e percentagens efectivas', () => {
    expect(totalDescontos(r(1, 'Ana'))).toBe('30540.00');
    expect(percentagemEfectiva('6000.00', '200000.00')).toBe('3 %');
    expect(percentagemEfectiva('16000.00', '200000.00')).toBe('8 %');
    expect(percentagemEfectiva('0', '0')).toBe('');
  });

  it('simulação de um colaborador: marca «Simulação», rubricas, INSS, IRT, líquido e encargo patronal', () => {
    const html = semEspacos(simulacaoColaboradorHtml(r(1, 'Ana Fictícia'), { nome: 'Ana Fictícia' }));
    expect(html).toContain('SIMULAÇÃO');
    expect(html).toContain('Salário Base');
    expect(html).toContain('Adiantamento');
    expect(html).not.toContain('Dias de Trabalho');
    expect(html).not.toContain('Horas Extraordinárias');
    expect(html).toContain('INSS trabalhador (3 %)');
    expect(html).toContain('matéria colectável 194 000,00 Kz');
    expect(html).toContain('189 460,00 Kz');
    expect(html).toContain('Custo total para a empresa');
    expect(html).toContain('236 000,00');
  });

  it('avençado: IRT Grupo B, sem INSS; período validado sem a marca', () => {
    const av = r(2, 'Prestador', { avencado: true, inss_trabalhador: '0.00', inss_patronal: '0.00' });
    const html = simulacaoColaboradorHtml(av, { nome: 'Prestador', simulacao: false });
    expect(html).toContain('IRT Grupo B (6,5 %)');
    expect(html).not.toContain('INSS trabalhador');
    expect(html).not.toContain('SIMULAÇÃO');
    expect(pedidoSimulacaoColaborador(av, { nome: 'Prestador', mesAno: '09/2026', simulacao: false }).titulo).toBe('Detalhe salarial');
    expect(pedidoSimulacaoColaborador(av, { nome: 'Prestador', mesAno: '09/2026' })).toMatchObject({ titulo: 'Simulação salarial', periodo: '09/2026', orientacao: 'retrato' });
  });

  it('mapa detalhado: todas as rubricas em colunas (as mais comuns primeiro), totais e «-» nos zeros', () => {
    const dados = [r(1, 'Ana'), r(3, 'Bruno', { rubricas: [{ nome: 'Abono de Família', tipo: 'VENCIMENTO', valor: '3000.00', infotipo_id: 9 }, { nome: 'Salário Base', tipo: 'VENCIMENTO', valor: '100000.00', infotipo_id: 1 }] })];
    expect(colunasRubricasFolha(dados).vencimentos).toEqual(['Salário Base', 'Subsídio de Alimentação', 'Abono de Família']);
    const html = semEspacos(tabelaFolhaDetalhada(dados, (x) => x.nome ?? ''));
    expect(html).toContain('<th class="imp-num">Abono de Família</th>');
    expect(html).toContain('TOTAIS (2)');
    expect(html).toContain('INSS patronal');
    expect(html).toMatch(/<td class="imp-num">-<\/td>/);
  });

  it('simulação do período: folhas separadas Colaboradores / Avençados, marca até à validação e assinaturas', () => {
    const dados = [r(1, 'Zeferino'), r(2, 'Ana', { avencado: true, inss_trabalhador: '0.00' })];
    const p = pedidoSimulacaoPeriodo({ resultados: dados, nome: (x) => x.nome ?? '', mesAno: '09/2026', estado: 'ABERTO' });
    expect(p.titulo).toBe('Simulação da folha de salários');
    const html = String(p.conteudo);
    expect(html).toContain('SIMULAÇÃO');
    expect(html).toContain('Folha de salários — Colaboradores · 1');
    expect(html).toContain('Folha de avenças — Prestadores de serviço (IRT Grupo B) · 1');
    expect(html).toContain('Aprovado por');
    const validado = pedidoSimulacaoPeriodo({ resultados: dados, nome: (x) => x.nome ?? '', mesAno: '09/2026', estado: 'VALIDADO', grupo: 'avencados' });
    expect(validado.titulo).toBe('Mapa de processamento salarial');
    expect(String(validado.conteudo)).not.toContain('SIMULAÇÃO');
    expect(String(validado.conteudo)).not.toContain('Colaboradores ·');
  });
});
