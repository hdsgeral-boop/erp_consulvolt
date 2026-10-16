import { Alert, Col, DatePicker, Form, Input, InputNumber, Row, Select, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo } from 'react';
import { enviar } from '@/api/cliente';
import { ModalFormulario, SeccaoFormulario, type CampoFormulario } from '@/componentes/formularios';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { useTabelaAux, useUnidadesNegocio } from '@/modulos/contab/comum/dados';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { dataApi } from '@/utilitarios/formatacao';
import { SeletorCategoria, useCategorias } from './comum/componentes';
import type { Activo, CategoriaActivo } from './comum/tipos';
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

/** Data da grade (dayjs) ou da vista Linhas ('AAAA-MM-DD') → API. */
const dataDe = (d: unknown): string | null => (dayjs.isDayjs(d) ? dataApi(d) ?? null : typeof d === 'string' && d ? d : null);

/**
 * Regras de gravação do activo, comuns à grade e à vista Linhas: vazios a nulo; o estado só na edição; o tipo não muda
 * depois de criado; um edifício não tem localização (o servidor confirma).
 */
export function dadosActivo(v: Record<string, unknown>, activo?: Pick<Activo, 'tipo_ativo'> | null): Record<string, unknown> {
  const dados: Record<string, unknown> = { ...v, data_aquisicao: dataDe(v.data_aquisicao) };
  for (const k of Object.keys(dados)) if (dados[k] === undefined || dados[k] === '') dados[k] = null;
  if (!activo) delete dados.estado;
  if (activo) delete dados.tipo_ativo;
  if (v.tipo_ativo === 'EDIFICIO' || activo?.tipo_ativo === 'EDIFICIO') { delete dados.edificio_id; delete dados.andar_id; delete dados.porta_id; }
  return dados;
}

/** Vida útil por omissão da categoria quando a linha não a traz (o mesmo que a grade faz ao escolher a categoria). */
export function comVidaDaCategoria(r: Record<string, unknown>, categorias: CategoriaActivo[] | undefined): Record<string, unknown> {
  if (r.vida_util !== null && r.vida_util !== undefined && r.vida_util !== '') return r;
  const c = categorias?.find((x) => x.id === r.categoria_ativo_id);
  return c?.vida_util_padrao ? { ...r, vida_util: c.vida_util_padrao } : r;
}

const TIPOS_ACTIVO = [{ value: 'BEM', label: 'Bem (equipamento, mobiliário, viatura...)' }, { value: 'EDIFICIO', label: 'Edifício / Local (contém outros bens)' }];

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
    const dados = dadosActivo(v as unknown as Record<string, unknown>, activo);
    accao.mutate(activo ? { metodo: 'put', url: `/ativos/bens/${activo.id}`, dados } : { url: '/ativos/bens', dados });
  };

  // Vista Linhas (legado: formularios_linhas_config.js › activos — um activo por linha, pela ordem do legado)
  const unidades = useUnidadesNegocio(aberto);
  const centros = useTabelaAux('centros-custo', aberto);
  const camposLinhas = useMemo<CampoFormulario[]>(() => [
    { nome: 'codigo', rotulo: 'Código / Nº inventário', largura: 130, maxLength: 50, placeholder: 'Automático', aliases: ['Cd. Inventário', 'Cd Inventario', 'Código'] },
    { nome: 'descricao', rotulo: 'Descrição do bem', obrigatorio: true, largura: 230, maxLength: 2000, aliases: ['Descrição', 'Descricao'] },
    { nome: 'categoria_ativo_id', rotulo: 'Categoria', tipo: 'select', obrigatorio: true, largura: 170, aliases: ['Categoria (Nome Exacto ou Novo)'],
      opcoes: (categorias.data ?? []).map((c) => ({ value: c.id, label: c.nome })) },
    { nome: 'unidade_negocio_id', rotulo: 'Unidade de negócio', tipo: 'select', largura: 160,
      opcoes: (unidades.data ?? []).map((u) => ({ value: u.id, label: `${u.codigo ? `${u.codigo} - ` : ''}${u.nome}` })) },
    { nome: 'centro_custo_id', rotulo: 'Centro de custo', tipo: 'select', largura: 160,
      opcoes: (centros.data ?? []).map((c) => ({ value: c.id, label: `${c.codigo} - ${c.descricao ?? ''}` })) },
    { nome: 'fornecedor_id', rotulo: 'Fornecedor / origem', tipo: 'personalizado', largura: 190, emLinhas: 'opcional', seccao: 'Origem',
      celula: (p) => <SeletorTerceiro papel="FORNECEDOR" aria-label={p.rotulo} status={p.erro ? 'error' : undefined} size="small" style={{ width: '100%' }} value={(p.valor as number | null) ?? undefined} onChange={(v) => p.aoMudar(v ?? null)} />,
      converter: (t) => (/^\d+$/.test(t) ? Number(t) : undefined) },
    { nome: 'data_aquisicao', rotulo: 'Data de aquisição', tipo: 'data', obrigatorio: true, largura: 140, aliases: ['Data Aquisição (AAAA-MM-DD)', 'Data Aquisição'] },
    { nome: 'valor_aquisicao', rotulo: 'Valor de aquisição', tipo: 'numero', obrigatorio: true, min: 0, precisao: 2, largura: 130, aliases: ['Valor Aquisição (Kz)', 'Valor Aquisição', 'Valor'] },
    { nome: 'valor_residual', rotulo: 'Valor residual', tipo: 'numero', min: 0, precisao: 2, largura: 120 },
    { nome: 'vida_util', rotulo: 'Vida útil (meses)', tipo: 'numero', min: 0, precisao: 0, largura: 100, aliases: ['Meses de Vida Total', 'Vida útil'] },
    { nome: 'vida_util_restante', rotulo: 'Vida remanescente', tipo: 'numero', min: 0, precisao: 0, largura: 100, aliases: ['Vida útil restante (meses)'] },
    { nome: 'quota_fixa', rotulo: 'Cota fixa mensal', tipo: 'numero', min: 0, precisao: 2, largura: 120, aliases: ['Quota mensal fixa (Kz)'] },
    { nome: 'amortizacao_acumulada_inicial', rotulo: 'Valor já amortizado (Kz)', tipo: 'numero', min: 0, precisao: 2, largura: 130, aliases: ['Amortização Acumulada (Kz)', 'Amortização Acumulada'] },
    { nome: 'acumulado_fim_ano', rotulo: 'Acumulado até ao fim do ano', tipo: 'numero', min: 1900, max: 2100, precisao: 0, largura: 110, emLinhas: 'opcional', seccao: 'Saldo inicial' },
    { nome: 'tipo_ativo', rotulo: 'Tipo de Activo', tipo: 'select', opcoes: TIPOS_ACTIVO, omissao: 'BEM', largura: 170, emLinhas: 'opcional', seccao: 'Tipo' },
  ], [categorias.data, unidades.data, centros.data]);

  return (
    <ModalFormulario
      chave="activos.activos"
      titulo={activo ? `Editar activo ${activo.codigo ?? ''}` : 'Novo activo'}
      aberto={aberto}
      aoFechar={aoFechar}
      criacao={!activo}
      form={form}
      campos={camposLinhas}
      nomeRegistos="activos imobilizados"
      invalidar={[['activos']]}
      gravarLinha={(r) => enviar('post', '/ativos/bens', dadosActivo(comVidaDaCategoria(r, categorias.data)))}
      nota="Uma linha por activo. Descrição, categoria, data e valor de aquisição são obrigatórios; sem código, o sistema numera (AST-NNN). Sem vida útil, usa a da categoria. A localização física escolhe-se na grade."
      larguraLinhas={1400}
      onOk={() => form.submit()}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={accao.isPending}
      width={larguraModal(860)}
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
              <Select disabled={!!activo} options={TIPOS_ACTIVO} />
            </Form.Item>
          </Col>
          <Col xs={24} md={16}>
            <Typography.Paragraph type="secondary" style={{ fontSize: 13 }}>
              {tipo === 'EDIFICIO'
                ? 'Depois de gravado, abra «Edifícios e Locais» para criar os andares e numerar as portas, e arraste para elas os bens já existentes.'
                : 'Um bem pode ficar localizado numa porta de um edifício já criado.'}
            </Typography.Paragraph>
          </Col>
        </Row>
        {tipo !== 'EDIFICIO' && (
          <SeccaoFormulario titulo="Localização física" recolhida={!!activo && !activo.edificio_id}>
            <CamposLocalizacao form={form} />
          </SeccaoFormulario>
        )}
        {/* grupos do legado (showAssetForm, ui_assets.js:265-420): identificação; UN/CC/fornecedor; aquisição; vida e cota; já amortizado */}
        <Row gutter={12}>
          <Col xs={24} md={6}>
            <Form.Item name="codigo" label="Código" tooltip="Vazio = numeração automática AST-NNN">
              <Input maxLength={50} placeholder="Automático" />
            </Form.Item>
          </Col>
          <Col xs={24} md={activo ? 12 : 18}>
            <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Indique a descrição.' }]}>
              <Input maxLength={2000} />
            </Form.Item>
          </Col>
          {activo && (
            <Col xs={24} md={6}>
              <Form.Item name="estado" label="Estado">
                <Select options={[{ value: 'ACTIVO', label: 'Activo' }, { value: 'INACTIVO', label: 'Inactivo' }]} />
              </Form.Item>
            </Col>
          )}
          <Col xs={24} md={8}>
            <Form.Item name="categoria_ativo_id" label="Categoria" rules={[{ required: true, message: 'Escolha a categoria.' }]}>
              <SeletorCategoria onChange={aoMudarCategoria} />
            </Form.Item>
          </Col>
        </Row>
        <SeccaoFormulario titulo="Imputação e origem">
          <Row gutter={12}>
            <Col xs={24} md={8}>
              <Form.Item name="unidade_negocio_id" label="Unidade de negócio">
                <SeletorUnidade style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="centro_custo_id" label="Centro de custo">
                <SeletorAux tabela="centros-custo" placeholder="Centro de custo" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="fornecedor_id" label="Fornecedor">
                <SeletorTerceiro papel="FORNECEDOR" style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          </Row>
        </SeccaoFormulario>
        <SeccaoFormulario titulo="Aquisição e amortização">
          <Row gutter={12}>
            <Col xs={24} md={8}>
              <Form.Item name="data_aquisicao" label="Data de aquisição" rules={[{ required: !activo, message: 'Indique a data.' }]}>
                <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabled={bloqueado} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="valor_aquisicao" label="Valor de aquisição (Kz)" rules={[{ required: !activo, message: 'Indique o valor.' }]}>
                <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={bloqueado} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="valor_residual" label="Valor residual (Kz)">
                <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={bloqueado} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="vida_util" label="Vida útil (meses)" tooltip="Vazio = amortiza pela taxa da categoria">
                <InputNumber min={0} precision={0} style={{ width: '100%' }} disabled={bloqueado} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="vida_util_restante" label="Vida útil restante (meses)">
                <InputNumber min={0} precision={0} style={{ width: '100%' }} disabled={bloqueado} />
              </Form.Item>
            </Col>
            <Col xs={24} md={8}>
              <Form.Item name="quota_fixa" label="Quota mensal fixa (Kz)" tooltip="Opcional: substitui a quota calculada">
                <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={bloqueado} />
              </Form.Item>
            </Col>
          </Row>
        </SeccaoFormulario>
        <SeccaoFormulario titulo="Saldo inicial (activos vindos de outro sistema)" recolhida={!Number(activo?.amortizacao_acumulada_inicial ?? 0)}>
          <Row gutter={12}>
            <Col xs={24} md={12}>
              <Form.Item name="amortizacao_acumulada_inicial" label="Amortização acumulada inicial (Kz)" tooltip="Activos vindos de outro sistema">
                <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={bloqueado} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="acumulado_fim_ano" label="Acumulado até ao fim do ano" tooltip="Ano a que respeita a amortização acumulada inicial">
                <InputNumber min={1900} max={2100} precision={0} style={{ width: '100%' }} disabled={bloqueado} />
              </Form.Item>
            </Col>
          </Row>
        </SeccaoFormulario>
      </Form>
    </ModalFormulario>
  );
}
