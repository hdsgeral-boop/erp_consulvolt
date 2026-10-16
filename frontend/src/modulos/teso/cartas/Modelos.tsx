import { Button, Card, Checkbox, Col, Empty, Form, Input, Modal, Row, Segmented, Skeleton, Space, Tag, Typography, theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ModalFormulario, SeccaoFormulario, type CampoFormulario } from '@/componentes/formularios';
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

const SIM_NAO = [{ value: 'S', label: 'Sim' }, { value: 'N', label: 'Não' }];

/** Regras de gravação do modelo de carta, comuns à grade e à vista Linhas (predefinido: caixa ou «Sim/Não»). */
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

/** Vista Linhas do «Novo modelo»: um modelo por linha (o assunto e o corpo começam com o texto padrão do tipo). */
export const CAMPOS_MODELO: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Nome do modelo', obrigatorio: true, largura: 200, maxLength: 100, aliases: ['Nome', 'Modelo'] },
  { nome: 'assunto', rotulo: 'Assunto', obrigatorio: true, largura: 220, maxLength: 50 },
  { nome: 'corpo', rotulo: 'Corpo da carta', tipo: 'textoLongo', obrigatorio: true, largura: 360, maxLength: 10000, aliases: ['Corpo', 'Texto'] },
  { nome: 'nome_assinatura', rotulo: 'Assinado por', largura: 180, maxLength: 255, emLinhas: 'opcional', seccao: 'Assinatura e local' },
  { nome: 'cargo_assinatura', rotulo: 'Cargo', largura: 160, maxLength: 100, emLinhas: 'opcional', seccao: 'Assinatura e local' },
  { nome: 'local', rotulo: 'Local', largura: 120, maxLength: 100, emLinhas: 'opcional', seccao: 'Assinatura e local' },
  { nome: 'predefinido_sn', rotulo: 'Predefinido', tipo: 'select', opcoes: SIM_NAO, omissao: 'N', largura: 110, emLinhas: 'opcional', seccao: 'Assinatura e local' },
];

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
  const { token } = theme.useToken();
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
  const gravar = (v: ValoresModelo) => {
    const r = v as unknown as Record<string, unknown>;
    accao.mutate(edicao === 'novo' ? { url: '/tesouraria/cartas/modelos', dados: dadosModelo(r, tipo) } : { metodo: 'put', url: `/tesouraria/cartas/modelos/${(edicao as ModeloCarta).id}`, dados: dadosModelo(r) });
  };
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
            <div key={v} style={{ display: 'flex', justifyContent: 'space-between', gap: 8, padding: '3px 0', borderBottom: `1px solid ${token.colorBorderSecondary}`, fontSize: 12 }}>
              <code style={{ color: '#0891b2', fontWeight: 600 }}>{`{{${v}}}`}</code><span style={{ color: token.colorTextSecondary, textAlign: 'right' }}>{d}</span>
            </div>
          ))}
        </Card>
      </Col>

      <ModalFormulario chave="teso.cartas_modelos" aberto={edicao !== null} width={larguraModal(760)} aoFechar={() => setEdicao(null)}
        titulo={`${edicao === 'novo' ? 'Novo modelo' : 'Editar modelo'} — ${ROTULO_TIPO_CARTA[tipo]}`}
        criacao={edicao === 'novo'} form={form} campos={CAMPOS_MODELO} nomeRegistos="modelos de carta" invalidar={[['teso', 'cartas']]}
        gravarLinha={(r) => enviar('post', '/tesouraria/cartas/modelos', dadosModelo(r, tipo))}
        nota={`Um modelo de carta (${ROTULO_TIPO_CARTA[tipo]}) por linha; o assunto e o corpo começam com o texto padrão. As variáveis {{…}} são substituídas na impressão. Linhas deixadas em branco são ignoradas. Campos com * são obrigatórios.`}
        footer={<Space wrap><Button icon={<IconeFa nome="undo" />} onClick={repor}>Repor o texto padrão</Button><Button onClick={() => setEdicao(null)}>Cancelar</Button>
          <Button type="primary" icon={<IconeFa nome="save" />} loading={accao.isPending} onClick={() => form.submit()}>Guardar modelo</Button></Space>}>
        <Form form={form} layout="vertical" onFinish={gravar}>
          <Form.Item name="nome" label="Nome do modelo" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={100} /></Form.Item>
          <SeccaoFormulario titulo="Texto da carta">
            <Form.Item name="assunto" label="Assunto" rules={[{ required: true, message: 'Indique o assunto.' }]}><Input maxLength={50} showCount /></Form.Item>
            <Form.Item name="corpo" label="Corpo da carta" rules={[{ required: true, message: 'Indique o texto.' }]}><Input.TextArea rows={10} maxLength={10000} /></Form.Item>
          </SeccaoFormulario>
          <SeccaoFormulario titulo="Assinatura e local">
            <Row gutter={12}>
              <Col xs={24} md={8}><Form.Item name="nome_assinatura" label="Assinado por"><Input maxLength={255} /></Form.Item></Col>
              <Col xs={24} md={8}><Form.Item name="cargo_assinatura" label="Cargo"><Input maxLength={100} /></Form.Item></Col>
              <Col xs={24} md={8}><Form.Item name="local" label="Local"><Input maxLength={100} placeholder="Ex.: Luanda" /></Form.Item></Col>
            </Row>
            <Form.Item name="predefinido" valuePropName="checked"><Checkbox>Predefinido para as cartas deste tipo</Checkbox></Form.Item>
          </SeccaoFormulario>
        </Form>
      </ModalFormulario>
    </Row>
  );
}
