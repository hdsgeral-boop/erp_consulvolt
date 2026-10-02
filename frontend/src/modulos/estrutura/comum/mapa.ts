import { pares, tabelaHtml, type ColunaImpressao } from '@/componentes/impressao';
import { formatarKz } from '@/utilitarios/formatacao';
import type { LinhaMapa } from './arvore';
/** Mapa de pessoal (GET /rh/estrutura/mapa, ADR-064): números por unidade (só os postos da própria) e por cargo. */
export interface ContagemMapa {
  previstos: number;
  ocupados: number;
  em_aberto: number;
  acima: number;
  postos: number;
  colaboradores: number;
  /** ilíquido do último processamento fechado; só com est_ver_salarios */
  massa_salarial?: string;
}

export interface UnidadeMapa extends ContagemMapa {
  unidade_organica_id: number;
  codigo: string | null;
  nome: string;
  unidade_organica_pai_id: number | null;
  ativo: boolean;
}

export interface CargoMapa extends ContagemMapa {
  cargo_funcao_id: number | null;
  nome: string;
}

export interface MapaPessoalApi {
  ver_salarios: boolean;
  periodo_salarial: string | null;
  por_unidade: UnidadeMapa[];
  sem_unidade: ContagemMapa;
  por_cargo: CargoMapa[];
  totais: ContagemMapa;
}

/** Linha da vista por unidade: a própria unidade e o acumulado do ramo (unidade + subunidades). */
export interface LinhaUnidadeMapa {
  chave: string;
  nivel: number;
  unidade: UnidadeMapa;
  propria: ContagemMapa;
  ramo: ContagemMapa;
}

const somarCentimos = (a: string | undefined, b: string | undefined): string | undefined => {
  if (a === undefined && b === undefined) return undefined;
  const c = (v: string | undefined) => Math.round(Number(v ?? 0) * 100);
  return ((c(a) + c(b)) / 100).toFixed(2);
};

export function somarContagens(a: ContagemMapa, b: ContagemMapa): ContagemMapa {
  const r: ContagemMapa = {
    previstos: a.previstos + b.previstos, ocupados: a.ocupados + b.ocupados, em_aberto: a.em_aberto + b.em_aberto, acima: a.acima + b.acima,
    postos: a.postos + b.postos, colaboradores: a.colaboradores + b.colaboradores,
  };
  const m = somarCentimos(a.massa_salarial, b.massa_salarial);
  return m === undefined ? r : { ...r, massa_salarial: m };
}

/**
 * Ordena as unidades em árvore (pré-ordem, pai antes dos filhos, pela ordem recebida) e acumula cada ramo. Unidades
 * cujo pai não existe (ou em ciclo) ficam na raiz; com `apenasActivas`, as inactivas e os seus ramos saem.
 */
export function linhasPorUnidade(unidades: UnidadeMapa[], apenasActivas = false): LinhaUnidadeMapa[] {
  const lista = apenasActivas ? unidades.filter((u) => u.ativo) : unidades;
  const ids = new Set(lista.map((u) => u.unidade_organica_id));
  const filhos = new Map<number | null, UnidadeMapa[]>();
  for (const u of lista) {
    const pai = u.unidade_organica_pai_id !== null && ids.has(u.unidade_organica_pai_id) && u.unidade_organica_pai_id !== u.unidade_organica_id ? u.unidade_organica_pai_id : null;
    filhos.set(pai, [...(filhos.get(pai) ?? []), u]);
  }
  const saida: LinhaUnidadeMapa[] = [];
  const visitados = new Set<number>();
  const visitar = (u: UnidadeMapa, nivel: number): ContagemMapa => {
    visitados.add(u.unidade_organica_id);
    const linha: LinhaUnidadeMapa = { chave: `u${u.unidade_organica_id}`, nivel, unidade: u, propria: u, ramo: u };
    saida.push(linha);
    let ramo: ContagemMapa = u;
    for (const f of filhos.get(u.unidade_organica_id) ?? []) {
      if (!visitados.has(f.unidade_organica_id)) ramo = somarContagens(ramo, visitar(f, nivel + 1));
    }
    linha.ramo = ramo;
    return ramo;
  };
  for (const u of filhos.get(null) ?? []) visitar(u, 0);
  // ciclos (nenhum elemento chega à raiz): mostram-se no fim, como raízes
  for (const u of lista) if (!visitados.has(u.unidade_organica_id)) visitar(u, 0);
  return saida;
}

export function taxaOcupacao(c: Pick<ContagemMapa, 'previstos' | 'ocupados'>): number | null {
  return c.previstos > 0 ? Math.round((c.ocupados / c.previstos) * 100) : null;
}

/** Colunas numéricas do mapa de pessoal para impressão (com a massa salarial só para quem a pode ver). */
function colunasContagem<T>(valor: (l: T) => ContagemMapa, verSalarios: boolean, total: ContagemMapa | null): ColunaImpressao<T>[] {
  const c: ColunaImpressao<T>[] = [
    { titulo: 'Previstos', valor: (l) => valor(l).previstos, formato: 'inteiro', total: total ? String(total.previstos) : undefined },
    { titulo: 'Ocupados', valor: (l) => valor(l).ocupados, formato: 'inteiro', total: total ? String(total.ocupados) : undefined },
    { titulo: 'Em aberto', valor: (l) => valor(l).em_aberto, formato: 'inteiro', total: total ? String(total.em_aberto) : undefined },
    { titulo: 'Acima', valor: (l) => valor(l).acima, formato: 'inteiro', total: total ? String(total.acima) : undefined },
    { titulo: 'Colaboradores', valor: (l) => valor(l).colaboradores, formato: 'inteiro', total: total ? String(total.colaboradores) : undefined },
  ];
  if (verSalarios) c.push({ titulo: 'Massa salarial (Kz)', valor: (l) => valor(l).massa_salarial ?? '0.00', formato: 'moeda', total: total ? formatarKz(total.massa_salarial ?? '0.00') : undefined });
  return c;
}

/**
 * Mapa de pessoal para impressão: indicadores e as três vistas (unidade orgânica, cargo, posto), com os filtros do ecrã.
 * Não contém nomes de pessoas (só contagens e, se autorizado, a massa salarial agregada).
 */
export function documentoMapaPessoal(
  d: MapaPessoalApi,
  unidades: LinhaUnidadeMapa[],
  cargos: CargoMapa[],
  postos: LinhaMapa[],
  opcoes: { ramo: boolean; verSalarios: boolean },
): string {
  const t = d.totais;
  const taxa = taxaOcupacao(t);
  const indicadores: [string, string | number][] = [
    ['Lugares previstos', `${t.previstos} (${t.postos} posto(s))`],
    ['Ocupados', `${t.ocupados}${taxa !== null ? ` (${taxa}% de ocupação)` : ''}`],
    ['Em aberto', t.em_aberto],
    ['Acima do previsto', t.acima],
    ['Colaboradores activos', t.colaboradores],
    ['Sem unidade orgânica', d.sem_unidade.colaboradores],
  ];
  if (opcoes.verSalarios) indicadores.push(['Massa salarial mensal', `${formatarKz(t.massa_salarial ?? '0.00')} Kz${d.periodo_salarial ? ` (${d.periodo_salarial})` : ''}`]);
  const num = (l: LinhaUnidadeMapa) => (opcoes.ramo ? l.ramo : l.propria);
  const porUnidade = tabelaHtml<LinhaUnidadeMapa>({
    legenda: `Por unidade orgânica${opcoes.ramo ? ' (com as subunidades)' : ''}`,
    linhas: unidades,
    totais: 'Sem unidade orgânica',
    colunas: [
      { titulo: 'Unidade', valor: (l) => `${'\u00a0\u00a0'.repeat(l.nivel)}${l.unidade.codigo ? `${l.unidade.codigo} — ` : ''}${l.unidade.nome}`, total: 'Sem unidade orgânica' },
      ...colunasContagem(num, opcoes.verSalarios, d.sem_unidade),
    ],
  });
  const porCargo = tabelaHtml<CargoMapa>({
    legenda: 'Por cargo',
    linhas: cargos,
    totais: 'Total',
    colunas: [{ titulo: 'Cargo', valor: (l) => l.nome, total: 'Total' }, ...colunasContagem((l: CargoMapa) => l, opcoes.verSalarios, t)],
  });
  const porPosto = tabelaHtml<LinhaMapa>({
    legenda: 'Por posto de trabalho',
    linhas: postos,
    colunas: [
      { titulo: 'Unidade', valor: (l) => `${'\u00a0\u00a0'.repeat(l.nivel)}${l.unidade}` },
      { titulo: 'Posto', valor: (l) => (l.posto ? `${l.posto}${l.chefia ? ' (chefia)' : ''}` : 'sem postos') },
      { titulo: 'Vagas', valor: (l) => l.vagas, formato: 'inteiro' },
      { titulo: 'Ocupados', valor: (l) => l.ocupados, formato: 'inteiro' },
      { titulo: 'Em aberto', valor: (l) => l.livres, formato: 'inteiro' },
      { titulo: 'Acima', valor: (l) => l.acima, formato: 'inteiro' },
    ],
  });
  return `${pares(indicadores, 3)}${porUnidade}${porCargo}${porPosto}`;
}
