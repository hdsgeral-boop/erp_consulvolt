import { Alert, Card, DatePicker, Flex, Select, Skeleton, Table, Typography, theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { GraficoBarras } from '@/componentes/graficos/Graficos';
import { scrollTabela } from '@/componentes/responsivo';
import { dataApi, formatarKz } from '@/utilitarios/formatacao';
import { useFunis } from './comum/dados';
import { GrelhaKpiCrm, KpiCrm } from './comum/componentes';
import { linhasMotivosPerda, previsaoPorResponsavel, rotuloMesPrevisao, totaisPrevisao } from './comum/previsao';
import type { IndicadoresCRM, PrevisaoCRM } from './comum/previsao';

const H4: CSSProperties = { margin: '0 0 0.4rem', fontSize: '0.92rem' };
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${Math.round(v)} %`);

function BarraConversao({ taxa }: { taxa: number | null }) {
  const { token } = theme.useToken();
  return (
    <div style={{ height: 8, background: token.colorFillSecondary, borderRadius: 4, overflow: 'hidden', minWidth: 80 }} aria-hidden>
      <div style={{ width: `${Math.min(100, Math.max(0, taxa ?? 0))}%`, height: '100%', background: '#1d4ed8' }} />
    </div>
  );
}

function Bloco({ titulo, children }: { titulo: ReactNode; children: ReactNode }) {
  return (
    <Card size="small" styles={{ body: { padding: '0.7rem' } }}>
      <h4 style={H4}>{titulo}</h4>
      {children}
    </Card>
  );
}

/**
 * CRM › Previsão (crm_previsao) — página única do antigo (crm_ui_gestao.js:224-296): filtros Pipeline / Horizonte /
 * Indicadores de … até, seis indicadores e os cinco blocos (Receita prevista por mês de fecho, Pipeline por etapa, Taxa de
 * conversão entre etapas, Motivos de perda, Previsão por responsável).
 */
export default function Previsao() {
  const funis = useFunis();
  const [funil, setFunil] = useState<number | undefined>();
  const [meses, setMeses] = useState(6);
  const [de, setDe] = useState<Dayjs | null>(dayjs().startOf('year'));
  const [ate, setAte] = useState<Dayjs | null>(dayjs());
  // o antigo abre no primeiro pipeline
  useEffect(() => {
    if (funil === undefined && funis.data?.length) setFunil(funis.data[0].id);
  }, [funis.data, funil]);
  const previsao = useQuery({ queryKey: ['crm', 'previsao', funil, meses], queryFn: () => obter<PrevisaoCRM>('/crm/previsao', { funil_vendas_crm_id: funil, meses }), enabled: funil !== undefined || funis.isFetched });
  const ind = useQuery({
    queryKey: ['crm', 'indicadores', funil, dataApi(de), dataApi(ate)],
    queryFn: () => obter<IndicadoresCRM>('/crm/indicadores', { funil_vendas_crm_id: funil, de: dataApi(de), ate: dataApi(ate) }),
    enabled: funil !== undefined || funis.isFetched,
  });
  const p = previsao.data;
  const k = ind.data;
  const ref = useRef<HTMLDivElement>(null);
  const nomeFunil = funis.data?.find((f) => f.id === funil)?.nome ?? k?.funil?.nome;
  const tot = useMemo(() => (p ? totaisPrevisao(p) : null), [p]);
  const porResp = useMemo(() => (p ? previsaoPorResponsavel(p) : []), [p]);
  const motivos = useMemo(() => (k ? linhasMotivosPerda(k) : []), [k]);
  const carregando = previsao.isLoading || ind.isLoading;

  return (
    <>
      <CabecalhoPagina
        titulo="Previsão de vendas e indicadores"
        subtitulo="Receita previsível = valor × probabilidade da etapa, no mês de fecho previsto. Indicadores de conversão, ciclo de venda, valor do pipeline e motivos de perda."
        impressaoDesactivada={!p || !k}
        impressao={() =>
          ref.current
            ? {
                titulo: 'Previsão de vendas e indicadores',
                filtros: [nomeFunil && `Pipeline: ${nomeFunil}`, `Horizonte: ${meses} meses`, `Indicadores de ${de?.format('DD/MM/YYYY') ?? '…'} até ${ate?.format('DD/MM/YYYY') ?? '…'}`],
                conteudo: ref.current,
                orientacao: 'paisagem',
              }
            : null
        }
      />
      <Flex gap={12} wrap align="end" style={{ marginBottom: 12 }} className="imp-nao-imprimir">
        <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span>Pipeline</span>
          <Select style={{ width: 220, maxWidth: '100%' }} value={funil} onChange={setFunil} loading={funis.isLoading} options={(funis.data ?? []).map((f) => ({ value: f.id, label: f.nome }))} />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span>Horizonte</span>
          <Select style={{ width: 130 }} value={meses} onChange={setMeses} options={[3, 6, 12].map((m) => ({ value: m, label: `${m} meses` }))} />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span>Indicadores de</span>
          <DatePicker format="DD/MM/YYYY" value={de} onChange={setDe} />
        </label>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <span>até</span>
          <DatePicker format="DD/MM/YYYY" value={ate} onChange={setAte} />
        </label>
      </Flex>

      {carregando ? (
        <Skeleton active />
      ) : !p || !k || !tot ? (
        <Alert type="error" showIcon message="Não foi possível calcular a previsão." />
      ) : (
        <div ref={ref}>
          {/* seis indicadores .crm-kpi do antigo (crm_ui_gestao.js:260-267): caixas simples, sem ícone nem barra */}
          <GrelhaKpiCrm>
            <KpiCrm rotulo="Valor do pipeline (em aberto)" valor={formatarKz(k.valor_funil)} secundaria={`ponderado ${formatarKz(k.ponderado)}`} />
            <KpiCrm
              rotulo={`Receita prevista · ${meses} meses`}
              valor={formatarKz(tot.ponderado)}
              secundaria={p.atrasadas.n ? `+ ${formatarKz(p.atrasadas.ponderado)} com fecho já ultrapassado (${p.atrasadas.n})` : 'ponderada pela probabilidade'}
            />
            <KpiCrm rotulo="Taxa de ganho" valor={pct(k.taxa_ganho)} secundaria={`${k.ganhas} ganhas · ${k.perdidas} perdidas no período`} />
            <KpiCrm rotulo="Conversão lead → ganho" valor={pct(k.conversao_lead_ganho)} secundaria={`das ${k.criadas} criadas no período`} />
            <KpiCrm rotulo="Ciclo médio de venda" valor={k.ciclo_medio === null ? '—' : `${Math.round(k.ciclo_medio)} dias`} secundaria={k.ciclo_mediana === null ? 'sem negócios ganhos' : `mediana ${k.ciclo_mediana} dias`} />
            <KpiCrm rotulo="Ticket médio (ganhos)" valor={k.ticket_medio === null ? '—' : formatarKz(k.ticket_medio)} secundaria={`valor ganho ${formatarKz(k.valor_ganho)}`} />
          </GrelhaKpiCrm>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(420px, 100%), 1fr))', gap: '0.75rem' }}>
            <Bloco titulo="Receita prevista por mês de fecho">
              <GraficoBarras
                monetario
                altura={250}
                rotulos={p.por_mes.map((m) => rotuloMesPrevisao(m.mes))}
                series={[
                  { rotulo: 'Valor bruto', valores: p.por_mes.map((m) => m.bruto), cor: '#cbd5e1' },
                  { rotulo: 'Ponderado', valores: p.por_mes.map((m) => m.ponderado), cor: '#1d4ed8' },
                ]}
              />
              <Table
                size="small"
                style={{ marginTop: 8 }}
                rowKey="mes"
                pagination={false}
                scroll={scrollTabela()}
                dataSource={p.por_mes}
                columns={[
                  { title: 'Mês', dataIndex: 'mes', render: rotuloMesPrevisao },
                  { title: 'Negócios', dataIndex: 'n', align: 'right' },
                  { title: 'Valor bruto', dataIndex: 'bruto', align: 'right', render: (v: string) => formatarKz(v) },
                  { title: 'Ponderado', dataIndex: 'ponderado', align: 'right', render: (v: string) => <b>{formatarKz(v)}</b> },
                  { title: 'Compromisso (≥75%)', dataIndex: 'compromisso', align: 'right', render: (v: string) => formatarKz(v) },
                ]}
                summary={() => (
                  <Table.Summary.Row>
                    <Table.Summary.Cell index={0}>Total</Table.Summary.Cell>
                    <Table.Summary.Cell index={1} align="right">{tot.n}</Table.Summary.Cell>
                    <Table.Summary.Cell index={2} align="right">{formatarKz(tot.bruto)}</Table.Summary.Cell>
                    <Table.Summary.Cell index={3} align="right">{formatarKz(tot.ponderado)}</Table.Summary.Cell>
                    <Table.Summary.Cell index={4} align="right">{formatarKz(tot.compromisso)}</Table.Summary.Cell>
                  </Table.Summary.Row>
                )}
              />
            </Bloco>

            <Bloco titulo="Pipeline por etapa">
              <Table
                size="small"
                rowKey="etapa"
                pagination={false}
                scroll={scrollTabela()}
                dataSource={k.por_etapa}
                columns={[
                  {
                    title: 'Etapa', dataIndex: 'etapa',
                    render: (v: string, e) => (
                      <span>
                        <span aria-hidden style={{ display: 'inline-block', width: 10, height: 10, borderRadius: 2, background: e.cor ?? '#94a3b8', marginRight: 5 }} />
                        {v}
                      </span>
                    ),
                  },
                  { title: 'Negócios', dataIndex: 'n', align: 'right' },
                  { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v) },
                  { title: 'Ponderado', dataIndex: 'ponderado', align: 'right', render: (v: string) => formatarKz(v) },
                ]}
              />
              <h4 style={{ ...H4, marginTop: '0.8rem' }}>Taxa de conversão entre etapas</h4>
              <Table
                size="small"
                rowKey={(c) => `${c.etapa}→${c.seguinte}`}
                pagination={false}
                scroll={scrollTabela()}
                dataSource={k.conversao}
                columns={[
                  { title: 'De → para', key: 'de', render: (_, c) => `${c.etapa} → ${c.seguinte}` },
                  { title: 'Entraram', dataIndex: 'entraram', align: 'right' },
                  { title: 'Avançaram', dataIndex: 'avancaram', align: 'right' },
                  { title: 'Taxa', dataIndex: 'taxa', align: 'right', render: (v: number | null) => pct(v) },
                  {
                    title: '', key: 'barra', width: 110,
                    render: (_, c) => <BarraConversao taxa={c.taxa} />,
                  },
                ]}
              />
              <Typography.Paragraph type="secondary" style={{ fontSize: '0.75rem', margin: '0.3rem 0 0' }}>
                Negócios criados no período que chegaram a cada etapa e quantos avançaram para a seguinte.
              </Typography.Paragraph>
            </Bloco>

            <Bloco titulo="Motivos de perda">
              {motivos.length ? (
                <>
                  <GraficoBarras monetario horizontal altura={220} rotulos={motivos.map((m) => m.motivo)} series={[{ rotulo: 'Valor perdido', valores: motivos.map((m) => m.valor), cor: '#dc2626' }]} />
                  <Table
                    size="small"
                    style={{ marginTop: 8 }}
                    rowKey="motivo"
                    pagination={false}
                    scroll={scrollTabela()}
                    dataSource={motivos}
                    columns={[
                      { title: 'Motivo', dataIndex: 'motivo' },
                      { title: 'Negócios', dataIndex: 'n', align: 'right' },
                      { title: 'Valor perdido', dataIndex: 'valor', align: 'right', render: (v: string) => formatarKz(v) },
                      { title: '% do valor', dataIndex: 'percentagem', align: 'right', render: (v: number | null) => pct(v) },
                    ]}
                  />
                </>
              ) : (
                <Typography.Text type="secondary">Sem negócios perdidos no período.</Typography.Text>
              )}
            </Bloco>

            <Bloco titulo="Previsão por responsável">
              <Table
                size="small"
                rowKey="responsavel"
                pagination={false}
                scroll={scrollTabela()}
                dataSource={porResp}
                locale={{ emptyText: 'Sem negócios em aberto.' }}
                columns={[
                  { title: 'Responsável', dataIndex: 'responsavel' },
                  { title: 'Negócios', dataIndex: 'n', align: 'right' },
                  { title: 'Pipeline', dataIndex: 'bruto', align: 'right', render: (v: string) => formatarKz(v) },
                  { title: 'Ponderado', dataIndex: 'ponderado', align: 'right', render: (v: string) => formatarKz(v) },
                ]}
              />
            </Bloco>
          </div>
        </div>
      )}
    </>
  );
}
