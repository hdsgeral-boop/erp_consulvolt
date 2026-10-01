/**
 * Lógica pura do editor de perfis v2 (ADR-040/058): catálogo de permissões (GET /sistema/perfis/catalogo), marcação de ecrãs
 * e tarefas, contagem, segregação de funções e matriz perfis × permissões. O servidor volta a validar tudo ao gravar.
 */

export interface TarefaCatalogo {
  chave: string;
  rotulo: string;
  sensivel: boolean;
}

export interface EcraCatalogo {
  id: string;
  nome: string;
  vistas: string[];
  pai: string | null;
  nota: string | null;
  tarefas: TarefaCatalogo[];
}

export interface ModuloCatalogo {
  id: string;
  nome: string;
  ecras: EcraCatalogo[];
}

export interface RegraSegregacao {
  a: string;
  b: string;
  motivo: string;
}

export interface ModeloPerfil {
  nome: string;
  permissoes: Record<string, boolean>;
}

export interface CatalogoPermissoes {
  modulos: ModuloCatalogo[];
  segregacao: RegraSegregacao[];
  modelos: ModeloPerfil[];
}

export interface Contagem {
  ecras: number;
  tarefas: number;
  sensiveis: number;
  conflitos: RegraSegregacao[];
}

/** Chave de consulta de um ecrã (como no servidor: «<ecrã>_view»). */
export const chaveVer = (ecra: string) => `${ecra}_view`;

/** Índice tarefa → ecrã e ecrã → módulo, para operações rápidas. */
export function indexar(catalogo: CatalogoPermissoes) {
  const tarefaEcra = new Map<string, EcraCatalogo>();
  const tarefas = new Map<string, TarefaCatalogo>();
  const ecras = new Map<string, EcraCatalogo>();
  for (const m of catalogo.modulos)
    for (const e of m.ecras) {
      ecras.set(e.id, e);
      for (const t of e.tarefas) {
        tarefaEcra.set(t.chave, e);
        tarefas.set(t.chave, t);
      }
    }
  return { tarefaEcra, tarefas, ecras };
}

/** Todas as chaves válidas do catálogo (consulta de cada ecrã + tarefas). */
export function chavesDoCatalogo(catalogo: CatalogoPermissoes): Set<string> {
  const s = new Set<string>();
  for (const m of catalogo.modulos)
    for (const e of m.ecras) {
      s.add(chaveVer(e.id));
      e.tarefas.forEach((t) => s.add(t.chave));
    }
  return s;
}

/**
 * Marca/desmarca a consulta de um ecrã. Desmarcar a consulta retira também as tarefas desse ecrã
 * (uma tarefa sem consulta do ecrã não se consegue usar).
 */
export function alternarEcra(chaves: Set<string>, ecra: EcraCatalogo, marcar: boolean): Set<string> {
  const n = new Set(chaves);
  if (marcar) n.add(chaveVer(ecra.id));
  else {
    n.delete(chaveVer(ecra.id));
    ecra.tarefas.forEach((t) => n.delete(t.chave));
  }
  return n;
}

/** Marca/desmarca uma tarefa; marcar uma tarefa marca a consulta do respectivo ecrã. */
export function alternarTarefa(chaves: Set<string>, ecra: EcraCatalogo, tarefa: string, marcar: boolean): Set<string> {
  const n = new Set(chaves);
  if (marcar) {
    n.add(tarefa);
    n.add(chaveVer(ecra.id));
  } else n.delete(tarefa);
  return n;
}

/** Marca (consulta + todas as tarefas, opcionalmente sem as sensíveis) ou desmarca um módulo inteiro. */
export function alternarModulo(chaves: Set<string>, modulo: ModuloCatalogo, marcar: boolean, incluirSensiveis = true): Set<string> {
  let n = new Set(chaves);
  for (const e of modulo.ecras) {
    n = alternarEcra(n, e, marcar);
    if (marcar) e.tarefas.filter((t) => incluirSensiveis || !t.sensivel).forEach((t) => n.add(t.chave));
  }
  return n;
}

/** Estado de um módulo no editor: nenhum, parcial ou total (consultas e tarefas). */
export function estadoModulo(chaves: Set<string>, modulo: ModuloCatalogo): 'nenhum' | 'parcial' | 'total' {
  const todas = modulo.ecras.flatMap((e) => [chaveVer(e.id), ...e.tarefas.map((t) => t.chave)]);
  const marcadas = todas.filter((k) => chaves.has(k)).length;
  return marcadas === 0 ? 'nenhum' : marcadas === todas.length ? 'total' : 'parcial';
}

/** Conflitos de segregação de funções presentes num conjunto de chaves. */
export function conflitosSegregacao(chaves: Set<string>, regras: RegraSegregacao[]): RegraSegregacao[] {
  return regras.filter((r) => chaves.has(r.a) && chaves.has(r.b));
}

/** Contagem igual à do servidor (ServicoPerfis::contar). */
export function contar(chaves: Set<string>, catalogo: CatalogoPermissoes): Contagem {
  let ecras = 0;
  let tarefas = 0;
  let sensiveis = 0;
  for (const m of catalogo.modulos)
    for (const e of m.ecras) {
      if (chaves.has(chaveVer(e.id))) ecras++;
      for (const t of e.tarefas)
        if (chaves.has(t.chave)) {
          tarefas++;
          if (t.sensivel) sensiveis++;
        }
    }
  return { ecras, tarefas, sensiveis, conflitos: conflitosSegregacao(chaves, catalogo.segregacao) };
}

/** Chaves de um perfil-modelo (sem `_v2`/`all`). */
export function chavesDoModelo(modelo: ModeloPerfil): Set<string> {
  return new Set(Object.entries(modelo.permissoes).filter(([k, v]) => v === true && k !== '_v2' && k !== 'all').map(([k]) => k));
}

/** Filtra o catálogo por texto (módulo, ecrã ou tarefa); devolve só os módulos/ecrãs com correspondência. */
export function filtrarCatalogo(modulos: ModuloCatalogo[], texto: string): ModuloCatalogo[] {
  const t = normalizar(texto);
  if (!t) return modulos;
  return modulos
    .map((m) => {
      if (normalizar(m.nome).includes(t)) return m;
      const ecras = m.ecras.filter((e) => normalizar(e.nome).includes(t) || e.id.includes(t) || e.tarefas.some((x) => normalizar(x.rotulo).includes(t) || x.chave.includes(t)));
      return { ...m, ecras };
    })
    .filter((m) => m.ecras.length > 0);
}

export function normalizar(s: string): string {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim();
}

export interface LinhaMatriz {
  chave: string;
  modulo: string;
  ecra: string;
  rotulo: string;
  tipo: 'consulta' | 'tarefa';
  sensivel: boolean;
  /** ids dos perfis que têm a permissão (perfis com acesso total contam sempre) */
  perfis: Set<number>;
}

/**
 * Matriz perfis × permissões a partir de GET /sistema/perfis/matriz ({perfis, chaves: {chave: [ids]}}) e do catálogo:
 * uma linha por consulta de ecrã e por tarefa, pela ordem do catálogo. Perfis com acesso total marcam todas as linhas.
 */
export function construirMatriz(
  catalogo: CatalogoPermissoes,
  matriz: { perfis: { id: number; nome: string; acesso_total: boolean }[]; chaves: Record<string, number[]> },
  apenasComPerfis = false,
): LinhaMatriz[] {
  const totais = matriz.perfis.filter((p) => p.acesso_total).map((p) => p.id);
  const linhas: LinhaMatriz[] = [];
  for (const m of catalogo.modulos)
    for (const e of m.ecras) {
      const entradas: [string, string, 'consulta' | 'tarefa', boolean][] = [
        [chaveVer(e.id), `Consultar — ${e.nome}`, 'consulta', false],
        ...e.tarefas.map((t) => [t.chave, t.rotulo, 'tarefa', t.sensivel] as [string, string, 'tarefa', boolean]),
      ];
      for (const [chave, rotulo, tipo, sensivel] of entradas) {
        const perfis = new Set([...(matriz.chaves[chave] ?? []), ...totais]);
        if (apenasComPerfis && perfis.size === totais.length && !(matriz.chaves[chave]?.length)) continue;
        linhas.push({ chave, modulo: m.nome, ecra: e.nome, rotulo, tipo, sensivel, perfis });
      }
    }
  return linhas;
}
