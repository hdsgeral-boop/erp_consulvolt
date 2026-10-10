import { Button, Checkbox, Col, Flex, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Switch } from 'antd';
import { MinusCircleOutlined, PlusOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { type ColunaApi } from '@/componentes/TabelaApi';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { SeletorConta } from '@/modulos/compras/comum/Seletores';
import { GRUPOS_LAV, UNIDADES_LAV, useDefinicoesLav, usePecas, useServicosLav } from './dados';
import type { DefinicoesLav, Peca, ServicoLav } from './tipos';
import { ImportarTabela } from './ImportarTabela';

import { IconeLegado, TabelaComModos } from '@/componentes/vistas';
import { Seccao } from '../contas/Seccao';
import '../comum/pos.css';
import { BotaoModeloLav } from './ImportarTabela';

/** «Venda por» como no antigo (lavandaria.js:1303). */
export function rotuloVendaPor(unidade: string | null | undefined): string {
  return unidade === 'KG' ? 'Quilograma (kg)' : 'Peça';
}

/** Preço da tabela de peças: «1 500,00 / peça» ou «por definir» (lavandaria.js:1304). */
export function textoPrecoPeca(p: Pick<Peca, 'preco' | 'unidade'>): string {
  const preco = Number(p.preco ?? 0);
  return preco > 0 ? `${formatarKz(preco)} / ${p.unidade === 'KG' ? 'kg' : 'peça'}` : 'por definir';
}

/** Separador «Peças» (renderPecas, lavandaria.js:1288-1307): tabela de peças com modelos e importação. */
export function TabelaPecas() {
  const { pode } = useSessao();
  const pecas = usePecas(true);
  const servicos = useServicosLav(true);
  const [editar, setEditar] = useState<Peca | 'nova' | null>(null);
  const gerir = pode('lav_tabelas');
  const colunasPecas: ColunaApi<Peca>[] = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => <b>{v}</b> },
    { title: 'Peça', dataIndex: 'nome', render: (v) => <b>{v}</b> },
    { title: 'Tipo de tecido', dataIndex: 'tecido', render: (v) => v || '—' },
    { title: 'Cor', dataIndex: 'cor', render: (v) => v || '—' },
    { title: 'Venda por', dataIndex: 'unidade', render: (v: string) => rotuloVendaPor(v), valorFiltro: (p) => rotuloVendaPor(p.unidade), valorImpressao: (p) => rotuloVendaPor(p.unidade) },
    {
      title: 'Preço (IVA incl.)',
      dataIndex: 'preco',
      align: 'right',
      render: (_, p) => {
        const n = (p.precos_servico ?? []).length;
        return (
          <>
            <span style={{ fontWeight: 700, color: Number(p.preco) > 0 ? undefined : '#b91c1c' }}>{textoPrecoPeca(p)}</span>
            {n > 0 && (
              <div>
                <small style={{ color: '#2563eb', fontWeight: 600 }} title={(p.precos_servico ?? []).map((x) => `${servicos.data?.find((s) => s.id === x.produto_id)?.nome ?? `#${x.produto_id}`}: ${formatarKz(x.preco)}`).join('\n')}>
                  + {n} preço(s) por serviço
                </small>
              </div>
            )}
          </>
        );
      },
      valorImpressao: (p) => `${textoPrecoPeca(p)}${(p.precos_servico ?? []).length ? ` (+ ${(p.precos_servico ?? []).length} preço(s) por serviço)` : ''}`,
    },
    { title: 'Estado', dataIndex: 'ativo', render: (v) => (v === false ? 'Inactiva' : 'Activa'), valorFiltro: (p) => (p.ativo === false ? 'Inactiva' : 'Activa'), valorImpressao: (p) => (p.ativo === false ? 'Inactiva' : 'Activa') },
  ];
  return (
    <Seccao
      titulo="Tabela de peças"
      descricao="Tipo de peça, tecido e cor habituais, unidade de venda (por peça ou por kg) e preço. O código é atribuído automaticamente."
      extra={
        gerir && (
          <>
            <BotaoModeloLav tipo="pecas" />
            <ImportarTabela tipo="pecas" />
            <BotaoModeloLav tipo="precos" />
            <ImportarTabela tipo="precos" />
            <Button type="primary" icon={<IconeLegado icone="plus" />} onClick={() => setEditar('nova')}>
              Nova peça
            </Button>
          </>
        )
      }
    >
      <TabelaComModos<Peca>
        idVista="pecas"
        modos={false}
        rowKey="id"
        size="small"
        scroll={scrollTabela()}
        loading={pecas.isFetching}
        dataSource={pecas.data}
        columns={colunasPecas}
        onRow={(p) => ({ style: p.ativo === false ? { opacity: 0.55 } : undefined })}
        impressao={{ titulo: 'Tabela de peças da lavandaria' }}
        accoes={(p) => [{ icone: 'edit', titulo: 'Editar', oculto: !gerir, onClick: () => setEditar(p) }]}
        vazio={{ icone: 'tshirt', titulo: 'Sem peças.', texto: 'Ex.: Camisa, Calças, Fato, Vestido, Edredão, Roupa diversa (kg).' }}
      />
      <ModalPeca alvo={editar} servicos={servicos.data ?? []} aoFechar={() => setEditar(null)} />
    </Seccao>
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

/** Separador «Serviços» (renderServicos, lavandaria.js:1375-1396): tabela de serviços com modelo e importação. */
export function TabelaServicos() {
  const { pode } = useSessao();
  const servicos = useServicosLav(true);
  const [editar, setEditar] = useState<ServicoLav | 'novo' | null>(null);
  const gerir = pode('lav_tabelas');
  // nomes das contas de proveitos (a mesma consulta do SeletorConta; sem permissão de contabilidade mostra só o código)
  const contas = useQuery({
    queryKey: ['plano', 'movimento', '62'],
    queryFn: () => obter<{ codigo: string; descricao: string }[]>('/contabilidade/plano-contas', { prefixo: '62', tipo: 'M' }),
    staleTime: 600_000,
    retry: false,
  });
  const textoConta = (s: ServicoLav) => {
    if (!String(s.codigo_conta ?? '').startsWith('62')) return 'por definir';
    const nome = contas.data?.find((c) => c.codigo === s.codigo_conta)?.descricao;
    return nome ? `${s.codigo_conta} - ${nome}` : String(s.codigo_conta);
  };
  const colunasServicos: ColunaApi<ServicoLav>[] = [
    { title: 'Código', dataIndex: 'codigo', render: (v) => <b>{v}</b> },
    { title: 'Serviço', dataIndex: 'nome', render: (v) => <b>{v}</b> },
    { title: 'Grupo', dataIndex: 'lavandaria_grupo', render: (v: string) => GRUPOS_LAV[v] ?? v, valorFiltro: (s) => GRUPOS_LAV[s.lavandaria_grupo] ?? s.lavandaria_grupo },
    { title: 'IVA', dataIndex: 'taxa_imposto', align: 'right', render: (v) => `${Number(v)}%` },
    {
      title: 'Conta de proveito',
      dataIndex: 'codigo_conta',
      render: (_, s) => <span style={{ color: textoConta(s) === 'por definir' ? '#b91c1c' : undefined }}>{textoConta(s)}</span>,
      valorFiltro: (s) => textoConta(s),
      valorImpressao: (s) => textoConta(s),
    },
    { title: 'Prazo', dataIndex: 'lavandaria_dias_entrega', align: 'right', render: (v) => `${Number(v ?? 0)} dia(s)` },
    { title: 'Orçamento', dataIndex: 'lavandaria_requer_orcamento', render: (v) => (v ? 'Sim' : 'Não'), valorFiltro: (s) => (s.lavandaria_requer_orcamento ? 'Sim' : 'Não') },
    { title: 'Estado', dataIndex: 'lavandaria_ativa', render: (v) => (v === false ? 'Inactivo' : 'Activo'), valorFiltro: (s) => (s.lavandaria_ativa === false ? 'Inactivo' : 'Activo') },
  ];
  return (
    <Seccao
      titulo="Tabela de serviços"
      descricao="Cada serviço tem conta de proveitos da classe 62, IVA, prazo e indicação de orçamento. O preço é definido na tabela de peças. O código é automático."
      extra={
        gerir && (
          <>
            <BotaoModeloLav tipo="servicos" />
            <ImportarTabela tipo="servicos" />
            <Button type="primary" icon={<IconeLegado icone="plus" />} onClick={() => setEditar('novo')}>
              Novo serviço
            </Button>
          </>
        )
      }
    >
      <TabelaComModos<ServicoLav>
        idVista="servicos"
        modos={false}
        rowKey="id"
        size="small"
        scroll={scrollTabela()}
        loading={servicos.isFetching}
        dataSource={servicos.data}
        columns={colunasServicos}
        onRow={(s) => ({ style: s.lavandaria_ativa === false ? { opacity: 0.55 } : undefined })}
        impressao={{ titulo: 'Tabela de serviços da lavandaria' }}
        accoes={(s) => [{ icone: 'edit', titulo: 'Editar', oculto: !gerir, onClick: () => setEditar(s) }]}
        vazio={{ icone: 'list', titulo: 'Sem serviços.' }}
      />
      <ModalServico alvo={editar} aoFechar={() => setEditar(null)} />
    </Seccao>
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

/** Separador «Definições» (renderDefinicoes, lavandaria.js:1442-1467). */
export function Definicoes() {
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
    <Form.Item name={nome} label={rotulo}>
      <InputNumber<number> min={0} decimalSeparator="," style={{ width: '100%' }} {...extra} />
    </Form.Item>
  );
  // cartão, rótulos e caixas de verificação do antigo (renderDefinicoes, lavandaria.js:1447-1466)
  return (
    <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 4, padding: '1rem', maxWidth: 780 }}>
      <h3 style={{ margin: '0 0 0.7rem', fontSize: '1.05rem' }}>Definições da lavandaria e alfaiataria</h3>
      <Form form={form} layout="vertical" className="lav-definicoes-form" disabled={!pode('lav_tabelas')} onFinish={(v) => gravar.mutate({ metodo: 'put', url: '/pos/lavandaria/definicoes', dados: { ...v, estados_entrada: undefined } })}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '0.7rem' }}>
          <Form.Item label="Taxa de armazenagem">
            <Form.Item name="taxa_armazenagem_ativa" valuePropName="checked" noStyle>
              <Checkbox style={{ height: 32, display: 'flex', alignItems: 'center' }}>Cobrar peças não levantadas</Checkbox>
            </Form.Item>
          </Form.Item>
          {n('dias_armazenagem_gratis', 'Prazo de levantamento (dias)', { precision: 0 })}
          {n('percentagem_armazenagem_dia', 'Taxa de armazenagem (% do serviço por dia)', { precision: 2 })}
          {n('percentagem_adiantamento', 'Adiantamento mínimo do Consumidor Final (%)', { precision: 2, max: 100 })}
          {n('percentagem_urgencia', 'Taxa de urgência (%)', { precision: 2 })}
          {n('fator_prazo_urgencia', 'Prazo do urgente (fracção do prazo normal)', { precision: 2, min: 0.1, max: 1, step: 0.05 })}
          {n('valor_taxa_recolha', 'Taxa de recolha ao domicílio', { precision: 2 })}
          {n('valor_taxa_entrega', 'Taxa de entrega ao domicílio', { precision: 2 })}
          {n('dias_reclamacao', 'Prazo de reclamação (dias após a entrega)', { precision: 0 })}
          <Form.Item name="conta_extras" label="Conta de proveitos das taxas (classe 62)">
            <SeletorConta prefixo="62" placeholder="— seleccionar —" />
          </Form.Item>
          {n('taxa_extras', 'IVA das taxas (%)', { precision: 2 })}
          <Form.Item name="conta_compensacao" label="Conta das indemnizações por danos (custo)">
            <SeletorConta prefixo="7" placeholder="— seleccionar —" />
          </Form.Item>
        </div>
        <Form.Item name="faturar_no_adiantamento" valuePropName="checked" style={{ margin: '0.9rem 0 0' }}>
          <Checkbox style={{ fontSize: '0.85rem', alignItems: 'flex-start' }}>
            <strong>Facturar ao receber pagamentos antes da facturação</strong>
            <br />
            <small style={{ color: '#64748b' }}>
              O IVA é exigível no recebimento de adiantamentos: um pagamento numa ordem ainda não facturada emite o documento dos serviços confirmados (Factura-Recibo se pagar exactamente esse valor; senão Factura e Recibo).
            </small>
          </Checkbox>
        </Form.Item>
        {pode('lav_tabelas') && (
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '1rem' }}>
            <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={gravar.isPending}>
              Gravar
            </Button>
          </div>
        )}
      </Form>
    </div>
  );
}
