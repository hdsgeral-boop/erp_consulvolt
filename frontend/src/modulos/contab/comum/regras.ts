/**
 * Regras de visibilidade das acções da Contabilidade (espelham as validações do servidor, que decide sempre).
 * `pode` tem a semântica de useSessao().pode: qualquer das chaves.
 */
import type { EstadoExercicio, LinhaLancamento, ValidacaoExercicio } from '../api';

export type Pode = (...chaves: string[]) => boolean;

/** Situação de estorno de um lançamento (documento com N linhas). */
export function situacaoLancamento(linhas: Pick<LinhaLancamento, 'estorno_de_id' | 'estornado_por_id'>[]): 'NORMAL' | 'ESTORNADO' | 'ESTORNO' {
  if (linhas.some((l) => l.estorno_de_id !== null && l.estorno_de_id !== undefined)) return 'ESTORNO';
  if (linhas.some((l) => l.estornado_por_id !== null && l.estornado_por_id !== undefined)) return 'ESTORNADO';
  return 'NORMAL';
}

export function accoesLancamento(linhas: Pick<LinhaLancamento, 'estorno_de_id' | 'estornado_por_id'>[], pode: Pode) {
  const situacao = situacaoLancamento(linhas);
  return {
    situacao,
    /** Um estorno não se estorna e um lançamento só se estorna uma vez (ADR-016). */
    podeEstornar: linhas.length > 0 && situacao === 'NORMAL' && pode('contab_lanc_transferir'),
  };
}

export function accoesEncerramento(estado: EstadoExercicio | undefined, validacao: ValidacaoExercicio | undefined, pode: Pode) {
  const encerrado = !!estado?.encerrado;
  const temApuramento = !!estado?.passos.some((p) => p.numero_lan);
  return {
    podeExecutarPassos: !!estado && !encerrado && pode('contab_apurar'),
    /** Encerrar só sem divergências bloqueantes (os avisos não impedem — ADR-056). */
    podeEncerrar: !!estado && !encerrado && !!validacao?.pode_encerrar && pode('contab_apurar'),
    podeReabrir: encerrado && pode('contab_exercicio_reabrir'),
    podeCancelarApuramento: !encerrado && temApuramento && pode('contab_exercicio_reabrir'),
  };
}

export function accoesRelatorioContas(estado: string | undefined, exercicioEncerrado: boolean, pode: Pode) {
  const concluido = estado === 'APROVADO';
  return {
    concluido,
    podeEditar: !concluido && pode('rc_editar'),
    /** Só se conclui com o exercício encerrado (o servidor exige `forcar` se houver erros). */
    podeConcluir: !concluido && exercicioEncerrado && pode('rc_concluir'),
    podeReabrir: concluido && pode('rc_reabrir'),
  };
}
