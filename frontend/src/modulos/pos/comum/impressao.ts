import { formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { RelatorioX, SessaoPOS, VendaEmitida } from './tipos';

/**
 * Preferências de impressão do POS. No legado viviam no localStorage por empresa (pos_print_format_<empresa>, …);
 * continuam a ser uma preferência do posto de trabalho (impressora térmica ou A4 ligada àquele computador).
 */

export interface PreferenciasImpressao {
  formato: 'TERMICO' | 'A4';
  /** Largura do rolo térmico em mm. */
  largura: 58 | 80;
  /** Imprimir o talão automaticamente depois de cada venda. */
  automatico: boolean;
  /** Ao reimprimir/consultar: pré-visualizar ou imprimir directamente. */
  consulta: 'PREVISUALIZAR' | 'DIRECTO';
  rodape: string;
}

export const PREFERENCIAS_PADRAO: PreferenciasImpressao = {
  formato: 'TERMICO',
  largura: 80,
  automatico: true,
  consulta: 'PREVISUALIZAR',
  rodape: 'Obrigado pela sua visita!',
};

const chave = (empresaId: number | null | undefined) => `erp.pos.impressao.${empresaId ?? 0}`;

export function lerPreferencias(empresaId: number | null | undefined): PreferenciasImpressao {
  try {
    const bruto = window.localStorage.getItem(chave(empresaId));
    if (!bruto) return { ...PREFERENCIAS_PADRAO };
    const p = JSON.parse(bruto) as Partial<PreferenciasImpressao>;
    return {
      formato: p.formato === 'A4' ? 'A4' : 'TERMICO',
      largura: p.largura === 58 ? 58 : 80,
      automatico: typeof p.automatico === 'boolean' ? p.automatico : PREFERENCIAS_PADRAO.automatico,
      consulta: p.consulta === 'DIRECTO' ? 'DIRECTO' : 'PREVISUALIZAR',
      rodape: typeof p.rodape === 'string' ? p.rodape.slice(0, 200) : PREFERENCIAS_PADRAO.rodape,
    };
  } catch {
    return { ...PREFERENCIAS_PADRAO };
  }
}

export function gravarPreferencias(empresaId: number | null | undefined, p: PreferenciasImpressao): void {
  window.localStorage.setItem(chave(empresaId), JSON.stringify(p));
}

function esc(texto: unknown): string {
  return String(texto ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c);
}

function documento(titulo: string, corpo: string, p: PreferenciasImpressao): string {
  const termico = p.formato === 'TERMICO';
  const pagina = termico ? `@page { size: ${p.largura}mm auto; margin: 2mm; }` : '@page { size: A4; margin: 15mm; }';
  const largura = termico ? `${p.largura - 6}mm` : '100%';
  return `<!doctype html><html lang="pt"><head><meta charset="utf-8"><title>${esc(titulo)}</title><style>
${pagina}
body { font-family: ${termico ? "'Courier New', monospace" : 'Arial, sans-serif'}; font-size: ${termico ? '11px' : '12px'}; margin: 0; color: #000; }
.talao { width: ${largura}; margin: 0 auto; }
h1 { font-size: ${termico ? '13px' : '18px'}; text-align: center; margin: 4px 0; }
.centro { text-align: center; } .direita { text-align: right; }
table { width: 100%; border-collapse: collapse; } td, th { padding: 2px 0; vertical-align: top; } th { text-align: left; border-bottom: 1px dashed #000; }
.linha { border-top: 1px dashed #000; margin: 4px 0; } .total { font-weight: bold; font-size: ${termico ? '13px' : '15px'}; }
</style></head><body><div class="talao">${corpo}</div></body></html>`;
}

export interface CabecalhoTalao {
  empresa: string;
  nif?: string | null;
  terminal?: string | null;
  operador?: string | null;
}

/** Talão (térmico ou A4) de uma factura-recibo POS. */
export function htmlTalaoVenda(v: VendaEmitida, c: CabecalhoTalao, p: PreferenciasImpressao, nomesProdutos: Map<number, string> = new Map()): string {
  const linhas = (v.itens_venda ?? [])
    .map(
      (l) =>
        `<tr><td colspan="3">${esc(l.descricao || nomesProdutos.get(l.produto_id) || `Produto #${l.produto_id}`)}</td></tr>` +
        `<tr><td>${esc(formatarNumero(l.quantidade))} × ${esc(formatarKz(l.preco_unitario))}</td><td></td><td class="direita">${esc(formatarKz(l.total))}</td></tr>`,
    )
    .join('');
  const pagamentos = (v.pos_pagamentos ?? [])
    .map((x) => `<tr><td>${esc(x.nome ?? x.tipo)}${x.referencia ? ` (${esc(x.referencia)})` : ''}</td><td class="direita">${esc(formatarKz(x.valor))}</td></tr>`)
    .join('');
  const corpo = `
<h1>${esc(c.empresa)}</h1>
${c.nif ? `<div class="centro">NIF ${esc(c.nif)}</div>` : ''}
<div class="linha"></div>
<div class="centro"><b>Factura-recibo ${esc(v.numero_documento)}</b></div>
<div>${esc(formatarDataHora(String(v.criado_em ?? v.data_emissao)))}</div>
${c.terminal ? `<div>Terminal: ${esc(c.terminal)}</div>` : ''}
${v.pos_operador || c.operador ? `<div>Operador: ${esc(v.pos_operador ?? c.operador)}</div>` : ''}
<div class="linha"></div>
<table>${linhas}</table>
<div class="linha"></div>
<table>
<tr><td>Base</td><td class="direita">${esc(formatarKz(v.total_liquido))}</td></tr>
<tr><td>IVA</td><td class="direita">${esc(formatarKz(v.total_imposto))}</td></tr>
${Number(v.desconto ?? 0) > 0 ? `<tr><td>Desconto</td><td class="direita">-${esc(formatarKz(v.desconto))}</td></tr>` : ''}
<tr class="total"><td>TOTAL</td><td class="direita">${esc(formatarKz(v.total_bruto, true))}</td></tr>
</table>
<div class="linha"></div>
<table>${pagamentos}
${Number(v.pos_troco ?? 0) > 0 ? `<tr><td>Troco</td><td class="direita">${esc(formatarKz(v.pos_troco))}</td></tr>` : ''}
</table>
<div class="linha"></div>
<div class="centro">${esc(p.rodape)}</div>`;
  return documento(v.numero_documento, corpo, p);
}

/** Relatório X (sessão aberta) ou Z (sessão fechada). */
export function htmlRelatorioSessao(r: RelatorioX | SessaoPOS, c: CabecalhoTalao, p: PreferenciasImpressao): string {
  const x = 'sessao' in r && !('numero_z' in r);
  const s = 'sessao' in r ? (r as RelatorioX).sessao : (r as SessaoPOS);
  const z = r as SessaoPOS;
  const meios = (r.totais_por_metodo ?? [])
    .map((m) => `<tr><td>${esc(m.nome)} (${m.quantidade})</td><td class="direita">${esc(formatarKz(m.valor))}</td></tr>`)
    .join('');
  const tpa = !x
    ? (z.fechos_tpa ?? [])
        .map((f) => `<tr><td>TPA ${esc(f.nome)}: talão ${esc(formatarKz(f.valor_talao))}</td><td class="direita">dif. ${esc(formatarKz(f.diferenca))}</td></tr>`)
        .join('')
    : '';
  const corpo = `
<h1>${esc(c.empresa)}</h1>
<div class="centro"><b>${x ? 'RELATÓRIO X' : `RELATÓRIO Z ${esc(z.numero_z)}`}</b></div>
<div>Sessão: ${esc(s.codigo_sessao)}</div>
<div>Terminal: ${esc(s.codigo_terminal)} — ${esc(s.nome_terminal)}</div>
<div>Operador: ${esc(s.nome_operador)}</div>
<div>Abertura: ${esc(formatarDataHora(s.aberto_em))}</div>
${!x && z.fechado_em ? `<div>Fecho: ${esc(formatarDataHora(z.fechado_em))}</div>` : ''}
${x ? `<div>Emitido: ${esc(formatarDataHora((r as RelatorioX).emitido_em))}</div>` : ''}
<div class="linha"></div>
<table>
<tr><td>N.º de vendas</td><td class="direita">${esc(r.numero_vendas ?? 0)}</td></tr>
<tr class="total"><td>Total vendas</td><td class="direita">${esc(formatarKz(r.total_vendas))}</td></tr>
</table>
<div class="linha"></div>
<table>${meios}</table>
<div class="linha"></div>
<table>
<tr><td>Fundo de maneio</td><td class="direita">${esc(formatarKz(s.fundo_maneio_abertura))}</td></tr>
<tr><td>Numerário de vendas</td><td class="direita">${esc(formatarKz(r.vendas_numerario))}</td></tr>
<tr class="total"><td>Numerário esperado</td><td class="direita">${esc(formatarKz(r.numerario_esperado))}</td></tr>
${!x ? `<tr><td>Numerário contado</td><td class="direita">${esc(formatarKz(z.numerario_contado))}</td></tr><tr class="total"><td>Desvio</td><td class="direita">${esc(formatarKz(z.desvio))}</td></tr>` : ''}
</table>
${tpa ? `<div class="linha"></div><table>${tpa}</table>` : ''}
${!x && z.justificacao ? `<div class="linha"></div><div>Justificação: ${esc(z.justificacao)}</div>` : ''}`;
  return documento(x ? 'Relatório X' : `Relatório Z ${z.numero_z ?? ''}`, corpo, p);
}

/**
 * Reimpressão a pedido do operador: pré-visualização numa janela nova (com botão de imprimir) ou impressão directa,
 * conforme a preferência do posto. Se o navegador bloquear a janela, imprime directamente.
 */
export function reimprimir(html: string, p: PreferenciasImpressao): void {
  if (p.consulta === 'DIRECTO') return imprimirHtml(html);
  const janela = window.open('', '_blank', 'width=480,height=720');
  if (!janela) return imprimirHtml(html);
  janela.document.open();
  janela.document.write(
    html.replace(
      '<body>',
      '<body><div style="position:sticky;top:0;background:#f5f5f5;padding:6px;text-align:center;border-bottom:1px solid #ddd" class="nao-imprimir"><button onclick="window.print()">Imprimir</button></div><style>@media print{.nao-imprimir{display:none}}</style>',
    ),
  );
  janela.document.close();
}

/** Imprime o HTML num iframe invisível (não abre janelas nem é bloqueado pelos bloqueadores de pop-ups). */
export function imprimirHtml(html: string): void {
  const iframe = document.createElement('iframe');
  iframe.setAttribute('aria-hidden', 'true');
  Object.assign(iframe.style, { position: 'fixed', right: '0', bottom: '0', width: '0', height: '0', border: '0' });
  document.body.appendChild(iframe);
  const doc = iframe.contentDocument;
  if (!doc || !iframe.contentWindow) {
    iframe.remove();
    return;
  }
  doc.open();
  doc.write(html);
  doc.close();
  const janela = iframe.contentWindow;
  window.setTimeout(() => {
    janela.focus();
    janela.print();
    window.setTimeout(() => iframe.remove(), 1000);
  }, 150);
}
