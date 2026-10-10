import type { ColunaImpressao } from '@/componentes/impressao';
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import { rotuloAD } from './componentes';
import type { ItemAD } from './tipos';

/**
 * Colunas do botão «Excel» da lista de registos, pela ordem do legado (exportarRegistos de ad_ui.js): Nº, Tipo, Natureza,
 * Descrição, Terceiro, Conta resultados, Conta balanço, Início, Fim, Repartição, Valor, Reconhecido, Por reconhecer,
 * Estado, Documento origem, Documento regularização, Data limite doc.
 */
export function colunasExcelRegistos(): ColunaImpressao<ItemAD>[] {
  return [
    { titulo: 'Nº', valor: (i) => i.id, formato: 'inteiro' },
    { titulo: 'Tipo', valor: (i) => rotuloAD(i.tipo) },
    { titulo: 'Natureza', valor: (i) => rotuloAD(i.natureza) },
    { titulo: 'Descrição', valor: (i) => i.descricao, quebrar: true },
    { titulo: 'Terceiro', valor: (i) => i.terceiro?.nome?.trim() ?? '' },
    { titulo: 'Conta resultados', valor: (i) => i.conta_resultado },
    { titulo: 'Conta balanço', valor: (i) => i.conta_balanco },
    { titulo: 'Início', valor: (i) => i.data_inicio?.slice(0, 10), formato: 'data' },
    { titulo: 'Fim', valor: (i) => i.data_fim?.slice(0, 10), formato: 'data' },
    { titulo: 'Repartição', valor: (i) => (i.reparticao === 'MESES' ? 'Meses iguais' : 'Dias') },
    { titulo: 'Valor', valor: (i) => i.valor, formato: 'moeda', somar: true },
    { titulo: 'Reconhecido', valor: (i) => i.reconhecido ?? '0.00', formato: 'moeda', somar: true },
    { titulo: 'Por reconhecer', valor: (i) => porReconhecer(i), formato: 'moeda', somar: true },
    { titulo: 'Estado', valor: (i) => i.estado_rotulo ?? rotuloAD(i.estado) },
    { titulo: 'Documento origem', valor: (i) => i.origem?.doc ?? '' },
    { titulo: 'Documento regularização', valor: (i) => i.regularizacao?.doc ?? '' },
    { titulo: 'Data limite doc.', valor: (i) => i.data_limite?.slice(0, 10) ?? '', formato: 'data' },
  ];
}

/** Valor − reconhecido, ao cêntimo. */
export function porReconhecer(i: Pick<ItemAD, 'valor' | 'reconhecido'>): string {
  return deCentimos(paraCentimos(i.valor) - paraCentimos(i.reconhecido ?? '0'));
}
