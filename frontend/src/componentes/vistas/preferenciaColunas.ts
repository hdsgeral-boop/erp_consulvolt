import { QueryClient, QueryClientContext, useQuery } from '@tanstack/react-query';
import { useCallback, useContext, useEffect, useMemo, useSyncExternalStore } from 'react';
import { enviar, obter } from '@/api/cliente';
import { chavePreferencias, type Preferencia } from '@/componentes/preferencias/usePreferencias';

/**
 * Colunas escondidas pelo menu «Colunas ▾» (como o botão «Colunas» das DataTables do antigo), lembradas **por utilizador
 * e por ecrã** numa só preferência do servidor (`PUT /api/sistema/preferencias/interface/colunas_listas`, valor
 * `{ [ecrã]: { ocultas: [...], mostradas: [...] } }`) com cópia no `localStorage` (try/catch: em modo privado fica só na
 * sessão). Sem QueryClientProvider (testes isolados) usa só o navegador.
 *
 * `mostradas` guarda as colunas `ocultaPorOmissao` que o utilizador decidiu mostrar.
 */
export interface EscolhaColunas {
  ocultas: string[];
  mostradas: string[];
}
export type PreferenciasColunas = Record<string, EscolhaColunas>;

export const NOME_PREFERENCIA_COLUNAS = 'colunas_listas';
export const CHAVE_LOCAL_COLUNAS = 'erp.colunas';
const URL_PREFERENCIAS = '/sistema/preferencias/interface';
const MAX_ECRAS = 300;
const MAX_COLUNAS = 80;

const ouvintes = new Set<() => void>();

function lista(v: unknown): string[] {
  return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string').map((x) => x.slice(0, 120)).slice(0, MAX_COLUNAS) : [];
}

export function normalizarPreferenciasColunas(v: unknown): PreferenciasColunas {
  if (!v || typeof v !== 'object' || Array.isArray(v)) return {};
  const r: PreferenciasColunas = {};
  for (const [k, e] of Object.entries(v as Record<string, unknown>)) {
    if (!e || typeof e !== 'object') continue;
    const o = e as Record<string, unknown>;
    r[k.slice(0, 300)] = { ocultas: lista(o.ocultas), mostradas: lista(o.mostradas) };
  }
  return r;
}

function lerLocal(): PreferenciasColunas {
  try {
    const t = window.localStorage.getItem(CHAVE_LOCAL_COLUNAS);
    return t ? normalizarPreferenciasColunas(JSON.parse(t)) : {};
  } catch {
    return {};
  }
}

let estado: PreferenciasColunas = typeof window !== 'undefined' ? lerLocal() : {};
let ultimaAlteracaoLocal = 0;

function definirLocal(novo: PreferenciasColunas): void {
  estado = novo;
  try {
    window.localStorage.setItem(CHAVE_LOCAL_COLUNAS, JSON.stringify(novo));
  } catch {
    /* armazenamento indisponível: fica só nesta sessão */
  }
  ouvintes.forEach((f) => f());
}

function subscrever(f: () => void): () => void {
  ouvintes.add(f);
  return () => ouvintes.delete(f);
}

/** Repõe o estado (apoio a testes). */
export function reporPreferenciasColunasLocal(): void {
  try {
    window.localStorage.removeItem(CHAVE_LOCAL_COLUNAS);
  } catch {
    /* sem armazenamento */
  }
  estado = {};
  ouvintes.forEach((f) => f());
}

let clienteSemProvedor: QueryClient | null = null;

/** Escolha de colunas de um ecrã (`ecra` = chave da lista, ex.: `chaveEcraVista(idVista)`). */
export function useColunasEscolhidas(ecra: string): { escolha: EscolhaColunas; definir: (e: EscolhaColunas) => void } {
  const contexto = useContext(QueryClientContext);
  const cliente = contexto ?? (clienteSemProvedor ??= new QueryClient());
  const chave = chavePreferencias('interface');
  const consulta = useQuery({ queryKey: chave, queryFn: () => obter<Preferencia[]>(URL_PREFERENCIAS), staleTime: 5 * 60_000, retry: false, enabled: !!contexto }, cliente);
  const local = useSyncExternalStore(subscrever, () => estado, () => estado);

  // O servidor manda (salvo alteração local mais recente do que a leitura).
  useEffect(() => {
    if (!consulta.isSuccess || !Array.isArray(consulta.data) || consulta.dataUpdatedAt < ultimaAlteracaoLocal) return;
    const p = consulta.data.find((x) => x?.nome === NOME_PREFERENCIA_COLUNAS);
    if (!p) return;
    const doServidor = normalizarPreferenciasColunas(p.valor);
    if (JSON.stringify(doServidor) !== JSON.stringify(estado)) definirLocal(doServidor);
  }, [consulta.isSuccess, consulta.data, consulta.dataUpdatedAt]);

  const escolha = useMemo<EscolhaColunas>(() => local[ecra] ?? { ocultas: [], mostradas: [] }, [local, ecra]);

  const definir = useCallback(
    (e: EscolhaColunas) => {
      const novo = { ...estado };
      delete novo[ecra];
      if (e.ocultas.length || e.mostradas.length) novo[ecra] = { ocultas: lista(e.ocultas), mostradas: lista(e.mostradas) };
      const chaves = Object.keys(novo);
      const limitado = chaves.length > MAX_ECRAS ? Object.fromEntries(chaves.slice(-MAX_ECRAS).map((k) => [k, novo[k]])) : novo;
      ultimaAlteracaoLocal = Date.now();
      definirLocal(limitado);
      if (!contexto) return;
      void cliente.cancelQueries({ queryKey: chave });
      enviar('put', `${URL_PREFERENCIAS}/${NOME_PREFERENCIA_COLUNAS}`, { valor: limitado })
        .then(() => {
          cliente.setQueryData<Preferencia[]>(chave, (l) => [...(Array.isArray(l) ? l : []).filter((p) => p.nome !== NOME_PREFERENCIA_COLUNAS), { nome: NOME_PREFERENCIA_COLUNAS, valor: limitado }]);
        })
        .catch(() => {
          /* sem ligação: a escolha fica no navegador */
        });
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [ecra, contexto, cliente],
  );

  return { escolha, definir };
}
