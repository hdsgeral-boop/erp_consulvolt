/** Estrutura orgânica (GET /rh/estrutura): tipos da API e transformações puras (árvore, totais, mapa de pessoal). */

export const TIPOS_UNIDADE: Record<string, string> = {
  ORGAO_SOCIAL: 'Órgão social',
  DIRECCAO_GERAL: 'Direcção-geral',
  DIRECCAO: 'Direcção',
  DEPARTAMENTO: 'Departamento',
  GABINETE: 'Gabinete',
  SECCAO: 'Secção',
  EQUIPA: 'Equipa',
  OUTRO: 'Outro',
};

export interface Posto {
  id: number;
  unidade_organica_id: number;
  cargo_funcao_id: number | null;
  titulo: string | null;
  vagas: number | null;
  posto_superior_id: number | null;
  responsabilidades: string | null;
  chefia: boolean | number | null;
  ordem: number | null;
  ocupados: number;
  livres: number;
}

export interface Unidade {
  id: number;
  codigo: string | null;
  nome: string;
  tipo: string | null;
  unidade_organica_pai_id: number | null;
  colaborador_responsavel_id: number | null;
  missao: string | null;
  atribuicoes: string | null;
  centro_custo_id: number | null;
  unidade_negocio_id: number | null;
  ordem: number | null;
  ativo: boolean;
  apoio: boolean | number | null;
  cor: string | null;
  membros: number;
  postos: Posto[];
}

export interface Estrutura {
  unidades: Unidade[];
  sem_unidade: number;
}

export interface NoUnidade {
  unidade: Unidade;
  filhos: NoUnidade[];
  nivel: number;
  /** membros da unidade e de todas as descendentes */
  membrosTotal: number;
  vagasTotal: number;
  ocupadosTotal: number;
}

/**
 * Constrói a árvore a partir da lista plana. Unidades cujo pai não existe (ou que formariam um ciclo) ficam na raiz.
 * Ordena por `ordem` e nome; calcula os totais acumulados de cada ramo.
 */
export function construirArvore(unidades: Unidade[], apenasActivas = false): NoUnidade[] {
  const lista = apenasActivas ? unidades.filter((u) => u.ativo) : unidades;
  const ids = new Set(lista.map((u) => u.id));
  const filhosDe = new Map<number | null, Unidade[]>();
  for (const u of lista) {
    let pai = u.unidade_organica_pai_id && ids.has(u.unidade_organica_pai_id) ? u.unidade_organica_pai_id : null;
    // ciclo: subir pelos pais até voltar à própria unidade
    if (pai !== null) {
      const vistos = new Set<number>([u.id]);
      let p: number | null = pai;
      while (p !== null) {
        if (vistos.has(p)) {
          pai = null;
          break;
        }
        vistos.add(p);
        const up = lista.find((x) => x.id === p);
        p = up?.unidade_organica_pai_id && ids.has(up.unidade_organica_pai_id) ? up.unidade_organica_pai_id : null;
      }
    }
    filhosDe.set(pai, [...(filhosDe.get(pai) ?? []), u]);
  }
  const ordenar = (a: Unidade, b: Unidade) => (a.ordem ?? 0) - (b.ordem ?? 0) || a.nome.localeCompare(b.nome, 'pt');
  const montar = (pai: number | null, nivel: number): NoUnidade[] =>
    (filhosDe.get(pai) ?? []).sort(ordenar).map((u) => {
      const filhos = montar(u.id, nivel + 1);
      const vagas = u.postos.reduce((s, p) => s + (p.vagas ?? 0), 0);
      const ocupados = u.postos.reduce((s, p) => s + p.ocupados, 0);
      return {
        unidade: u,
        filhos,
        nivel,
        membrosTotal: u.membros + filhos.reduce((s, f) => s + f.membrosTotal, 0),
        vagasTotal: vagas + filhos.reduce((s, f) => s + f.vagasTotal, 0),
        ocupadosTotal: ocupados + filhos.reduce((s, f) => s + f.ocupadosTotal, 0),
      };
    });
  return montar(null, 0);
}

/** Percorre a árvore em profundidade (pré-ordem). */
export function aplanar(nos: NoUnidade[]): NoUnidade[] {
  return nos.flatMap((n) => [n, ...aplanar(n.filhos)]);
}

/** Ids de uma unidade e de todas as descendentes (para não deixar escolher um descendente como pai). */
export function descendentes(nos: NoUnidade[], id: number): Set<number> {
  const no = aplanar(nos).find((n) => n.unidade.id === id);
  return new Set(no ? aplanar([no]).map((n) => n.unidade.id) : []);
}

export interface LinhaMapa {
  chave: string;
  unidade: string;
  nivel: number;
  posto: string | null;
  cargo_funcao_id: number | null;
  chefia: boolean;
  vagas: number;
  ocupados: number;
  livres: number;
  acima: number;
}

/** Mapa de pessoal: uma linha por posto (pela ordem da árvore), com vagas, ocupados, livres e acima do previsto. */
export function mapaPessoal(arvore: NoUnidade[], nomeCargo: (id: number | null) => string): LinhaMapa[] {
  return aplanar(arvore).flatMap((n): LinhaMapa[] => {
    const postos = [...n.unidade.postos].sort((a, b) => (a.ordem ?? 0) - (b.ordem ?? 0) || a.id - b.id);
    if (!postos.length) return [{ chave: `u${n.unidade.id}`, unidade: n.unidade.nome, nivel: n.nivel, posto: null, cargo_funcao_id: null, chefia: false, vagas: 0, ocupados: 0, livres: 0, acima: 0 }];
    return postos.map((p) => {
      const vagas = p.vagas ?? 0;
      return {
        chave: `p${p.id}`,
        unidade: n.unidade.nome,
        nivel: n.nivel,
        posto: p.titulo || nomeCargo(p.cargo_funcao_id),
        cargo_funcao_id: p.cargo_funcao_id,
        chefia: !!p.chefia,
        vagas,
        ocupados: p.ocupados,
        livres: Math.max(0, vagas - p.ocupados),
        acima: Math.max(0, p.ocupados - vagas),
      };
    });
  });
}

export function totaisMapa(linhas: LinhaMapa[]): { vagas: number; ocupados: number; livres: number; acima: number; taxa: number | null } {
  const t = linhas.reduce((s, l) => ({ vagas: s.vagas + l.vagas, ocupados: s.ocupados + l.ocupados, livres: s.livres + l.livres, acima: s.acima + l.acima }), { vagas: 0, ocupados: 0, livres: 0, acima: 0 });
  return { ...t, taxa: t.vagas ? Math.round(((t.vagas - t.livres) / t.vagas) * 1000) / 10 : null };
}
