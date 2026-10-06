import { useCallback, useMemo } from 'react';
import type { EcraMenu, ModuloMenu } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import { usePreferencia } from './usePreferencias';

/** Um favorito: ecrã do catálogo (legado: estrela no cartão do módulo / clique direito no menu — toggleFavorite). */
export interface Favorito {
  modulo: string;
  ecra: string;
}

export interface FavoritoResolvido extends Favorito {
  nome: string;
  nomeModulo: string;
  rota: string;
}

const PADRAO: Favorito[] = [];

/** Só os favoritos que o utilizador ainda vê no menu da empresa activa (o servidor volta a validar cada pedido). */
export function resolverFavoritos(favoritos: Favorito[], menu: ModuloMenu[]): FavoritoResolvido[] {
  return favoritos.flatMap((f) => {
    const m = menu.find((x) => x.id === f.modulo);
    const e: EcraMenu | undefined = m?.ecras.find((x) => x.id === f.ecra);
    return m && e ? [{ ...f, nome: e.nome, nomeModulo: m.nome, rota: `/m/${m.id}/${e.id}` }] : [];
  });
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
  return { favoritos: resolvidos, eFavorito, alternar, reordenar, aCarregar };
}
