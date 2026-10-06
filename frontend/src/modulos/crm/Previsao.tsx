import { Alert, Card, Col, DatePicker, Flex, Input, InputNumber, Row, Select, Skeleton, Space, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useRef, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { dataApi, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { GraficoBarras, GraficoDonut } from '@/componentes/graficos/Graficos';
import { CartaoKpi } from '@/modulos/geral/comum/componentes';
import { useFunis } from './comum/dados';
import { scrollTabela } from '@/componentes/responsivo';

interface Previsao {
  por_mes: { mes: string; bruto: string; ponderado: string; n: number; compromisso: string }[];
  atrasadas: { bruto: string; ponderado: string; n: number };
  por_responsavel: { responsavel: string; bruto: string; ponderado: string; n: number }[];
  abertas: number;
}

interface Indicadores {
  funil: { id: number; nome: string } | null;
  criadas: number;
  ganhas: number;
  perdidas: number;
  valor_ganho: string;
  valor_perdido: string;
  taxa_ganho: number | null;
  conversao_lead_ganho: number | null;
  conversao: { etapa: string; seguinte: string; entraram: number; avancaram: number; taxa: number | null }[];
  ciclo_medio: number | null;
  ciclo_mediana: number | null;
  valor_funil: string;
  ponderado: string;
  por_etapa: { etapa: string; cor: string | null; n: number; valor: string; ponderado: string }[];
  motivos: { motivo: string; n: number; valor: string }[];
  ticket_medio: string | null;
}

const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
const rotuloMes = (m: string) => `${MESES[Number(m.slice(5, 7)) - 1] ?? m}/${m.slice(2, 4)}`;

/** CRM › Previsão e indicadores (crm_previsao): previsão por mês (bruto, ponderado, compromisso) e indicadores do funil. */
export default function Previsao() {
  const funis = useFunis();
  const [funil, setFunil] = useState<number | undefined>();
  const [meses, setMeses] = useState(6);
  const [responsavel, setResponsavel] = useState('');
  const [datas, setDatas] = useState<[Dayjs | null, Dayjs | null] | null>([dayjs().startOf('year'), dayjs()]);
  const previsao = useQuery({ queryKey: ['crm', 'previsao', funil, meses, responsavel], queryFn: () => obter<Previsao>('/crm/previsao', { funil_vendas_crm_id: funil, meses, responsavel: responsavel || undefined }) });
  const ind = useQuery({ queryKey: ['crm', 'indicadores', funil, datas?.[0]?.valueOf(), datas?.[1]?.valueOf()], queryFn: () => obter<Indicadores>('/crm/indicadores', { funil_vendas_crm_id: funil, de: dataApi(datas?.[0]), ate: dataApi(datas?.[1]) }) });
  const p = previsao.data;
  const i = ind.data;
  const ref = useRef<HTMLDivElement>(null);
  const nomeFunil = funis.data?.find((f) => f.id === funil)?.nome;

  return (
    <>
      <CabecalhoPagina
        titulo="Previsão de vendas e indicadores"
        subtitulo="Quanto deve entrar nos próximos meses e como está a correr o funil"
        impressaoDesactivada={!p && !i}
        impressao={() =>
          ref.current
            ? {
                titulo: 'Previsão de vendas e indicadores do funil',
                filtros: [nomeFunil ? `Funil: ${nomeFunil}` : 'Todos os funis', `Previsão a ${meses} mes(es)`, responsavel && `Responsável: ${responsavel}`, `Indicadores de ${datas?.[0]?.format('DD/MM/YYYY') ?? '…'} a ${datas?.[1]?.format('DD/MM/YYYY') ?? '…'}`],
                conteudo: ref.current,
              }
            : null
        }
      />
      <Card size="small" style={{ marginBottom: 12 }} className="imp-nao-imprimir">
        <Flex gap={8} wrap align="center">
          <Select allowClear placeholder="Todos os funis" style={{ width: 200, maxWidth: '100%' }} value={funil} onChange={setFunil} options={(funis.data ?? []).map((f) => ({ value: f.id, label: f.nome }))} />
          <span>Previsão a</span>
          <InputNumber min={1} max={24} value={meses} onChange={(v) => setMeses(v ?? 6)} style={{ width: 70 }} />
          <span>meses</span>
          <Input.Search placeholder="Responsável" allowClear style={{ width: 160, maxWidth: '100%' }} onSearch={setResponsavel} />
          <span>Indicadores de</span>
          <DatePicker.RangePicker format="DD/MM/YYYY" allowEmpty={[true, true]} value={datas} onChange={setDatas} />
        </Flex>
      </Card>

      <div ref={ref}>
      <Typography.Title level={4}>Previsão</Typography.Title>
      {previsao.isLoading ? (
        <Skeleton active />
      ) : previsao.error || !p ? (
        <Alert type="error" showIcon message="Não foi possível carregar a previsão." />
      ) : (
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
          <Row gutter={[12, 12]}>
            <Col xs={12} md={6}><CartaoKpi rotulo="Oportunidades abertas" valor={p.abertas} formato="num" /></Col>
            <Col xs={12} md={6}><CartaoKpi rotulo="Ponderado no período" valor={p.por_mes.reduce((s, m) => s + Number(m.ponderado), 0).toFixed(2)} formato="kz" /></Col>
            <Col xs={12} md={6}><CartaoKpi rotulo="Compromisso (prob. ≥ 75%)" valor={p.por_mes.reduce((s, m) => s + Number(m.compromisso), 0).toFixed(2)} formato="kz" /></Col>
            <Col xs={12} md={6}><CartaoKpi rotulo="Com fecho ultrapassado" valor={p.atrasadas.bruto} formato="kz" subtitulo={`${p.atrasadas.n} oportunidade(s) · pond. ${formatarKz(p.atrasadas.ponderado)}`} alerta={p.atrasadas.n > 0} /></Col>
          </Row>
          <Card size="small">
            <GraficoBarras
              titulo="Previsão por mês de fecho"
              monetario
              rotulos={p.por_mes.map((m) => rotuloMes(m.mes))}
              series={[
                { rotulo: 'Valor bruto', valores: p.por_mes.map((m) => m.bruto), cor: '#93c5fd' },
                { rotulo: 'Ponderado', valores: p.por_mes.map((m) => m.ponderado), cor: '#1d4ed8' },
                { rotulo: 'Compromisso', valores: p.por_mes.map((m) => m.compromisso) },
              ]}
            />
          </Card>
          <Card size="small" title="Por responsável">
            <Table scroll={scrollTabela()}
              size="small"
              rowKey="responsavel"
              pagination={false}
              dataSource={p.por_responsavel}
              columns={[
                { title: 'Responsável', dataIndex: 'responsavel' },
                { title: 'Oportunidades', dataIndex: 'n', align: 'right' },
                { title: 'Bruto', dataIndex: 'bruto', align: 'right', render: (v: string) => formatarKz(v) },
                { title: 'Ponderado', dataIndex: 'ponderado', align: 'right', render: (v: string) => formatarKz(v) },
              ]}
            />
          </Card>
        </Space>
      )}

      <Typography.Title level={4} style={{ marginTop: 24 }}>Indicadores{i?.funil ? ` — ${i.funil.nome}` : ''}</Typography.Title>
      {ind.isLoading ? (
        <Skeleton active />
      ) : !i ? (
        <Alert type="error" showIcon message="Não foi possível carregar os indicadores." />
      ) : (
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
          <Row gutter={[12, 12]}>
            <Col xs={12} md={6}><CartaoKpi rotulo="Criadas" valor={i.criadas} formato="num" subtitulo={`${i.ganhas} ganhas · ${i.perdidas} perdidas`} /></Col>
            <Col xs={12} md={6}><CartaoKpi rotulo="Taxa de ganho" valor={i.taxa_ganho} formato="pct" subtitulo={`Lead → ganho: ${i.conversao_lead_ganho === null ? '—' : `${formatarNumero(i.conversao_lead_ganho)}%`}`} /></Col>
            <Col xs={12} md={6}><CartaoKpi rotulo="Valor ganho" valor={i.valor_ganho} formato="kz" subtitulo={`Ticket médio ${formatarKz(i.ticket_medio)}`} /></Col>
            <Col xs={12} md={6}><CartaoKpi rotulo="Ciclo de venda" valor={i.ciclo_medio} formato="dias" subtitulo={`Mediana ${formatarNumero(i.ciclo_mediana)} dias`} /></Col>
          </Row>
          <Row gutter={[12, 12]}>
            <Col xs={24} xl={12}>
              <Card size="small" style={{ height: '100%' }}>
                <GraficoBarras titulo="Valor em funil por etapa" monetario horizontal rotulos={i.por_etapa.map((e) => e.etapa)} series={[{ rotulo: 'Valor', valores: i.por_etapa.map((e) => e.valor) }, { rotulo: 'Ponderado', valores: i.por_etapa.map((e) => e.ponderado) }]} />
              </Card>
            </Col>
            <Col xs={24} xl={12}>
              <Card size="small" style={{ height: '100%' }}>
                {i.motivos.length ? (
                  <GraficoDonut titulo="Motivos de perda (valor)" monetario rotulos={i.motivos.map((m) => `${m.motivo} (${m.n})`)} series={[{ rotulo: 'Valor perdido', valores: i.motivos.map((m) => m.valor) }]} />
                ) : (
                  <Typography.Text type="secondary">Sem oportunidades perdidas no período.</Typography.Text>
                )}
              </Card>
            </Col>
          </Row>
          <Card size="small" title="Conversão entre etapas">
            <Table scroll={scrollTabela()}
              size="small"
              rowKey="etapa"
              pagination={false}
              dataSource={i.conversao}
              columns={[
                { title: 'Etapa', dataIndex: 'etapa' },
                { title: 'Seguinte', dataIndex: 'seguinte' },
                { title: 'Entraram', dataIndex: 'entraram', align: 'right' },
                { title: 'Avançaram', dataIndex: 'avancaram', align: 'right' },
                { title: 'Taxa', dataIndex: 'taxa', align: 'right', render: (v: number | null) => (v === null ? '—' : `${formatarNumero(v)}%`) },
              ]}
            />
          </Card>
        </Space>
      )}
      </div>
    </>
  );
}
