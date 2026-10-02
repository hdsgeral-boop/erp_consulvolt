import { Button, Card, Checkbox, Col, Form, Input, InputNumber, Modal, Popconfirm, Row, Segmented, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined, ThunderboltOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { EtiquetaOrc, useRubricas } from './comum/componentes';
import type { Rubrica, TipoOrcamento } from './comum/tipos';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { pedidoTabela } from './comum/impressao';

const NATUREZAS: Record<TipoOrcamento, { value: string; label: string }[]> = {
  EXPLORACAO: [{ value: 'PROVEITO', label: 'Proveito' }, { value: 'CUSTO', label: 'Custo' }],
  TESOURARIA: [{ value: 'RECEBIMENTO', label: 'Recebimento' }, { value: 'PAGAMENTO', label: 'Pagamento' }],
};

/** Orçamento › Rubricas (ecrã orc_rubricas): rubricas de exploração e tesouraria, ligação às contas e controlo de excesso. */
export default function Rubricas() {
  const { pode } = useSessao();
  const [tipo, setTipo] = useState<TipoOrcamento>('EXPLORACAO');
  const [texto, setTexto] = useState('');
  const [editar, setEditar] = useState<Partial<Rubrica> | null>(null);
  const q = useRubricas(tipo);
  const accao = useAccao<{ criadas: unknown[]; ignoradas: unknown[] }>({ invalidar: [['orcamento']] });
  const gerir = pode('orc_rubricas_edit');
  const linhas = (q.data ?? []).filter((r) => contemTexto(texto, r.codigo, r.nome, r.grupo, ...r.contas.map((x) => x.codigo)));

  const colunas: ColunaApi<Rubrica>[] = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => <strong>{v}</strong> },
    { title: 'Nome', dataIndex: 'nome' },
    { title: 'Natureza', dataIndex: 'natureza', render: (v) => <EtiquetaOrc valor={v} /> },
    { title: 'Grupo', dataIndex: 'grupo', render: (v) => v ?? '—' },
    { title: 'Contas', dataIndex: 'contas', valorImpressao: (r) => r.contas.map((x) => `${x.codigo}${x.prefixo ? '*' : ''}`).join(', '), render: (cs: Rubrica['contas']) => <Space size={2} wrap>{cs.map((x) => <Tag key={x.codigo}>{x.codigo}{x.prefixo ? '*' : ''}</Tag>)}</Space> },
    { title: 'Controlo', key: 'ctl', responsive: ['md'], render: (_, r) => (r.controlo?.modo && r.controlo.modo !== 'NENHUM' ? <><EtiquetaOrc valor={r.controlo.modo} /><Typography.Text type="secondary">{r.controlo.aviso_pct ?? 90}% / {r.controlo.limite_pct ?? 100}%</Typography.Text></> : '—') },
    { title: 'Activa', dataIndex: 'ativo', render: (v) => (v === false ? <Tag>Inactiva</Tag> : <Tag color="green">Sim</Tag>) },
    {
      title: '', key: 'acc', align: 'right',
      render: (_, r) => gerir && (
        <Space>
          <Button size="small" icon={<EditOutlined />} onClick={() => setEditar(r)} />
          <Popconfirm title={`Eliminar a rubrica ${r.codigo}?`} description="Rubricas usadas em orçamentos, previsões ou pedidos não se eliminam." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
            onConfirm={() => accao.mutate({ metodo: 'delete', url: `/orcamento/rubricas/${r.id}` })}>
            <Button size="small" danger icon={<DeleteOutlined />} />
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Rubricas orçamentais"
        subtitulo="Cada conta pertence a uma só rubrica do mesmo tipo (exacta ou por prefixo)"
        accoes={gerir && (
          <>
            <Popconfirm title={`Criar as rubricas base do PGC (${tipo === 'EXPLORACAO' ? 'exploração' : 'tesouraria'})?`} description="Só se criam as que existem no plano e não se sobrepõem às actuais." okText="Criar" cancelText="Cancelar"
              onConfirm={() => accao.mutate({ url: '/orcamento/rubricas/base', dados: { tipo } })}>
              <Button icon={<ThunderboltOutlined />} loading={accao.isPending}>Rubricas base</Button>
            </Popconfirm>
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar({ tipo, ativo: true, contas: [] })}>Nova rubrica</Button>
          </>
        )}
        impressaoDesactivada={!linhas.length}
        impressao={() => pedidoTabela({ titulo: `Rubricas orçamentais (${tipo === 'EXPLORACAO' ? 'exploração' : 'tesouraria'})`, filtros: texto ? [`Pesquisa: ${texto}`] : undefined, colunas, linhas })}
      />
      <Card>
        <BarraFiltros>
          <Segmented value={tipo} onChange={(v) => setTipo(v as TipoOrcamento)} options={[{ value: 'EXPLORACAO', label: 'Exploração' }, { value: 'TESOURARIA', label: 'Tesouraria' }]} />
          <Input.Search placeholder="Código, nome, grupo ou conta" allowClear onSearch={setTexto} style={{ width: 280 }} />
        </BarraFiltros>
        <Table<Rubrica>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={linhas}
          pagination={false}
          scroll={scrollTabela()}
          columns={colunas}
        />
        <Typography.Text type="secondary">* conta por prefixo (abrange as subcontas)</Typography.Text>
      </Card>
      <ModalRubrica rubrica={editar} aoFechar={() => setEditar(null)} />
    </>
  );
}

function ModalRubrica({ rubrica, aoFechar }: { rubrica: Partial<Rubrica> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const tipo: TipoOrcamento = Form.useWatch('tipo', form) ?? rubrica?.tipo ?? 'EXPLORACAO';
  const natureza = Form.useWatch('natureza', form);
  const modo = Form.useWatch(['controlo', 'modo'], form);
  const accao = useAccao({ invalidar: [['orcamento']], aoSucesso: () => aoFechar() });
  const comControlo = natureza === 'CUSTO' || natureza === 'PAGAMENTO';
  useEffect(() => {
    if (!rubrica) return;
    form.resetFields();
    form.setFieldsValue({
      ...rubrica,
      contas: rubrica.contas?.length ? rubrica.contas.map((x) => ({ codigo: x.codigo, prefixo: !!x.prefixo })) : [{ codigo: '', prefixo: true }],
      controlo: { modo: 'NENHUM', aviso_pct: 90, limite_pct: 100, base: 'ACUMULADO', ...(rubrica.controlo ?? {}) },
      cambial_pct: rubrica.cambial_pct !== null && rubrica.cambial_pct !== undefined ? Number(rubrica.cambial_pct) : undefined,
      variavel_pct: rubrica.variavel_pct !== null && rubrica.variavel_pct !== undefined ? Number(rubrica.variavel_pct) : undefined,
    });
  }, [rubrica, form]);

  return (
    <Modal title={rubrica?.id ? `Editar rubrica ${rubrica.codigo}` : 'Nova rubrica'} open={!!rubrica} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(820)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = { ...v, grupo: v.grupo || null, descricao: v.descricao || null, indutor: v.indutor || null, controlo: comControlo ? v.controlo : null,
          contas: v.contas.filter((x: { codigo: string }) => x.codigo?.trim()).map((x: { codigo: string; prefixo: boolean }) => ({ codigo: x.codigo.trim(), prefixo: !!x.prefixo })) };
        accao.mutate(rubrica?.id ? { metodo: 'put', url: `/orcamento/rubricas/${rubrica.id}`, dados } : { url: '/orcamento/rubricas', dados });
      }}>
        <Row gutter={12}>
          <Col xs={24} md={8}><Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select options={[{ value: 'EXPLORACAO', label: 'Exploração' }, { value: 'TESOURARIA', label: 'Tesouraria' }]} onChange={() => form.setFieldValue('natureza', undefined)} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="natureza" label="Natureza" rules={[{ required: true, message: 'Escolha a natureza.' }]}><Select options={NATUREZAS[tipo]} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="ativo" label="Activa" valuePropName="checked"><Switch /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }]}><Input maxLength={50} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="ordem" label="Ordem"><InputNumber style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="grupo" label="Grupo"><Input maxLength={50} placeholder="Ex.: Custos operacionais" /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="descricao" label="Descrição"><Input maxLength={2000} /></Form.Item></Col>
        </Row>
        <Typography.Text strong>Contas</Typography.Text>
        {tipo === 'TESOURARIA' && <Typography.Paragraph type="secondary" style={{ margin: 0 }}>Na tesouraria indicam-se as contrapartidas (não as contas 43/45).</Typography.Paragraph>}
        <Form.List name="contas" rules={[{ validator: (_, v) => (v?.some((x: { codigo?: string }) => x?.codigo?.trim()) ? Promise.resolve() : Promise.reject(new Error('Indique pelo menos uma conta.'))) }]}>
          {(campos, { add, remove }, { errors }) => (
            <div style={{ marginTop: 8 }}>
              {campos.map((c) => (
                <Space key={c.key} align="baseline">
                  <Form.Item name={[c.name, 'codigo']}><Input placeholder="Conta" maxLength={20} style={{ width: 160 }} /></Form.Item>
                  <Form.Item name={[c.name, 'prefixo']} valuePropName="checked"><Checkbox>Prefixo</Checkbox></Form.Item>
                  <Button danger size="small" icon={<DeleteOutlined />} onClick={() => remove(c.name)} />
                </Space>
              ))}
              <Form.ErrorList errors={errors} />
              <Button size="small" icon={<PlusOutlined />} onClick={() => add({ codigo: '', prefixo: true })}>Conta</Button>
            </div>
          )}
        </Form.List>
        {comControlo && (
          <Card size="small" title="Controlo de excesso nos documentos" style={{ marginTop: 16 }}>
            <Row gutter={12}>
              <Col xs={24} md={8}>
                <Form.Item name={['controlo', 'modo']} label="Modo">
                  <Select options={[{ value: 'NENHUM', label: 'Sem controlo' }, { value: 'AVISAR', label: 'Avisar' }, { value: 'APROVACAO', label: 'Exigir aprovação' }, { value: 'BLOQUEAR', label: 'Bloquear' }]} />
                </Form.Item>
              </Col>
              {modo && modo !== 'NENHUM' && (
                <>
                  <Col xs={8} md={5}><Form.Item name={['controlo', 'aviso_pct']} label="Aviso (%)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={8} md={5}>
                    <Form.Item name={['controlo', 'limite_pct']} label="Limite (%)" dependencies={[['controlo', 'aviso_pct']]} rules={[({ getFieldValue }) => ({
                      validator: (_, v) => (v === undefined || v === null || v >= (getFieldValue(['controlo', 'aviso_pct']) ?? 0) ? Promise.resolve() : Promise.reject(new Error('Inferior ao aviso.'))),
                    })]}>
                      <InputNumber min={0} style={{ width: '100%' }} />
                    </Form.Item>
                  </Col>
                  <Col xs={8} md={6}><Form.Item name={['controlo', 'base']} label="Base"><Select options={[{ value: 'ACUMULADO', label: 'Acumulado ao mês' }, { value: 'ANO', label: 'Ano inteiro' }]} /></Form.Item></Col>
                </>
              )}
            </Row>
          </Card>
        )}
        <Card size="small" title="Cenários (what-if)" style={{ marginTop: 16 }}>
          <Row gutter={12}>
            <Col xs={24} md={8}><Form.Item name="indutor" label="Indutor" tooltip="Vazio = deduzido do PGC"><Select allowClear options={['vendas_volume_pct', 'vendas_preco_pct', 'materias_pct', 'pessoal_pct', 'outros_pct'].map((x) => ({ value: x, label: x.replace('_pct', '').replace('_', ' ') }))} /></Form.Item></Col>
            <Col xs={12} md={8}><Form.Item name="variavel_pct" label="Parte variável (%)"><InputNumber min={0} max={100} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} md={8}><Form.Item name="cambial_pct" label="Exposição cambial (%)"><InputNumber min={0} max={100} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
        </Card>
      </Form>
    </Modal>
  );
}
