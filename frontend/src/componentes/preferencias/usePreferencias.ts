import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useMemo } from 'react';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';

/**
 * Preferências do utilizador guardadas no servidor (M-19, GET/PUT/DELETE /api/sistema/preferencias/{tipo}/{nome}):
 * favoritos, ordem dos módulos no Início, visões guardadas da Análise Dinâmica e preferências de interface. No legado
 * ficavam no localStorage do navegador (perdiam-se ao mudar de posto). Cada utilizador só vê as suas.
 */
export type TipoPreferencia = 'favoritos' | 'ordem_modulos' | 'visoes_cubo' | 'interface';

export interface Preferencia<T = unknown> {
  nome: string;
  valor: T;
  atualizado_em?: string | null;
}

export const chavePreferencias = (tipo: TipoPreferencia) => ['sistema', 'preferencias', tipo];

/** Todas as preferências de um tipo, com gravar/eliminar (actualização optimista da cache). */
export function usePreferencias<T = unknown>(tipo: TipoPreferencia, activo = true) {
  const cliente = useQueryClient();
  const chave = chavePreferencias(tipo);
  const consulta = useQuery({
    queryKey: chave,
    queryFn: () => obter<Preferencia<T>[]>(`/sistema/preferencias/${tipo}`),
    staleTime: 5 * 60_000,
    enabled: activo,
    retry: false,
  });
  const gravar = useMutation({
    mutationFn: ({ nome, valor }: { nome: string; valor: T }) => enviar('put', `/sistema/preferencias/${tipo}/${encodeURIComponent(nome)}`, { valor }),
    onMutate: async ({ nome, valor }) => {
      await cliente.cancelQueries({ queryKey: chave });
      const antes = cliente.getQueryData<Preferencia<T>[]>(chave);
      cliente.setQueryData<Preferencia<T>[]>(chave, (l = []) => [...l.filter((p) => p.nome !== nome), { nome, valor }]);
      return { antes };
    },
    onError: (e, _v, ctx) => {
      if (ctx?.antes) cliente.setQueryData(chave, ctx.antes);
      notificarErro(e, 'Não foi possível guardar a preferência');
    },
  });
  const eliminar = useMutation({
    mutationFn: (nome: string) => enviar('delete', `/sistema/preferencias/${tipo}/${encodeURIComponent(nome)}`),
    onMutate: async (nome) => {
      await cliente.cancelQueries({ queryKey: chave });
      const antes = cliente.getQueryData<Preferencia<T>[]>(chave);
      cliente.setQueryData<Preferencia<T>[]>(chave, (l = []) => l.filter((p) => p.nome !== nome));
      return { antes };
    },
    onError: (e, _v, ctx) => {
      if (ctx?.antes) cliente.setQueryData(chave, ctx.antes);
      notificarErro(e, 'Não foi possível eliminar a preferência');
    },
  });
  return { lista: consulta.data ?? [], aCarregar: consulta.isLoading, gravar: gravar.mutate, eliminar: eliminar.mutate, consulta };
}

/** Uma preferência (tipo + nome) com valor por omissão. */
export function usePreferencia<T>(tipo: TipoPreferencia, nome: string, padrao: T, activo = true): [T, (valor: T) => void, boolean] {
  const { lista, gravar, aCarregar } = usePreferencias<T>(tipo, activo);
  const valor = useMemo(() => lista.find((p) => p.nome === nome)?.valor ?? padrao, [lista, nome, padrao]);
  const definir = useCallback((v: T) => gravar({ nome, valor: v }), [gravar, nome]);
  return [valor, definir, aCarregar];
}
