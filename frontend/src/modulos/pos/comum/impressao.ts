import { construirDocumento, FORMATO_PADRAO, logotipoSeguro } from '@/componentes/impressao';
import { useIdentidade } from '@/sessao/identidade';
import { useSessao } from '@/sessao/SessaoContexto';
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

/** Estilos do talão (térmico: largura do rolo; A4: dentro do documento do motor comum). */
function cssTalao(termico: boolean, largura: number): string {
  return `
body { font-family: ${termico ? "'Courier New', monospace" : 'Arial, sans-serif'}; font-size: ${termico ? '11px' : '12px'}; margin: 0; color: #000; }
.talao { width: ${termico ? `${largura - 6}mm` : '100%'}; margin: 0 auto; }
.talao h1 { font-size: ${termico ? '13px' : '18px'}; text-align: center; margin: 4px 0; }
.talao .logo { display: block; margin: 0 auto 3px; max-width: 70%; max-height: 22mm; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.talao .centro { text-align: center; } .talao .direita { text-align: right; } .talao .pequeno { font-size: ${termico ? '10px' : '11px'}; }
.talao table { width: 100%; border-collapse: collapse; } .talao td, .talao th { padding: 2px 0; vertical-align: top; border: 0; } .talao th { text-align: left; border-bottom: 1px dashed #000; }
.talao .linha { border-top: 1px dashed #000; margin: 4px 0; } .talao .total { font-weight: bold; font-size: ${termico ? '13px' : '15px'}; }
${termico ? '' : '.talao-cabecalho-termico, .talao .titulo-doc { display: none; }'}`;
}

/**
 * Documento a imprimir. Térmico: talão com a largura do rolo (fora da regra automática A4/A3 do motor comum),
 * com logótipo e nome da empresa no topo. A4: documento do motor comum (cabeçalho com logótipo, nome, NIF e
 * morada; «Página X de Y»), em A4 retrato.
 */
function documento(titulo: string, corpo: string, p: PreferenciasImpressao, c: CabecalhoTalao): string {
  if (p.formato === 'A4') {
    return construirDocumento(
      {
        titulo,
        identidade: { nome: c.empresa, nif: c.nif, morada: c.morada, telefone: c.telefone, logotipo: c.logotipo, rodape: c.rodapeEmpresa },
        utilizador: c.utilizador,
        conteudo: `<div class="talao">${corpo}</div>`,
        cssExtra: cssTalao(false, 0),
        nomeFicheiro: titulo,
      },
      FORMATO_PADRAO,
    );
  }
  return `<!doctype html><html lang="pt"><head><meta charset="utf-8"><title>${esc(titulo)}</title><style>
@page { size: ${p.largura}mm auto; margin: 2mm; }
${cssTalao(true, p.largura)}
</style></head><body><div class="talao">${corpo}</div></body></html>`;
}

/** Topo do talão térmico: logótipo, nome, NIF e morada (no A4 isto vem no cabeçalho do motor comum). */
function topoTalao(c: CabecalhoTalao): string {
  const logo = logotipoSeguro(c.logotipo);
  return `<div class="talao-cabecalho-termico">
${logo ? `<img class="logo" src="${logo}" alt="">` : ''}
<h1>${esc(c.empresa)}</h1>
${c.nif ? `<div class="centro">NIF ${esc(c.nif)}</div>` : ''}
${c.morada ? `<div class="centro pequeno">${esc(c.morada)}</div>` : ''}
${c.telefone ? `<div class="centro pequeno">Tel. ${esc(c.telefone)}</div>` : ''}
<div class="linha"></div>
</div>`;
}

export interface CabecalhoTalao {
  empresa: string;
  nif?: string | null;
  terminal?: string | null;
  operador?: string | null;
  /** Logótipo da empresa (data URI), da identidade (GET /sistema/identidade). */
  logotipo?: string | null;
  morada?: string | null;
  telefone?: string | null;
  /** Rodapé da empresa (só no A4, em cada página). */
  rodapeEmpresa?: string | null;
  /** Utilizador que imprime (A4). */
  utilizador?: string | null;
  /** Rótulo do local da venda (vendas.nome_tabela): «Mesa» no restaurante, «Quarto(s)» no hotel. */
  rotuloLocal?: string;
}

/** Cabeçalho do talão com a identidade da empresa activa (logótipo, nome, NIF, morada) e o utilizador da sessão. */
export function useCabecalhoTalao(): (extra?: Partial<CabecalhoTalao>) => CabecalhoTalao {
  const { empresa, utilizador } = useSessao();
  const i = useIdentidade().data;
  return (extra = {}) => ({
    empresa: i?.nome ?? empresa?.nome ?? '',
    nif: i?.nif ?? (empresa?.nif as string | null | undefined) ?? null,
    logotipo: i?.logotipo ?? null,
    morada: i?.morada ?? null,
    telefone: i?.telefone ?? null,
    rodapeEmpresa: i?.rodape ?? null,
    utilizador: utilizador ? utilizador.nome_completo || utilizador.nome_utilizador : null,
    ...extra,
  });
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
${topoTalao(c)}
<div class="centro titulo-doc"><b>Factura-recibo ${esc(v.numero_documento)}</b></div>
<div>${esc(formatarDataHora(String(v.criado_em ?? v.data_emissao)))}</div>
${c.terminal ? `<div>Terminal: ${esc(c.terminal)}</div>` : ''}
${v.nome_tabela ? `<div>${esc(c.rotuloLocal ?? 'Mesa')}: ${esc(v.nome_tabela)}</div>` : ''}
${v.pos_operador || c.operador ? `<div>Operador: ${esc(v.pos_operador ?? c.operador)}</div>` : ''}
<div class="linha"></div>
<table>${linhas}</table>
<div class="linha"></div>
<table>
<tr><td>Base</td><td class="direita">${esc(formatarKz(v.total_liquido))}</td></tr>
<tr><td>IVA</td><td class="direita">${esc(formatarKz(v.total_imposto))}</td></tr>
${Number(v.desconto ?? 0) > 0 ? `<tr><td>Desconto</td><td class="direita">-${esc(formatarKz(v.desconto))}</td></tr>` : ''}
${Number(v.arredondamento_agt ?? 0) !== 0 ? `<tr><td>Arredondamento AGT</td><td class="direita">${esc(formatarKz(v.arredondamento_agt as string))}</td></tr>` : ''}
<tr class="total"><td>TOTAL</td><td class="direita">${esc(formatarKz(v.total_bruto, true))}</td></tr>
</table>
<div class="linha"></div>
<table>${pagamentos}
${Number(v.pos_troco ?? 0) > 0 ? `<tr><td>Troco</td><td class="direita">${esc(formatarKz(v.pos_troco))}</td></tr>` : ''}
</table>
<div class="linha"></div>
<div class="centro">${esc(p.rodape)}</div>`;
  return documento(`Factura-recibo ${v.numero_documento}`, corpo, p, c);
}

/** Linha de um talão genérico (consulta de mesa, lavandaria, hotel). Valores já em Kz (texto da API ou número). */
export interface LinhaTalao {
  descricao: string;
  quantidade?: string | number | null;
  preco?: string | number | null;
  total?: string | number | null;
  /** Texto extra por baixo (ex.: peça, serviço, observação). */
  detalhe?: string | null;
}

export interface OpcoesTalao {
  /** Linhas de identificação por baixo do título (ex.: «Mesa 5», «OS n.º …», «Cliente: …»). */
  dados?: (string | null | undefined | false)[];
  linhas?: LinhaTalao[];
  /** Totais [rótulo, valor, destacado]. */
  totais?: [string, string | number | null | undefined, boolean?][];
  /** Aviso em destaque (ex.: «Documento de consulta — não serve de factura»). */
  aviso?: string | null;
  /** Texto final antes do rodapé (ex.: condições, assinatura). */
  notas?: string | null;
  /** Linha de assinatura (ex.: «O cliente (confirmo o estado das peças…)»). */
  assinatura?: string | null;
  /** Vias (ex.: «Via do cliente», «Via da loja»): o talão repete-se com quebra de página. */
  vias?: string[];
}

/**
 * Talão genérico (térmico ou A4) com o mesmo aspecto do talão de venda: consulta de mesa (M-15), talão/recibo da
 * lavandaria e talão do check-out do hotel (M-16). Todos os textos são escapados.
 */
export function htmlTalaoGenerico(titulo: string, o: OpcoesTalao, c: CabecalhoTalao, p: PreferenciasImpressao): string {
  const linhas = (o.linhas ?? [])
    .map(
      (l) =>
        `<tr><td colspan="3">${esc(l.descricao)}${l.detalhe ? `<br><span class="pequeno">${esc(l.detalhe)}</span>` : ''}</td></tr>` +
        (l.total !== undefined && l.total !== null
          ? `<tr><td>${l.quantidade !== undefined && l.quantidade !== null ? `${esc(formatarNumero(l.quantidade))}${l.preco !== undefined && l.preco !== null ? ` × ${esc(formatarKz(l.preco))}` : ''}` : ''}</td><td></td><td class="direita">${esc(formatarKz(l.total))}</td></tr>`
          : ''),
    )
    .join('');
  const totais = (o.totais ?? [])
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([r, v, forte]) => `<tr${forte ? ' class="total"' : ''}><td>${esc(r)}</td><td class="direita">${esc(typeof v === 'number' || /^-?\d+(\.\d+)?$/.test(String(v)) ? formatarKz(v as string, !!forte) : v)}</td></tr>`)
    .join('');
  const via = (rotulo?: string) => `
${topoTalao(c)}
<div class="centro titulo-doc"><b>${esc(titulo)}</b></div>
${rotulo ? `<div class="centro">${esc(rotulo)}</div>` : ''}
${(o.dados ?? []).filter(Boolean).map((d) => `<div>${esc(d)}</div>`).join('')}
${c.terminal ? `<div>Terminal: ${esc(c.terminal)}</div>` : ''}
${c.operador ? `<div>Operador: ${esc(c.operador)}</div>` : ''}
${o.aviso ? `<div class="linha"></div><div class="centro"><b>${esc(o.aviso)}</b></div>` : ''}
${linhas ? `<div class="linha"></div><table>${linhas}</table>` : ''}
${totais ? `<div class="linha"></div><table>${totais}</table>` : ''}
${o.notas ? `<div class="linha"></div><div class="pequeno">${esc(o.notas).replace(/\n/g, '<br>')}</div>` : ''}
${o.assinatura ? `<br><br><div class="centro">______________________<br>${esc(o.assinatura)}</div>` : ''}
<div class="linha"></div>
<div class="centro">${esc(p.rodape)}</div>`;
  const corpo = o.vias?.length ? o.vias.map((v) => via(v)).join('<div style="page-break-after:always;break-after:page"></div>') : via();
  return documento(titulo, corpo, p, c);
}

/** Etiquetas (térmico: uma por «folha» do rolo; A4: grelha), ex.: n.º da OS e da peça na lavandaria (M-16). */
export function htmlEtiquetas(titulo: string, etiquetas: { titulo: string; linhas: (string | null | undefined)[] }[], c: CabecalhoTalao, p: PreferenciasImpressao): string {
  const termico = p.formato !== 'A4';
  const css = termico
    ? '.etiqueta{page-break-after:always;break-after:page;text-align:center;padding:2mm 0}.etiqueta:last-child{page-break-after:auto;break-after:auto}.etiqueta b{font-size:15px;display:block}'
    : '.etiquetas{display:grid;grid-template-columns:repeat(3,1fr);gap:4mm}.etiqueta{border:1px dashed #000;padding:3mm;text-align:center;break-inside:avoid}.etiqueta b{font-size:14px;display:block}';
  const corpo = `<style>${css}</style><div class="etiquetas">${etiquetas
    .map((e) => `<div class="etiqueta"><div class="pequeno">${esc(c.empresa)}</div><b>${esc(e.titulo)}</b>${e.linhas.filter(Boolean).map((l) => `<div>${esc(l)}</div>`).join('')}</div>`)
    .join('')}</div>`;
  return documento(titulo, corpo, p, c);
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
${topoTalao(c)}
<div class="centro titulo-doc"><b>${x ? 'RELATÓRIO X' : `RELATÓRIO Z ${esc(z.numero_z)}`}</b></div>
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
  return documento(x ? 'Relatório X' : `Relatório Z ${z.numero_z ?? ''}`, corpo, p, c);
}

/**
 * Reimpressão a pedido do operador: pré-visualização numa janela nova (com botão de imprimir) ou impressão directa,
 * conforme a preferência do posto. Se o navegador bloquear a janela, imprime directamente.
 */
export function reimprimir(html: string, p: PreferenciasImpressao): void {
  if (p.consulta === 'DIRECTO') return imprimirHtml(html);
  const janela = window.open('', '_blank', `width=${p.formato === 'A4' ? 900 : 480},height=760`);
  if (!janela) return imprimirHtml(html);
  janela.document.open();
  janela.document.write(
    html.replace(
      /<body([^>]*)>/,
      '<body$1><div style="position:sticky;top:0;z-index:1;background:#f5f5f5;padding:6px;text-align:center;border-bottom:1px solid #ddd" class="nao-imprimir"><button type="button" data-accao="imprimir">Imprimir</button></div><style>@media print{.nao-imprimir{display:none !important}}</style>',
    ),
  );
  janela.document.close();
  // Sem onclick inline (CSP script-src 'self' sem 'unsafe-hashes'): o handler é registado a partir da janela principal.
  janela.document.querySelector('[data-accao="imprimir"]')?.addEventListener('click', () => {
    janela.focus();
    janela.print();
  });
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
