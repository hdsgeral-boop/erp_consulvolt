import { Col, Form, Input, InputNumber, Radio, Row, Select, Space, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo } from 'react';
import { enviar, obterPagina } from '@/api/cliente';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { useTabelaAux, useUnidadesNegocio } from '@/modulos/contab/comum/dados';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { ModalFormulario, SeccaoFormulario, type CampoFormulario } from '@/componentes/formularios';
import { formatarKz } from '@/utilitarios/formatacao';
import type { FichaProjecto, Projecto } from './comum/tipos';
import { diasDoCalendario, MODELOS_CAL, modeloDe, NOMES_DIA } from './comum/regrasMetas';
import { dadosProjecto, ESTADOS_PROJECTO_INICIAL, TIPOS_PROJECTO, validarLinhaProjecto } from './comum/linhas';
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

interface EncomendaNe { id: number; numero_documento: string; data_emissao?: string; total_liquido?: string; estado?: string | null; cliente_id?: number | null; cliente?: { nome: string } | null }

/**
 * Ficha do projecto: INTERNO exige unidade de negócio e centro de custo; EXTERNO (obra) exige cliente e encomenda de venda.
 * Na criação há as vistas «Grade | Linhas» (legado: formularios_linhas_config.js › projectos); cada linha é gravada pelo
 * mesmo POST /projetos e com as mesmas regras (`dadosProjecto`). Na edição só a grade, com o calendário recolhível.
 */
export function ModalProjecto({ aberto, projecto, aoFechar, aoGravar }: { aberto: boolean; projecto?: FichaProjecto | null; aoFechar: () => void; aoGravar?: (p: Projecto) => void }) {
  const [form] = Form.useForm<Valores>();
  const tipo = Form.useWatch('tipo', form);
  const cliente = Form.useWatch('cliente_id', form);
  const calModelo = Form.useWatch('cal_modelo', form);
  const accao = useAccao<Projecto>({ invalidar: [['projectos']], aoSucesso: (p) => { aoGravar?.(p); aoFechar(); } });
  const encomendas = useQuery({
    queryKey: ['projectos', 'encomendas-venda', cliente],
    queryFn: () => obterPagina<EncomendaNe>('/vendas/documentos', { tipo_documento: 'NE', cliente_id: cliente, por_pagina: 100 }),
    enabled: tipo === 'EXTERNO' && !!cliente,
    retry: false,
  });

  // vista Linhas (só na criação): listas das colunas de escolha
  const criacao = aberto && !projecto;
  const unidades = useUnidadesNegocio(criacao);
  const centros = useTabelaAux('centros-custo', criacao);
  const clientes = useQuery({
    queryKey: ['terceiros', 'lista', 'CLIENTE'],
    queryFn: () => obterPagina<{ id: number; nome: string; nif: string | null }>('/terceiros', { papel: 'CLIENTE', estrito: 1, por_pagina: 500 }),
    enabled: criacao,
    retry: false,
  });
  const todasEncomendas = useQuery({
    queryKey: ['projectos', 'encomendas-venda', 'todas'],
    queryFn: () => obterPagina<EncomendaNe>('/vendas/documentos', { tipo_documento: 'NE', por_pagina: 500 }),
    enabled: criacao,
    retry: false,
  });
  const campos = useMemo<CampoFormulario[]>(() => {
    const clienteDaEncomenda = new Map((todasEncomendas.data?.itens ?? []).map((e) => [e.id, e.cliente_id ?? null]));
    return [
      { nome: 'tipo', rotulo: 'Tipo (INTERNO/EXTERNO)', tipo: 'select', opcoes: TIPOS_PROJECTO, omissao: 'INTERNO', largura: 130, aliases: ['Tipo'] },
      { nome: 'codigo', rotulo: 'Código', largura: 120, maxLength: 50, placeholder: 'Automático' },
      { nome: 'nome', rotulo: 'Designação', obrigatorio: true, largura: 240, maxLength: 255, aliases: ['Nome', 'Projecto', 'Obra'] },
      { nome: 'unidade_negocio_id', rotulo: 'Unidade de negócio (interno)', tipo: 'select', largura: 170, aliases: ['Unidade de negócio', 'UN'],
        opcoes: (unidades.data ?? []).map((x) => ({ value: x.id, label: `${x.codigo ? `${x.codigo} — ` : ''}${x.nome}` })),
        validar: (v, r) => validarLinhaProjecto('unidade_negocio_id', v, r) },
      { nome: 'centro_custo_id', rotulo: 'Centro de custo (interno)', tipo: 'select', largura: 170, aliases: ['Centro de custo', 'CC'],
        opcoes: (centros.data ?? []).map((c) => ({ value: c.id, label: `${c.codigo} — ${c.descricao ?? ''}` })),
        validar: (v, r) => validarLinhaProjecto('centro_custo_id', v, r) },
      { nome: 'cliente_id', rotulo: 'Cliente (externo)', tipo: 'select', largura: 190, aliases: ['Cliente'],
        opcoes: (clientes.data?.itens ?? []).map((t) => ({ value: t.id, label: `${t.nome.trim()}${t.nif ? ` (NIF ${t.nif})` : ''}` })),
        validar: (v, r) => validarLinhaProjecto('cliente_id', v, r) },
      { nome: 'encomenda_venda_id', rotulo: 'Encomenda (externo)', tipo: 'select', largura: 170, aliases: ['Encomenda', 'Encomenda de venda'],
        opcoes: (todasEncomendas.data?.itens ?? []).map((e) => ({ value: e.id, label: `${e.numero_documento}${e.cliente?.nome ? ` — ${e.cliente.nome}` : ''}` })),
        validar: (v, r) => {
          const erro = validarLinhaProjecto('encomenda_venda_id', v, r);
          if (erro) return erro;
          const dono = v ? clienteDaEncomenda.get(Number(v)) : null;
          return r.tipo === 'EXTERNO' && dono && r.cliente_id && dono !== r.cliente_id ? 'A encomenda não é deste cliente.' : null;
        } },
      { nome: 'estado', rotulo: 'Estado', tipo: 'select', opcoes: ESTADOS_PROJECTO_INICIAL, omissao: 'PREPARACAO', largura: 130, aliases: ['Estado inicial'] },
      { nome: 'descricao', rotulo: 'Descrição', emLinhas: 'opcional', largura: 220, maxLength: 5000 },
    ];
  }, [unidades.data, centros.data, clientes.data, todasEncomendas.data]);

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
    <ModalFormulario
      chave="projectos.projectos"
      titulo={projecto ? `Editar projecto ${projecto.codigo ?? ''}` : 'Novo projecto'}
      aberto={aberto}
      aoFechar={aoFechar}
      criacao={!projecto}
      form={form}
      campos={campos}
      nomeRegistos="projectos / obras"
      invalidar={[['projectos']]}
      gravarLinha={(r) => enviar('post', '/projetos', dadosProjecto(r))}
      nota="Uma linha por projecto. Internos: unidade de negócio e centro de custo. Externos: cliente e encomenda. Sem código, o sistema gera um. O calendário fica de segunda a sexta, 8 h por dia (pode alterá-lo depois na ficha). Linhas deixadas em branco são ignoradas."
      onOk={() => form.submit()}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={accao.isPending}
      width={larguraModal(760)}
    >
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const dados = dadosProjecto(v as unknown as Record<string, unknown>, !!projecto);
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
              <Radio.Group optionType="button" options={TIPOS_PROJECTO} />
            </Form.Item>
          </Col>
          {!projecto && (
            <Col xs={24} md={12}>
              <Form.Item name="estado" label="Estado inicial">
                <Select options={ESTADOS_PROJECTO_INICIAL} />
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
        {/* menos usado: recolhido por omissão (os campos continuam montados, com os valores e as regras) */}
        <SeccaoFormulario titulo="Calendário de trabalho" recolhida>
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
          <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>O calendário não conhece feriados nem turnos: é uma repartição linear pelos dias de trabalho.</Typography.Paragraph>
        </SeccaoFormulario>
        <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </ModalFormulario>
  );
}
