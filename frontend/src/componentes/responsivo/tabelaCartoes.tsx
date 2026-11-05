import type { ColumnsType } from 'antd/es/table';
import type { ReactNode } from 'react';

/**
 * Tabela → cartões em telemóvel (< 576 px), SEM perda de informação (Implementar.txt §14, estratégia 4).
 * Uso: `<Table className={CLASSE_TABELA_CARTOES} columns={comRotulosDeCartao(colunas)} … />`.
 * Cada linha passa a um cartão; cada célula mostra o título da coluna à esquerda e o valor à direita (CSS em
 * estilos/responsividade.css, `.erp-tabela-cartoes`). A coluna de acções (sem título) ocupa a largura toda no fim do cartão.
 * Em ≥ 576 px a tabela mantém o aspecto normal. Só para tabelas simples (sem linhas expansíveis/agrupadas).
 */
export const CLASSE_TABELA_CARTOES = 'erp-tabela-cartoes';

function textoDoTitulo(titulo: unknown): string {
  return typeof titulo === 'string' || typeof titulo === 'number' ? String(titulo) : '';
}

/** Acrescenta `data-rotulo` (o título) a cada célula, para o CSS o mostrar no modo cartão. */
export function comRotulosDeCartao<T>(colunas: ColumnsType<T>): ColumnsType<T> {
  return colunas.map((c) => {
    const rotulo = textoDoTitulo((c as { title?: ReactNode }).title);
    const onCellOriginal = (c as { onCell?: (r: T, i?: number) => object }).onCell;
    return {
      ...c,
      onCell: (registo: T, indice?: number) => ({ ...(onCellOriginal?.(registo, indice) ?? {}), 'data-rotulo': rotulo }),
    } as ColumnsType<T>[number];
  });
}
