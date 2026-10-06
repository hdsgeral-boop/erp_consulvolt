import { Button, Card, Col, Flex, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Space, Switch, Tabs, Tag } from 'antd';
import { EditOutlined, MinusCircleOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { colunasParaImpressao, type ColunaApi } from '@/componentes/TabelaApi';
import { BotoesExportar, prepararTexto, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { GRUPOS_LAV, UNIDADES_LAV, useDefinicoesLav, usePecas, useServicosLav } from './dados';
import type { DefinicoesLav, Peca, ServicoLav } from './tipos';
import { ImportarTabela } from './ImportarTabela';

import { TabelaComModos } from '@/componentes/vistas';
/** Tabelas da lavandaria: peças (com preço por serviço), serviços (conta e IVA) e definições (taxas, adiantamento, contas). */
export function Tabelas() {
  return (
    <Tabs
      size="small"
      items={[
        { key: 'pecas', label: 'Peças', children: <TabelaPecas /> },
        { key: 'servicos', label: 'Serviços', children: <TabelaServicos /> },
        { key: 'definicoes', label: 'Definições', children: <Definicoes /> },
      ]}
    />
  );
}

function TabelaPecas() {
  const { pode } = useSessao();
  const pecas = usePecas(true);
  const servicos = useServicosLav(true);
  const [editar, setEditar] = useState<Peca | 'nova' | null>(null);
  const nomeServico = (id: number) => servicos.data?.find((s) => s.id === id)?.nome ?? `#${id}`;
  const colunasPecas: ColunaApi<Peca>[] = [
          { title: 'Código', dataIndex: 'codigo' },
          { title: 'Peça', dataIndex: 'nome' },
          { title: 'Tecido', dataIndex: 'tecido', render: (v) => v ?? '—', responsive: ['md'] },
          { title: 'Cor', dataIndex: 'cor', render: (v) => v ?? '—', responsive: ['md'] },
          { title: 'Unidade', dataIndex: 'unidade', render: (v: string) => UNIDADES_LAV[v] ?? v },
          { title: 'Preço base', dataIndex: 'preco', align: 'right', render: (v) => formatarKz(v) },
          { title: 'Preços por serviço', dataIndex: 'precos_servico', responsive: ['lg'], render: (v: Peca['precos_servico']) => (v ?? []).map((p) => <Tag key={p.produto_id}>{`${nomeServico(p.produto_id)}: ${formatarKz(p.preco)}`}</Tag>) },
          { title: 'Estado', dataIndex: 'ativo', render: (v) => (v === false ? <Tag>Inactiva</Tag> : <Tag color="green">Activa</Tag>) },
          { title: '', key: 'a', exportar: false, render: (_, p) => pode('lav_tabelas') && <Button size="small" icon={<EditOutlined />} onClick={() => setEditar(p)} aria-label="Editar" /> },
        ];
  return (
    <>
      <BarraFiltros
        accoes={
          <BotoesExportar
            tamanho="small"
            desactivado={!pecas.data?.length}
            obterPedido={async () => {
              await prepararTexto();
              const linhas = pecas.data ?? [];
              return { titulo: 'Tabela de peças da lavandaria', conteudo: tabelaHtml({ colunas: colunasParaImpressao(colunasPecas, linhas), linhas }) };
            }}
          />
        }
      >
        {pode('lav_tabelas') && (
          <>
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar('nova')}>
              Nova peça
            </Button>
            <ImportarTabela tipo="pecas" />
            <ImportarTabela tipo="precos" />
          </>
        )}
      </BarraFiltros>
      <TabelaComModos<Peca> idVista="pecas"
        rowKey="id"
        size="small"
        scroll={scrollTabela()}
        loading={pecas.isFetching}
        dataSource={pecas.data}
        columns={colunasPecas}
      />
      <ModalPeca alvo={editar} servicos={servicos.data ?? []} aoFechar={() => setEditar(null)} />
    </>
  );
}

function ModalPeca({ alvo, servicos, aoFechar }: { alvo: Peca | 'nova' | null; servicos: ServicoLav[]; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const p = alvo && alvo !== 'nova' ? alvo : null;
  const gravar = useAccao({ invalidar: [['pos', 'lavandaria']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar a peça' });
  useEffect(() => {
    if (!alvo) return;
    form.resetFields();
    form.setFieldsValue(
      p
        ? { ...p, preco: Number(p.preco), ativo: p.ativo !== false, precos_servico: (p.precos_servico ?? []).map((x) => ({ produto_id: x.produto_id, preco: x.preco === null ? null : Number(x.preco) })) }
        : { unidade: 'PECA', preco: 0, ativo: true, precos_servico: [] },
    );
  }, [alvo, p, form]);
  return (
    <Modal open={!!alvo} title={p ? `Peça ${p.nome}` : 'Nova peça'} width={larguraModal(640)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onCancel={aoFechar} onOk={() => form.submit()} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(p ? { metodo: 'put', url: `/pos/lavandaria/pecas/${p.id}`, dados: v } : { url: '/pos/lavandaria/pecas', dados: v })}>
        <Row gutter={[12, 0]}>
          <Col xs={24} sm={12}>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}>
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={12} sm={6}>
            <Form.Item name="unidade" label="Unidade">
              <Select options={Object.entries(UNIDADES_LAV).map(([value, label]) => ({ value, label }))} />
            </Form.Item>
          </Col>
          <Col xs={12} sm={6}>
            <Form.Item name="ativo" label="Activa" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="tecido" label="Tecido">
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="cor" label="Cor">
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="preco" label="Preço base (c/ IVA)">
              <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
        <Form.List name="precos_servico">
          {(campos, { add, remove }) => (
            <>
              {campos.map((c) => (
                <Flex key={c.key} gap={8} align="baseline">
                  <Form.Item name={[c.name, 'produto_id']} rules={[{ required: true, message: 'Serviço' }]} style={{ flex: 1 }}>
                    <Select placeholder="Serviço" options={servicos.map((s) => ({ value: s.id, label: s.nome }))} />
                  </Form.Item>
                  <Form.Item name={[c.name, 'preco']}>
                    <InputNumber<number> min={0} precision={2} decimalSeparator="," placeholder="Preço" style={{ width: 150, maxWidth: '100%' }} />
                  </Form.Item>
                  <MinusCircleOutlined onClick={() => remove(c.name)} />
                </Flex>
              ))}
              <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ preco: null })}>
                Preço específico por serviço
              </Button>
            </>
          )}
        </Form.List>
      </Form>
    </Modal>
  );
}

function TabelaServicos() {
  const { pode } = useSessao();
  const servicos = useServicosLav(true);
  const [editar, setEditar] = useState<ServicoLav | 'novo' | null>(null);
  const colunasServicos: ColunaApi<ServicoLav>[] = [
          { title: 'Código', dataIndex: 'codigo' },
          { title: 'Serviço', dataIndex: 'nome' },
          { title: 'Grupo', dataIndex: 'lavandaria_grupo', render: (v: string) => GRUPOS_LAV[v] ?? v },
          { title: 'Conta', dataIndex: 'codigo_conta', responsive: ['md'] },
          { title: 'IVA', dataIndex: 'taxa_imposto', align: 'right', render: (v) => `${Number(v)}%` },
          { title: 'Prazo (dias)', dataIndex: 'lavandaria_dias_entrega', align: 'right', responsive: ['md'] },
          { title: 'Orçamento', dataIndex: 'lavandaria_requer_orcamento', render: (v) => (v ? 'Sim' : 'Não') },
          { title: 'Estado', dataIndex: 'lavandaria_ativa', render: (v) => (v === false ? <Tag>Inactivo</Tag> : <Tag color="green">Activo</Tag>) },
          { title: '', key: 'a', exportar: false, render: (_, s) => pode('lav_tabelas') && <Button size="small" icon={<EditOutlined />} onClick={() => setEditar(s)} aria-label="Editar" /> },
        ];
  return (
    <>
      <BarraFiltros
        accoes={
          <BotoesExportar
            tamanho="small"
            desactivado={!servicos.data?.length}
            obterPedido={async () => {
              await prepararTexto();
              const linhas = servicos.data ?? [];
              return { titulo: 'Tabela de serviços da lavandaria', conteudo: tabelaHtml({ colunas: colunasParaImpressao(colunasServicos, linhas), linhas }) };
            }}
          />
        }
      >
        {pode('lav_tabelas') && (
          <>
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar('novo')}>
              Novo serviço
            </Button>
            <ImportarTabela tipo="servicos" />
          </>
        )}
      </BarraFiltros>
      <TabelaComModos<ServicoLav> idVista="servicos"
        rowKey="id"
        size="small"
        scroll={scrollTabela()}
        loading={servicos.isFetching}
        dataSource={servicos.data}
        columns={colunasServicos}
      />
      <ModalServico alvo={editar} aoFechar={() => setEditar(null)} />
    </>
  );
}

function ModalServico({ alvo, aoFechar }: { alvo: ServicoLav | 'novo' | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const s = alvo && alvo !== 'novo' ? alvo : null;
  const gravar = useAccao({ invalidar: [['pos', 'lavandaria'], ['logistica', 'catalogo']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar o serviço' });
  useEffect(() => {
    if (!alvo) return;
    form.resetFields();
    form.setFieldsValue(
      s
        ? { nome: s.nome, grupo: s.lavandaria_grupo, codigo_conta: s.codigo_conta, taxa_imposto: Number(s.taxa_imposto), dias_entrega: s.lavandaria_dias_entrega, requer_orcamento: !!s.lavandaria_requer_orcamento, ativa: s.lavandaria_ativa !== false }
        : { grupo: 'LAVANDARIA', taxa_imposto: 14, dias_entrega: 2, requer_orcamento: false, ativa: true },
    );
  }, [alvo, s, form]);
  return (
    <Modal open={!!alvo} title={s ? `Serviço ${s.nome}` : 'Novo serviço'} width={larguraModal(620)} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} onCancel={aoFechar} onOk={() => form.submit()} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(s ? { metodo: 'put', url: `/pos/lavandaria/servicos/${s.id}`, dados: v } : { url: '/pos/lavandaria/servicos', dados: v })}>
        <Row gutter={[12, 0]}>
          <Col xs={24} sm={14}>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}>
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={10}>
            <Form.Item name="grupo" label="Grupo">
              <Select options={Object.entries(GRUPOS_LAV).map(([value, label]) => ({ value, label }))} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="codigo_conta" label="Conta de proveitos (62)" rules={[{ required: true, message: 'Indique a conta.' }]}>
              <SeletorConta prefixo="62" />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="conta_iva_liquidado" label="Conta de IVA liquidado">
              <SeletorConta prefixo="34" />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="taxa_imposto" label="IVA (%)">
              <InputNumber<number> min={0} precision={2} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="dias_entrega" label="Prazo (dias)">
              <InputNumber<number> min={0} precision={0} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={8}>
            <Form.Item name="codigo_isencao_fe" label="Código de isenção">
              <Input maxLength={10} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="requer_orcamento" label="Requer orçamento" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="ativa" label="Activo" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </Modal>
  );
}

function Definicoes() {
  const { pode } = useSessao();
  const definicoes = useDefinicoesLav();
  const [form] = Form.useForm();
  const gravar = useAccao<DefinicoesLav>({ invalidar: [['pos', 'lavandaria', 'definicoes']], tituloErro: 'Não foi possível gravar as definições' });
  useEffect(() => {
    if (definicoes.data)
      form.setFieldsValue({
        ...definicoes.data,
        percentagem_armazenagem_dia: Number(definicoes.data.percentagem_armazenagem_dia),
        percentagem_adiantamento: Number(definicoes.data.percentagem_adiantamento),
        percentagem_urgencia: Number(definicoes.data.percentagem_urgencia),
        valor_taxa_recolha: Number(definicoes.data.valor_taxa_recolha),
        valor_taxa_entrega: Number(definicoes.data.valor_taxa_entrega),
        taxa_extras: Number(definicoes.data.taxa_extras),
      });
  }, [definicoes.data, form]);
  if (!definicoes.data) return <Skeleton active />;
  const n = (nome: string, rotulo: string, extra: Record<string, unknown> = {}) => (
    <Col xs={24} md={8}>
      <Form.Item name={nome} label={rotulo}>
        <InputNumber<number> min={0} decimalSeparator="," style={{ width: '100%' }} {...extra} />
      </Form.Item>
    </Col>
  );
  return (
    <Card size="small">
      <Form form={form} layout="vertical" disabled={!pode('lav_tabelas')} onFinish={(v) => gravar.mutate({ metodo: 'put', url: '/pos/lavandaria/definicoes', dados: { ...v, estados_entrada: undefined } })}>
        <Row gutter={[12, 0]}>
          <Col xs={24} md={8}>
            <Form.Item name="taxa_armazenagem_ativa" label="Taxa de armazenagem activa" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Col>
          {n('dias_armazenagem_gratis', 'Dias de armazenagem grátis', { precision: 0 })}
          {n('percentagem_armazenagem_dia', 'Armazenagem por dia (%)', { precision: 2 })}
          {n('percentagem_adiantamento', 'Adiantamento mínimo — Consumidor Final (%)', { precision: 2, max: 100 })}
          {n('percentagem_urgencia', 'Taxa de urgência (%)', { precision: 2 })}
          {n('fator_prazo_urgencia', 'Factor de prazo da urgência', { precision: 2, min: 0.1, max: 1, step: 0.1 })}
          {n('valor_taxa_recolha', 'Taxa de recolha (Kz)', { precision: 2 })}
          {n('valor_taxa_entrega', 'Taxa de entrega (Kz)', { precision: 2 })}
          {n('dias_reclamacao', 'Prazo de reclamação (dias)', { precision: 0 })}
          <Col xs={24} md={8}>
            <Form.Item name="conta_extras" label="Conta das taxas (extras)">
              <SeletorConta prefixo="62" />
            </Form.Item>
          </Col>
          {n('taxa_extras', 'IVA das taxas (%)', { precision: 2 })}
          <Col xs={24} md={8}>
            <Form.Item name="conta_compensacao" label="Conta das indemnizações">
              <SeletorConta />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="faturar_no_adiantamento" label="Facturar no adiantamento" valuePropName="checked">
              <Switch />
            </Form.Item>
          </Col>
        </Row>
        {pode('lav_tabelas') && (
          <Space wrap>
            <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={gravar.isPending}>
              Gravar definições
            </Button>
          </Space>
        )}
      </Form>
    </Card>
  );
}
