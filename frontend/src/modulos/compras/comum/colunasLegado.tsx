import { Tag } from 'antd';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { formatarKz } from '@/utilitarios/formatacao';

/**
 * Colunas comuns das listas de Compras do legado (renderCompras, js/ui_compras_v2.js): «UNº / CC», «Moeda» / «Total Moeda»
 * (comprasFxCelulas) e «Satisfação (E/F)». Os campos vêm do servidor (ServicoListagensCompras, por lote).
 */

export interface ComDimensoes {
  unidade_negocio_codigo?: string | null;
  centro_custo_codigo?: string | null;
}

/** «UN / CC» como no legado: «código UN / código CC», com «-» quando falta. */
export function textoUnCc(r: ComDimensoes): string {
  return `${r.unidade_negocio_codigo || '-'} / ${r.centro_custo_codigo || '-'}`;
}

export function colunaUnCc<T extends ComDimensoes>(): ColunaApi<T> {
  return {
    title: 'UNº / CC',
    key: 'un_cc',
    valorImpressao: (r) => textoUnCc(r),
    valorFiltro: (r) => textoUnCc(r),
    render: (_, r) => <span style={{ fontSize: '0.75rem', color: '#64748b' }}>{textoUnCc(r)}</span>,
  };
}

/** Moeda do documento (AOA por omissão) e total na moeda (vazio em Kz), como comprasFxCelulas. */
export function colunasMoeda<T extends { codigo_moeda?: string | null; montante_total_moeda?: string | null }>(): ColunaApi<T>[] {
  const estrangeira = (r: T) => !!r.codigo_moeda && r.codigo_moeda !== 'AOA';
  return [
    { title: 'Moeda', key: 'moeda', valorImpressao: (r) => r.codigo_moeda || 'AOA', render: (_, r) => r.codigo_moeda || 'AOA' },
    {
      title: 'Total Moeda',
      key: 'total_moeda',
      align: 'right',
      valorImpressao: (r) => (estrangeira(r) ? formatarKz(r.montante_total_moeda) : ''),
      render: (_, r) => (estrangeira(r) ? formatarKz(r.montante_total_moeda) : ''),
    },
  ];
}

/** Duas barras finas: entrega (verde) e facturação (azul), com a percentagem na dica. */
export function BarrasSatisfacao({ entregue, faturada }: { entregue: number; faturada: number }) {
  const barra = (p: number, cor: string, rotulo: string) => (
    <div title={`${rotulo}: ${Math.round(p)}%`} aria-label={`${rotulo}: ${Math.round(p)}%`} style={{ marginBottom: 5 }}>
      <div style={{ width: '100%', height: 6, background: '#e2e8f0', borderRadius: 3 }}>
        <div style={{ width: `${Math.max(0, Math.min(100, p))}%`, height: '100%', background: cor, borderRadius: 3 }} />
      </div>
    </div>
  );
  return (
    <div style={{ width: 100 }}>
      {barra(entregue, '#22c55e', 'Entrega')}
      {barra(faturada, '#3b82f6', 'Faturação')}
    </div>
  );
}

/** Etiqueta de estado em maiúsculas, como os «badge» do legado (texto do servidor). */
export function EtiquetaLegado({ texto, cor }: { texto: string | null | undefined; cor?: string }) {
  return <Tag color={cor}>{(texto || '—').toUpperCase()}</Tag>;
}
