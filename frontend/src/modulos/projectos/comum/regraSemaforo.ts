import type { IndicadoresSemaforo } from './tipos';

/**
 * Semáforo da Carteira de Projectos.
 *
 * No legado (renderProjectsList, ui_projects.js:468) era um círculo verde fixo («No Prazo / No Orcamento») que não
 * olhava para os dados. Aqui tem significado e usa a MESMA regra dos «Alertas e pontos de atenção» do Resumo
 * (ServicoAnaliticoProjetos::alertas, projectos_dashboard.js:347-369), só nos alertas de prazo e orçamento:
 *   - VERMELHO (alertas de nível ERRO): há tarefas em atraso (fim já passou, não concluídas e abaixo de 100 %) ou o
 *     custo realizado ultrapassou o orçamento (consumo > 100 %);
 *   - ÂMBAR (alertas de nível AVISO): execução abaixo do tempo decorrido (ritmo < 90 %), orçamento quase esgotado
 *     (consumo ≥ 85 % com execução física < 85 %) ou alguma rubrica acima do orçado;
 *   - VERDE: dentro do prazo e do orçamento (nenhum dos anteriores);
 *   - CINZENTO: projecto encerrado ou cancelado (já não se acompanha) ou sem tarefas nem orçamento (nada a avaliar).
 * Os alertas só informativos do Resumo (requisições, aditamentos, organigrama, facturação) não mudam a cor.
 */
export type CorSemaforo = 'verde' | 'ambar' | 'vermelho' | 'cinzento';

/** Cores do legado (verde do ícone da carteira, ui_projects.js:468; restantes de projectos_dashboard.js:28). */
export const CORES_SEMAFORO: Record<CorSemaforo, string> = { verde: '#10b981', ambar: '#d97706', vermelho: '#dc2626', cinzento: '#94a3b8' };

export const ROTULOS_SEMAFORO: Record<CorSemaforo, string> = {
  verde: 'No prazo e no orçamento',
  ambar: 'Requer atenção',
  vermelho: 'Em atraso ou acima do orçamento',
  cinzento: 'Sem avaliação',
};

const pct = (v: number) => `${Math.round(v * 10) / 10}`.replace('.', ',');

export function avaliarSemaforo(estado: string | null | undefined, ind: IndicadoresSemaforo | null | undefined): { cor: CorSemaforo; motivos: string[] } {
  if (estado === 'ENCERRADO' || estado === 'CANCELADO') return { cor: 'cinzento', motivos: [estado === 'ENCERRADO' ? 'Projecto encerrado.' : 'Projecto cancelado.'] };
  if (!ind || (ind.tarefas === 0 && !(Number(ind.orcamento) > 0))) return { cor: 'cinzento', motivos: ['Sem tarefas nem orçamento: nada a avaliar.'] };

  const erros: string[] = [];
  const avisos: string[] = [];
  if (ind.tarefas_atrasadas > 0) erros.push(`${ind.tarefas_atrasadas} tarefa(s) em atraso.`);
  if (ind.consumo_pct > 100) erros.push(`Orçamento ultrapassado (${pct(ind.consumo_pct)}% consumido).`);
  else if (ind.consumo_pct >= 85 && ind.execucao < 85) avisos.push(`Orçamento quase esgotado: ${pct(ind.consumo_pct)}% consumido com ${ind.execucao}% de execução.`);
  if (ind.desempenho !== null && ind.desempenho < 0.9 && ind.tarefas > 0) avisos.push(`Execução abaixo do tempo decorrido (ritmo ${pct(ind.desempenho * 100)}%).`);
  if (ind.rubricas_excedidas > 0) avisos.push(`${ind.rubricas_excedidas} rubrica(s) acima do orçado.`);

  if (erros.length) return { cor: 'vermelho', motivos: [...erros, ...avisos] };
  if (avisos.length) return { cor: 'ambar', motivos: avisos };
  return { cor: 'verde', motivos: ['Sem tarefas em atraso, ritmo e consumo do orçamento dentro dos limites.'] };
}
