import { Tag } from 'antd';
import { IconeFa } from '@/componentes/fluxos';
import { formatarData } from '@/utilitarios/formatacao';
import type { Activo, Propriedade, RegimeAlheio } from './tipos';

/**
 * Propriedade do bem/edifício (r3_activos). Próprio: aquisição e amortização obrigatórias, entra nos mapas e na contabilidade.
 * Alheio (arrendado, cedido, de terceiros): sem amortização nem aquisição a contabilizar; dados do proprietário/contrato
 * opcionais. O servidor valida e decide (ServicoAtivos::normalizarPropriedade); aqui só se apresenta.
 */

export const OPCOES_PROPRIEDADE: { value: Propriedade; label: string }[] = [
  { value: 'PROPRIO', label: 'Próprio (da empresa)' },
  { value: 'ALHEIO', label: 'Alheio (arrendado, cedido, de terceiros)' },
];

export const REGIMES_ALHEIO: Record<RegimeAlheio, string> = {
  ARRENDADO: 'Arrendado / alugado',
  CEDIDO: 'Cedido (comodato)',
  TERCEIROS: 'De terceiros (à guarda)',
  OUTRO: 'Outro',
};

export const OPCOES_REGIME = Object.entries(REGIMES_ALHEIO).map(([value, label]) => ({ value: value as RegimeAlheio, label }));

/** NULL (registos antigos) conta como próprio, como no servidor. */
export const eAlheio = (a: { propriedade?: Propriedade | string | null } | null | undefined) => a?.propriedade === 'ALHEIO';

/** «Arrendado · Senhorio · contrato CT-01 · até 31/01/2028» — resumo do contrato de um bem alheio. */
export function textoContrato(a: Pick<Activo, 'regime_alheio' | 'proprietario_nome' | 'contrato_referencia' | 'contrato_inicio' | 'contrato_fim' | 'proprietario'>): string {
  const partes = [
    a.regime_alheio ? REGIMES_ALHEIO[a.regime_alheio] ?? a.regime_alheio : 'Alheio',
    a.proprietario?.nome || a.proprietario_nome || '',
    a.contrato_referencia ? `contrato ${a.contrato_referencia}` : '',
    a.contrato_inicio && a.contrato_fim ? `${formatarData(a.contrato_inicio)} a ${formatarData(a.contrato_fim)}`
      : a.contrato_fim ? `até ${formatarData(a.contrato_fim)}` : a.contrato_inicio ? `desde ${formatarData(a.contrato_inicio)}` : '',
  ];
  return partes.filter(Boolean).join(' · ');
}

/** Selo «Alheio» (com o regime) ao lado da descrição. */
export function EtiquetaPropriedade({ activo }: { activo: Pick<Activo, 'propriedade' | 'regime_alheio'> }) {
  if (!eAlheio(activo)) return null;
  const regime = activo.regime_alheio ? REGIMES_ALHEIO[activo.regime_alheio] : 'Alheio';
  return (
    <Tag color="purple" style={{ marginInlineStart: 6 }} icon={<IconeFa nome="handshake" />} title="Bem alheio: não amortiza nem entra nos mapas fiscais">
      {regime}
    </Tag>
  );
}
