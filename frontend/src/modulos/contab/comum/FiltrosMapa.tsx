import { Button, Checkbox, Collapse, DatePicker, Flex, Form, Input, InputNumber } from 'antd';
import { CalculatorOutlined } from '@ant-design/icons';
import dayjs, { type Dayjs } from 'dayjs';
import type { ReactNode } from 'react';
import { dataApi } from '@/utilitarios/formatacao';
import { SeletorAux, SeletorDiario, SeletorTerceiro, SeletorUnidade } from './Seletores';

export type ModoDatas = 'periodo' | 'ate' | 'ano';

export interface ValoresFiltroMapa {
  periodo?: [Dayjs | null, Dayjs | null] | null;
  data_fim_unica?: Dayjs | null;
  ano?: number;
  filtro_contas?: string;
  diario_id?: number;
  centro_custo_id?: number;
  unidade_negocio_id?: number;
  terceiro_id?: number;
  incluir_classe_9?: boolean;
  incluir_apuramento?: boolean;
  [extra: string]: unknown;
}

/** Converte os valores do formulário nos parâmetros dos mapas (FiltroMapas do backend). Booleanos falsos não se enviam. */
export function paraParametros(v: ValoresFiltroMapa, modo: ModoDatas): Record<string, unknown> {
  const { periodo, data_fim_unica, ano, ...resto } = v;
  const p: Record<string, unknown> = {};
  if (modo === 'periodo') {
    p.data_inicio = dataApi(periodo?.[0]);
    p.data_fim = dataApi(periodo?.[1]);
  } else if (modo === 'ate') {
    p.data_fim = dataApi(data_fim_unica);
  } else {
    p.ano = ano;
  }
  for (const [k, x] of Object.entries(resto)) {
    if (x === undefined || x === null || x === '' || x === false) continue;
    p[k] = x === true ? 1 : x;
  }
  return p;
}

interface Props {
  modo: ModoDatas;
  aoCalcular: (parametros: Record<string, unknown>) => void;
  aCalcular?: boolean;
  /** Campos próprios do mapa (Form.Item com name). */
  extra?: ReactNode;
  /** Filtros avançados a mostrar (por omissão, todos). */
  avancados?: { contas?: boolean; diario?: boolean; centro?: boolean; unidade?: boolean; terceiro?: boolean; classe9?: boolean; apuramento?: boolean };
  valoresIniciais?: Partial<ValoresFiltroMapa>;
  /** Exige período completo (início e fim). */
  periodoObrigatorio?: boolean;
}

const TODOS = { contas: true, diario: true, centro: true, unidade: true, terceiro: true, classe9: true, apuramento: true };

/** Barra de filtros comum aos mapas contabilísticos: datas conforme o mapa, filtros avançados recolhíveis e «Calcular». */
export function FiltrosMapa({ modo, aoCalcular, aCalcular, extra, avancados = TODOS, valoresIniciais, periodoObrigatorio = true }: Props) {
  const [form] = Form.useForm<ValoresFiltroMapa>();
  const hoje = dayjs();
  const iniciais: Partial<ValoresFiltroMapa> = {
    periodo: [hoje.startOf('year'), hoje],
    data_fim_unica: hoje,
    ano: hoje.year(),
    ...valoresIniciais,
  };
  const a = { ...avancados };

  return (
    <Form<ValoresFiltroMapa> form={form} layout="vertical" initialValues={iniciais} onFinish={(v) => aoCalcular(paraParametros(v, modo))}>
      <Flex gap={12} wrap align="end">
        {modo === 'periodo' && (
          <Form.Item
            name="periodo"
            label="Período"
            style={{ marginBottom: 8 }}
            rules={periodoObrigatorio ? [{ validator: async (_, v) => (v?.[0] && v?.[1] ? undefined : Promise.reject(new Error('Indique o período.'))) }] : []}
          >
            <DatePicker.RangePicker format="DD/MM/YYYY" allowEmpty={periodoObrigatorio ? undefined : [true, true]} />
          </Form.Item>
        )}
        {modo === 'ate' && (
          <Form.Item name="data_fim_unica" label="Até à data" rules={[{ required: true, message: 'Indique a data.' }]} style={{ marginBottom: 8 }}>
            <DatePicker format="DD/MM/YYYY" />
          </Form.Item>
        )}
        {modo === 'ano' && (
          <Form.Item name="ano" label="Ano" rules={[{ required: true, message: 'Indique o ano.' }]} style={{ marginBottom: 8 }}>
            <InputNumber min={1990} max={2100} style={{ width: 110 }} />
          </Form.Item>
        )}
        {a.contas && (
          <Form.Item name="filtro_contas" label="Contas" tooltip="Ex.: 31, 32*, 4311-4319 (separe por vírgulas)" style={{ marginBottom: 8 }}>
            <Input placeholder="Todas" style={{ width: 180 }} maxLength={500} allowClear />
          </Form.Item>
        )}
        {extra}
        <Form.Item style={{ marginBottom: 8 }}>
          <Button type="primary" htmlType="submit" icon={<CalculatorOutlined />} loading={aCalcular}>
            Calcular
          </Button>
        </Form.Item>
      </Flex>
      {(a.diario || a.centro || a.unidade || a.terceiro || a.classe9 || a.apuramento) && (
        <Collapse
          ghost
          size="small"
          items={[
            {
              key: 'mais',
              label: 'Mais filtros',
              children: (
                <Flex gap={12} wrap align="center">
                  {a.diario && (
                    <Form.Item name="diario_id" style={{ marginBottom: 0 }}>
                      <SeletorDiario allowClear />
                    </Form.Item>
                  )}
                  {a.centro && (
                    <Form.Item name="centro_custo_id" style={{ marginBottom: 0 }}>
                      <SeletorAux tabela="centros-custo" placeholder="Centro de custo" />
                    </Form.Item>
                  )}
                  {a.unidade && (
                    <Form.Item name="unidade_negocio_id" style={{ marginBottom: 0 }}>
                      <SeletorUnidade />
                    </Form.Item>
                  )}
                  {a.terceiro && (
                    <Form.Item name="terceiro_id" style={{ marginBottom: 0 }}>
                      <SeletorTerceiro />
                    </Form.Item>
                  )}
                  {a.classe9 && (
                    <Form.Item name="incluir_classe_9" valuePropName="checked" style={{ marginBottom: 0 }}>
                      <Checkbox>Incluir classe 9</Checkbox>
                    </Form.Item>
                  )}
                  {a.apuramento && (
                    <Form.Item name="incluir_apuramento" valuePropName="checked" style={{ marginBottom: 0 }}>
                      <Checkbox>Incluir apuramento (período 13)</Checkbox>
                    </Form.Item>
                  )}
                </Flex>
              ),
            },
          ]}
        />
      )}
    </Form>
  );
}
