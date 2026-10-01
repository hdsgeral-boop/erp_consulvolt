import { Alert, Card, Col, Descriptions, Drawer, Empty, Flex, Row, Segmented, Select, Statistic, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotaoCsv } from '@/modulos/contab/comum/Componentes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaOrc, Kz, SeletorOrcamento, useOrcamentos } from './comum/componentes';
import { MESES, somaValores } from './comum/regras';
import type { Controlo as DadosControlo, Desvio, LinhaControlo } from './comum/tipos';

/** Orçamento › Controlo orçamental (ecrã orc_controlo): orçado vs realizado por rubrica, piores desvios e análise do desvio. */
export default function Controlo() {
  const todos = useOrcamentos({});
  const [orcamento, setOrcamento] = useState<number>();
  const [mes, setMes] = useState(dayjs().month() + 1);
  const [vista, setVista] = useState<'MES' | 'ACUMULADO' | 'ANO'>('ACUMULADO');
  const [desvio, setDesvio] = useState<LinhaControlo | null>(null);

  // por omissão: o aprovado mais recente (ou o primeiro da lista)
  useEffect(() => {
    if (orcamento || !todos.data?.length) return;
    setOrcamento((todos.data.find((o) => o.estado === 'APROVADO') ?? todos.data[0]).id);
  }, [todos.data, orcamento]);

  const q = useQuery({
    queryKey: ['orcamento', 'controlo', orcamento, mes, vista],
    queryFn: () => obter<DadosControlo>(`/orcamento/orcamentos/${orcamento}/controlo`, { mes, vista }),
    enabled: !!orcamento,
  });
  const c = q.data;
  const exploracao = c?.orcamento.tipo === 'EXPLORACAO';
  const pct = (v: number | null) => (v === null ? '—' : `${v.toLocaleString('pt-PT', { maximumFractionDigits: 1 })}%`);

  return (
    <>
      <CabecalhoPagina titulo="Controlo orçamental" subtitulo="Realizado a partir do Diário; desvio = real − orçado (favorável acima do orçado nas entradas e abaixo nas saídas)" />
      <Card style={{ marginBottom: 16 }}>
        <Flex gap={8} wrap align="center">
          <SeletorOrcamento value={orcamento} onChange={setOrcamento} style={{ width: 420 }} />
          <Select value={mes} onChange={setMes} style={{ width: 140 }} options={MESES.map((m, i) => ({ value: i + 1, label: `Até ${m}` }))} />
          <Segmented value={vista} onChange={(v) => setVista(v as typeof vista)} options={[{ value: 'MES', label: 'Mês' }, { value: 'ACUMULADO', label: 'Acumulado' }, { value: 'ANO', label: 'Ano' }]} />
          {c && c.orcamento.estado !== 'APROVADO' && <Tag color="gold">Orçamento {c.orcamento.estado.toLowerCase()}</Tag>}
        </Flex>
      </Card>
      {!orcamento && !todos.isLoading && <Empty description="Não há orçamentos" />}
      {c && (
        <>
          <Row gutter={16} style={{ marginBottom: 16 }}>
            <Col xs={12} md={6}><Card size="small"><Statistic title="Orçado (Kz)" value={formatarKz(c.totais.orcado)} /></Card></Col>
            <Col xs={12} md={6}><Card size="small"><Statistic title="Realizado (Kz)" value={formatarKz(c.totais.realizado)} /></Card></Col>
            {c.saldo_inicial !== undefined && <Col xs={12} md={6}><Card size="small"><Statistic title="Saldo inicial (Kz)" value={formatarKz(c.saldo_inicial)} /></Card></Col>}
            {c.saldos_fim_mes && <Col xs={12} md={6}><Card size="small"><Statistic title={`Saldo no fim de ${MESES[mes - 1]} (Kz)`} value={formatarKz(c.saldos_fim_mes[mes - 1])} /></Card></Col>}
          </Row>
          {c.piores_desvios.length > 0 && (
            <Card size="small" title="Piores desvios" style={{ marginBottom: 16 }}>
              <Flex gap={12} wrap>
                {c.piores_desvios.map((l) => (
                  <Card key={l.rubrica_id} size="small" style={{ minWidth: 220, cursor: exploracao ? 'pointer' : undefined }} onClick={() => exploracao && setDesvio(l)}>
                    <Typography.Text strong>{l.codigo} {l.nome}</Typography.Text>
                    <div><Kz valor={l.desvio} forte /> <Typography.Text type="secondary">({pct(l.desvio_pct)})</Typography.Text></div>
                  </Card>
                ))}
              </Flex>
            </Card>
          )}
          <Card size="small" extra={<BotaoCsv nome={`controlo-orcamental-${c.orcamento.ano}-${mes}`} linhas={c.linhas} colunas={[
            { titulo: 'Código', valor: (l) => l.codigo }, { titulo: 'Rubrica', valor: (l) => l.nome }, { titulo: 'Natureza', valor: (l) => l.natureza }, { titulo: 'Grupo', valor: (l) => l.grupo },
            { titulo: 'Orçado', valor: (l) => l.orcado, numerico: true }, { titulo: 'Orçado inicial', valor: (l) => l.orcado_inicial ?? '', numerico: true },
            { titulo: 'Realizado', valor: (l) => l.realizado, numerico: true }, { titulo: 'Desvio', valor: (l) => l.desvio, numerico: true },
            { titulo: 'Execução %', valor: (l) => l.execucao_pct, numerico: true }, { titulo: 'Favorável', valor: (l) => l.favoravel },
          ]} />}>
            <Table<LinhaControlo>
              rowKey="rubrica_id"
              size="small"
              loading={q.isFetching}
              dataSource={c.linhas}
              pagination={false}
              scroll={{ x: 'max-content' }}
              onRow={(l) => ({ onClick: () => exploracao && setDesvio(l), style: { cursor: exploracao ? 'pointer' : undefined } })}
              columns={[
                { title: 'Rubrica', key: 'r', render: (_, l) => <><strong>{l.codigo}</strong> {l.nome}</> },
                { title: 'Natureza', dataIndex: 'natureza', render: (v) => <EtiquetaOrc valor={v} /> },
                { title: 'Orçado', dataIndex: 'orcado', align: 'right', render: (v) => <Kz valor={v} /> },
                { title: 'Orçado inicial (v1)', dataIndex: 'orcado_inicial', align: 'right', render: (v, l) => (v !== null && v !== l.orcado ? <Kz valor={v} /> : <Typography.Text type="secondary">=</Typography.Text>) },
                { title: 'Realizado', dataIndex: 'realizado', align: 'right', render: (v) => <Kz valor={v} forte /> },
                { title: 'Desvio', dataIndex: 'desvio', align: 'right', render: (v, l) => <Typography.Text type={l.favoravel ? 'success' : 'danger'}>{formatarKz(v)}</Typography.Text> },
                { title: 'Execução', dataIndex: 'execucao_pct', align: 'right', render: pct },
                { title: '', key: 'f', render: (_, l) => <>{l.favoravel ? <Tag color="green">Favorável</Tag> : <Tag color="red">Desfavorável</Tag>}{l.desvio_significativo && <Tag color="volcano">&gt; 10%</Tag>}</> },
              ]}
            />
          </Card>
          {c.sem_rubrica.length > 0 && (
            <Alert style={{ marginTop: 16 }} type="warning" showIcon message={`${c.sem_rubrica.length} conta(s) com movimento sem rubrica`}
              description={c.sem_rubrica.map((s) => `${s.conta}: ${formatarKz(somaValores(s.valores))}`).join(' · ')} />
          )}
        </>
      )}
      <DrawerDesvio orcamentoId={orcamento} linha={desvio} ate={mes} aoFechar={() => setDesvio(null)} />
    </>
  );
}

/** Análise do desvio de uma rubrica (só exploração): mês a mês, classificação, contas e maiores movimentos. */
function DrawerDesvio({ orcamentoId, linha, ate, aoFechar }: { orcamentoId?: number; linha: LinhaControlo | null; ate: number; aoFechar: () => void }) {
  const q = useQuery({
    queryKey: ['orcamento', 'desvio', orcamentoId, linha?.rubrica_id, ate],
    queryFn: () => obter<Desvio>(`/orcamento/orcamentos/${orcamentoId}/desvios/${linha?.rubrica_id}`, { de: 1, ate }),
    enabled: !!linha && !!orcamentoId,
    retry: false,
  });
  const d = q.data;
  return (
    <Drawer title={linha ? `Desvio — ${linha.codigo} ${linha.nome}` : ''} open={!!linha} onClose={aoFechar} width={860} loading={q.isLoading}>
      {q.error && <Alert type="error" showIcon message={(q.error as Error).message} />}
      {d && (
        <>
          <Descriptions size="small" bordered column={3} style={{ marginBottom: 16 }}>
            <Descriptions.Item label="Desvio total"><Kz valor={d.desvio_total} forte /></Descriptions.Item>
            <Descriptions.Item label="Classificação"><EtiquetaOrc valor={d.classificacao} /></Descriptions.Item>
            <Descriptions.Item label="Meses desfavoráveis">{d.desfavoraveis}</Descriptions.Item>
            {d.fecho_estimado && <Descriptions.Item label="Fecho estimado" span={3}><Kz valor={d.fecho_estimado.valor} /> (orçado {formatarKz(d.fecho_estimado.orcado_ano)} · previsão rev. {d.fecho_estimado.revisao} de {d.fecho_estimado.mes_referencia})</Descriptions.Item>}
          </Descriptions>
          <Table size="small" rowKey="mes" pagination={false} dataSource={d.mensal}
            columns={[
              { title: 'Mês', dataIndex: 'mes', render: (m) => MESES[m - 1] },
              { title: 'Orçado', dataIndex: 'orcado', align: 'right', render: (v) => <Kz valor={v} /> },
              { title: 'Real', dataIndex: 'real', align: 'right', render: (v) => <Kz valor={v} /> },
              { title: 'Desvio', dataIndex: 'desvio', align: 'right', render: (v, l) => <Typography.Text type={l.desfavoravel ? 'danger' : undefined}>{formatarKz(v)}</Typography.Text> },
              { title: 'Acumulado', dataIndex: 'acumulado', align: 'right', render: (v) => <Kz valor={v} forte /> },
            ]} />
          {d.contas.length > 0 && (
            <Table style={{ marginTop: 16 }} size="small" rowKey={(x) => String(x.conta)} pagination={false} dataSource={d.contas} title={() => <strong>Contas face ao mesmo período do ano anterior</strong>}
              columns={[
                { title: 'Conta', dataIndex: 'conta' },
                { title: 'Real', dataIndex: 'real', align: 'right', render: (v) => <Kz valor={v} /> },
                { title: 'Ano anterior', dataIndex: 'anterior', align: 'right', render: (v) => <Kz valor={v} /> },
                { title: 'Variação', dataIndex: 'variacao', align: 'right', render: (v) => <Kz valor={v} forte /> },
              ]} />
          )}
          {d.maiores_movimentos.length > 0 && (
            <Table style={{ marginTop: 16 }} size="small" rowKey={(_, i) => String(i)} pagination={false} dataSource={d.maiores_movimentos} title={() => <strong>Maiores movimentos</strong>}
              columns={[
                { title: 'Data', dataIndex: 'data_documento', render: formatarData },
                { title: 'Documento', dataIndex: 'numero_documento', render: (v) => v ?? '—' },
                { title: 'Conta', dataIndex: 'codigo_conta' },
                { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
                { title: 'D/C', dataIndex: 'tipo_dc' },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <Kz valor={v} /> },
              ]} />
          )}
        </>
      )}
    </Drawer>
  );
}
