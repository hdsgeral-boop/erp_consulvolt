import { Button, Card, Checkbox, Form, Input, Select, Space, Tag, Tooltip, message, theme } from 'antd';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { enviar } from '@/api/cliente';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { usePlanoContas } from '../contab/comum/dados';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
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

/** Tipo do meio pela conta: 43 = Banco, cartão (classe 3 marcada) = Cartão, resto (45) = Caixa (typeBadge do legado). */
export function tipoMeio(m: Pick<MeioPagamento, 'codigo_conta' | 'tipo'>): 'BANCO' | 'CAIXA' | 'CARTAO' {
  return m.tipo ?? (m.codigo_conta?.startsWith('43') ? 'BANCO' : 'CAIXA');
}

/** Resumo informativo do legado: Meios Bancários (43), Caixas (45), Cartões (classe 3), Com IBAN, Ativos. */
export function resumoMeios(meios: MeioPagamento[]): { bancos: number; caixas: number; cartoes: number; comIban: number; activos: number } {
  return {
    bancos: meios.filter((m) => tipoMeio(m) === 'BANCO').length,
    caixas: meios.filter((m) => tipoMeio(m) === 'CAIXA').length,
    cartoes: meios.filter((m) => tipoMeio(m) === 'CARTAO').length,
    comIban: meios.filter((m) => (m.iban ?? '').length > 5).length,
    activos: meios.filter((m) => m.ativo).length,
  };
}

const codigo = (v: string | null, cor: { fundo: string; borda: string; texto?: string }, vazio: string) =>
  v ? <code style={{ fontSize: 12, background: cor.fundo, padding: '2px 6px', borderRadius: 4, border: `1px solid ${cor.borda}`, color: cor.texto, letterSpacing: 0.5 }}>{v}</code> : <span style={{ color: vazio }}>—</span>;

const SIM_NAO = [{ value: 'S', label: 'Sim' }, { value: 'N', label: 'Não' }];
const simNao = (v: unknown, omissao: boolean) => (typeof v === 'boolean' ? v : v === 'S' ? true : v === 'N' ? false : omissao);

/** Regras de gravação do meio de pagamento, comuns à grade e à vista Linhas (caixas de verificação ou «Sim/Não»). */
export function dadosMeio(v: Record<string, unknown>): Record<string, unknown> {
  const txt = (x: unknown) => (typeof x === 'string' && x.trim() ? x.trim() : undefined);
  return {
    nome: typeof v.nome === 'string' ? v.nome.trim() : v.nome,
    codigo_conta: v.codigo_conta,
    iban: txt(v.iban),
    swift: txt(v.swift),
    ativo: simNao(v.ativo ?? v.activo_sn, true),
    predefinido: simNao(v.predefinido ?? v.padrao_sn, false),
  };
}

/** Vista Linhas do «Novo Meio de Pagamento»: um meio por linha; a conta escolhe-se entre as contas de movimento 43/45/cartão. */
export function camposMeio(contas: { value: string; label: string }[]): CampoFormulario[] {
  return [
    { nome: 'nome', rotulo: 'Nome / Designação', obrigatorio: true, largura: 220, maxLength: 255, aliases: ['Nome', 'Designação'] },
    { nome: 'codigo_conta', rotulo: 'Conta (43/45/cartão)', tipo: 'select', obrigatorio: true, opcoes: contas, largura: 240, aliases: ['Conta', 'Conta (Plano)'] },
    { nome: 'iban', rotulo: 'IBAN', largura: 230, maxLength: 40 },
    { nome: 'swift', rotulo: 'SWIFT / BIC', largura: 120, maxLength: 11, aliases: ['SWIFT', 'BIC'] },
    { nome: 'activo_sn', rotulo: 'Ativo', tipo: 'select', opcoes: SIM_NAO, omissao: 'S', largura: 90, aliases: ['Estado'] },
    { nome: 'padrao_sn', rotulo: 'Padrão', tipo: 'select', opcoes: SIM_NAO, omissao: 'N', largura: 90, emLinhas: 'opcional', seccao: 'Outros' },
  ];
}

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
  const { token } = theme.useToken();
  const plano = usePlanoContas(edicao === 'novo');
  const opcoesContaMeio = useMemo(() => (plano.data ?? [])
    .filter((c) => c.tipo === 'M' && (c.codigo.startsWith('43') || c.codigo.startsWith('45') || c.cartao_tesouraria))
    .map((c) => ({ value: c.codigo, label: `${c.codigo} - ${c.descricao ?? ''}` })), [plano.data]);
  const campos = useMemo(() => camposMeio(opcoesContaMeio), [opcoesContaMeio]);
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
    { title: 'Nome / Designação', dataIndex: 'nome', key: 'nome', render: (v: string, m) => <><strong style={{ color: token.colorText }}>{v}</strong>{m.predefinido && <IconeFa nome="star" rotulo="Meio padrão" style={{ color: '#f59e0b', fontSize: 11, marginLeft: 6 }} />}</> },
    { title: 'Conta (Plano)', dataIndex: 'codigo_conta', key: 'conta', render: (v: string) => <Tag style={{ fontFamily: 'monospace' }}>{v || '—'}</Tag> },
    { title: 'Descrição da Conta', dataIndex: 'descricao_conta', key: 'descricao_conta', width: 220, render: (v: string | null) => (v ? <span style={{ fontSize: 13, color: token.colorTextSecondary }}>{v}</span> : <em style={{ color: token.colorTextQuaternary }}>Conta não encontrada no plano</em>) },
    {
      title: 'Tipo', key: 'tipo', valorImpressao: (m) => (tipoMeio(m) === 'BANCO' ? 'Banco' : tipoMeio(m) === 'CARTAO' ? 'Cartão' : 'Caixa'),
      render: (_, m) => (tipoMeio(m) === 'BANCO'
        ? <Tag style={{ background: 'rgba(6,182,212,0.1)', color: '#0891b2', borderColor: 'rgba(6,182,212,0.3)' }} icon={<IconeFa nome="university" />}> Banco</Tag>
        : tipoMeio(m) === 'CARTAO'
          ? <Tag style={{ background: 'rgba(124,58,237,0.1)', color: '#7c3aed', borderColor: 'rgba(124,58,237,0.3)' }} icon={<IconeFa nome="credit-card" />}> Cartão</Tag>
          : <Tag style={{ background: 'rgba(16,185,129,0.1)', color: '#059669', borderColor: 'rgba(16,185,129,0.3)' }} icon={<IconeFa nome="coins" />}> Caixa</Tag>),
    },
    { title: 'Moeda', dataIndex: 'codigo_moeda', key: 'moeda', render: (c: string | null) => (!c || c === 'AOA' ? <Tag>AOA</Tag> : <Tag style={{ background: 'rgba(3,105,161,0.1)', color: '#0369a1', borderColor: 'rgba(3,105,161,0.35)', fontWeight: 700 }}>{c}</Tag>), valorImpressao: (m) => m.codigo_moeda || 'AOA' },
    { title: 'IBAN', dataIndex: 'iban', key: 'iban', render: (v: string | null) => codigo(v, { fundo: token.colorFillTertiary, borda: token.colorBorderSecondary }, token.colorTextQuaternary) },
    { title: 'SWIFT / BIC', dataIndex: 'swift', key: 'swift', render: (v: string | null) => codigo(v, { fundo: 'rgba(3,105,161,0.1)', borda: 'rgba(3,105,161,0.35)', texto: '#0369a1' }, token.colorTextQuaternary) },
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
        subtitulo="Contas bancárias (43), de caixa (45) e cartões de tesouraria disponíveis para usar nas faturas e recibos."
        ecraCheio
      />
      <Card
        style={{ borderTop: '4px solid #7c3aed' }}
        title={
          <div style={{ whiteSpace: 'normal', padding: '8px 0' }}>
            <span><IconeFa nome="credit-card" style={{ color: '#7c3aed' }} /> Meios de Pagamento</span>
            <p style={{ margin: '4px 0 0', fontSize: 12, fontWeight: 400, color: token.colorTextSecondary }}>
              Contas bancárias (43), caixas (45) e cartões de tesouraria associados ao plano de contas — usadas nas faturas e recibos. O meio <IconeFa nome="star" style={{ color: '#f59e0b' }} /> <strong>Padrão</strong> é pre-selecionado automaticamente nas faturas.
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
          {resumo.cartoes > 0 && <CartaoKpi rotulo="Cartões (classe 3)" valor={resumo.cartoes} icone="credit-card" cor="#7c3aed" corValor="#7c3aed" />}
          <CartaoKpi rotulo="Com IBAN" valor={resumo.comIban} icone="barcode" cor="#f59e0b" corValor="#d97706" />
          <CartaoKpi rotulo="Ativos" valor={resumo.activos} icone="check-circle" cor="#7c3aed" corValor="#7c3aed" />
        </GrelhaKpi>
      </div>
      <ModalFormulario chave="teso.meios_pagamento" width={larguraModal(560)} titulo={edicao === 'novo' ? 'Novo Meio de Pagamento' : 'Editar Meio de Pagamento'} aberto={edicao !== null} aoFechar={() => setEdicao(null)}
        criacao={edicao === 'novo'} form={form} campos={campos} nomeRegistos="meios de pagamento" invalidar={[['teso', 'meios']]}
        gravarLinha={(r) => enviar('post', '/tesouraria/meios-pagamento', dadosMeio(r))}
        nota="Um meio de pagamento por linha. A conta é uma conta de movimento 43 (bancos), 45 (caixa) ou um cartão de tesouraria do plano. Linhas deixadas em branco são ignoradas. Cada linha é gravada com as mesmas regras do formulário. Campos com * são obrigatórios."
        okText="Gravar" confirmLoading={mutacao.isPending} onOk={() => form.submit()}>
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) => {
            const corpo = dadosMeio(v as unknown as Record<string, unknown>);
            mutacao.mutate(edicao && edicao !== 'novo' ? { metodo: 'put', url: `/tesouraria/meios-pagamento/${edicao.id}`, corpo } : { metodo: 'post', url: '/tesouraria/meios-pagamento', corpo });
          }}
        >
          <Form.Item name="nome" label="Nome / Designação" rules={[{ required: true }]}>
            <Input maxLength={255} />
          </Form.Item>
          <Form.Item name="codigo_conta" label="Conta (43 bancos, 45 caixa ou cartão de tesouraria)" rules={[{ required: true }]}>
            <Select showSearch optionFilterProp="label" options={opcoesContaMeio} loading={plano.isLoading} style={{ width: '100%' }} popupMatchSelectWidth={false} />
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
      </ModalFormulario>
    </>
  );
}
