import { Button, Card, Checkbox, Col, Empty, Form, Input, Modal, Row, Segmented, Skeleton, Space, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAvisarErroCartas } from './comum';
import { ROTULO_TIPO_CARTA, type ModeloCarta, type ModelosCarta, type TipoCarta } from './api';

interface ValoresModelo {
  nome: string;
  assunto: string;
  corpo: string;
  nome_assinatura?: string;
  cargo_assinatura?: string;
  local?: string;
  predefinido?: boolean;
}

/**
 * «Modelo de texto» (desenharModelo, cartas_pagamento_ui.js): modelos por tipo (terceiros / salários), um predefinido por
 * tipo, com as variáveis disponíveis ao lado e «Repor o texto padrão». Alterar o texto é a tarefa sensível teso_carta_modelo;
 * as cartas já emitidas não mudam. O texto é texto: as variáveis são substituídas e escapadas na impressão.
 */
export function ModelosTexto() {
  const { pode } = useSessao();
  const gerir = pode('teso_carta_modelo');
  const [tipo, setTipo] = useState<TipoCarta>('TERCEIRO');
  const [edicao, setEdicao] = useState<ModeloCarta | 'novo' | null>(null);
  const [form] = Form.useForm<ValoresModelo>();
  const dados = useQuery({ queryKey: ['teso', 'cartas', 'modelos'], queryFn: () => obter<ModelosCarta>('/tesouraria/cartas/modelos') });
  useAvisarErroCartas(dados.error, 'Erro ao carregar os modelos');
  const accao = useAccao({ invalidar: [['teso', 'cartas']], aoSucesso: () => setEdicao(null) });
  const padrao = dados.data?.padrao[tipo];
  const lista = (dados.data?.modelos ?? []).filter((m) => m.tipo === tipo);

  const abrir = (m: ModeloCarta | 'novo') => {
    form.resetFields();
    form.setFieldsValue(m === 'novo'
      ? { nome: '', assunto: padrao?.assunto ?? '', corpo: padrao?.corpo ?? '', cargo_assinatura: padrao?.cargo_assinatura ?? '', predefinido: lista.length === 0 }
      : { nome: m.nome ?? '', assunto: m.assunto ?? '', corpo: m.corpo ?? '', nome_assinatura: m.nome_assinatura ?? '', cargo_assinatura: m.cargo_assinatura ?? '', local: m.local ?? '', predefinido: m.predefinido });
    setEdicao(m);
  };
  const gravar = (v: ValoresModelo) =>
    accao.mutate(edicao === 'novo' ? { url: '/tesouraria/cartas/modelos', dados: { ...v, tipo } } : { metodo: 'put', url: `/tesouraria/cartas/modelos/${(edicao as ModeloCarta).id}`, dados: v });
  const repor = () =>
    Modal.confirm({
      title: 'Repor o texto padrão deste tipo de carta?',
      content: 'Substitui o assunto, o corpo e o cargo no formulário. As cartas já emitidas não são alteradas.',
      okText: 'Repor', cancelText: 'Cancelar',
      onOk: () => form.setFieldsValue({ assunto: padrao?.assunto ?? '', corpo: padrao?.corpo ?? '', cargo_assinatura: padrao?.cargo_assinatura ?? '' }),
    });

  if (dados.isLoading) return <Skeleton active />;
  return (
    <Row gutter={[16, 16]}>
      <Col xs={24} lg={16}>
        <Card
          title={<Segmented<TipoCarta> value={tipo} onChange={setTipo} options={(['TERCEIRO', 'SALARIOS'] as TipoCarta[]).map((t) => ({ value: t, label: ROTULO_TIPO_CARTA[t] }))} />}
          extra={gerir && <Button type="primary" icon={<IconeFa nome="plus" />} onClick={() => abrir('novo')}>Novo modelo</Button>}>
          {!lista.length && (
            <Empty description={<>Sem modelos gravados: as cartas deste tipo saem com o <strong>texto padrão</strong>.</>}>
              {padrao && <Typography.Paragraph type="secondary" style={{ whiteSpace: 'pre-wrap', textAlign: 'left' }}><strong>Assunto:</strong> {padrao.assunto}{'\n\n'}{padrao.corpo}</Typography.Paragraph>}
            </Empty>
          )}
          <Space direction="vertical" style={{ width: '100%' }}>
            {lista.map((m) => (
              <Card key={m.id} size="small" type="inner"
                title={<Space wrap>{m.nome ?? `Modelo #${m.id}`}{m.predefinido && <Tag color="gold" icon={<IconeFa nome="star" />}> Predefinido</Tag>}</Space>}
                extra={gerir && (
                  <Space wrap>
                    {!m.predefinido && <Button size="small" icon={<IconeFa nome="star" style={{ color: '#f59e0b' }} />} onClick={() => accao.mutate({ url: `/tesouraria/cartas/modelos/${m.id}/predefinir` })}>Predefinir</Button>}
                    <Button size="small" icon={<IconeFa nome="edit" />} onClick={() => abrir(m)}>Editar</Button>
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
        </Card>
      </Col>
      <Col xs={24} lg={8}>
        <Card size="small" title="Variáveis disponíveis">
          <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>Escreva-as no assunto ou no corpo; são substituídas na impressão.</Typography.Paragraph>
          {Object.entries(dados.data?.variaveis ?? {}).map(([v, d]) => (
            <div key={v} style={{ display: 'flex', justifyContent: 'space-between', gap: 8, padding: '3px 0', borderBottom: '1px solid #f1f5f9', fontSize: 12 }}>
              <code style={{ color: '#0891b2', fontWeight: 600 }}>{`{{${v}}}`}</code><span style={{ color: '#64748b', textAlign: 'right' }}>{d}</span>
            </div>
          ))}
        </Card>
      </Col>

      <Modal open={edicao !== null} width={larguraModal(760)} onCancel={() => setEdicao(null)} destroyOnHidden
        title={`${edicao === 'novo' ? 'Novo modelo' : 'Editar modelo'} — ${ROTULO_TIPO_CARTA[tipo]}`}
        footer={<Space wrap><Button icon={<IconeFa nome="undo" />} onClick={repor}>Repor o texto padrão</Button><Button onClick={() => setEdicao(null)}>Cancelar</Button>
          <Button type="primary" icon={<IconeFa nome="save" />} loading={accao.isPending} onClick={() => form.submit()}>Guardar modelo</Button></Space>}>
        <Form form={form} layout="vertical" onFinish={gravar}>
          <Form.Item name="nome" label="Nome do modelo" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={100} /></Form.Item>
          <Form.Item name="assunto" label="Assunto" rules={[{ required: true, message: 'Indique o assunto.' }]}><Input maxLength={50} showCount /></Form.Item>
          <Form.Item name="corpo" label="Corpo da carta" rules={[{ required: true, message: 'Indique o texto.' }]}><Input.TextArea rows={10} maxLength={10000} /></Form.Item>
          <Row gutter={12}>
            <Col xs={24} md={8}><Form.Item name="nome_assinatura" label="Assinado por"><Input maxLength={255} /></Form.Item></Col>
            <Col xs={24} md={8}><Form.Item name="cargo_assinatura" label="Cargo"><Input maxLength={100} /></Form.Item></Col>
            <Col xs={24} md={8}><Form.Item name="local" label="Local"><Input maxLength={100} placeholder="Ex.: Luanda" /></Form.Item></Col>
          </Row>
          <Form.Item name="predefinido" valuePropName="checked"><Checkbox>Predefinido para as cartas deste tipo</Checkbox></Form.Item>
        </Form>
      </Modal>
    </Row>
  );
}
