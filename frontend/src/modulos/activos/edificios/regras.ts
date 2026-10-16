import type { OpcoesLocais, Resultado } from './tipos';

/** Regras de apresentação dos Edifícios e Locais (js/activos_edificios.js). O servidor valida e decide; aqui só se mostra. */

export const RESULTADOS: Record<Resultado, { rotulo: string; cor: string }> = {
  ENCONTRADO: { rotulo: 'Encontrado', cor: '#16a34a' },
  EM_FALTA: { rotulo: 'Em falta', cor: '#dc2626' },
  DESLOCADO: { rotulo: 'Noutro local', cor: '#d97706' },
  EXTRA: { rotulo: 'Não previsto', cor: '#7c3aed' },
};

export const AMBITOS = [
  { value: 'EDIFICIO', label: 'Todo o edifício' },
  { value: 'ANDAR', label: 'Um andar' },
  { value: 'PORTA', label: 'Uma porta' },
] as const;

export const MAX_PORTAS = 200;

/** Nome do andar por omissão (assetFloorModal :352): 0 = Rés-do-chão, n = «n.º andar». */
export function nomeAndarPorOmissao(numero: string | number | null | undefined): string {
  const t = String(numero ?? '').trim();
  if (t === '') return 'Piso';
  if (!/^-?\d+$/.test(t)) return `Piso ${t}`;
  const n = Number(t);
  return n === 0 ? 'Rés-do-chão' : `${n}.º andar`;
}

export function nomeAndar(a: { nome?: string | null; numero?: string | number | null } | null | undefined): string {
  if (!a) return '—';
  return a.nome || `Piso ${a.numero ?? ''}`.trim();
}

/** Pré-visualização das portas em série (previa :409-417): «Vão ser criadas N porta(s): A1, A2, A3 … A6». */
export function previaPortas(prefixo: string, de: number | null | undefined, ate: number | null | undefined): { valida: boolean; n: number; texto: string } {
  if (de === null || de === undefined || ate === null || ate === undefined || Number.isNaN(de) || Number.isNaN(ate) || ate < de) {
    return { valida: false, n: 0, texto: 'Indique um intervalo válido.' };
  }
  const n = ate - de + 1;
  if (n > MAX_PORTAS) return { valida: false, n, texto: `No máximo ${MAX_PORTAS} portas de cada vez.` };
  const p = prefixo.trim();
  const primeiras = Array.from({ length: Math.min(3, n) }, (_, i) => `${p}${de + i}`);
  return { valida: true, n, texto: `Vão ser criadas ${n} porta(s): ${primeiras.join(', ')}${n > 3 ? ` … ${p}${ate}` : ''}` };
}

/**
 * Texto da localização para listas («Edifício · Andar · Porta», assetLocalTexto :1015-1027). Sem porta mas com edifício
 * (ADR-071, A2) dá «Edifício · [Andar ·] sem porta».
 */
export function textoLocal(opcoes: OpcoesLocais | undefined, portaId: number | null | undefined, edificioId?: number | null, andarId?: number | null): string {
  if (!opcoes?.portas) return '';
  if (!portaId) {
    if (!edificioId) return '';
    const e = opcoes.edificios.find((x) => x.id === edificioId);
    const a = andarId ? opcoes.andares.find((x) => x.id === andarId) : undefined;
    return [e?.codigo || e?.descricao || '', a ? nomeAndar(a) : '', 'sem porta'].filter(Boolean).join(' · ');
  }
  const p = opcoes.portas.find((x) => x.id === portaId);
  if (!p) return '';
  const e = opcoes.edificios.find((x) => x.id === p.edificio_id);
  const a = opcoes.andares.find((x) => x.id === p.andar_id);
  return [e?.codigo || e?.descricao || '', nomeAndar(a), p.numero].filter(Boolean).join(' · ');
}

/** Localização da ficha do activo (edifício › andar › porta) a partir das relações que vêm em GET /ativos/bens/{id}. */
export function textoLocalFicha(a: {
  tipo_ativo?: string | null;
  edificio?: { codigo: string | null; descricao: string } | null;
  andar?: { numero: string | null; nome: string | null } | null;
  porta?: { numero: string; nome: string | null } | null;
}): string {
  if (a.tipo_ativo === 'EDIFICIO') return 'É um edifício / local';
  if (!a.edificio) return 'Sem localização';
  const edificio = [a.edificio.codigo, a.edificio.descricao].filter(Boolean).join(' — ');
  const porta = a.porta ? `Porta ${a.porta.numero}${a.porta.nome ? ` (${a.porta.nome})` : ''}` : 'sem porta';
  return [edificio, a.andar ? nomeAndar(a.andar) : '', porta].filter(Boolean).join(' › ');
}

/** Filtra os bens por código ou descrição (caixa «Procurar bem…» da planta). */
export function filtrarBens<T extends { codigo: string | null; descricao: string }>(bens: T[], texto: string): T[] {
  const q = texto.trim().toLowerCase();
  if (!q) return bens;
  return bens.filter((b) => `${b.codigo ?? ''} ${b.descricao}`.toLowerCase().includes(q));
}

/** «Marcar todos encontrados» (assetMarcarTodos :780-782): só as linhas ainda por contar. */
export function marcarTodosEncontrados<T extends { resultado: Resultado | null | '' }>(linhas: T[]): T[] {
  return linhas.map((l) => (l.resultado ? l : { ...l, resultado: 'ENCONTRADO' as Resultado }));
}
