import { Tag } from 'antd';

/** Rótulos e cores dos estados do POS, lavandaria e hotelaria (valores gravados pelo servidor). */
export const ESTADOS_POS: Record<string, { rotulo: string; cor: string }> = {
  ABERTA: { rotulo: 'Aberta', cor: 'processing' },
  FECHADA: { rotulo: 'Fechada', cor: 'default' },
  ANULADA: { rotulo: 'Anulada', cor: 'red' },
  PENDENTE: { rotulo: 'Pendente', cor: 'orange' },
  CONTABILIZADA: { rotulo: 'Integrada', cor: 'green' },
  SEM_MOVIMENTO: { rotulo: 'Sem movimento', cor: 'default' },
  NAO_APLICAVEL: { rotulo: 'N/A', cor: 'default' },
  SEM_DESVIO: { rotulo: 'Sem desvio', cor: 'green' },
  DELIBERADO: { rotulo: 'Deliberado', cor: 'blue' },
  PARCIAL: { rotulo: 'Parcial', cor: 'gold' },
  LIQUIDADA: { rotulo: 'Liquidada', cor: 'green' },
  REGISTADO: { rotulo: 'Registado', cor: 'green' },
  ANULADO: { rotulo: 'Anulado', cor: 'red' },
  PRESTADO: { rotulo: 'Prestado', cor: 'green' },
  POR_PRESTAR: { rotulo: 'Por prestar', cor: 'orange' },
  BLOQUEADO: { rotulo: 'Bloqueado', cor: 'volcano' },
  // lavandaria
  ORCAMENTO: { rotulo: 'Orçamento', cor: 'purple' },
  RECEBIDA: { rotulo: 'Recebida', cor: 'cyan' },
  EM_EXECUCAO: { rotulo: 'Em execução', cor: 'processing' },
  PRONTA: { rotulo: 'Pronta', cor: 'green' },
  ENTREGA_PARCIAL: { rotulo: 'Entrega parcial', cor: 'gold' },
  ENTREGUE: { rotulo: 'Entregue', cor: 'default' },
  APROVADO: { rotulo: 'Aprovado', cor: 'green' },
  RECUSADO: { rotulo: 'Recusado', cor: 'volcano' },
  AGUARDA_COMPROVATIVO: { rotulo: 'Aguarda comprovativo', cor: 'orange' },
  COMPROVADO: { rotulo: 'Comprovado', cor: 'cyan' },
  APROVADA: { rotulo: 'Aprovada', cor: 'green' },
  RECUSADA: { rotulo: 'Recusada', cor: 'volcano' },
  PAGA: { rotulo: 'Paga', cor: 'default' },
  ADIANTAMENTO: { rotulo: 'Adiantamento', cor: 'blue' },
  PAGAMENTO: { rotulo: 'Pagamento', cor: 'green' },
  // hotelaria
  LIVRE: { rotulo: 'Livre', cor: 'green' },
  OCUPADO: { rotulo: 'Ocupado', cor: 'red' },
  // natureza dos meios
  NUMERARIO: { rotulo: 'Numerário', cor: 'green' },
  TPA: { rotulo: 'TPA', cor: 'blue' },
  TRANSFERENCIA: { rotulo: 'Transferência', cor: 'purple' },
};

export function rotuloEstadoPOS(estado: string | null | undefined): string {
  if (!estado) return '—';
  const conhecido = ESTADOS_POS[estado];
  if (conhecido) return conhecido.rotulo;
  const t = estado.replace(/_/g, ' ').toLowerCase();
  return t.charAt(0).toUpperCase() + t.slice(1);
}

export function EstadoPOS({ estado }: { estado: string | null | undefined }) {
  if (!estado) return <>—</>;
  return <Tag color={ESTADOS_POS[estado]?.cor ?? 'default'}>{rotuloEstadoPOS(estado)}</Tag>;
}

export function opcoesEstadoPOS(estados: string[]) {
  return estados.map((e) => ({ value: e, label: rotuloEstadoPOS(e) }));
}
