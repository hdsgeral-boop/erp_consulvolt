import type { CorFlag } from './tipos';

/**
 * Bandeira de atenção manual numa tarefa ou subtarefa (mesma tabela — a subtarefa é só uma tarefa com
 * tarefa_pai_id preenchido): marcação do utilizador, distinta do semáforo calculado automaticamente da carteira
 * (`regraSemaforo.ts`, que não tem preto).
 */
export const FLAGS: CorFlag[] = ['verde', 'amarelo', 'vermelho', 'preto'];

export const CORES_FLAG: Record<CorFlag, string> = { verde: '#10b981', amarelo: '#d97706', vermelho: '#dc2626', preto: '#1f2937' };

export const ROTULOS_FLAG: Record<CorFlag, string> = { verde: 'Sem problemas', amarelo: 'Atenção', vermelho: 'Urgente', preto: 'Bloqueada' };
