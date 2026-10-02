import { Col, Form, Input, Modal, Radio, Row, Select } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { obterPagina } from '@/api/cliente';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { formatarKz } from '@/utilitarios/formatacao';
import type { FichaProjecto, Projecto } from './comum/tipos';
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
}

/** Ficha do projecto: INTERNO exige unidade de negócio e centro de custo; EXTERNO (obra) exige cliente e encomenda de venda. */
export function ModalProjecto({ aberto, projecto, aoFechar, aoGravar }: { aberto: boolean; projecto?: FichaProjecto | null; aoFechar: () => void; aoGravar?: (p: Projecto) => void }) {
  const [form] = Form.useForm<Valores>();
  const tipo = Form.useWatch('tipo', form);
  const cliente = Form.useWatch('cliente_id', form);
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
            centro_custo_id: projecto.centro_custo_id ?? undefined, cliente_id: projecto.cliente_id ?? undefined, encomenda_venda_id: projecto.encomenda_venda_id ?? undefined }
        : { tipo: 'INTERNO', estado: 'PREPARACAO' },
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
          const dados = {
            ...v, codigo: v.codigo || null,
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
      </Form>
    </Modal>
  );
}
