import { Popover, Spin, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { obter } from '@/api/cliente';
import type { PreVisualizacaoLancamento } from '@/componentes/simulacoes/PreVisualizacaoLancamento';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { FaturaCompra } from '../comum/tipos';

/** Chave da consulta (sob ['compras'], invalidada pelas acções da lista: contabilizar, editar, anular). */
export const chavePreContabilizacao = (id: number) => ['compras', 'faturas', id, 'pre-contabilizacao'] as const;

/**
 * «Simulação contabilística» ao passar o rato (ou focar) sobre «Contabilizar» na lista de facturas de fornecedores
 * (legado showAccountingPreviewTooltip, js/ui_compras_v2.js:640 e :3628-3750): factura, data, total, fornecedor e as
 * linhas do lançamento (conta, descrição, D/C, valor), diário e equilíbrio. Usa o endpoint de pré-visualização do
 * detalhe (calculado no servidor, nada é gravado); só carrega quando o popover abre e fica em cache 1 minuto.
 */
export function PopoverPreContabilizacao({ fatura, children }: { fatura: FaturaCompra; children: ReactNode }) {
  const [aberto, setAberto] = useState(false);
  const q = useQuery({
    queryKey: chavePreContabilizacao(fatura.id),
    queryFn: () => obter<PreVisualizacaoLancamento>(`/compras/faturas/${fatura.id}/contabilizacao/pre-visualizacao`),
    enabled: aberto,
    retry: false,
    staleTime: 60_000,
  });
  return (
    <Popover
      open={aberto}
      onOpenChange={setAberto}
      trigger={['hover', 'focus']}
      mouseEnterDelay={0.25}
      placement="leftTop"
      destroyOnHidden
      content={<ConteudoPreContabilizacao fatura={fatura} dados={q.data} carregando={q.isLoading} erro={q.error as Error | null} />}
    >
      <span style={{ display: 'inline-block' }}>{children}</span>
    </Popover>
  );
}

export function ConteudoPreContabilizacao({ fatura, dados: p, carregando, erro }: {
  fatura: FaturaCompra;
  dados?: PreVisualizacaoLancamento;
  carregando?: boolean;
  erro?: Error | null;
}) {
  return (
    <div style={{ width: 420, maxWidth: '80vw', fontSize: 12 }} aria-live="polite">
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, borderBottom: '1px solid rgba(5,5,5,0.12)', paddingBottom: 6, marginBottom: 6 }}>
        <div>
          <Tag color="blue" style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase' }}>Simulação contabilística</Tag>
          <div style={{ fontWeight: 700, fontSize: 14, marginTop: 2 }}>Fatura: {fatura.numero_fatura}</div>
        </div>
        <div style={{ textAlign: 'right' }}>
          <Typography.Text type="secondary" style={{ fontSize: 11, display: 'block' }}>Data Doc: {formatarData(p?.data_documento ?? fatura.data)}</Typography.Text>
          <strong>{formatarKz(fatura.montante_total)} Kz</strong>
        </div>
      </div>
      <div style={{ marginBottom: 6 }}>
        <strong>{fatura.fornecedor?.nome ?? 'N/D'}</strong>
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 11 }}>NIF: {fatura.fornecedor?.nif || '---'}</Typography.Text>
      </div>
      {carregando && <Spin size="small" tip="A calcular no servidor…"><div style={{ height: 32 }} /></Spin>}
      {erro && <Typography.Text type="warning">{erro.message}</Typography.Text>}
      {p && (
        <>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr style={{ fontSize: 10, textTransform: 'uppercase', color: 'rgba(0,0,0,0.45)' }}>
                <th style={{ textAlign: 'left' }}>Conta</th>
                <th style={{ textAlign: 'left' }}>Lançamento</th>
                <th style={{ textAlign: 'right' }}>D/C</th>
                <th style={{ textAlign: 'right' }}>Valor</th>
              </tr>
            </thead>
            <tbody>
              {p.linhas.map((l, i) => (
                <tr key={i} style={{ borderTop: '1px solid rgba(5,5,5,0.06)' }}>
                  <td style={{ fontFamily: 'monospace', fontWeight: 600, padding: '3px 0' }}>{l.codigo_conta}</td>
                  <td style={{ padding: '3px 6px' }}>{[l.nome_conta, l.terceiro].filter(Boolean).join(' · ') || '—'}</td>
                  <td style={{ textAlign: 'right', fontWeight: 800, color: l.tipo_dc === 'D' ? '#16a34a' : '#dc2626' }}>{l.tipo_dc}</td>
                  <td style={{ textAlign: 'right', fontWeight: 600 }}>{formatarKz(l.valor)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6, borderTop: '1px dashed rgba(5,5,5,0.15)', paddingTop: 4, fontSize: 11 }}>
            <Typography.Text type="secondary" italic>Diário: {p.diario ?? '—'}</Typography.Text>
            {p.equilibrado ? <Typography.Text type="success">Equilíbrio D/C OK</Typography.Text> : <Typography.Text type="danger">Desequilibrado</Typography.Text>}
          </div>
        </>
      )}
    </div>
  );
}
