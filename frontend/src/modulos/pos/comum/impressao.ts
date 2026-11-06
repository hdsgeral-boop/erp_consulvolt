import { useEffect } from 'react';
import { enviar, obter } from '@/api/cliente';
import { construirDocumento, FORMATO_PADRAO, logotipoSeguro } from '@/componentes/impressao';
import { useIdentidade } from '@/sessao/identidade';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { RelatorioX, SessaoPOS, VendaEmitida } from './tipos';
import { idOpaco } from '@/sessao/chaveOpaca';

/**
 * Preferências de impressão do POS (F7). A fonte de verdade é o servidor, por empresa e com sobreposição por posto/terminal
 * (GET/PUT /pos/impressao/config). O navegador só guarda uma CÓPIA (cache e modo offline), lida de forma síncrona por
 * `lerPreferencias`; `sincronizarPreferencias` actualiza essa cópia a partir do servidor. Uma configuração antiga que só exista no
 * navegador continua a valer e pode ser enviada ao servidor com «Guardar no servidor».
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
  rodape: 'Obrigado pela preferência!', // por omissão do legado (ui_sales.js:9833)
};

const chave = (empresaId: number | null | undefined) => `erp.pos.impressao.${idOpaco(empresaId ?? 0)}`;

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

/** Existe uma cópia local (configuração antiga do navegador ou cache do servidor)? */
export function temPreferenciasLocais(empresaId: number | null | undefined): boolean {
  try {
    return window.localStorage.getItem(chave(empresaId)) !== null;
  } catch {
    return false;
  }
}

/** Resposta de GET /pos/impressao/config. `terminal` só tem os campos que o terminal sobrepõe (os restantes herdam da empresa). */
export interface ConfigImpressaoServidor {
  guardada: boolean;
  empresa: Partial<PreferenciasImpressao> | null;
  terminal: Partial<PreferenciasImpressao> | null;
  terminal_pos_id: number | null;
  efectiva: PreferenciasImpressao;
  padrao: PreferenciasImpressao;
}

/** Terminal escolhido neste posto (o mesmo que a frente de caixa memoriza), para pedir a sobreposição certa. */
export function terminalDoPosto(empresaId: number | null | undefined): number | null {
  try {
    const v = Number(window.localStorage.getItem(`erp.pos.terminal.${idOpaco(empresaId ?? 0)}`));
    return v > 0 ? v : null;
  } catch {
    return null;
  }
}

export function obterConfigServidor(terminalId?: number | null): Promise<ConfigImpressaoServidor> {
  return obter<ConfigImpressaoServidor>('/pos/impressao/config', terminalId ? { terminal_pos_id: terminalId } : undefined);
}

/** Grava no servidor os valores da empresa (sem terminal) ou a sobreposição de um terminal. */
export async function guardarConfigServidor(p: Partial<PreferenciasImpressao>, terminalId?: number | null): Promise<ConfigImpressaoServidor> {
  return (await enviar<ConfigImpressaoServidor>('put', '/pos/impressao/config', { ...p, ...(terminalId ? { terminal_pos_id: terminalId } : {}) })).dados;
}

/** Remove a sobreposição de um terminal (volta a herdar da empresa). */
export async function removerSobreposicaoServidor(terminalId: number): Promise<ConfigImpressaoServidor> {
  return (await enviar<ConfigImpressaoServidor>('delete', `/pos/impressao/config/terminal/${terminalId}`)).dados;
}

/**
 * Actualiza a cópia local com a configuração efectiva do servidor (empresa + terminal do posto). Sem configuração no servidor,
 * mantém a cópia local (se a houver, `porGuardar` é verdadeiro: o ecrã oferece «Guardar no servidor»). Offline ou sem permissão:
 * fica tudo como estava.
 */
export async function sincronizarPreferencias(empresaId: number | null | undefined, terminalId?: number | null): Promise<{ origem: 'SERVIDOR' | 'LOCAL'; porGuardar: boolean; config: ConfigImpressaoServidor | null }> {
  try {
    const config = await obterConfigServidor(terminalId ?? terminalDoPosto(empresaId));
    if (config.guardada || config.terminal) {
      gravarPreferencias(empresaId, { ...PREFERENCIAS_PADRAO, ...config.efectiva });
      return { origem: 'SERVIDOR', porGuardar: false, config };
    }
    return { origem: 'LOCAL', porGuardar: temPreferenciasLocais(empresaId), config };
  } catch {
    return { origem: 'LOCAL', porGuardar: false, config: null };
  }
}

/** Sincroniza a cópia local uma vez por empresa/terminal ao abrir um ecrã de impressão do POS. */
export function useSincronizarImpressao(): void {
  const { empresa } = useSessao();
  const id = empresa?.id;
  useEffect(() => {
    if (id) void sincronizarPreferencias(id);
  }, [id]);
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
.talao .qr { width: 38mm; height: 38mm; display: block; margin: 3px auto; }
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

/**
 * Elementos do talão que vêm do servidor (GET /pos/frente/talao/{venda}, ServicoTalaoPOS): QR de consulta da AGT, série
 * electrónica, cliente e coordenadas bancárias dos meios activos. Sem eles o talão imprime-se igual, sem QR nem IBAN.
 */
export interface TalaoFiscal {
  fe_regime?: boolean;
  /** Série electrónica («FR T012026»); só nos documentos selados no regime. */
  serie?: string | null;
  /** Imagem SVG (texto) do QR; nunca é executada: entra no talão como data URL de um <img>. */
  qr_svg?: string | null;
  url_consulta?: string | null;
  fe_estado?: string | null;
  fe_erros?: unknown[];
  cliente_nome?: string | null;
  cliente_nif?: string | null;
  operador?: string | null;
  coordenadas_bancarias?: { nome: string; iban: string; swift?: string | null }[];
  /**
   * Talão ÚNICO de vários documentos (check-out da hotelaria, F-181): um QR e uma série por documento, pela ordem dos números
   * no título. Com um só documento usa-se `qr_svg`/`serie` do próprio objecto.
   */
  documentos?: { numero_documento?: string | null; serie?: string | null; qr_svg?: string | null }[];
}

/** NIF do consumidor final quando o cliente não tem NIF (legado: «NIF Cliente: 999999999»). */
export const NIF_CONSUMIDOR_FINAL = '999999999';

const svgComoImagem = (svg: string): string => `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;

/** Mensagem para o operador quando o documento ficou selado com erros de validação da AGT (F-068); null se não há erros. */
export function avisoErrosAgt(v: { fe_estado?: unknown; fe_erros?: unknown }): string | null {
  const erros = Array.isArray(v.fe_erros) ? v.fe_erros : [];
  if (v.fe_estado !== 'COM_ERROS' && !erros.length) return null;
  const textos = erros
    .map((e) => (typeof e === 'string' ? e : e && typeof e === 'object' ? String((e as Record<string, unknown>).mensagem ?? (e as Record<string, unknown>).message ?? (e as Record<string, unknown>).codigo ?? '') : ''))
    .filter(Boolean)
    .slice(0, 3);
  return `O documento ficou com erros de validação da AGT${textos.length ? `: ${textos.join('; ')}` : ''}. Peça a revisão em Vendas › Facturação electrónica.`;
}

/**
 * Talão (térmico ou A4) de uma factura-recibo POS, como o do legado (printPOSThermalReceipt, ui_sales.js:8000-8166): empresa,
 * «Talão nº», data, operador REAL, cliente e NIF (999999999 por omissão), artigos, totais, meios de pagamento, valor entregue e
 * troco, QR da AGT (documentos selados), coordenadas bancárias, mensagem de rodapé e «Processado por computador».
 */
export function htmlTalaoVenda(v: VendaEmitida, c: CabecalhoTalao, p: PreferenciasImpressao, nomesProdutos: Map<number, string> = new Map(), fiscal?: TalaoFiscal | null): string {
  const linhas = (v.itens_venda ?? [])
    .map(
      (l) =>
        `<tr><td colspan="3">${esc(l.descricao || nomesProdutos.get(l.produto_id) || `Produto #${l.produto_id}`)}</td></tr>` +
        `<tr><td>${esc(formatarNumero(l.quantidade))} × ${esc(formatarKz(l.preco_unitario))}</td><td></td><td class="direita">${esc(formatarKz(l.total))}</td></tr>`,
    )
    .join('');
  const pagos = v.pos_pagamentos ?? [];
  const pagamentos = pagos
    .map((x) => `<tr><td>- ${esc(x.nome ?? x.tipo)}${x.referencia ? ` (${esc(x.referencia)})` : ''}</td><td class="direita">${esc(formatarKz(x.valor))}</td></tr>`)
    .join('');
  const troco = Number(v.pos_troco ?? 0);
  const numerario = pagos.filter((x) => x.tipo === 'NUMERARIO').reduce((a, x) => a + Number(x.valor ?? 0), 0);
  // o valor gravado do numerário é líquido do troco: o que o cliente entregou é o líquido mais o troco
  const entregue = pagos.some((x) => x.tipo === 'NUMERARIO') ? numerario + troco : null;
  const operador = v.pos_operador || fiscal?.operador || c.operador || c.utilizador;
  const nif = (fiscal?.cliente_nif ?? '').trim() || NIF_CONSUMIDOR_FINAL;
  const bancos = (fiscal?.coordenadas_bancarias ?? []).filter((b) => b.iban);
  const qrDocumentos = (fiscal?.documentos ?? []).filter((d) => d.qr_svg);
  const qr = qrDocumentos.length > 1
    ? `<div class="linha"></div>
${qrDocumentos
  .map(
    (d) => `<div class="centro"><img class="qr" src="${svgComoImagem(d.qr_svg as string)}" alt="QR code de consulta do documento ${esc(d.numero_documento)} na AGT">
<div class="pequeno">${d.numero_documento ? `${esc(d.numero_documento)} · ` : ''}Facturação electrónica${d.serie ? ` · Série ${esc(d.serie)}` : ''}</div></div>`,
  )
  .join('')}
<div class="centro pequeno">Consulte cada documento no portal da AGT</div>`
    : fiscal?.qr_svg
    ? `<div class="linha"></div>
<div class="centro"><img class="qr" src="${svgComoImagem(fiscal.qr_svg)}" alt="QR code de consulta do documento na AGT">
<div class="pequeno">Facturação electrónica${fiscal.serie ? ` · Série ${esc(fiscal.serie)}` : ''}</div>
<div class="pequeno">Consulte este documento no portal da AGT</div></div>`
    : '';
  const corpo = `
${topoTalao(c)}
<div class="centro titulo-doc"><b>Factura-recibo</b></div>
<div>Talão nº: <b>${esc(v.numero_documento)}</b></div>
<div>Data: ${esc(formatarDataHora(String(v.criado_em ?? v.data_emissao)))}</div>
${c.terminal ? `<div>Terminal: ${esc(c.terminal)}</div>` : ''}
${v.nome_tabela ? `<div>${esc(c.rotuloLocal ?? 'Mesa')}: ${esc(v.nome_tabela)}</div>` : ''}
${operador ? `<div>Operador: ${esc(operador)}</div>` : ''}
<div>Cliente: ${esc(fiscal?.cliente_nome || 'Consumidor Final')}</div>
<div>NIF Cliente: ${esc(nif)}</div>
<div class="linha"></div>
<table>${linhas}</table>
<div class="linha"></div>
<table>
<tr><td>Base</td><td class="direita">${esc(formatarKz(v.total_liquido))}</td></tr>
<tr><td>IVA</td><td class="direita">${esc(formatarKz(v.total_imposto))}</td></tr>
${Number(v.desconto ?? 0) > 0 ? `<tr><td>Desconto</td><td class="direita">-${esc(formatarKz(v.desconto))}</td></tr>` : ''}
${Number(v.arredondamento_agt ?? 0) !== 0 ? `<tr><td>Arredondamento AGT</td><td class="direita">${esc(formatarKz(v.arredondamento_agt as string))}</td></tr>` : ''}
<tr class="total"><td>TOTAL A PAGAR</td><td class="direita">${esc(formatarKz(v.total_bruto, true))}</td></tr>
</table>
<div class="linha"></div>
<div><b>Meios de Pagamento:</b></div>
<table>${pagamentos}
${entregue !== null ? `<tr><td>Valor Entregue (Dinheiro):</td><td class="direita">${esc(formatarKz(entregue))}</td></tr>` : ''}
${entregue !== null || troco > 0 ? `<tr><td>Troco:</td><td class="direita">${esc(formatarKz(troco))}</td></tr>` : ''}
</table>
${qr}
<div class="linha"></div>
${bancos.length ? `<div class="pequeno"><b>Coordenadas Bancárias:</b>${bancos.map((b) => `<br>${esc(b.nome)}: ${esc(b.iban)}${b.swift ? ` · SWIFT ${esc(b.swift)}` : ''}`).join('')}</div><div class="linha"></div>` : ''}
<div class="centro">${esc(p.rodape)}</div>
<div class="centro pequeno">Processado por computador (Software ERP)</div>`;
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
${Number((r as RelatorioX).suprimentos ?? 0) > 0 ? `<tr><td>Suprimentos</td><td class="direita">${esc(formatarKz((r as RelatorioX).suprimentos))}</td></tr>` : ''}
${Number((r as RelatorioX).sangrias ?? 0) > 0 ? `<tr><td>Sangrias</td><td class="direita">-${esc(formatarKz((r as RelatorioX).sangrias))}</td></tr>` : ''}
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
