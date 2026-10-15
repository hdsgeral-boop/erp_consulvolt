import { esc, type PedidoImpressao } from '@/componentes/impressao';
import { ROTULO_TIPO_CARTA, type CartaPagamento } from './api';
import { aplicarVariaveis, contextoCarta, dataCurta, dataPorExtenso, formatarIban, localCarta, valorMoeda } from './regras';

/** CSS da carta (acrescentado ao CSS base do motor). */
export const CSS_CARTA = `
.cp-carta { font-size: 10.5pt; line-height: 1.5; color: #111827; }
.cp-anulada { color: #b91c1c; border: 2px solid #b91c1c; border-radius: 4px; padding: 3px 10px; display: inline-block; font-weight: 700; letter-spacing: 1px; margin-bottom: 8px; }
.cp-numero { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
.cp-numero strong { font-size: 12pt; }
.cp-banco { border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 10px; background: #f8fafc; margin-bottom: 12px; break-inside: avoid; }
.cp-banco h2 { margin: 0 0 4px; font-size: 9.5pt; text-transform: uppercase; letter-spacing: .5px; }
.cp-banco table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
.cp-banco td { border: 0; padding: 1px 6px 1px 0; }
.cp-banco td.r { color: #64748b; white-space: nowrap; width: 1%; }
.cp-meta { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
.cp-assunto { font-weight: 700; margin: 0 0 10px; }
.cp-corpo { white-space: pre-wrap; text-align: justify; margin-bottom: 14px; }
.cp-linhas { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 12px; }
.cp-linhas th { background: #e2e8f0; text-transform: uppercase; font-size: 8pt; letter-spacing: .4px; padding: 4px 5px; border: 1px solid #cbd5e1; text-align: left; }
.cp-linhas td { padding: 3px 5px; border: 1px solid #e2e8f0; }
.cp-linhas td.num, .cp-linhas th.num { text-align: right; white-space: nowrap; }
.cp-linhas td.iban { font-family: monospace; font-size: 8.5pt; }
.cp-linhas tfoot td { font-weight: 700; background: #e2e8f0; border: 1px solid #cbd5e1; }
.cp-assinatura { margin-top: 24px; text-align: center; break-inside: avoid; }
.cp-assinatura .linha { border-top: 1px solid #0f172a; width: 62mm; margin: 36px auto 4px; }
.cp-rodape { margin-top: 14px; border-top: 1px solid #e2e8f0; padding-top: 4px; font-size: 8pt; color: #64748b; text-align: center; }
`;

/**
 * HTML da carta de ordem de pagamento (cartas_pagamento.js imprimir): conta a debitar, destinatário e data, assunto,
 * corpo (com as variáveis substituídas), quadro dos beneficiários, total, assinatura e referência do pagamento.
 * TODO o texto vem escapado: o modelo é texto e nunca injecta HTML/JS. O logótipo e o nome da empresa são postos pelo motor.
 */
export function htmlCarta(c: CartaPagamento): string {
  const empresa = c.empresa ?? null;
  const moeda = c.codigo_moeda || 'AOA';
  const ctx = contextoCarta(c, empresa);
  const assunto = aplicarVariaveis(c.assunto, ctx);
  const corpo = aplicarVariaveis(c.corpo, ctx);
  const linhas = (c.itens ?? []).map((l, i) => `<tr>
      <td class="num">${i + 1}</td>
      <td>${esc(l.nome ?? '')}</td>
      <td>${esc(l.nif ?? '')}</td>
      <td>${esc(l.banco ?? '')}</td>
      <td class="iban">${esc(formatarIban(l.iban))}</td>
      <td class="num">${esc(valorMoeda(l.montante, moeda))}</td>
    </tr>`).join('');
  const campo = (rotulo: string, valor: string | null | undefined) => `<td class="r">${esc(rotulo)}</td><td><strong>${esc(valor || '—')}</strong></td>`;

  return `<div class="cp-carta">
  ${c.estado_efectivo === 'ANULADA' ? `<div class="cp-anulada">CARTA ANULADA${c.motivo_anulacao ? ` — ${esc(c.motivo_anulacao)}` : ''}</div>` : ''}
  <div class="cp-numero"><strong>${esc(c.numero ?? `#${c.id}`)}</strong><span>${esc(ROTULO_TIPO_CARTA[c.tipo_efectivo] ?? '')}</span></div>
  <div class="cp-banco">
    <h2>Conta a debitar</h2>
    <table><tbody>
      <tr>${campo('Banco', c.banco_nome)}${campo('Balcão', c.banco_balcao)}</tr>
      <tr>${campo('N.º de conta', c.banco_numero_conta)}${campo('IBAN', formatarIban(c.banco_iban))}</tr>
      <tr>${campo('SWIFT/BIC', c.banco_swift)}${campo('Titular', c.banco_titular || empresa?.nome)}</tr>
    </tbody></table>
  </div>
  <div class="cp-meta">
    <div><strong>Exmos. Senhores</strong><br>${esc(c.banco_nome || 'Ao Banco')}</div>
    <div style="text-align:right">${esc(localCarta(c, empresa))}, ${esc(dataPorExtenso(c.data))}</div>
  </div>
  <p class="cp-assunto">Assunto: ${esc(assunto)}</p>
  <div class="cp-corpo">${esc(corpo)}</div>
  <table class="cp-linhas">
    <thead><tr><th class="num" style="width:8mm">#</th><th>Beneficiário</th><th style="width:22mm">NIF</th><th style="width:32mm">Banco</th><th style="width:50mm">IBAN</th><th class="num" style="width:30mm">Valor</th></tr></thead>
    <tbody>${linhas}</tbody>
    <tfoot><tr><td colspan="5" style="text-align:right">TOTAL</td><td class="num">${esc(valorMoeda(c.montante_total, moeda))}</td></tr></tfoot>
  </table>
  <div class="cp-assinatura">
    <div class="linha"></div>
    ${c.nome_assinatura ? `<div style="font-weight:600">${esc(c.nome_assinatura)}</div>` : ''}
    <div style="font-size:9.5pt;color:#475569">${esc(c.cargo_assinatura ?? '')}</div>
  </div>
  <div class="cp-rodape">Carta emitida a partir do pagamento de tesouraria ${esc(c.referencia_movimento ?? '')} · ${esc(dataCurta(c.data))}</div>
</div>`;
}

/** Pedido ao motor de impressão (logótipo + nome da empresa no cabeçalho; A4 retrato). */
export function pedidoCarta(c: CartaPagamento, modo: 'imprimir' | 'pdf' = 'imprimir'): PedidoImpressao {
  return {
    titulo: 'Carta de ordem de pagamento',
    subtitulo: c.numero ?? `#${c.id}`,
    conteudo: htmlCarta(c),
    cssExtra: CSS_CARTA,
    orientacao: 'retrato',
    papel: 'A4',
    nomeFicheiro: `Carta ${c.numero ?? c.id}`.replace(/[\\/:*?"<>|]+/g, '-'),
    modo,
  };
}
