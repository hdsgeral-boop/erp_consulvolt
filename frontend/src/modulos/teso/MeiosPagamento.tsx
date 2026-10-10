import { Button, Card, Checkbox, Form, Input, Modal, Space, Tag, Tooltip, message } from 'antd';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { SeletorConta } from '../contab/comum/Seletores';
import type { MeioPagamento } from './api';
import { useMeiosPagamento } from './comum';
import { larguraModal } from '@/componentes/responsivo';
import { CartaoKpi, GrelhaKpi, TabelaComModos, type AccaoLinha, type ColunaLegado } from '@/componentes/vistas';

interface ValoresMeio {
  nome: string;
  codigo_conta: string;
  iban?: string;
  swift?: string;
  ativo: boolean;
  predefinido: boolean;
}

/** Tipo do meio pela conta: 43 = Banco, resto (45) = Caixa (typeBadge do legado). */
export function tipoMeio(m: Pick<MeioPagamento, 'codigo_conta' | 'tipo'>): 'BANCO' | 'CAIXA' {
  return m.tipo ?? (m.codigo_conta?.startsWith('43') ? 'BANCO' : 'CAIXA');
}

/** Resumo informativo do legado: Meios Bancários (43), Caixas (45), Com IBAN, Ativos. */
export function resumoMeios(meios: MeioPagamento[]): { bancos: number; caixas: number; comIban: number; activos: number } {
  return {
    bancos: meios.filter((m) => m.codigo_conta?.startsWith('43')).length,
    caixas: meios.filter((m) => m.codigo_conta?.startsWith('45')).length,
    comIban: meios.filter((m) => (m.iban ?? '').length > 5).length,
    activos: meios.filter((m) => m.ativo).length,
  };
}

const codigo = (v: string | null, cor: { fundo: string; borda: string; texto?: string }) =>
  v ? <code style={{ fontSize: 12, background: cor.fundo, padding: '2px 6px', borderRadius: 4, border: `1px solid ${cor.borda}`, color: cor.texto, letterSpacing: 0.5 }}>{v}</code> : <span style={{ color: '#94a3b8' }}>—</span>;

/**
 * Tesouraria › Meios de pagamento (ecrã teso_meios_pagamento), como renderMeiosPagamentoPage do legado: página própria com
 * «Ecrã Cheio», colunas NOME / DESIGNAÇÃO, CONTA (PLANO), DESCRIÇÃO DA CONTA, TIPO, MOEDA, IBAN, SWIFT / BIC, PADRÃO,
 * ESTADO, AÇÕES, botão «Novo Meio de Pagamento» e o resumo (bancos, caixas, com IBAN, activos).
 */
export default function MeiosPagamento() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const meios = useMeiosPagamento();
  const [edicao, setEdicao] = useState<MeioPagamento | 'novo' | null>(null);
  const [form] = Form.useForm<ValoresMeio>();
  const gerir = pode('teso_meios_gerir');
  const lista = meios.data ?? [];
  const resumo = resumoMeios(lista);

  const mutacao = useMutation({
    mutationFn: ({ metodo, url, corpo }: { metodo: 'post' | 'put' | 'delete'; url: string; corpo?: unknown }) => enviar<MeioPagamento | null>(metodo, url, corpo),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setEdicao(null);
      void cliente.invalidateQueries({ queryKey: ['teso', 'meios'] });
    },
    onError: (e) => notificarErro(e),
  });

  const abrir = (m: MeioPagamento | 'novo') => {
    form.resetFields();
    form.setFieldsValue(m === 'novo' ? { ativo: true, predefinido: false } : { nome: m.nome, codigo_conta: m.codigo_conta, iban: m.iban ?? undefined, swift: m.swift ?? undefined, ativo: m.ativo, predefinido: m.predefinido });
    setEdicao(m);
  };
  const definirPadrao = (m: MeioPagamento) =>
    mutacao.mutateAsync({ metodo: 'put', url: `/tesouraria/meios-pagamento/${m.id}`, corpo: { nome: m.nome, codigo_conta: m.codigo_conta, iban: m.iban, swift: m.swift, ativo: m.ativo, predefinido: true } });

  const colunas: ColunaLegado<MeioPagamento>[] = [
    { title: 'Nome / Designação', dataIndex: 'nome', key: 'nome', render: (v: string, m) => <><strong style={{ color: '#1e293b' }}>{v}</strong>{m.predefinido && <IconeFa nome="star" rotulo="Meio padrão" style={{ color: '#f59e0b', fontSize: 11, marginLeft: 6 }} />}</> },
    { title: 'Conta (Plano)', dataIndex: 'codigo_conta', key: 'conta', render: (v: string) => <Tag style={{ fontFamily: 'monospace' }}>{v || '—'}</Tag> },
    { title: 'Descrição da Conta', dataIndex: 'descricao_conta', key: 'descricao_conta', width: 220, render: (v: string | null) => (v ? <span style={{ fontSize: 13, color: '#475569' }}>{v}</span> : <em style={{ color: '#94a3b8' }}>Conta não encontrada no plano</em>) },
    {
      title: 'Tipo', key: 'tipo', valorImpressao: (m) => (tipoMeio(m) === 'BANCO' ? 'Banco' : 'Caixa'),
      render: (_, m) => (tipoMeio(m) === 'BANCO'
        ? <Tag style={{ background: 'rgba(6,182,212,0.1)', color: '#0891b2', borderColor: 'rgba(6,182,212,0.3)' }} icon={<IconeFa nome="university" />}> Banco</Tag>
        : <Tag style={{ background: 'rgba(16,185,129,0.1)', color: '#059669', borderColor: 'rgba(16,185,129,0.3)' }} icon={<IconeFa nome="coins" />}> Caixa</Tag>),
    },
    { title: 'Moeda', dataIndex: 'codigo_moeda', key: 'moeda', render: (c: string | null) => (!c || c === 'AOA' ? <Tag>AOA</Tag> : <Tag style={{ background: 'rgba(3,105,161,0.1)', color: '#0369a1', borderColor: 'rgba(3,105,161,0.35)', fontWeight: 700 }}>{c}</Tag>), valorImpressao: (m) => m.codigo_moeda || 'AOA' },
    { title: 'IBAN', dataIndex: 'iban', key: 'iban', render: (v: string | null) => codigo(v, { fundo: '#f8fafc', borda: '#e2e8f0' }) },
    { title: 'SWIFT / BIC', dataIndex: 'swift', key: 'swift', render: (v: string | null) => codigo(v, { fundo: '#f0f9ff', borda: '#bae6fd', texto: '#0369a1' }) },
    {
      title: 'Padrão', dataIndex: 'predefinido', key: 'padrao', align: 'center', valorImpressao: (m) => (m.predefinido ? 'Padrão' : ''),
      render: (v: boolean, m) => (v
        ? <Tag style={{ background: 'rgba(245,158,11,0.15)', color: '#b45309', borderColor: 'rgba(245,158,11,0.4)' }} icon={<IconeFa nome="star" />}> Padrão</Tag>
        : gerir ? <Tooltip title="Definir como padrão"><Button size="small" icon={<IconeFa nome="star" style={{ color: '#f59e0b' }} />} onClick={() => void definirPadrao(m)}>Definir Padrão</Button></Tooltip> : null),
    },
    { title: 'Estado', dataIndex: 'ativo', key: 'estado', align: 'center', valorImpressao: (m) => (m.ativo ? 'Ativo' : 'Inativo'), render: (v: boolean) => (v ? <Tag color="success" icon={<IconeFa nome="check" />}> Ativo</Tag> : <Tag icon={<IconeFa nome="ban" />}> Inativo</Tag>) },
  ];
  const accoes = (m: MeioPagamento): AccaoLinha[] => [
    { chave: 'editar', icone: 'edit', titulo: 'Editar', oculto: !gerir, onClick: () => abrir(m) },
    { chave: 'eliminar', icone: 'trash', titulo: 'Eliminar', variante: 'perigo', oculto: !gerir, confirmar: { titulo: `Eliminar ${m.nome}?`, textoOk: 'Eliminar', perigo: true },
      onClick: () => mutacao.mutateAsync({ metodo: 'delete', url: `/tesouraria/meios-pagamento/${m.id}` }) },
  ];

  return (
    <>
      <CabecalhoModulo
        titulo="Meios de Pagamento"
        icone={<IconeFa nome="credit-card" style={{ color: '#7c3aed' }} />}
        subtitulo="Contas bancárias (43) e de caixa (45) disponíveis para usar nas faturas e recibos."
        ecraCheio
      />
      <Card
        style={{ borderTop: '4px solid #7c3aed' }}
        title={
          <div style={{ whiteSpace: 'normal', padding: '8px 0' }}>
            <span><IconeFa nome="credit-card" style={{ color: '#7c3aed' }} /> Meios de Pagamento</span>
            <p style={{ margin: '4px 0 0', fontSize: 12, fontWeight: 400, color: '#64748b' }}>
              Contas bancárias (43) e caixas (45) associadas ao plano de contas — usadas nas faturas e recibos. O meio <IconeFa nome="star" style={{ color: '#f59e0b' }} /> <strong>Padrão</strong> é pre-selecionado automaticamente nas faturas.
            </p>
          </div>
        }
        extra={gerir && <Button type="primary" style={{ background: 'linear-gradient(135deg,#7c3aed,#6d28d9)', borderColor: '#6d28d9' }} icon={<IconeFa nome="plus" />} onClick={() => abrir('novo')}>Novo Meio de Pagamento</Button>}
      >
        <TabelaComModos<MeioPagamento>
          rowKey="id"
          loading={meios.isLoading}
          dataSource={lista}
          columns={colunas}
          accoes={accoes}
          impressao={{ titulo: 'Meios de pagamento' }}
          vazio={{ icone: 'credit-card', titulo: 'Nenhum meio de pagamento registado.', texto: 'Clique em «+ Novo Meio de Pagamento» para começar.' }}
        />
      </Card>
      <div style={{ marginTop: 16 }}>
        <GrelhaKpi larguraMinima={200}>
          <CartaoKpi rotulo="Meios Bancários (43)" valor={resumo.bancos} icone="university" cor="#0891b2" corValor="#0891b2" />
          <CartaoKpi rotulo="Caixas (45)" valor={resumo.caixas} icone="coins" cor="#059669" corValor="#059669" />
          <CartaoKpi rotulo="Com IBAN" valor={resumo.comIban} icone="barcode" cor="#f59e0b" corValor="#d97706" />
          <CartaoKpi rotulo="Ativos" valor={resumo.activos} icone="check-circle" cor="#7c3aed" corValor="#7c3aed" />
        </GrelhaKpi>
      </div>
      <Modal width={larguraModal(560)} title={edicao === 'novo' ? 'Novo Meio de Pagamento' : 'Editar Meio de Pagamento'} open={edicao !== null} onCancel={() => setEdicao(null)} okText="Gravar" confirmLoading={mutacao.isPending} onOk={() => form.submit()}>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) =>
            mutacao.mutate(edicao && edicao !== 'novo' ? { metodo: 'put', url: `/tesouraria/meios-pagamento/${edicao.id}`, corpo: v } : { metodo: 'post', url: '/tesouraria/meios-pagamento', corpo: v })
          }
        >
          <Form.Item name="nome" label="Nome / Designação" rules={[{ required: true }]}>
            <Input maxLength={255} />
          </Form.Item>
          <Form.Item name="codigo_conta" label="Conta (43 bancos, 45 caixa)" rules={[{ required: true }]}>
            <SeletorConta prefixos={['43', '45']} style={{ width: '100%' }} />
          </Form.Item>
          <Space wrap>
            <Form.Item name="iban" label="IBAN">
              <Input maxLength={40} style={{ width: 280, maxWidth: '100%' }} />
            </Form.Item>
            <Form.Item name="swift" label="SWIFT / BIC">
              <Input maxLength={11} style={{ width: 140 }} />
            </Form.Item>
          </Space>
          <Space wrap>
            <Form.Item name="ativo" valuePropName="checked" noStyle>
              <Checkbox>Ativo</Checkbox>
            </Form.Item>
            <Form.Item name="predefinido" valuePropName="checked" noStyle>
              <Checkbox>Padrão</Checkbox>
            </Form.Item>
          </Space>
        </Form>
      </Modal>
    </>
  );
}
