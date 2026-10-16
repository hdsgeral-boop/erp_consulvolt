import { Alert, Button, Flex, Modal, Skeleton, Tag, Typography, theme, type GlobalToken } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { CSSProperties, ReactNode } from 'react';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { botoesFluxo, corEstadoFluxo, rotuloDocumentoFluxo, type FluxoProcesso } from './fluxoProcesso';

// cores do legado (css: --primary-color, --secondary-color, --success, --danger)
const PRIMARIA = '#2563eb';
const SECUNDARIA = '#475569';
const SUCESSO = '#059669';
const PERIGO = '#ef4444';

/** Acções do rodapé, ligadas pelo ecrã às mesmas funções da linha da lista. */
export interface AccoesFluxo {
  imprimir: (id: number) => unknown;
  contabilizar: (id: number, numero: string) => unknown;
  descontabilizar: (id: number, numero: string) => unknown;
  abrirNoModulo: (id: number) => void;
  converter: (id: number) => void;
  emitirRecibo: (id: number) => void;
}

/** Caixa «vazia» do antigo (tracejada); cores do tema e raio de 4 px. */
const estiloVazio = (t: GlobalToken): CSSProperties => ({ padding: '1.5rem', textAlign: 'center', background: t.colorFillAlter, borderRadius: 4, color: t.colorTextTertiary, border: `1px dashed ${t.colorBorder}`, fontSize: '0.85rem' });
const tituloColuna = (cor: string): CSSProperties => ({ borderLeft: `4px solid ${cor}`, paddingLeft: 10, marginTop: 0, marginBottom: '1.5rem', fontSize: '1rem' });

/** Linha tracejada com os pontos (timeline do legado). */
function Linha({ cor, children }: { cor: string; children: ReactNode }) {
  return <div style={{ position: 'relative', paddingLeft: 25, borderLeft: `2px dashed ${cor}`, marginLeft: 10 }}>{children}</div>;
}

function Passo({ ponto, anel, children }: { ponto: string; anel: string; children: ReactNode }) {
  const { token } = theme.useToken();
  return (
    <div style={{ marginBottom: '1.5rem', position: 'relative' }}>
      <div style={{ position: 'absolute', left: -36, top: 0, width: 20, height: 20, borderRadius: '50%', background: ponto, border: `4px solid ${token.colorBgContainer}`, boxShadow: `0 0 0 2px ${anel}`, boxSizing: 'border-box' }} />
      {children}
    </div>
  );
}

/**
 * Janela «Fluxo Completo do Processo» (legado: window.showDocumentWorkflow, js/ui_sales.js:5758): 1. Aprovisionamento
 * (B2B), 2. Ciclo de Vendas (cadeia de documentos e recibos, com o actual destacado), 3. Integração Contabilística, e o
 * rodapé «Ações de Fluxo Sugeridas». «Abrir no Módulo» leva à página de detalhe do documento.
 */
export function ModalFluxoProcesso({ id, onFechar, accoes }: { id: number | null; onFechar: () => void; accoes: AccoesFluxo }) {
  const { pode } = useSessao();
  const { token } = theme.useToken();
  const vazio = estiloVazio(token);
  // o «negro» da coluna 1 do antigo passa a ser a cor do texto (legível na tela nocturna)
  const negro = token.colorText;
  const consulta = useQuery({
    queryKey: ['vendas', 'fluxo', id],
    queryFn: () => obter<FluxoProcesso>(`/vendas/documentos/${id}/fluxo`),
    enabled: id !== null,
  });
  const fluxo = consulta.data;
  const actual = fluxo?.vendas.find((v) => v.actual);
  const botoes = botoesFluxo(actual, pode);
  const fechar = (depois?: () => unknown) => () => { onFechar(); depois?.(); };

  return (
    <Modal
      centered
      open={id !== null}
      onCancel={onFechar}
      width={larguraModal(1200)}
      style={{ top: 24 }}
      styles={{ body: { maxHeight: 'calc(95vh - 170px)', overflowY: 'auto', overflowX: 'hidden' } }}
      destroyOnHidden
      title={<span style={{ color: PRIMARIA, fontSize: '1.17rem' }}><IconeFa nome="project-diagram" /> Fluxo Completo do Processo</span>}
      footer={
        <Flex wrap gap={10} justify="space-between" align="center" style={{ marginTop: '1rem', paddingTop: '1.25rem', borderTop: `2px solid ${token.colorSplit}` }}>
          <Typography.Text strong style={{ color: SECUNDARIA, fontSize: '0.9rem' }}>Ações de Fluxo Sugeridas:</Typography.Text>
          <Flex wrap gap={10} justify="end">
            {id !== null && <Button icon={<IconeFa nome="print" />} onClick={() => accoes.imprimir(id)}>Imprimir</Button>}
            {id !== null && actual && botoes.contabilizar && (
              <Button icon={<IconeFa nome="book" />} style={{ color: PRIMARIA, borderColor: PRIMARIA }} onClick={() => accoes.contabilizar(id, actual.numero_documento)}>Contabilizar</Button>
            )}
            {id !== null && actual && botoes.descontabilizar && (
              <Button icon={<IconeFa nome="undo" />} style={{ color: PERIGO, borderColor: PERIGO }} onClick={() => accoes.descontabilizar(id, actual.numero_documento)}>Descontabilizar</Button>
            )}
            {id !== null && <Button icon={<IconeFa nome="external-link-alt" />} style={{ color: PRIMARIA }} onClick={fechar(() => accoes.abrirNoModulo(id))}>Abrir no Módulo</Button>}
            {id !== null && botoes.converter && (
              <Button icon={<IconeFa nome="exchange-alt" />} style={{ color: '#fff', background: SUCESSO, borderColor: SUCESSO }} onClick={fechar(() => accoes.converter(id))}>Converter/Faturar</Button>
            )}
            {id !== null && botoes.emitirRecibo && (
              <Button type="primary" icon={<IconeFa nome="check-double" />} onClick={fechar(() => accoes.emitirRecibo(id))}>Emitir Recibo</Button>
            )}
            <Button style={{ color: '#fff', background: SECUNDARIA, borderColor: SECUNDARIA }} onClick={onFechar}>Fechar</Button>
          </Flex>
        </Flex>
      }
    >
      {consulta.isLoading && <Skeleton active paragraph={{ rows: 6 }} />}
      {consulta.isError && <Alert type="error" showIcon message="Não foi possível obter o fluxo do documento." />}
      {fluxo && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '1.5rem', paddingTop: '1rem' }}>
          {/* Coluna 1: Aprovisionamento (opcional) */}
          <div>
            <h4 style={tituloColuna(negro)}>1. Aprovisionamento (B2B)</h4>
            {fluxo.aprovisionamento.length === 0 ? (
              <div style={vazio}>Venda direta de stock.</div>
            ) : (
              <Linha cor={negro}>
                {fluxo.aprovisionamento.map((p) => (
                  <Passo key={`${p.tipo}-${p.id}`} ponto={negro} anel={negro}>
                    <div style={{ background: token.colorFillSecondary, padding: '0.8rem', borderRadius: 4, border: `1px solid ${token.colorBorderSecondary}` }}>
                      <strong style={{ fontSize: '0.85rem', display: 'block' }}>{p.tipo}</strong>
                      <span style={{ fontSize: '0.8rem' }}>{p.documento}</span>
                      {p.data && <small style={{ fontSize: '0.75rem', color: token.colorTextSecondary, marginLeft: 8 }}>{formatarData(p.data)}</small>}
                      <div style={{ marginTop: '0.4rem' }}><Tag style={{ fontSize: '0.65rem', background: negro, color: token.colorBgContainer, borderColor: negro, marginInlineEnd: 0 }}>{p.estado ?? '—'}</Tag></div>
                    </div>
                  </Passo>
                ))}
              </Linha>
            )}
          </div>

          {/* Coluna 2: Documentos de venda e recibos */}
          <div>
            <h4 style={tituloColuna(PRIMARIA)}>2. Ciclo de Vendas</h4>
            <Linha cor={PRIMARIA}>
              {fluxo.vendas.map((s) => (
                <Passo key={s.id} ponto={s.actual ? PRIMARIA : token.colorBorder} anel={token.colorBorder}>
                  <div style={{ background: s.actual ? token.colorPrimaryBg : token.colorFillAlter, padding: '0.8rem', borderRadius: 4, border: `1px solid ${token.colorBorderSecondary}`, ...(s.actual ? { borderLeft: `4px solid ${PRIMARIA}` } : {}) }}>
                    <strong style={{ fontSize: '0.85rem', display: 'block' }}>{rotuloDocumentoFluxo(s)}</strong>
                    <small style={{ fontSize: '0.75rem' }}>{formatarData(s.data_emissao)}</small>
                    <Flex justify="space-between" align="center" style={{ marginTop: '0.4rem' }}>
                      <Tag color={corEstadoFluxo(s.estado)} style={{ fontSize: '0.65rem', marginInlineEnd: 0 }}>{s.estado ?? 'PENDENTE'}</Tag>
                      <strong style={{ fontSize: '0.8rem' }}>{formatarKz(s.total_bruto)}</strong>
                    </Flex>
                  </div>
                </Passo>
              ))}
              {fluxo.recibos.map((r) => (
                <Passo key={`${r.id}-${r.venda_id}`} ponto={SUCESSO} anel={SUCESSO}>
                  <div style={{ background: token.colorSuccessBg, padding: '0.8rem', borderRadius: 4, border: `1px solid ${token.colorSuccessBorder}`, borderLeft: `4px solid ${SUCESSO}` }}>
                    <strong style={{ fontSize: '0.85rem', display: 'block' }}>{r.numero}{r.estado === 'ANULADO' && <Tag color="error" style={{ fontSize: '0.6rem', marginInlineStart: 6 }}>ANULADO</Tag>}</strong>
                    <small style={{ fontSize: '0.75rem' }}>{formatarData(r.data)}{fluxo.vendas.length > 1 && r.numero_documento ? ` · ${r.numero_documento}` : ''}</small>
                    <div style={{ marginTop: '0.4rem', color: SUCESSO, fontWeight: 'bold', fontSize: '0.8rem' }}>Pago: {formatarKz(r.valor)}</div>
                  </div>
                </Passo>
              ))}
            </Linha>
          </div>

          {/* Coluna 3: Contabilidade */}
          <div>
            <h4 style={tituloColuna(SECUNDARIA)}>3. Integração Contabilística</h4>
            {fluxo.lancamentos.length === 0 ? (
              <div style={vazio}>Aguardar Lancamento.</div>
            ) : (
              <Flex vertical gap="1.2rem">
                {fluxo.lancamentos.map((l) => (
                  <div key={`${l.referencia}-${l.numero_lan ?? ''}`} style={{ background: token.colorBgContainer, border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4, overflow: 'hidden', boxShadow: '0 2px 5px rgba(0,0,0,0.05)' }}>
                    <div style={{ background: token.colorFillSecondary, padding: '0.5rem 0.8rem', fontWeight: 600, fontSize: '0.75rem', display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                      <span>{l.referencia}{l.numero_lan && <span style={{ color: token.colorTextSecondary, fontWeight: 400 }}> · {l.numero_lan}</span>}</span>
                      <IconeFa nome="check-circle" style={{ color: SUCESSO }} rotulo="Contabilizado" />
                    </div>
                    <table style={{ width: '100%', fontSize: '0.75rem', borderCollapse: 'collapse' }}>
                      <tbody style={{ background: token.colorBgContainer }}>
                        {l.linhas.map((x, i) => (
                          <tr key={i} style={{ borderBottom: `1px solid ${token.colorSplit}` }}>
                            <td style={{ padding: '4px 8px' }}>{x.conta}</td>
                            <td style={{ padding: '4px 8px', textAlign: 'right' }}>{x.debito !== null ? formatarKz(x.debito) : ''}</td>
                            <td style={{ padding: '4px 8px', textAlign: 'right' }}>{x.credito !== null ? formatarKz(x.credito) : ''}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ))}
              </Flex>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}
