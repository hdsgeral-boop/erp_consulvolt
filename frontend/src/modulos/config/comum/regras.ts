/** Regras puras dos ecrãs de Configurações (testáveis): auditoria, colagem de tabelas, manutenção de dados e moedas. */

export interface Diferenca {
  campo: string;
  antes: string;
  depois: string;
  alterado: boolean;
}

function texto(v: unknown): string {
  if (v === undefined) return '';
  if (v === null) return '—';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

/** Campos de um registo de auditoria lado a lado (antes/depois), alterados primeiro. */
export function diferencas(antes: Record<string, unknown> | null | undefined, depois: Record<string, unknown> | null | undefined): Diferenca[] {
  const campos = [...new Set([...Object.keys(antes ?? {}), ...Object.keys(depois ?? {})])];
  return campos
    .map((campo) => {
      const a = antes ? texto(antes[campo]) : '';
      const d = depois ? texto(depois[campo]) : '';
      return { campo, antes: a, depois: d, alterado: !!antes && !!depois && a !== d };
    })
    .sort((x, y) => Number(y.alterado) - Number(x.alterado) || x.campo.localeCompare(y.campo));
}

/** Ficheiros que seguem para o servidor em multipart (lidos lá com PhpSpreadsheet); CSV/TXT continuam a ser lidos no navegador. */
export function eFolhaExcel(nome: string): boolean {
  return /\.(xlsx|xls)$/i.test(nome.trim());
}

/**
 * Lê uma tabela colada do Excel (separada por tabulações) ou um CSV (; ou ,) com cabeçalho na 1.ª linha e devolve objectos
 * {cabeçalho: valor}. Linhas vazias são ignoradas; aspas duplas delimitam campos.
 */
export function lerTabelaColada(conteudo: string): Record<string, string>[] {
  const linhas = conteudo.replace(/^﻿/, '').split(/\r?\n/).filter((l) => l.trim() !== '');
  if (linhas.length < 2) return [];
  const sep = linhas[0].includes('\t') ? '\t' : linhas[0].includes(';') ? ';' : ',';
  const partir = (l: string) => {
    const r: string[] = [];
    let actual = '';
    let aspas = false;
    for (let i = 0; i < l.length; i++) {
      const c = l[i];
      if (c === '"') {
        if (aspas && l[i + 1] === '"') {
          actual += '"';
          i++;
        } else aspas = !aspas;
      } else if (c === sep && !aspas) {
        r.push(actual.trim());
        actual = '';
      } else actual += c;
    }
    r.push(actual.trim());
    return r;
  };
  const cabecalho = partir(linhas[0]);
  return linhas.slice(1).map((l) => {
    const v = partir(l);
    return Object.fromEntries(cabecalho.map((c, i) => [c, v[i] ?? '']));
  });
}

export type EstadoPedido = 'PENDENTE' | 'APROVADO' | 'EXECUTADO' | 'REJEITADO' | 'CANCELADO' | 'EXPIRADO';

export interface PedidoResumo {
  estado: EstadoPedido | string;
  pedido_por: { id: number; nome_utilizador: string } | null;
  aprovado_por: { id: number; nome_utilizador: string } | null;
  ambito?: string;
  empresa_id?: number | null;
}

/**
 * Acções visíveis num pedido de manutenção (aprovação dupla). O servidor volta a validar tudo (inclui ser administrador para
 * aprovar e ter a empresa do pedido activa para executar).
 */
export function accoesPedido(p: PedidoResumo, eu: { id: number; superAdmin: boolean }): { aprovar: boolean; rejeitar: boolean; executar: boolean; cancelar: boolean } {
  const souPedinte = p.pedido_por?.id === eu.id;
  const souAprovador = p.aprovado_por?.id === eu.id;
  const pendente = p.estado === 'PENDENTE';
  const aprovado = p.estado === 'APROVADO';
  return {
    aprovar: pendente && !souPedinte,
    rejeitar: pendente && !souPedinte,
    executar: aprovado && (souPedinte || souAprovador || eu.superAdmin),
    cancelar: (pendente || aprovado) && (souPedinte || eu.superAdmin),
  };
}

/** Texto que o servidor exige para executar um pedido. */
export const textoConfirmacao = (id: number) => `CONFIRMO #${id}`;

/** Variação percentual entre uma taxa nova e a anterior (alerta do BAI); null sem base. */
export function variacaoTaxa(nova: number | null | undefined, anterior: number | null | undefined): number | null {
  if (!nova || !anterior) return null;
  return Math.round(((nova - anterior) / anterior) * 10000) / 100;
}
