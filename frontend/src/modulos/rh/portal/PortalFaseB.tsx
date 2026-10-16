import { Alert, Button, Card, Col, DatePicker, Descriptions, Empty, Form, Input, InputNumber, Row, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { EstadoTag } from '../comum/componentes';
import { useAccaoRh, useAvisarErro } from '../comum/consultas';
import { TabelaPlano } from '../faseB/TabelaPlano';
import { DIAS_CURTOS, planoEmprestimo, textoHorario } from '../faseB/regras';
import type { LimitesEmprestimo, MeuContrato, MinhaEscala, Emprestimo } from '../faseB/tipos';

/**
 * Portal do Colaborador — separadores novos da Versão Actual (portal_ui.js:398-620): «A minha escala» (só consulta; a
 * prioritária é a que vale), «O meu contrato» (o vigente, mesmo suspenso ou cessado) e «Empréstimos» (pedir com simulação
 * ao vivo, acompanhar e ver o plano). Os dados vêm só do colaborador ligado ao utilizador (o servidor recusa os outros).
 */
const h2 = (v: number | null | undefined) => String(Math.round(Number(v ?? 0) * 100) / 100).replace('.', ',');

export function PortalEscala() {
  const [mes, setMes] = useState<Dayjs>(dayjs().startOf('month'));
  const q = useQuery({ queryKey: ['rh', 'portal', 'escala', mes.format('YYYY-MM')], queryFn: () => obter<MinhaEscala>('/rh/portal/escala', { mes: mes.format('YYYY-MM') }) });
  useAvisarErro(q.error);
  const e = q.data;
  return (
    <Card title="A minha escala" loading={q.isLoading} extra={<DatePicker picker="month" format="MM/YYYY" allowClear={false} value={mes} onChange={(v) => v && setMes(v)} aria-label="Mês" />}>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Mostra o que está escalado para si. Se houver uma escala prioritária, é essa que vale nesse dia. Para alterações, fale com a sua chefia." />
      {e && (
        <>
          {/* antigo (portal_ui.js:530): três caixas em grelha de 130 px */}
          <GrelhaKpi larguraMinima={130} style={{ marginBottom: 12 }}>
            <CartaoKpi barra="nenhuma" rotulo="Dias de trabalho" valor={e.dias_trabalho} />
            <CartaoKpi barra="nenhuma" rotulo="Folgas" valor={e.folgas} />
            <CartaoKpi barra="nenhuma" rotulo="Horas previstas" valor={h2(e.horas)} />
          </GrelhaKpi>
          {e.dias.length === 0 ? (
            <Empty description={`Ainda não há escala publicada para ${mes.format('MM/YYYY')}.${e.horario_contrato ? ` O seu horário de contrato é ${textoHorario(e.horario_contrato)}.` : ''}`} />
          ) : (
            <Table size="small" rowKey="dia" pagination={false} dataSource={e.dias} scroll={{ x: true }}
              rowClassName={(d) => (d.prioritaria ? 'erp-linha-destaque' : '')}
              columns={[
                { title: 'Dia', dataIndex: 'dia', render: (d: string) => <span><strong>{Number(d.slice(8))}</strong> <Typography.Text type="secondary">{DIAS_CURTOS[dayjs(d).day()]}</Typography.Text></span> },
                { title: 'Turno', render: (_, d) => (d.folga ? <Tag>Folga</Tag> : textoHorario({ ...d, intervalo_min: d.intervalo_minutos })) },
                { title: 'Regime', render: (_, d) => (d.folga ? '—' : d.periodos === 2 ? '4 picagens' : '2 picagens') },
                { title: 'Horas', dataIndex: 'horas', align: 'right', render: h2 },
                { title: '', render: (_, d) => <Space size={4}>{d.prioritaria && <Tag color="gold">Prioritária</Tag>}{d.nota && <Typography.Text type="secondary">{d.nota}</Typography.Text>}</Space> },
              ]} />
          )}
        </>
      )}
    </Card>
  );
}

export function PortalContrato() {
  const q = useQuery({ queryKey: ['rh', 'portal', 'contrato'], queryFn: () => obter<MeuContrato>('/rh/portal/contrato') });
  useAvisarErro(q.error);
  const c = q.data?.contrato;
  if (q.isLoading) return <Card loading />;
  if (!c) return <Card title="O meu contrato"><Empty description="Não há contrato registado em seu nome. Fale com o RH." /></Card>;
  return (
    <Space direction="vertical" style={{ width: '100%' }} size="middle">
      <Card title="O meu contrato">
        <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Dados do seu contrato, só para consulta. Qualquer correcção faz-se pelo RH." />
        <Descriptions size="small" bordered column={{ xs: 1, md: 2 }}>
          <Descriptions.Item label="Início do vínculo">{formatarData(c.data_inicio)}</Descriptions.Item>
          <Descriptions.Item label="Fim do vínculo">{c.data_fim ? formatarData(c.data_fim) : 'Sem termo previsto'}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={c.estado} /></Descriptions.Item>
          <Descriptions.Item label="Dias contratados por mês">{c.dias_contrato_mes ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Horas por dia">{h2(c.horas_por_dia)}</Descriptions.Item>
          <Descriptions.Item label="Moeda">{c.codigo_moeda}</Descriptions.Item>
          {c.suspensao && <Descriptions.Item label="Suspensão" span={2}>{c.suspensao.motivo} ({c.suspensao.artigo}) — desde {formatarData(c.suspensao.de)}{c.suspensao.ate ? ` até ${formatarData(c.suspensao.ate)}` : ''}</Descriptions.Item>}
          {c.cessacao && <Descriptions.Item label="Cessação" span={2}>{formatarData(c.cessacao.data)} — {c.cessacao.modalidade}: {c.cessacao.motivo} ({c.cessacao.artigo})</Descriptions.Item>}
        </Descriptions>
      </Card>
      {c.horario && (
        <Card title="Horário de trabalho">
          <Descriptions size="small" bordered column={{ xs: 1, md: 2 }}>
            <Descriptions.Item label="Regime">{c.horario.periodos === 2 ? 'Dois períodos — 4 picagens' : 'Período corrido — 2 picagens'}</Descriptions.Item>
            <Descriptions.Item label="Horário">{textoHorario(c.horario)}</Descriptions.Item>
            <Descriptions.Item label="Tolerância de entrada">{c.horario.tolerancia_min ?? 0} min</Descriptions.Item>
            <Descriptions.Item label="Dias da semana">{c.horario.dias?.length ? c.horario.dias.map((d) => DIAS_CURTOS[d]).join(' · ') : 'Dias úteis da empresa'}</Descriptions.Item>
          </Descriptions>
        </Card>
      )}
      <Card title="Remunerações contratualizadas">
        <Table size="small" rowKey="infotipo_id" pagination={false} dataSource={c.remuneracoes}
          columns={[{ title: 'Rubrica', dataIndex: 'nome' }, { title: 'Valor mensal', dataIndex: 'valor_mes', align: 'right', render: (v: string) => formatarKz(v, true) }]}
          summary={() => <Table.Summary.Row><Table.Summary.Cell index={0}><strong>Total ilíquido contratado</strong></Table.Summary.Cell><Table.Summary.Cell index={1} align="right"><strong>{formatarKz(c.total_mensal, true)}</strong></Table.Summary.Cell></Table.Summary.Row>} />
        <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>Base para faltas e horas extra: {formatarKz(c.valor_dia, true)} por dia · {formatarKz(c.valor_hora, true)} por hora.</Typography.Paragraph>
      </Card>
      {c.produtividade.length > 0 && (
        <Card title="Itens de produtividade">
          <Table size="small" rowKey="item_id" pagination={false} dataSource={c.produtividade} columns={[
            { title: 'Item', render: (_, p) => `${p.codigo ?? ''} — ${p.descricao ?? `Item #${p.item_id}`}` },
            { title: 'Unidade', dataIndex: 'unidade' },
            { title: 'Preço unitário', align: 'right', render: (_, p) => `${formatarKz(p.preco_unitario, true)}${p.preco_proprio ? ' (preço próprio)' : ''}` },
          ]} />
        </Card>
      )}
    </Space>
  );
}

interface ValoresPedido { capital?: number; prestacoes?: number; inicio?: Dayjs; motivo?: string }

export function PortalEmprestimos() {
  const q = useQuery({ queryKey: ['rh', 'portal', 'emprestimos'], queryFn: () => obter<{ limites: LimitesEmprestimo; emprestimos: Emprestimo[] }>('/rh/portal/emprestimos') });
  useAvisarErro(q.error);
  const [form] = Form.useForm<ValoresPedido>();
  const accao = useAccaoRh(() => form.resetFields());
  const v = Form.useWatch([], form) as ValoresPedido | undefined;
  if (q.isLoading || !q.data) return <Card loading={q.isLoading} />;
  const { limites, emprestimos } = q.data;
  const tecto = limites.tecto ? Number(limites.tecto) : 0;
  const taxa = Number(limites.taxa_juro);
  const sim = v?.capital && v.prestacoes && v.inicio ? planoEmprestimo(v.capital, v.prestacoes, taxa, v.inicio.format('YYYY-MM')) : null;
  const podePedir = limites.por_configurar.length === 0 && !limites.em_curso;
  return (
    <Space direction="vertical" style={{ width: '100%' }} size="middle">
      {limites.por_configurar.length > 0 && <Alert type="warning" showIcon message={`Os empréstimos ainda não estão configurados pelo RH (falta ${limites.por_configurar.join(', ')}). Não é possível pedir de momento.`} />}
      {limites.por_configurar.length === 0 && limites.em_curso && <Alert type="info" showIcon message={`Tem o empréstimo ${limites.em_curso} em curso. Só pode pedir outro depois de o liquidar.`} />}
      {podePedir && (
        <Card title="Pedir empréstimo">
          <Typography.Paragraph type="secondary">
            Máximo {limites.prestacoes_max} prestações{tecto ? ` · até ${formatarKz(tecto, true)} (${Number(limites.multiplo_salario_max)}× o salário base)` : ''}{taxa > 0 ? ` · juro ${taxa} %/ano` : ' · sem juros'}.
            O pedido segue para aprovação{limites.exige_chefia ? ' (chefia e RH)' : ' (RH)'} e, aprovado, o desconto é feito no salário segundo o plano.
          </Typography.Paragraph>
          <Form form={form} layout="vertical" initialValues={{ prestacoes: Math.min(6, limites.prestacoes_max), inicio: dayjs().add(1, 'month').startOf('month') }}
            onFinish={(x) => accao.mutate({ metodo: 'post', url: '/rh/portal/pedidos', dados: { tipo: 'EMPRESTIMO', capital: x.capital, prestacoes: x.prestacoes, inicio_desconto: x.inicio?.format('YYYY-MM'), motivo: x.motivo } })}>
            <Row gutter={12}>
              <Col xs={24} md={8}><Form.Item name="capital" label="Valor pretendido" rules={[{ required: true, message: 'Indique o valor.' }]}><InputNumber min={1} precision={2} decimalSeparator="," style={{ width: '100%' }} suffix="Kz" /></Form.Item></Col>
              <Col xs={12} md={8}><Form.Item name="prestacoes" label="N.º de prestações" rules={[{ required: true }]}><InputNumber min={1} max={limites.prestacoes_max} style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={12} md={8}><Form.Item name="inicio" label="Início do desconto" rules={[{ required: true }]}><DatePicker picker="month" format="MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isBefore(dayjs().startOf('month'))} /></Form.Item></Col>
            </Row>
            <Form.Item name="motivo" label="Motivo do pedido" rules={[{ required: true, min: 5, message: 'Explique brevemente (mínimo 5 caracteres).' }]}><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
            {sim && (
              <Typography.Paragraph>
                Prestação mensal de <strong>{formatarKz(sim.prestacao, true)}</strong>, de {sim.linhas[0].mes_ano} a {sim.linhas[sim.linhas.length - 1].mes_ano}
                {Number(sim.total_juros) > 0 ? ` · juros ${formatarKz(sim.total_juros)} · total ${formatarKz(sim.total)}` : ''}
                {tecto && (v?.capital ?? 0) > tecto ? <Typography.Text type="danger"> — acima do limite de {formatarKz(tecto, true)}</Typography.Text> : null}
              </Typography.Paragraph>
            )}
            <Button type="primary" htmlType="submit" loading={accao.isPending}>Submeter pedido</Button>
          </Form>
        </Card>
      )}
      <Card title="Os meus empréstimos">
        {emprestimos.length === 0 ? <Empty description="Ainda não pediu nenhum empréstimo." /> : emprestimos.map((e) => (
          <Card key={e.id} type="inner" size="small" style={{ marginBottom: 12 }}
            title={<Space wrap><strong>{e.numero}</strong>{formatarKz(e.capital, true)} em {e.prestacoes} prestações de {formatarKz(e.valor_prestacao, true)}</Space>}
            extra={<EstadoTag estado={e.estado} />}>
            <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>
              Início do desconto: {e.inicio_desconto}{Number(e.taxa_juro) > 0 ? ` · juro ${Number(e.taxa_juro)} %/ano` : ' · sem juros'} · Motivo: {e.motivo ?? '—'}
              {e.estado === 'APROVADO' ? ` · Em dívida: ${formatarKz(e.em_divida, true)}` : ''}
            </Typography.Paragraph>
            {(e.plano ?? []).length ? <TabelaPlano plano={e.plano ?? []} comJuro={Number(e.taxa_juro) > 0} />
              : <Typography.Text type="secondary">O plano de amortização é gerado quando o pedido for aprovado.</Typography.Text>}
          </Card>
        ))}
      </Card>
    </Space>
  );
}
