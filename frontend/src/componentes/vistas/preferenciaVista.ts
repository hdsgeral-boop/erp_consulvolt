import { QueryClient, QueryClientContext, useQuery } from '@tanstack/react-query';
import { useCallback, useContext, useEffect, useMemo, useSyncExternalStore } from 'react';
import { enviar, obter } from '@/api/cliente';
import { chavePreferencias, type Preferencia } from '@/componentes/preferencias/usePreferencias';
import { useEcra, useModoResponsivo } from '@/componentes/responsivo';

/**
 * Modo de vista das listas de registos (pedido do utilizador, 2026-10: «todas as vistas de cadastro/edição com 2
 * modos — grade ou linha — que o utilizador possa ajustar»).
 *
 * - `linhas`: a tabela de sempre (Ant Design `Table`);
 * - `grade`: cartões numa grelha responsiva, derivados das mesmas colunas.
 *
 * A escolha é guardada **por utilizador e por ecrã** numa única preferência do servidor
 * (`PUT /api/sistema/preferencias/interface/vistas_listas`, valor `{ omissao, ecras: { [ecrã]: modo } }` — uma só
 * entrada para não gastar o limite de 200 preferências por tipo), com cópia no `localStorage` do navegador: sem
 * ligação ao servidor a escolha fica no posto e é enviada na próxima leitura bem-sucedida.
 *
 * Ordem de resolução do modo: escolha do ecrã → omissão global do utilizador → omissão do ecrã (prop) →
 * automática (grade no telemóvel, linhas a partir de 768 px). Em portátil/desktop a omissão é sempre «linhas».
 */
export type ModoVista = 'linhas' | 'grade';
/** Omissão global: um modo fixo ou `automatica` (grade no telemóvel, linhas nos ecrãs largos). */
export type OmissaoVista = ModoVista | 'automatica';

export interface PreferenciasVista {
  omissao?: OmissaoVista;
  ecras?: Record<string, ModoVista>;
}

export const NOME_PREFERENCIA_VISTAS = 'vistas_listas';
export const CHAVE_LOCAL_VISTAS = 'erp.vistas';
/** Máximo de ecrãs guardados (a preferência tem de ficar muito abaixo dos 64 KB do servidor). */
const MAX_ECRAS = 400;
const URL_PREFERENCIAS = '/sistema/preferencias/interface';

interface EstadoLocal {
  valor: PreferenciasVista;
  /** `true` = alteração feita no posto ainda não confirmada pelo servidor. */
  pendente: boolean;
}

const ouvintes = new Set<() => void>();
/** Momento da última alteração feita no posto: leituras do servidor mais antigas do que isto são ignoradas. */
let ultimaAlteracaoLocal = 0;

function lerLocal(): EstadoLocal {
  try {
    const t = window.localStorage.getItem(CHAVE_LOCAL_VISTAS);
    if (!t) return { valor: {}, pendente: false };
    const o = JSON.parse(t) as Partial<EstadoLocal>;
    return { valor: normalizar(o.valor), pendente: o.pendente === true };
  } catch {
    return { valor: {}, pendente: false };
  }
}

let estado: EstadoLocal = typeof window !== 'undefined' ? lerLocal() : { valor: {}, pendente: false };

function definirLocal(novo: EstadoLocal): void {
  estado = novo;
  try {
    window.localStorage.setItem(CHAVE_LOCAL_VISTAS, JSON.stringify(novo));
  } catch {
    /* armazenamento indisponível (modo privado): fica só nesta sessão */
  }
  ouvintes.forEach((f) => f());
}

function subscrever(f: () => void): () => void {
  ouvintes.add(f);
  return () => ouvintes.delete(f);
}

const MODOS: readonly string[] = ['linhas', 'grade'];
const OMISSOES: readonly string[] = ['linhas', 'grade', 'automatica'];

/** Valida o valor lido (servidor ou navegador): ignora chaves e modos desconhecidos. */
export function normalizar(v: unknown): PreferenciasVista {
  if (!v || typeof v !== 'object') return {};
  const o = v as Record<string, unknown>;
  const r: PreferenciasVista = {};
  if (typeof o.omissao === 'string' && OMISSOES.includes(o.omissao)) r.omissao = o.omissao as OmissaoVista;
  if (o.ecras && typeof o.ecras === 'object') {
    const ecras: Record<string, ModoVista> = {};
    for (const [k, m] of Object.entries(o.ecras as Record<string, unknown>)) if (typeof m === 'string' && MODOS.includes(m)) ecras[k] = m as ModoVista;
    r.ecras = ecras;
  }
  return r;
}

/**
 * Identificador do ecrã para a preferência: o caminho actual (números → `:id`, para os detalhes não criarem uma
 * entrada por registo) e, se houver várias listas no mesmo ecrã, um sufixo `#id`.
 */
export function chaveEcraVista(id?: string): string {
  const caminho = typeof window !== 'undefined' ? window.location.pathname : '/';
  const base = caminho.replace(/\/\d+(?=\/|$)/g, '/:id').replace(/\/+$/, '') || '/';
  return id ? `${base}#${id}` : base;
}

/** Repõe o estado local (apoio a testes). */
export function reporPreferenciasVistaLocal(): void {
  try {
    window.localStorage.removeItem(CHAVE_LOCAL_VISTAS);
  } catch {
    /* sem armazenamento */
  }
  estado = { valor: {}, pendente: false };
  ouvintes.forEach((f) => f());
}

/** Volta a ler o estado guardado no navegador (como num novo carregamento da página; apoio a testes). */
export function recarregarPreferenciasVistaLocal(): void {
  estado = lerLocal();
  ouvintes.forEach((f) => f());
}

// Cliente vazio para ecrãs renderizados sem QueryClientProvider (ex.: testes isolados): só o navegador.
let clienteSemProvedor: QueryClient | null = null;

/** Preferências de vista do utilizador (servidor + navegador) com gravação optimista e tolerante a falhas de rede. */
export function usePreferenciasVista(): { valor: PreferenciasVista; gravar: (novo: PreferenciasVista) => void } {
  const contexto = useContext(QueryClientContext);
  const cliente = contexto ?? (clienteSemProvedor ??= new QueryClient());
  const chave = chavePreferencias('interface');
  const consulta = useQuery(
    {
      queryKey: chave,
      queryFn: () => obter<Preferencia[]>(URL_PREFERENCIAS),
      staleTime: 5 * 60_000,
      retry: false,
      enabled: !!contexto,
    },
    cliente,
  );
  const local = useSyncExternalStore(subscrever, () => estado, () => estado);

  const doServidor = useMemo(() => {
    if (!consulta.isSuccess || !Array.isArray(consulta.data)) return undefined;
    return normalizar(consulta.data.find((p) => p?.nome === NOME_PREFERENCIA_VISTAS)?.valor);
  }, [consulta.isSuccess, consulta.data]);

  const enviarServidor = useCallback(
    (novo: PreferenciasVista) => {
      if (!contexto) return;
      enviar('put', `${URL_PREFERENCIAS}/${NOME_PREFERENCIA_VISTAS}`, { valor: novo })
        .then(() => {
          cliente.setQueryData<Preferencia[]>(chave, (l) => [...(Array.isArray(l) ? l : []).filter((p) => p.nome !== NOME_PREFERENCIA_VISTAS), { nome: NOME_PREFERENCIA_VISTAS, valor: novo }]);
          // só limpa o «pendente» se entretanto não houve outra alteração
          if (estado.valor === novo) definirLocal({ valor: novo, pendente: false });
          // uma leitura que estivesse em curso (anterior à gravação) não pode repor o valor antigo
          void cliente.invalidateQueries({ queryKey: chave });
        })
        .catch(() => {
          /* sem ligação: a escolha fica no navegador (pendente) e é reenviada na próxima leitura */
        });
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [contexto, cliente],
  );

  // Sincronização: o servidor manda, salvo se houver uma alteração do posto por enviar.
  useEffect(() => {
    if (!consulta.isSuccess || consulta.dataUpdatedAt < ultimaAlteracaoLocal) return;
    if (estado.pendente) enviarServidor(estado.valor);
    else if (doServidor && JSON.stringify(doServidor) !== JSON.stringify(estado.valor)) definirLocal({ valor: doServidor, pendente: false });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [consulta.dataUpdatedAt, consulta.isSuccess]);

  // O estado do navegador é a fonte do ecrã; o efeito acima mantém-no igual ao do servidor (sem «saltos» entre a
  // gravação e a actualização da cache da consulta).
  const valor = local.valor;

  const gravar = useCallback(
    (novo: PreferenciasVista) => {
      const ecras = novo.ecras ?? {};
      const chaves = Object.keys(ecras);
      // limite de entradas: descarta as mais antigas (ordem de inserção)
      const limitado = chaves.length > MAX_ECRAS ? { ...novo, ecras: Object.fromEntries(chaves.slice(-MAX_ECRAS).map((k) => [k, ecras[k]])) } : novo;
      ultimaAlteracaoLocal = Date.now();
      definirLocal({ valor: limitado, pendente: true });
      // uma leitura em curso (iniciada antes desta alteração) traria o valor antigo: cancela-se; a gravação volta a ler
      if (contexto) void cliente.cancelQueries({ queryKey: chave });
      enviarServidor(limitado);
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [enviarServidor, contexto, cliente],
  );

  return { valor, gravar };
}

export interface OpcoesModoVista {
  /** Omissão própria deste ecrã (antes da automática). */
  omissao?: ModoVista;
}

export interface EstadoModoVista {
  /** Modo efectivo. */
  modo: ModoVista;
  /** Guarda a escolha para este ecrã. */
  definir: (modo: ModoVista) => void;
  /** Omissão global do utilizador. */
  omissaoGlobal: OmissaoVista;
  definirOmissaoGlobal: (omissao: OmissaoVista) => void;
  /** `true` se este ecrã tem uma escolha própria guardada. */
  temEscolhaEcra: boolean;
  /** Apaga a escolha deste ecrã (volta à omissão). */
  reporEcra: () => void;
  /** Chave do ecrã na preferência. */
  ecra: string;
}

/** `true` em telemóvel (largura < 768 px) ou com o «modo responsivo» forçado. */
function useTelemovel(): boolean {
  const { telemovel } = useEcra();
  const [forcado] = useModoResponsivo();
  if (forcado) return true;
  if (!telemovel) return false;
  // confirmação directa da largura (o simulacro do jsdom responde «não» a tudo e não deve passar por telemóvel)
  try {
    return typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 767.98px)').matches;
  } catch {
    return false;
  }
}

/** Modo de vista de uma lista (por utilizador e por ecrã), com a omissão global e a automática. */
export function useModoVista(idVista?: string, opcoes: OpcoesModoVista = {}): EstadoModoVista {
  const { valor, gravar } = usePreferenciasVista();
  const telemovel = useTelemovel();
  const ecra = chaveEcraVista(idVista);
  const escolha = valor.ecras?.[ecra];
  const omissaoGlobal: OmissaoVista = valor.omissao ?? 'automatica';
  const automatica: ModoVista = telemovel ? 'grade' : 'linhas';
  const modo: ModoVista = escolha ?? (omissaoGlobal !== 'automatica' ? omissaoGlobal : opcoes.omissao ?? automatica);

  const definir = useCallback((m: ModoVista) => gravar({ ...estado.valor, ecras: { ...(estado.valor.ecras ?? {}), [ecra]: m } }), [gravar, ecra]);
  const definirOmissaoGlobal = useCallback((o: OmissaoVista) => gravar({ ...estado.valor, omissao: o }), [gravar]);
  const reporEcra = useCallback(() => {
    const ecras = { ...(estado.valor.ecras ?? {}) };
    delete ecras[ecra];
    gravar({ ...estado.valor, ecras });
  }, [gravar, ecra]);

  return { modo, definir, omissaoGlobal, definirOmissaoGlobal, temEscolhaEcra: !!escolha, reporEcra, ecra };
}
