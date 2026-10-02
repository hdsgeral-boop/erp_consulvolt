import { Alert, Button, Card, Col, Descriptions, Divider, Flex, Input, InputNumber, Modal, Result, Row, Segmented, Skeleton, Statistic, Table, Typography } from 'antd';
import { PrinterOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { avaliarFecho, contagensParaApi, DENOMINACOES, deCentimos, formatarCentimos, totalContagem } from '../comum/calculos';
import { useDefinicoesPOS } from '../comum/dados';
import { TabelaMeios } from '../comum/DetalheSessao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { BotaoImprimir } from '@/componentes/impressao';
import { pedidoSessao } from '../comum/documentos';
import { htmlRelatorioSessao, lerPreferencias, reimprimir, useCabecalhoTalao } from '../comum/impressao';
import type { RelatorioX, SessaoPOS } from '../comum/tipos';

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
      title: 'Confirmar o fecho Z?',
      content: `Numerário contado: ${formatarCentimos(contado)} Kz. Desvio: ${formatarCentimos(avaliacao.desvio)} Kz. Depois do fecho não se registam mais vendas nesta sessão.`,
      okText: 'Fechar sessão',
      cancelText: 'Voltar',
      onOk: () => fechar.mutateAsync({ url: `/pos/sessoes/${sessaoId}/fechar`, dados: corpo }).catch(() => undefined),
    });
  };

  const imprimirZ = (s: SessaoPOS) => reimprimir(htmlRelatorioSessao(s, cabecalho(), lerPreferencias(empresa?.id)), lerPreferencias(empresa?.id));

  return (
    <Modal
      open={aberto}
      onCancel={aoFechar}
      width={larguraModal(980)}
      title="Fecho de caixa (Z)"
      destroyOnHidden
      maskClosable={false}
      footer={
        fechada ? (
          <Button type="primary" onClick={aoFechar}>
            Concluir
          </Button>
        ) : (
          <Flex justify="end" gap={8} wrap>
            <Button onClick={aoFechar}>Cancelar</Button>
            <Button type="primary" danger loading={fechar.isPending} disabled={!x.data || talaoEmFalta || contagemEmFalta || faltaJustificacao} onClick={confirmar}>
              Fechar sessão (Z)
            </Button>
          </Flex>
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
        <Row gutter={[16, 16]}>
          <Col xs={24} lg={13}>
            <Card size="small" title="Numerário contado" extra={<Segmented size="small" value={modo} onChange={(v) => setModo(v as 'NOTAS' | 'TOTAL')} options={[{ value: 'NOTAS', label: 'Notas e moedas' }, { value: 'TOTAL', label: 'Pelo total' }]} />}>
              {modo === 'NOTAS' ? (
                <Table
                  size="small"
                  scroll={scrollTabela()}
                  pagination={false}
                  rowKey={(d) => String(d)}
                  dataSource={[...DENOMINACOES]}
                  columns={[
                    { title: 'Nota/moeda', render: (d: number) => `${formatarKz(d)} Kz` },
                    {
                      title: 'Quantidade',
                      render: (d: number) => (
                        <InputNumber<number>
                          aria-label={`Quantidade de ${d} Kz`}
                          min={0}
                          precision={0}
                          style={{ width: 110 }}
                          value={contagens[String(d)] ?? null}
                          onChange={(v) => setContagens((c) => ({ ...c, [String(d)]: v }))}
                        />
                      ),
                    },
                    { title: 'Subtotal', align: 'right', render: (d: number) => formatarKz(d * (contagens[String(d)] ?? 0)) },
                  ]}
                />
              ) : (
                <InputNumber<number>
                  aria-label="Total contado"
                  size="large"
                  min={0}
                  precision={2}
                  decimalSeparator=","
                  style={{ width: '100%' }}
                  suffix="Kz"
                  value={totalManual}
                  onChange={setTotalManual}
                  autoFocus
                />
              )}
            </Card>
          </Col>
          <Col xs={24} lg={11}>
            <Card size="small" title="Resumo">
              <Descriptions size="small" column={1}>
                <Descriptions.Item label="Vendas">{`${x.data.numero_vendas} · ${formatarKz(x.data.total_vendas)} Kz`}</Descriptions.Item>
                <Descriptions.Item label="Fundo de maneio">{formatarKz(x.data.sessao.fundo_maneio_abertura)} Kz</Descriptions.Item>
                <Descriptions.Item label="Numerário de vendas">{formatarKz(x.data.vendas_numerario)} Kz</Descriptions.Item>
                {x.data.lavandaria.movimento && <Descriptions.Item label="Recibos da lavandaria">{`${x.data.lavandaria.numero_recibos} · ${formatarKz(x.data.lavandaria.total_recibos)} Kz`}</Descriptions.Item>}
              </Descriptions>
              <Flex gap={16} wrap style={{ marginTop: 8 }}>
                <Statistic title="Esperado" value={formatarKz(x.data.numerario_esperado)} suffix="Kz" />
                <Statistic title="Contado" value={formatarCentimos(contado)} suffix="Kz" />
                <Statistic
                  title="Desvio"
                  value={formatarCentimos(avaliacao.desvio)}
                  suffix="Kz"
                  valueStyle={{ color: avaliacao.desvio < 0 ? '#cf1322' : avaliacao.desvio > 0 ? '#3f8600' : undefined }}
                />
              </Flex>
              <Typography.Text type="secondary">
                {tolerancia !== undefined ? `Tolerância: ${formatarKz(tolerancia)} Kz. ` : ''}
                {avaliacao.desvio === 0 ? 'Sem desvio.' : avaliacao.acimaTolerancia ? 'Acima da tolerância: fica por deliberar.' : 'Dentro da tolerância: regularização automática.'}
              </Typography.Text>
            </Card>

            {tpas.length > 0 && (
              <Card size="small" title="Talões de fecho dos TPA" style={{ marginTop: 12 }}>
                {tpas.map((m) => {
                  const t = taloes[m.meio_id!] ?? {};
                  const alterar = (a: Partial<TalaoTPA>) => setTaloes((v) => ({ ...v, [m.meio_id!]: { ...t, ...a } }));
                  return (
                    <div key={m.meio_id} style={{ marginBottom: 12 }}>
                      <Typography.Text strong>
                        {m.nome}
                        {m.codigo_tpa ? ` (${m.codigo_tpa})` : ''}
                      </Typography.Text>
                      <Typography.Text type="secondary"> · sistema {formatarKz(m.valor)} Kz em {m.quantidade} operação(ões)</Typography.Text>
                      <Flex gap={8} wrap style={{ marginTop: 4 }}>
                        <InputNumber<number> aria-label={`Valor do talão ${m.nome}`} placeholder="Valor do talão" min={0} precision={2} decimalSeparator="," style={{ width: 160, maxWidth: '100%' }} value={t.valor_talao ?? null} onChange={(v) => alterar({ valor_talao: v })} />
                        <InputNumber<number> placeholder="Operações" min={0} precision={0} style={{ width: 110 }} value={t.operacoes_talao ?? null} onChange={(v) => alterar({ operacoes_talao: v })} />
                        <Input placeholder="Lote" maxLength={60} style={{ width: 120 }} value={t.referencia_lote} onChange={(e) => alterar({ referencia_lote: e.target.value })} />
                      </Flex>
                    </div>
                  );
                })}
              </Card>
            )}

            <Card size="small" title="Justificação" style={{ marginTop: 12 }}>
              {avaliacao.exigeJustificacao && (
                <Alert
                  type="warning"
                  showIcon
                  style={{ marginBottom: 8 }}
                  message={avaliacao.acimaTolerancia ? 'O desvio excede a tolerância: a justificação é obrigatória.' : 'Um talão TPA difere do sistema: a justificação é obrigatória.'}
                />
              )}
              <Input.TextArea rows={3} maxLength={2000} value={justificacao} onChange={(e) => setJustificacao(e.target.value)} placeholder="Explique o desvio ou a diferença dos talões" />
            </Card>
          </Col>
          <Col xs={24}>
            <Divider orientation="left" plain>
              Totais por meio de pagamento
            </Divider>
            <TabelaMeios linhas={x.data.totais_por_metodo} />
          </Col>
        </Row>
      )}
    </Modal>
  );
}
