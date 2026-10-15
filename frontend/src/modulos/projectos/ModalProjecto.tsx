import { Col, Divider, Form, Input, InputNumber, Modal, Radio, Row, Select, Space, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { obterPagina } from '@/api/cliente';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { formatarKz } from '@/utilitarios/formatacao';
import type { FichaProjecto, Projecto } from './comum/tipos';
import { diasDoCalendario, MODELOS_CAL, modeloDe, NOMES_DIA } from './comum/regrasMetas';
import { larguraModal } from '@/componentes/responsivo';

interface Valores {
  codigo?: string;
  nome: string;
  tipo: 'INTERNO' | 'EXTERNO';
  estado?: string;
  unidade_negocio_id?: number;
  centro_custo_id?: number;
  cliente_id?: number;
  encomenda_venda_id?: number;
  /** Versão Actual: calendário de trabalho (modelo ou dias personalizados) e descrição. */
  cal_modelo?: string;
  cal_horas?: number;
  cal_dias?: number[];
  descricao?: string;
}

/** Ficha do projecto: INTERNO exige unidade de negócio e centro de custo; EXTERNO (obra) exige cliente e encomenda de venda. */
export function ModalProjecto({ aberto, projecto, aoFechar, aoGravar }: { aberto: boolean; projecto?: FichaProjecto | null; aoFechar: () => void; aoGravar?: (p: Projecto) => void }) {
  const [form] = Form.useForm<Valores>();
  const tipo = Form.useWatch('tipo', form);
  const cliente = Form.useWatch('cliente_id', form);
  const calModelo = Form.useWatch('cal_modelo', form);
  const accao = useAccao<Projecto>({ invalidar: [['projectos']], aoSucesso: (p) => { aoGravar?.(p); aoFechar(); } });
  const encomendas = useQuery({
    queryKey: ['projectos', 'encomendas-venda', cliente],
    queryFn: () => obterPagina<{ id: number; numero_documento: string; data_emissao: string; total_liquido: string; estado: string | null }>('/vendas/documentos', { tipo_documento: 'NE', cliente_id: cliente, por_pagina: 100 }),
    enabled: tipo === 'EXTERNO' && !!cliente,
    retry: false,
  });

  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(
      projecto
        ? { codigo: projecto.codigo ?? undefined, nome: projecto.nome, tipo: projecto.tipo as Valores['tipo'], estado: projecto.estado, unidade_negocio_id: projecto.unidade_negocio_id ?? undefined,
            centro_custo_id: projecto.centro_custo_id ?? undefined, cliente_id: projecto.cliente_id ?? undefined, encomenda_venda_id: projecto.encomenda_venda_id ?? undefined,
            cal_modelo: modeloDe(diasDoCalendario(projecto.calendario)), cal_horas: projecto.calendario?.horas_dia ?? 8, cal_dias: diasDoCalendario(projecto.calendario),
            descricao: projecto.descricao ?? undefined }
        : { tipo: 'INTERNO', estado: 'PREPARACAO', cal_modelo: 'SEG_SEX', cal_horas: 8, cal_dias: MODELOS_CAL.SEG_SEX.dias },
    );
  }, [aberto, projecto, form]);

  const opcoesEncomenda = (encomendas.data?.itens ?? []).map((e) => ({ value: e.id, label: `${e.numero_documento} — ${formatarKz(e.total_liquido)} Kz` }));
  if (projecto?.encomenda && !opcoesEncomenda.some((o) => o.value === projecto.encomenda?.id))
    opcoesEncomenda.unshift({ value: projecto.encomenda.id, label: projecto.encomenda.numero_documento });

  return (
    <Modal
      title={projecto ? `Editar projecto ${projecto.codigo ?? ''}` : 'Novo projecto'}
      open={aberto}
      onCancel={aoFechar}
      onOk={() => form.submit()}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={accao.isPending}
      width={larguraModal(760)}
      destroyOnHidden
    >
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const { cal_modelo: modelo, cal_horas: horas, cal_dias: diasCal, ...resto } = v;
          const dados = {
            ...resto, codigo: v.codigo || null,
            // calendário de trabalho (ui_projects.js:246-334): o servidor valida horas (0,5 a 24) e dias (0, ½ ou 1)
            calendario: { modelo: modelo ?? 'SEG_SEX', horas_dia: horas ?? 8, dias: modelo && modelo !== 'PERSONALIZADO' ? MODELOS_CAL[modelo].dias : diasCal ?? MODELOS_CAL.SEG_SEX.dias },
            descricao: v.descricao || null,
            unidade_negocio_id: v.unidade_negocio_id ?? null,
            centro_custo_id: v.centro_custo_id ?? null,
            cliente_id: v.tipo === 'EXTERNO' ? v.cliente_id ?? null : null,
            encomenda_venda_id: v.tipo === 'EXTERNO' ? v.encomenda_venda_id ?? null : null,
          };
          if (projecto) delete (dados as Partial<Valores>).estado;
          accao.mutate(projecto ? { metodo: 'put', url: `/projetos/${projecto.id}`, dados } : { url: '/projetos', dados });
        }}
      >
        <Row gutter={12}>
          <Col xs={24} md={6}>
            <Form.Item name="codigo" label="Código" tooltip="Vazio = numeração automática">
              <Input maxLength={50} placeholder="Automático" />
            </Form.Item>
          </Col>
          <Col xs={24} md={18}>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}>
              <Input maxLength={255} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
              <Radio.Group optionType="button" options={[{ value: 'INTERNO', label: 'Interno' }, { value: 'EXTERNO', label: 'Externo (obra)' }]} />
            </Form.Item>
          </Col>
          {!projecto && (
            <Col xs={24} md={12}>
              <Form.Item name="estado" label="Estado inicial">
                <Select options={[{ value: 'PREPARACAO', label: 'Em preparação' }, { value: 'ACTIVO', label: 'Activo' }]} />
              </Form.Item>
            </Col>
          )}
          <Col xs={24} md={12}>
            <Form.Item name="unidade_negocio_id" label="Unidade de negócio" rules={[{ required: tipo === 'INTERNO', message: 'Obrigatória num projecto interno.' }]}>
              <SeletorUnidade style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="centro_custo_id" label="Centro de custo" rules={[{ required: tipo === 'INTERNO', message: 'Obrigatório num projecto interno.' }]}>
              <SeletorAux tabela="centros-custo" placeholder="Centro de custo" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          {tipo === 'EXTERNO' && (
            <>
              <Col xs={24} md={12}>
                <Form.Item name="cliente_id" label="Cliente" rules={[{ required: true, message: 'Indique o cliente.' }]}>
                  <SeletorTerceiro papel="CLIENTE" onChange={() => form.setFieldValue('encomenda_venda_id', undefined)} />
                </Form.Item>
              </Col>
              <Col xs={24} md={12}>
                <Form.Item name="encomenda_venda_id" label="Encomenda de venda" rules={[{ required: true, message: 'Indique a encomenda.' }]}>
                  <Select placeholder={cliente ? 'Encomenda (NE) do cliente' : 'Escolha primeiro o cliente'} disabled={!cliente} loading={encomendas.isFetching} options={opcoesEncomenda} />
                </Form.Item>
              </Col>
            </>
          )}
        </Row>
        <Divider orientation="left" plain style={{ margin: '4px 0 12px' }}>Calendário de trabalho</Divider>
        <Row gutter={12}>
          <Col xs={24} md={14}>
            <Form.Item name="cal_modelo" label="Dias de trabalho" tooltip="Base das horas previstas e da repartição das metas pelo tempo">
              <Select options={[...Object.entries(MODELOS_CAL).map(([k, m]) => ({ value: k, label: m.nome })), { value: 'PERSONALIZADO', label: 'Personalizado, dia a dia' }]}
                onChange={(m: string) => { if (m !== 'PERSONALIZADO') form.setFieldValue('cal_dias', MODELOS_CAL[m].dias); }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={10}><Form.Item name="cal_horas" label="Horas por dia" rules={[{ type: 'number', min: 0.5, max: 24, message: 'Entre 0,5 e 24.' }]}><InputNumber min={0.5} max={24} step={0.5} style={{ width: '100%' }} decimalSeparator="," /></Form.Item></Col>
        </Row>
        {calModelo === 'PERSONALIZADO' && (
          <Form.Item label="Factor de cada dia" extra="0 = não se trabalha, ½ = meio dia, 1 = dia inteiro (pelo menos um dia de trabalho).">
            <Space wrap>
              {[1, 2, 3, 4, 5, 6, 0].map((d) => (
                <Form.Item key={d} name={['cal_dias', d]} noStyle>
                  <Select style={{ width: 120 }} aria-label={NOMES_DIA[d]} options={[0, 0.5, 1].map((f) => ({ value: f, label: `${NOMES_DIA[d].slice(0, 3)}: ${f === 0.5 ? '½' : f}` }))} />
                </Form.Item>
              ))}
            </Space>
          </Form.Item>
        )}
        <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>O calendário não conhece feriados nem turnos: é uma repartição linear pelos dias de trabalho.</Typography.Text>
      </Form>
    </Modal>
  );
}
