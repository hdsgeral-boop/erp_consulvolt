/** Motor comum de impressão/PDF (ver README.md nesta pasta). */
export type { DocumentoPreparado, FormatoPagina, IdentidadeImpressao, OpcoesDocumento, OpcoesImpressao, Orientacao, Papel } from './tipos';
export { alturaPaginaMm, candidatos, cssPagina, decidirFormato, descreverFormato, ESCALA_MINIMA, FORMATO_PADRAO, mmParaPx, COLUNAS_PAISAGEM } from './formato';
export { construirDocumento, CSS_BASE, esc, htmlCabecalho, logotipoSeguro, nomeFicheiroPadrao } from './documento';
export { clonarParaImpressao, contarColunas, estilosDaPagina, type OpcoesClone } from './dom';
export { imprimirDocumento, prepararDocumento } from './motor';
export { fixarColunas, paginarDocumento, type ResultadoPaginacao } from './paginacao';
export { tabelaHtml, pares, type ColunaImpressao, type FormatoColuna, type OpcoesTabela, type ValorCelula } from './tabela';
export { prepararTexto, textoDeNo } from './texto';
export { useImpressao, useMensagem, DICA_PDF, type PedidoImpressao } from './useImpressao';
export { BotaoImprimir, BotoesExportar, DICA_EXCEL, type ObterPedido } from './BotoesExportar';
export { descarregarExcel, gerarXlsx, montarFolha, lerNumeroPt, lerDataPt, interpretarCelula, TIPO_XLSX, type CelulaExcel, type FolhaExcel } from './excel';
