/**
 * Transformações puras que reproduzem o ecrã «Estrutura orgânica» do sistema anterior
 * (payroll_system_web/js/modules/estrutura/estrutura_ui.js e estrutura_dados.js):
 *   - caminho da unidade («Direcção-Geral › Direcção Financeira»);
 *   - linhas de «Colaboradores afectos» (colaborador, função, cargo, chefia directa, salário mensal);
 *   - mapa de pessoal numa só tabela (unidade + cargo: vagas, ocupados, em aberto, acima, pessoas, massa salarial);
 *   - unidades a empilhar / pôr lado a lado no organigrama.
 * No sistema novo o «cargo» do antigo (lugar numa unidade, com vagas) é o posto de trabalho; a «função» é o cargo/função.
 */
import { pares, tabelaHtml, type ColunaImpressao } from '@/componentes/impressao';
import { formatarKz } from '@/utilitarios/formatacao';
import { aplanar, type NoUnidade, type Posto } from './arvore';

/** Colaborador mínimo usado pela estrutura (subconjunto de rh/api Colaborador). */
export interface ColaboradorEstrutura {
  id: number;
  nome_completo: string;
  estado: string;
  unidade_organica_id: number | null;
  posto_trabalho_id: number | null;
  colaborador_gestor_id: number | null;
  cargo_funcao_id: number | null;
}

/** Salário mensal (ilíquido do último processamento fechado) por colaborador; vazio sem a permissão. */
export type MapaSalarios = Map<number, number>;

export interface SalariosApi {
  periodo_salarial: string | null;
  por_colaborador: { colaborador_id: number; valor: string }[];
}

export function mapaSalarios(api: SalariosApi | null | undefined): MapaSalarios {
  return new Map((api?.por_colaborador ?? []).map((l) => [l.colaborador_id, Number(l.valor) || 0]));
}

const somaCentimos = (valores: number[]) => Math.round(valores.reduce((s, v) => s + Math.round(v * 100), 0)) / 100;

export const nomePosto = (p: Posto, nomeCargo: (id: number | null) => string) => p.titulo || nomeCargo(p.cargo_funcao_id);

/** Caminho desde a raiz até à unidade (inclusive). */
export function caminho(arvore: NoUnidade[], id: number): NoUnidade[] {
  const procurar = (nos: NoUnidade[], acc: NoUnidade[]): NoUnidade[] | null => {
    for (const n of nos) {
      const c = [...acc, n];
      if (n.unidade.id === id) return c;
      const r = procurar(n.filhos, c);
      if (r) return r;
    }
    return null;
  };
  return procurar(arvore, []) ?? [];
}

export interface LinhaMembro {
  id: number;
  nome: string;
  responsavel: boolean;
  funcao: string;
  /** nome do cargo (posto) da unidade; null = «sem cargo» */
  cargo: string | null;
  /** chefia directa: gestor explícito; sem ele, o responsável da unidade marcado «(responsável)»; '—' se não houver */
  chefia: string;
  chefiaDeduzida: boolean;
  salario: number | null;
}

/** Linhas da tabela «Colaboradores afectos» de uma unidade (só activos, como o antigo). */
export function linhasMembros(
  no: NoUnidade,
  colaboradores: ColaboradorEstrutura[],
  nomeCargo: (id: number | null) => string,
  salarios: MapaSalarios | null,
): LinhaMembro[] {
  const u = no.unidade;
  const porId = new Map(colaboradores.map((c) => [c.id, c]));
  const resp = u.colaborador_responsavel_id ? porId.get(u.colaborador_responsavel_id) : undefined;
  return colaboradores
    .filter((c) => c.estado === 'ACTIVO' && c.unidade_organica_id === u.id)
    .map((c) => {
      const posto = u.postos.find((p) => p.id === c.posto_trabalho_id);
      const gestor = c.colaborador_gestor_id ? porId.get(c.colaborador_gestor_id) : undefined;
      const deduzida = !gestor;
      return {
        id: c.id,
        nome: c.nome_completo,
        responsavel: c.id === u.colaborador_responsavel_id,
        funcao: c.cargo_funcao_id ? nomeCargo(c.cargo_funcao_id) : '',
        cargo: posto ? nomePosto(posto, nomeCargo) : null,
        chefia: gestor ? gestor.nome_completo : resp && resp.id !== c.id ? `${resp.nome_completo} (responsável)` : '—',
        chefiaDeduzida: deduzida,
        salario: salarios ? salarios.get(c.id) ?? 0 : null,
      };
    });
}

/** Ocupantes (activos) de cada posto. */
export function ocupantesPorPosto(colaboradores: ColaboradorEstrutura[]): Map<number, ColaboradorEstrutura[]> {
  const m = new Map<number, ColaboradorEstrutura[]>();
  for (const c of colaboradores) {
    if (c.estado !== 'ACTIVO' || !c.posto_trabalho_id) continue;
    m.set(c.posto_trabalho_id, [...(m.get(c.posto_trabalho_id) ?? []), c]);
  }
  return m;
}

export interface LinhaMapaLegado {
  chave: string;
  unidadeId: number;
  unidade: string;
  /** «Direcção-Geral › Finanças» (exportação Excel do antigo) */
  caminho: string;
  nivel: number;
  cargo: string;
  chefia: boolean;
  semCargo: boolean;
  vagas: number;
  ocupados: number;
  emAberto: number;
  acima: number;
  pessoas: string[];
  massa: number;
}

/**
 * Mapa de pessoal do antigo (estrutura_dados.js mapaPessoal): pela ordem da árvore, uma linha por cargo (posto) de
 * cada unidade e, se houver pessoas da unidade sem cargo, uma linha «(sem cargo atribuído)». Unidades sem cargos nem
 * pessoas não têm linha.
 */
export function linhasMapaLegado(
  arvore: NoUnidade[],
  colaboradores: ColaboradorEstrutura[],
  nomeCargo: (id: number | null) => string,
  salarios: MapaSalarios | null,
): LinhaMapaLegado[] {
  const ocup = ocupantesPorPosto(colaboradores);
  const sal = (cs: ColaboradorEstrutura[]) => somaCentimos(cs.map((c) => salarios?.get(c.id) ?? 0));
  const linhas: LinhaMapaLegado[] = [];
  const percorrer = (nos: NoUnidade[], antes: string[]) => {
    for (const n of nos) {
      const cam = [...antes, n.unidade.nome];
      const postos = [...n.unidade.postos].sort((a, b) => (a.ordem ?? 0) - (b.ordem ?? 0) || a.id - b.id);
      for (const p of postos) {
        const pessoas = ocup.get(p.id) ?? [];
        const vagas = p.vagas ?? 0;
        const ocupados = pessoas.length;
        linhas.push({
          chave: `p${p.id}`, unidadeId: n.unidade.id, unidade: n.unidade.nome, caminho: cam.join(' › '), nivel: n.nivel,
          cargo: nomePosto(p, nomeCargo), chefia: !!p.chefia, semCargo: false,
          vagas, ocupados, emAberto: Math.max(0, vagas - ocupados), acima: Math.max(0, ocupados - vagas),
          pessoas: pessoas.map((c) => c.nome_completo), massa: sal(pessoas),
        });
      }
      const idsPostos = new Set(n.unidade.postos.map((p) => p.id));
      const semCargo = colaboradores.filter((c) => c.estado === 'ACTIVO' && c.unidade_organica_id === n.unidade.id && !(c.posto_trabalho_id && idsPostos.has(c.posto_trabalho_id)));
      if (semCargo.length) {
        linhas.push({
          chave: `s${n.unidade.id}`, unidadeId: n.unidade.id, unidade: n.unidade.nome, caminho: cam.join(' › '), nivel: n.nivel,
          cargo: '(sem cargo atribuído)', chefia: false, semCargo: true,
          vagas: 0, ocupados: semCargo.length, emAberto: 0, acima: 0, pessoas: semCargo.map((c) => c.nome_completo), massa: sal(semCargo),
        });
      }
      percorrer(n.filhos, cam);
    }
  };
  percorrer(arvore, []);
  return linhas;
}

export function totaisMapaLegado(linhas: LinhaMapaLegado[]) {
  return {
    vagas: linhas.reduce((s, l) => s + l.vagas, 0),
    ocupados: linhas.reduce((s, l) => s + l.ocupados, 0),
    /** vagas ocupadas (sem contar os excedentes nem quem não tem cargo com vagas) */
    vagasOcupadas: linhas.reduce((s, l) => s + Math.min(l.vagas || l.ocupados, l.ocupados), 0),
    emAberto: linhas.reduce((s, l) => s + l.emAberto, 0),
    acima: linhas.reduce((s, l) => s + l.acima, 0),
    massa: somaCentimos(linhas.map((l) => l.massa)),
  };
}

export interface IndicadoresMapaLegado {
  activos: number;
  semUnidade: number;
  massaTotal: number | null;
}

/**
 * Mapa de pessoal para Imprimir/Excel (como o antigo: indicadores + a tabela única). Excel: a «Unidade» leva o caminho
 * completo (exportarMapa do antigo); no papel a unidade vai indentada pelo nível.
 */
export function documentoMapaLegado(linhas: LinhaMapaLegado[], ind: IndicadoresMapaLegado, verSalarios: boolean, paraExcel = false): string {
  const t = totaisMapaLegado(linhas);
  const kpis: [string, string | number][] = [
    ['Colaboradores activos', ind.activos],
    ['Vagas previstas', t.vagas],
    ['Vagas ocupadas', t.vagasOcupadas],
    ['Vagas em aberto', t.emAberto],
    ['Acima das vagas', t.acima],
    ['Sem unidade', ind.semUnidade],
  ];
  if (verSalarios && ind.massaTotal !== null) kpis.push(['Massa salarial mensal', formatarKz(ind.massaTotal.toFixed(2))]);
  const colunas: ColunaImpressao<LinhaMapaLegado>[] = [
    { titulo: 'Unidade', valor: (l) => (paraExcel ? l.caminho : `${'  '.repeat(l.nivel)}${l.unidade}`), total: 'Total' },
    { titulo: 'Cargo', valor: (l) => `${l.cargo}${l.chefia ? ' (chefia)' : ''}`, total: '' },
    { titulo: 'Vagas', valor: (l) => l.vagas, formato: 'inteiro', somar: true },
    { titulo: 'Ocupados', valor: (l) => l.ocupados, formato: 'inteiro', somar: true },
    { titulo: 'Em aberto', valor: (l) => l.emAberto, formato: 'inteiro', somar: true },
    { titulo: paraExcel ? 'Acima das vagas' : 'Acima', valor: (l) => l.acima, formato: 'inteiro', somar: true },
    { titulo: 'Pessoas', valor: (l) => l.pessoas.join(', '), quebrar: true, total: '' },
  ];
  if (verSalarios) colunas.push({ titulo: paraExcel ? 'Massa salarial mensal' : 'Massa salarial', valor: (l) => l.massa.toFixed(2), formato: 'moeda', somar: true });
  return `${pares(kpis, 4)}${tabelaHtml({ colunas, linhas, totais: 'Total', vazio: 'Defina a estrutura e os cargos para obter o mapa de pessoal.' })}`;
}

/** «Empilhar últimos níveis»:unidades com 2+ subunidades, nenhuma delas com subunidades. */
export function idsEmpilharUltimos(arvore: NoUnidade[]): number[] {
  return aplanar(arvore).filter((n) => n.filhos.length >= 2 && n.filhos.every((f) => !f.filhos.length)).map((n) => n.unidade.id);
}

/** «Tudo lado a lado»: unidades que hoje têm as subunidades em coluna. */
export function idsEmColuna(arvore: NoUnidade[]): number[] {
  return aplanar(arvore).filter((n) => n.unidade.disposicao === 'COLUNA').map((n) => n.unidade.id);
}

/** Zoom do organigrama do antigo: passos de 15 %, entre 35 % e 160 %; 0 = repor 100 %. */
export function proximoZoom(actual: number, passo: -1 | 0 | 1): number {
  if (passo === 0) return 1;
  return Math.round(Math.max(0.35, Math.min(1.6, actual + passo * 0.15)) * 100) / 100;
}

/** Ramos a recolher no «Recolher» do antigo: todas as unidades com subunidades que não são raiz. */
export function idsRecolher(arvore: NoUnidade[]): number[] {
  return aplanar(arvore).filter((n) => n.filhos.length && n.unidade.unidade_organica_pai_id).map((n) => n.unidade.id);
}

/** Etiqueta de ocupação de um cargo (como vagasTag do antigo). */
export function estadoVagas(vagas: number, ocupados: number): { texto: string; tipo: 'neutro' | 'exc' | 'aberto' | 'ok' } {
  if (!vagas) return { texto: `${ocupados} sem limite`, tipo: 'neutro' };
  if (ocupados > vagas) return { texto: `${ocupados}/${vagas} · +${ocupados - vagas}`, tipo: 'exc' };
  if (ocupados < vagas) return { texto: `${ocupados}/${vagas} · ${vagas - ocupados} em aberto`, tipo: 'aberto' };
  return { texto: `${ocupados}/${vagas}`, tipo: 'ok' };
}
