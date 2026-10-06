/**
 * Estados de uma etapa no Fluxo de Processos (ESTADOS, js/fluxo_processos.js:32-37): rótulo, classe CSS (cores em
 * fluxos.css) e ícone do legado. «na» (não aplicável) é tratado como concluída pelo backend (ServicoFluxos::normalizar).
 */
export type EstadoEtapa = 'concluida' | 'curso' | 'fazer' | 'bloqueada';

export interface DefinicaoEstado {
  rotulo: string;
  cls: 'ok' | 'curso' | 'fazer' | 'bloq';
  icone: string;
  /** cores das pastilhas (impressão e gráficos): fundo, texto, traço */
  fundo: string;
  texto: string;
  traco: string;
}

export const ESTADOS_FLUXO: Readonly<Record<EstadoEtapa, DefinicaoEstado>> = {
  concluida: { rotulo: 'Concluída', cls: 'ok', icone: 'check', fundo: '#dcfce7', texto: '#166534', traco: '#16a34a' },
  curso: { rotulo: 'Em curso', cls: 'curso', icone: 'play', fundo: '#dbeafe', texto: '#1e40af', traco: '#2563eb' },
  fazer: { rotulo: 'Por fazer', cls: 'fazer', icone: 'circle-regular', fundo: '#f1f5f9', texto: '#475569', traco: '#cbd5e1' },
  bloqueada: { rotulo: 'Bloqueada', cls: 'bloq', icone: 'exclamation-triangle', fundo: '#fee2e2', texto: '#991b1b', traco: '#dc2626' },
};

export const ORDEM_ESTADOS: readonly EstadoEtapa[] = ['concluida', 'curso', 'fazer', 'bloqueada'];

/** Estado conhecido (qualquer outro valor → «Por fazer», como no legado). */
export function estadoDe(estado: string | null | undefined): EstadoEtapa {
  return estado && estado in ESTADOS_FLUXO ? (estado as EstadoEtapa) : estado === 'na' ? 'concluida' : 'fazer';
}

/** Linha de ligação antes de uma etapa: verde quando a etapa já começou (concluída, em curso ou bloqueada). */
export function ligacaoActiva(estado: string | null | undefined): boolean {
  return estadoDe(estado) !== 'fazer';
}

/** Estado global de um processo (estadoPeriodo, fluxo_processos.js:359): bloqueado › concluído › em curso. */
export function estadoProcesso(p: { bloqueado: boolean; concluidas: number; total_etapas: number }): { estado: EstadoEtapa; rotulo: string } {
  if (p.bloqueado) return { estado: 'bloqueada', rotulo: 'Bloqueado' };
  if (p.concluidas >= p.total_etapas) return { estado: 'concluida', rotulo: 'Concluído' };
  return { estado: 'curso', rotulo: 'Em curso' };
}
