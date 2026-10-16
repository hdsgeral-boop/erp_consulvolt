import { Alert, Button, Col, Empty, Modal, Row, Skeleton, Tag, Typography, theme, type GlobalToken } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { CSSProperties, ReactNode } from 'react';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal } from '@/componentes/responsivo';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { rotuloEstado } from './estados';
import { numeroOuId } from './tipos';

export type TipoDocumentoCompra = 'pedido' | 'proposta' | 'encomenda' | 'rececao' | 'fatura';

/** Resposta de GET /compras/fluxo-documental/{tipo}/{id} (ServicoListagensCompras::fluxoDocumental). */
export interface FluxoDocumentalCompra {
  pedido: { id: number; numero_pedido: string | null; data: string | null; estado: string; nome_requerente: string | null } | null;
  propostas: { id: number; numero_proposta: string | null; referencia: string | null; data: string | null; estado: string; montante_total: string | null; fornecedor?: { id: number; nome: string } | null }[];
  encomenda: { id: number; numero_encomenda: string | null; data: string | null; estado: string; montante_total: string | null; fornecedor?: { id: number; nome: string } | null } | null;
  rececoes: { id: number; numero_rececao: string | null; numero_entrega: string | null; data: string | null; estado: string; validado: boolean | null; contabilizado: boolean | null }[];
  faturas: { id: number; numero_fatura: string; data: string | null; estado: string | null; contabilizado: boolean | null; montante_total: string | null }[];
  lancamentos: { documento: string; numero_lan: string; linhas: { codigo_conta: string; tipo_dc: 'D' | 'C'; valor: string }[] }[];
}

/**
 * Cores dos marcos da cadeia, como no legado: cinzento (pedido/proposta), azul (ordem), amarelo (recepção), verde
 * (factura). Os fundos e as molduras claras vêm do tema (ficam certos na tela nocturna); os pontos mantêm as cores do antigo.
 */
type CorMarco = 'neutro' | 'ordem' | 'rececao' | 'fatura';
const PONTOS: Record<CorMarco, string> = { neutro: '#cbd5e1', ordem: '#1e3a8a', rececao: '#f59e0b', fatura: '#16a34a' };
const coresMarco = (t: GlobalToken, cor: CorMarco) =>
  ({
    neutro: { fundo: t.colorFillAlter, borda: t.colorBorderSecondary },
    ordem: { fundo: t.colorPrimaryBg, borda: t.colorPrimaryBorder },
    rececao: { fundo: t.colorWarningBg, borda: t.colorWarningBorder },
    fatura: { fundo: t.colorSuccessBg, borda: t.colorSuccessBorder },
  })[cor];

function Marco({ cor, titulo, data, children }: { cor: CorMarco; titulo: ReactNode; data?: string | null; children?: ReactNode }) {
  const { token } = theme.useToken();
  const c = { ponto: PONTOS[cor], ...coresMarco(token, cor) };
  const realce: CSSProperties = cor === 'neutro' ? {} : { borderLeft: `4px solid ${c.ponto}` };
  return (
    <div style={{ marginBottom: 18, position: 'relative' }}>
      <span aria-hidden style={{ position: 'absolute', left: -36, top: 0, width: 20, height: 20, borderRadius: '50%', background: c.ponto, border: `4px solid ${token.colorBgContainer}`, boxShadow: `0 0 0 2px ${c.ponto}` }} />
      <div style={{ background: c.fundo, padding: '0.8rem', borderRadius: 4, border: `1px solid ${c.borda}`, ...realce }}>
        <strong style={{ fontSize: '0.85rem', display: 'block' }}>{titulo}</strong>
        {data && <small style={{ fontSize: '0.75rem' }}>{formatarData(data)}</small>}
        <div style={{ marginTop: 6 }}>{children}</div>
      </div>
    </div>
  );
}

/** Cadeia de documentos (coluna 1) e integração contabilística (coluna 2) do modal «Fluxo Completo de Compra». */
export function ConteudoFluxoDocumental({ fluxo }: { fluxo: FluxoDocumentalCompra }) {
  const { pedido, propostas, encomenda, rececoes, faturas, lancamentos } = fluxo;
  const { token } = theme.useToken();
  const titulo = (texto: string, cor: string) => <h4 style={{ borderLeft: `4px solid ${cor}`, paddingLeft: 10, marginBottom: 18 }}>{texto}</h4>;
  return (
    <Row gutter={[24, 24]}>
      <Col xs={24} md={12}>
        {titulo('1. Cadeia de Documentos', '#1e3a8a')}
        <div style={{ position: 'relative', paddingLeft: 25, borderLeft: '2px dashed #1e3a8a', marginLeft: 10 }}>
          {pedido && (
            <Marco cor="neutro" titulo={`Pedido ${numeroOuId(pedido.numero_pedido, pedido.id)}`} data={pedido.data}>
              <Tag>{rotuloEstado(pedido.estado)}</Tag>
            </Marco>
          )}
          {propostas.map((p) => (
            <Marco key={`p${p.id}`} cor="neutro" titulo={`Proposta ${p.referencia || numeroOuId(p.numero_proposta, p.id)}`} data={p.data}>
              <Tag>{rotuloEstado(p.estado)}</Tag>
              {p.fornecedor?.nome && <Typography.Text type="secondary" style={{ fontSize: 12 }}>{p.fornecedor.nome.trim()}</Typography.Text>}
            </Marco>
          ))}
          {encomenda && (
            <Marco cor="ordem" titulo={`Ordem ${numeroOuId(encomenda.numero_encomenda, encomenda.id)}`} data={encomenda.data}>
              <Tag color="blue">{rotuloEstado(encomenda.estado)}</Tag>
            </Marco>
          )}
          {rececoes.map((r) => (
            <Marco key={`r${r.id}`} cor="rececao" titulo={`Receção ${r.numero_rececao || r.numero_entrega || `#${r.id}`}`} data={r.data}>
              <Tag color="gold">{rotuloEstado(r.estado)}</Tag>
              {r.validado && <Tag color="green">VALIDADO ARMZ</Tag>}
            </Marco>
          ))}
          {faturas.map((f) => (
            <Marco key={`f${f.id}`} cor="fatura" titulo={`Fatura Forn. ${f.numero_fatura}`} data={f.data}>
              <Tag color="green">{f.contabilizado ? 'CONTABILIZADO' : rotuloEstado(f.estado ?? 'PENDENTE')}</Tag>
              <strong>{formatarKz(f.montante_total)}</strong>
            </Marco>
          ))}
          {!pedido && !propostas.length && !encomenda && !rececoes.length && !faturas.length && <Empty description="Sem documentos." />}
        </div>
      </Col>
      <Col xs={24} md={12}>
        {titulo('2. Integração Contabilística', '#0ea5e9')}
        {lancamentos.length === 0 ? (
          <div style={{ padding: '1.5rem', textAlign: 'center', background: token.colorFillAlter, borderRadius: 4, color: token.colorTextTertiary, border: `1px dashed ${token.colorBorder}`, fontSize: '0.85rem' }}>Sem contabilização.</div>
        ) : (
          lancamentos.map((l) => (
            <div key={`${l.documento}-${l.numero_lan}`} style={{ border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4, overflow: 'hidden', marginBottom: 16, boxShadow: '0 2px 5px rgba(0,0,0,0.05)' }}>
              <div style={{ background: token.colorFillSecondary, padding: '0.5rem 0.8rem', fontWeight: 600, fontSize: '0.75rem', display: 'flex', justifyContent: 'space-between' }}>
                <span>{l.documento} · {l.numero_lan}</span>
                <IconeFa nome="check-circle" style={{ color: '#16a34a' }} />
              </div>
              <table style={{ width: '100%', fontSize: '0.75rem', borderCollapse: 'collapse' }}>
                <tbody>
                  {l.linhas.map((x, i) => (
                    <tr key={i} style={{ borderBottom: `1px solid ${token.colorSplit}` }}>
                      <td style={{ padding: '4px 8px' }}>{x.codigo_conta}</td>
                      <td style={{ padding: '4px 8px', textAlign: 'right' }}>{x.tipo_dc === 'D' ? formatarKz(x.valor) : ''}</td>
                      <td style={{ padding: '4px 8px', textAlign: 'right' }}>{x.tipo_dc === 'C' ? formatarKz(x.valor) : ''}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ))
        )}
      </Col>
    </Row>
  );
}

/** «Fluxo Documental» / «Ver Fluxo» das listas de Compras (showPurchaseWorkflow do legado). */
export function ModalFluxoDocumental({ documento, aoFechar }: { documento: { tipo: TipoDocumentoCompra; id: number } | null; aoFechar: () => void }) {
  const consulta = useQuery({
    queryKey: ['compras', 'fluxo-documental', documento?.tipo, documento?.id],
    queryFn: () => obter<FluxoDocumentalCompra>(`/compras/fluxo-documental/${documento?.tipo}/${documento?.id}`),
    enabled: !!documento,
  });
  return (
    <Modal
      open={!!documento}
      onCancel={aoFechar}
      width={larguraModal(1200)}
      title={<span style={{ color: '#1e3a8a' }}><IconeFa nome="project-diagram" /> Fluxo Completo de Compra</span>}
      footer={<Button onClick={aoFechar}>Fechar</Button>}
      destroyOnHidden
    >
      {consulta.isLoading ? <Skeleton active /> : consulta.error ? <Alert type="error" showIcon message="Não foi possível obter o fluxo do documento." /> : consulta.data && <ConteudoFluxoDocumental fluxo={consulta.data} />}
    </Modal>
  );
}
