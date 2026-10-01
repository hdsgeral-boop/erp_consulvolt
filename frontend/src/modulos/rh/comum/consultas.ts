import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { message } from 'antd';
import { enviar, obter, obterPagina } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import type { Banco, Cadastro, Colaborador, Infotipo, PeriodoSalarial } from '../api';
import { chaveMesAno } from './regras';

/** Todas as páginas de /rh/colaboradores (máx. 500 por página no servidor), para nomes e seletores. */
async function todosColaboradores(): Promise<Colaborador[]> {
  const lista: Colaborador[] = [];
  let pagina = 1;
  for (;;) {
    const r = await obterPagina<Colaborador>('/rh/colaboradores', { por_pagina: 500, pagina });
    lista.push(...r.itens);
    if (pagina >= r.paginacao.ultima_pagina) break;
    pagina += 1;
  }
  return lista;
}

const CINCO_MIN = 5 * 60_000;

export function useColaboradores() {
  const q = useQuery({ queryKey: ['rh', 'colaboradores', 'todos'], queryFn: todosColaboradores, staleTime: CINCO_MIN });
  const mapa = new Map((q.data ?? []).map((c) => [c.id, c]));
  return { ...q, lista: q.data ?? [], mapa, nome: (id: number | null | undefined) => (id ? mapa.get(id)?.nome_completo ?? `#${id}` : '—') };
}

export function useInfotipos() {
  const q = useQuery({ queryKey: ['rh', 'infotipos'], queryFn: () => obter<Infotipo[]>('/rh/infotipos'), staleTime: CINCO_MIN });
  const mapa = new Map((q.data ?? []).map((i) => [i.id, i]));
  return { ...q, lista: q.data ?? [], mapa, nome: (id: number | null | undefined) => (id ? mapa.get(id)?.nome ?? `#${id}` : '—') };
}

export function useCargos() {
  const q = useQuery({ queryKey: ['rh', 'cargos'], queryFn: () => obter<Cadastro[]>('/rh/cargos'), staleTime: CINCO_MIN });
  const mapa = new Map((q.data ?? []).map((i) => [i.id, i]));
  return { ...q, lista: q.data ?? [], nome: (id: number | null | undefined) => (id ? mapa.get(id)?.nome ?? `#${id}` : '—') };
}

export function useTiposOrganizacao() {
  const q = useQuery({ queryKey: ['rh', 'tipos-organizacao'], queryFn: () => obter<Cadastro[]>('/rh/tipos-organizacao'), staleTime: CINCO_MIN });
  const mapa = new Map((q.data ?? []).map((i) => [i.id, i]));
  return { ...q, lista: q.data ?? [], nome: (id: number | null | undefined) => (id ? mapa.get(id)?.nome ?? `#${id}` : '—') };
}

export function useBancos(activo = true) {
  return useQuery({ queryKey: ['rh', 'bancos'], queryFn: () => obter<Banco[]>('/rh/bancos'), staleTime: CINCO_MIN, enabled: activo });
}

/** Períodos salariais, do mais recente para o mais antigo. */
export function usePeriodosSalariais() {
  return useQuery({
    queryKey: ['rh', 'salarios', 'periodos'],
    queryFn: async () => (await obter<PeriodoSalarial[]>('/rh/salarios/periodos')).sort((a, b) => chaveMesAno(b.mes_ano).localeCompare(chaveMesAno(a.mes_ano))),
  });
}

interface Pedido {
  metodo: 'post' | 'put' | 'delete';
  url: string;
  dados?: unknown;
}

/**
 * Mutação padrão do módulo: envia, mostra a mensagem do servidor, invalida as consultas ['rh', ...] e notifica o erro.
 * `aoConcluir` recebe os dados devolvidos (para fechar modais, navegar, etc.).
 */
export function useAccaoRh<T = unknown>(aoConcluir?: (dados: T, pedido: Pedido) => void) {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: (p: Pedido) => enviar<T>(p.metodo, p.url, p.dados),
    onSuccess: ({ dados, mensagem }, pedido) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['rh'] });
      aoConcluir?.(dados, pedido);
    },
    onError: (e) => notificarErro(e),
  });
}

/** Notifica (uma vez por erro) a falha de uma consulta. */
export function useAvisarErro(erro: unknown, titulo = 'Erro ao carregar os dados') {
  useEffect(() => {
    if (erro) notificarErro(erro, titulo);
  }, [erro, titulo]);
}
