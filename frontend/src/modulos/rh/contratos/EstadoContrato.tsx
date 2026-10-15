import { Alert, Checkbox, Col, DatePicker, Descriptions, Empty, Form, Input, InputNumber, Modal, Row, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect } from 'react';
import { obter } from '@/api/cliente';
import type { AccaoLinha } from '@/componentes/vistas/AccoesLinha';
import { larguraModal } from '@/componentes/responsivo';
import { dataApi, formatarData, formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import type { ContratoTrabalho } from '../api';
import { useAccaoRh } from '../comum/consultas';
import type { EstadoContratoDetalhe, Fundamentos, PropostaCompensacao } from '../faseB/tipos';

/**
 * Suspensão e cessação do contrato (Versão Actual: contrato_estado_ui.js; Lei 12/23): Suspender, Reactivar, Cessar,
 * Anular cessação e Ver estado. Suspender e reactivar exigem `contratos_suspender`; cessar e anular `contratos_terminate`
 * (o servidor volta a verificar). A compensação proposta (arts. 307.º, 308.º e 310.º) vem do servidor e é editável.
 */
export type AccaoEstado = 'suspender' | 'reactivar' | 'cessar' | 'anular' | 'ver';

export function accoesEstadoContrato(c: ContratoTrabalho, pode: (p: string) => boolean, abrir: (a: AccaoEstado, c: ContratoTrabalho) => void): (AccaoLinha | false)[] {
  const estado = c.estado ?? 'ACTIVO';
  return [
    pode('contratos_suspender') && estado === 'ACTIVO' && { icone: 'pause-circle', titulo: 'Suspender contrato', variante: 'aviso', onClick: () => abrir('suspender', c) },
    pode('contratos_suspender') && estado === 'SUSPENSO' && { icone: 'play-circle', titulo: 'Reactivar contrato', variante: 'sucesso', onClick: () => abrir('reactivar', c) },
    pode('contratos_terminate') && (estado === 'ACTIVO' || estado === 'SUSPENSO') && { icone: 'user-times', titulo: 'Cessar contrato (Lei 12/23)', variante: 'perigo', onClick: () => abrir('cessar', c) },
    pode('contratos_terminate') && estado === 'CESSADO' && { icone: 'undo', titulo: 'Anular cessação', variante: 'aviso', onClick: () => abrir('anular', c) },
    (estado !== 'ACTIVO' || (c.suspensoes ?? []).length > 0 || (c.cessacoes_anuladas ?? []).length > 0) && { icone: 'history', titulo: 'Ver estado e histórico', variante: 'info', onClick: () => abrir('ver', c) },
  ];
}

interface Props {
  accao: { tipo: AccaoEstado; contrato: ContratoTrabalho } | null;
  nome: (id: number) => string;
  aoFechar: () => void;
}

export function ModaisEstadoContrato({ accao, nome, aoFechar }: Props) {
  const fundamentos = useQuery({ queryKey: ['rh', 'contratos', 'fundamentos'], queryFn: () => obter<Fundamentos>('/rh/contratos/fundamentos'), staleTime: Infinity, enabled: accao !== null });
  const c = accao?.contrato;
  const titulo = c ? `${nome(c.colaborador_id)} — contrato iniciado em ${formatarData(c.data_inicio)}` : '';
  return (
    <>
      {accao?.tipo === 'suspender' && c && <Suspender contrato={c} titulo={titulo} fundamentos={fundamentos.data} aoFechar={aoFechar} />}
      {accao?.tipo === 'reactivar' && c && <Observacoes contrato={c} titulo={titulo} url="reactivar" rotulo="Reactivar contrato" obrigatorio={false} aoFechar={aoFechar}
        texto={`Termina a suspensão em vigor (${c.suspensao?.motivo ?? 'sem fundamento registado'}, desde ${formatarData(c.suspensao?.de)}). O contrato volta a ser processado.`} />}
      {accao?.tipo === 'cessar' && c && <Cessar contrato={c} titulo={titulo} fundamentos={fundamentos.data} aoFechar={aoFechar} />}
      {accao?.tipo === 'anular' && c && <Observacoes contrato={c} titulo={titulo} url="anular-cessacao" rotulo="Anular cessação" obrigatorio aoFechar={aoFechar}
        texto={`Anula a cessação de ${formatarData(c.cessacao?.data)} (${c.cessacao?.motivo ?? ''}). A data de fim anterior é reposta e o colaborador volta a activo. Recusado se já houver outro contrato por cessar.`} />}
      {accao?.tipo === 'ver' && c && <VerEstado contrato={c} titulo={titulo} aoFechar={aoFechar} />}
    </>
  );
}

function Suspender({ contrato, titulo, fundamentos, aoFechar }: { contrato: ContratoTrabalho; titulo: string; fundamentos?: Fundamentos; aoFechar: () => void }) {
  const [form] = Form.useForm<{ de: Dayjs; ate?: Dayjs | null; motivo_codigo: string; observacoes?: string }>();
  const accao = useAccaoRh(aoFechar);
  const lados = ['Trabalhador', 'Empregador'];
  return (
    <Modal open title="Suspender contrato" width={larguraModal(640)} okText="Suspender" cancelText="Cancelar" confirmLoading={accao.isPending} onCancel={aoFechar} onOk={() => form.submit()} destroyOnHidden>
      <Typography.Paragraph type="secondary">{titulo}. Durante a suspensão o colaborador não é processado nem conta faltas (Lei 12/23, arts. 267.º e 270.º).</Typography.Paragraph>
      <Form form={form} layout="vertical" initialValues={{ de: dayjs() }} onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/contratos/${contrato.id}/suspender`,
        dados: { de: dataApi(v.de), ate: dataApi(v.ate ?? null) ?? null, motivo_codigo: v.motivo_codigo, observacoes: v.observacoes ?? null } })}>
        <Row gutter={12}>
          <Col xs={12}><Form.Item name="de" label="Início" rules={[{ required: true, message: 'Indique o início.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12}>
            <Form.Item name="ate" label="Fim (opcional)" dependencies={['de']} rules={[({ getFieldValue }) => ({
              validator: (_, v: Dayjs | null) => (!v || !getFieldValue('de') || !v.isBefore(getFieldValue('de'), 'day') ? Promise.resolve() : Promise.reject(new Error('Anterior ao início.'))),
            })]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item>
          </Col>
        </Row>
        <Form.Item name="motivo_codigo" label="Fundamento" rules={[{ required: true, message: 'Escolha o fundamento.' }]}>
          <Select showSearch optionFilterProp="label" loading={!fundamentos}
            options={lados.map((l) => ({ label: l === 'Trabalhador' ? 'Factos relativos ao trabalhador (art. 267.º)' : 'Causas relativas ao empregador (art. 270.º)',
              options: (fundamentos?.suspensao ?? []).filter((f) => f.lado === l).map((f) => ({ value: f.codigo, label: `${f.nome} — ${f.artigo}` })) }))} />
        </Form.Item>
        <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
      </Form>
    </Modal>
  );
}

function Cessar({ contrato, titulo, fundamentos, aoFechar }: { contrato: ContratoTrabalho; titulo: string; fundamentos?: Fundamentos; aoFechar: () => void }) {
  const [form] = Form.useForm<{ data: Dayjs; motivo_codigo: string; aviso_previo_dias?: number | null; compensacao_valor?: number | null; observacoes?: string; inactivar_colaborador: boolean }>();
  const accao = useAccaoRh(aoFechar);
  const motivo = Form.useWatch('motivo_codigo', form);
  const data = Form.useWatch('data', form);
  const fundamento = (fundamentos?.cessacao ?? []).find((f) => f.codigo === motivo);
  const proposta = useQuery({
    queryKey: ['rh', 'contratos', contrato.id, 'compensacao', motivo, data?.format('YYYY-MM-DD')],
    queryFn: () => obter<PropostaCompensacao>(`/rh/contratos/${contrato.id}/compensacao`, { motivo_codigo: motivo, data: data?.format('YYYY-MM-DD') }),
    enabled: Boolean(motivo && data),
  });
  useEffect(() => {
    if (proposta.data) form.setFieldsValue({ compensacao_valor: Number(proposta.data.valor) });
  }, [proposta.data, form]);
  useEffect(() => {
    if (fundamento) form.setFieldsValue({ aviso_previo_dias: fundamento.aviso_previo ?? null });
  }, [fundamento, form]);
  const modalidades = ['Caducidade', 'Revogação', 'Resolução'];
  return (
    <Modal open title="Cessar contrato (Lei 12/23)" width={larguraModal(720)} okText="Cessar contrato" okButtonProps={{ danger: true }} cancelText="Cancelar" confirmLoading={accao.isPending}
      onCancel={aoFechar} onOk={() => form.submit()} destroyOnHidden>
      <Typography.Paragraph type="secondary">{titulo}. A data de fim do contrato passa a ser a da cessação; o mês da cessação ainda é processado (ajuste os dias).</Typography.Paragraph>
      <Form form={form} layout="vertical" initialValues={{ data: dayjs(), inactivar_colaborador: true }} onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/contratos/${contrato.id}/cessar`,
        dados: { data: dataApi(v.data), motivo_codigo: v.motivo_codigo, aviso_previo_dias: v.aviso_previo_dias ?? null, compensacao_valor: v.compensacao_valor ?? null,
          observacoes: v.observacoes ?? null, inactivar_colaborador: v.inactivar_colaborador } })}>
        <Row gutter={12}>
          <Col xs={24} md={8}><Form.Item name="data" label="Data da cessação" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={16}>
            <Form.Item name="motivo_codigo" label="Modalidade e fundamento" rules={[{ required: true, message: 'Escolha o fundamento.' }]}>
              <Select showSearch optionFilterProp="label" loading={!fundamentos}
                options={modalidades.map((m) => ({ label: m, options: (fundamentos?.cessacao ?? []).filter((f) => f.modalidade === m).map((f) => ({ value: f.codigo, label: `${f.nome} — ${f.artigo}` })) }))} />
            </Form.Item>
          </Col>
        </Row>
        {fundamento?.nota && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={fundamento.nota} />}
        {proposta.isFetching && <Spin size="small" />}
        {proposta.data && (
          <Descriptions size="small" bordered column={{ xs: 1, md: 2 }} style={{ marginBottom: 12 }}>
            <Descriptions.Item label="Salário base (SB)">{formatarKz(proposta.data.salario_base, true)}</Descriptions.Item>
            <Descriptions.Item label="Antiguidade (art. 311.º)">{proposta.data.anos} ano(s)</Descriptions.Item>
            <Descriptions.Item label="Regra" span={2}>{proposta.data.aplicavel ? `${proposta.data.artigo} — ${proposta.data.formula}` : 'Sem compensação legal automática'}</Descriptions.Item>
            <Descriptions.Item label="Compensação proposta" span={2}><strong>{formatarKz(proposta.data.valor, true)}</strong></Descriptions.Item>
          </Descriptions>
        )}
        {(proposta.data?.processamentos_posteriores ?? []).length > 0 && (
          <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Há processamentos posteriores com lançamentos deste colaborador"
            description={(proposta.data?.processamentos_posteriores ?? []).map((p) => `${p.periodo} (${p.lancamentos} lançamento(s), ${p.estado ?? '—'})`).join('; ')} />
        )}
        <Row gutter={12}>
          <Col xs={12}><Form.Item name="compensacao_valor" label="Compensação decidida" extra="Não gera lançamento salarial: fica registada."><InputNumber min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} suffix="Kz" /></Form.Item></Col>
          <Col xs={12}><Form.Item name="aviso_previo_dias" label="Aviso prévio (dias)"><InputNumber min={0} max={365} style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
        <Form.Item name="observacoes" label="Observações"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
        <Form.Item name="inactivar_colaborador" valuePropName="checked"><Checkbox>Passar o colaborador a inactivo com a data da cessação</Checkbox></Form.Item>
      </Form>
    </Modal>
  );
}

function Observacoes({ contrato, titulo, url, rotulo, texto, obrigatorio, aoFechar }: {
  contrato: ContratoTrabalho; titulo: string; url: string; rotulo: string; texto: string; obrigatorio: boolean; aoFechar: () => void;
}) {
  const [form] = Form.useForm<{ observacoes?: string }>();
  const accao = useAccaoRh(aoFechar);
  return (
    <Modal open title={rotulo} okText={rotulo} cancelText="Cancelar" confirmLoading={accao.isPending} onCancel={aoFechar} onOk={() => form.submit()} destroyOnHidden>
      <Typography.Paragraph type="secondary">{titulo}.</Typography.Paragraph>
      <Typography.Paragraph>{texto}</Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: 'post', url: `/rh/contratos/${contrato.id}/${url}`, dados: { observacoes: v.observacoes ?? null } })}>
        <Form.Item name="observacoes" label={obrigatorio ? 'Motivo' : 'Observações'} rules={obrigatorio ? [{ required: true, min: 5, message: 'Indique o motivo (mínimo 5 caracteres).' }] : []}>
          <Input.TextArea rows={3} maxLength={2000} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

function VerEstado({ contrato, titulo, aoFechar }: { contrato: ContratoTrabalho; titulo: string; aoFechar: () => void }) {
  const q = useQuery({ queryKey: ['rh', 'contratos', contrato.id, 'estado'], queryFn: () => obter<EstadoContratoDetalhe>(`/rh/contratos/${contrato.id}/estado`) });
  const e = q.data;
  return (
    <Modal open title="Estado do contrato" width={larguraModal(820)} footer={null} onCancel={aoFechar}>
      <Typography.Paragraph type="secondary">{titulo}</Typography.Paragraph>
      {q.isLoading || !e ? <Spin /> : (
        <Space direction="vertical" style={{ width: '100%' }} size="middle">
          <Descriptions size="small" bordered column={{ xs: 1, md: 3 }}>
            <Descriptions.Item label="Estado"><Tag color={e.estado === 'CESSADO' ? 'red' : e.estado === 'SUSPENSO' ? 'orange' : 'green'}>{e.estado}</Tag></Descriptions.Item>
            <Descriptions.Item label="Salário base">{formatarKz(e.salario_base, true)}</Descriptions.Item>
            <Descriptions.Item label="Antiguidade">{e.antiguidade_anos} ano(s)</Descriptions.Item>
          </Descriptions>
          {e.cessacao && (
            <Descriptions title="Cessação" size="small" bordered column={{ xs: 1, md: 2 }}>
              <Descriptions.Item label="Data">{formatarData(e.cessacao.data)}</Descriptions.Item>
              <Descriptions.Item label="Modalidade">{e.cessacao.modalidade}</Descriptions.Item>
              <Descriptions.Item label="Fundamento" span={2}>{e.cessacao.motivo} ({e.cessacao.artigo})</Descriptions.Item>
              <Descriptions.Item label="Compensação registada">{formatarKz(e.cessacao.compensacao_valor, true)}</Descriptions.Item>
              <Descriptions.Item label="Proposta">{formatarKz(e.cessacao.compensacao_proposta, true)}</Descriptions.Item>
              {e.cessacao.compensacao_artigo && <Descriptions.Item label="Regra aplicada" span={2}>{e.cessacao.compensacao_artigo} — {e.cessacao.compensacao_formula}</Descriptions.Item>}
              <Descriptions.Item label="Aviso prévio">{e.cessacao.aviso_previo_dias ?? '—'} dia(s)</Descriptions.Item>
              <Descriptions.Item label="Registado">{e.cessacao.registado_por ?? '—'} · {formatarDataHora(e.cessacao.registado_em)}</Descriptions.Item>
            </Descriptions>
          )}
          <Typography.Title level={5} style={{ margin: 0 }}>Suspensões</Typography.Title>
          {e.suspensoes.length ? (
            <Table size="small" rowKey={(_, i) => String(i)} pagination={false} dataSource={e.suspensoes} scroll={{ x: true }} columns={[
              { title: 'De', dataIndex: 'de', render: formatarData },
              { title: 'Até', dataIndex: 'ate', render: (v: string | null) => (v ? formatarData(v) : 'em aberto') },
              { title: 'Fundamento', render: (_, s) => `${s.motivo} (${s.artigo})` },
              { title: 'Registo', render: (_, s) => `${s.registado_por ?? '—'} · ${formatarDataHora(s.registado_em)}` },
              { title: 'Terminada', render: (_, s) => (s.terminada_em ? `${s.terminada_por ?? '—'} · ${formatarDataHora(s.terminada_em)}${s.terminada_observacoes ? ` — ${s.terminada_observacoes}` : ''}` : '—') },
            ]} />
          ) : <Empty description="Sem suspensões." image={Empty.PRESENTED_IMAGE_SIMPLE} />}
          {e.cessacoes_anuladas.length > 0 && (
            <>
              <Typography.Title level={5} style={{ margin: 0 }}>Cessações anuladas</Typography.Title>
              <Table size="small" rowKey={(_, i) => String(i)} pagination={false} dataSource={e.cessacoes_anuladas} scroll={{ x: true }} columns={[
                { title: 'Data', dataIndex: 'data', render: formatarData },
                { title: 'Fundamento', render: (_, s) => `${s.motivo} (${s.artigo})` },
                { title: 'Anulada', render: (_, s) => `${s.anulada_por ?? '—'} · ${formatarDataHora(s.anulada_em)} — ${s.anulada_observacoes ?? ''}` },
              ]} />
            </>
          )}
        </Space>
      )}
    </Modal>
  );
}
