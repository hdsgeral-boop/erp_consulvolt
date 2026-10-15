import { Alert, Col, DatePicker, Form, Input, InputNumber, Modal, Row, Select } from 'antd';
import dayjs from 'dayjs';
import { useEffect } from 'react';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { dataApi } from '@/utilitarios/formatacao';
import { SeletorCategoria, useCategorias } from './comum/componentes';
import type { Activo } from './comum/tipos';
import { larguraModal } from '@/componentes/responsivo';
import { CamposLocalizacao } from './edificios/locais';

interface Props {
  aberto: boolean;
  /** Activo a editar; vazio = novo. */
  activo?: Activo | null;
  aoFechar: () => void;
  aoGravar?: (a: Activo) => void;
  /** Tipo proposto num activo novo (botão «Novo Edifício / Local» — assetNovoEdificio). */
  tipoInicial?: 'BEM' | 'EDIFICIO';
}

interface Valores {
  codigo?: string;
  descricao: string;
  categoria_ativo_id: number;
  estado?: string;
  unidade_negocio_id?: number;
  centro_custo_id?: number;
  fornecedor_id?: number;
  data_aquisicao?: dayjs.Dayjs;
  valor_aquisicao?: number;
  valor_residual?: number;
  vida_util?: number;
  vida_util_restante?: number;
  quota_fixa?: number;
  amortizacao_acumulada_inicial?: number;
  acumulado_fim_ano?: number;
  tipo_ativo?: 'BEM' | 'EDIFICIO';
  edificio_id?: number;
  andar_id?: number;
  porta_id?: number;
}

const num = (v: string | number | null | undefined) => (v === null || v === undefined || v === '' ? undefined : Number(v));

/** Ficha do activo (criar/editar). Com a ficha bloqueada (amortizações integradas ou compra lançada) o servidor recusa mexer nos valores. */
export function ModalActivo({ aberto, activo, aoFechar, aoGravar, tipoInicial = 'BEM' }: Props) {
  const [form] = Form.useForm<Valores>();
  const categorias = useCategorias();
  const accao = useAccao<Activo>({ invalidar: [['activos']], aoSucesso: (a) => { aoGravar?.(a); aoFechar(); } });
  const bloqueado = !!activo?.bloqueado;

  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    // edifício novo: vida útil longa por omissão, como no legado (assetNovoEdificio :155, 600 meses)
    if (!activo) form.setFieldsValue(tipoInicial === 'EDIFICIO' ? { tipo_ativo: 'EDIFICIO', vida_util: 600, vida_util_restante: 600 } : { tipo_ativo: 'BEM' });
    if (activo)
      form.setFieldsValue({
        codigo: activo.codigo ?? undefined, descricao: activo.descricao, categoria_ativo_id: activo.categoria_ativo_id ?? undefined, estado: activo.estado,
        unidade_negocio_id: activo.unidade_negocio_id ?? undefined, centro_custo_id: activo.centro_custo_id ?? undefined, fornecedor_id: activo.fornecedor_id ?? undefined,
        data_aquisicao: activo.data_aquisicao ? dayjs(activo.data_aquisicao) : undefined, valor_aquisicao: num(activo.valor_aquisicao),
        valor_residual: num(activo.valor_residual), vida_util: num(activo.vida_util), vida_util_restante: num(activo.vida_util_restante),
        quota_fixa: num(activo.quota_fixa), amortizacao_acumulada_inicial: num(activo.amortizacao_acumulada_inicial), acumulado_fim_ano: num(activo.acumulado_fim_ano),
        tipo_ativo: activo.tipo_ativo === 'EDIFICIO' ? 'EDIFICIO' : 'BEM', edificio_id: activo.edificio_id ?? undefined, andar_id: activo.andar_id ?? undefined,
        porta_id: activo.porta_id ?? undefined,
      });
  }, [aberto, activo, form, tipoInicial]);
  const tipo = Form.useWatch('tipo_ativo', form);

  const aoMudarCategoria = (id: number) => {
    const c = categorias.data?.find((x) => x.id === id);
    if (c?.vida_util_padrao && !form.getFieldValue('vida_util')) form.setFieldValue('vida_util', c.vida_util_padrao);
  };

  const gravar = (v: Valores) => {
    const dados: Record<string, unknown> = { ...v, data_aquisicao: dataApi(v.data_aquisicao) };
    for (const k of Object.keys(dados)) if (dados[k] === undefined) dados[k] = null;
    if (!activo) delete dados.estado;
    // o tipo não muda depois de criado; um edifício não tem localização (o servidor confirma)
    if (activo) delete dados.tipo_ativo;
    if (v.tipo_ativo === 'EDIFICIO' || activo?.tipo_ativo === 'EDIFICIO') { delete dados.edificio_id; delete dados.andar_id; delete dados.porta_id; }
    accao.mutate(activo ? { metodo: 'put', url: `/ativos/bens/${activo.id}`, dados } : { url: '/ativos/bens', dados });
  };

  return (
    <Modal
      title={activo ? `Editar activo ${activo.codigo ?? ''}` : 'Novo activo'}
      open={aberto}
      onCancel={aoFechar}
      onOk={() => form.submit()}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={accao.isPending}
      width={larguraModal(860)}
      destroyOnHidden
    >
      {bloqueado && (
        <Alert
          type="info"
          showIcon
          style={{ marginBottom: 12 }}
          message="Ficha bloqueada para valores"
          description="O activo tem lançamento de compra ou amortizações integradas: o servidor não aceita alterações aos valores, à data nem à vida útil."
        />
      )}
      <Form form={form} layout="vertical" onFinish={gravar}>
        <Row gutter={12} align="bottom">
          <Col xs={24} md={8}>
            <Form.Item name="tipo_ativo" label="Tipo de Activo" tooltip={activo ? 'O tipo não muda depois de criado' : undefined}>
              <Select disabled={!!activo} options={[{ value: 'BEM', label: 'Bem (equipamento, mobiliário, viatura...)' }, { value: 'EDIFICIO', label: 'Edifício / Local (contém outros bens)' }]} />
            </Form.Item>
          </Col>
          <Col xs={24} md={16}>
            <p style={{ color: '#64748b', fontSize: 13 }}>
              {tipo === 'EDIFICIO'
                ? 'Depois de gravado, abra «Edifícios e Locais» para criar os andares e numerar as portas, e arraste para elas os bens já existentes.'
                : 'Um bem pode ficar localizado numa porta de um edifício já criado.'}
            </p>
          </Col>
        </Row>
        {tipo !== 'EDIFICIO' && (
          <fieldset style={{ border: '1px solid #e2e8f0', borderRadius: 4, padding: '8px 12px', marginBottom: 12 }}>
            <legend style={{ fontSize: 12, textTransform: 'uppercase', color: '#64748b', width: 'auto', margin: 0, padding: '0 4px' }}>Localização física</legend>
            <CamposLocalizacao form={form} />
          </fieldset>
        )}
        <Row gutter={12}>
          <Col xs={24} md={6}>
            <Form.Item name="codigo" label="Código" tooltip="Vazio = numeração automática AST-NNN">
              <Input maxLength={50} placeholder="Automático" />
            </Form.Item>
          </Col>
          <Col xs={24} md={18}>
            <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Indique a descrição.' }]}>
              <Input maxLength={2000} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="categoria_ativo_id" label="Categoria" rules={[{ required: true, message: 'Escolha a categoria.' }]}>
              <SeletorCategoria onChange={aoMudarCategoria} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="centro_custo_id" label="Centro de custo">
              <SeletorAux tabela="centros-custo" placeholder="Centro de custo" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="unidade_negocio_id" label="Unidade de negócio">
              <SeletorUnidade style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="fornecedor_id" label="Fornecedor">
              <SeletorTerceiro papel="FORNECEDOR" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="data_aquisicao" label="Data de aquisição" rules={[{ required: !activo, message: 'Indique a data.' }]}>
              <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabled={bloqueado} />
            </Form.Item>
          </Col>
          {activo && (
            <Col xs={24} md={6}>
              <Form.Item name="estado" label="Estado">
                <Select options={[{ value: 'ACTIVO', label: 'Activo' }, { value: 'INACTIVO', label: 'Inactivo' }]} />
              </Form.Item>
            </Col>
          )}
          <Col xs={24} md={6}>
            <Form.Item name="valor_aquisicao" label="Valor de aquisição (Kz)" rules={[{ required: !activo, message: 'Indique o valor.' }]}>
              <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={bloqueado} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="valor_residual" label="Valor residual (Kz)">
              <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={bloqueado} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="vida_util" label="Vida útil (meses)" tooltip="Vazio = amortiza pela taxa da categoria">
              <InputNumber min={0} precision={0} style={{ width: '100%' }} disabled={bloqueado} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}>
            <Form.Item name="quota_fixa" label="Quota mensal fixa (Kz)" tooltip="Opcional: substitui a quota calculada">
              <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={bloqueado} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="amortizacao_acumulada_inicial" label="Amortização acumulada inicial (Kz)" tooltip="Activos vindos de outro sistema">
              <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={bloqueado} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="acumulado_fim_ano" label="Acumulado até ao fim do ano" tooltip="Ano a que respeita a amortização acumulada inicial">
              <InputNumber min={1900} max={2100} precision={0} style={{ width: '100%' }} disabled={bloqueado} />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item name="vida_util_restante" label="Vida útil restante (meses)">
              <InputNumber min={0} precision={0} style={{ width: '100%' }} disabled={bloqueado} />
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </Modal>
  );
}
