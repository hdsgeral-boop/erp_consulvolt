import { describe, expect, it } from 'vitest';
import type { DetalheProdutividade, ItemProdutividade, PeriodoFerias, PeriodoProdutividade, PeriodoSalarial, ResultadoSalarial, ResumoFerias } from './api';
import { normalizarMesAno } from './calcular/mesAno';
import { contratoAssinaturaHtml } from './contratos/contratoAssinatura';
import { barraNoAno, conflitos, filtrarPorEstado, periodosPorColaborador, rotuloChip, sobrepoe, totaisFerias } from './ferias/regrasFerias';
import { periodosProcessados } from './folha/ListaProcessamentos';
import { colunasFolha, csvFolha, separarFolhas, totaisFolha } from './folha/regrasFolha';
import { periodoActualProdutividade, resumoProdutividade } from './produtividade/regrasProdutividade';
import { cicloActivo, textoProcedimentos } from './avaliacao360/textoComunicado';

/** Paridade visual de RH com o sistema anterior (secção 6 do relatório): regras de apresentação. Dados fictícios. */

const res = (id: number, nome: string, extra: Partial<ResultadoSalarial> = {}): ResultadoSalarial => ({
  colaborador_id: id, nome, nif: `00000000${id}`, tipo_organizacao_id: 1, unidade_negocio_id: null, centro_custo_id: null, avencado: false, reformado: false,
  dias_contrato: '22.00', dias_trabalhados: '22.00', bruto: '220000.00', base_inss: '200000.00', inss_trabalhador: '6000.00', inss_patronal: '16000.00',
  isencoes: '20000.00', base_irt: '194000.00', irt: '19540.00', descontos: '0.00', liquido: '194460.00', avisos: [], modo_calculo: 'ATUAL',
  rubricas: [
    { nome: 'Salário Base', tipo: 'VENCIMENTO', valor: '200000.00', infotipo_id: 1 },
    { nome: 'Subsídio de Alimentação', tipo: 'VENCIMENTO', valor: '20000.00', infotipo_id: 2 },
  ],
  ...extra,
});

describe('6.2.1 — «Digitar Mês/Ano»', () => {
  it('normaliza e valida MM/AAAA', () => {
    expect(normalizarMesAno('1/2026')).toBe('01/2026');
    expect(normalizarMesAno('09-2026')).toBe('09/2026');
    expect(normalizarMesAno(' 012026 ')).toBe('01/2026');
    expect(normalizarMesAno('13/2026')).toBeNull();
    expect(normalizarMesAno('ab/2026')).toBeNull();
  });
});

describe('6.3 — Processamentos e folhas separadas', () => {
  it('a lista mostra por omissão só os períodos encerrados/validados', () => {
    const ps = [{ id: 1, estado: 'ABERTO' }, { id: 2, estado: 'VALIDADO' }, { id: 3, estado: 'FECHADO' }] as PeriodoSalarial[];
    expect(periodosProcessados(ps, false).map((p) => p.id)).toEqual([2, 3]);
    expect(periodosProcessados(ps, true)).toHaveLength(3);
  });

  it('separa colaboradores e avençados, uma coluna por rubrica e linha TOTAIS (sem recalcular)', () => {
    const nome = (r: ResultadoSalarial) => r.nome ?? '';
    const avencado = res(3, 'Prestador Teste', { avencado: true, inss_trabalhador: '0.00', inss_patronal: '0.00', irt: '14300.00', liquido: '205700.00',
      rubricas: [{ nome: 'Honorários', tipo: 'VENCIMENTO', valor: '220000.00', infotipo_id: 9 }] });
    const folhas = separarFolhas([res(2, 'Rui'), avencado, res(1, 'Ana')], nome);
    expect(folhas.colaboradores.map(nome)).toEqual(['Ana', 'Rui']);
    expect(folhas.avencados.map(nome)).toEqual(['Prestador Teste']);

    const cols = colunasFolha(folhas.colaboradores, nome);
    const titulos = cols.map((c) => c.titulo);
    expect(titulos.slice(0, 5)).toEqual(['Nº', 'Colaborador', 'NIF', 'D.C.', 'D.T.']);
    expect(titulos).toContain('Sal. Base');
    expect(titulos).toContain('S. Alim');
    expect(titulos).toContain('INSS 3%');
    expect(titulos).toContain('INSS Patronal 8%');
    const tot = totaisFolha(folhas.colaboradores, cols);
    expect(tot.bruto).toBe('440000.00');
    expect(tot.liquido).toBe('388920.00');

    const colsAv = colunasFolha(folhas.avencados, nome).map((c) => c.titulo);
    expect(colsAv).toContain('IRT Grupo B 6,5%');
    expect(colsAv).not.toContain('INSS 3%');
  });

  it('Exportar CSV: separador «;», vírgula decimal, TOTAIS e sem fórmulas', () => {
    const nome = (r: ResultadoSalarial) => r.nome ?? '';
    const linhas = [res(1, '=HYPERLINK("x")')];
    const csv = csvFolha(linhas, colunasFolha(linhas, nome));
    expect(csv.split('\r\n')[0]).toContain('Colaborador;NIF;D.C.;D.T.');
    expect(csv).toContain("'=HYPERLINK");
    expect(csv).toContain('220000,00');
    expect(csv).toContain('TOTAIS (1)');
  });
});

describe('6.5.1 — Contrato para assinatura', () => {
  it('escapa os dados vindos da base', () => {
    const html = contratoAssinaturaHtml({
      contrato: { id: 1, colaborador_id: 1, remuneracoes: [], dias_contrato_mes: 22, data_inicio: '2026-01-01', data_fim: null, estado: 'ACTIVO', horas_por_dia: '8', codigo_moeda: 'AOA', produtividade: null },
      empresa: { nome: 'Empresa <b>Teste</b>' }, colaborador: { nome: 'Ana <script>' }, rubrica: () => 'Salário Base', hoje: '2026-10-09',
    });
    expect(html).toContain('Empresa &lt;b&gt;Teste&lt;/b&gt;');
    expect(html).not.toContain('<script>');
    expect(html).toContain('Contrato de Trabalho');
  });
});

describe('6.5.5 — Programa de férias', () => {
  const p = (id: number, colab: number, ini: string, fim: string, estado = 'PLANEADO', dias = 5): PeriodoFerias =>
    ({ id, colaborador_id: colab, ano: 2026, data_inicio: ini, data_fim: fim, dias, direito: null, estado, observacoes: null, pedido_portal_colaborador_id: null });
  const periodos = [p(1, 1, '2026-09-17', '2026-09-30', 'APROVADO', 10), p(2, 1, '2026-09-28', '2026-10-02'), p(3, 2, '2026-12-28', '2027-01-08'), p(4, 1, '2026-09-20', '2026-09-22', 'CANCELADO')];
  const resumo = [
    { colaborador_id: 1, nome: 'Ana', direito: 22, marcados: 15, aprovados: 10, gozados: 0, pedidos: 0, saldo: 7 },
    { colaborador_id: 2, nome: 'Rui', direito: 22, marcados: 5, aprovados: 0, gozados: 0, pedidos: 0, saldo: 17 },
    { colaborador_id: 3, nome: 'Eva', direito: 22, marcados: 0, aprovados: 0, gozados: 0, pedidos: 0, saldo: 22 },
  ] as ResumoFerias[];

  it('sobreposições ignoram os cancelados e outros colaboradores', () => {
    expect(sobrepoe(periodos[0], periodos[1])).toBe(true);
    expect([...conflitos(periodos)].sort()).toEqual([1, 2]);
  });

  it('chips, filtro por estado e cartões', () => {
    expect(rotuloChip(periodos[0])).toBe('17/09–30/09 · 10 d');
    const por = periodosPorColaborador(periodos);
    expect(filtrarPorEstado(resumo, por, 'SEM').map((r) => r.nome)).toEqual(['Eva']);
    expect(filtrarPorEstado(resumo, por, 'APROVADO').map((r) => r.nome)).toEqual(['Ana']);
    expect(totaisFerias(resumo, periodos)).toEqual({ colaboradores: 3, direito: 66, planeados: 20, gozados: 0, sem: 1, sobreposicoes: 1 });
  });

  it('barras do mapa anual cortadas ao ano', () => {
    const b = barraNoAno(periodos[2], 2026);
    expect(b).not.toBeNull();
    expect((b?.esquerda ?? 0) + (b?.largura ?? 0)).toBeCloseTo(100, 5);
    expect(barraNoAno(p(9, 1, '2025-01-01', '2025-01-10'), 2026)).toBeNull();
  });
});

describe('6.5.7 — Subsídio de produtividade', () => {
  it('abre no período aberto mais recente', () => {
    const ps = [{ id: 1, mes: '2026-08', estado: 'FECHADO' }, { id: 2, mes: '2026-09', estado: 'ABERTO' }, { id: 3, mes: '2026-10', estado: 'FECHADO' }] as PeriodoProdutividade[];
    expect(periodoActualProdutividade(ps)?.id).toBe(2);
    expect(periodoActualProdutividade(ps.filter((x) => x.estado === 'FECHADO'))?.id).toBe(3);
    expect(periodoActualProdutividade([])).toBeUndefined();
  });

  it('resumo por colaborador com itens, rubricas e total', () => {
    const det = {
      elegiveis: [{ colaborador_id: 1, nome: 'Ana', itens: {} }],
      registos: [
        { id: 1, colaborador_id: 1, item_produtividade_id: 7, data: null, quantidade: '10', preco_unitario: '100', valor: '1000.00', quantidade_considerada: '8', observacoes: null, origem: null },
        { id: 2, colaborador_id: 1, item_produtividade_id: 7, data: null, quantidade: '2', preco_unitario: '100', valor: '200.00', quantidade_considerada: null, observacoes: null, origem: null },
      ],
    } as unknown as DetalheProdutividade;
    const item = { id: 7, codigo: 'CX', descricao: 'Caixas', infotipo_salarial_id: 5 } as ItemProdutividade;
    const r = resumoProdutividade(det, (id) => (id === 7 ? item : undefined));
    expect(r).toHaveLength(1);
    expect(r[0]).toMatchObject({ nome: 'Ana', registos: 2, total: '1200.00', rubricas: [5] });
    expect(r[0].itens[0]).toEqual({ rotulo: 'CX Caixas', quantidade: '10', valor: '1200.00' });
  });
});

describe('6.5.11 — Configuração da avaliação', () => {
  it('abre o ciclo activo', () => {
    expect(cicloActivo([{ id: 1, estado: 'FECHADO', ano: 2025 }, { id: 2, estado: 'RASCUNHO', ano: 2027 }, { id: 3, estado: 'ABERTO', ano: 2026 }])?.id).toBe(3);
    expect(cicloActivo([{ id: 1, estado: 'FECHADO', ano: 2025 }, { id: 2, estado: 'RASCUNHO', ano: 2027 }])?.id).toBe(2);
  });

  it('gera o texto do comunicado a partir da configuração', () => {
    const t = textoProcedimentos({
      nome: 'Avaliação Anual 2026', data_inicio: '2026-01-01', data_fim: '2026-12-31', pesos: { CHEFIA: 50, AUTO: 10, PARES: 20, SUBORDINADOS: 20 }, minimo_anonimato: 3,
      prazos: { respostas_ate: '2027-01-20', chefia_ate: '2027-01-30', dias_conhecimento: 10, dias_contestacao: 10 }, feedback: { periodicidade: 'TRIMESTRAL' }, bonificacao: { metodo: 'NENHUM' },
    });
    expect(t).toContain('«Avaliação Anual 2026»');
    expect(t).toContain('01/01/2026 a 31/12/2026');
    expect(t).toContain('Chefia 50%, Autoavaliação 10%, Pares 20%, Subordinados 20%');
    expect(t).toContain('periodicidade trimestral');
    expect(t).not.toContain('Bonificação:');
    expect(t).not.toMatch(/\n\n\n/);
  });
});
