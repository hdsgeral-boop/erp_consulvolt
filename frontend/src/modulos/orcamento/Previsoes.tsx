import { Alert, Button, Card, Col, DatePicker, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Space, Table, Typography } from 'antd';
import { ArrowLeftOutlined, BranchesOutlined, CloudUploadOutlined, DeleteOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { SeletorProjecto } from '@/modulos/activos/comum/componentes';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { EtiquetaOrc, Kz } from './comum/componentes';
import { totalAnual } from './comum/regras';
import type { Previsao, ResumoPrevisao } from './comum/tipos';

const METODOS = [
  { value: 'ORCAMENTO', label: 'Orçamento aprovado do mês' },
  { value: 'TENDENCIA', label: 'Tendência (média dos 3 últimos meses reais)' },
  { value: 'ANO_ANTERIOR', label: 'Mês homólogo + crescimento' },
  { value: 'BRANCO', label: 'Em branco' },
];

/** Orçamento › Previsões deslizantes (ecrã orc_previsoes): 12 meses a partir do último mês fechado, com revisões e publicação. */
export default function Previsoes() {
  return (
    <Routes>
      <Route index element={<Lista />} />
      <Route path=":id" element={<Detalhe />} />
    </Routes>
  );
}

function Lista() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [nova, setNova] = useState(false);
  const q = useQuery({ queryKey: ['orcamento', 'previsoes'], queryFn: () => obter<Previsao[]>('/orcamento/previsoes') });
  return (
    <>
      <CabecalhoPagina titulo="Previsões deslizantes" subtitulo="Rolling forecast a 12 meses e estimativa de fecho do ano"
        accoes={pode('orc_previsoes_edit') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNova(true)}>Nova previsão</Button>} />
      <Card>
        <Table<Previsao>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={q.data}
          onRow={(p) => ({ onClick: () => navegar(String(p.id)), style: { cursor: 'pointer' } })}
          columns={[
            { title: 'Nome', dataIndex: 'nome', render: (v) => <strong>{v ?? '—'}</strong> },
            { title: 'Tipo', dataIndex: 'tipo', render: (v) => <EtiquetaOrc valor={v} /> },
            { title: 'Mês de referência', dataIndex: 'mes_referencia', render: (v) => dayjs(`${v}-01`).format('MM/YYYY') },
            { title: 'Revisão', dataIndex: 'revisao', render: (v) => `R${v}` },
            { title: 'Método', dataIndex: 'metodo', render: (v) => METODOS.find((m) => m.value === v)?.label ?? v ?? '—' },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaOrc valor={v} /> },
            { title: 'Publicada', key: 'p', render: (_, p) => (p.publicado_em ? `${p.publicado_por ?? ''} ${formatarDataHora(p.publicado_em)}` : '—') },
          ]}
        />
      </Card>
      <ModalNova aberto={nova} aoFechar={() => setNova(false)} aoGravar={(p) => navegar(String(p.id))} />
    </>
  );
}

function ModalNova({ aberto, aoFechar, aoGravar }: { aberto: boolean; aoFechar: () => void; aoGravar: (p: Previsao) => void }) {
  const [form] = Form.useForm();
  const metodo = Form.useWatch('metodo', form);
  const accao = useAccao<Previsao>({ invalidar: [['orcamento']], aoSucesso: (p) => { aoGravar(p); aoFechar(); } });
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ tipo: 'EXPLORACAO', metodo: 'TENDENCIA', mes_referencia: dayjs().subtract(1, 'month') }); } }, [aberto, form]);
  return (
    <Modal title="Nova previsão" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Criar" cancelText="Cancelar" confirmLoading={accao.isPending} width={720} destroyOnClose>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados: Record<string, unknown> = { ...v, mes_referencia: v.mes_referencia.format('YYYY-MM') };
        for (const k of Object.keys(dados)) if (dados[k] === undefined || dados[k] === '') dados[k] = null;
        accao.mutate({ url: '/orcamento/previsoes', dados });
      }}>
        <Row gutter={12}>
          <Col xs={24} md={8}><Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select options={[{ value: 'EXPLORACAO', label: 'Exploração' }, { value: 'TESOURARIA', label: 'Tesouraria' }]} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="mes_referencia" label="Último mês real fechado" rules={[{ required: true }]}><DatePicker picker="month" format="MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="nome" label="Nome"><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} md={16}><Form.Item name="metodo" label="Semear com"><Select options={METODOS} /></Form.Item></Col>
          {metodo === 'ANO_ANTERIOR' && <Col xs={24} md={8}><Form.Item name="crescimento_pct" label="Crescimento (%)"><InputNumber min={-100} max={1000} style={{ width: '100%' }} /></Form.Item></Col>}
          <Col xs={24} md={8}><Form.Item name="unidade_negocio_id" label="Unidade de negócio"><SeletorUnidade style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="centro_custo_id" label="Centro de custo"><SeletorAux tabela="centros-custo" placeholder="Centro de custo" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="projeto_id" label="Projecto"><SeletorProjecto allowClear /></Form.Item></Col>
        </Row>
      </Form>
    </Modal>
  );
}

type Linha = ResumoPrevisao['linhas'][number];

function Detalhe() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const q = useQuery({ queryKey: ['orcamento', 'previsao', id], queryFn: () => obter<ResumoPrevisao>(`/orcamento/previsoes/${id}`) });
  const [valores, setValores] = useState<Record<number, Record<string, number>>>({});
  const [notas, setNotas] = useState('');
  const [alterado, setAlterado] = useState(false);
  const gravar = useAccao({ invalidar: [['orcamento']], aoSucesso: () => setAlterado(false) });
  const accao = useAccao({ invalidar: [['orcamento']] });
  const revisao = useAccao<Previsao>({ invalidar: [['orcamento']], aoSucesso: (p) => navegar(`../${p.id}`, { relative: 'path' }) });
  const eliminar = useAccao({ invalidar: [['orcamento']], aoSucesso: () => navegar('..') });

  useEffect(() => {
    if (!q.data) return;
    setValores(Object.fromEntries(q.data.linhas.map((l) => [l.rubrica_id, Object.fromEntries(q.data.meses.map((m) => [m, Number(l.previsao[m] ?? 0)]))])));
    setNotas(q.data.previsao.notas ?? '');
    setAlterado(false);
  }, [q.data]);

  if (!q.data) return <Card loading={q.isLoading} />;
  const r = q.data;
  const p = r.previsao;
  const editavel = p.estado === 'RASCUNHO' && pode('orc_previsoes_edit');
  const total = (rid: number) => totalAnual(Object.values(valores[rid] ?? {}));

  const colunas: ColumnsType<Linha> = [
    { title: 'Rubrica', key: 'r', fixed: 'left', width: 240, render: (_, l) => <><strong>{l.codigo}</strong> {l.nome}</> },
    ...r.meses_reais.map((m, i) => ({ title: <Typography.Text type="secondary">{dayjs(`${m}-01`).format('MMM YY')} (real)</Typography.Text>, key: `real${i}`, align: 'right' as const, render: (_: unknown, l: Linha) => <Typography.Text type="secondary">{Number(l.real_recente[i] ?? 0).toLocaleString('pt-PT', { maximumFractionDigits: 0 })}</Typography.Text> })),
    ...r.meses.map((m) => ({
      title: dayjs(`${m}-01`).format('MMM YY'), key: m, align: 'right' as const,
      render: (_: unknown, l: Linha) => editavel
        ? <InputNumber size="small" controls={false} precision={2} style={{ width: 108 }} value={valores[l.rubrica_id]?.[m] ?? 0} onChange={(v) => { setValores((s) => ({ ...s, [l.rubrica_id]: { ...s[l.rubrica_id], [m]: v ?? 0 } })); setAlterado(true); }} />
        : <Kz valor={valores[l.rubrica_id]?.[m] ?? 0} />,
    })),
    { title: 'Total 12 m', key: 't12', align: 'right', render: (_, l) => <Kz valor={total(l.rubrica_id)} forte /> },
    { title: `Real ${r.ano_fecho}`, dataIndex: 'real_ano', align: 'right', render: (v) => <Kz valor={v} /> },
    { title: `Fecho ${r.ano_fecho}`, dataIndex: 'fecho_estimado', align: 'right', render: (v) => <Kz valor={v} forte /> },
    { title: 'Orçado', dataIndex: 'orcado', align: 'right', render: (v) => (v === null ? '—' : <Kz valor={v} />) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={<Space wrap><Button type="text" icon={<ArrowLeftOutlined />} onClick={() => navegar('..')} />{p.nome ?? 'Previsão'} · R{p.revisao}<EtiquetaOrc valor={p.tipo} /><EtiquetaOrc valor={p.estado} /></Space>}
        subtitulo={`Mês de referência ${dayjs(`${p.mes_referencia}-01`).format('MM/YYYY')} · fecho estimado de ${r.ano_fecho}${r.orcamento_id ? '' : ' (sem orçamento aprovado para comparar)'}`}
        accoes={
          <>
            {editavel && <Button type="primary" icon={<SaveOutlined />} disabled={!alterado} loading={gravar.isPending}
              onClick={() => gravar.mutate({ metodo: 'put', url: `/orcamento/previsoes/${p.id}`, dados: { linhas: Object.entries(valores).map(([rid, v]) => ({ rubrica_orcamental_id: Number(rid), valores: v })), notas: notas || null } })}>Gravar</Button>}
            {editavel && (
              <Popconfirm title="Publicar a previsão?" description="Uma revisão publicada não se altera nem se elimina." okText="Publicar" cancelText="Cancelar" disabled={alterado} onConfirm={() => accao.mutate({ url: `/orcamento/previsoes/${p.id}/publicar` })}>
                <Button icon={<CloudUploadOutlined />} disabled={alterado}>Publicar</Button>
              </Popconfirm>
            )}
            {p.estado === 'PUBLICADA' && pode('orc_previsoes_edit') && <NovaRevisao aoCriar={(mes) => revisao.mutate({ url: `/orcamento/previsoes/${p.id}/revisao`, dados: { mes_referencia: mes } })} carregando={revisao.isPending} mesActual={p.mes_referencia} />}
            {editavel && (
              <Popconfirm title="Eliminar esta revisão?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/orcamento/previsoes/${p.id}` })}>
                <Button danger icon={<DeleteOutlined />} />
              </Popconfirm>
            )}
          </>
        }
      />
      {alterado && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Há alterações por gravar." />}
      <Card size="small">
        <Table<Linha> rowKey="rubrica_id" size="small" pagination={false} dataSource={r.linhas} columns={colunas} scroll={{ x: 'max-content', y: 620 }} />
        <Flex style={{ marginTop: 12 }}>
          <Input.TextArea rows={2} maxLength={5000} placeholder="Notas da revisão" value={notas} disabled={!editavel} onChange={(e) => { setNotas(e.target.value); setAlterado(true); }} />
        </Flex>
      </Card>
    </>
  );
}

function NovaRevisao({ aoCriar, carregando, mesActual }: { aoCriar: (mes: string) => void; carregando: boolean; mesActual: string }) {
  const [aberto, setAberto] = useState(false);
  const [mes, setMes] = useState(dayjs(`${mesActual}-01`).add(1, 'month'));
  return (
    <>
      <Button icon={<BranchesOutlined />} onClick={() => setAberto(true)} loading={carregando}>Nova revisão</Button>
      <Modal title="Nova revisão da previsão" open={aberto} onCancel={() => setAberto(false)} okText="Criar" cancelText="Cancelar" onOk={() => { aoCriar(mes.format('YYYY-MM')); setAberto(false); }}>
        <Typography.Paragraph type="secondary">Copia os meses em comum com a revisão actual e semeia só os meses novos.</Typography.Paragraph>
        <DatePicker picker="month" format="MM/YYYY" value={mes} allowClear={false} onChange={(d) => d && setMes(d)} />
      </Modal>
    </>
  );
}
