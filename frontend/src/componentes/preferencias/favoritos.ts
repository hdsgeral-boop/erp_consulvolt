import { useCallback, useMemo } from 'react';
import type { EcraMenu, ModuloMenu } from '@/api/tipos';
import { nomeModulo, rotuloEcra } from '@/layout/menuLegado';
import { useSessao } from '@/sessao/SessaoContexto';
import { usePreferencia } from './usePreferencias';

/** Um favorito: ecrã do catálogo (legado: estrela no cartão do módulo / clique direito no menu — toggleFavorite). */
export interface Favorito {
  modulo: string;
  ecra: string;
  /** Rótulo próprio dado pelo utilizador (Versão Actual: renomear favorito); vazio = nome do ecrã. */
  rotulo?: string;
}

export interface FavoritoResolvido extends Favorito {
  /** rótulo a mostrar (o próprio, se houver; senão o nome do ecrã — ver nomeOriginalFavorito) */
  nome: string;
  nomeModulo: string;
  rota: string;
}

/** Comprimento máximo do rótulo próprio de um favorito. */
export const MAX_ROTULO_FAVORITO = 60;

/** Rótulo próprio normalizado: sem espaços a mais, cortado ao máximo; vazio ou igual ao nome do ecrã → sem rótulo. */
export function normalizarRotulo(rotulo: string | null | undefined, nomeOriginal?: string): string | undefined {
  const r = (rotulo ?? '').replace(/\s+/g, ' ').trim().slice(0, MAX_ROTULO_FAVORITO);
  return r && r !== nomeOriginal ? r : undefined;
}

/** Lista com o rótulo de um favorito alterado (sem rótulo volta ao nome do ecrã); os outros ficam como estão. */
export function renomearNaLista(lista: Favorito[], modulo: string, ecra: string, rotulo: string | null | undefined, nomeOriginal?: string): Favorito[] {
  const novo = normalizarRotulo(rotulo, nomeOriginal);
  return lista.map((f) => {
    if (f.modulo !== modulo || f.ecra !== ecra) return f;
    const { rotulo: _antigo, ...resto } = f;
    return novo ? { ...resto, rotulo: novo } : resto;
  });
}

const PADRAO: Favorito[] = [];

/** Só os favoritos que o utilizador ainda vê no menu da empresa activa (o servidor volta a validar cada pedido). */
export function resolverFavoritos(favoritos: Favorito[], menu: ModuloMenu[]): FavoritoResolvido[] {
  return favoritos.flatMap((f) => {
    const m = menu.find((x) => x.id === f.modulo);
    const e: EcraMenu | undefined = m?.ecras.find((x) => x.id === f.ecra);
    if (!m || !e) return [];
    return [{ ...f, nome: normalizarRotulo(f.rotulo) ?? rotuloEcra(m.id, e.id, e.nome), nomeModulo: nomeModulo(m.id, m.nome), rota: `/m/${m.id}/${e.id}` }];
  });
}

/** Nome do ecrã de um favorito no menu (sem o rótulo próprio); vazio se o ecrã já não está no menu. */
export function nomeOriginalFavorito(modulo: string, ecra: string, menu: ModuloMenu[]): string {
  const e = menu.find((x) => x.id === modulo)?.ecras.find((x) => x.id === ecra);
  return e ? rotuloEcra(modulo, ecra, e.nome) : '';
}

/** Favoritos do utilizador (guardados no servidor, iguais em todos os postos). */
export function useFavoritos() {
  const { menu, estado } = useSessao();
  const [lista, definir, aCarregar] = usePreferencia<Favorito[]>('favoritos', 'lista', PADRAO, estado === 'autenticado');
  const resolvidos = useMemo(() => resolverFavoritos(lista, menu), [lista, menu]);
  const eFavorito = useCallback((modulo: string, ecra: string) => lista.some((f) => f.modulo === modulo && f.ecra === ecra), [lista]);
  const alternar = useCallback(
    (modulo: string, ecra: string) =>
      definir(eFavorito(modulo, ecra) ? lista.filter((f) => !(f.modulo === modulo && f.ecra === ecra)) : [...lista, { modulo, ecra }].slice(-50)),
    [definir, eFavorito, lista],
  );
  const reordenar = useCallback((nova: Favorito[]) => definir(nova), [definir]);
  /** Dá (ou retira, com texto vazio) um rótulo próprio ao favorito; guardado com a preferência no servidor. */
  const renomear = useCallback(
    (modulo: string, ecra: string, rotulo: string | null) => {
      definir(renomearNaLista(lista, modulo, ecra, rotulo, nomeOriginalFavorito(modulo, ecra, menu)));
    },
    [definir, lista, menu],
  );
  return { favoritos: resolvidos, eFavorito, alternar, reordenar, renomear, aCarregar };
}
