import { describe, expect, it } from 'vitest';
import type { ContratoTrabalho } from './api';
import { contratoEmVigor } from './colaboradores/fichaIndividual';
import { contratoAssinaturaHtml, horasSemana, listaResponsabilidades, pctSubsidio, periodoExperimental } from './contratos/contratoAssinatura';
import { explicarCalculo, valorProdutividade } from './produtividade/regrasProdutividade';

/** Fecho da Versão Actual — RH (#7, #8, #11): minuta pela Lei 12/23, base «prémio» e contrato vigente. Dados fictícios. */
const contrato = (x: Partial<ContratoTrabalho> = {}): ContratoTrabalho => ({
  id: 1, colaborador_id: 1, remuneracoes: [], dias_contrato_mes: 22, data_inicio: '2026-01-01', data_fim: null, estado: 'ACTIVO', horas_por_dia: '8',
  codigo_moeda: 'AOA', produtividade: null, ...x,
});

describe('#8 — minuta do contrato (Lei n.º 12/23)', () => {
  const base = { empresa: { nome: 'Empresa Fictícia' }, colaborador: { nome: 'Ana Fictícia' }, rubrica: () => 'Salário Base', hoje: '2026-10-15' };

  it('regras: período experimental, horas por semana e subsídios mínimos', () => {
    expect(periodoExperimental(contrato())).toBe(60);
    expect(periodoExperimental(contrato({ data_fim: '2026-12-31' }))).toBe(30);
    expect(periodoExperimental(contrato({ data_fim: '2026-12-31', periodo_experimental_dias: 90 }))).toBe(30);
    expect(periodoExperimental(contrato({ periodo_experimental_dias: 0 }))).toBe(0);
    expect(horasSemana(8)).toBe(44);
    expect(horasSemana(6)).toBe(33);
    expect(pctSubsidio(30)).toBe(50);
    expect(pctSubsidio(100)).toBe(100);
    expect(listaResponsabilidades('- Tratar o expediente;\n\n• Organizar o arquivo.')).toEqual(['Tratar o expediente;', 'Organizar o arquivo.']);
  });

  it('cita a lei, avisa quando falta o motivo do termo e numera as responsabilidades (texto escapado)', () => {
    const aTermo = contratoAssinaturaHtml({ ...base, contrato: contrato({ data_fim: '2026-12-31' }), subsidios: { ferias: 100, natal: 40 },
      funcao: { nome: 'Técnico', objetivo_geral: 'Apoiar <b>a</b> direcção', responsabilidades: 'Tratar o expediente\nOrganizar o arquivo' } });
    expect(aTermo).toContain('Lei n.º 12/23, de 27 de Dezembro');
    expect(aTermo).toContain('Atenção — contrato incompleto');
    expect(aTermo).toContain('A termo certo');
    expect(aTermo).toContain('<strong>30 dias</strong>');
    expect(aTermo).toContain('100% do salário base');
    expect(aTermo).toContain('50% do salário base');   // o Natal nunca abaixo do mínimo legal
    expect(aTermo).toContain('Apoiar &lt;b&gt;a&lt;/b&gt; direcção');
    expect(aTermo).toContain('<ol><li>Tratar o expediente</li><li>Organizar o arquivo</li></ol>');
    expect(aTermo).toContain('<strong>44 horas semanais</strong>');

    const semTermo = contratoAssinaturaHtml({ ...base, contrato: contrato({ motivo_termo: null }), cargo: { titulo: 'Director <script>', responsabilidades: 'Dirigir' } });
    expect(semTermo).not.toContain('contrato incompleto');
    expect(semTermo).toContain('por tempo indeterminado');
    expect(semTermo).toContain('60 (sessenta) dias');
    expect(semTermo).toContain('Director &lt;script&gt;');
    expect(semTermo).not.toContain('<script>');
    expect(semTermo).toContain('(mínimos legais)');

    const comMotivo = contratoAssinaturaHtml({ ...base, contrato: contrato({ data_fim: '2026-12-31', motivo_termo: 'Obra <x>' }) });
    expect(comMotivo).not.toContain('contrato incompleto');
    expect(comMotivo).toContain('Obra &lt;x&gt;');
  });
});

describe('#11 — produtividade com base «prémio»', () => {
  const objectivo = { metrica: 'OBJECTIVO', base_calculo: 'PREMIO' as const, unidade: '%', minimo: 80, maximo: 120 };
  it('prémio × % considerada ÷ 100, com mínimo e tecto', () => {
    expect(valorProdutividade(objectivo, 95, 50000)).toBe(47500);
    expect(valorProdutividade(objectivo, 130, 50000)).toBe(60000);
    expect(valorProdutividade(objectivo, 70, 50000)).toBe(0);
    // a base «prémio» só vale no OBJECTIVO: noutra métrica é quantidade × preço
    expect(valorProdutividade({ ...objectivo, metrica: 'QUANTIDADE' }, 95, 10)).toBe(950);
  });
  it('explica a conta por extenso', () => {
    const e = explicarCalculo(objectivo, 130, 50000);
    expect(e.formula).toContain('prémio a 100%');
    expect(e.conta).toContain('(120 ÷ 100)');
    expect(e.cortes).toHaveLength(1);
    expect(explicarCalculo({ metrica: 'QUANTIDADE', base_calculo: null, unidade: 'peças', minimo: null, maximo: null }, 40, 2000).conta).toContain('40 peças × 2');
  });
});

describe('#7 — contrato vigente na ficha', () => {
  it('mostra o suspenso ou o cessado que abrange a data; senão o activo mais recente', () => {
    const antigo = contrato({ id: 1, data_inicio: '2020-01-01', data_fim: '2024-12-31', estado: 'INACTIVO' });
    const suspenso = contrato({ id: 2, data_inicio: '2025-01-01', estado: 'SUSPENSO' });
    expect(contratoEmVigor([antigo, suspenso], '2026-10-15')?.id).toBe(2);
    expect(contratoEmVigor([antigo], '2026-10-15')?.id).toBe(1);
    expect(contratoEmVigor([antigo, suspenso, contrato({ id: 3, data_inicio: '2026-01-01' })], '2026-10-15')?.id).toBe(3);
  });
});
