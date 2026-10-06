import { Alert, Button, Col, DatePicker, Empty, Form, Input, InputNumber, Modal, Row, Select, Space, Table, Tag, Typography } from 'antd';
import { CommentOutlined, FormOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { larguraModal } from '@/componentes/responsivo';
import { formatarData, formatarNumero } from '@/utilitarios/formatacao';
import { FASES_AVALIACAO, PERIODOS_AVALIACAO, type Avaliacao, type ItemAvaliacao, type PeriodoAvaliacao } from '../api';
import { useAccaoRh, useAvisarErro } from './consultas';

interface Membro {
  colaborador_id: number;
  nome: string;
  avaliacao: (Avaliacao & { fase: string; nota_final: number | null }) | null;
  criterios: ItemAvaliacao[];
  objetivos: ItemAvaliacao[];
  feedbacks: { id: number; data: string; periodo_referencia: string; positivos: string | null; melhorar: string | null; acordos: string | null; confirmacao: unknown }[];
}

interface Equipa {
  ciclo: { id: number; nome: string | null; ano: number; periodo: string; estado: string } | null;
  membros: Membro[];
}

interface FormAvaliar {
  criterios: { chave: string; nota?: number | null; comentario?: string }[];
  objetivos: { chave: string; atingido?: number | null; nota_qual?: number | null; comentario?: string }[];
  pontos_fortes?: string;
  pontos_melhorar?: string;
  plano_desenvolvimento?: string;
}

const NOTAS = [1, 2, 3, 4, 5].map((n) => ({ value: n, label: String(n) }));

/**
 * Portal › A minha equipa (M-11; avaliacaoGravarChefia e aval360RegistarFeedback do legado): a chefia avalia cada membro
 * da equipa no período e regista as reuniões de acompanhamento. O servidor confirma que é a chefia do período.
 */
export function AMinhaEquipa() {
  const [ano, setAno] = useState(dayjs().year());
  const [periodo, setPeriodo] = useState<PeriodoAvaliacao>('ANUAL');
  const q = useQuery({ queryKey: ['rh', 'avaliacao', 'equipa', ano, periodo], queryFn: () => obter<Equipa>('/rh/avaliacao/equipa', { ano, periodo }) });
  useAvisarErro(q.error, 'Erro ao carregar a equipa');
  const [avaliar, setAvaliar] = useState<Membro | null>(null);
  const [reuniao, setReuniao] = useState<Membro | null>(null);
  const [formA] = Form.useForm<FormAvaliar>();
  const [formR] = Form.useForm<{ periodo_referencia: string; data: Dayjs; positivos?: string; melhorar?: string; acordos?: string }>();
  const accao = useAccaoRh(() => { setAvaliar(null); setReuniao(null); });
  const e = q.data;

  const abrirAvaliar = (m: Membro) => {
    const a = m.avaliacao;
    formA.setFieldsValue({
      criterios: m.criterios.map((i) => { const x = a?.criterios?.find((c) => c.chave === i.chave); return { chave: i.chave, nota: x?.nota ?? null, comentario: x?.comentario ?? undefined }; }),
      objetivos: m.objetivos.map((i) => { const x = a?.objetivos?.find((o) => o.chave === i.chave); return { chave: i.chave, atingido: x?.atingido ?? null, nota_qual: x?.nota_qual ?? null, comentario: x?.comentario ?? undefined }; }),
      pontos_fortes: a?.pontos_fortes ?? undefined, pontos_melhorar: a?.pontos_melhorar ?? undefined, plano_desenvolvimento: a?.plano_desenvolvimento ?? undefined,
    });
    setAvaliar(m);
  };
  const gravar = (v: FormAvaliar, concluir: boolean) => avaliar && accao.mutate({ metodo: 'post', url: '/rh/avaliacao/avaliacoes', dados: { ...v, colaborador_id: avaliar.colaborador_id, ano, periodo, concluir } });

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <InputNumber value={ano} min={2000} max={2100} onChange={(v) => v && setAno(v)} aria-label="Ano" />
        <Select value={periodo} onChange={setPeriodo} options={PERIODOS_AVALIACAO} style={{ width: 160 }} aria-label="Período" />
        {e?.ciclo ? <Tag color="blue">Ciclo {e.ciclo.nome ?? `${e.ciclo.ano} ${e.ciclo.periodo}`} · {e.ciclo.estado}</Tag> : <Tag>Sem ciclo 360º neste período</Tag>}
      </Space>
      {e && e.membros.length === 0 && <Empty description="Não tem colaboradores a seu cargo neste período." />}
      {e && e.membros.length > 0 && (
        <Table<Membro> rowKey="colaborador_id" size="small" dataSource={e.membros} pagination={false} scroll={{ x: 'max-content' }} loading={q.isFetching} columns={[
          { title: 'Colaborador', dataIndex: 'nome' },
          { title: 'Fase', render: (_, m) => { const f = FASES_AVALIACAO[m.avaliacao?.fase ?? 'POR_AVALIAR']; return f ? <Tag color={f.cor}>{f.rotulo}</Tag> : <Tag>{m.avaliacao?.fase ?? 'Por avaliar'}</Tag>; } },
          { title: 'Nota final', align: 'right', render: (_, m) => (m.avaliacao?.nota_final != null ? formatarNumero(m.avaliacao.nota_final) : '—') },
          { title: 'Classificação', responsive: ['md'], render: (_, m) => m.avaliacao?.classificacao ?? '—' },
          { title: 'Reuniões', align: 'right', responsive: ['md'], render: (_, m) => m.feedbacks.length },
          {
            title: '', key: 'a', align: 'right', render: (_, m) => (
              <Space wrap>
                <Button size="small" icon={<FormOutlined />} disabled={m.avaliacao?.estado === 'CONCLUIDA'} onClick={() => abrirAvaliar(m)}>Avaliar</Button>
                <Button size="small" icon={<CommentOutlined />} disabled={!e.ciclo} onClick={() => { formR.resetFields(); formR.setFieldsValue({ data: dayjs(), periodo_referencia: dayjs().format('YYYY-MM') }); setReuniao(m); }}>Registar reunião</Button>
              </Space>
            ),
          },
        ]} expandable={{
          rowExpandable: (m) => m.feedbacks.length > 0,
          expandedRowRender: (m) => (
            <Table size="small" rowKey="id" pagination={false} dataSource={m.feedbacks} columns={[
              { title: 'Data', dataIndex: 'data', render: (v: string) => formatarData(v) }, { title: 'Referência', dataIndex: 'periodo_referencia' },
              { title: 'Pontos positivos', dataIndex: 'positivos', ellipsis: true }, { title: 'A melhorar', dataIndex: 'melhorar', ellipsis: true },
              { title: 'Confirmada', render: (_, f) => (f.confirmacao ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>) },
            ]} />
          ),
        }} />
      )}

      <Modal title={`Avaliação de ${avaliar?.nome ?? ''} — ${periodo} ${ano}`} open={avaliar !== null} width={larguraModal(820)} onCancel={() => setAvaliar(null)} destroyOnHidden
        footer={<Space wrap><Button onClick={() => setAvaliar(null)}>Cancelar</Button>
          <Button loading={accao.isPending} onClick={() => formA.validateFields().then((v) => gravar(v, false))}>Gravar rascunho</Button>
          <Button type="primary" loading={accao.isPending} onClick={() => formA.validateFields().then((v) => gravar(v, true))}>Concluir avaliação</Button></Space>}>
        <Form form={formA} layout="vertical">
          {avaliar && avaliar.criterios.length === 0 && <Alert type="info" message="Sem critérios configurados." />}
          <Typography.Title level={5}>Critérios (nota de 1 a 5)</Typography.Title>
          <Form.List name="criterios">{(campos) => campos.map(({ key, name }) => {
            const i = avaliar?.criterios[name];
            return (
              <Row key={key} gutter={8} align="middle">
                <Col xs={24} md={10}><Typography.Text>{i?.nome}</Typography.Text></Col>
                <Col xs={8} md={4}><Form.Item name={[name, 'nota']} rules={[{ required: true, message: 'Nota' }]}><Select options={NOTAS} placeholder="Nota" /></Form.Item></Col>
                <Col xs={16} md={10}><Form.Item name={[name, 'comentario']}><Input placeholder="Comentário" maxLength={2000} /></Form.Item></Col>
              </Row>
            );
          })}</Form.List>
          {avaliar && avaliar.objetivos.length > 0 && <Typography.Title level={5}>Objectivos</Typography.Title>}
          <Form.List name="objetivos">{(campos) => campos.map(({ key, name }) => {
            const i = avaliar?.objetivos[name];
            const qual = i?.natureza === 'QUALITATIVO';
            return (
              <Row key={key} gutter={8} align="middle">
                <Col xs={24} md={10}><Typography.Text>{i?.nome}</Typography.Text>{!qual && i?.meta && <Typography.Text type="secondary"> (meta {i.meta}{i.unidade ? ` ${i.unidade}` : ''})</Typography.Text>}</Col>
                <Col xs={8} md={4}>
                  {qual ? <Form.Item name={[name, 'nota_qual']}><Select options={NOTAS} placeholder="Nota" /></Form.Item>
                    : <Form.Item name={[name, 'atingido']}><InputNumber placeholder="Atingido" style={{ width: '100%' }} /></Form.Item>}
                </Col>
                <Col xs={16} md={10}><Form.Item name={[name, 'comentario']}><Input placeholder="Comentário" maxLength={2000} /></Form.Item></Col>
              </Row>
            );
          })}</Form.List>
          <Form.Item name="pontos_fortes" label="Pontos fortes"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
          <Form.Item name="pontos_melhorar" label="Pontos a melhorar"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
          <Form.Item name="plano_desenvolvimento" label="Plano de desenvolvimento"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
        </Form>
      </Modal>

      <Modal title={`Reunião de acompanhamento — ${reuniao?.nome ?? ''}`} open={reuniao !== null} onCancel={() => setReuniao(null)} okText="Registar" cancelText="Cancelar"
        confirmLoading={accao.isPending} onOk={() => formR.submit()} destroyOnHidden>
        <Form form={formR} layout="vertical" onFinish={(v) => reuniao && e?.ciclo && accao.mutate({ metodo: 'post', url: '/rh/avaliacao/feedbacks', dados: {
          ciclo_avaliacao_id: e.ciclo.id, colaborador_id: reuniao.colaborador_id, periodo_referencia: v.periodo_referencia, data: v.data.format('YYYY-MM-DD'),
          positivos: v.positivos, melhorar: v.melhorar, acordos: v.acordos } })}>
          <Row gutter={12}>
            <Col xs={24} sm={12}><Form.Item name="data" label="Data" rules={[{ required: true }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} sm={12}><Form.Item name="periodo_referencia" label="Período de referência" rules={[{ required: true, pattern: /^\d{4}-(0[1-9]|1[0-2]|T[1-4]|S[12])$/, message: 'AAAA-MM, AAAA-T1… ou AAAA-S1' }]}><Input placeholder="AAAA-MM" /></Form.Item></Col>
          </Row>
          <Form.Item name="positivos" label="Pontos positivos"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
          <Form.Item name="melhorar" label="A melhorar"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
          <Form.Item name="acordos" label="Acordos e próximos passos"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}
