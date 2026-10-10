import { describe, expect, it } from 'vitest';
import { xmlFolha } from '@/componentes/impressao/excel';
import { temIconeFa } from '@/componentes/fluxos';
import { colunasLancamentos } from './colunas';
import {
  CABECALHOS_TEMPLATE,
  LIMITE_COLADOS,
  SECCOES,
  alternarSeccoes,
  folhasTemplate,
  juntarColados,
  paraCaixa,
  regrasAccoes,
  separarColados,
  totaisDC,
  urlSegura,
  urlsDasDescricoes,
} from './ferramentas';
import { filtrosLancamentos, semCriterios } from './filtros';
import { destinoOrigem } from './ModaisParidade';

const vazio = new Map<number, string>();
const mapas = { codigoDiario: vazio, nomeDiario: vazio, demo: vazio, fluxo: vazio, unidade: vazio, centro: vazio };

describe('8.1.2 — colunas da lista (ordem do legado)', () => {
  const colunas = colunasLancamentos(mapas);
  it('tem todas as colunas do antigo, pela mesma ordem (+ Situação no fim)', () => {
    expect(colunas.map((c) => c.title)).toEqual([
      'ID', 'Nº Lanç.', 'Cd. Diário', 'Diário', 'Data Fiscal', 'Data Doc.', 'Data Lanç.', 'Nº Doc.', 'Ref.', 'Cd. Conta', 'Conta', 'Terceiro', 'Descrição',
      'Dem.', 'Fluxo', 'UN', 'CC', 'Projeto', 'Moeda', 'Valor na Moeda', 'Câmbio', 'Valor (Kz)', 'D/C', 'URL', 'Situação',
    ]);
  });
  it('as menos usadas começam escondidas («Colunas ▾») e as essenciais não se escondem', () => {
    expect(colunas.filter((c) => c.ocultaPorOmissao).map((c) => c.key)).toEqual(['id', 'diario', 'data_doc', 'data_lanc', 'projeto', 'moeda', 'valor_moeda', 'cambio', 'url']);
    expect(colunas.filter((c) => c.ocultavel === false).map((c) => c.key)).toEqual(['numero_lan', 'codigo_conta', 'valor']);
    expect(new Set(colunas.map((c) => c.key)).size).toBe(colunas.length);
  });
  it('o total do Valor (Kz) soma débitos e créditos em separado (impressão)', () => {
    const valor = colunas.find((c) => c.key === 'valor')!;
    expect(valor.totalImpressao?.([{ tipo_dc: 'D', valor: '100.10' }, { tipo_dc: 'C', valor: '60.05' }, { tipo_dc: 'C', valor: '40.05' }] as never)).toMatch(/^D 100,10 · C 100,10$/);
  });
});

describe('8.1.1 — colar múltiplas contas/documentos', () => {
  it('separa por linha, vírgula, «;» e tab, sem vazios nem repetidos', () => {
    expect(separarColados('21\r\n24\n\n26,21; 31\t41')).toEqual(['21', '24', '26', '31', '41']);
    expect(juntarColados(['21', '24'])).toBe('21, 24');
    expect(paraCaixa('11, 21-25, 31*')).toBe('11\n21-25\n31*');
    expect(LIMITE_COLADOS).toBe(500);
  });
  it('envia as listas e as datas do documento/lançamento à API', () => {
    const p = filtrosLancamentos({ numeroDoc: 'A, B', docInicio: '2026-01-01', docFim: '2026-01-31', criadoInicio: '2026-02-01', criadoFim: '2026-02-02' }, []);
    expect(p).toMatchObject({ numero_documento: 'A, B', data_doc_inicio: '2026-01-01', data_doc_fim: '2026-01-31', criado_inicio: '2026-02-01', criado_fim: '2026-02-02' });
  });
  it('sem critérios, as operações «a todas as filtradas» não são permitidas (classe 9 não conta)', () => {
    expect(semCriterios({}, [])).toBe(true);
    expect(semCriterios({ classe9: true }, [])).toBe(true);
    expect(semCriterios({}, ['demo'])).toBe(false);
    expect(semCriterios({ contas: '6' }, [])).toBe(false);
  });
});

describe('8.1.1 — secções do painel de filtros', () => {
  it('alterna uma secção e mostra/oculta todas', () => {
    expect(alternarSeccoes([], 'datas')).toEqual(['datas']);
    expect(alternarSeccoes(['datas'], 'datas')).toEqual([]);
    expect(alternarSeccoes(['datas', 'conta'], 'todas')).toEqual([]);
    expect(alternarSeccoes([], 'nenhuma')).toEqual(SECCOES.map((s) => s.chave));
    expect(alternarSeccoes([], 'nenhuma', ['periodo', 'sem'])).toEqual(['periodo', 'sem']);
    expect(SECCOES.map((s) => s.rotulo)).toContain('Alterar em massa');
  });
});

describe('ligações e totais', () => {
  it('só aceita http(s) (nunca javascript: nem data:)', () => {
    expect(urlSegura('https://exemplo.invalid/a.pdf')).toBe('https://exemplo.invalid/a.pdf');
    expect(urlSegura('javascript:alert(1)')).toBeNull();
    expect(urlSegura('data:text/html,x')).toBeNull();
    expect(urlSegura(null)).toBeNull();
    expect(urlsDasDescricoes(['Ver http://exemplo.invalid/x e https://exemplo.invalid/y', 'http://exemplo.invalid/x'])).toEqual(['http://exemplo.invalid/x', 'https://exemplo.invalid/y']);
  });
  it('Σ débito e Σ crédito ao cêntimo', () => {
    expect(totaisDC([{ tipo_dc: 'D', valor: '0.10' }, { tipo_dc: 'D', valor: '0.20' }, { tipo_dc: 'C', valor: '0.30' }])).toEqual({ debito: 0.3, credito: 0.3 });
  });
});

describe('8.1.1 — «Baixar Template» de importação', () => {
  it('tem os cabeçalhos que o importador reconhece e as instruções', () => {
    const [dados, instrucoes] = folhasTemplate();
    expect(CABECALHOS_TEMPLATE).toEqual(expect.arrayContaining(['Diário', 'Data Fiscal', 'No do documento', 'Conta', 'D-C', 'Valor', 'NIF terceiro', 'URL']));
    expect(dados.linhas[0].map((c) => c?.valor)).toEqual(CABECALHOS_TEMPLATE);
    expect(instrucoes.linhas.length).toBeGreaterThan(CABECALHOS_TEMPLATE.length);
    // texto como inlineStr (nunca fórmula)
    expect(xmlFolha(dados)).toContain('t="inlineStr"');
  });
});

describe('8.1.3 — acções por linha', () => {
  const todas = () => true;
  const nenhuma = () => false;
  const manual = { tipo_origem: 'MANUAL', reconciliacao_codigo: null, estorno_de_id: null, estornado_por_id: null };
  it('lançamento manual: edição completa, estornar, copiar e transferir', () => {
    expect(regrasAccoes(manual, todas)).toEqual({ edicao: 'completa', tituloEdicao: 'Editar Lançamento Completo', estornar: true, copiar: true, transferir: true });
  });
  it('documento integrado (de um módulo ou reconciliado): só a classificação', () => {
    expect(regrasAccoes({ ...manual, tipo_origem: 'VENDAS' }, todas)).toMatchObject({ edicao: 'classificacao', tituloEdicao: 'Editar Classificação (Documento Integrado)' });
    expect(regrasAccoes({ ...manual, reconciliacao_codigo: 'REC-1' }, todas).edicao).toBe('classificacao');
  });
  it('estornado: estornar/transferir desactivados com a razão; estorno não se copia', () => {
    expect(regrasAccoes({ ...manual, estornado_por_id: 9 }, todas)).toMatchObject({ estornar: 'Lançamento já estornado', transferir: 'Lançamento já estornado', copiar: true });
    expect(regrasAccoes({ ...manual, tipo_origem: 'ESTORNO', estorno_de_id: 3 }, todas)).toMatchObject({ copiar: false, edicao: 'classificacao' });
  });
  it('sem permissões: nenhuma acção de escrita', () => {
    expect(regrasAccoes(manual, nenhuma)).toEqual({ edicao: null, tituloEdicao: 'Editar Classificação', estornar: false, copiar: false, transferir: false });
  });
  it('os ícones do legado existem no catálogo', () => {
    for (const i of ['edit', 'project-diagram', 'exchange-alt', 'copy', 'truck-moving', 'external-link-alt', 'robot', 'file-download', 'file-import', 'undo', 'history', 'paste', 'hashtag', 'eye-slash', 'expand'])
      expect(temIconeFa(i), i).toBe(true);
  });
  it('destino do documento de origem: ecrã do módulo com o n.º como pesquisa', () => {
    expect(destinoOrigem({ tipo_origem: 'VENDAS', modulo: 'vendas', ecra: 'vendas_faturacao', nome: 'Vendas', instrucoes: '', documento_id: 1, numero: 'FT 2026/1' })).toBe('/m/vendas/vendas_faturacao?pesquisa=FT%202026%2F1');
    expect(destinoOrigem({ tipo_origem: 'X', modulo: 'contab', ecra: 'lancamentos', nome: 'x', instrucoes: '', documento_id: null, numero: null })).toBeNull();
  });
});
