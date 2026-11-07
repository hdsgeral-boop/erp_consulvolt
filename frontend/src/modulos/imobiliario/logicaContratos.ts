import type { EstadoContrato, EstadoRenda } from './apiContratos';

/** Meses de cada periodicidade (os mesmos do servidor). */
export const MESES: Readonly<Record<string, number>> = { MENSAL: 1, BIMESTRAL: 2, TRIMESTRAL: 3, SEMESTRAL: 6, ANUAL: 12 };

/** Cores dos estados (tokens do legado). O estado nunca se distingue só pela cor: mostra-se sempre o texto. */
export const COR_CONTRATO: Readonly<Record<EstadoContrato, string>> = { RASCUNHO: 'gold', ACTIVO: 'green', SUSPENSO: 'orange', TERMINADO: 'default', DENUNCIADO: 'red' };
export const COR_RENDA: Readonly<Record<EstadoRenda, string>> = { PREVISTA: 'default', APROVADA: 'blue', FACTURADA: 'green', PAGA: 'cyan', ANULADA: 'red' };

/** Valor mensal de uma renda (renda ÷ meses da periodicidade), ao cêntimo. */
export const rendaMensal = (renda: number, periodicidade: string): number => Math.round((renda / (MESES[periodicidade] ?? 1)) * 100) / 100;

/** A caução acordada vale, no máximo, seis rendas. */
export const limiteCaucao = (renda: number): number => Math.round(renda * 6 * 100) / 100;

const arred = (v: number): number => Math.floor(v + 0.5 + 1e-9);   // meio para cima, sobre cêntimos inteiros

export interface TaxasRenda { ip_taxa: number; ip_base: number; selo_habitacao: number; selo_outro: number; iva_arrendamento: number; iva_alojamento: number }
export interface DadosRenda {
  renda: number; retencao_ip: boolean; isencao_ip: string; selo_encargo: string; finalidade_fiscal: string; encargos?: { valor: number; incluido_na_renda: boolean }[];
}
export interface PrevisaoRenda { base: number; ip: number; selo: number; iva: number; totalFacturar: number; totalReceber: number; custoSenhorio: number }

/**
 * Pré-visualização da renda de um período (a mesma fórmula do servidor, em cêntimos inteiros): base = renda + encargos a acrescer;
 * IP = base × «incide sobre» % × taxa %; Selo = base × 0,1 % (habitação) ou 0,4 %; IVA só no alojamento; o IP retido sai do que se
 * recebe. É só para o formulário: quem calcula e grava é o servidor.
 */
export function previsaoRenda(c: DadosRenda, t: TaxasRenda, factor = 1): PrevisaoRenda {
  const cent = (v: number) => arred(v * 100);
  const renda = factor === 1 ? cent(c.renda) : arred(cent(c.renda) * factor);
  const enc = (c.encargos ?? []).filter((e) => !e.incluido_na_renda).reduce((s, e) => s + (factor === 1 ? cent(e.valor) : arred(cent(e.valor) * factor)), 0);
  const base = renda + enc;
  const ipBase = arred((base * t.ip_base) / 100);
  let ip = arred((ipBase * t.ip_taxa) / 100);
  if (c.isencao_ip === 'TOTAL') ip = 0;
  else if (c.isencao_ip === 'METADE') ip = arred(ip / 2);
  const selo = c.selo_encargo === 'ISENTO' ? 0 : arred((base * (c.finalidade_fiscal === 'HABITACAO' ? t.selo_habitacao : t.selo_outro)) / 100);
  const iva = arred((base * (c.finalidade_fiscal === 'ALOJAMENTO' ? t.iva_alojamento : t.iva_arrendamento)) / 100);
  const totalFacturar = base + iva;
  const retido = c.retencao_ip ? ip : 0;
  const custo = (c.selo_encargo === 'SENHORIO' ? selo : 0) + (c.retencao_ip ? 0 : ip);
  const k = (v: number) => v / 100;

  return { base: k(base), ip: k(ip), selo: k(selo), iva: k(iva), totalFacturar: k(totalFacturar), totalReceber: k(totalFacturar - retido), custoSenhorio: k(custo) };
}

const dias = (a: string, b: string): number => Math.round((Date.UTC(+b.slice(0, 4), +b.slice(5, 7) - 1, +b.slice(8, 10)) - Date.UTC(+a.slice(0, 4), +a.slice(5, 7) - 1, +a.slice(8, 10))) / 86_400_000);

export const fimDoMes = (periodo: string): string => {
  const d = new Date(Date.UTC(+periodo.slice(0, 4), +periodo.slice(5, 7), 0));
  return d.toISOString().slice(0, 10);
};

export const somarMeses = (periodo: string, n: number): string => {
  const i = +periodo.slice(0, 4) * 12 + (+periodo.slice(5, 7) - 1) + n;
  return `${Math.floor(i / 12)}-${String((i % 12) + 1).padStart(2, '0')}`;
};

/** Dias cobertos pelo contrato no primeiro período e total de dias desse período (o pro-rata do primeiro período). */
export function diasDoPrimeiroPeriodo(inicio: string, periodicidade: string): { cobertos: number; total: number } {
  const periodo = inicio.slice(0, 7);
  const de = `${periodo}-01`;
  const ate = fimDoMes(somarMeses(periodo, (MESES[periodicidade] ?? 1) - 1));
  const total = dias(de, ate) + 1;

  return { cobertos: Math.min(total, dias(inicio, ate) + 1), total };
}

export interface AccoesContrato { editar: boolean; activar: boolean; suspender: boolean; terminar: boolean; renovar: boolean; actualizarRenda: boolean; eliminar: boolean; movimentarCaucao: boolean }

/** O que se pode fazer a um contrato conforme o estado (o servidor tem a última palavra). */
export function accoesDoContrato(estado: EstadoContrato, temTermo: boolean): AccoesContrato {
  const vivo = estado === 'ACTIVO' || estado === 'SUSPENSO';

  return { editar: estado !== 'TERMINADO' && estado !== 'DENUNCIADO', activar: estado === 'RASCUNHO' || estado === 'SUSPENSO', suspender: estado === 'ACTIVO', terminar: vivo,
    renovar: estado === 'ACTIVO' && temTermo, actualizarRenda: estado === 'ACTIVO', eliminar: !vivo, movimentarCaucao: true };
}

/** Uma renda por aprovar (prevista) ou aprovada pode seleccionar-se para as acções em lote. */
export const rendaSeleccionavel = (estado: EstadoRenda): boolean => estado === 'PREVISTA' || estado === 'APROVADA';

/** Chave única de um pedido de movimento da caução: repetir o mesmo pedido não lança duas vezes. */
export function chaveDePedido(): string {
  const c = globalThis.crypto as Crypto | undefined;

  return `cau-${c?.randomUUID ? c.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`}`;
}

export const textoAtraso = (d: number): string => (d > 0 ? `${d} dia${d === 1 ? '' : 's'} de atraso` : '');
