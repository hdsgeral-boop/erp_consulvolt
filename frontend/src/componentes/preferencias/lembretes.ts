import { useCallback, useMemo } from 'react';
import { useSessao } from '@/sessao/SessaoContexto';
import { usePreferencia } from './usePreferencias';

/** Um lembrete pessoal do utilizador (página Início, caixa «Lembretes»), guardado como preferência no servidor. */
export interface Lembrete {
  id: string;
  texto: string;
  feito: boolean;
  fixado: boolean;
  criado_em: string;
}

const PADRAO: Lembrete[] = [];

function novoId(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/** Um lembrete novo a partir do texto (aparado; vazio é ignorado por quem chama). */
export function novoLembrete(texto: string): Lembrete {
  return { id: novoId(), texto: texto.trim(), feito: false, fixado: false, criado_em: new Date().toISOString() };
}

/** Fixados primeiro, depois os restantes; dentro de cada grupo, os mais recentes primeiro. */
export function ordenarLembretes(lista: Lembrete[]): Lembrete[] {
  return [...lista].sort((a, b) => (a.fixado === b.fixado ? b.criado_em.localeCompare(a.criado_em) : a.fixado ? -1 : 1));
}

export function alternarFeitoNaLista(lista: Lembrete[], id: string): Lembrete[] {
  return lista.map((l) => (l.id === id ? { ...l, feito: !l.feito } : l));
}

export function alternarFixadoNaLista(lista: Lembrete[], id: string): Lembrete[] {
  return lista.map((l) => (l.id === id ? { ...l, fixado: !l.fixado } : l));
}

export function removerDaLista(lista: Lembrete[], id: string): Lembrete[] {
  return lista.filter((l) => l.id !== id);
}

/** Lembretes do utilizador (guardados no servidor, iguais em todos os postos — mesmo mecanismo dos favoritos). */
export function useLembretes() {
  const { estado } = useSessao();
  const [lista, definir, aCarregar] = usePreferencia<Lembrete[]>('lembretes', 'lista', PADRAO, estado === 'autenticado');
  const ordenados = useMemo(() => ordenarLembretes(lista), [lista]);
  const porFazer = useMemo(() => lista.filter((l) => !l.feito).length, [lista]);
  const adicionar = useCallback(
    (texto: string) => {
      const t = texto.trim();
      if (t) definir([...lista, novoLembrete(t)]);
    },
    [definir, lista],
  );
  const alternarFeito = useCallback((id: string) => definir(alternarFeitoNaLista(lista, id)), [definir, lista]);
  const alternarFixado = useCallback((id: string) => definir(alternarFixadoNaLista(lista, id)), [definir, lista]);
  const remover = useCallback((id: string) => definir(removerDaLista(lista, id)), [definir, lista]);
  return { lista: ordenados, porFazer, adicionar, alternarFeito, alternarFixado, remover, aCarregar };
}
