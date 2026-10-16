/**
 * «Modelo» do extracto bancário externo (downloadExternalReconTemplate, ui_tesouraria.js:4047-4059): livro XLSX com a folha
 * «Extrato Externo», os cabeçalhos Data · Referencia · Descrição · DEBITO · CREDITO e as duas linhas de exemplo do legado —
 * o formato que POST /tesouraria/extrato/importar lê (DEBITO = saída do banco, CREDITO = entrada). Gerado no navegador
 * pelo motor de Excel comum (sem pedido ao servidor).
 */
import { ficheirosXlsx, TIPO_XLSX, zipSemCompressao, type CelulaExcel } from '@/componentes/impressao/excel';

export const NOME_MODELO_EXTRATO = 'Modelo_Extrato_Externo.xlsx';

export const MODELO_EXTRATO: (string | number)[][] = [
  ['Data', 'Referencia', 'Descrição', 'DEBITO', 'CREDITO'],
  ['01/05/2026', 'REF-001', 'PAGAMENTO FORNECEDOR X', 0, 1500.5],
  ['02/05/2026', 'REF-002', 'RECEBIMENTO CLIENTE Y', 5000, 0],
];

export function bytesModeloExtrato(): Uint8Array {
  const linhas: CelulaExcel[][] = MODELO_EXTRATO.map((l, i) =>
    l.map((v) => ({ valor: v, tipo: typeof v === 'number' ? 'numero' : 'texto', papel: i === 0 ? 'cabecalho' : 'normal', ...(typeof v === 'number' ? { casas: 2 } : {}) })),
  );
  return zipSemCompressao(ficheirosXlsx([{ nome: 'Extrato Externo', linhas, larguras: [12, 12, 32, 12, 12] }]));
}

export function descarregarModeloExtrato(): void {
  const url = URL.createObjectURL(new Blob([bytesModeloExtrato() as BlobPart], { type: TIPO_XLSX }));
  const a = document.createElement('a');
  a.href = url;
  a.download = NOME_MODELO_EXTRATO;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
