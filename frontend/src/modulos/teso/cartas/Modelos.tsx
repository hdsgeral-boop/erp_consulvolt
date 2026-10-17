import { Button, Card, Col, Collapse, Form, Input, Modal, Row, Skeleton, Space, Tag, Typography, theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAvisarErroCartas } from './comum';
import { ROTULO_TIPO_CARTA, type ModeloCarta, type ModelosCarta, type TipoCarta } from './api';

interface ValoresModelo {
  assunto: string;
  corpo: string;
  nome_assinatura?: string;
  cargo_assinatura?: string;
  local?: string;
}

const TIPOS: TipoCarta[] = ['TERCEIRO', 'SALARIOS'];

/** Regras de gravação do modelo de carta (predefinido: caixa ou «Sim/Não»); o tipo só vai na criação. */
export function dadosModelo(v: Record<string, unknown>, tipo?: TipoCarta): Record<string, unknown> {
  const txt = (x: unknown) => (typeof x === 'string' ? x : x ?? '');
  const predefinido = typeof v.predefinido === 'boolean' ? v.predefinido : v.predefinido_sn === 'S';
  const dados: Record<string, unknown> = {
    nome: txt(v.nome), assunto: txt(v.assunto), corpo: txt(v.corpo),
    nome_assinatura: txt(v.nome_assinatura), cargo_assinatura: txt(v.cargo_assinatura), local: txt(v.local), predefinido,
  };
  if (tipo) dados.tipo = tipo;
  return dados;
}

/**
 * O modelo activo do tipo — o legado guarda UM texto por empresa e tipo (payment_letter_templates); o sistema novo pode
 * ter vários (ADR-071): mostra-se e edita-se o predefinido (ou o mais antigo, se nenhum o estiver).
 */
export function modeloActivo(modelos: ModeloCarta[] | undefined, tipo: TipoCarta): ModeloCarta | undefined {
  const doTipo = (modelos ?? []).filter((m) => m.tipo === tipo);
  return doTipo.find((m) => m.predefinido) ?? [...doTipo].sort((a, b) => a.id - b.id)[0];
}

/**
 * «Modelo de texto» como o legado (desenharModelo, cartas_pagamento_ui.js:271-304): botões dos tipos, o formulário do texto
 * (Assunto, Corpo da carta, Assinado por / Cargo / Local) com «Guardar modelo» e «Repor o texto padrão», e as variáveis
 * disponíveis ao lado. Um texto activo por tipo; os outros modelos que existam no sistema novo ficam guardados e podem
 * passar a activo («Outros modelos guardados»). Alterar o texto é a tarefa teso_carta_modelo; as cartas emitidas não mudam.
 */
export function ModelosTexto() {
  const { pode } = useSessao();
  const gerir = pode('teso_carta_modelo');
  const [tipo, setTipo] = useState<TipoCarta>('TERCEIRO');
  const [form] = Form.useForm<ValoresModelo>();
  const { token } = theme.useToken();
  const dados = useQuery({ queryKey: ['teso', 'cartas', 'modelos'], queryFn: () => obter<ModelosCarta>('/tesouraria/cartas/modelos') });
  useAvisarErroCartas(dados.error, 'Erro ao carregar os modelos');
  const accao = useAccao({ invalidar: [['teso', 'cartas']] });
  const padrao = dados.data?.padrao[tipo];
  const activo = modeloActivo(dados.data?.modelos, tipo);
  const outros = (dados.data?.modelos ?? []).filter((m) => m.tipo === tipo && m.id !== activo?.id);

  // o formulário mostra o texto activo do tipo (ou o texto padrão, se ainda não houver modelo gravado)
  useEffect(() => {
    if (!dados.data) return;
    form.setFieldsValue(activo
      ? { assunto: activo.assunto ?? '', corpo: activo.corpo ?? '', nome_assinatura: activo.nome_assinatura ?? '', cargo_assinatura: activo.cargo_assinatura ?? '', local: activo.local ?? '' }
      : { assunto: padrao?.assunto ?? '', corpo: padrao?.corpo ?? '', nome_assinatura: '', cargo_assinatura: padrao?.cargo_assinatura ?? '', local: '' });
  }, [dados.data, activo, padrao, form]);

  const gravarTexto = (v: ValoresModelo) => {
    const r = { ...v, nome: activo?.nome ?? `Modelo — ${ROTULO_TIPO_CARTA[tipo]}`, predefinido: true } as unknown as Record<string, unknown>;
    return activo
      ? accao.mutateAsync({ metodo: 'put', url: `/tesouraria/cartas/modelos/${activo.id}`, dados: dadosModelo(r) })
      : accao.mutateAsync({ url: '/tesouraria/cartas/modelos', dados: dadosModelo(r, tipo) });
  };
  const repor = () =>
    Modal.confirm({
      title: 'Repor o texto padrão deste tipo de carta?',
      content: 'As cartas já emitidas não são alteradas.',
      okText: 'Repor', cancelText: 'Cancelar',
      onOk: async () => {
        const texto = { assunto: padrao?.assunto ?? '', corpo: padrao?.corpo ?? '', nome_assinatura: '', cargo_assinatura: padrao?.cargo_assinatura ?? '', local: '' };
        form.setFieldsValue(texto);
        // reporModelo do legado: grava o texto padrão (sem assinante nem local); sem modelo gravado já é o padrão
        if (activo) await gravarTexto(texto);
      },
    });

  if (dados.isLoading) return <Skeleton active />;
  return (
    <Card>
      <Space wrap style={{ marginBottom: 16 }}>
        {TIPOS.map((t) => (
          <Button key={t} size="small" type={t === tipo ? 'primary' : 'default'} aria-pressed={t === tipo} onClick={() => setTipo(t)}>{ROTULO_TIPO_CARTA[t]}</Button>
        ))}
      </Space>
      <Row gutter={[16, 16]}>
        <Col xs={24} lg={16}>
          <Form form={form} layout="vertical" disabled={!gerir} onFinish={(v) => void gravarTexto(v)}>
            <Form.Item name="assunto" label="Assunto" rules={[{ required: true, message: 'Indique o assunto.' }]}><Input maxLength={50} /></Form.Item>
            <Form.Item name="corpo" label="Corpo da carta" rules={[{ required: true, message: 'Indique o texto.' }]}><Input.TextArea rows={12} maxLength={10000} /></Form.Item>
            <Row gutter={12}>
              <Col xs={24} md={8}><Form.Item name="nome_assinatura" label="Assinado por"><Input maxLength={255} /></Form.Item></Col>
              <Col xs={24} md={8}><Form.Item name="cargo_assinatura" label="Cargo"><Input maxLength={100} /></Form.Item></Col>
              <Col xs={24} md={8}><Form.Item name="local" label="Local"><Input maxLength={100} placeholder="Ex.: Luanda" /></Form.Item></Col>
            </Row>
            {gerir && (
              <Space wrap>
                <Button type="primary" htmlType="submit" icon={<IconeFa nome="save" />} loading={accao.isPending}>Guardar modelo</Button>
                <Button icon={<IconeFa nome="undo" />} onClick={repor}>Repor o texto padrão</Button>
              </Space>
            )}
          </Form>
          {outros.length > 0 && (
            <Collapse size="small" style={{ marginTop: 16 }} items={[{
              key: 'outros',
              label: `Outros modelos guardados deste tipo (${outros.length})`,
              children: (
                <Space direction="vertical" style={{ width: '100%' }}>
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>As cartas saem com o texto acima. Estes textos continuam guardados; «Usar este» passa-o a texto do tipo.</Typography.Text>
                  {outros.map((m) => (
                    <Card key={m.id} size="small" type="inner" title={m.nome ?? `Modelo #${m.id}`}
                      extra={gerir && (
                        <Space wrap>
                          <Button size="small" onClick={() => accao.mutate({ url: `/tesouraria/cartas/modelos/${m.id}/predefinir` })}>Usar este</Button>
                          <Button size="small" danger icon={<IconeFa nome="trash" />} aria-label={`Eliminar ${m.nome ?? m.id}`}
                            onClick={() => Modal.confirm({ title: `Eliminar o modelo «${m.nome ?? m.id}»?`, content: 'As cartas já emitidas não são alteradas.', okText: 'Eliminar', okButtonProps: { danger: true }, cancelText: 'Cancelar',
                              onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/tesouraria/cartas/modelos/${m.id}` }) })} />
                        </Space>
                      )}>
                      <Typography.Paragraph style={{ marginBottom: 4 }}><strong>Assunto:</strong> {m.assunto}</Typography.Paragraph>
                      <Typography.Paragraph type="secondary" ellipsis={{ rows: 3, expandable: true, symbol: 'ver mais' }} style={{ whiteSpace: 'pre-wrap', marginBottom: 0 }}>{m.corpo}</Typography.Paragraph>
                    </Card>
                  ))}
                </Space>
              ),
            }]} />
          )}
        </Col>
        <Col xs={24} lg={8}>
          <Typography.Title level={5} style={{ marginTop: 0, fontSize: 14 }}>Variáveis disponíveis</Typography.Title>
          <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>Escreva-as no assunto ou no corpo; são substituídas na impressão.</Typography.Paragraph>
          <div style={{ border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4, overflow: 'hidden' }}>
            {Object.entries(dados.data?.variaveis ?? {}).map(([v, d]) => (
              <div key={v} style={{ display: 'flex', justifyContent: 'space-between', gap: 8, padding: '5px 8px', borderBottom: `1px solid ${token.colorBorderSecondary}`, fontSize: 12 }}>
                <code style={{ color: '#0891b2', fontWeight: 600 }}>{`{{${v}}}`}</code><span style={{ color: token.colorTextSecondary, textAlign: 'right' }}>{d}</span>
              </div>
            ))}
          </div>
          {activo && <Tag style={{ marginTop: 12 }} color="gold">{activo.nome ?? `Modelo #${activo.id}`}</Tag>}
        </Col>
      </Row>
    </Card>
  );
}
