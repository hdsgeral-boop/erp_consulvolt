import { valorPorExtenso } from '@/modulos/vendas/impressao/documentoComercial';
import { somar } from '@/utilitarios/decimal';
import { formatarKz } from '@/utilitarios/formatacao';
import type { BeneficiarioCarta, CartaPagamento, EmpresaCarta } from './api';

/**
 * Regras puras das cartas de pagamento (legado novo js/cartas_pagamento.js): datas e mês por extenso, variáveis do texto,
 * selecção inicial dos beneficiários e totais. Os valores e as contas vêm sempre do servidor.
 */

export const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

/** Contas que emitem cartas: 42 (depósitos a prazo) e 43 (depósitos à ordem). A caixa paga-se em numerário. */
export const PREFIXOS_BANCARIOS = ['42', '43'];

export function eContaBancaria(codigo: string | null | undefined): boolean {
  const c = String(codigo ?? '').trim();
  return PREFIXOS_BANCARIOS.some((p) => c.startsWith(p));
}

/** «03/2026» (RH) ou «2026-03» → «Março de 2026». */
export function mesPorExtenso(mesAno: string | null | undefined): string {
  const v = String(mesAno ?? '');
  const a = /^(\d{2})\/(\d{4})$/.exec(v);
  const b = /^(\d{4})-(\d{2})/.exec(v);
  const [mes, ano] = a ? [Number(a[1]), a[2]] : b ? [Number(b[2]), b[1]] : [0, ''];
  return mes >= 1 && mes <= 12 ? `${MESES[mes - 1]} de ${ano}` : v;
}

/** «2026-10-15» → «15/10/2026». */
export function dataCurta(iso: string | null | undefined): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso ?? ''));
  return m ? `${m[3]}/${m[2]}/${m[1]}` : String(iso ?? '');
}

/** «2026-10-15» → «15 de Outubro de 2026». */
export function dataPorExtenso(iso: string | null | undefined): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso ?? ''));
  return m ? `${Number(m[3])} de ${MESES[Number(m[2]) - 1]} de ${m[1]}` : String(iso ?? '');
}

/** Valor com a moeda: Kz para AOA, o código para as outras. */
export function valorMoeda(valor: string | number | null | undefined, moeda: string | null | undefined = 'AOA'): string {
  const m = moeda || 'AOA';
  return `${formatarKz(valor)} ${m === 'AOA' ? 'Kz' : m}`;
}

/** IBAN em grupos de 4 (só apresentação). */
export function formatarIban(iban: string | null | undefined): string {
  return String(iban ?? '').replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim();
}

/** Substitui {{VARIAVEL}} pelo valor do contexto; variáveis desconhecidas ficam como estão. Devolve TEXTO (escapa-se na impressão). */
export function aplicarVariaveis(texto: string | null | undefined, ctx: Record<string, string | number | null | undefined>): string {
  return String(texto ?? '').replace(/\{\{([A-Z_]+)\}\}/g, (todo, nome: string) => (Object.prototype.hasOwnProperty.call(ctx, nome) ? String(ctx[nome] ?? '') : todo));
}

/** Contexto das variáveis de uma carta emitida (cartas_pagamento.js imprimir). */
export function contextoCarta(c: CartaPagamento, empresa?: EmpresaCarta | null): Record<string, string | number> {
  const moeda = c.codigo_moeda || 'AOA';
  const itens = c.itens ?? [];
  const um = itens.length === 1 ? itens[0] : null;
  return {
    EMPRESA: empresa?.nome ?? '',
    NIF_EMPRESA: empresa?.nif ?? '',
    BANCO: c.banco_nome ?? '',
    CONTA: c.banco_numero_conta || c.codigo_conta_bancaria || '',
    IBAN: formatarIban(c.banco_iban),
    SWIFT: c.banco_swift ?? '',
    TITULAR: c.banco_titular || empresa?.nome || '',
    BENEFICIARIO: um ? (um.nome ?? '') : 'diversos beneficiários (ver quadro)',
    IBAN_BENEFICIARIO: um ? formatarIban(um.iban) : '',
    TOTAL: valorMoeda(c.montante_total, moeda),
    TOTAL_EXTENSO: valorPorExtenso(c.montante_total, moeda),
    MOEDA: moeda,
    N_BENEFICIARIOS: itens.length,
    MES: mesPorExtenso(c.mes_ano),
    NUMERO: c.numero ?? `#${c.id}`,
    DATA: dataCurta(c.data),
    LOCAL: localCarta(c, empresa),
    REFERENCIA: c.referencia_movimento ?? '',
  };
}

export function localCarta(c: Pick<CartaPagamento, 'local'>, empresa?: EmpresaCarta | null): string {
  return c.local || empresa?.municipio || empresa?.provincia || 'Luanda';
}

/** Identificador estável do beneficiário (terceiro ou colaborador). */
export function chaveBeneficiario(b: Pick<BeneficiarioCarta, 'terceiro_id' | 'colaborador_id'>): string {
  return b.colaborador_id != null ? `c${b.colaborador_id}` : `t${b.terceiro_id}`;
}

/** Selecção inicial (como o legado): marcados os que têm conta e ainda não estão noutra carta activa. */
export function seleccaoInicial(beneficiarios: BeneficiarioCarta[]): string[] {
  return beneficiarios.filter((b) => b.iban && !b.ja_na_carta).map(chaveBeneficiario);
}

export function totalSeleccionado(beneficiarios: BeneficiarioCarta[], seleccao: string[]): string {
  const s = new Set(seleccao);
  return somar(beneficiarios.filter((b) => s.has(chaveBeneficiario(b))).map((b) => b.valor));
}

/** Corpo do pedido de emissão: só a escolha (os valores são recalculados no servidor). */
export function corpoBeneficiarios(beneficiarios: BeneficiarioCarta[], seleccao: string[], contas: Record<string, number | undefined> = {}) {
  const s = new Set(seleccao);
  return beneficiarios.filter((b) => s.has(chaveBeneficiario(b))).map((b) =>
    b.colaborador_id != null
      ? { colaborador_id: b.colaborador_id }
      : { terceiro_id: b.terceiro_id, ...(contas[chaveBeneficiario(b)] ? { conta_bancaria_id: contas[chaveBeneficiario(b)] } : {}) },
  );
}
