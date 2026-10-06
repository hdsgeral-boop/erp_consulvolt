import { Alert, Button, Card, Col, Empty, Flex, Form, Input, InputNumber, List, Modal, Popconfirm, Row, Select, Space, Statistic, Table, Typography } from 'antd';
import { BranchesOutlined, DeleteOutlined, PlusOutlined, SaveOutlined, ThunderboltOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, pares } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { pedidoTabela } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaOrc, Kz, SeletorOrcamento, useOrcamentos } from './comum/componentes';
import { diferenca } from './comum/regras';
import type { CalculoCenario, Cenario } from './comum/tipos';

const VARIAVEIS: { chave: string; rotulo: string }[] = [
  { chave: 'vendas_volume_pct', rotulo: 'Volume de vendas' },
  { chave: 'vendas_preco_pct', rotulo: 'Preço de venda' },
  { chave: 'materias_pct', rotulo: 'Matérias e mercadorias' },
  { chave: 'pessoal_pct', rotulo: 'Pessoal' },
  { chave: 'outros_pct', rotulo: 'Outros custos' },
  { chave: 'cambio_pct', rotulo: 'Câmbio' },
];

/** Orçamento › Cenários what-if (ecrã orc_cenarios): variáveis e ajustes por rubrica sobre um orçamento; gerar nova versão. */
export default function Cenarios() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const todos = useOrcamentos({});
  const [orcamento, setOrcamento] = useState<number>();
  const [cenarioId, setCenarioId] = useState<number | null>(null);
  const [novo, setNovo] = useState(false);
  const editar = pode('orc_cenarios_edit');
  useEffect(() => {
    if (orcamento || !todos.data?.length) return;
    setOrcamento((todos.data.find((o) => o.estado === 'APROVADO') ?? todos.data[0]).id);
  }, [todos.data, orcamento]);
  const lista = useQuery({ queryKey: ['orcamento', 'cenarios', orcamento], queryFn: () => obter<Cenario[]>(`/orcamento/orcamentos/${orcamento}/cenarios`), enabled: !!orcamento });
  const padrao = useAccao({ invalidar: [['orcamento', 'cenarios']] });
  useEffect(() => setCenarioId(null), [orcamento]);

  return (
    <>
      <CabecalhoPagina titulo="Cenários (what-if)" subtitulo="Volume, preço, matérias, pessoal, outros custos e câmbio aplicados ao orçamento base" />
      <Card style={{ marginBottom: 16 }}>
        <Flex gap={8} wrap>
          <SeletorOrcamento value={orcamento} onChange={setOrcamento} style={{ width: 420, maxWidth: '100%' }} />
          {editar && orcamento && (
            <>
              <Button icon={<ThunderboltOutlined />} loading={padrao.isPending} onClick={() => padrao.mutate({ url: `/orcamento/orcamentos/${orcamento}/cenarios/padrao` })}>Criar Otimista / Realista / Pessimista</Button>
              <Button icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo cenário</Button>
            </>
          )}
        </Flex>
      </Card>
      <Row gutter={16}>
        <Col xs={24} md={6}>
          <Card size="small" title="Cenários" loading={lista.isLoading}>
            {lista.data?.length ? (
              <List size="small" dataSource={lista.data} renderItem={(c) => (
                <List.Item onClick={() => setCenarioId(c.id)} style={{ cursor: 'pointer', background: c.id === cenarioId ? 'rgba(22,119,255,0.08)' : undefined, paddingInline: 8 }}>
                  <Space>{c.nome}{c.tipo && <EtiquetaOrc valor={c.tipo} />}</Space>
                </List.Item>
              )} />
            ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem cenários" />}
          </Card>
        </Col>
        <Col xs={24} md={18}>
          {cenarioId ? <DetalheCenario id={cenarioId} editar={editar} podeVersao={editar && pode('orc_editar')} aoEliminar={() => setCenarioId(null)} aoGerar={(oid) => navegar(`/m/orcamento/orc_orcamentos/${oid}`)} /> : <Card><Empty description="Escolha um cenário" /></Card>}
        </Col>
      </Row>
      <ModalNovo orcamento={novo ? orcamento ?? null : null} aoFechar={() => setNovo(false)} aoGravar={(c) => setCenarioId(c.id)} />
    </>
  );
}

function DetalheCenario({ id, editar, podeVersao, aoEliminar, aoGerar }: { id: number; editar: boolean; podeVersao: boolean; aoEliminar: () => void; aoGerar: (orcamentoId: number) => void }) {
  const q = useQuery({ queryKey: ['orcamento', 'cenario', id], queryFn: () => obter<CalculoCenario>(`/orcamento/cenarios/${id}`) });
  const [variaveis, setVariaveis] = useState<Record<string, number>>({});
  const [ajustes, setAjustes] = useState<Record<string, number>>({});
  const [alterado, setAlterado] = useState(false);
  const gravar = useAccao({ invalidar: [['orcamento']], aoSucesso: () => setAlterado(false) });
  const eliminar = useAccao({ invalidar: [['orcamento', 'cenarios']], aoSucesso: aoEliminar });
  const gerar = useAccao<{ id: number }>({ invalidar: [['orcamento']], aoSucesso: (o) => aoGerar(o.id) });
  useEffect(() => {
    if (!q.data) return;
    setVariaveis({ ...(q.data.cenario.variaveis ?? {}) });
    setAjustes({ ...(q.data.cenario.ajustes ?? {}) });
    setAlterado(false);
  }, [q.data]);
  if (!q.data) return <Card loading />;
  const r = q.data;
  const c = r.cenario;
  const variacao = diferenca(r.resultado_cenario, r.resultado_base);

  const colunas: ColunaApi<CalculoCenario['linhas'][number]>[] = [
    { title: 'Rubrica', key: 'r', render: (_, l) => <><strong>{l.codigo}</strong> {l.nome}</>, valorImpressao: (l) => `${l.codigo} ${l.nome}` },
    { title: 'Natureza', dataIndex: 'natureza', responsive: ['md'], render: (v) => <EtiquetaOrc valor={v} /> },
    { title: 'Indutor', dataIndex: 'indutor', responsive: ['lg'], render: (v) => VARIAVEIS.find((x) => x.chave === v)?.rotulo ?? v ?? '—' },
    { title: 'Base', dataIndex: 'base', align: 'right', render: (v) => <Kz valor={v} /> },
    { title: 'Cenário', dataIndex: 'cenario', align: 'right', render: (v) => <Kz valor={v} forte /> },
    { title: 'Variação', dataIndex: 'variacao', align: 'right', render: (v) => <Kz valor={v} /> },
    {
      title: 'Ajuste (%)', key: 'aj', align: 'right',
      valorImpressao: (l) => (ajustes[String(l.rubrica_id)] ? `${ajustes[String(l.rubrica_id)].toLocaleString('pt-PT')}%` : ''),
      render: (_, l) => <InputNumber size="small" min={-100} max={500} precision={2} style={{ width: 100 }} disabled={!editar} value={ajustes[String(l.rubrica_id)] ?? undefined} placeholder="0"
        onChange={(x) => { setAjustes((s) => { const n = { ...s }; if (x === null || x === 0) delete n[String(l.rubrica_id)]; else n[String(l.rubrica_id)] = x; return n; }); setAlterado(true); }} />,
    },
  ];

  return (
    <Card size="small" title={<Space wrap>{c.nome}{c.tipo && <EtiquetaOrc valor={c.tipo} />}</Space>}
      extra={(
        <Space wrap>
          <BotoesExportar tamanho="small" obterPedido={() => pedidoTabela({
            titulo: 'Cenário what-if',
            subtitulo: `${c.nome}${alterado ? ' (com alterações por gravar)' : ''}`,
            antes: pares([
              ...VARIAVEIS.map((v): [string, string] => [v.rotulo, `${(variaveis[v.chave] ?? 0).toLocaleString('pt-PT')}%`]),
              ['Resultado base (Kz)', formatarKz(r.resultado_base)], ['Resultado do cenário (Kz)', formatarKz(r.resultado_cenario)], ['Variação (Kz)', formatarKz(variacao)],
            ]),
            colunas,
            linhas: r.linhas,
          })} />
          {editar && <>
          <Button type="primary" size="small" icon={<SaveOutlined />} disabled={!alterado} loading={gravar.isPending}
            onClick={() => gravar.mutate({ metodo: 'put', url: `/orcamento/cenarios/${c.id}`, dados: { nome: c.nome, tipo: c.tipo, variaveis, ajustes, notas: c.notas } })}>Gravar e recalcular</Button>
          {podeVersao && (
            <Popconfirm title="Gerar uma nova versão do orçamento a partir deste cenário?" description="Fica em rascunho; o aprovado mantém-se até à aprovação." okText="Gerar" cancelText="Cancelar" disabled={alterado}
              onConfirm={() => gerar.mutate({ url: `/orcamento/cenarios/${c.id}/gerar-versao` })}>
              <Button size="small" icon={<BranchesOutlined />} disabled={alterado} loading={gerar.isPending}>Gerar versão</Button>
            </Popconfirm>
          )}
          <Popconfirm title="Eliminar o cenário?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/orcamento/cenarios/${c.id}` })}>
            <Button size="small" danger icon={<DeleteOutlined />} />
          </Popconfirm>
          </>}
        </Space>
      )}>
      {alterado && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Grave para recalcular o cenário no servidor." />}
      <Row gutter={[12, 12]} style={{ marginBottom: 16 }}>
        {VARIAVEIS.map((v) => (
          <Col key={v.chave} xs={12} md={8} lg={4}>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>{v.rotulo}</Typography.Text>
            <InputNumber size="small" style={{ width: '100%' }} suffix="%" precision={2} disabled={!editar} value={variaveis[v.chave] ?? 0}
              onChange={(x) => { setVariaveis((s) => ({ ...s, [v.chave]: x ?? 0 })); setAlterado(true); }} />
          </Col>
        ))}
      </Row>
      <Row gutter={16} style={{ marginBottom: 16 }}>
        <Col xs={24} sm={8}><Statistic title="Resultado base (Kz)" value={formatarKz(r.resultado_base)} /></Col>
        <Col xs={24} sm={8}><Statistic title="Resultado do cenário (Kz)" value={formatarKz(r.resultado_cenario)} /></Col>
        <Col xs={24} sm={8}><Statistic title="Variação (Kz)" value={formatarKz(variacao)} valueStyle={{ color: variacao < 0 ? '#cf1322' : '#389e0d' }} /></Col>
      </Row>
      <Table
        rowKey="rubrica_id"
        size="small"
        pagination={false}
        dataSource={r.linhas}
        scroll={scrollTabela(480)}
        columns={colunas}
      />
    </Card>
  );
}

function ModalNovo({ orcamento, aoFechar, aoGravar }: { orcamento: number | null; aoFechar: () => void; aoGravar: (c: Cenario) => void }) {
  const [form] = Form.useForm();
  const accao = useAccao<Cenario>({ invalidar: [['orcamento', 'cenarios']], aoSucesso: (c) => { aoGravar(c); aoFechar(); } });
  useEffect(() => { if (orcamento) form.setFieldsValue({ nome: '', tipo: 'PERSONALIZADO', notas: '' }); }, [orcamento, form]);
  return (
    <Modal title="Novo cenário" open={!!orcamento} onCancel={aoFechar} onOk={() => form.submit()} okText="Criar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: '/orcamento/cenarios', dados: { orcamento_anual_id: orcamento, nome: v.nome, tipo: v.tipo, variaveis: {}, ajustes: {}, notas: v.notas || null } })}>
        <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
        <Form.Item name="tipo" label="Tipo"><Select options={[{ value: 'OTIMISTA', label: 'Otimista' }, { value: 'REALISTA', label: 'Realista' }, { value: 'PESSIMISTA', label: 'Pessimista' }, { value: 'PERSONALIZADO', label: 'Personalizado' }]} /></Form.Item>
        <Form.Item name="notas" label="Notas"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}
