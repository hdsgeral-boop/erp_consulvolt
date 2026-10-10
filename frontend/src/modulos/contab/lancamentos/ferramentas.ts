/**
 * Regras puras do ecrã Lançamentos (paridade 8.1.1–8.1.3 com js/ui_lancamentos.js) — testáveis sem React:
 * colar listas, secções do painel de filtros, ligações seguras, totais D/C, template de importação e acções por linha.
 */
import { TIPO_XLSX, ficheirosXlsx, zipSemCompressao, type CelulaExcel, type FolhaExcel } from '@/componentes/impressao/excel';
import type { LinhaLancamento } from '../api';

/** Linha da lista com os campos que só a lista devolve (8.1.2). */
export type LinhaLista = LinhaLancamento & {
  criado_em?: string | null;
  url_documento?: string | null;
  codigo_projeto?: string | null;
  conta_descricao?: string | null;
  taxa_cambio_manual?: boolean;
};

// --- «Colar múltiplas contas/documentos» ---------------------------------------------------------------------------

/** Máximo de valores colados (o servidor recusa acima disto). */
export const LIMITE_COLADOS = 500;

/** Texto colado do Excel (um por linha) ou separado por vírgula, «;» ou tab → valores sem vazios nem repetidos. */
export function separarColados(texto: string): string[] {
  return [...new Set(texto.split(/[\r\n,;\t]+/).map((v) => v.trim()).filter(Boolean))];
}

/** Valores → texto do campo de filtro (como o legado: «a, b, c»). */
export function juntarColados(valores: string[]): string {
  return valores.join(', ');
}

/** Texto do campo → conteúdo da caixa de colar (um por linha), como o legado ao abrir o modal. */
export function paraCaixa(texto?: string): string {
  return separarColados(texto ?? '').join('\n');
}

// --- Secções do painel de filtros («Mostrar/Ocultar todas») ---------------------------------------------------------

export type Seccao = 'periodo' | 'datas' | 'conta' | 'ident' | 'terceiro' | 'sem' | 'massa';

export const SECCOES: { chave: Seccao; rotulo: string }[] = [
  { chave: 'periodo', rotulo: 'Período fiscal' },
  { chave: 'datas', rotulo: 'Datas doc./lanç.' },
  { chave: 'conta', rotulo: 'Conta e diário' },
  { chave: 'ident', rotulo: 'Identificação' },
  { chave: 'terceiro', rotulo: 'Terceiro e descrição' },
  { chave: 'sem', rotulo: 'Linhas sem' },
  { chave: 'massa', rotulo: 'Alterar em massa' },
];

/** Preferência do browser (a mesma chave do legado). */
export const CHAVE_SECCOES = 'lancamentos.filtrosOcultos';

/** Alterna uma secção, ou mostra/oculta todas (legado: _lancFiltrosVistas). */
export function alternarSeccoes(ocultas: Seccao[], accao: Seccao | 'todas' | 'nenhuma', disponiveis: Seccao[] = SECCOES.map((s) => s.chave)): Seccao[] {
  if (accao === 'todas') return [];
  if (accao === 'nenhuma') return [...disponiveis];
  return ocultas.includes(accao) ? ocultas.filter((s) => s !== accao) : [...ocultas, accao];
}

export function lerSeccoesOcultas(): Seccao[] {
  try {
    const v = JSON.parse(localStorage.getItem(CHAVE_SECCOES) ?? '[]') as unknown;
    const validas = new Set<string>(SECCOES.map((s) => s.chave));
    return Array.isArray(v) ? (v.filter((x) => typeof x === 'string' && validas.has(x)) as Seccao[]) : [];
  } catch {
    return [];
  }
}

export function gravarSeccoesOcultas(ocultas: Seccao[]): void {
  try {
    localStorage.setItem(CHAVE_SECCOES, JSON.stringify(ocultas));
  } catch {
    /* preferência não essencial */
  }
}

// --- Ligações ------------------------------------------------------------------------------------------------------

/** Só http(s): o URL do documento vem de importações (nunca «javascript:» nem outros esquemas). */
export function urlSegura(url?: string | null): string | null {
  const u = (url ?? '').trim();
  return /^https?:\/\/[^\s"'<>]+$/i.test(u) ? u : null;
}

/** URLs escritos nas descrições das linhas (legado: «Documentos Externos» do fluxo do lançamento manual). */
export function urlsDasDescricoes(textos: (string | null | undefined)[]): string[] {
  const todos = textos.flatMap((t) => (t ?? '').match(/https?:\/\/[^\s"'<>]+/gi) ?? []);
  return [...new Set(todos.map((u) => urlSegura(u)).filter((u): u is string => !!u))];
}

// --- Totais (G.3) ----------------------------------------------------------------------------------------------------

/** Σ débitos e Σ créditos (cêntimos inteiros, sem erros de vírgula flutuante). */
export function totaisDC(linhas: Pick<LinhaLancamento, 'tipo_dc' | 'valor'>[]): { debito: number; credito: number } {
  let d = 0;
  let c = 0;
  for (const l of linhas) {
    const cent = Math.round(Number(l.valor) * 100);
    if (!Number.isFinite(cent)) continue;
    if (l.tipo_dc === 'D') d += cent;
    else c += cent;
  }
  return { debito: d / 100, credito: c / 100 };
}

// --- «Baixar Template» (legado: downloadJournalTemplate) ---------------------------------------------------------------

export const CABECALHOS_TEMPLATE = [
  'Diário', 'Data Fiscal', 'Data Documento', 'Data Lancamento', 'No do documento', 'Referencia', 'Conta', 'D-C', 'Valor', 'NIF terceiro',
  'Nota demonstrações', 'Nota fluxo caixa', 'Unidade de Negocio', 'Centro de Custo', 'Descrição movimento', 'URL',
];

export const INSTRUCOES_TEMPLATE: [string, string, string][] = [
  ['Diário', 'Código do Diário', 'Ex: 01, SAL'],
  ['Data Fiscal', 'Data Fiscal para Reports (DD/MM/AAAA)', 'Ex: 01/05/2026'],
  ['Data Documento', 'Data no documento original (DD/MM/AAAA)', 'Ex: 01/05/2026'],
  ['Data Lancamento', 'Data de registo no sistema - deixar vazio para usar data actual', 'Ex: 01/05/2026'],
  ['No do documento', 'Número do documento original', 'Ex: DOC001'],
  ['Referencia', 'Texto de Referência livre', 'Ex: REF-001'],
  ['Conta', 'Código da conta no Plano de Contas', 'Ex: 62.1'],
  ['D-C', 'Indicador de DÉBITO ou CRÉDITO', 'D ou C'],
  ['Valor', 'Montante do movimento', 'Ex: 10000.50'],
  ['NIF terceiro', 'NIF do terceiro (opcional)', 'Ex: 500000000'],
  ['Nota demonstrações', 'Código da nota às demonstrações', 'Ex: N1'],
  ['Nota fluxo caixa', 'Código da nota de fluxo', 'Ex: F1'],
  ['Unidade de Negocio', 'Código da Unidade de Negócio', 'Ex: UN01'],
  ['Centro de Custo', 'Código do Centro de Custo', 'Ex: 91'],
  ['Descrição movimento', 'Descrição da linha', 'Ex: Pagamento de Salários'],
  ['URL', 'Link do documento externo', 'Opcional'],
];

const texto = (valor: string, papel: CelulaExcel['papel'] = 'normal'): CelulaExcel => ({ valor, tipo: 'texto', papel });

/** Folhas do template: «Lançamentos» (só cabeçalhos, a preencher) e «Instruções» (campo, descrição, exemplo). */
export function folhasTemplate(): FolhaExcel[] {
  return [
    { nome: 'Lançamentos', linhas: [CABECALHOS_TEMPLATE.map((c) => texto(c, 'cabecalho'))], larguras: CABECALHOS_TEMPLATE.map((c) => Math.max(12, c.length + 4)) },
    {
      nome: 'Instruções',
      linhas: [
        [texto('Campo', 'cabecalho'), texto('Descrição', 'cabecalho'), texto('Exemplo', 'cabecalho')],
        ...INSTRUCOES_TEMPLATE.map((l) => l.map((v) => texto(v))),
        [],
        [texto('O Nº Lançamento (Diário + Ano + Sequencial de 6 dígitos) é gerado automaticamente na importação.', 'info')],
      ],
      larguras: [24, 60, 26],
    },
  ];
}

/** Gera e descarrega «Template_Lancamentos.xlsx» (no browser, sem dados da empresa). */
export function descarregarTemplate(): string {
  const nome = 'Template_Lancamentos.xlsx';
  const bytes = zipSemCompressao(ficheirosXlsx(folhasTemplate()));
  const url = URL.createObjectURL(new Blob([bytes as BlobPart], { type: TIPO_XLSX }));
  const a = document.createElement('a');
  a.href = url;
  a.download = nome;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  return nome;
}

// --- Acções por linha (8.1.3) ------------------------------------------------------------------------------------------

export type Pode = (...chaves: string[]) => boolean;

/** Origens que pertencem à própria Contabilidade (as restantes vêm de um módulo: só a classificação se edita aqui). */
const ORIGENS_CONTABILIDADE = ['', 'MANUAL', 'IMPORTACAO', 'ESTORNO', 'TRANSFERENCIA'];

export interface RegrasAccoes {
  /** «Editar Lançamento Completo» (estorno + novo) ou «Editar Classificação (Documento Integrado)». */
  edicao: 'completa' | 'classificacao' | null;
  tituloEdicao: string;
  estornar: boolean | string;
  copiar: boolean;
  transferir: boolean | string;
}

/**
 * Que acções mostrar numa linha (o legado mostrava-as a quem podia editar lançamentos; o servidor volta a validar tudo).
 * `string` = visível mas desactivada, com a razão na dica.
 */
export function regrasAccoes(l: Pick<LinhaLancamento, 'tipo_origem' | 'reconciliacao_codigo' | 'estorno_de_id' | 'estornado_por_id'>, pode: Pode): RegrasAccoes {
  const deModulo = !ORIGENS_CONTABILIDADE.includes((l.tipo_origem ?? '').toUpperCase());
  const integrado = deModulo || !!l.reconciliacao_codigo;
  const fechado = l.estornado_por_id ? 'Lançamento já estornado' : l.estorno_de_id ? 'É um estorno: não se estorna nem transfere' : false;
  const podeCompleta = pode('lancamentos_post') && pode('lancamentos_editar', 'contab_lanc_transferir');
  const podeClassificar = pode('lancamentos_bulk_notes', 'lancamentos_editar');
  const edicao = !integrado && !fechado && podeCompleta ? 'completa' : podeClassificar ? 'classificacao' : null;
  return {
    edicao,
    tituloEdicao: edicao === 'completa' ? 'Editar Lançamento Completo' : integrado ? 'Editar Classificação (Documento Integrado)' : 'Editar Classificação',
    estornar: pode('contab_lanc_transferir') ? fechado || true : false,
    copiar: pode('lancamentos_post') && !l.estorno_de_id,
    transferir: pode('contab_lanc_transferir') ? fechado || true : false,
  };
}
