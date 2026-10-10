import type { IndicadoresOrdem, OrdemResumo } from './tipos';

/**
 * Indicadores e cartões das ordens de serviço como no sistema anterior (renderListaOrdens, lavandaria.js:697-841).
 * Só apresentação: os contadores e a situação vêm do servidor (ServicoOrdensLavandaria::listar).
 */

/** Filtros do selector de estado (lavandaria.js:784), pela mesma ordem; «Em curso» = ACTIVAS. */
export const ESTADOS_FILTRO: { valor: string; rotulo: string }[] = [
  { valor: 'ACTIVAS', rotulo: 'Em curso' },
  { valor: 'TODAS', rotulo: 'Todas' },
  { valor: 'SEM_RESPONSAVEL', rotulo: 'Sem responsável' },
  { valor: 'ORCAMENTO', rotulo: 'Orçamentos por aprovar' },
  { valor: 'RECEBIDA', rotulo: 'Recebidas' },
  { valor: 'EM_EXECUCAO', rotulo: 'Em execução' },
  { valor: 'PRONTA', rotulo: 'Prontas' },
  { valor: 'ENTREGA_PARCIAL', rotulo: 'Entrega parcial' },
  { valor: 'ATRASADAS', rotulo: 'Atrasadas' },
  { valor: 'NAO_LEVANTADAS', rotulo: 'Não levantadas' },
  { valor: 'ENTREGUE', rotulo: 'Entregues' },
  { valor: 'ANULADA', rotulo: 'Anuladas' },
];

export interface CartaoOrdens {
  chave: string;
  rotulo: string;
  contador: string;
  cor: string;
  filtro: string;
}

/** Cartões KPI (lavandaria.js:776-778): rótulo, contador do servidor, cor da barra e filtro ao clicar. */
export function cartoesOrdens(diasLevantamento: number | null | undefined): CartaoOrdens[] {
  return [
    { chave: 'hoje', rotulo: 'Recebidas hoje', contador: 'recebidas_hoje', cor: '#2563eb', filtro: 'ACTIVAS' },
    { chave: 'sem', rotulo: 'Sem responsável', contador: 'sem_responsavel', cor: '#db2777', filtro: 'SEM_RESPONSAVEL' },
    { chave: 'orc', rotulo: 'Orçamentos por aprovar', contador: 'orcamentos', cor: '#b45309', filtro: 'ORCAMENTO' },
    { chave: 'exec', rotulo: 'Por executar / em execução', contador: 'em_execucao', cor: '#7c3aed', filtro: 'ACTIVAS' },
    { chave: 'prontas', rotulo: 'Prontas para levantar', contador: 'prontas', cor: '#059669', filtro: 'PRONTA' },
    { chave: 'atrasadas', rotulo: 'Atrasadas', contador: 'atrasadas', cor: '#b91c1c', filtro: 'ATRASADAS' },
    { chave: 'naolev', rotulo: `Não levantadas (+${diasLevantamento ?? 30} dias)`, contador: 'nao_levantadas', cor: '#9a3412', filtro: 'NAO_LEVANTADAS' },
  ];
}

/** Cor da situação (indicadoresOrdem, lavandaria.js:172-174). */
export function corSituacao(situacao: string | null | undefined): string {
  const s = situacao ?? '';
  if (s === 'Anulada') return '#94a3b8';
  if (s.startsWith('Concluída com')) return '#b45309';
  if (s.startsWith('Concluída')) return '#059669';
  if (s.startsWith('Atrasada')) return '#b91c1c';
  return '#2563eb';
}

/** Linha secundária dos indicadores: «23d em aberto · execução 2d · a aguardar levantamento 5d» (lavandaria.js:823). */
export function detalheIndicadores(ind: IndicadoresOrdem): string {
  const partes = [`${ind.dias_aberta ?? 0}d em aberto`];
  if (ind.execucao !== null && ind.execucao !== undefined) partes.push(`execução ${ind.execucao}d`);
  if (ind.espera_levantamento !== null && ind.espera_levantamento !== undefined && !ind.entregue_em) partes.push(`a aguardar levantamento ${ind.espera_levantamento}d`);
  return partes.join(' · ');
}

/** Ordem em curso (seleccionável para acções em massa). */
export function emCurso(o: Pick<OrdemResumo, 'estado'>): boolean {
  return o.estado !== 'ENTREGUE' && o.estado !== 'ANULADA';
}

/** Atrasada (prazo a vermelho): em curso, ainda não pronta e com atraso. */
export function atrasada(o: Pick<OrdemResumo, 'estado' | 'indicadores'>): boolean {
  return emCurso(o) && !o.indicadores.pronta_em && (o.indicadores.atraso ?? 0) > 0;
}
