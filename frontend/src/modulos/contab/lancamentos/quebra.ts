/**
 * «Quebra por Terceiro» / «Quebra por Unidade de Negócio» / «Quebra por Centro de Custo» da lista de lançamentos —
 * regras puras (testáveis): tipos da API, filtro de cada grupo e documento de impressão/Excel com subtotais e total.
 */
import { esc } from '@/componentes/impressao';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';

export type PorQuebra = 'terceiro' | 'unidade' | 'centro';

/** Vista da lista: as linhas (como o legado) ou uma das quebras. */
export type VistaLancamentos = 'linhas' | PorQuebra;

export const VISTAS_LANCAMENTOS: { valor: VistaLancamentos; rotulo: string; icone: string }[] = [
  { valor: 'linhas', rotulo: 'Linhas', icone: 'list' },
  { valor: 'terceiro', rotulo: 'Quebra por Terceiro', icone: 'user-tag' },
  { valor: 'unidade', rotulo: 'Quebra por Unidade de Negócio', icone: 'building' },
  { valor: 'centro', rotulo: 'Quebra por Centro de Custo', icone: 'sitemap' },
];

/** Rótulo da dimensão (cabeçalho da coluna do grupo). */
export const ROTULO_DIMENSAO: Record<PorQuebra, string> = { terceiro: 'Terceiro', unidade: 'Unidade de negócio', centro: 'Centro de custo' };

export interface LinhaDetalheQuebra {
  id: number;
  data_documento: string | null;
  diario: string | null;
  numero_lan: string | null;
  numero_documento: string | null;
  codigo_conta: string;
  descricao: string | null;
  tipo_dc: 'D' | 'C';
  valor: string;
}

export interface GrupoQuebra {
  chave: number | null;
  codigo: string | null;
  nome: string;
  linhas: number;
  debito: string;
  credito: string;
  saldo: string;
  detalhe?: LinhaDetalheQuebra[];
}

export interface TotalQuebra {
  grupos: number;
  linhas: number;
  debito: string;
  credito: string;
  saldo: string;
}

export interface Quebra {
  por: PorQuebra;
  grupos: GrupoQuebra[];
  total: TotalQuebra;
  paginacao: { pagina_atual: number; por_pagina: number; total: number; ultima_pagina: number };
}

/** Coluna de cada dimensão e o valor do filtro «sem …» da lista (GET /contabilidade/lancamentos). */
const DIMENSAO: Record<PorQuebra, { campo: string; sem: string }> = {
  terceiro: { campo: 'terceiro_id', sem: 'terceiro' },
  unidade: { campo: 'unidade_negocio_id', sem: 'un' },
  centro: { campo: 'centro_custo_id', sem: 'cc' },
};

/** Filtros da lista para as linhas de um grupo: os filtros aplicados + a dimensão do grupo (ou «sem …» no grupo vazio). */
export function filtrosDoGrupo(filtros: Record<string, unknown>, por: PorQuebra, chave: number | null): Record<string, unknown> {
  const d = DIMENSAO[por];
  if (chave !== null) return { ...filtros, [d.campo]: chave };
  const sem = Array.isArray(filtros.sem) ? (filtros.sem as string[]) : [];
  return { ...filtros, [d.campo]: undefined, sem: sem.includes(d.sem) ? sem : [...sem, d.sem] };
}

/** Rótulo do grupo («código — nome»; o grupo «sem …» só com o nome). */
export function rotuloGrupo(g: Pick<GrupoQuebra, 'codigo' | 'nome' | 'chave'>): string {
  return g.chave !== null && g.codigo ? `${g.codigo} — ${g.nome}` : g.nome;
}

const num = (v: string) => `<td class="num" data-xv="${esc(v)}" data-xt="n">${esc(formatarKz(v))}</td>`;

/**
 * Documento (HTML) da quebra para Imprimir/PDF/Excel: com detalhe, cada grupo é um título (imp-grupo), as suas linhas e o
 * subtotal (imp-subtotal); sem detalhe, uma linha por grupo. Fecha com o TOTAL GERAL (imp-total). Todo o texto é escapado.
 */
export function documentoQuebra(por: PorQuebra, grupos: GrupoQuebra[], total: TotalQuebra, comDetalhe: boolean): string {
  const rotulo = ROTULO_DIMENSAO[por];
  if (!comDetalhe) {
    const linhas = grupos
      .map((g) => `<tr><td>${esc(rotuloGrupo(g))}</td><td class="num">${g.linhas}</td>${num(g.debito)}${num(g.credito)}${num(g.saldo)}</tr>`)
      .join('');
    return `<table><thead><tr><th>${esc(rotulo)}</th><th class="num">Linhas</th><th class="num">Débito</th><th class="num">Crédito</th><th class="num">Saldo (D − C)</th></tr></thead>
<tbody>${linhas}</tbody>
<tfoot><tr class="imp-total"><td>TOTAL GERAL (${total.grupos} grupo(s))</td><td class="num">${total.linhas}</td>${num(total.debito)}${num(total.credito)}${num(total.saldo)}</tr></tfoot></table>`;
  }
  const corpo = grupos
    .map((g) => {
      const det = (g.detalhe ?? [])
        .map(
          (l) =>
            `<tr><td>${esc(formatarData(l.data_documento))}</td><td>${esc(l.diario ?? '')}</td><td>${esc(l.numero_lan ?? '')}</td><td>${esc(l.numero_documento ?? '')}</td><td>${esc(l.codigo_conta)}</td><td>${esc(l.descricao ?? '')}</td>${
              l.tipo_dc === 'D' ? num(l.valor) : '<td></td>'
            }${l.tipo_dc === 'C' ? num(l.valor) : '<td></td>'}</tr>`,
        )
        .join('');
      return `<tr class="imp-grupo"><td colspan="8">${esc(rotulo)}: ${esc(rotuloGrupo(g))}</td></tr>${det}<tr class="imp-subtotal"><td colspan="6">Subtotal ${esc(rotuloGrupo(g))} (${g.linhas} linha(s)) · saldo ${esc(formatarKz(g.saldo))}</td>${num(g.debito)}${num(g.credito)}</tr>`;
    })
    .join('');
  return `<table><thead><tr><th>Data fiscal</th><th>Diário</th><th>Nº Lanç.</th><th>Nº Doc.</th><th>Conta</th><th>Descrição</th><th class="num">Débito</th><th class="num">Crédito</th></tr></thead>
<tbody>${corpo}</tbody>
<tfoot><tr class="imp-total"><td colspan="6">TOTAL GERAL (${total.grupos} grupo(s), ${total.linhas} linha(s)) · saldo ${esc(formatarKz(total.saldo))}</td>${num(total.debito)}${num(total.credito)}</tr></tfoot></table>`;
}
