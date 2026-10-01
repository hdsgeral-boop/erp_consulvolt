import { Button, Card, Col, Flex, Form, Input, InputNumber, Modal, Row, Select, Table, Typography } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { SeletorProjecto } from '@/modulos/activos/comum/componentes';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { EtiquetaOrc, SeletorOrcamento, useOrcamentos } from './comum/componentes';
import type { Orcamento, TipoOrcamento } from './comum/tipos';
import { DetalheOrcamento } from './DetalheOrcamento';

/** Orçamento › Orçamentos (ecrã orc_orcamentos): exploração e tesouraria, versões, aprovação, top-down e contributos. */
export default function Orcamentos() {
  return (
    <Routes>
      <Route index element={<Lista />} />
      <Route path=":id" element={<DetalheOrcamento />} />
    </Routes>
  );
}

function Lista() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [ano, setAno] = useState<number | undefined>(dayjs().year());
  const [tipo, setTipo] = useState<TipoOrcamento>();
  const [novo, setNovo] = useState(false);
  const q = useOrcamentos({ ano, tipo });
  const nomes = new Map((q.data ?? []).map((o) => [o.id, o.nome]));

  return (
    <>
      <CabecalhoPagina
        titulo="Orçamentos"
        subtitulo="Rascunho → submetido → aprovado; nova versão a partir do aprovado (o anterior fica substituído)"
        accoes={pode('orc_editar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo orçamento</Button>}
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <InputNumber addonBefore="Ano" min={2000} max={2100} value={ano} onChange={(v) => setAno(v ?? undefined)} style={{ width: 150 }} />
          <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 160 }} options={[{ value: 'EXPLORACAO', label: 'Exploração' }, { value: 'TESOURARIA', label: 'Tesouraria' }]} />
        </Flex>
        <Table<Orcamento>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={q.data}
          scroll={{ x: 'max-content' }}
          onRow={(o) => ({ onClick: () => navegar(String(o.id)), style: { cursor: 'pointer' } })}
          columns={[
            { title: 'Ano', dataIndex: 'ano' },
            { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaOrc valor={v} /> },
            { title: 'Nome', dataIndex: 'nome', render: (v, o) => <><strong>{v ?? '—'}</strong>{o.orcamento_pai_id && <Typography.Text type="secondary"> · contributo de {nomes.get(o.orcamento_pai_id) ?? `#${o.orcamento_pai_id}`}</Typography.Text>}</> },
            { title: 'Versão', dataIndex: 'versao', render: (v) => `v${v}` },
            { title: 'Abordagem', dataIndex: 'abordagem', render: (v) => (v === 'TOP_DOWN' ? 'Top-down' : v === 'BOTTOM_UP' ? 'Bottom-up' : '—') },
            { title: 'Responsável', dataIndex: 'responsavel', render: (v) => v ?? '—' },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaOrc valor={v} /> },
            { title: 'Aprovado', key: 'ap', render: (_, o) => (o.aprovado_em ? `${o.aprovado_por ?? ''} ${formatarDataHora(o.aprovado_em)}` : '—') },
          ]}
        />
      </Card>
      <ModalNovoOrcamento aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(o) => navegar(String(o.id))} />
    </>
  );
}

function ModalNovoOrcamento({ aberto, aoFechar, aoGravar }: { aberto: boolean; aoFechar: () => void; aoGravar: (o: Orcamento) => void }) {
  const [form] = Form.useForm();
  const tipo = Form.useWatch('tipo', form);
  const metodo = Form.useWatch('metodo', form);
  const abordagem = Form.useWatch('abordagem', form);
  const accao = useAccao<Orcamento>({ invalidar: [['orcamento']], aoSucesso: (o) => { aoGravar(o); aoFechar(); } });
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ ano: dayjs().year() + (dayjs().month() >= 9 ? 1 : 0), tipo: 'EXPLORACAO', metodo: 'HISTORICO', origem: 'REALIZADO_ANTERIOR' }); } }, [aberto, form]);

  return (
    <Modal title="Novo orçamento" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Criar" cancelText="Cancelar" confirmLoading={accao.isPending} width={820} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados: Record<string, unknown> = { ...v };
        for (const k of Object.keys(dados)) if (dados[k] === undefined || dados[k] === '') dados[k] = null;
        accao.mutate({ url: '/orcamento/orcamentos', dados });
      }}>
        <Row gutter={12}>
          <Col xs={12} md={4}><Form.Item name="ano" label="Ano" rules={[{ required: true }]}><InputNumber min={2000} max={2100} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select options={[{ value: 'EXPLORACAO', label: 'Exploração' }, { value: 'TESOURARIA', label: 'Tesouraria' }]} /></Form.Item></Col>
          <Col xs={24} md={14}><Form.Item name="nome" label="Nome"><Input maxLength={255} placeholder="Ex.: Conta de exploração 2027" /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="unidade_negocio_id" label="Unidade de negócio"><SeletorUnidade style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="centro_custo_id" label="Centro de custo"><SeletorAux tabela="centros-custo" placeholder="Centro de custo" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="projeto_id" label="Projecto"><SeletorProjecto allowClear /></Form.Item></Col>
          <Col xs={24} md={8}>
            <Form.Item name="metodo" label="Método">
              <Select options={[{ value: 'HISTORICO', label: 'Histórico (com crescimento)' }, { value: 'BASE_ZERO', label: 'Base zero (justificar cada rubrica)' }]} />
            </Form.Item>
          </Col>
          {metodo === 'HISTORICO' && (
            <>
              <Col xs={24} md={8}><Form.Item name="origem" label="Base"><Select options={[{ value: 'REALIZADO_ANTERIOR', label: 'Realizado do ano anterior' }, { value: 'ORCAMENTO_ANTERIOR', label: 'Orçamento aprovado anterior' }]} /></Form.Item></Col>
              <Col xs={8} md={8}><Form.Item name="inflacao_pct" label="Inflação (%)"><InputNumber min={-100} max={1000} style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={8} md={8}><Form.Item name="crescimento_proveitos_pct" label={tipo === 'TESOURARIA' ? 'Cresc. recebimentos (%)' : 'Cresc. proveitos (%)'}><InputNumber min={-100} max={1000} style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={8} md={8}><Form.Item name="crescimento_custos_pct" label={tipo === 'TESOURARIA' ? 'Cresc. pagamentos (%)' : 'Cresc. custos (%)'}><InputNumber min={-100} max={1000} style={{ width: '100%' }} /></Form.Item></Col>
            </>
          )}
          {tipo === 'TESOURARIA' && <Col xs={24} md={8}><Form.Item name="saldo_inicial" label="Saldo inicial (Kz)" tooltip="Vazio = calculado pelo Diário"><InputNumber precision={2} style={{ width: '100%' }} /></Form.Item></Col>}
          <Col xs={24} md={8}><Form.Item name="abordagem" label="Abordagem hierárquica"><Select allowClear options={[{ value: 'TOP_DOWN', label: 'Top-down (repartir metas)' }, { value: 'BOTTOM_UP', label: 'Bottom-up (contributos)' }]} /></Form.Item></Col>
          {abordagem && <Col xs={24} md={8}><Form.Item name="dimensao_filhos" label="Dimensão dos filhos"><Select options={[{ value: 'UN', label: 'Unidades de negócio' }, { value: 'CC', label: 'Centros de custo' }, { value: 'PROJETO', label: 'Projectos' }]} /></Form.Item></Col>}
          <Col xs={24} md={8}><Form.Item name="orcamento_pai_id" label="Orçamento pai (opcional)"><SeletorOrcamento allowClear ano={form.getFieldValue('ano')} tipo={tipo} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="responsavel" label="Responsável (utilizador)"><Input maxLength={100} /></Form.Item></Col>
          <Col span={24}><Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={2000} /></Form.Item></Col>
        </Row>
      </Form>
      <Typography.Text type="secondary">A combinação ano / tipo / UN / CC / projecto é única para todas as versões.</Typography.Text>
    </Modal>
  );
}
