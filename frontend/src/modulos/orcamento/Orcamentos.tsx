import { Button, Card, Col, Form, Input, InputNumber, Modal, Row, Select, Typography } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { SeletorProjecto } from '@/modulos/activos/comum/componentes';
import { formatarKz } from '@/utilitarios/formatacao';
import { IconeFa } from '@/componentes/fluxos';
import { rotuloOrc, SeletorOrcamento, useOrcamentosLegado } from './comum/componentes';
import { chipsPreparacao, COR_ESTADO, textoPreparacao, textoResponsavel, textoUltimaAccao } from './comum/lista';
import type { Orcamento, OrcamentoLista, TipoOrcamento } from './comum/tipos';
import { DetalheOrcamento } from './DetalheOrcamento';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';

import { TabelaComModos } from '@/componentes/vistas';

/** Estado com as cores do legado (.orc-estado). */
function EstadoOrc({ valor }: { valor: string }) {
  return <span style={{ display: 'inline-block', color: '#fff', background: COR_ESTADO[valor] ?? '#64748b', borderRadius: 4, padding: '0 6px', fontSize: 11.5, fontWeight: 600, whiteSpace: 'nowrap' }}>{rotuloOrc(valor)}</span>;
}

/** Coluna «Preparação»: chips (base zero, top-down/bottom-up) e responsável com prazo. */
function Preparacao({ o }: { o: OrcamentoLista }) {
  const resp = textoResponsavel(o);
  return (
    <span style={{ fontSize: 12.5 }}>
      {chipsPreparacao(o).map((c) => <span key={c.texto} style={{ display: 'inline-block', fontSize: 11.5, background: c.fundo, color: c.cor, borderRadius: 4, padding: '0 5px', margin: '1px 2px 1px 0' }}>{c.texto}</span>)}
      {resp && <div style={{ color: '#475569' }}><IconeFa nome="user" /> {resp}</div>}
    </span>
  );
}
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
  const { pode, utilizador } = useSessao();
  const [ano, setAno] = useState<number | undefined>();
  const [tipo, setTipo] = useState<TipoOrcamento>();
  const [soMeus, setSoMeus] = useState(false);
  const [novo, setNovo] = useState(false);
  const q = useOrcamentosLegado({ ano, tipo });
  const todos = q.data?.orcamentos ?? [];
  const meus = todos.filter((o) => o.estado === 'RASCUNHO' && !!o.responsavel && o.responsavel === utilizador?.nome_utilizador);
  const linhas = soMeus ? meus : todos;

  const colunas: ColunaApi<OrcamentoLista>[] = [
    { title: 'Ano', dataIndex: 'ano', render: (v) => <strong>{v}</strong> },
    { title: 'Tipo', dataIndex: 'tipo', valorFiltro: (o) => rotuloOrc(o.tipo), valorImpressao: (o) => rotuloOrc(o.tipo), render: (v) => rotuloOrc(v) },
    {
      title: 'Nome', dataIndex: 'nome', valorImpressao: (o) => `${'  '.repeat(o.nivel)}${o.nome ?? '—'}`,
      render: (v, o) => <span style={{ paddingLeft: `${o.nivel * 1.2}rem` }}>{o.nivel > 0 && <IconeFa nome="level-up-alt" style={{ color: '#94a3b8', marginRight: 4, transform: 'rotate(90deg)' }} />}{v ?? '—'}</span>,
    },
    { title: 'Dimensões', dataIndex: 'dimensoes', render: (v) => <span style={{ fontSize: 12.5 }}>{v}</span> },
    { title: 'Preparação', key: 'prep', valorFiltro: textoPreparacao, valorImpressao: textoPreparacao, render: (_, o) => <Preparacao o={o} /> },
    { title: 'Versão', dataIndex: 'versao', align: 'right', render: (v) => `v${v}` },
    { title: 'Estado', dataIndex: 'estado', valorFiltro: (o) => rotuloOrc(o.estado), valorImpressao: (o) => rotuloOrc(o.estado), render: (v) => <EstadoOrc valor={v} /> },
    { title: 'Total das rubricas', dataIndex: 'total_rubricas', align: 'right', render: (v) => formatarKz(v, true), valorImpressao: (o) => formatarKz(o.total_rubricas, true) },
    { title: 'Última acção', key: 'ultima', valorFiltro: textoUltimaAccao, valorImpressao: textoUltimaAccao, render: (_, o) => <span style={{ fontSize: 12, color: '#64748b' }}>{textoUltimaAccao(o)}</span> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Orçamentos"
        subtitulo="Orçamento de exploração e de tesouraria por ano, unidade de negócio e centro de custo. Rascunho → submetido → aprovado; alterações a um aprovado fazem-se numa nova versão."
        accoes={pode('orc_editar') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo orçamento</Button>}
      />
      {meus.length > 0 && (
        <div style={{ border: '1px solid #bfdbfe', background: '#eff6ff', color: '#1e3a8a', borderRadius: 4, padding: '0.5rem 0.7rem', fontSize: 13, marginBottom: 10, display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span><IconeFa nome="user-edit" /> Tem <b>{meus.length}</b> contributo(s) orçamental(is) por preencher como responsável.</span>
          <Button size="small" onClick={() => setSoMeus((x) => !x)}>{soMeus ? 'Ver todos os orçamentos' : 'Ver só os meus contributos'}</Button>
        </div>
      )}
      <Card>
        <BarraFiltros>
          <InputNumber prefix="Ano" min={2000} max={2100} value={ano} onChange={(v) => setAno(v ?? undefined)} style={{ width: 150 }} placeholder="Todos" />
          <Select placeholder="Tipo" allowClear value={tipo} onChange={setTipo} style={{ width: 160 }} options={[{ value: 'EXPLORACAO', label: 'Exploração' }, { value: 'TESOURARIA', label: 'Tesouraria' }]} />
        </BarraFiltros>
        <TabelaComModos<OrcamentoLista>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={linhas}
          scroll={scrollTabela()}
          columns={colunas}
          impressao={{ titulo: 'Lista de orçamentos', filtros: [ano ? `Ano: ${ano}` : null, tipo ? `Tipo: ${rotuloOrc(tipo)}` : null].filter((x): x is string => !!x) }}
          vazio={{ icone: 'file-invoice-dollar', titulo: 'Ainda não há orçamentos', texto: 'Crie primeiro as rubricas (Rubricas Orçamentais) e depois «Novo orçamento».' }}
          accoes={(o) => [
            { icone: o.estado === 'RASCUNHO' ? 'edit' : 'eye', titulo: o.estado === 'RASCUNHO' ? 'Editar' : 'Abrir', texto: o.estado === 'RASCUNHO' ? 'Editar' : 'Abrir', variante: 'primario', cheio: true, onClick: () => navegar(String(o.id)) },
            { icone: 'balance-scale', titulo: 'Orçado vs realizado', oculto: !pode('orc_controlo_view'), onClick: () => navegar(`/m/orcamento/orc_controlo?orcamento=${o.id}`) },
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
  const anoForm = Form.useWatch('ano', form);
  const accao = useAccao<Orcamento>({ invalidar: [['orcamento']], aoSucesso: (o) => { aoGravar(o); aoFechar(); } });
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ ano: dayjs().year() + (dayjs().month() >= 9 ? 1 : 0), tipo: 'EXPLORACAO', metodo: 'HISTORICO', origem: 'REALIZADO_ANTERIOR' }); } }, [aberto, form]);

  return (
    <Modal title="Novo orçamento" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Criar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(820)} destroyOnHidden>
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
          <Col xs={24} md={8}><Form.Item name="orcamento_pai_id" label="Orçamento pai (opcional)"><SeletorOrcamento allowClear ano={anoForm} tipo={tipo} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="responsavel" label="Responsável (utilizador)"><Input maxLength={100} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={2000} /></Form.Item></Col>
        </Row>
      </Form>
      <Typography.Text type="secondary">A combinação ano / tipo / UN / CC / projecto é única para todas as versões.</Typography.Text>
    </Modal>
  );
}
