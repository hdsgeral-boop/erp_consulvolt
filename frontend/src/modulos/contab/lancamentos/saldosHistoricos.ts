/**
 * Saldos históricos de um exercício sem lançamentos detalhados (lacuna A-06; legado «Importar Histórico»,
 * js/ui_lancamentos.js:1186-1459): valores por nota DEMO (Balanço/DR) e por nota de fluxo de caixa, usados como
 * comparativo do ano seguinte. Gravar substitui todo o histórico do ano; o resultado líquido é calculado.
 */
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';

/** GET /contabilidade/saldos-historicos/{ano} (ServicoSaldosHistoricos::obter). */
export interface NotaHistorico {
  codigo: string;
  descricao: string | null;
  valor: string | null;
}

export interface SaldosHistoricos {
  ano: number;
  encerrado: boolean;
  demo: NotaHistorico[];
  fluxo: NotaHistorico[];
  res_liq: string | null;
}

/** POST /contabilidade/saldos-historicos/importar — só lê o ficheiro (não grava). */
export interface LeituraHistorico {
  demo: Record<string, string>;
  fluxo: Record<string, string>;
  ignoradas: number;
}

export type Valores = Record<string, number | null | undefined>;

/** Notas que entram no resultado líquido (js/ui_lancamentos.js:1260-1266; a 34 entra com os financeiros/extraordinários). */
export const PROVEITOS = ['22', '23', '24', '25', '26'];
export const CUSTOS = ['27', '28', '29', '30'];
export const FINANCEIROS = ['31', '32', '33', '34'];
export const IMPOSTO = '35';

const soma = (v: Valores, codigos: string[]) => codigos.reduce((s, c) => s + paraCentimos(v[c] ?? 0), 0);

/** Resultado líquido = (Σ22..26 − Σ27..30) + Σ31..34 − 35, em cêntimos exactos (o servidor recalcula e grava). */
export function calcularResultadoLiquido(demo: Valores): string {
  return deCentimos(soma(demo, PROVEITOS) - soma(demo, CUSTOS) + soma(demo, FINANCEIROS) - paraCentimos(demo[IMPOSTO] ?? 0));
}

export function valoresDe(notas: NotaHistorico[]): Valores {
  return Object.fromEntries(notas.filter((n) => n.codigo !== 'res_liq').map((n) => [n.codigo, n.valor === null || n.valor === '' ? null : Number(n.valor)]));
}

/** Corpo do PUT: só os valores preenchidos (o servidor apaga o resto do ano) e nunca o res_liq (é calculado). */
export function corpoGravacao(demo: Valores, fluxo: Valores): { demo: Record<string, number>; fluxo: Record<string, number> } {
  const limpar = (v: Valores) =>
    Object.fromEntries(Object.entries(v).filter(([c, x]) => c !== 'res_liq' && x !== null && x !== undefined && Number.isFinite(x)).map(([c, x]) => [c, Math.round((x as number) * 100) / 100]));
  return { demo: limpar(demo), fluxo: limpar(fluxo) };
}

/**
 * Junta o que foi lido do Excel aos valores do ecrã (só as notas que existem; o legado também ignorava as outras).
 * Devolve os códigos do ficheiro que não correspondem a nenhuma nota, para avisar.
 */
export function aplicarLeitura(actual: { demo: Valores; fluxo: Valores }, lido: LeituraHistorico): { demo: Valores; fluxo: Valores; desconhecidos: string[]; aplicados: number } {
  const desconhecidos: string[] = [];
  let aplicados = 0;
  const juntar = (base: Valores, novos: Record<string, string>, prefixo: string) => {
    const r: Valores = { ...base };
    const porMinusculas = new Map(Object.keys(base).map((k) => [k.toLowerCase(), k]));
    for (const [codigo, valor] of Object.entries(novos)) {
      const chave = codigo in base ? codigo : porMinusculas.get(codigo.toLowerCase());
      if (!chave || chave === 'res_liq') {
        if (codigo !== 'res_liq') desconhecidos.push(`${prefixo} ${codigo}`);
        continue;
      }
      r[chave] = Number(valor);
      aplicados++;
    }
    return r;
  };
  return { demo: juntar(actual.demo, lido.demo, 'DEMO'), fluxo: juntar(actual.fluxo, lido.fluxo, 'FLUXO'), desconhecidos, aplicados };
}
