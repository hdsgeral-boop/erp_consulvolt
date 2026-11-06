import { descarregarPost } from '@/api/cliente';
import { CSS_BASE, type PedidoImpressao } from '@/componentes/impressao';

/**
 * PDF A4 gerado no servidor (F7, POST /pos/impressao/pdf). Serve os impressos A4 do POS (sessão X/Z, relatórios, talão A4, guia de
 * saída, recibos). Envia o CORPO do mesmo pedido que o motor de impressão do navegador usa; o servidor acrescenta o cabeçalho com a
 * identidade da empresa, o rodapé e «Página X de Y», e limpa o HTML. O talão térmico continua só no navegador (ESC/POS fora de âmbito).
 */

export type TipoPdfPos = 'sessao' | 'relatorio' | 'talao' | 'recibo' | 'guia_saida';

/** Nome de ficheiro seguro (o servidor volta a limpá-lo). */
export function nomePdf(pedido: Pick<PedidoImpressao, 'titulo' | 'nomeFicheiro'>): string {
  return (pedido.nomeFicheiro || pedido.titulo || 'Documento').replace(/[\/:*?"<>|\r\n\t]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120) || 'Documento';
}

/** Corpo a enviar: o HTML do conteúdo (um elemento do ecrã é enviado como o seu HTML externo). */
export function corpoDoPedido(pedido: Pick<PedidoImpressao, 'conteudo'>): string {
  return typeof pedido.conteudo === 'string' ? pedido.conteudo : pedido.conteudo.outerHTML;
}

export function montarPedidoPdf(tipo: TipoPdfPos, pedido: PedidoImpressao): Record<string, unknown> {
  const filtros = Array.isArray(pedido.filtros) ? pedido.filtros.filter((f): f is string => !!f).join(' · ') : pedido.filtros;
  const subtitulo = [pedido.subtitulo, pedido.periodo ? `Período: ${pedido.periodo}` : null, filtros].filter(Boolean).join(' · ');
  return {
    tipo,
    titulo: pedido.titulo.slice(0, 150),
    subtitulo: subtitulo ? subtitulo.slice(0, 200) : null,
    html: corpoDoPedido(pedido),
    css: pedido.cssExtra ? pedido.cssExtra.slice(0, 20000) : null,
    orientacao: pedido.orientacao === 'paisagem' ? 'paisagem' : 'retrato',
    nome_ficheiro: nomePdf(pedido),
  };
}

/** Pede o PDF ao servidor e descarrega-o (erros: o mesmo tratamento dos outros ficheiros). */
export async function descarregarPdfServidor(tipo: TipoPdfPos, pedido: PedidoImpressao): Promise<string> {
  const { nome } = await descarregarPost('/pos/impressao/pdf', montarPedidoPdf(tipo, pedido), `${nomePdf(pedido)}.pdf`);
  return nome;
}

/**
 * Pedido de PDF a partir de um documento A4 completo já montado pelo motor (`construirDocumento`, p. ex. o talão em A4): extrai o
 * título, o corpo (`main.imp-conteudo`) e o CSS próprio do documento (o que vem depois do CSS base do motor). Devolve null se o
 * documento não for do motor (por exemplo, um talão térmico, que não tem PDF no servidor).
 */
export function pedidoPdfDeDocumentoHtml(html: string): PedidoImpressao | null {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const corpo = doc.querySelector('main.imp-conteudo');
  if (!corpo) return null;
  const titulo = doc.querySelector('h1.imp-titulo')?.textContent?.trim() || doc.title || 'Documento';
  const base = doc.querySelector('style#imp-base')?.textContent ?? '';
  const extra = base.startsWith(CSS_BASE) ? base.slice(CSS_BASE.length) : '';
  return { titulo, conteudo: corpo.innerHTML, cssExtra: extra || undefined, nomeFicheiro: doc.title || titulo };
}
