import { Tag } from 'antd';

/** Rótulos e cores dos estados usados em Compras, Logística e Inventário (valores gravados pelo servidor). */
export const ESTADOS: Record<string, { rotulo: string; cor: string }> = {
  PENDENTE: { rotulo: 'Pendente', cor: 'orange' },
  AGUARDA: { rotulo: 'A aguardar', cor: 'default' },
  APROVADO: { rotulo: 'Aprovado', cor: 'green' },
  APROVADA: { rotulo: 'Aprovada', cor: 'green' },
  REJEITADO: { rotulo: 'Rejeitado', cor: 'red' },
  RECUSADA: { rotulo: 'Recusada', cor: 'volcano' },
  RECUSADO: { rotulo: 'Recusado', cor: 'volcano' },
  ADJUDICADO: { rotulo: 'Adjudicado', cor: 'blue' },
  PROPOSTA: { rotulo: 'Proposta', cor: 'default' },
  PROPOSTA_ADJUDICACAO: { rotulo: 'Proposta para adjudicação', cor: 'gold' },
  EM_PROCESSAMENTO: { rotulo: 'Em processamento', cor: 'processing' },
  PARCIAL: { rotulo: 'Parcial', cor: 'gold' },
  RECEBIDO: { rotulo: 'Recebido', cor: 'cyan' },
  VALIDADO: { rotulo: 'Validado', cor: 'green' },
  PAGO: { rotulo: 'Pago', cor: 'green' },
  ATIVO: { rotulo: 'Activo', cor: 'green' },
  EXPIRADO: { rotulo: 'Expirado', cor: 'default' },
  CANCELADO: { rotulo: 'Cancelado', cor: 'red' },
  ANULADO: { rotulo: 'Anulado', cor: 'red' },
  ANULADA: { rotulo: 'Anulada', cor: 'red' },
  CONCLUIDO: { rotulo: 'Concluído', cor: 'green' },
  CONCLUIDA: { rotulo: 'Concluída', cor: 'green' },
  EM_CONTAGEM: { rotulo: 'Em contagem', cor: 'processing' },
  REVISAO: { rotulo: 'Em revisão', cor: 'gold' },
  POR_EXPEDIR: { rotulo: 'Por expedir', cor: 'orange' },
  EMITIDO: { rotulo: 'Emitido', cor: 'blue' },
};

export function rotuloEstado(estado: string | null | undefined): string {
  if (!estado) return '—';
  const conhecido = ESTADOS[estado];
  if (conhecido) return conhecido.rotulo;
  const t = estado.replace(/_/g, ' ').toLowerCase();
  return t.charAt(0).toUpperCase() + t.slice(1);
}

export function EstadoTag({ estado }: { estado: string | null | undefined }) {
  if (!estado) return <>—</>;
  return <Tag color={ESTADOS[estado]?.cor ?? 'default'}>{rotuloEstado(estado)}</Tag>;
}

/** Opções para filtros de estado (Select). */
export function opcoesEstado(estados: string[]) {
  return estados.map((e) => ({ value: e, label: rotuloEstado(e) }));
}
