/**
 * «Modelo» dos câmbios (descarregarModeloCambios, js/moedas.js:494-506): livro XLSX com a folha «Cambios», os
 * cabeçalhos Data · Moeda · Taxa · Origem · Âmbito e três linhas de exemplo — a folha que POST /sistema/cambios/importar
 * lê de preferência. Gerado no navegador pelo motor de Excel comum (sem pedido ao servidor).
 */
import { ficheirosXlsx, TIPO_XLSX, zipSemCompressao, type CelulaExcel } from '@/componentes/impressao/excel';
import { MODELO_CAMBIOS } from './paridade';

export const NOME_MODELO_CAMBIOS = 'modelo_cambios.xlsx';

export function bytesModeloCambios(): Uint8Array {
  const linhas: CelulaExcel[][] = MODELO_CAMBIOS.map((l, i) =>
    l.map((v) => ({ valor: v, tipo: typeof v === 'number' ? 'numero' : 'texto', papel: i === 0 ? 'cabecalho' : 'normal', ...(typeof v === 'number' ? { casas: 2 } : {}) })),
  );
  return zipSemCompressao(ficheirosXlsx([{ nome: 'Cambios', linhas, larguras: [12, 8, 12, 14, 10] }]));
}

export function descarregarModeloCambios(): void {
  const url = URL.createObjectURL(new Blob([bytesModeloCambios() as BlobPart], { type: TIPO_XLSX }));
  const a = document.createElement('a');
  a.href = url;
  a.download = NOME_MODELO_CAMBIOS;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
