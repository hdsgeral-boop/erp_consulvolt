import { Col, DatePicker, Form, Input, InputNumber, Progress, Row } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { larguraModal } from '@/componentes/responsivo';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo } from 'react';
import { enviar, obterPagina } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { dataDe, fimAntesDe, textoOuNull } from '@/modulos/vendas/comum/valoresFormulario';
import { SeletorTerceiro } from '../comum/Seletores';
import type { FichaTerceiro } from '../comum/referencias';
import type { ContratoCompra } from '../comum/tipos';

interface ValoresContrato {
  fornecedor_id?: number;
  referencia: string;
  descricao?: string;
  data_inicio?: Dayjs | null;
  data_fim?: Dayjs | null;
  valor_total?: number;
}

/** Corpo do pedido do contrato — o mesmo na grade e na vista Linhas (datas em `dayjs` ou 'AAAA-MM-DD'). */
export function dadosContrato(v: Record<string, unknown>): Record<string, unknown> {
  return {
    fornecedor_id: v.fornecedor_id,
    referencia: String(v.referencia ?? '').trim(),
    descricao: textoOuNull(v.descricao),
    data_inicio: dataDe(v.data_inicio),
    data_fim: dataDe(v.data_fim),
    valor_total: v.valor_total,
  };
}

/** Colunas da vista Linhas dos contratos (as regras da grade: fornecedor, referência ≤ 50, valor obrigatório, fim ≥ início). */
export function camposContrato(fornecedores: { value: number; label: string }[]): CampoFormulario[] {
  return [
    { nome: 'fornecedor_id', rotulo: 'Fornecedor', tipo: 'select', obrigatorio: true, opcoes: fornecedores, largura: 230, aliases: ['NIF', 'Fornecedor (NIF)'] },
    { nome: 'referencia', rotulo: 'Referência', obrigatorio: true, maxLength: 50, largura: 140, aliases: ['Ref', 'Contrato'] },
    { nome: 'descricao', rotulo: 'Objecto / descrição', tipo: 'textoLongo', maxLength: 4000, largura: 240, aliases: ['Objecto', 'Descrição'] },
    { nome: 'data_inicio', rotulo: 'Início', tipo: 'data', largura: 140, omissao: () => dayjs().format('YYYY-MM-DD') },
    { nome: 'data_fim', rotulo: 'Fim', tipo: 'data', largura: 140, validar: fimAntesDe('data_inicio') },
    { nome: 'valor_total', rotulo: 'Valor contratado (Kz)', tipo: 'numero', obrigatorio: true, min: 0, precisao: 2, largura: 150, aliases: ['Valor'] },
  ];
}

/** Criação ou edição do contrato (POST /compras/contratos; PUT /compras/contratos/{id}). */
export function ModalContrato({ contrato, aberto, aoFechar, aoGravar }: { contrato?: ContratoCompra; aberto: boolean; aoFechar: () => void; aoGravar?: (c: ContratoCompra) => void }) {
  const [form] = Form.useForm<ValoresContrato>();
  const gravar = useAccao<ContratoCompra>({
    invalidar: [['compras', 'contratos'], ['compras', 'contrato']],
    aoSucesso: (c) => {
      aoFechar();
      aoGravar?.(c);
    },
    tituloErro: 'Não foi possível gravar o contrato',
  });

  // fornecedores para a coluna da vista Linhas (só na criação)
  const fornecedores = useQuery({
    queryKey: ['terceiros', 'lista', 'FORNECEDOR'],
    queryFn: () => obterPagina<FichaTerceiro>('/terceiros', { papel: 'FORNECEDOR', por_pagina: 500, estrito: 1 }),
    enabled: aberto && !contrato,
    staleTime: 60_000,
    retry: false,
  });
  const campos = useMemo(
    () => camposContrato((fornecedores.data?.itens ?? []).map((t) => ({ value: t.id, label: `${t.nome.trim()}${t.nif ? ` (${t.nif})` : ''}` }))),
    [fornecedores.data],
  );

  useEffect(() => {
    if (!aberto) return;
    form.setFieldsValue(
      contrato
        ? {
            fornecedor_id: contrato.fornecedor_id,
            referencia: contrato.referencia,
            descricao: contrato.descricao ?? undefined,
            data_inicio: contrato.data_inicio ? dayjs(contrato.data_inicio) : null,
            data_fim: contrato.data_fim ? dayjs(contrato.data_fim) : null,
            valor_total: Number(contrato.valor_total),
          }
        : { fornecedor_id: undefined, referencia: '', descricao: undefined, data_inicio: dayjs(), data_fim: null, valor_total: undefined },
    );
  }, [aberto, contrato, form]);

  return (
    <ModalFormulario
      chave="compras.contratos"
      titulo={contrato ? `Editar contrato ${contrato.referencia}` : 'Novo contrato'}
      aberto={aberto}
      aoFechar={aoFechar}
      criacao={!contrato}
      form={form}
      campos={campos}
      nomeRegistos="contratos"
      invalidar={[['compras', 'contratos'], ['compras', 'contrato']]}
      gravarLinha={(r) => enviar('post', '/compras/contratos', dadosContrato(r))}
      nota="Uma linha por contrato de fornecimento. Linhas deixadas em branco são ignoradas. Cada linha é gravada com as mesmas regras do formulário."
      okText="Gravar"
      cancelText="Cancelar"
      width={larguraModal(680)}
      confirmLoading={gravar.isPending}
      onOk={() => form.submit()}
    >
      <Form<ValoresContrato>
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const dados = dadosContrato(v as unknown as Record<string, unknown>);
          gravar.mutate(contrato ? { metodo: 'put', url: `/compras/contratos/${contrato.id}`, dados } : { url: '/compras/contratos', dados });
        }}
      >
        <Row gutter={16}>
          <Col xs={24} md={14}>
            <Form.Item name="fornecedor_id" label="Fornecedor" rules={[{ required: true, message: 'Escolha o fornecedor.' }]} tooltip={contrato ? 'Não muda se o contrato já tiver encomendas.' : undefined}>
              <SeletorTerceiro papel="FORNECEDOR" />
            </Form.Item>
          </Col>
          <Col xs={24} md={10}>
            <Form.Item name="referencia" label="Referência" rules={[{ required: true, message: 'Indique a referência.' }, { max: 50 }]}>
              <Input />
            </Form.Item>
          </Col>
          <Col span={24}>
            <Form.Item name="descricao" label="Objecto / descrição" rules={[{ max: 4000 }]}>
              <Input.TextArea rows={2} />
            </Form.Item>
          </Col>
          <Col xs={12} md={7}>
            <Form.Item name="data_inicio" label="Vigência: início">
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={12} md={7}>
            <Form.Item
              name="data_fim"
              label="Fim"
              dependencies={['data_inicio']}
              rules={[({ getFieldValue }) => ({
                validator: (_, v: Dayjs | null) => (!v || !getFieldValue('data_inicio') || !v.isBefore(getFieldValue('data_inicio'), 'day') ? Promise.resolve() : Promise.reject(new Error('O fim é anterior ao início.'))),
              })]}
            >
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={10}>
            <Form.Item name="valor_total" label="Valor contratado (Kz)" rules={[{ required: true, message: 'Indique o valor.' }]}>
              <InputNumber min={0} precision={2} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </ModalFormulario>
  );
}

/** Barra de consumo do contrato (encomendado face ao contratado). */
export function ConsumoContrato({ contrato }: { contrato: ContratoCompra }) {
  const c = contrato.consumo;
  if (!c) return null;
  return <Progress percent={Math.min(100, c.percentagem)} status={c.excedido ? 'exception' : undefined} format={() => `${c.percentagem}%`} />;
}
