/**
 * Documento impresso / PDF do Relatório e Contas (legado: rcImprimir, js/relatorio_contas.js:1195-1310).
 *
 * Como o legado: capa (logótipo, denominação, «RELATÓRIO E CONTAS AAAA», data e local, «VERSÃO PROVISÓRIA»), índice,
 * Relatório de Gestão (textos, tabelas de indicadores e gráficos por secção, local/data e assinatura no fim), Balanço,
 * Demonstração dos Resultados e Demonstração dos Fluxos de Caixa (cada uma com «Valores expressos em» e assinaturas),
 * Notas às Contas (composição, movimentos, CMVMC na Nota 27 e apuramento do imposto na Nota 35) e Anexos escolhidos na
 * configuração. Acrescentos do sistema novo: síntese de indicadores e rácios (cartões, grandezas, rácios e alertas), índice
 * numerado com a página de cada secção, cabeçalho corrido (empresa · exercício) e rodapé «Página X de Y» em todas as folhas,
 * A4 vertical. Funções puras (testáveis); a paginação é a do motor comum (`@/componentes/impressao`).
 *
 * Números da página no índice: o documento é preparado duas vezes — a primeira (com o mesmo conteúdo e números
 * provisórios de largura fixa) dá a folha em que começa cada secção (`paginasDasAncoras`); a segunda leva os números.
 */
import dayjs from 'dayjs';
import { esc, logotipoSeguro } from '@/componentes/impressao/documento';
import { tabelaHtml, type ColunaImpressao } from '@/componentes/impressao/tabela';
import type { IdentidadeImpressao } from '@/componentes/impressao/tipos';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Balanco, DemonstracaoResultados, FluxoCaixa } from '../api';
import { linhasBalanco, linhasDR, linhasFluxo, type LinhaPlana } from '../comum/demonstracoes';
import { graficoSeccao } from './graficosRC';
import { htmlParaTexto, SECCOES, tabelaSeccao, textoActual, textoParaHtml, type ConfigGestao, type TextoGravado } from './textosGestao';

// ───────────── tipos (resposta de GET /contabilidade/relatorio-contas/{ano}) ─────────────

export type Indicadores = Record<string, string | number | null>;

export interface LinhaMovimentoNota {
  conta: string;
  descricao: string | null;
  inicial: string;
  aumentos: string;
  diminuicoes: string;
  final: string;
}

export interface DemonstracoesFixadas {
  balanco?: Balanco;
  dr?: DemonstracaoResultados;
  fluxo?: FluxoCaixa;
}

export interface DadosRC {
  ano: number;
  ano_anterior: number;
  encerrado: boolean;
  moeda: string;
  gerado_em: string;
  empresa: { nome: string; nif: string | null; endereco?: string | null };
  n: { indicadores: Indicadores; fluxo?: Record<string, unknown>; cmvmc?: Record<string, string>; multas?: string };
  n1: { indicadores: Indicadores; fluxo?: Record<string, unknown>; cmvmc?: Record<string, string>; multas?: string };
  composicao: Record<string, { conta: string; descricao: string | null; n: string; n1: string }[]>;
  movimentos?: Record<string, LinhaMovimentoNota[]>;
  alertas: { tipo: 'info' | 'aviso' | 'erro'; texto: string }[];
  colaboradores: number;
  anexos: Record<string, { conta: string; descricao: string | null; debito?: string; credito?: string; saldo_devedor?: string; saldo_credor?: string; [k: string]: unknown }[]>;
  /** Balanço/DR/Fluxo fixados na conclusão (só na fotografia de relatórios concluídos desde esta versão). */
  demonstracoes?: DemonstracoesFixadas;
}

// ───────────── constantes partilhadas com o ecrã ─────────────

export const MONETARIOS: [string, string][] = [
  ['vendas_prestacoes', 'Vendas e prestações de serviços'],
  ['margem_bruta', 'Margem bruta'],
  ['pessoal', 'Custos com o pessoal'],
  ['gastos_op', 'Gastos operacionais'],
  ['ebitda', 'EBITDA'],
  ['amortizacoes', 'Amortizações'],
  ['ebit', 'EBIT (resultado operacional)'],
  ['rai', 'Resultado antes de impostos'],
  ['imposto', 'Imposto sobre o rendimento'],
  ['rl', 'Resultado líquido'],
  ['activo', 'Activo total'],
  ['capital_proprio', 'Capital próprio'],
  ['passivo', 'Passivo total'],
  ['disponibilidades', 'Disponibilidades'],
];

export const RACIOS: [string, string, 'pct' | 'x'][] = [
  ['roe', 'Rendibilidade do capital próprio (ROE)', 'pct'],
  ['roa', 'Rendibilidade do activo (ROA)', 'pct'],
  ['ros', 'Rendibilidade das vendas (ROS)', 'pct'],
  ['margem_operacional', 'Margem operacional', 'pct'],
  ['autonomia_financeira', 'Autonomia financeira', 'pct'],
  ['solvabilidade', 'Solvabilidade', 'x'],
  ['endividamento', 'Endividamento', 'pct'],
  ['liquidez_geral', 'Liquidez geral', 'x'],
  ['liquidez_reduzida', 'Liquidez reduzida', 'x'],
  ['liquidez_imediata', 'Liquidez imediata', 'x'],
];

export function racio(v: string | number | null | undefined, tipo: 'pct' | 'x'): string {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  if (!Number.isFinite(n)) return '—';
  return tipo === 'pct' ? `${(n * 100).toLocaleString('pt-PT', { maximumFractionDigits: 2 })} %` : n.toLocaleString('pt-PT', { maximumFractionDigits: 2 });
}

export const NOMES_ANEXOS: Record<string, string> = {
  razao_dezembro: 'Balancete razão (Dezembro)',
  razao_apuramento: 'Balancete razão (apuramento)',
  geral_dezembro: 'Balancete geral (Dezembro)',
  geral_apuramento: 'Balancete geral (apuramento)',
  amortizacoes: 'Mapa de amortizações',
};

/** Títulos das notas (legado NOTAS, js/relatorio_contas.js:32-68). */
export const TITULOS_NOTAS: Record<string, string> = {
  '1': 'Actividade',
  '2': 'Políticas Contabilísticas Adoptadas na Preparação das Demonstrações Financeiras',
  '3': 'Alterações nas Políticas Contabilísticas',
  '4': 'Imobilizações Corpóreas',
  '5': 'Imobilizações Incorpóreas',
  '6': 'Investimentos em Subsidiárias e Associadas',
  '7': 'Outros Activos Financeiros',
  '8': 'Existências',
  '9': 'Contas a Receber',
  '10': 'Disponibilidades',
  '11': 'Outros Activos Correntes',
  '12': 'Capital',
  '13': 'Reservas',
  '14': 'Resultados Transitados',
  '15': 'Empréstimos de Médio e Longo Prazos',
  '16': 'Impostos Diferidos',
  '17': 'Provisões para Pensões',
  '18': 'Provisões para Outros Riscos e Encargos',
  '19': 'Contas a Pagar',
  '20': 'Empréstimos de Curto Prazo',
  '21': 'Outros Passivos Correntes',
  '22': 'Vendas',
  '23': 'Prestações de Serviços',
  '24': 'Outros Proveitos Operacionais',
  '25': 'Variação nos Produtos Acabados e em Vias de Fabrico',
  '26': 'Trabalhos para a Própria Empresa',
  '27': 'Custo das Mercadorias Vendidas e das Matérias Consumidas',
  '28': 'Custos com o Pessoal',
  '29': 'Amortizações',
  '30': 'Outros Custos e Perdas Operacionais',
  '31': 'Resultados Financeiros',
  '32': 'Resultados de Filiais e Associadas',
  '33': 'Resultados Não Operacionais',
  '34': 'Resultados Extraordinários',
  '35': 'Imposto sobre o Rendimento',
};

const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

/** «2027-03-31» → «31 de Março de 2027» (legado dataExtenso); outros valores ficam como estão. */
export function dataExtenso(iso: unknown): string {
  const s = dayjs.isDayjs(iso) ? iso.format('YYYY-MM-DD') : String(iso ?? '');
  if (!/^\d{4}-\d{2}-\d{2}/.test(s)) return s;
  return `${parseInt(s.substring(8, 10), 10)} de ${MESES[parseInt(s.substring(5, 7), 10) - 1]} de ${s.substring(0, 4)}`;
}

// ───────────── índice ─────────────

export interface EntradaIndice {
  /** âncora (data-rc-ancora) do título da secção no documento */
  id: string;
  titulo: string;
  nivel: 1 | 2 | 3;
  /** «1.», «1.2.», «1.7.1.» (numeração das secções) */
  numero?: string;
}

/** Numera as entradas por nível (1., 1.1., 1.1.1.); um nível superior reinicia os inferiores. */
export function numerarIndice(entradas: Omit<EntradaIndice, 'numero'>[]): EntradaIndice[] {
  const cont = [0, 0, 0];
  return entradas.map((e) => {
    const i = e.nivel - 1;
    cont[i] += 1;
    for (let k = i + 1; k < cont.length; k++) cont[k] = 0;
    return { ...e, numero: `${cont.slice(0, e.nivel).join('.')}.` };
  });
}

/** Lugar provisório do número de página (largura fixa: a 1.ª preparação pagina igual à final). */
export const PAGINA_PROVISORIA = '000';

export function htmlIndice(entradas: EntradaIndice[], paginas?: Record<string, number>): string {
  const linhas = entradas
    .map((e) => {
      const pag = paginas ? (paginas[e.id] ? String(paginas[e.id]) : '—') : PAGINA_PROVISORIA;
      return `<div class="rc-ind rc-ind-n${e.nivel}"><span class="rc-ind-num">${esc(e.numero ?? '')}</span><span class="rc-ind-tit">${esc(e.titulo)}</span><span class="rc-ind-pontos"></span><span class="rc-ind-pag">${esc(pag)}</span></div>`;
    })
    .join('');
  return `<h1 class="rc-h1" data-rc-ancora="indice">Índice</h1><div class="rc-indice">${linhas}</div>`;
}

/**
 * Página (1, 2…) em que começa cada âncora, a partir do HTML paginado pelo motor (folhas `section.imp-pagina` com
 * `data-pagina`). Fica a primeira ocorrência de cada âncora.
 */
export function paginasDasAncoras(htmlPaginado: string): Record<string, number> {
  const doc = new DOMParser().parseFromString(htmlPaginado, 'text/html');
  const out: Record<string, number> = {};
  doc.querySelectorAll<HTMLElement>('[data-rc-ancora]').forEach((el) => {
    const id = el.getAttribute('data-rc-ancora') ?? '';
    const folha = el.closest('.imp-pagina');
    const n = Number(folha?.getAttribute('data-pagina'));
    if (id && Number.isFinite(n) && n > 0 && out[id] === undefined) out[id] = n;
  });
  return out;
}

// ───────────── blocos ─────────────

export interface EntradaDocumentoRC {
  dados: DadosRC;
  notas: Record<string, boolean>;
  textos: Record<string, TextoGravado>;
  config: ConfigGestao;
  identidade?: IdentidadeImpressao | null;
  /** Balanço/DR/Fluxo a imprimir (fixados na conclusão ou calculados agora); os que faltam (sem permissão) não saem. */
  demonstracoes?: DemonstracoesFixadas;
  concluido?: boolean;
  /** «concluído em AAAA-MM-DD por utilizador» */
  legendaConclusao?: string;
}

const cfgTexto = (c: ConfigGestao, k: string) => (typeof c[k] === 'string' ? (c[k] as string).trim() : c[k] == null ? '' : String(c[k]));

/** Denominação, NIF e sede: os da configuração do relatório e, na falta, os da ficha da empresa. */
export function identificacaoEmpresa(e: Pick<EntradaDocumentoRC, 'dados' | 'config' | 'identidade'>) {
  const c = e.config;
  return {
    nome: cfgTexto(c, 'nome') || e.identidade?.nome || e.dados.empresa.nome || '',
    nif: cfgTexto(c, 'nif') || e.identidade?.nif || e.dados.empresa.nif || '',
    morada: cfgTexto(c, 'sede') || e.identidade?.morada || e.dados.empresa.endereco || '',
    contactos: [e.identidade?.telefone ? `Tel. ${e.identidade.telefone}` : '', e.identidade?.email ?? '', e.identidade?.website ?? ''].filter(Boolean).join(' · '),
  };
}

/** O legado marca como provisório o relatório não concluído ou de exercício em aberto. */
export const ehProvisorio = (e: Pick<EntradaDocumentoRC, 'dados' | 'concluido'>) => !e.dados.encerrado || !e.concluido;

/** Membros do Conselho de Administração (um por linha; «Nome — Cargo»). */
export function membrosConselho(c: ConfigGestao): string[] {
  return cfgTexto(c, 'conselho')
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean)
    .slice(0, 20);
}

export function htmlCapa(e: EntradaDocumentoRC): string {
  const emp = identificacaoEmpresa(e);
  const logo = logotipoSeguro(e.identidade?.logotipo);
  const local = cfgTexto(e.config, 'local');
  const data = dataExtenso(e.config.data);
  const conselho = membrosConselho(e.config);
  const director = cfgTexto(e.config, 'director');
  const orgao = conselho.length
    ? `<div class="rc-capa-orgao"><div class="rc-capa-orgao-tit">Conselho de Administração</div>${conselho.map((m) => `<div>${esc(m)}</div>`).join('')}</div>`
    : director
      ? `<div class="rc-capa-orgao"><div class="rc-capa-orgao-tit">Direcção Geral</div><div>${esc(director)}</div></div>`
      : '';
  const estado = ehProvisorio(e)
    ? '<div class="rc-capa-estado rc-capa-prov">Versão provisória</div>'
    : `<div class="rc-capa-estado">Versão final${e.legendaConclusao ? ` · ${esc(e.legendaConclusao)}` : ''}</div>`;
  return `<section class="rc-capa imp-sem-quebra imp-quebra-pagina" data-rc-ancora="capa">
<div class="rc-capa-faixa"></div>
<div class="rc-capa-topo">${logo ? `<img class="rc-capa-logo" src="${logo}" alt="Logótipo">` : ''}<div class="rc-capa-nome">${esc(emp.nome)}</div>
<div class="rc-capa-dados">${[emp.nif ? `NIF ${esc(emp.nif)}` : '', esc(emp.morada), esc(emp.contactos)].filter(Boolean).join('<br>')}</div></div>
<div class="rc-capa-centro"><div class="rc-capa-sobre">Exercício de ${esc(e.dados.ano)}</div><div class="rc-capa-titulo">Relatório e Contas</div><div class="rc-capa-ano">${esc(e.dados.ano)}</div>
<div class="rc-capa-sub">Relatório de Gestão · Demonstrações Financeiras · Notas às Contas</div>${estado}</div>
<div class="rc-capa-pe">${orgao}<div class="rc-capa-local">${esc([local, 'Angola'].filter(Boolean).join(', '))}${data ? `<br>${esc(data)}` : ''}</div></div>
</section>`;
}

const assinatura = (rotulo: string, nome: string) => `<div class="rc-assin-bloco"><div class="rc-assin-linha"></div><div>${esc(rotulo)}</div><div class="rc-assin-nome">${esc(nome)}</div></div>`;

function htmlAssinaturas(c: ConfigGestao): string {
  return `<div class="rc-assin imp-sem-quebra">${assinatura('O Contabilista Certificado', cfgTexto(c, 'contabilista'))}${assinatura('O Director Geral', cfgTexto(c, 'director'))}</div>`;
}

function classeLinha(l: LinhaPlana): string {
  if (l.tipo === 'seccao') return 'imp-grupo rc-sec';
  if (l.tipo === 'total') return `imp-total${l.estilo === 'final' ? ' rc-final' : ''}`;
  if (l.tipo === 'subtotal') return 'imp-subtotal';
  return '';
}

/** Tabela de uma demonstração (Designação · Notas · N · N-1), com as linhas planas dos mapas contabilísticos. */
export function tabelaDemonstracao(linhas: LinhaPlana[], rotuloN: string, rotuloN1: string, comNotas = true): string {
  const corpo = linhas
    .map((l) => {
      const cls = classeLinha(l);
      if (l.tipo === 'seccao') return `<tr class="${cls}"><td colspan="${comNotas ? 4 : 3}">${esc(l.descricao)}</td></tr>`;
      return `<tr${cls ? ` class="${cls}"` : ''}><td class="imp-quebra">${esc(l.descricao)}</td>${comNotas ? `<td class="imp-centro">${esc(l.nota ?? '')}</td>` : ''}<td class="imp-num">${esc(formatarKz(l.atual))}</td><td class="imp-num">${esc(formatarKz(l.anterior))}</td></tr>`;
    })
    .join('');
  return `<table class="imp-tabela rc-dem"><thead><tr><th>Designação</th>${comNotas ? '<th class="imp-centro">Notas</th>' : ''}<th class="imp-num">${esc(rotuloN)}</th><th class="imp-num">${esc(rotuloN1)}</th></tr></thead><tbody>${corpo}</tbody></table>`;
}

const tabelaIndicadores = (linhas: [string, string, string][], aplicacao: boolean, d: DadosRC) =>
  linhas.length
    ? tabelaHtml<[string, string, string]>({
        linhas,
        colunas: [
          { titulo: aplicacao ? 'Aplicação' : 'Indicador', valor: (r) => r[0], quebrar: true },
          { titulo: String(d.ano), valor: (r) => r[1], alinhamento: 'direita' },
          ...(aplicacao ? [] : [{ titulo: String(d.ano_anterior), valor: (r: [string, string, string]) => r[2], alinhamento: 'direita' as const }]),
        ],
      })
    : '';

const num = (v: unknown) => {
  const n = Number(v ?? 0);
  return Number.isFinite(n) ? n : 0;
};

/** Cartões de destaque da síntese (activo, capital próprio, vendas, EBITDA, resultado líquido). */
function htmlDestaques(d: DadosRC): string {
  const kpis: [string, string][] = [
    ['Total do activo', 'activo'],
    ['Capital próprio', 'capital_proprio'],
    ['Vendas e prestações', 'vendas_prestacoes'],
    ['EBITDA', 'ebitda'],
    ['Resultado líquido', 'rl'],
  ];
  return `<div class="rc-kpis imp-sem-quebra">${kpis
    .map(([rot, k]) => {
      const n = num(d.n.indicadores[k]);
      const p = num(d.n1.indicadores[k]);
      const v = Math.abs(p) < 0.005 ? null : (n - p) / Math.abs(p);
      const variacao = v === null ? '' : `<div class="rc-kpi-var ${v >= 0 ? 'rc-sobe' : 'rc-desce'}">${v >= 0 ? '▲' : '▼'} ${esc(`${(Math.abs(v) * 100).toLocaleString('pt-PT', { maximumFractionDigits: 1 })} %`)} vs ${esc(d.ano_anterior)}</div>`;
      return `<div class="rc-kpi"><div class="rc-kpi-rot">${esc(rot)}</div><div class="rc-kpi-val${n < 0 ? ' rc-neg' : ''}">${esc(formatarKz(d.n.indicadores[k] as string))}</div>${variacao}</div>`;
    })
    .join('')}</div>`;
}

function htmlNota(e: EntradaDocumentoRC, nota: string, contas: DadosRC['composicao'][string]): string {
  const d = e.dados;
  const anoN = String(d.ano);
  const anoN1 = String(d.ano_anterior);
  let html = '';
  const texto = e.textos[`nota_${nota}`];
  if (texto) {
    const t = texto.texto ?? htmlParaTexto(texto.html ?? '');
    if (t.trim()) html += `<div class="rc-texto">${textoParaHtml(t)}</div>`;
  }
  if (contas.length)
    html += tabelaHtml({
      linhas: contas,
      totais: true,
      colunas: [
        { titulo: 'Conta', valor: (c) => c.conta },
        { titulo: 'Descrição', valor: (c) => c.descricao ?? '—', quebrar: true },
        { titulo: anoN, valor: (c) => c.n, formato: 'moeda', somar: true },
        { titulo: anoN1, valor: (c) => c.n1, formato: 'moeda', somar: true },
      ],
    });
  const mov = d.movimentos?.[nota] ?? [];
  if (mov.length)
    html +=
      '<p class="rc-legenda imp-manter-com-seguinte">Movimentos ocorridos durante o exercício:</p>' +
      tabelaHtml<LinhaMovimentoNota>({
        linhas: mov,
        totais: true,
        colunas: [
          { titulo: 'Conta', valor: (r) => `${r.conta} ${r.descricao ?? ''}`.trim(), quebrar: true },
          { titulo: 'Saldo inicial', valor: (r) => r.inicial, formato: 'moeda', somar: true },
          { titulo: 'Aumentos', valor: (r) => r.aumentos, formato: 'moeda', somar: true },
          { titulo: 'Diminuições', valor: (r) => r.diminuicoes, formato: 'moeda', somar: true },
          { titulo: 'Saldo final', valor: (r) => r.final, formato: 'moeda', somar: true },
        ],
      });
  if (nota === '27' && d.n.cmvmc) {
    const N = d.n.cmvmc, P = d.n1.cmvmc ?? {};
    const linhas: [string, string, string, boolean][] = [
      ['Existências iniciais', N.ei, P.ei, false],
      ['Compras', N.compras, P.compras, false],
      ['Existências finais', N.ef, P.ef, false],
      ['Custo no ano (EI + Compras − EF)', N.custo, P.custo, true],
    ];
    html +=
      '<p class="rc-legenda imp-manter-com-seguinte">Custo das existências vendidas e consumidas (contas da classe 2):</p>' +
      `<table class="imp-tabela"><thead><tr><th></th><th class="imp-num">${esc(anoN)}</th><th class="imp-num">${esc(anoN1)}</th></tr></thead><tbody>${linhas
        .map(([r, a, b, t]) => `<tr${t ? ' class="imp-total"' : ''}><td>${esc(r)}</td><td class="imp-num">${esc(formatarKz(a))}</td><td class="imp-num">${esc(formatarKz(b))}</td></tr>`)
        .join('')}</tbody></table>`;
  }
  if (nota === '35') {
    const I = d.n.indicadores;
    const taxa = num(e.config.taxa_imposto) / 100;
    const prejuizos = num(e.config.prejuizos_fiscais);
    const tributavel = num(I.rai) + num(d.n.multas) - prejuizos;
    const estimado = Math.max(0, tributavel) * taxa;
    const efectiva = Math.abs(num(I.rai)) < 0.005 ? null : num(I.imposto) / num(I.rai);
    const pct = (v: number | null) => (v === null ? 'n.a.' : `${(v * 100).toLocaleString('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`);
    const linhas: [string, string, string?][] = [
      ['Resultado contabilístico antes de impostos', formatarKz(I.rai as string)],
      ['A somar: multas e penalidades não aceites fiscalmente (78.06)', formatarKz(d.n.multas ?? 0)],
      ['A deduzir: prejuízos fiscais de anos anteriores', formatarKz(prejuizos)],
      ['Lucro tributável (prejuízo fiscal)', formatarKz(tributavel), 'imp-subtotal'],
      ['Taxa nominal de imposto', pct(taxa)],
      ['Imposto estimado', formatarKz(estimado), 'imp-subtotal'],
      ['Imposto registado (Nota 35)', formatarKz(I.imposto as string)],
      ['Taxa efectiva de imposto', pct(efectiva)],
    ];
    html += `<table class="imp-tabela"><thead><tr><th>Apuramento do imposto</th><th class="imp-num">${esc(anoN)}</th></tr></thead><tbody>${linhas
      .map(([r, v, cls]) => `<tr${cls ? ` class="${cls}"` : ''}><td class="imp-quebra">${esc(r)}</td><td class="imp-num">${esc(v)}</td></tr>`)
      .join('')}</tbody></table>`;
  }
  return html;
}

type LinhaAnexo = DadosRC['anexos'][string][number];

const COLUNAS_BALANCETE: ColunaImpressao<LinhaAnexo>[] = [
  { titulo: 'Conta', valor: (l) => l.conta },
  { titulo: 'Descrição', valor: (l) => l.descricao ?? '', quebrar: true },
  { titulo: 'Mov. Débito', valor: (l) => l.debito, formato: 'moeda', somar: true },
  { titulo: 'Mov. Crédito', valor: (l) => l.credito, formato: 'moeda', somar: true },
  { titulo: 'Saldo Devedor', valor: (l) => l.saldo_devedor, formato: 'moeda', somar: true },
  { titulo: 'Saldo Credor', valor: (l) => l.saldo_credor, formato: 'moeda', somar: true },
];

const COLUNAS_AMORTIZACOES: ColunaImpressao<LinhaAnexo>[] = [
  { titulo: 'Código', valor: (l) => l.codigo as string },
  { titulo: 'Descrição', valor: (l) => l.descricao ?? '', quebrar: true },
  { titulo: 'Aquisição', valor: (l) => l.aquisicao as string, formato: 'data' },
  { titulo: 'Valor', valor: (l) => l.valor as string, formato: 'moeda', somar: true },
  { titulo: 'Vida (anos)', valor: (l) => (num(l.vida_anos) ? String(l.vida_anos).replace('.', ',') : '—'), alinhamento: 'direita' },
  { titulo: 'Taxa', valor: (l) => (l.taxa == null ? '—' : `${(num(l.taxa) * 100).toLocaleString('pt-PT', { maximumFractionDigits: 2 })} %`), alinhamento: 'direita' },
  { titulo: 'Amort. exercício', valor: (l) => l.exercicio as string, formato: 'moeda', somar: true },
  { titulo: 'Amort. acumulada', valor: (l) => l.acumulada as string, formato: 'moeda', somar: true },
  { titulo: 'Valor líquido', valor: (l) => l.valor_liquido as string, formato: 'moeda', somar: true },
];

/** Anexos escolhidos na configuração (legado: anexo_razao, anexo_geral, anexo_amortizacoes; por omissão razão e amortizações). */
export function anexosEscolhidos(d: DadosRC, c: ConfigGestao): { chave: string; titulo: string; linhas: LinhaAnexo[] }[] {
  const liga = (k: string, omissao: boolean) => (c[k] === undefined || c[k] === null ? omissao : !!c[k]);
  const out: { chave: string; titulo: string; linhas: LinhaAnexo[] }[] = [];
  const add = (chave: string, titulo: string) => {
    const linhas = d.anexos[chave] ?? [];
    if (linhas.length) out.push({ chave, titulo, linhas });
  };
  if (liga('anexo_razao', true)) {
    add('razao_dezembro', `Balancete Razão (Abertura a Dezembro) — ${d.ano}`);
    add('razao_apuramento', `Balancete Razão (Abertura a Apuramento) — ${d.ano}`);
  }
  if (liga('anexo_geral', false)) {
    add('geral_dezembro', `Balancete Geral (Abertura a Dezembro) — ${d.ano}`);
    add('geral_apuramento', `Balancete Geral (Abertura a Apuramento) — ${d.ano}`);
  }
  if (liga('anexo_amortizacoes', true)) add('amortizacoes', `Mapa de Amortizações e Reintegrações — ${d.ano}`);
  return out;
}

// ───────────── documento ─────────────

export interface DocumentoRC {
  html: string;
  indice: EntradaIndice[];
}

/**
 * Monta o documento completo. `paginas` (âncora → página) vem da 1.ª preparação; sem ela, o índice leva números
 * provisórios de largura fixa.
 */
export function montarDocumentoRC(e: EntradaDocumentoRC, paginas?: Record<string, number>): DocumentoRC {
  const d = e.dados;
  const c = e.config;
  const anoN = String(d.ano);
  const anoN1 = String(d.ano_anterior);
  const indice: Omit<EntradaIndice, 'numero'>[] = [];
  const titulo = (id: string, texto: string, nivel: 1 | 2 | 3) => {
    indice.push({ id, titulo: texto, nivel });
    const tag = nivel === 1 ? 'h1' : nivel === 2 ? 'h2' : 'h3';
    return `<${tag} class="rc-h${nivel}" data-rc-ancora="${esc(id)}">${esc(texto)}</${tag}>`;
  };
  const partes: string[] = [];
  const valoresEm = `<p class="rc-valores">Valores expressos em ${esc(d.moeda)} (Kwanzas)</p>`;

  // 1. Relatório de Gestão (textos, tabelas de indicadores e gráficos por secção)
  {
    let html = titulo('gestao', 'Relatório de Gestão', 1);
    let grupo: string | undefined;
    SECCOES.forEach((s) => {
      if (s.grupo && s.grupo !== grupo) html += titulo(`grupo-${s.id}`, s.grupo, 2);
      if (s.grupo) grupo = s.grupo;
      else grupo = undefined;
      const nivel = s.grupo ? 3 : 2;
      const texto = textoParaHtml(textoActual(s.id, e.textos[s.id], d, c).texto);
      html += `<section class="rc-sub">${titulo(`s-${s.id}`, s.titulo, nivel)}<div class="rc-texto">${texto}</div>${tabelaIndicadores(tabelaSeccao(s.tabela, d, c), s.tabela === 'aplicacao', d)}${graficoSeccao(s.id, d, c)}</section>`;
      if (s.id === 'finais') {
        const local = cfgTexto(c, 'local');
        const data = dataExtenso(c.data);
        html += `<div class="rc-fecho imp-sem-quebra"><p class="rc-local">${esc([local, data].filter(Boolean).join(', '))}</p><div class="rc-assin rc-assin-um">${assinatura('O Director Geral', cfgTexto(c, 'director'))}</div></div>`;
      }
    });
    partes.push(`<section class="rc-parte">${html}</section>`);
  }

  // 2. Síntese de indicadores e rácios (sistema novo)
  {
    let html = titulo('indicadores', 'Principais Indicadores e Rácios', 1);
    html += htmlDestaques(d);
    html += titulo('grandezas', 'Grandezas económicas e financeiras', 2);
    html += tabelaHtml({
      linhas: MONETARIOS,
      colunas: [
        { titulo: 'Indicador', valor: (r) => r[1], quebrar: true },
        { titulo: anoN, valor: (r) => d.n.indicadores[r[0]] as string, formato: 'moeda' },
        { titulo: anoN1, valor: (r) => d.n1.indicadores[r[0]] as string, formato: 'moeda' },
      ],
    });
    html += titulo('racios', 'Rácios', 2);
    html += tabelaHtml({
      linhas: RACIOS,
      colunas: [
        { titulo: 'Rácio', valor: (r) => r[1], quebrar: true },
        { titulo: anoN, valor: (r) => racio(d.n.indicadores[r[0]], r[2]), alinhamento: 'direita' },
        { titulo: anoN1, valor: (r) => racio(d.n1.indicadores[r[0]], r[2]), alinhamento: 'direita' },
      ],
    });
    html += `<p class="rc-legenda">Colaboradores no exercício: ${esc(d.colaboradores)}</p>`;
    if (d.alertas.length) {
      html += titulo('alertas', 'Observações do sistema', 2);
      html += tabelaHtml({
        linhas: d.alertas,
        colunas: [
          { titulo: 'Tipo', valor: (a) => (a.tipo === 'erro' ? 'Erro' : a.tipo === 'aviso' ? 'Aviso' : 'Informação') },
          { titulo: 'Observação', valor: (a) => a.texto, quebrar: true },
        ],
      });
    }
    partes.push(`<section class="rc-parte">${html}</section>`);
  }

  // 3. Demonstrações financeiras (legado: uma por página, com «Valores expressos em» e assinaturas)
  {
    const dem = e.demonstracoes ?? {};
    const blocos: string[] = [];
    let primeira = true;
    const bloco = (id: string, tit: string, data: string, tabela: string) => {
      blocos.push(`<section class="rc-parte${primeira ? '' : ' rc-nova'}">${primeira ? titulo('demonstracoes', 'Demonstrações Financeiras', 1) : ''}${titulo(id, tit, 2)}<p class="rc-data">${esc(data)}</p>${valoresEm}${tabela}${htmlAssinaturas(c)}</section>`);
      primeira = false;
    };
    if (dem.balanco) bloco('balanco', 'Balanço', `Em 31 de Dezembro de ${anoN}`, tabelaDemonstracao(linhasBalanco(dem.balanco), `31/12/${anoN}`, `31/12/${anoN1}`));
    if (dem.dr) bloco('dr', 'Demonstração dos Resultados', `Exercício findo em 31 de Dezembro de ${anoN}`, tabelaDemonstracao(linhasDR(dem.dr), anoN, anoN1));
    if (dem.fluxo) bloco('fluxo', 'Demonstração dos Fluxos de Caixa', `Exercício findo em 31 de Dezembro de ${anoN}`, tabelaDemonstracao(linhasFluxo(dem.fluxo), anoN, anoN1, false));
    partes.push(...blocos);
  }

  // 4. Notas às Contas (as marcadas «Incluir»)
  {
    const notas = Object.entries(d.composicao)
      .filter(([nota]) => e.notas[nota] !== false)
      .sort(([a], [b]) => Number(a) - Number(b));
    if (notas.length) {
      let html = titulo('notas', `Notas às Contas do Exercício Findo em 31 de Dezembro de ${anoN}`, 1);
      let grupo = '';
      notas.forEach(([nota, contas]) => {
        const n = Number(nota);
        const g = n >= 4 && n <= 21 ? 'balanco' : n >= 22 ? 'dr' : 'geral';
        if (g !== grupo) {
          grupo = g;
          if (g === 'balanco') html += '<h2 class="rc-grupo-notas">Notas ao Balanço</h2>';
          if (g === 'dr') html += '<h2 class="rc-grupo-notas">Notas à Demonstração dos Resultados</h2>';
        }
        const tit = TITULOS_NOTAS[nota] ? `Nota ${nota} — ${TITULOS_NOTAS[nota]}` : `Nota ${nota}`;
        html += `<section class="rc-nota">${titulo(`nota-${nota}`, tit, 2)}${htmlNota(e, nota, contas)}</section>`;
      });
      partes.push(`<section class="rc-parte">${html}</section>`);
    }
  }

  // 5. Anexos
  {
    const anexos = anexosEscolhidos(d, c);
    if (anexos.length) {
      let html = titulo('anexos', 'Anexos', 1);
      anexos.forEach((a, i) => {
        const colunas = a.chave === 'amortizacoes' ? COLUNAS_AMORTIZACOES : COLUNAS_BALANCETE;
        html += `<section class="rc-anexo${i ? ' rc-nova' : ''}">${titulo(`anexo-${a.chave}`, a.titulo, 2)}${tabelaHtml<LinhaAnexo>({ linhas: a.linhas, colunas, totais: 'Totais' })}</section>`;
      });
      partes.push(`<section class="rc-parte">${html}</section>`);
    }
  }

  // índice: as entradas acima (o próprio índice não se lista)
  const numerado = numerarIndice(indice);
  const emp = identificacaoEmpresa(e);
  const corrido = `<div class="imp-repetir rc-corrido"><span>${esc(emp.nome)}</span><span>Relatório e Contas ${esc(anoN)}${ehProvisorio(e) ? ' · <b>Provisório</b>' : ''}</span></div>`;
  const html = `<div class="rc-doc">${htmlCapa(e)}<div class="rc-corpo">${corrido}<section class="rc-parte rc-parte-indice">${htmlIndice(numerado, paginas)}</section>${partes.join('')}</div></div>`;
  return { html, indice: numerado };
}

/** Rodapé de cada folha (à esquerda; à direita o motor põe «Página X de Y»). */
export function rodapeRC(e: Pick<EntradaDocumentoRC, 'dados' | 'config' | 'identidade' | 'concluido'>): string {
  const emp = identificacaoEmpresa(e);
  return [emp.nome, emp.nif ? `NIF ${emp.nif}` : '', `Relatório e Contas · Exercício de ${e.dados.ano}`, ehProvisorio(e) ? 'Versão provisória' : ''].filter(Boolean).join(' · ');
}

/** Opções do pedido de impressão (A4 vertical, sem o cabeçalho genérico: a capa e o cabeçalho corrido fazem esse papel). */
export function opcoesDocumentoRC(e: EntradaDocumentoRC) {
  const emp = identificacaoEmpresa(e);
  return {
    titulo: `Relatório e Contas ${e.dados.ano}`,
    orientacao: 'retrato' as const,
    papel: 'A4' as const,
    cabecalho: false,
    blocoTitulo: false,
    rodape: rodapeRC(e),
    cssExtra: CSS_RC,
    nomeFicheiro: `Relatório e Contas ${e.dados.ano} - ${emp.nome}${ehProvisorio(e) ? ' - provisório' : ''}`,
  };
}

/** Estilos do documento (paleta do legado: azul-marinho #1e3a8a, cinzas ardósia). */
export const CSS_RC = `
.rc-doc { font-family: 'Segoe UI', Arial, 'Helvetica Neue', sans-serif; color: #1e293b; font-size: 9.6pt; line-height: 1.5; }
.rc-doc * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
/* capa: ocupa a folha inteira; sem rodapé na 1.ª folha */
.rc-capa { position: relative; height: 262mm; display: flex; flex-direction: column; justify-content: space-between; padding: 14mm 12mm 10mm; border: 0.4mm solid #cbd5e1; overflow: hidden; }
body.imp-paginado .rc-capa { height: calc((var(--imp-altura-pagina) - 7mm - 4mm) / var(--imp-escala, 1)); }
.imp-paginas > .imp-pagina:first-child .imp-pagina-rodape { visibility: hidden; }
.rc-capa-faixa { position: absolute; left: 0; top: 0; bottom: 0; width: 7mm; background: #1e3a8a; }
.rc-capa-faixa::after { content: ''; position: absolute; left: 7mm; top: 0; bottom: 0; width: 1.6mm; background: #0ea5e9; }
.rc-capa-topo { padding-left: 6mm; }
.rc-capa-logo { display: block; max-height: 26mm; max-width: 70mm; object-fit: contain; margin-bottom: 6mm; }
.rc-capa-nome { font-size: 20pt; font-weight: 800; color: #0f172a; line-height: 1.2; }
.rc-capa-dados { font-size: 9pt; color: #475569; margin-top: 2mm; line-height: 1.5; }
.rc-capa-centro { padding-left: 6mm; }
.rc-capa-sobre { font-size: 11pt; letter-spacing: .2em; text-transform: uppercase; color: #0ea5e9; font-weight: 700; }
.rc-capa-titulo { font-size: 34pt; font-weight: 800; color: #1e3a8a; line-height: 1.1; margin-top: 2mm; }
.rc-capa-ano { font-size: 54pt; font-weight: 200; color: #1e3a8a; line-height: 1; margin-top: 1mm; }
.rc-capa-sub { font-size: 10pt; color: #475569; margin-top: 6mm; padding-top: 3mm; border-top: 0.6mm solid #1e3a8a; display: inline-block; }
.rc-capa-estado { margin-top: 6mm; font-size: 9pt; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #334155; }
.rc-capa-prov { color: #b91c1c; border: 0.5mm solid #b91c1c; display: inline-block; padding: 1.2mm 4mm; border-radius: 1mm; }
.rc-capa-pe { padding-left: 6mm; display: flex; justify-content: space-between; align-items: flex-end; gap: 10mm; font-size: 9.5pt; color: #334155; }
.rc-capa-orgao-tit { font-weight: 700; color: #1e3a8a; text-transform: uppercase; letter-spacing: .08em; font-size: 8.5pt; margin-bottom: 1mm; }
.rc-capa-local { text-align: right; text-transform: uppercase; letter-spacing: .06em; }
/* cabeçalho corrido (repetido no topo de cada folha a seguir à capa) */
.rc-corrido { display: flex; align-items: baseline; justify-content: space-between; gap: 6mm; font-size: 7.5pt; color: #475569; border-bottom: 0.3mm solid #1e3a8a; padding-bottom: 1.4mm; margin-bottom: 6.5mm; white-space: nowrap; }
.rc-corrido span:first-child { flex: 1 1 auto; min-width: 0; font-weight: 700; color: #1e3a8a; overflow: hidden; text-overflow: ellipsis; }
.rc-corrido span:last-child { flex: 0 0 auto; }
.rc-corrido b { color: #b91c1c; }
/* partes: cada uma em folha nova */
.rc-parte, .rc-nova { break-before: page; page-break-before: always; }
.rc-parte-indice { break-before: auto; page-break-before: auto; }
.rc-h1 { font-size: 15pt; color: #1e3a8a; border-bottom: 0.6mm solid #1e3a8a; padding-bottom: 1.2mm; margin: 0 0 4mm; text-transform: uppercase; letter-spacing: .02em; }
.rc-h2 { font-size: 12pt; color: #1e40af; margin: 5mm 0 2mm; }
.rc-h3 { font-size: 10.5pt; color: #0f172a; margin: 4mm 0 1.5mm; }
.rc-grupo-notas { font-size: 11pt; color: #334155; border-bottom: 0.3mm solid #cbd5e1; margin: 5mm 0 2mm; padding-bottom: 0.8mm; }
.rc-texto p { text-align: justify; margin: 0 0 2mm; }
.rc-texto ul { margin: 1mm 0 2.5mm 5mm; padding: 0; } .rc-texto li { margin-bottom: 1mm; text-align: justify; }
.rc-legenda { font-size: 8pt; color: #64748b; margin: 2mm 0 1mm; }
.rc-valores, .rc-data { font-size: 9pt; color: #475569; margin: 0 0 1mm; }
/* índice */
.rc-indice { margin-top: 2mm; }
.rc-ind { display: flex; flex-wrap: nowrap; align-items: baseline; gap: 2mm; padding: 1.1mm 0; }
.rc-ind-n1 { font-weight: 700; color: #1e3a8a; font-size: 10.5pt; margin-top: 2mm; }
.rc-ind-n2 { padding-left: 7mm; font-size: 9.5pt; }
.rc-ind-n3 { padding-left: 14mm; font-size: 9pt; color: #475569; }
.rc-ind-num { flex: none; min-width: 9mm; }
.rc-ind-tit { flex: 0 1 auto; }
.rc-ind-pontos { flex: 1 1 auto; border-bottom: 0.3mm dotted #94a3b8; min-width: 6mm; transform: translateY(-1mm); }
.rc-ind-pag { flex: none; min-width: 8mm; text-align: right; font-variant-numeric: tabular-nums; }
/* tabelas */
.rc-doc .imp-tabela { font-size: 8.6pt; margin: 2mm 0 4mm; }
.rc-doc .imp-tabela caption { color: #1e3a8a; }
.rc-doc .imp-tabela th { background: #1e3a8a; color: #fff; border-color: #1e3a8a; }
.rc-doc .imp-tabela td { border-color: #e2e8f0; border-left: 0; border-right: 0; }
.rc-doc .imp-tabela tbody tr:nth-child(even) td { background: #f8fafc; }
.rc-doc .imp-tabela tr.rc-sec td, .rc-doc .imp-tabela tr.imp-grupo td { background: #eef2ff; color: #1e3a8a; font-weight: 800; }
.rc-doc .imp-tabela tr.imp-subtotal td { background: #f1f5f9; border-top: 0.3mm solid #94a3b8; }
.rc-doc .imp-tabela tr.imp-total td, .rc-doc .imp-tabela tfoot td { background: #e2e8f0; border-top: 0.5mm solid #0f172a; }
.rc-doc .imp-tabela tr.rc-final td { background: #1e3a8a; color: #fff; }
.rc-anexo .imp-tabela { font-size: 7.6pt; }
/* gráficos */
.rc-graf { margin: 3mm auto 4mm !important; text-align: center; break-inside: avoid; }
.rc-graf svg { display: block; margin: 0 auto; }
.rc-graf-duo { display: flex !important; flex-wrap: nowrap !important; gap: 4mm !important; margin: 2mm 0 4mm !important; break-inside: avoid; }
.rc-graf-duo > .rc-graf { flex: 1 1 0 !important; min-width: 0; margin: 0 !important; }
/* cartões da síntese */
.rc-kpis { display: flex; flex-wrap: nowrap; gap: 2.5mm; margin: 2mm 0 5mm; }
.rc-kpi { flex: 1 1 0; min-width: 0; border: 0.3mm solid #cbd5e1; border-top: 1mm solid #1e3a8a; border-radius: 1mm; padding: 2mm 2.4mm; background: #f8fafc; }
.rc-kpi-rot { font-size: 7pt; text-transform: uppercase; letter-spacing: .05em; color: #64748b; font-weight: 700; }
.rc-kpi-val { font-size: 10.5pt; font-weight: 800; color: #0f172a; margin-top: 0.8mm; white-space: nowrap; }
.rc-kpi-val.rc-neg { color: #b91c1c; }
.rc-kpi-var { font-size: 7pt; margin-top: 0.6mm; }
.rc-sobe { color: #047857; } .rc-desce { color: #b91c1c; }
/* assinaturas e fecho */
.rc-assin { display: flex; flex-wrap: nowrap; justify-content: space-around; gap: 10mm; margin-top: 14mm; text-align: center; font-size: 9pt; }
.rc-assin-um { justify-content: flex-end; }
.rc-assin-bloco { min-width: 60mm; }
.rc-assin-linha { border-top: 0.3mm solid #0f172a; margin: 0 auto 1.2mm; width: 60mm; }
.rc-assin-nome { font-weight: 700; min-height: 4mm; }
.rc-local { margin-top: 8mm; }
.rc-nota { margin-bottom: 3mm; }
`;
