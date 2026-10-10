/**
 * Tabela «Resultado anual por cenário» do sistema anterior (orcamento_planeamento_ui.js:518-549): por natureza, o total
 * anual do orçamento base e de cada cenário, o resultado (ou fluxo de caixa) e a margem; com «Mostrar todas as rubricas»,
 * também cada rubrica. Somas ao cêntimo a partir das linhas calculadas no servidor (/orcamento/cenarios/{id}).
 */

import { diferenca, somaValores } from './regras';
import type { CalculoCenario, NaturezaRubrica, TipoOrcamento } from './tipos';
import { NOME_NATUREZA, naturezas } from './controlo';

export interface LinhaResultadoCenario {
  chave: string;
  tipo: 'grupo' | 'rubrica' | 'total' | 'resultado' | 'margem';
  rotulo: string;
  codigo?: string;
  natureza?: NaturezaRubrica;
  /** valores[0] = base; valores[i] = cenário i (null na linha de grupo) */
  valores: (number | null)[];
}

export function resultadoPorCenario(calculos: CalculoCenario[], tipo: TipoOrcamento | string, detalhe = false): LinhaResultadoCenario[] {
  const [entrada, saida] = naturezas(tipo);
  const tes = tipo === 'TESOURARIA';
  // rubricas pela ordem do primeiro cálculo (todas partilham o orçamento base)
  const rubricas = calculos[0]?.linhas ?? [];
  const valorDe = (i: number, rid: number): number => {
    if (i === 0) return somaValores([rubricas.find((l) => l.rubrica_id === rid)?.base]);
    return somaValores([calculos[i - 1].linhas.find((l) => l.rubrica_id === rid)?.cenario]);
  };
  const colunas = calculos.length + 1;
  const totalNat = (n: NaturezaRubrica) => Array.from({ length: colunas }, (_, i) => somaValores(rubricas.filter((r) => r.natureza === n).map((r) => valorDe(i, r.rubrica_id))));
  const linhas: LinhaResultadoCenario[] = [];
  const totais: Record<string, number[]> = {};
  for (const n of [entrada, saida]) {
    if (detalhe) {
      linhas.push({ chave: `g-${n}`, tipo: 'grupo', rotulo: NOME_NATUREZA[n], valores: Array(colunas).fill(null) });
      for (const r of rubricas.filter((x) => x.natureza === n)) {
        linhas.push({ chave: `r-${r.rubrica_id}`, tipo: 'rubrica', rotulo: r.nome, codigo: r.codigo, natureza: n, valores: Array.from({ length: colunas }, (_, i) => valorDe(i, r.rubrica_id)) });
      }
    }
    totais[n] = totalNat(n);
    linhas.push({ chave: `t-${n}`, tipo: 'total', rotulo: `Total ${NOME_NATUREZA[n].toLowerCase()}`, natureza: n, valores: totais[n] });
  }
  const res = Array.from({ length: colunas }, (_, i) => diferenca(totais[entrada][i], totais[saida][i]));
  linhas.push({ chave: 'res', tipo: 'resultado', rotulo: tes ? 'Fluxo de caixa' : 'Resultado', natureza: entrada, valores: res });
  if (!tes) {
    linhas.push({ chave: 'margem', tipo: 'margem', rotulo: 'Margem (resultado / proveitos)', valores: res.map((v, i) => (totais[entrada][i] ? (v / totais[entrada][i]) * 100 : null)) });
  }
  return linhas;
}

/** Variação face à base: valor e percentagem (null sem base); favorável se acima nas entradas e abaixo nas saídas. */
export function variacaoCenario(v: number, base: number, entrada: boolean): { d: number; pct: number | null; favoravel: boolean } | null {
  const d = diferenca(v, base);
  if (Math.abs(d) < 0.01) return null;
  return { d, pct: base ? (d / Math.abs(base)) * 100 : null, favoravel: entrada ? d >= 0 : d <= 0 };
}
