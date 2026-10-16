import { Button, Flex, Input, InputNumber, Modal, Result, Segmented, Skeleton, Typography } from 'antd';
import { PrinterOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useAccao } from '@/componentes/Accoes';
import { avaliarFecho, contagensParaApi, DENOMINACOES, deCentimos, formatarCentimos, paraCentimos, totalContagem } from '../comum/calculos';
import { iconeMeio } from '../comum/frente';
import { useCoresPos } from '../comum/cores';
import { RelatorioXModal } from './RelatorioXModal';
import { useDefinicoesPOS } from '../comum/dados';
import { larguraModal } from '@/componentes/responsivo';
import { BotaoImprimir } from '@/componentes/impressao';
import { pedidoSessao } from '../comum/documentos';
import { htmlRelatorioSessao, lerPreferencias, reimprimir, useCabecalhoTalao } from '../comum/impressao';
import type { RelatorioX, SessaoPOS } from '../comum/tipos';

/** Notas e moedas do ecrã do legado (sem a de 2 Kz, que a API aceita mas o legado não mostra). */
const NOTAS = DENOMINACOES.filter((d) => d !== 2);
const COR_MEIO: Record<string, string> = { NUMERARIO: '#059669', TPA: '#2563eb', TRANSFERENCIA: '#7c3aed' };
const SECCAO = { margin: '1rem 0 0.5rem', fontSize: '0.8rem', color: '#334155', textTransform: 'uppercase', letterSpacing: '0.03em', fontWeight: 700 } as const;
const caixaZ = (c: ReturnType<typeof useCoresPos>) => ({ background: c.fundoAlt, border: `1px solid ${c.borda}`, borderRadius: 4, padding: '0.5rem' }) as const;

interface TalaoTPA {
  valor_talao?: number | null;
  operacoes_talao?: number | null;
  referencia_lote?: string;
}

export function useRelatorioX(sessaoId: number | null | undefined, activo = true) {
  return useQuery({
    queryKey: ['pos', 'relatorio-x', sessaoId],
    queryFn: () => obter<RelatorioX>(`/pos/sessoes/${sessaoId}/relatorio-x`),
    enabled: !!sessaoId && activo,
    refetchOnWindowFocus: false,
  });
}

/**
 * Fecho Z: contagem do numerário (por notas e moedas ou pelo total), talão de fecho de cada TPA com movimento e
 * justificação quando o desvio excede a tolerância ou um talão difere do sistema (ServicoSessoesPOS::fechar).
 */
export function FechoZ({ sessaoId, aberto, aoFechar }: { sessaoId: number; aberto: boolean; aoFechar: () => void }) {
  const { empresa } = useSessao();
  const CAIXA = caixaZ(useCoresPos());
  const cabecalho = useCabecalhoTalao();
  const x = useRelatorioX(sessaoId, aberto);
  const definicoes = useDefinicoesPOS(aberto);
  const [modo, setModo] = useState<'NOTAS' | 'TOTAL'>('NOTAS');
  const [contagens, setContagens] = useState<Record<string, number | null>>({});
  const [totalManual, setTotalManual] = useState<number | null>(null);
  const [taloes, setTaloes] = useState<Record<string, TalaoTPA>>({});
  const [justificacao, setJustificacao] = useState('');
  const [fechada, setFechada] = useState<SessaoPOS | null>(null);

  useEffect(() => {
    if (aberto) {
      setContagens({});
      setTotalManual(null);
      setTaloes({});
      setJustificacao('');
      setFechada(null);
    }
  }, [aberto]);

  const fechar = useAccao<SessaoPOS>({ invalidar: [['pos']], aoSucesso: (s) => setFechada(s), tituloErro: 'Não foi possível fechar a sessão' });

  const tpas = useMemo(() => (x.data?.totais_por_metodo ?? []).filter((m) => m.tipo === 'TPA' && m.meio_id), [x.data]);
  const contado = modo === 'NOTAS' ? totalContagem(contagens) : Math.round((totalManual ?? 0) * 100);
  const tolerancia = definicoes.data?.tolerancia_desvio;
  const avaliacao = avaliarFecho(
    contado,
    x.data?.numerario_esperado,
    tolerancia ?? '0',
    tpas.map((m) => ({ sistema: m.valor, talao: taloes[m.meio_id!]?.valor_talao ?? null })),
  );
  const talaoEmFalta = tpas.some((m) => taloes[m.meio_id!]?.valor_talao === undefined || taloes[m.meio_id!]?.valor_talao === null);
  const contagemEmFalta = modo === 'TOTAL' && totalManual === null;
  const faltaJustificacao = avaliacao.exigeJustificacao && justificacao.trim().length === 0;

  const confirmar = () => {
    const corpo = {
      ...(modo === 'NOTAS' ? { contagens: contagensParaApi(contagens) } : { numerario_contado: deCentimos(contado) }),
      fechos_tpa: tpas.map((m) => ({
        meio_id: m.meio_id,
        valor_talao: taloes[m.meio_id!]?.valor_talao ?? 0,
        operacoes_talao: taloes[m.meio_id!]?.operacoes_talao ?? undefined,
        referencia_lote: taloes[m.meio_id!]?.referencia_lote?.trim() || undefined,
      })),
      justificacao: justificacao.trim() || undefined,
    };
    Modal.confirm({
      title: `Fechar a caixa ${x.data?.sessao.codigo_sessao ?? ''}?`,
      content: `Contado: ${formatarCentimos(contado)} Kz · Desvio: ${formatarCentimos(avaliacao.desvio)} Kz. Depois de fechada não é possível emitir mais vendas nesta sessão.`,
      okText: 'Fechar sessão',
      cancelText: 'Voltar',
      onOk: () => fechar.mutateAsync({ url: `/pos/sessoes/${sessaoId}/fechar`, dados: corpo }).catch(() => undefined),
    });
  };

  const imprimirZ = (s: SessaoPOS) => reimprimir(htmlRelatorioSessao(s, cabecalho(), lerPreferencias(empresa?.id)), lerPreferencias(empresa?.id));

  const [verX, setVerX] = useState(false);
  const corDesvioZ = avaliacao.acimaTolerancia ? '#b91c1c' : '#059669';
  const textoDesvioZ = `${avaliacao.desvio > 0 ? '+' : ''}${formatarCentimos(avaliacao.desvio)} Kz${!avaliacao.acimaTolerancia ? ' (dentro da tolerância)' : avaliacao.desvio < 0 ? ' (falta)' : ' (sobra)'}`;

  return (
    <Modal
      open={aberto}
      onCancel={aoFechar}
      width={larguraModal(760)}
      title={
        <span style={{ color: '#b91c1c', fontSize: '1.17em' }}>
          <IconeFa nome="lock" /> Fecho de caixa{x.data ? ` · ${x.data.sessao.codigo_sessao}` : ''}
        </span>
      }
      className="pos-fecho-z"
      destroyOnHidden
      maskClosable={false}
      footer={
        fechada ? (
          <Button type="primary" onClick={aoFechar}>
            Concluir
          </Button>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1.5fr', gap: '0.6rem', width: '100%', marginTop: '1rem' }}>
            <Button onClick={aoFechar}>Cancelar</Button>
            <Button icon={<IconeFa nome="file-alt" />} disabled={!x.data} onClick={() => setVerX(true)}>
              Relatório X
            </Button>
            <Button type="primary" danger style={{ fontWeight: 800 }} icon={<IconeFa nome="lock" />} loading={fechar.isPending} disabled={!x.data || talaoEmFalta || contagemEmFalta || faltaJustificacao} onClick={confirmar}>
              Fechar caixa e emitir Z
            </Button>
          </div>
        )
      }
    >
      {fechada ? (
        <Result
          status="success"
          title={`Sessão fechada: ${fechada.numero_z}`}
          subTitle={`Desvio ${formatarKz(fechada.desvio)} Kz · ${fechada.estado_desvio === 'PENDENTE' ? 'desvio por deliberar' : 'desvio regularizado automaticamente ou sem desvio'}`}
          extra={
            <Flex gap={8} wrap justify="center">
              <Button icon={<PrinterOutlined />} onClick={() => imprimirZ(fechada)}>
                Imprimir relatório Z
              </Button>
              <BotaoImprimir modo="pdf" texto="PDF (A4)" obterPedido={() => pedidoSessao(fechada)} />
            </Flex>
          }
        />
      ) : !x.data ? (
        <Skeleton active />
      ) : (
        // estrutura do legado (pos_gestao.js:530-575); a contagem «pelo total» e os recibos da lavandaria são acréscimos
        <div style={{ borderTop: '1px solid #f1f5f9' }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '0.5rem', marginTop: '0.75rem', fontSize: '0.8rem', color: '#475569' }}>
            <div>
              <strong>Terminal:</strong> {x.data.sessao.codigo_terminal} {x.data.sessao.nome_terminal}
            </div>
            <div>
              <strong>Operador:</strong> {x.data.sessao.nome_operador}
            </div>
            <div>
              <strong>Abertura:</strong> {formatarDataHora(x.data.sessao.aberto_em)}
            </div>
            <div>
              <strong>Fundo de maneio:</strong> {formatarKz(x.data.sessao.fundo_maneio_abertura)} Kz
            </div>
          </div>

          <h4 style={SECCAO}>Vendas da sessão por meio de pagamento</h4>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.85rem' }}>
            <tbody>
              {x.data.totais_por_metodo.length === 0 ? (
                <tr>
                  <td style={{ padding: '6px 0', color: '#94a3b8' }}>Sem vendas nesta sessão.</td>
                </tr>
              ) : (
                x.data.totais_por_metodo.map((m) => (
                  <tr key={`${m.meio_id ?? m.tipo}`} style={{ borderBottom: '1px solid #f1f5f9' }}>
                    <td style={{ padding: '4px 0' }}>
                      <IconeFa nome={iconeMeio(m.tipo)} style={{ color: COR_MEIO[m.tipo] ?? '#334155' }} /> {m.nome}
                    </td>
                    <td style={{ textAlign: 'center', color: '#64748b' }}>{m.quantidade} pag.</td>
                    <td style={{ textAlign: 'right', fontWeight: 700 }}>{formatarKz(m.valor)} Kz</td>
                  </tr>
                ))
              )}
              <tr style={{ fontWeight: 800, borderTop: '2px solid #0f172a' }}>
                <td style={{ padding: '4px 0' }}>Total ({x.data.numero_vendas} documentos)</td>
                <td />
                <td style={{ textAlign: 'right' }}>{formatarKz(x.data.total_vendas)} Kz</td>
              </tr>
            </tbody>
          </table>
          {x.data.lavandaria.movimento && (
            <div style={{ fontSize: '0.8rem', color: '#475569', marginTop: 4 }}>
              Recibos da lavandaria: {x.data.lavandaria.numero_recibos} · <strong>{formatarKz(x.data.lavandaria.total_recibos)} Kz</strong>
            </div>
          )}

          <h4 style={{ ...SECCAO, display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
            <span>Contagem do numerário</span>
            <Segmented size="small" style={{ textTransform: 'none', letterSpacing: 0, fontWeight: 400 }} value={modo} onChange={(v) => setModo(v as 'NOTAS' | 'TOTAL')} options={[{ value: 'NOTAS', label: 'Notas e moedas' }, { value: 'TOTAL', label: 'Pelo total' }]} />
          </h4>
          {modo === 'NOTAS' ? (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(125px, 1fr))', gap: '0.4rem' }}>
              {NOTAS.map((d) => (
                <label key={d} style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', fontSize: '0.78rem', background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 4, padding: '0.25rem 0.4rem', margin: 0 }}>
                  <span style={{ minWidth: 44, fontWeight: 700 }}>{d}</span>×
                  <InputNumber<number>
                    aria-label={`Quantidade de ${d} Kz`}
                    size="small"
                    min={0}
                    precision={0}
                    controls={false}
                    className="pos-fecho-den"
                    style={{ width: '100%' }}
                    value={contagens[String(d)] ?? null}
                    onChange={(v) => setContagens((c) => ({ ...c, [String(d)]: v }))}
                  />
                </label>
              ))}
            </div>
          ) : (
            <InputNumber<number> aria-label="Total contado" min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} suffix="Kz" value={totalManual} onChange={setTotalManual} autoFocus />
          )}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: '0.5rem', marginTop: '0.6rem', fontSize: '0.85rem' }}>
            <div style={CAIXA}>
              Esperado em caixa
              <br />
              <strong>{formatarKz(x.data.numerario_esperado)} Kz</strong>
              <br />
              <small style={{ color: '#64748b' }}>
                fundo {formatarKz(x.data.sessao.fundo_maneio_abertura)} Kz + numerário {formatarKz(x.data.vendas_numerario)} Kz
              </small>
            </div>
            <div style={CAIXA}>
              Contado
              <br />
              <strong>{formatarCentimos(contado)} Kz</strong>
            </div>
            <div style={CAIXA}>
              Desvio
              <br />
              <strong style={{ color: corDesvioZ }}>{textoDesvioZ}</strong>
              <br />
              <small style={{ color: '#64748b' }}>tolerância {formatarKz(tolerancia ?? 0)} Kz</small>
            </div>
          </div>

          {tpas.length > 0 && (
            <>
              <h4 style={SECCAO}>Fecho do TPA (talão de fecho do terminal Multicaixa)</h4>
              {tpas.map((m) => {
                const t = taloes[m.meio_id!] ?? {};
                const alterar = (a: Partial<TalaoTPA>) => setTaloes((v) => ({ ...v, [m.meio_id!]: { ...t, ...a } }));
                const temTalao = t.valor_talao !== undefined && t.valor_talao !== null;
                const dif = temTalao ? Math.round((t.valor_talao ?? 0) * 100) - paraCentimos(m.valor) : 0;
                return (
                  <div key={m.meio_id} style={{ display: 'grid', gridTemplateColumns: '1.3fr 1fr 0.8fr 1fr 1fr', gap: '0.4rem', alignItems: 'center', fontSize: '0.8rem', marginBottom: '0.4rem' }}>
                    <div>
                      <strong>{m.nome}</strong>
                      {m.codigo_tpa && (
                        <>
                          <br />
                          <small style={{ color: '#64748b' }}>TPA {m.codigo_tpa}</small>
                        </>
                      )}
                      <br />
                      <small>
                        Sistema: {formatarKz(m.valor)} Kz · {m.quantidade} op.
                      </small>
                    </div>
                    <InputNumber<number> aria-label={`Valor do talão ${m.nome}`} placeholder="Valor do talão" min={0} precision={2} decimalSeparator="," controls={false} style={{ width: '100%' }} value={t.valor_talao ?? null} onChange={(v) => alterar({ valor_talao: v })} />
                    <InputNumber<number> placeholder="N.º op." min={0} precision={0} controls={false} style={{ width: '100%' }} value={t.operacoes_talao ?? null} onChange={(v) => alterar({ operacoes_talao: v })} />
                    <Input placeholder="N.º lote / fecho" maxLength={60} value={t.referencia_lote} onChange={(e) => alterar({ referencia_lote: e.target.value })} />
                    <div style={{ textAlign: 'right', fontWeight: 700, color: !temTalao || dif === 0 ? '#059669' : '#b91c1c' }}>{temTalao ? `${dif > 0 ? '+' : ''}${formatarCentimos(dif)} Kz` : '—'}</div>
                  </div>
                );
              })}
            </>
          )}

          {x.data.transferencias.length > 0 && (
            <>
              <h4 style={SECCAO}>Transferências recebidas (comprovativos)</h4>
              <div style={{ fontSize: '0.8rem', color: '#475569' }}>
                {x.data.transferencias.map((t) => (
                  <div key={t.venda_id}>
                    {t.numero_documento ?? '—'} · {t.referencia ?? '—'} · <strong>{formatarKz(t.valor)} Kz</strong>
                  </div>
                ))}
              </div>
            </>
          )}

          <h4 style={SECCAO}>Justificação</h4>
          {avaliacao.exigeJustificacao && justificacao.trim() === '' && (
            <Typography.Text type="danger" style={{ fontSize: '0.78rem', display: 'block', marginBottom: 4 }}>
              {avaliacao.acimaTolerancia ? 'O desvio excede a tolerância: a justificação é obrigatória.' : 'Um talão TPA difere do sistema: a justificação é obrigatória.'}
            </Typography.Text>
          )}
          <Input.TextArea rows={2} maxLength={2000} value={justificacao} onChange={(e) => setJustificacao(e.target.value)} placeholder="Obrigatória quando existe desvio de caixa ou diferença no TPA" />
        </div>
      )}
      {verX && <RelatorioXModal sessaoId={sessaoId} aberto aoFechar={() => setVerX(false)} />}
    </Modal>
  );
}
