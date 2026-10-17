import { describe, expect, it } from 'vitest';
import { construirDocumento } from '@/componentes/impressao/documento';
import type { Balanco } from '../api';
import { anexosEscolhidos, dataExtenso, htmlIndice, membrosConselho, montarDocumentoRC, numerarIndice, opcoesDocumentoRC, paginasDasAncoras, PAGINA_PROVISORIA, rodapeRC, type DadosRC, type EntradaDocumentoRC } from './documentoRC';

const dados: DadosRC = {
  ano: 2026,
  ano_anterior: 2025,
  encerrado: true,
  moeda: 'AKZ',
  gerado_em: '2027-01-10T10:00:00Z',
  empresa: { nome: 'Empresa Fictícia, Lda', nif: '5000000000', endereco: 'Rua A, Luanda' },
  n: {
    indicadores: { rl: '1500000.00', vendas_prestacoes: '10000000.00', margem_bruta: '6000000.00', ebitda: '2000000.00', ebit: '1800000.00', activo: '6000000.00', capital_proprio: '3000000.00', passivo_corrente: '500000.00', liquidez_geral: 2.5, roe: 0.5, rai: '2000000.00', imposto: '500000.00', pessoal: '900000.00', cmvmc: '4000000.00' },
    fluxo: { operacionais: '800000.00', investimento: '-200000.00', financiamento: '0.00', variacao: '600000.00' },
    cmvmc: { ei: '100.00', compras: '200.00', ef: '50.00', custo: '250.00' },
    multas: '100.00',
  },
  n1: { indicadores: { rl: '-200000.00', vendas_prestacoes: '8000000.00', ebitda: '500000.00', activo: '5000000.00', roe: null, liquidez_geral: 0.8 } },
  composicao: {
    '10': [{ conta: '43.1', descricao: 'Banco <A>', n: '1000.00', n1: '500.00' }],
    '28': [{ conta: '72.1', descricao: 'Remunerações', n: '900000.00', n1: '700000.00' }],
    '35': [{ conta: '87.1', descricao: 'Imposto', n: '500000.00', n1: '0.00' }],
  },
  movimentos: { '10': [{ conta: '43.1', descricao: 'Banco', inicial: '500.00', aumentos: '700.00', diminuicoes: '200.00', final: '1000.00' }] },
  alertas: [{ tipo: 'aviso', texto: 'Linhas sem nota' }],
  colaboradores: 12,
  anexos: {
    razao_dezembro: [{ conta: '43', descricao: 'Depósitos', debito: '1000.00', credito: '0.00', saldo_devedor: '1000.00', saldo_credor: '0.00' }],
    razao_apuramento: [],
    geral_dezembro: [{ conta: '43.1', descricao: 'Banco', debito: '1000.00', credito: '0.00', saldo_devedor: '1000.00', saldo_credor: '0.00' }],
    geral_apuramento: [],
    amortizacoes: [],
  },
};

const balanco = {
  ano: 2026,
  ano_anterior: 2025,
  seccoes: {
    activo_nao_corrente: { linhas: [], total: { atual: '0.00', anterior: '0.00' } },
    activo_corrente: { linhas: [{ descricao: 'Disponibilidades', nota: '10', atual: '1000.00', anterior: '500.00' }], total: { atual: '1000.00', anterior: '500.00' } },
    capital_proprio: { linhas: [], total: { atual: '1000.00', anterior: '500.00' } },
    passivo_nao_corrente: { linhas: [], total: { atual: '0.00', anterior: '0.00' } },
    passivo_corrente: { linhas: [], total: { atual: '0.00', anterior: '0.00' } },
  },
  totais: { atual: { activo: '1000.00', passivo: '0.00', capital_proprio_passivo: '1000.00' }, anterior: { activo: '500.00', passivo: '0.00', capital_proprio_passivo: '500.00' } },
} as unknown as Balanco;

const entrada = (extra: Partial<EntradaDocumentoRC> = {}): EntradaDocumentoRC => ({
  dados,
  notas: {},
  textos: {},
  config: { nome: 'Empresa Fictícia, Lda', nif: '5000000000', sede: 'Rua A, Luanda', local: 'Luanda', data: '2027-03-31', director: 'Director X', contabilista: 'Contabilista Y', pct_reservas: 10, pct_transitados: 90, pct_dividendos: 0, graficos: true },
  identidade: { nome: 'Empresa Fictícia, Lda', logotipo: 'data:image/png;base64,iVBORw0KGgo=' },
  demonstracoes: { balanco },
  concluido: true,
  legendaConclusao: 'concluído em 2027-03-31 por admin',
  ...extra,
});

describe('Relatório e Contas — índice', () => {
  it('numera por nível e reinicia os níveis inferiores', () => {
    const r = numerarIndice([
      { id: 'a', titulo: 'A', nivel: 1 },
      { id: 'a1', titulo: 'A1', nivel: 2 },
      { id: 'g', titulo: 'G', nivel: 2 },
      { id: 'g1', titulo: 'G1', nivel: 3 },
      { id: 'g2', titulo: 'G2', nivel: 3 },
      { id: 'a3', titulo: 'A3', nivel: 2 },
      { id: 'b', titulo: 'B', nivel: 1 },
      { id: 'b1', titulo: 'B1', nivel: 2 },
    ]);
    expect(r.map((e) => e.numero)).toEqual(['1.', '1.1.', '1.2.', '1.2.1.', '1.2.2.', '1.3.', '2.', '2.1.']);
  });

  it('mostra números provisórios de largura fixa e depois as páginas (— quando falta)', () => {
    const ent = numerarIndice([
      { id: 'x', titulo: 'Secção <X>', nivel: 1 },
      { id: 'y', titulo: 'Y', nivel: 2 },
    ]);
    expect(htmlIndice(ent)).toContain(`<span class="rc-ind-pag">${PAGINA_PROVISORIA}</span>`);
    expect(htmlIndice(ent)).toContain('Secção &lt;X&gt;');
    const final = htmlIndice(ent, { x: 3 });
    expect(final).toContain('<span class="rc-ind-pag">3</span>');
    expect(final).toContain('<span class="rc-ind-pag">—</span>');
  });

  it('lê a página de cada âncora no HTML paginado (primeira ocorrência)', () => {
    const html = `<div class="imp-paginas"><section class="imp-pagina" data-pagina="1"><h1 data-rc-ancora="a">A</h1></section>
      <section class="imp-pagina" data-pagina="2"><h2 data-rc-ancora="b">B</h2><h2 data-rc-ancora="a">A de novo</h2></section></div>`;
    expect(paginasDasAncoras(html)).toEqual({ a: 1, b: 2 });
  });
});

describe('Relatório e Contas — documento', () => {
  it('tem capa com a empresa, índice, gestão com gráficos, demonstrações, notas e anexos, por esta ordem', () => {
    const { html, indice } = montarDocumentoRC(entrada());
    const pos = (id: string) => html.indexOf(`data-rc-ancora="${id}"`);
    const ordem = ['capa', 'indice', 'gestao', 's-economia', 's-finais', 'indicadores', 'balanco', 'notas', 'nota-10', 'anexos'];
    ordem.forEach((id) => expect(pos(id), id).toBeGreaterThan(-1));
    ordem.slice(1).forEach((id, i) => expect(pos(ordem[i]), `${ordem[i]} antes de ${id}`).toBeLessThan(pos(id)));
    // capa
    expect(html).toContain('Empresa Fictícia, Lda');
    expect(html).toContain('NIF 5000000000');
    expect(html).toContain('Rua A, Luanda');
    expect(html).toContain('31 de Março de 2027');
    expect(html).toContain('class="rc-capa-logo"');
    expect(html).toContain('Versão final');
    // gráficos SVG (barras dos agregados, liquidez com referência, fluxos, aplicação do resultado)
    expect(html).toContain('<svg');
    expect(html).toContain('Evolução dos principais agregados de resultados');
    expect(html).toContain('Referência (1,00)');
    expect(html).toContain('Fluxos de caixa por actividade');
    expect(html).toContain('Proposta de aplicação do resultado');
    // demonstrações com assinaturas; a DR e o Fluxo sem dados (sem permissão) não saem
    expect(html).toContain('Em 31 de Dezembro de 2026');
    expect(html).toContain('O Contabilista Certificado');
    expect(html).not.toContain('Demonstração dos Fluxos de Caixa');
    // notas: títulos do legado, texto escapado, movimentos, apuramento do imposto
    expect(html).toContain('Nota 10 — Disponibilidades');
    expect(html).toContain('Banco &lt;A&gt;');
    expect(html).toContain('Movimentos ocorridos durante o exercício');
    expect(html).toContain('Apuramento do imposto');
    // índice: todas as partes e secções numeradas
    expect(indice.find((e) => e.id === 'gestao')?.numero).toBe('1.');
    expect(indice.find((e) => e.id === 's-liquidez')?.numero).toBe('1.7.1.');
    expect(indice.find((e) => e.id === 'balanco')?.numero).toBe('3.1.');
    expect(indice.some((e) => e.id === 'nota-10')).toBe(true);
    // cada entrada do índice tem a sua âncora no documento
    indice.forEach((e) => expect(html).toContain(`data-rc-ancora="${e.id}"`));
    // cabeçalho corrido repetido em cada folha
    expect(html).toContain('class="imp-repetir rc-corrido"');
  });

  it('sem gráficos quando a configuração os desliga; notas excluídas não saem', () => {
    const { html } = montarDocumentoRC(entrada({ config: { ...entrada().config, graficos: false }, notas: { '10': false } }));
    expect(html).not.toContain('<svg');
    expect(html).not.toContain('Nota 10 —');
    expect(html).toContain('Nota 28 —');
  });

  it('provisório: marca na capa, no cabeçalho corrido e no rodapé; Conselho de Administração na capa', () => {
    const e = entrada({ concluido: false, config: { ...entrada().config, conselho: 'Ana — Presidente\n\nRui — Vogal' } });
    const { html } = montarDocumentoRC(e);
    expect(html).toContain('Versão provisória');
    expect(html).toContain('<b>Provisório</b>');
    expect(rodapeRC(e)).toContain('Versão provisória');
    expect(html).toContain('Conselho de Administração');
    expect(membrosConselho(e.config)).toEqual(['Ana — Presidente', 'Rui — Vogal']);
  });

  it('anexos conforme a configuração (como o legado: razão e amortizações por omissão)', () => {
    expect(anexosEscolhidos(dados, {}).map((a) => a.chave)).toEqual(['razao_dezembro']);
    expect(anexosEscolhidos(dados, { anexo_razao: false, anexo_geral: true }).map((a) => a.chave)).toEqual(['geral_dezembro']);
  });

  it('exportação: A4 vertical, sem o cabeçalho genérico, com capa, índice e gráficos no documento final', () => {
    const e = entrada();
    const opcoes = opcoesDocumentoRC(e);
    expect(opcoes).toMatchObject({ orientacao: 'retrato', papel: 'A4', cabecalho: false, blocoTitulo: false });
    expect(opcoes.rodape).toContain('Relatório e Contas · Exercício de 2026');
    const doc = construirDocumento({ ...opcoes, identidade: e.identidade, conteudo: montarDocumentoRC(e, { gestao: 3 }).html });
    expect(doc).not.toContain('class="imp-cabecalho"');
    expect(doc).toContain('rc-capa');
    expect(doc).toContain('rc-indice');
    expect(doc).toContain('<svg');
    expect(doc).toContain('.rc-capa');
  });

  it('data por extenso', () => {
    expect(dataExtenso('2027-01-05')).toBe('5 de Janeiro de 2027');
    expect(dataExtenso('')).toBe('');
  });
});
