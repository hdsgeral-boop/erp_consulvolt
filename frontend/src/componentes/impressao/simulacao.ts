import { somar } from '@/utilitarios/decimal';
import { formatarKz } from '@/utilitarios/formatacao';
import { esc } from './documento';
import { tabelaHtml } from './tabela';

/**
 * Peças comuns às impressões de simulações e pré-visualizações (nada gravado): a marca «Simulação» no topo e a tabela
 * de um lançamento contabilístico ainda por gravar (débito/crédito, totais e equilíbrio). Os valores vêm sempre do
 * servidor; aqui só se formata.
 */

export const CSS_SIMULACAO_COMUM = `
.imp-marca-simulacao { border: 0.4mm dashed #b45309; color: #92400e; padding: 1.2mm 2.5mm; font-size: 8pt; margin: 0 0 3mm; break-inside: avoid; }
.imp-equilibrio { font-size: 8pt; margin: -2mm 0 3mm; }
.imp-equilibrio.desequilibrado { color: #b42318; font-weight: 700; }
`;

/** Faixa «SIMULAÇÃO — …» (texto escapado). */
export function marcaSimulacao(texto = 'documento sem valor contabilístico: pré-visualização calculada no servidor; nada foi gravado.', titulo = 'SIMULAÇÃO'): string {
  return `<div class="imp-marca-simulacao"><b>${esc(titulo)}</b> — ${esc(texto)}</div>`;
}

export interface LinhaLancamentoImpressao {
  conta: string;
  descricao?: string | null;
  unidade?: string | null;
  centro?: string | null;
  tipo_dc: 'D' | 'C';
  valor: string | number;
}

/** Tabela de um lançamento por gravar: conta, descrição, UN/CC (se houver), débito e crédito, totais e equilíbrio. */
export function tabelaLancamentoHtml(linhas: LinhaLancamentoImpressao[], legenda = 'Linhas do lançamento'): string {
  const temDesc = linhas.some((l) => l.descricao);
  const temAnalitica = linhas.some((l) => l.unidade || l.centro);
  const debito = somar(linhas.filter((l) => l.tipo_dc === 'D').map((l) => l.valor));
  const credito = somar(linhas.filter((l) => l.tipo_dc === 'C').map((l) => l.valor));
  const tabela = tabelaHtml<LinhaLancamentoImpressao>({
    legenda,
    linhas,
    totais: 'Totais',
    colunas: [
      { titulo: 'Conta', valor: (l) => l.conta },
      ...(temDesc ? [{ titulo: 'Descrição', valor: (l: LinhaLancamentoImpressao) => l.descricao ?? '', quebrar: true }] : []),
      ...(temAnalitica
        ? [
            { titulo: 'UN', valor: (l: LinhaLancamentoImpressao) => l.unidade || '—' },
            { titulo: 'CC', valor: (l: LinhaLancamentoImpressao) => l.centro || '—' },
          ]
        : []),
      { titulo: 'Débito (Kz)', valor: (l) => (l.tipo_dc === 'D' ? l.valor : ''), formato: 'moeda', somar: true },
      { titulo: 'Crédito (Kz)', valor: (l) => (l.tipo_dc === 'C' ? l.valor : ''), formato: 'moeda', somar: true },
    ],
  });
  const equilibrado = debito === credito;
  return `${tabela}<div class="imp-equilibrio${equilibrado ? '' : ' desequilibrado'}">${equilibrado ? 'Lançamento equilibrado (débito = crédito).' : `Lançamento desequilibrado: débito ${esc(formatarKz(debito))} Kz ≠ crédito ${esc(formatarKz(credito))} Kz.`}</div>`;
}
