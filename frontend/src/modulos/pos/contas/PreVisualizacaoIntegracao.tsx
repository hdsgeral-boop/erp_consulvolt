import { Alert, App, Button, Modal, Skeleton, Space, Typography } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { enviar } from '@/api/cliente';
import { larguraModal } from '@/componentes/responsivo';
import { IconeLegado } from '@/componentes/vistas';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';

export interface LinhaPrevista {
  codigo_conta: string;
  nome_conta: string | null;
  tipo_dc: 'D' | 'C';
  valor: string;
  descricao: string | null;
  terceiro: string | null;
}

export interface LancamentoPrevisto {
  numero_lan_provisorio: string;
  data_documento: string | null;
  diario: string | null;
  tipo_origem: string;
  descricao: string | null;
  linhas: LinhaPrevista[];
  total_debito: string;
  total_credito: string;
}

export interface SessaoPrevista {
  sessao_pos_id: number;
  numero_z: string | null;
  codigo_terminal: string | null;
  nome_terminal: string | null;
  nome_operador: string | null;
  lancamentos: LancamentoPrevisto[];
  impedimentos: { codigo: string; mensagem: string }[];
}

export interface PreVisualizacao {
  sessoes: SessaoPrevista[];
  total_debito: string;
  total_credito: string;
  impedimentos: number;
}

interface ResultadoLote {
  contabilizadas: { sessao_pos_id: number; numero_z: string | null; lancamentos: string[] }[];
  erros: { sessao_pos_id: number; numero_z: string | null; codigo: string; mensagem: string }[];
}

/** N.º de documentos do lançamento (a descrição do servidor termina em «(N doc.)»). */
export function documentosDoLancamento(descricao: string | null | undefined): number | null {
  const m = /\((\d+) doc\.\)/.exec(descricao ?? '');
  return m ? Number(m[1]) : null;
}

/**
 * «Pré-visualização da integração» (posPreVisualizarIntegracao, pos_prestacao.js:262-331): um bloco por sessão com os
 * avisos/impedimentos e cada lançamento (data, diário, documentos, contas a débito e a crédito e total), o resumo
 * «Débito · Crédito · N impedimento(s)» e «Contabilizar N sessão(ões)» (bloqueado se houver impedimentos).
 * POST /pos/integracao/pre-visualizacao (nada é gravado) e POST /pos/integracao/contabilizar.
 */
export function ModalPreVisualizacaoIntegracao({ ids, aoFechar, aoConcluir }: { ids: number[]; aoFechar: () => void; aoConcluir: () => void }) {
  const { modal } = App.useApp();
  const cliente = useQueryClient();
  const aberto = ids.length > 0;
  const consulta = useQuery({
    queryKey: ['pos', 'integracao', 'pre-visualizacao', ids],
    queryFn: async () => (await enviar<PreVisualizacao>('post', '/pos/integracao/pre-visualizacao', { ids })).dados,
    enabled: aberto,
    gcTime: 0,
    staleTime: 0,
  });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao preparar a integração');
  }, [consulta.error]);
  const contabilizar = useMutation({
    mutationFn: async () => (await enviar<ResultadoLote>('post', '/pos/integracao/contabilizar', { ids })).dados,
    onSuccess: (r) => {
      void cliente.invalidateQueries({ queryKey: ['pos'] });
      modal.info({
        title: 'Integração contabilística',
        width: larguraModal(560),
        content: (
          <div style={{ whiteSpace: 'pre-line' }}>
            {r.contabilizadas.length ? `Contabilizadas:\n${r.contabilizadas.map((c) => `${c.numero_z ?? c.sessao_pos_id}: ${c.lancamentos.join(', ')}`).join('\n')}` : 'Nenhuma sessão contabilizada.'}
            {r.erros.length ? `\n\nErros:\n${r.erros.map((e) => `${e.numero_z ?? e.sessao_pos_id}: ${e.mensagem}`).join('\n')}` : ''}
          </div>
        ),
      });
      aoConcluir();
    },
    onError: (e) => notificarErro(e, 'Não foi possível contabilizar'),
  });
  const p = consulta.data;
  const n = ids.length;

  return (
    <Modal
      open={aberto}
      title={
        <Space>
          <IconeLegado icone="book" /> Pré-visualização da integração
        </Space>
      }
      width={larguraModal(980)}
      onCancel={aoFechar}
      destroyOnHidden
      footer={
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '0.6rem' }}>
          <div style={{ fontSize: '0.85rem', fontWeight: 700 }}>
            {p && (
              <>
                Débito {formatarKz(p.total_debito)} · Crédito {formatarKz(p.total_credito)}
                {p.impedimentos > 0 && <span style={{ color: '#b91c1c' }}> · {p.impedimentos} impedimento(s)</span>}
              </>
            )}
          </div>
          <Space wrap>
            <Button onClick={aoFechar}>Cancelar</Button>
            <Button
              type="primary"
              icon={<IconeLegado icone="check" />}
              disabled={!p || p.impedimentos > 0}
              title={p && p.impedimentos > 0 ? 'Corrija os impedimentos' : undefined}
              loading={contabilizar.isPending}
              onClick={() => contabilizar.mutate()}
            >
              Contabilizar {n} sessão(ões)
            </Button>
          </Space>
        </div>
      }
    >
      {!p ? (
        <Skeleton active />
      ) : (
        <>
          <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
            Simulação: nada foi gravado. Os números dos lançamentos são atribuídos ao contabilizar.
          </Typography.Paragraph>
          {p.sessoes.map((s) => (
            <BlocoSessao key={s.sessao_pos_id} s={s} />
          ))}
        </>
      )}
    </Modal>
  );
}

function BlocoSessao({ s }: { s: SessaoPrevista }) {
  return (
    <div style={{ border: '1px solid #e2e8f0', borderRadius: 4, marginBottom: '0.8rem' }} data-testid="bloco-sessao">
      <div style={{ background: '#f8fafc', padding: '0.5rem 0.75rem', fontWeight: 700, fontSize: '0.85rem' }}>
        {[s.numero_z, s.nome_terminal, s.nome_operador].filter(Boolean).join(' · ')}
      </div>
      {s.impedimentos.map((i) => (
        <Alert key={i.codigo + i.mensagem} type="error" showIcon banner style={{ fontSize: '0.78rem' }} message={i.mensagem} />
      ))}
      {s.lancamentos.map((l) => {
        const docs = documentosDoLancamento(l.descricao);
        return (
          <table key={l.numero_lan_provisorio} style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.78rem' }}>
            <tbody>
              <tr>
                <td colSpan={4} style={{ padding: '0.35rem 0.75rem', color: '#475569' }}>
                  Data {formatarData(l.data_documento)} · diário {l.diario ?? 'GEPOS'}
                  {docs !== null ? ` · ${docs} documento(s)` : ''}
                  {l.tipo_origem === 'POS_DESVIO' ? ' · desvio de caixa' : ''}
                </td>
              </tr>
              {l.linhas.map((x, i) => (
                <tr key={i} style={{ borderTop: '1px solid #f1f5f9' }}>
                  <td style={{ padding: '3px 0.75rem', width: 110, fontWeight: 700 }}>{x.codigo_conta}</td>
                  <td>
                    {x.nome_conta ?? x.descricao ?? ''}
                    {x.nome_conta && x.descricao && x.descricao !== l.descricao ? <Typography.Text type="secondary"> · {x.descricao}</Typography.Text> : null}
                    {x.terceiro ? <Typography.Text type="secondary"> · {x.terceiro}</Typography.Text> : null}
                  </td>
                  <td style={{ textAlign: 'right', width: 120 }}>{x.tipo_dc === 'D' ? formatarKz(x.valor) : ''}</td>
                  <td style={{ textAlign: 'right', width: 120, paddingRight: '0.75rem' }}>{x.tipo_dc === 'C' ? formatarKz(x.valor) : ''}</td>
                </tr>
              ))}
              <tr style={{ borderTop: '2px solid #0f172a', fontWeight: 800 }}>
                <td colSpan={2} style={{ padding: '3px 0.75rem' }}>
                  Total
                </td>
                <td style={{ textAlign: 'right' }}>{formatarKz(l.total_debito)}</td>
                <td style={{ textAlign: 'right', paddingRight: '0.75rem' }}>{formatarKz(l.total_credito)}</td>
              </tr>
            </tbody>
          </table>
        );
      })}
    </div>
  );
}
