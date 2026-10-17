import { Alert, Button, Col, Collapse, DatePicker, Flex, Form, Input, Modal, Result, Row, Select, Skeleton, Space, Table, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useImpressao, useMensagem } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi } from '@/utilitarios/formatacao';
import { ROTULO_TIPO_CARTA, type BeneficiarioCarta, type CartaPagamento, type Elegibilidade, type ModelosCarta } from './api';
import { pedidoCarta } from './impressao';
import { chaveBeneficiario, corpoBeneficiarios, dataCurta, formatarIban, seleccaoInicial, totalSeleccionado, valorMoeda } from './regras';

interface ValoresTexto {
  modelo_id?: number;
  assunto: string;
  corpo: string;
  nome_assinatura?: string;
  cargo_assinatura?: string;
  local?: string;
  data: Dayjs;
}

/**
 * Janela de emissão da carta a partir de um pagamento gravado (abrirEmissaoCarta, cartas_pagamento_ui.js): conta a debitar,
 * beneficiários (marcados os que têm conta e ainda não estão noutra carta; os sem conta ficam bloqueados), total, texto do
 * modelo (editável só nesta carta) e «Emitir carta». Depois de emitida, abre a impressão. Nenhum lançamento é gerado.
 */
export function ModalEmissaoCarta({ documentoId, aoFechar, aoEmitida }: { documentoId: number | null; aoFechar: () => void; aoEmitida?: (c: CartaPagamento) => void }) {
  const cliente = useQueryClient();
  const mensagem = useMensagem();
  const { imprimir } = useImpressao();
  const [form] = Form.useForm<ValoresTexto>();
  const [seleccao, setSeleccao] = useState<string[]>([]);
  const [contas, setContas] = useState<Record<string, number | undefined>>({});
  const el = useQuery({
    queryKey: ['teso', 'cartas', 'elegibilidade', documentoId],
    queryFn: () => obter<Elegibilidade>(`/tesouraria/cartas/documentos/${documentoId}/elegibilidade`),
    enabled: documentoId !== null,
  });
  const modelos = useQuery({ queryKey: ['teso', 'cartas', 'modelos'], queryFn: () => obter<ModelosCarta>('/tesouraria/cartas/modelos'), enabled: documentoId !== null, staleTime: 60_000 });
  const e = el.data;
  const beneficiarios = useMemo(() => e?.beneficiarios ?? [], [e]);
  const moeda = e?.documento.codigo_moeda || 'AOA';

  useEffect(() => {
    if (!e) return;
    setSeleccao(seleccaoInicial(beneficiarios));
    setContas({});
    const m = e.modelo;
    form.setFieldsValue({ modelo_id: m?.id ?? undefined, assunto: m?.assunto ?? '', corpo: m?.corpo ?? '', nome_assinatura: m?.nome_assinatura ?? '',
      cargo_assinatura: m?.cargo_assinatura ?? '', local: e.local_padrao ?? '', data: dayjs(e.documento.data_documento) });
  }, [e, beneficiarios, form]);

  const emitir = useMutation({
    mutationFn: (corpo: Record<string, unknown>) => enviar<CartaPagamento>('post', `/tesouraria/cartas/documentos/${documentoId}`, corpo),
    onSuccess: ({ dados, mensagem: texto }) => {
      mensagem.success(texto);
      void cliente.invalidateQueries({ queryKey: ['teso', 'cartas'] });
      aoEmitida?.(dados);
      aoFechar();
      void imprimir(pedidoCarta(dados));
    },
    onError: (erro) => notificarErro(erro, 'Não foi possível emitir a carta'),
  });

  const escolhidos = beneficiarios.filter((b) => seleccao.includes(chaveBeneficiario(b)));
  const seleccionaveis = beneficiarios.filter((b) => b.iban || b.contas.some((c) => c.iban));
  const semConta = beneficiarios.filter((b) => !b.iban && !b.contas.some((c) => c.iban));
  const total = totalSeleccionado(beneficiarios, seleccao);

  const submeter = (v: ValoresTexto) => {
    const corpo = {
      beneficiarios: corpoBeneficiarios(beneficiarios, seleccao, contas),
      modelo_id: v.modelo_id ?? null, assunto: v.assunto, corpo: v.corpo, nome_assinatura: v.nome_assinatura || null,
      cargo_assinatura: v.cargo_assinatura || null, local: v.local || null, data: dataApi(v.data),
    };
    const repetidos = escolhidos.filter((b) => b.ja_na_carta);
    if (!repetidos.length) return emitir.mutate(corpo);
    Modal.confirm({
      title: `${repetidos.length} beneficiário(s) já consta(m) de outra carta`,
      content: <ul style={{ paddingLeft: 18 }}>{repetidos.slice(0, 8).map((b) => <li key={chaveBeneficiario(b)}>{b.nome} ({b.ja_na_carta})</li>)}{repetidos.length > 8 && <li>… e mais {repetidos.length - 8}</li>}</ul>,
      okText: 'Incluir mesmo assim',
      cancelText: 'Rever',
      onOk: () => emitir.mutateAsync({ ...corpo, confirmar_repetidos: true }),
    });
  };

  const aplicarModelo = (id?: number) => {
    const m = modelos.data?.modelos.find((x) => x.id === id);
    if (m) form.setFieldsValue({ assunto: m.assunto ?? '', corpo: m.corpo ?? '', nome_assinatura: m.nome_assinatura ?? '', cargo_assinatura: m.cargo_assinatura ?? '', local: m.local || e?.local_padrao || '' });
  };

  const colunas: ColumnsType<BeneficiarioCarta> = [
    {
      title: 'Beneficiário', key: 'nome',
      render: (_, b) => (
        <>
          <div style={{ fontWeight: 600 }}>{b.nome}</div>
          {b.nif && <div style={{ fontSize: 12, color: '#64748b' }}>NIF {b.nif}</div>}
          {b.referencia && b.tipo === 'TERCEIRO' && <div style={{ fontSize: 12, color: '#64748b' }}>{b.referencia}</div>}
          {b.ja_na_carta && <div style={{ fontSize: 12, color: '#b45309' }}><IconeFa nome="exclamation-triangle" /> já incluído na carta {b.ja_na_carta}</div>}
        </>
      ),
    },
    {
      title: 'Banco / IBAN', key: 'conta',
      render: (_, b) => {
        if (b.contas.length > 1) {
          const k = chaveBeneficiario(b);
          return (
            <Select size="small" style={{ minWidth: 260 }} value={contas[k] ?? b.conta_bancaria_id ?? undefined} aria-label={`Conta bancária de ${b.nome}`}
              onChange={(id: number) => setContas((c) => ({ ...c, [k]: id }))}
              options={b.contas.map((c) => ({ value: c.id, label: `${c.banco ?? '—'} · ${formatarIban(c.iban)}${c.predefinida ? ' (predefinida)' : ''}` }))} />
          );
        }
        return b.iban
          ? <><div>{b.banco ?? '—'}</div><code style={{ fontSize: 12 }}>{formatarIban(b.iban)}</code></>
          : <Typography.Text type="danger"><IconeFa nome="ban" /> sem conta bancária configurada</Typography.Text>;
      },
    },
    { title: 'Valor', dataIndex: 'valor', key: 'valor', align: 'right', render: (v: string) => <strong style={{ whiteSpace: 'nowrap' }}>{valorMoeda(v, moeda)}</strong> },
  ];

  const conteudo = () => {
    if (el.isLoading || !e) return <Skeleton active />;
    if (!e.pode && !e.beneficiarios?.length) {
      return <Result status="warning" title="Não é possível emitir a carta" subTitle={<>{e.motivo}{e.sugestao && <><br /><strong>{e.sugestao}</strong></>}</>} />;
    }
    return (
      <>
        <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>
          {ROTULO_TIPO_CARTA[e.tipo]} · pagamento <strong>{e.documento.numero_documento ?? e.documento.referencia ?? `#${e.documento.id}`}</strong> de {dataCurta(e.documento.data_documento)}
          {' '}· valor {valorMoeda(e.valor_documento, moeda)}{e.valor_em_cartas && Number(e.valor_em_cartas) > 0 ? ` (já em cartas: ${valorMoeda(e.valor_em_cartas, moeda)})` : ''}
        </Typography.Paragraph>
        {!e.pode && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={e.motivo} description={e.sugestao} />}
        {e.conta && (
          <Row gutter={[12, 6]} style={{ border: '1px solid #e2e8f0', borderRadius: 4, padding: '8px 10px', background: '#f8fafc', marginBottom: 12, fontSize: 13 }}>
            <Col xs={24} md={12}><span style={{ color: '#64748b' }}>Conta a debitar</span><br /><strong>{e.conta.codigo} — {e.conta.descricao}</strong></Col>
            <Col xs={24} md={12}><span style={{ color: '#64748b' }}>Banco</span><br /><strong>{e.conta.banco_nome ?? '—'}</strong>{e.conta.banco_balcao ? ` · ${e.conta.banco_balcao}` : ''}</Col>
            <Col span={24}><span style={{ color: '#64748b' }}>IBAN</span> <code>{formatarIban(e.conta.banco_iban)}</code></Col>
          </Row>
        )}
        {semConta.length > 0 && (
          <Alert type="warning" showIcon style={{ marginBottom: 10 }}
            message={`${semConta.length} beneficiário(s) fica(m) de fora por não ter(em) conta bancária configurada`}
            description={<>{semConta.slice(0, 6).map((b) => b.nome).join(', ')}{semConta.length > 6 ? `, e mais ${semConta.length - 6}` : ''}. {e.tipo === 'SALARIOS' ? 'Configure os IBAN em RH › Dados Bancários.' : 'Registe a conta na ficha do terceiro (Contas bancárias).'}</>} />
        )}
        {/* legado (abrirEmissaoCarta): «Beneficiários» à esquerda, «Marcar todos» / «Desmarcar» à direita */}
        <Flex wrap gap={6} justify="space-between" align="center" style={{ marginBottom: 6 }}>
          <strong>Beneficiários</strong>
          <Space wrap size={6}>
            <Button size="small" onClick={() => setSeleccao(seleccionaveis.map(chaveBeneficiario))}>Marcar todos</Button>
            <Button size="small" onClick={() => setSeleccao([])}>Desmarcar</Button>
          </Space>
        </Flex>
        <Table<BeneficiarioCarta> rowKey={chaveBeneficiario} size="small" pagination={false} columns={colunas} dataSource={beneficiarios} scroll={{ ...scrollTabela(), y: 300 }}
          rowSelection={{ selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as string[]), getCheckboxProps: (b) => ({ disabled: !b.iban && !b.contas.some((c) => c.iban), 'aria-label': `Seleccionar ${b.nome}` }) }}
          rowClassName={(b) => (!b.iban ? 'cp-sem-conta' : '')} />
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: '#0f172a', color: '#fff', borderRadius: 4, padding: '8px 14px', margin: '12px 0' }}>
          <span>{escolhidos.length} beneficiário(s) seleccionado(s)</span>
          <strong style={{ fontSize: 16 }}>{escolhidos.length ? valorMoeda(total, moeda) : '—'}</strong>
        </div>
        <Form form={form} layout="vertical" onFinish={submeter}>
          <Collapse size="small" items={[{
            key: 'texto', forceRender: true, label: <span><strong>Texto da carta</strong> <Typography.Text type="secondary">(parte do modelo; altere só nesta carta)</Typography.Text></span>,
            children: (
              <Row gutter={12}>
                {/* o legado tem um texto por tipo (sem escolha); só se escolhe o modelo quando há vários guardados no tipo */}
                <Col xs={24} md={12} hidden={(modelos.data?.modelos ?? []).filter((m) => m.tipo === e.tipo).length < 2}>
                  <Form.Item name="modelo_id" label="Modelo">
                    <Select allowClear placeholder="Texto padrão" onChange={aplicarModelo}
                      options={(modelos.data?.modelos ?? []).filter((m) => m.tipo === e.tipo).map((m) => ({ value: m.id, label: `${m.nome ?? `Modelo #${m.id}`}${m.predefinido ? ' (predefinido)' : ''}` }))} />
                  </Form.Item>
                </Col>
                <Col xs={24} md={12}><Form.Item name="data" label="Data da carta" rules={[{ required: true, message: 'Indique a data.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
                <Col span={24}><Form.Item name="assunto" label="Assunto" rules={[{ required: true, message: 'Indique o assunto.' }]}><Input maxLength={100} /></Form.Item></Col>
                <Col span={24}><Form.Item name="corpo" label="Corpo" rules={[{ required: true, message: 'Indique o texto.' }]}><Input.TextArea rows={8} maxLength={10000} /></Form.Item></Col>
                <Col xs={24} md={8}><Form.Item name="nome_assinatura" label="Assinado por"><Input maxLength={255} placeholder="Nome de quem assina" /></Form.Item></Col>
                <Col xs={24} md={8}><Form.Item name="cargo_assinatura" label="Cargo"><Input maxLength={100} /></Form.Item></Col>
                <Col xs={24} md={8}><Form.Item name="local" label="Local"><Input maxLength={100} placeholder="Ex.: Luanda" /></Form.Item></Col>
              </Row>
            ),
          }]} />
        </Form>
      </>
    );
  };

  return (
    <Modal open={documentoId !== null} width={larguraModal(960)} onCancel={aoFechar} destroyOnHidden
      title={<Space><IconeFa nome="file-signature" style={{ color: '#0891b2' }} />Emitir carta de ordem de pagamento</Space>}
      footer={
        <Space wrap>
          <Button onClick={aoFechar}>Cancelar</Button>
          <Button type="primary" icon={<IconeFa nome="file-signature" />} loading={emitir.isPending} disabled={!e?.pode || !escolhidos.length} onClick={() => form.submit()}>Emitir carta</Button>
        </Space>
      }>
      {conteudo()}
    </Modal>
  );
}
