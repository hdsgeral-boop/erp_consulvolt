import { Alert, Button, Card, Col, Divider, Drawer, Flex, Form, Input, InputNumber, Modal, Radio, Row, Select, Space, Switch, Table, Tag, TimePicker, Tooltip, Typography } from 'antd';
import { CopyOutlined, DeleteOutlined, EditOutlined, PlusOutlined, PoweroffOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { NomeArmazem } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem, SeletorConta, SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { TIPOS_MEIO, TIPOS_TERMINAL, useTerminais } from './comum/dados';
import { classeLiquidacao, meiosPadrao, meiosParaApi, validarMeios } from './comum/meios';
import { accoesTerminal } from './comum/regras';
import type { MeioPagamento, Terminal, TipoTerminal } from './comum/tipos';

/**
 * POS › Terminais (ecrã pos_terminais): terminais, meios de pagamento com as contas transitória/liquidação/comissão,
 * cópia de meios de outro terminal (mesma ou outra empresa), activar/desactivar e eliminar (ADR-047).
 */
export default function Terminais() {
  const { pode } = useSessao();
  const terminais = useTerminais();
  const [editar, setEditar] = useState<Terminal | 'novo' | null>(null);
  const [copiar, setCopiar] = useState<Terminal | null>(null);
  const accao = useAccao({ invalidar: [['pos']] });

  return (
    <>
      <CabecalhoPagina
        titulo="Terminais POS"
        subtitulo="Terminais de venda, meios de pagamento e contas"
        accoes={
          pode('pos_terminais_gerir') && (
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar('novo')}>
              Novo terminal
            </Button>
          )
        }
      />
      <Table<Terminal>
        rowKey="id"
        size="middle"
        loading={terminais.isFetching}
        dataSource={terminais.data}
        pagination={false}
        scroll={{ x: 'max-content' }}
        expandable={{ expandedRowRender: (t) => <TabelaMeiosTerminal meios={t.meios_pagamento ?? []} /> }}
        columns={[
          { title: 'Código', dataIndex: 'codigo', render: (v) => <b>{v}</b> },
          { title: 'Nome', dataIndex: 'nome' },
          { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => TIPOS_TERMINAL[v] ?? v },
          { title: 'Armazém', dataIndex: 'armazem_id', render: (v) => (v ? <NomeArmazem id={v} /> : 'Predefinido') },
          { title: 'Fundo padrão', dataIndex: 'fundo_maneio_padrao', align: 'right', render: (v) => formatarKz(v) },
          { title: 'Meios activos', render: (_, t) => (t.meios_pagamento ?? []).filter((m) => m.ativo).map((m) => <Tag key={m.id}>{m.nome}</Tag>) },
          { title: 'Sessão', dataIndex: 'sessao_aberta', render: (s: Terminal['sessao_aberta']) => (s ? <Tooltip title={`${s.nome_operador ?? ''} · ${formatarDataHora(s.aberto_em)}`}><Tag color="processing">{s.codigo_sessao}</Tag></Tooltip> : '—') },
          { title: 'Estado', dataIndex: 'ativo', render: (v) => (v ? <Tag color="green">Activo</Tag> : <Tag>Inactivo</Tag>) },
          {
            title: '',
            key: 'accoes',
            fixed: 'right',
            render: (_, t) => {
              const a = accoesTerminal(pode, t);
              return (
                <Space size={4}>
                  {a.editar && <Button size="small" icon={<EditOutlined />} onClick={() => setEditar(t)} aria-label="Editar" />}
                  {a.copiarMeios && (
                    <Tooltip title="Copiar meios de pagamento de outro terminal">
                      <Button size="small" icon={<CopyOutlined />} onClick={() => setCopiar(t)} aria-label="Copiar meios" />
                    </Tooltip>
                  )}
                  {a.activar && (
                    <Button size="small" icon={<PoweroffOutlined />} onClick={() => accao.mutate({ url: `/pos/terminais/${t.id}/ativo`, dados: { ativo: true } })}>
                      Activar
                    </Button>
                  )}
                  {a.desactivar.visivel && (
                    <Tooltip title={a.desactivar.bloqueio}>
                      <Button size="small" icon={<PoweroffOutlined />} disabled={!!a.desactivar.bloqueio} onClick={() => accao.mutate({ url: `/pos/terminais/${t.id}/ativo`, dados: { ativo: false } })}>
                        Desactivar
                      </Button>
                    </Tooltip>
                  )}
                  {a.eliminar && (
                    <Button
                      size="small"
                      danger
                      icon={<DeleteOutlined />}
                      aria-label="Eliminar"
                      onClick={() =>
                        Modal.confirm({
                          title: `Eliminar o terminal ${t.codigo}?`,
                          content: 'Só é possível se o terminal nunca teve sessões nem vendas; caso contrário, desactive-o.',
                          okText: 'Eliminar',
                          okButtonProps: { danger: true },
                          cancelText: 'Cancelar',
                          onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/pos/terminais/${t.id}` }).catch(() => undefined),
                        })
                      }
                    />
                  )}
                </Space>
              );
            },
          },
        ]}
      />
      <EditorTerminal alvo={editar} aoFechar={() => setEditar(null)} />
      <ModalCopiarMeios destino={copiar} terminais={terminais.data ?? []} aoFechar={() => setCopiar(null)} />
    </>
  );
}

function TabelaMeiosTerminal({ meios }: { meios: MeioPagamento[] }) {
  return (
    <Table<MeioPagamento>
      size="small"
      pagination={false}
      rowKey={(m) => m.id ?? m.nome}
      dataSource={meios}
      columns={[
        { title: 'Meio', dataIndex: 'nome' },
        { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => TIPOS_MEIO[v] ?? v },
        { title: 'Transitória', dataIndex: 'conta_transitoria', render: (v) => v ?? '—' },
        { title: 'Liquidação', dataIndex: 'conta_liquidacao', render: (v) => v ?? '—' },
        { title: 'TPA', render: (_, m) => (m.tipo === 'TPA' ? `${m.codigo_tpa ?? '—'} · comissão ${m.comissao_pct ?? 0}%${m.conta_comissao ? ` (${m.conta_comissao})` : ''}${m.comissao_deduzida === false ? ' · não deduzida' : ''}` : '—') },
        { title: 'Copiado de', dataIndex: 'copiado_de', render: (v) => v ?? '—' },
        { title: 'Estado', dataIndex: 'ativo', render: (v) => (v ? <Tag color="green">Activo</Tag> : <Tag>Inactivo</Tag>) },
      ]}
    />
  );
}

interface FormTerminal {
  codigo: string;
  nome: string;
  tipo: TipoTerminal;
  armazem_id?: number | null;
  cliente_padrao_id?: number | null;
  fundo_maneio_padrao?: number | null;
  unidade_negocio_id?: number | null;
  centro_custo_id?: number | null;
  meios_pagamento: MeioPagamento[];
  hotel_hora_entrada?: Dayjs | null;
  hotel_hora_saida?: Dayjs | null;
  hotel_tolerancia_atraso_min?: number | null;
  hotel_bloco_horas?: boolean;
  hotel_bloco_horas_de?: Dayjs | null;
  hotel_bloco_horas_ate?: Dayjs | null;
}

const hora = (v: string | null | undefined) => (v ? dayjs(v, 'HH:mm') : null);
const textoHora = (v: Dayjs | null | undefined) => (v ? v.format('HH:mm') : null);

function EditorTerminal({ alvo, aoFechar }: { alvo: Terminal | 'novo' | null; aoFechar: () => void }) {
  const [form] = Form.useForm<FormTerminal>();
  const [erros, setErros] = useState<string[]>([]);
  const novo = alvo === 'novo';
  const t = alvo && alvo !== 'novo' ? alvo : null;
  const tipo = Form.useWatch('tipo', form);
  const gravar = useAccao({ invalidar: [['pos']], aoSucesso: aoFechar, tituloErro: 'Não foi possível gravar o terminal' });

  useEffect(() => {
    if (!alvo) return;
    setErros([]);
    form.resetFields();
    form.setFieldsValue(
      t
        ? {
            codigo: t.codigo,
            nome: t.nome,
            tipo: t.tipo,
            armazem_id: t.armazem_id,
            cliente_padrao_id: t.cliente_padrao_id,
            fundo_maneio_padrao: Number(t.fundo_maneio_padrao ?? 0),
            unidade_negocio_id: t.unidade_negocio_id,
            centro_custo_id: t.centro_custo_id,
            meios_pagamento: t.meios_pagamento?.length ? t.meios_pagamento : meiosPadrao(),
            hotel_hora_entrada: hora(t.hotel_hora_entrada ?? '14:00'),
            hotel_hora_saida: hora(t.hotel_hora_saida ?? '12:00'),
            hotel_tolerancia_atraso_min: t.hotel_tolerancia_atraso_min ?? 60,
            hotel_bloco_horas: t.hotel_bloco_horas !== false,
            hotel_bloco_horas_de: hora(t.hotel_bloco_horas_de ?? '21:00'),
            hotel_bloco_horas_ate: hora(t.hotel_bloco_horas_ate ?? '08:00'),
          }
        : { tipo: 'LOJA', fundo_maneio_padrao: 0, meios_pagamento: meiosPadrao(), hotel_hora_entrada: hora('14:00'), hotel_hora_saida: hora('12:00'), hotel_tolerancia_atraso_min: 60, hotel_bloco_horas: true, hotel_bloco_horas_de: hora('21:00'), hotel_bloco_horas_ate: hora('08:00') },
    );
  }, [alvo, t, form]);

  const enviar = (v: FormTerminal) => {
    const meios = v.meios_pagamento ?? [];
    const e = validarMeios(meios);
    setErros(e);
    if (e.length) return;
    const dados = {
      codigo: v.codigo?.trim().toUpperCase(),
      nome: v.nome?.trim(),
      tipo: v.tipo,
      armazem_id: v.armazem_id ?? null,
      cliente_padrao_id: v.cliente_padrao_id ?? null,
      fundo_maneio_padrao: v.fundo_maneio_padrao ?? 0,
      unidade_negocio_id: v.unidade_negocio_id ?? null,
      centro_custo_id: v.centro_custo_id ?? null,
      meios_pagamento: meiosParaApi(meios),
      ...(v.tipo === 'HOTELARIA'
        ? {
            hotel_hora_entrada: textoHora(v.hotel_hora_entrada),
            hotel_hora_saida: textoHora(v.hotel_hora_saida),
            hotel_tolerancia_atraso_min: v.hotel_tolerancia_atraso_min ?? 60,
            hotel_bloco_horas: !!v.hotel_bloco_horas,
            hotel_bloco_horas_de: textoHora(v.hotel_bloco_horas_de),
            hotel_bloco_horas_ate: textoHora(v.hotel_bloco_horas_ate),
          }
        : {}),
    };
    gravar.mutate(t ? { metodo: 'put', url: `/pos/terminais/${t.id}`, dados } : { url: '/pos/terminais', dados });
  };

  return (
    <Drawer
      open={!!alvo}
      onClose={aoFechar}
      width={980}
      title={novo ? 'Novo terminal' : `Terminal ${t?.codigo ?? ''}`}
      destroyOnClose
      extra={
        <Space>
          <Button onClick={aoFechar}>Cancelar</Button>
          <Button type="primary" loading={gravar.isPending} onClick={() => form.submit()}>
            Gravar
          </Button>
        </Space>
      }
    >
      <Form form={form} layout="vertical" onFinish={enviar}>
        <Row gutter={16}>
          <Col xs={24} md={6}>
            <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { pattern: /^[A-Za-z0-9_-]{1,10}$/, message: 'Até 10 letras, algarismos, «-» ou «_».' }]} extra={t ? 'Bloqueado depois da primeira sessão (entra na numeração).' : undefined}>
              <Input maxLength={10} style={{ textTransform: 'uppercase' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}>
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="tipo" label="Tipo">
              <Select options={Object.entries(TIPOS_TERMINAL).map(([value, label]) => ({ value, label }))} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="armazem_id" label="Armazém" extra="Sem armazém: o predefinido.">
              <SeletorArmazem allowClear />
            </Form.Item>
          </Col>
          <Col xs={24} md={10}>
            <Form.Item name="cliente_padrao_id" label="Cliente padrão" extra="Sem cliente: «Consumidor Final».">
              <SeletorTerceiro papel="CLIENTE" />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="fundo_maneio_padrao" label="Fundo de maneio padrão">
              <InputNumber<number> min={0} precision={2} decimalSeparator="," style={{ width: '100%' }} addonAfter="Kz" />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="unidade_negocio_id" label="Unidade de negócio">
              <SeletorUnidade style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="centro_custo_id" label="Centro de custo">
              <SeletorAux tabela="centros-custo" placeholder="Centro de custo" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>

        {tipo === 'HOTELARIA' && (
          <>
            <Divider orientation="left">Regras da hotelaria</Divider>
            <Flex gap={16} wrap>
              <Form.Item name="hotel_hora_entrada" label="Hora de entrada">
                <TimePicker format="HH:mm" minuteStep={5} />
              </Form.Item>
              <Form.Item name="hotel_hora_saida" label="Hora de saída">
                <TimePicker format="HH:mm" minuteStep={5} />
              </Form.Item>
              <Form.Item name="hotel_tolerancia_atraso_min" label="Tolerância de atraso (min)">
                <InputNumber<number> min={0} precision={0} />
              </Form.Item>
              <Form.Item name="hotel_bloco_horas" label="Bloquear venda à hora" valuePropName="checked">
                <Switch />
              </Form.Item>
              <Form.Item name="hotel_bloco_horas_de" label="Das">
                <TimePicker format="HH:mm" minuteStep={5} />
              </Form.Item>
              <Form.Item name="hotel_bloco_horas_ate" label="Até">
                <TimePicker format="HH:mm" minuteStep={5} />
              </Form.Item>
            </Flex>
          </>
        )}

        <Divider orientation="left">Meios de pagamento</Divider>
        <Typography.Paragraph type="secondary">
          Cada meio activo precisa de uma conta transitória própria (saldada na prestação de contas) e da conta de liquidação: caixa (45) no numerário, bancos (43) no TPA e na transferência.
        </Typography.Paragraph>
        <Form.List name="meios_pagamento">
          {(campos, { add, remove }) => (
            <Flex vertical gap={12}>
              {campos.map((campo) => (
                <EditorMeio key={campo.key} nome={campo.name} aoRemover={() => remove(campo.name)} />
              ))}
              <Space>
                {(['NUMERARIO', 'TPA', 'TRANSFERENCIA'] as const).map((tp) => (
                  <Button key={tp} icon={<PlusOutlined />} onClick={() => add({ tipo: tp, nome: TIPOS_MEIO[tp], ativo: true, conta_transitoria: null, conta_liquidacao: null, ...(tp === 'TPA' ? { comissao_pct: 0, comissao_deduzida: true } : {}) })}>
                    {TIPOS_MEIO[tp]}
                  </Button>
                ))}
              </Space>
            </Flex>
          )}
        </Form.List>
        {erros.length > 0 && (
          <Alert
            style={{ marginTop: 12 }}
            type="error"
            showIcon
            message="Corrija os meios de pagamento"
            description={
              <ul style={{ margin: 0, paddingLeft: 18 }}>
                {erros.map((e) => (
                  <li key={e}>{e}</li>
                ))}
              </ul>
            }
          />
        )}
      </Form>
    </Drawer>
  );
}

function EditorMeio({ nome, aoRemover }: { nome: number; aoRemover: () => void }) {
  const form = Form.useFormInstance<FormTerminal>();
  const tipo = Form.useWatch(['meios_pagamento', nome, 'tipo'], form) as MeioPagamento['tipo'] | undefined;
  const pct = Form.useWatch(['meios_pagamento', nome, 'comissao_pct'], form) as number | undefined;
  return (
    <Card size="small" extra={<Button size="small" type="text" danger icon={<DeleteOutlined />} onClick={aoRemover} aria-label="Retirar meio" />} title={tipo ? TIPOS_MEIO[tipo] : 'Meio'}>
      <Form.Item name={[nome, 'id']} hidden>
        <Input />
      </Form.Item>
      <Form.Item name={[nome, 'tipo']} hidden>
        <Input />
      </Form.Item>
      <Row gutter={12}>
        <Col xs={24} md={8}>
          <Form.Item name={[nome, 'nome']} label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}>
            <Input maxLength={100} />
          </Form.Item>
        </Col>
        <Col xs={12} md={4}>
          <Form.Item name={[nome, 'ativo']} label="Activo" valuePropName="checked">
            <Switch />
          </Form.Item>
        </Col>
        <Col xs={24} md={6}>
          <Form.Item name={[nome, 'conta_transitoria']} label="Conta transitória">
            <SeletorConta />
          </Form.Item>
        </Col>
        <Col xs={24} md={6}>
          <Form.Item name={[nome, 'conta_liquidacao']} label={`Liquidação (${tipo ? classeLiquidacao(tipo) : '—'})`}>
            <SeletorConta prefixo={tipo ? classeLiquidacao(tipo) : undefined} />
          </Form.Item>
        </Col>
        {tipo === 'TPA' && (
          <>
            <Col xs={12} md={6}>
              <Form.Item name={[nome, 'codigo_tpa']} label="Código do TPA">
                <Input maxLength={30} />
              </Form.Item>
            </Col>
            <Col xs={12} md={6}>
              <Form.Item name={[nome, 'comissao_pct']} label="Comissão (%)">
                <InputNumber<number> min={0} max={99.99} precision={2} decimalSeparator="," style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item name={[nome, 'conta_comissao']} label="Conta da comissão" rules={[{ required: Number(pct ?? 0) > 0, message: 'Com comissão, indique a conta.' }]}>
                <SeletorConta />
              </Form.Item>
            </Col>
            <Col xs={24} md={6}>
              <Form.Item name={[nome, 'comissao_deduzida']} label="Comissão deduzida no recebimento" valuePropName="checked">
                <Switch />
              </Form.Item>
            </Col>
          </>
        )}
      </Row>
    </Card>
  );
}

function ModalCopiarMeios({ destino, terminais, aoFechar }: { destino: Terminal | null; terminais: Terminal[]; aoFechar: () => void }) {
  const { empresa, empresas } = useSessao();
  const [form] = Form.useForm<{ empresa_origem_id: number; terminal_origem_id: number; modo: 'SUBSTITUIR' | 'ACRESCENTAR' }>();
  const empresaOrigem = Form.useWatch('empresa_origem_id', form);
  const outraEmpresa = !!empresaOrigem && empresaOrigem !== empresa?.id;
  const deOutra = useQuery({
    queryKey: ['pos', 'terminais', 'empresa', empresaOrigem],
    queryFn: () => obter<Terminal[]>('/pos/terminais', undefined, { headers: { 'X-Empresa-Id': String(empresaOrigem) } }),
    enabled: !!destino && outraEmpresa,
    retry: false,
  });
  const copiar = useAccao({ invalidar: [['pos']], aoSucesso: aoFechar, tituloErro: 'Não foi possível copiar os meios' });
  useEffect(() => {
    if (destino) form.setFieldsValue({ empresa_origem_id: empresa?.id, terminal_origem_id: undefined, modo: 'SUBSTITUIR' });
  }, [destino, empresa, form]);
  const origens = (outraEmpresa ? deOutra.data ?? [] : terminais.filter((t) => t.id !== destino?.id)).map((t) => ({
    value: t.id,
    label: `${t.codigo} — ${t.nome} (${(t.meios_pagamento ?? []).length} meio(s))`,
  }));
  return (
    <Modal open={!!destino} title={`Copiar meios de pagamento para ${destino?.codigo ?? ''}`} okText="Copiar" cancelText="Cancelar" confirmLoading={copiar.isPending} onCancel={aoFechar} onOk={() => form.submit()} destroyOnClose>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) =>
          destino &&
          copiar.mutate({
            url: `/pos/terminais/${destino.id}/copiar-meios`,
            dados: { terminal_origem_id: v.terminal_origem_id, modo: v.modo, ...(outraEmpresa ? { empresa_origem_id: v.empresa_origem_id } : {}) },
          })
        }
      >
        {empresas.length > 1 && (
          <Form.Item name="empresa_origem_id" label="Empresa de origem">
            <Select options={empresas.map((e) => ({ value: e.id, label: e.nome }))} onChange={() => form.setFieldValue('terminal_origem_id', undefined)} />
          </Form.Item>
        )}
        <Form.Item name="terminal_origem_id" label="Terminal de origem" rules={[{ required: true, message: 'Escolha o terminal de origem.' }]}>
          <Select loading={deOutra.isFetching} options={origens} placeholder="Terminal" />
        </Form.Item>
        <Form.Item name="modo" label="Modo">
          <Radio.Group>
            <Space direction="vertical">
              <Radio value="SUBSTITUIR">Substituir os meios actuais (reaproveita os identificadores por tipo)</Radio>
              <Radio value="ACRESCENTAR">Acrescentar aos meios actuais</Radio>
            </Space>
          </Radio.Group>
        </Form.Item>
        <Alert type="info" showIcon message="As contas são validadas no destino: têm de existir no plano desta empresa." />
      </Form>
    </Modal>
  );
}
